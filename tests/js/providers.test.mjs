/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 *
 * Unit tests for assets/private/js/ai/providers.js — the AI transport layer.
 *
 * Run with `composer test:js` (or `node --test tests/js/`). Node's built-in test
 * runner is used deliberately: node is already a build prerequisite (it vendors
 * TinyMCE/CodeMirror/FontAwesome), so this adds no dependency and no bundler.
 *
 * providers.js is a plain browser IIFE that hangs itself off `window` and reaches
 * for the `api` global that app.js declares. We therefore evaluate it inside a `vm`
 * context with a fake `window`, `fetch` and `api`, then drive the real code.
 *
 * This is the ONLY automated coverage of the provider wire formats: the transport
 * runs in the SPA, so PHPUnit cannot reach it (see tests/Integration/Ai/
 * ResponsesApiLiveTest.php, which pins the same formats against a real server).
 */

import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';

// -----------------------------------------------------------------------------
//  Harness
// -----------------------------------------------------------------------------

const SOURCE = readFileSync(new URL('../../assets/private/js/ai/providers.js', import.meta.url), 'utf8');

/**
 * Load providers.js into a fresh sandbox.
 *
 * @param {Object}   [opts]
 * @param {Object}   [opts.resolved]     what api.resolvedAiService() returns
 * @param {Function} [opts.fetch]        stand-in for the direct streaming fetch
 * @param {boolean}  [opts.corsFailure]  make the direct fetch fail the way a CORS
 *                                       block does (see the realm note below)
 * @param {Function} [opts.aiProxy]      stand-in for POST /api/ai/proxy
 * @returns {{ AI: Object, proxyCalls: Array }}
 */
function load(opts = {}) {
    const proxyCalls = [];

    const sandbox = {
        window: {},
        AbortController,
        TextDecoder,
        api: {
            resolvedAiService: async () => opts.resolved,
            aiProxy: async (req) => {
                proxyCalls.push({ ...req, parsedBody: JSON.parse(req.body) });
                return opts.aiProxy ? opts.aiProxy(req, proxyCalls.length) : { status: 200, body: '{}' };
            },
        },
    };
    sandbox.globalThis = sandbox;

    vm.createContext(sandbox);

    // providers.js detects a CORS/network failure with `err instanceof TypeError`.
    // The browser has a single realm, but the sandbox has its own — so a TypeError
    // built out here would NOT be an instanceof the sandbox's TypeError and the
    // fallback would never trigger. Mint the error inside the sandbox instead.
    const networkError = vm.runInContext('(msg) => new TypeError(msg)', sandbox);

    sandbox.fetch = opts.corsFailure
        ? async () => { throw networkError('Failed to fetch'); }
        : (opts.fetch ?? (async () => { throw networkError('no fetch configured'); }));

    vm.runInContext(SOURCE, sandbox);

    return { AI: sandbox.window.GrafidaAI, proxyCalls };
}

/** Turns SSE text into a ReadableStream-alike, split into awkward chunks on purpose. */
function sseBody(text, chunkCount = 3) {
    const bytes = new TextEncoder().encode(text);
    const size = Math.ceil(bytes.length / chunkCount);
    const chunks = [];
    for (let i = 0; i < bytes.length; i += size) chunks.push(bytes.slice(i, i + size));

    let i = 0;

    return {
        getReader: () => ({
            read: async () => (i < chunks.length
                ? { value: chunks[i++], done: false }
                : { value: undefined, done: true }),
        }),
    };
}

const RESPONSES = {
    endpoint: 'https://api.openai.com/v1', chatPath: '/responses',
    sseDialect: 'openai_responses', model: 'gpt-5',
    authHeader: 'Authorization', apiKey: 'sk-test', params: {},
};

const COMPLETIONS = {
    endpoint: 'https://api.scaleway.ai/v1', chatPath: '/chat/completions',
    sseDialect: 'openai_completions', model: 'llama',
    authHeader: 'Authorization', apiKey: 'sk-test', params: {},
};

const ANTHROPIC = {
    endpoint: 'https://api.anthropic.com/v1', chatPath: '/messages',
    sseDialect: 'anthropic', model: 'claude-sonnet-5',
    authHeader: 'X-Api-Key', apiKey: 'sk-test', params: {},
};

/** A conversation with a system prompt, one completed exchange, and a new question. */
const MESSAGES = [
    { role: 'system',    content: 'You are an editor.' },
    { role: 'user',      content: 'First question' },
    { role: 'assistant', content: 'First answer' },
    { role: 'user',      content: 'Follow-up question' },
];

// -----------------------------------------------------------------------------
//  buildRequest — the Responses dialect
// -----------------------------------------------------------------------------

test('Responses: system turns become `instructions`, the rest become `input`', () => {
    const { AI } = load();
    const req = AI.buildRequest(RESPONSES, MESSAGES, true);
    const body = JSON.parse(req.body);

    assert.equal(req.url, 'https://api.openai.com/v1/responses');
    assert.equal(req.headers.Authorization, 'Bearer sk-test');
    assert.equal(body.instructions, 'You are an editor.');
    assert.equal(body.messages, undefined, 'Responses takes `input`, never `messages`');
    assert.equal(body.input.length, 3, 'every non-system turn');
    assert.equal(body.stream, true);
});

test('Responses: `store` defaults ON when the param is unset', () => {
    const { AI } = load();
    const body = JSON.parse(AI.buildRequest(RESPONSES, MESSAGES, false).body);

    assert.equal(body.store, true);
});

test('Responses: chaining sends ONLY the new user turn plus previous_response_id', () => {
    const { AI } = load();
    const body = JSON.parse(AI.buildRequest(RESPONSES, MESSAGES, true, { previousResponseId: 'resp_abc' }).body);

    assert.equal(body.previous_response_id, 'resp_abc');
    assert.equal(body.input.length, 1, 'the transcript is NOT re-uploaded — that is the whole point');
    assert.equal(body.input[0].content, 'Follow-up question');
    // instructions is not inherited across previous_response_id, so it must be resent.
    assert.equal(body.instructions, 'You are an editor.');
});

test('Responses: `store: false` never chains, even when a chain id is offered', () => {
    const { AI } = load();
    const resolved = { ...RESPONSES, params: { store: false } };
    const body = JSON.parse(AI.buildRequest(resolved, MESSAGES, true, { previousResponseId: 'resp_abc' }).body);

    assert.equal(body.store, false);
    assert.equal(body.previous_response_id, undefined, 'nothing is stored to chain onto');
    assert.equal(body.input.length, 3, 'so the full history must be resent');
});

test('Responses: will not chain when the last turn is not a fresh user message', () => {
    const { AI } = load();
    const trailingAssistant = MESSAGES.slice(0, 3);   // ends on the assistant turn
    const body = JSON.parse(AI.buildRequest(RESPONSES, trailingAssistant, false, { previousResponseId: 'resp_abc' }).body);

    assert.equal(body.previous_response_id, undefined, 'there is nothing new to chain');
    assert.equal(body.input.length, 2);
});

test('Responses: max_completion_tokens maps to max_output_tokens', () => {
    const { AI } = load();
    const resolved = { ...RESPONSES, params: { max_completion_tokens: 1024, temperature: 0.7, top_p: 0.9 } };
    const body = JSON.parse(AI.buildRequest(resolved, MESSAGES, false).body);

    assert.equal(body.max_output_tokens, 1024);
    assert.equal(body.max_tokens, undefined, 'that is the Chat Completions name');
    assert.equal(body.temperature, 0.7);
    assert.equal(body.top_p, 0.9);
    assert.equal(body.stream, undefined, 'no stream key when not streaming');
});

// -----------------------------------------------------------------------------
//  buildRequest — the other dialects must be untouched by the migration
// -----------------------------------------------------------------------------

test('Chat Completions (Scaleway): unchanged — system stays in `messages`, no store', () => {
    const { AI } = load();
    const req = AI.buildRequest(COMPLETIONS, MESSAGES, true);
    const body = JSON.parse(req.body);

    assert.equal(req.url, 'https://api.scaleway.ai/v1/chat/completions');
    assert.equal(body.messages.length, 4, 'including the system turn');
    assert.equal(body.input, undefined);
    assert.equal(body.store, undefined, '`store` is Responses-only');
    assert.equal(body.previous_response_id, undefined);
});

test('Anthropic: unchanged — system hoisted to `system`, x-api-key auth', () => {
    const { AI } = load();
    const req = AI.buildRequest(ANTHROPIC, MESSAGES, true);
    const body = JSON.parse(req.body);

    assert.equal(req.headers['x-api-key'], 'sk-test');
    assert.equal(body.system, 'You are an editor.');
    assert.equal(body.messages.length, 3, 'system is not among the turns');
    assert.equal(body.max_tokens, 4096, 'Anthropic requires max_tokens');
});

test('a legacy/unknown dialect degrades to Chat Completions', () => {
    const { AI } = load();

    for (const dialect of ['openai', 'something-new', '']) {
        const body = JSON.parse(AI.buildRequest({ ...COMPLETIONS, sseDialect: dialect }, MESSAGES, false).body);
        assert.ok(Array.isArray(body.messages), `"${dialect}" must fall back to Chat Completions`);
    }
});

// -----------------------------------------------------------------------------
//  Streaming
// -----------------------------------------------------------------------------

test('Responses streaming: typed events parsed across split chunks, id captured, no [DONE]', async () => {
    const sse = [
        'event: response.created',
        'data: {"type":"response.created","response":{"id":"resp_123"}}',
        '',
        'event: response.output_text.delta',
        'data: {"type":"response.output_text.delta","delta":"Hello"}',
        '',
        'event: response.output_text.delta',
        'data: {"type":"response.output_text.delta","delta":" world"}',
        '',
        'event: response.completed',
        'data: {"type":"response.completed","response":{"id":"resp_123"}}',
        '',
    ].join('\n');

    const { AI } = load({
        resolved: RESPONSES,
        fetch: async () => ({ ok: true, body: sseBody(sse, 4) }),
    });

    const tokens = [];
    const result = await AI.sendChat(1, MESSAGES, { stream: true, onToken: (d) => tokens.push(d) });

    assert.deepEqual(tokens, ['Hello', ' world'], 'delivered token by token, not in one lump');
    assert.equal(result.text, 'Hello world');
    assert.equal(result.responseId, 'resp_123');
    assert.equal(result.usedFallback, false);
});

test('Responses streaming: a response.failed event surfaces as an error', async () => {
    const sse = [
        'event: response.failed',
        'data: {"type":"response.failed","response":{"error":{"message":"context length exceeded"}}}',
        '',
    ].join('\n');

    const { AI } = load({ resolved: RESPONSES, fetch: async () => ({ ok: true, body: sseBody(sse, 1) }) });

    await assert.rejects(() => AI.sendChat(1, MESSAGES, { stream: true }), /context length exceeded/);
});

test('Chat Completions streaming: still honours the [DONE] sentinel', async () => {
    const sse = [
        'data: {"choices":[{"delta":{"content":"Hi"}}]}',
        '',
        'data: {"choices":[{"delta":{"content":" there"}}]}',
        '',
        'data: [DONE]',
        '',
    ].join('\n');

    const { AI } = load({ resolved: COMPLETIONS, fetch: async () => ({ ok: true, body: sseBody(sse, 3) }) });

    const result = await AI.sendChat(1, MESSAGES, { stream: true });

    assert.equal(result.text, 'Hi there');
    assert.equal(result.responseId, null, 'only Responses carries a chain id');
});

// -----------------------------------------------------------------------------
//  Non-streaming (the proxy path)
// -----------------------------------------------------------------------------

test('Responses non-streaming: walks output[].content[], skipping reasoning items', async () => {
    const { AI } = load({
        resolved: RESPONSES,
        aiProxy: () => ({
            status: 200,
            body: JSON.stringify({
                id: 'resp_789',
                error: null,                                   // present-but-null on success
                output: [
                    { type: 'reasoning', summary: [] },        // must be skipped
                    { type: 'message', content: [
                        { type: 'output_text', text: 'Parsed ' },
                        { type: 'output_text', text: 'reply' },
                    ] },
                ],
            }),
        }),
    });

    const result = await AI.sendChat(1, MESSAGES, { stream: false });

    assert.equal(result.text, 'Parsed reply');
    assert.equal(result.responseId, 'resp_789');
    assert.equal(result.usedFallback, true);
});

test('Responses non-streaming: an output with no text part is a clear error', async () => {
    const { AI } = load({
        resolved: RESPONSES,
        aiProxy: () => ({ status: 200, body: JSON.stringify({ id: 'r', output: [{ type: 'reasoning' }] }) }),
    });

    await assert.rejects(() => AI.sendChat(1, MESSAGES, { stream: false }), /no output text/i);
});

test('a CORS/network TypeError falls back to the proxy without streaming tokens', async () => {
    const { AI, proxyCalls } = load({
        resolved: RESPONSES,
        corsFailure: true,
        aiProxy: () => ({
            status: 200,
            body: JSON.stringify({ id: 'resp_1', output: [{ type: 'message', content: [{ type: 'output_text', text: 'Via proxy' }] }] }),
        }),
    });

    const tokens = [];
    const result = await AI.sendChat(1, MESSAGES, { stream: true, onToken: (d) => tokens.push(d) });

    assert.equal(result.text, 'Via proxy');
    assert.equal(result.usedFallback, true, 'panel.js keys off this to take result.text wholesale');
    assert.deepEqual(tokens, [], 'onToken must never fire on the proxy path');
    assert.equal(proxyCalls.length, 1);
});

// -----------------------------------------------------------------------------
//  The self-healing retry
// -----------------------------------------------------------------------------

test('a rejected previous_response_id retries once with the full history', async () => {
    const { AI, proxyCalls } = load({
        resolved: RESPONSES,
        aiProxy: (_req, callNo) => (callNo === 1
            ? { status: 404, body: JSON.stringify({ error: { message: "Previous response with id 'resp_gone' not found." } }) }
            : { status: 200, body: JSON.stringify({ id: 'resp_new', output: [{ type: 'message', content: [{ type: 'output_text', text: 'Recovered' }] }] }) }),
    });

    const result = await AI.sendChat(1, MESSAGES, { stream: false, previousResponseId: 'resp_gone' });

    assert.equal(proxyCalls.length, 2, 'exactly one retry');
    assert.equal(proxyCalls[0].parsedBody.previous_response_id, 'resp_gone');
    assert.equal(proxyCalls[0].parsedBody.input.length, 1, 'first attempt chained');
    assert.equal(proxyCalls[1].parsedBody.previous_response_id, undefined, 'retry drops the chain');
    assert.equal(proxyCalls[1].parsedBody.input.length, 3, 'retry resends the whole transcript');
    assert.equal(result.text, 'Recovered');
    assert.equal(result.responseId, 'resp_new');
});

test('a genuine provider error is NOT retried away', async () => {
    const { AI, proxyCalls } = load({
        resolved: RESPONSES,
        aiProxy: () => ({ status: 401, body: JSON.stringify({ error: { message: 'Invalid API key' } }) }),
    });

    await assert.rejects(
        () => AI.sendChat(1, MESSAGES, { stream: false, previousResponseId: 'resp_abc' }),
        /Invalid API key/,
    );
    assert.equal(proxyCalls.length, 1, 'a 401 is not a stale chain');
});

test('the retry is skipped once tokens have already streamed (no duplicated reply)', async () => {
    // A provider that rejects the chain mid-stream, after emitting text. Re-running
    // would append a second copy of the reply to the caller's accumulator.
    const sse = [
        'data: {"type":"response.output_text.delta","delta":"partial"}',
        '',
        'data: {"type":"response.failed","response":{"error":{"message":"previous response not found"}}}',
        '',
    ].join('\n');

    const { AI, proxyCalls } = load({
        resolved: RESPONSES,
        fetch: async () => ({ ok: true, body: sseBody(sse, 2) }),
        aiProxy: () => ({ status: 200, body: JSON.stringify({ id: 'x', output: [] }) }),
    });

    const tokens = [];
    await assert.rejects(
        () => AI.sendChat(1, MESSAGES, { stream: true, previousResponseId: 'resp_abc', onToken: (d) => tokens.push(d) }),
        /previous response not found/,
    );
    assert.deepEqual(tokens, ['partial'], 'the token streamed exactly once');
    assert.equal(proxyCalls.length, 0, 'no retry after tokens were handed to the caller');
});

test('a user abort propagates and is never retried', async () => {
    const { AI, proxyCalls } = load({
        resolved: RESPONSES,
        fetch: async () => {
            const err = new Error('aborted');
            err.name = 'AbortError';
            throw err;
        },
    });

    await assert.rejects(
        () => AI.sendChat(1, MESSAGES, { stream: true, previousResponseId: 'resp_abc' }),
        (err) => err.name === 'AbortError',
    );
    assert.equal(proxyCalls.length, 0, 'a cancel must not silently fall back to the proxy');
});
