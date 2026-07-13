/**
 * Grafida — desktop Joomla! article editor
 * Copyright (c) 2026 Nicholas K. Dionysopoulos
 * GNU General Public License version 3, or later
 *
 * AI provider transport layer — plain IIFE, no bundler.
 * Exposes window.GrafidaAI = { buildRequest, sendChat, newAbort }.
 *
 * The api.resolvedAiService / api.aiProxy helpers are defined in app.js and
 * resolved at call time via the shared global lexical scope; this file is
 * loaded BEFORE app.js so that app.js may reference window.GrafidaAI
 * immediately on startup.
 *
 * Message shape:  { role: 'system' | 'user' | 'assistant', content: string }
 *
 * SSE dialect reference:
 *   openai_completions — "data: {json}" lines, choices[].delta.content, "[DONE]" sentinel.
 *                        Any unknown/legacy dialect value (including a stale "openai")
 *                        also degrades to this path — that is the whole back-compat story.
 *   anthropic          — "event:"/"data:" pairs, content_block_delta/text_delta, message_stop.
 *   openai_responses   — OpenAI's Responses API. "event:"/"data:" pairs; every data payload
 *                        carries a "type", so dispatch on that and ignore the "event:" lines.
 *                        No "[DONE]" sentinel; response.completed is terminal. Non-streaming
 *                        replies nest the text under output[].content[] instead of a single
 *                        path. Carries a response id that can be chained via
 *                        previous_response_id on a later turn (see sendChat's opts).
 */

'use strict';

(function (global) {

    /** Default max_tokens sent to Anthropic when params omit it. */
    const ANTHROPIC_MAX_TOKENS = 4096;

    // -------------------------------------------------------------------------
    //  Request builder
    // -------------------------------------------------------------------------

    /**
     * Build a provider HTTP request descriptor from a resolved service config.
     *
     * @param {Object}  resolved  - payload from GET /api/ai/services/{id}/resolved
     * @param {Array}   messages  - [{role, content}, …]
     * @param {boolean} stream    - if true, sets stream:true in the request body
     * @param {Object}  [opts]
     * @param {string|null} [opts.previousResponseId] - Responses-API chain id from a prior turn
     * @returns {{ url:string, method:string, headers:Object, body:string }}
     */
    function buildRequest(resolved, messages, stream, opts) {
        const { endpoint, chatPath, sseDialect, model, authHeader, apiKey, params } = resolved;
        // Strip trailing slashes from endpoint before appending chatPath.
        const url = endpoint.replace(/\/+$/, '') + chatPath;
        const p   = params || {};
        const { previousResponseId = null } = opts || {};

        if (sseDialect === 'openai_responses') {
            // OpenAI Responses API: system-role turns become "instructions" (resent
            // every turn — it is NOT inherited across previous_response_id), the
            // rest go in "input".
            const sysParts = messages.filter(m => m.role === 'system').map(m => m.content);
            const turns    = messages.filter(m => m.role !== 'system');
            const lastTurn = turns.length ? turns[turns.length - 1] : null;

            // Unset store means ON, mirroring how `stream` already defaults on.
            const store = p.store !== false;

            // Chain onto a prior response only when storage is on, we have a chain
            // id from the previous turn, and the last turn is a fresh user message
            // (otherwise there is nothing new to chain, or nothing to chain onto).
            const useChain = store && !!previousResponseId && !!lastTurn && lastTurn.role === 'user';

            const body = {
                model,
                input: useChain ? [lastTurn] : turns,
                store,
            };
            if (sysParts.length) body.instructions = sysParts.join('\n\n');
            if (useChain) body.previous_response_id = previousResponseId;
            if (stream) body.stream = true;
            if (p.max_completion_tokens != null) body.max_output_tokens = p.max_completion_tokens;
            if (p.temperature != null) body.temperature = p.temperature;
            if (p.top_p       != null) body.top_p       = p.top_p;

            // "Authorization" → "Bearer <key>"; any other header name → raw key value.
            const authValue = authHeader === 'Authorization' ? 'Bearer ' + apiKey : apiKey;

            return {
                url, method: 'POST',
                headers: { 'Content-Type': 'application/json', [authHeader]: authValue },
                body: JSON.stringify(body),
            };
        }

        if (sseDialect === 'anthropic') {
            // Anthropic: system messages become a single top-level "system" string.
            // Only user/assistant turns go in the "messages" array.
            const sysParts = messages.filter(m => m.role === 'system').map(m => m.content);
            const turns    = messages.filter(m => m.role !== 'system');

            const body = {
                model,
                messages:   turns,
                max_tokens: p.max_completion_tokens || ANTHROPIC_MAX_TOKENS,
            };
            if (stream) body.stream = true;

            // Anthropic rejects a request that carries both temperature and top_p;
            // prefer temperature, fall back to top_p if only top_p is set.
            if (p.temperature != null) {
                body.temperature = p.temperature;
            } else if (p.top_p != null) {
                body.top_p = p.top_p;
            }

            if (sysParts.length) body.system = sysParts.join('\n\n');

            return {
                url, method: 'POST',
                headers: {
                    'Content-Type':                              'application/json',
                    'x-api-key':                                 apiKey,
                    'anthropic-version':                         '2023-06-01',
                    'anthropic-dangerous-direct-browser-access': 'true',
                },
                body: JSON.stringify(body),
            };
        }

        // OpenAI Chat Completions dialect (the default fallback for any unknown/legacy
        // dialect value, including a stale "openai"): messages array includes
        // system-role entries.
        const body = { model, messages };
        if (stream) body.stream = true;
        if (p.temperature        != null) body.temperature = p.temperature;
        if (p.top_p              != null) body.top_p       = p.top_p;
        if (p.max_completion_tokens != null) body.max_tokens = p.max_completion_tokens;

        // "Authorization" → "Bearer <key>"; any other header name → raw key value.
        const authValue = authHeader === 'Authorization' ? 'Bearer ' + apiKey : apiKey;

        return {
            url, method: 'POST',
            headers: { 'Content-Type': 'application/json', [authHeader]: authValue },
            body: JSON.stringify(body),
        };
    }

    // -------------------------------------------------------------------------
    //  SSE stream reader
    // -------------------------------------------------------------------------

    /**
     * Read an SSE response body, calling onToken for each text delta.
     *
     * Buffers partial lines across read() calls so chunks split mid-line are
     * handled correctly.
     *
     * @param {ReadableStream} body     - response body from a streaming fetch
     * @param {string}         dialect  - 'openai_completions' | 'anthropic' | 'openai_responses'
     * @param {Function|null}  onToken  - called with each incremental delta string
     * @returns {Promise<{text:string, responseId:string|null}>} full accumulated text + chain id
     */
    async function readSseStream(body, dialect, onToken) {
        const reader  = body.getReader();
        const decoder = new TextDecoder();
        let buf        = '';  // partial-line buffer across read() calls
        let text       = '';  // full accumulated response
        let responseId = null;
        let done       = false;

        while (!done) {
            const { value, done: rdDone } = await reader.read();
            done = rdDone;
            if (value) buf += decoder.decode(value, { stream: !rdDone });

            // Drain all complete lines from the buffer.
            let nl;
            while ((nl = buf.indexOf('\n')) !== -1) {
                const line = buf.slice(0, nl).replace(/\r$/, '');  // strip CR (CRLF lines)
                buf = buf.slice(nl + 1);

                if (!line) continue;  // SSE blank-line event separator

                if (dialect === 'openai_responses') {
                    // Skip event-type lines; every data payload carries its own "type".
                    if (line.startsWith('event:')) continue;
                    if (!line.startsWith('data:'))  continue;

                    const payload = line.slice(5).trim();
                    let json;
                    try { json = JSON.parse(payload); } catch { continue; }

                    if (json.type === 'response.created') {
                        responseId = json.response?.id || responseId;
                        continue;
                    }
                    if (json.type === 'response.output_text.delta') {
                        const delta = json.delta || '';
                        text += delta;
                        if (onToken && delta) onToken(delta);
                        continue;
                    }
                    if (json.type === 'response.completed') {
                        responseId = json.response?.id || responseId;
                        done = true; break;
                    }
                    if (json.type === 'response.failed' || json.type === 'response.incomplete') {
                        throw new Error('Provider error: ' + (json.response?.error?.message || JSON.stringify(json)));
                    }
                    if (json.type === 'error') {
                        throw new Error('Provider error: ' + (json.message || JSON.stringify(json)));
                    }
                    // Other event types (response.output_item.added, reasoning deltas, …) are ignored.

                } else if (dialect === 'anthropic') {
                    // Skip event-type lines; only data lines carry content.
                    if (line.startsWith('event:')) continue;
                    if (!line.startsWith('data:'))  continue;

                    const payload = line.slice(5).trim();
                    let json;
                    try { json = JSON.parse(payload); } catch { continue; }

                    if (json.type === 'message_stop') { done = true; break; }
                    if (json.type === 'error') {
                        throw new Error('Provider error: ' + (json.error?.message || JSON.stringify(json)));
                    }
                    if (json.type !== 'content_block_delta') continue;
                    if (json.delta?.type !== 'text_delta')   continue;

                    const delta = json.delta.text || '';
                    text += delta;
                    if (onToken && delta) onToken(delta);

                } else {
                    // OpenAI Chat Completions dialect (the default fallback for any
                    // unknown/legacy dialect value, including a stale "openai").
                    if (!line.startsWith('data:')) continue;

                    const payload = line.slice(5).trim();
                    if (payload === '[DONE]') { done = true; break; }

                    let json;
                    try { json = JSON.parse(payload); } catch { continue; }

                    if (json.error) {
                        throw new Error('Provider error: ' + (json.error.message || JSON.stringify(json.error)));
                    }

                    let delta = '';
                    (json.choices || []).forEach(c => { delta += c.delta?.content ?? ''; });
                    text += delta;
                    if (onToken && delta) onToken(delta);
                }
            }
        }

        return { text, responseId };
    }

    // -------------------------------------------------------------------------
    //  Full-response parser (non-streaming / proxy path)
    // -------------------------------------------------------------------------

    /**
     * Extract the assistant text (+ chain id, for the Responses dialect) from a
     * full (non-streaming) provider JSON response.
     *
     * @param {string} dialect   - 'openai_completions' | 'anthropic' | 'openai_responses'
     * @param {string} bodyText  - raw JSON string from the provider
     * @returns {{text:string, responseId:string|null}}
     */
    function parseFullResponse(dialect, bodyText) {
        let json;
        try {
            json = typeof bodyText === 'string' ? JSON.parse(bodyText) : bodyText;
        } catch {
            throw new Error('Provider returned non-JSON: ' + String(bodyText).slice(0, 200));
        }
        if (json.error) {
            throw new Error('Provider error: ' + (json.error.message || JSON.stringify(json.error)));
        }
        if (dialect === 'openai_responses') {
            // The text is not at a single path: walk output[], keep "message" items,
            // and within each concatenate "output_text" content parts. Interleaved
            // "reasoning" items are skipped.
            const parts = [];
            (json.output || []).forEach(item => {
                if (item.type !== 'message') return;
                (item.content || []).forEach(c => {
                    if (c.type === 'output_text' && typeof c.text === 'string') parts.push(c.text);
                });
            });
            if (!parts.length) throw new Error('Unexpected Responses API response shape (no output text found)');
            return { text: parts.join(''), responseId: json.id || null };
        }
        if (dialect === 'anthropic') {
            const text = json.content?.[0]?.text;
            if (typeof text !== 'string') throw new Error('Unexpected Anthropic response shape');
            return { text, responseId: null };
        }
        // OpenAI Chat Completions dialect (the default fallback for any unknown/legacy
        // dialect value, including a stale "openai").
        const text = json.choices?.[0]?.message?.content;
        if (typeof text !== 'string') throw new Error('Unexpected OpenAI response shape');
        return { text, responseId: null };
    }

    // -------------------------------------------------------------------------
    //  Public API
    // -------------------------------------------------------------------------

    /**
     * True when a thrown error looks like the provider rejecting a stale/unknown
     * previous_response_id (Responses-API chain), rather than any other failure.
     * The retention window is a guess about the provider's policy, not a
     * guarantee — an expired/purged chain must degrade to a full-history call,
     * never surface as an error to the user.
     *
     * @param {Error} err
     * @returns {boolean}
     */
    function _looksLikeStaleChainError(err) {
        if (!err || err.name === 'AbortError') return false;
        const msg = String(err.message || '');
        if (/\b404\b/.test(msg)) return true;
        return /previous[_ ]?response/i.test(msg);
    }

    /**
     * One attempt at sendChat's streaming-then-proxy-fallback flow, for a given
     * previousResponseId. Factored out of sendChat() so the self-healing retry
     * (see sendChat) can call it a second time with previousResponseId cleared.
     *
     * @param {number|string} serviceId
     * @param {Object}      resolved
     * @param {Array}       messages
     * @param {Object}      attemptOpts
     * @param {boolean}     attemptOpts.stream
     * @param {Function}    [attemptOpts.onToken]
     * @param {AbortSignal} [attemptOpts.signal]
     * @param {string|null} [attemptOpts.previousResponseId]
     * @returns {Promise<{text:string, usedFallback:boolean, responseId:string|null}>}
     */
    async function _attemptChat(serviceId, resolved, messages, attemptOpts) {
        const { stream, onToken, signal, previousResponseId } = attemptOpts;

        if (stream) {
            const req = buildRequest(resolved, messages, true, { previousResponseId });
            try {
                const res = await fetch(req.url, {
                    method:  req.method,
                    headers: req.headers,
                    body:    req.body,
                    signal,
                });
                if (!res.ok) {
                    let errMsg = 'HTTP ' + res.status;
                    try {
                        const j = await res.json();
                        errMsg = j.error?.message || j.message || errMsg;
                    } catch {}
                    throw new Error('Provider error: ' + errMsg);
                }
                const { text, responseId } = await readSseStream(res.body, resolved.sseDialect, onToken);
                return { text, usedFallback: false, responseId };
            } catch (err) {
                if (err.name === 'AbortError') throw err;    // propagate user cancel
                if (!(err instanceof TypeError)) throw err;  // only network errors fall back
                // TypeError = CORS / network failure → fall through to proxy
            }
        }

        // Non-streaming path, or streaming fell back due to a network/CORS TypeError.
        const req = buildRequest(resolved, messages, false, { previousResponseId });
        const proxyResult = await api.aiProxy({
            serviceId,
            url:       req.url,
            method:    req.method,
            headers:   req.headers,
            body:      req.body,
        });

        if (proxyResult.status < 200 || proxyResult.status >= 300) {
            let errMsg = 'HTTP ' + proxyResult.status;
            try {
                const j = JSON.parse(proxyResult.body);
                errMsg = j.error?.message || j.message || errMsg;
            } catch {}
            throw new Error('Provider error: ' + errMsg);
        }

        const { text, responseId } = parseFullResponse(resolved.sseDialect, proxyResult.body);
        return { text, usedFallback: true, responseId };
    }

    /**
     * Send a chat-completion request to an AI service.
     *
     * Streaming path (opts.stream = true):
     *   Direct fetch to the provider; calls opts.onToken(deltaText) for each
     *   token. On a network/CORS TypeError the request is retried once via the
     *   PHP proxy (non-streaming). Abort errors and provider HTTP errors are
     *   propagated immediately without falling back.
     *
     * Non-streaming path (opts.stream = false, or streaming fallback):
     *   Routes through POST /api/ai/proxy (host-validated server-side), then
     *   parses the full provider response. onToken is NOT called in this path.
     *
     * Responses-API chaining (opts.previousResponseId): if the request that
     * carried it fails because the provider no longer recognises it (HTTP 404,
     * or an error message naming the previous response), the call is retried
     * once with no previous_response_id and the full messages array — never
     * surfaced as an error to the user.
     *
     * @param {number|string}  serviceId
     * @param {Array}          messages               [{role:'system'|'user'|'assistant', content:string}, …]
     * @param {Object}         [opts]
     * @param {boolean}        [opts.stream=false]     request SSE streaming
     * @param {Function}       [opts.onToken]          called with each incremental delta string
     * @param {AbortSignal}    [opts.signal]           AbortSignal from newAbort().signal
     * @param {string}         [opts.toolKey]          tool key for per-tool param overrides
     * @param {string|null}    [opts.previousResponseId] Responses-API chain id from a prior turn
     * @returns {Promise<{text:string, usedFallback:boolean, responseId:string|null}>}
     */
    async function sendChat(serviceId, messages, opts) {
        const { stream = false, onToken, signal, toolKey, previousResponseId = null } = opts || {};
        // api is declared as `const` in app.js; both scripts share the browser's
        // global lexical scope, so `api` is in scope here at call time.
        /* global api */
        const resolved = await api.resolvedAiService(serviceId, toolKey || '');

        // Any token handed to the caller has already been rendered and accumulated
        // on their side, so re-running the request would duplicate the reply. A
        // rejected previous_response_id is reported before the stream opens, so this
        // never blocks a legitimate retry — it only bounds the damage if some
        // provider ever rejects the chain mid-stream.
        let emittedToken = false;
        const trackedOnToken = onToken
            ? (delta) => { emittedToken = true; onToken(delta); }
            : onToken;

        try {
            return await _attemptChat(serviceId, resolved, messages, {
                stream, onToken: trackedOnToken, signal, previousResponseId,
            });
        } catch (err) {
            if (previousResponseId && !emittedToken && _looksLikeStaleChainError(err)) {
                return await _attemptChat(serviceId, resolved, messages, {
                    stream, onToken, signal, previousResponseId: null,
                });
            }
            throw err;
        }
    }

    /**
     * Create a new AbortController for cancelling a sendChat call.
     * Pass the returned controller's .signal as opts.signal, then call
     * controller.abort() to cancel.
     *
     * @returns {AbortController}
     */
    function newAbort() {
        return new AbortController();
    }

    global.GrafidaAI = { buildRequest, sendChat, newAbort };

})(window);
