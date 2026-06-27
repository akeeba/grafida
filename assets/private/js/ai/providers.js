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
 * SSE dialect reference (ported from AITiny):
 *   OpenAI    — "data: {json}" lines, choices[].delta.content, "[DONE]" sentinel
 *   Anthropic — "event:"/"data:" pairs, content_block_delta/text_delta, message_stop
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
     * @returns {{ url:string, method:string, headers:Object, body:string }}
     */
    function buildRequest(resolved, messages, stream) {
        const { endpoint, chatPath, sseDialect, model, authHeader, apiKey, params } = resolved;
        // Strip trailing slashes from endpoint before appending chatPath.
        const url = endpoint.replace(/\/+$/, '') + chatPath;
        const p   = params || {};

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

        // OpenAI-compatible dialect: the messages array includes system-role entries.
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
     * @param {string}         dialect  - 'openai' | 'anthropic'
     * @param {Function|null}  onToken  - called with each incremental delta string
     * @returns {Promise<string>} full accumulated text
     */
    async function readSseStream(body, dialect, onToken) {
        const reader  = body.getReader();
        const decoder = new TextDecoder();
        let buf  = '';  // partial-line buffer across read() calls
        let text = '';  // full accumulated response
        let done = false;

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

                if (dialect === 'anthropic') {
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
                    // OpenAI-compatible dialect.
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

        return text;
    }

    // -------------------------------------------------------------------------
    //  Full-response parser (non-streaming / proxy path)
    // -------------------------------------------------------------------------

    /**
     * Extract the assistant text from a full (non-streaming) provider JSON response.
     *
     * @param {string} dialect   - 'openai' | 'anthropic'
     * @param {string} bodyText  - raw JSON string from the provider
     * @returns {string}
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
        if (dialect === 'anthropic') {
            const text = json.content?.[0]?.text;
            if (typeof text !== 'string') throw new Error('Unexpected Anthropic response shape');
            return text;
        }
        // OpenAI-compatible
        const text = json.choices?.[0]?.message?.content;
        if (typeof text !== 'string') throw new Error('Unexpected OpenAI response shape');
        return text;
    }

    // -------------------------------------------------------------------------
    //  Public API
    // -------------------------------------------------------------------------

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
     * @param {number|string}  serviceId
     * @param {Array}          messages               [{role:'system'|'user'|'assistant', content:string}, …]
     * @param {Object}         [opts]
     * @param {boolean}        [opts.stream=false]    request SSE streaming
     * @param {Function}       [opts.onToken]         called with each incremental delta string
     * @param {AbortSignal}    [opts.signal]           AbortSignal from newAbort().signal
     * @param {string}         [opts.toolKey]          tool key for per-tool param overrides
     * @returns {Promise<{text:string, usedFallback:boolean}>}
     */
    async function sendChat(serviceId, messages, opts) {
        const { stream = false, onToken, signal, toolKey } = opts || {};
        // api is declared as `const` in app.js; both scripts share the browser's
        // global lexical scope, so `api` is in scope here at call time.
        /* global api */
        const resolved = await api.resolvedAiService(serviceId, toolKey || '');

        if (stream) {
            const req = buildRequest(resolved, messages, true);
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
                const fullText = await readSseStream(res.body, resolved.sseDialect, onToken);
                return { text: fullText, usedFallback: false };
            } catch (err) {
                if (err.name === 'AbortError') throw err;    // propagate user cancel
                if (!(err instanceof TypeError)) throw err;  // only network errors fall back
                // TypeError = CORS / network failure → fall through to proxy
            }
        }

        // Non-streaming path, or streaming fell back due to a network/CORS TypeError.
        const req = buildRequest(resolved, messages, false);
        const proxyResult = await api.aiProxy({
            serviceId,
            url:     req.url,
            method:  req.method,
            headers: req.headers,
            body:    req.body,
        });

        if (proxyResult.status < 200 || proxyResult.status >= 300) {
            let errMsg = 'HTTP ' + proxyResult.status;
            try {
                const j = JSON.parse(proxyResult.body);
                errMsg = j.error?.message || j.message || errMsg;
            } catch {}
            throw new Error('Provider error: ' + errMsg);
        }

        const text = parseFullResponse(resolved.sseDialect, proxyResult.body);
        return { text, usedFallback: true };
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
