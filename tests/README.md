# Grafida tests

```bash
composer test          # everything: unit + integration + feature + JS
composer test:unit
composer test:feature
composer test:integration
composer test:js
composer linter:check  # PHPStan, level max + strict rules (src/ only)
```

Everything runs with **no configuration**. The live provider tests skip themselves when they
are not configured, so a clean checkout is green.

## The suites

| Suite | What it covers |
|---|---|
| **unit** (`tests/Unit/`) | Pure PHP units — the Joomla API client, HTML splitting/rebasing, secret stores, repositories, the AI provider table and proxy. Fast, no network, in-memory SQLite. |
| **feature** (`tests/Feature/`) | The back end end-to-end through the kernel. The kernel is a pure `Request → Response` function, so the whole API is testable **without opening a window** — no Boson, no webview. |
| **integration** (`tests/Integration/`) | Boson application stubs, plus the **live AI provider tests** below. |
| **js** (`tests/js/`) | The AI transport, `assets/private/js/ai/providers.js`. |

### Why there is a JavaScript suite

The AI provider call **runs in the SPA's JavaScript**, not in PHP — that is what makes streaming
possible (the `boson://` kernel buffers whole responses and cannot stream). PHPUnit therefore
cannot reach `providers.js` at all, and it is the riskiest code in the AI feature: three wire
dialects, two SSE formats, a CORS fallback and a retry path.

`tests/js/providers.test.mjs` covers it with **node's built-in test runner** — no bundler, no new
dependency (node is already a build prerequisite, since it vendors TinyMCE/CodeMirror/FontAwesome).
It loads the browser IIFE into a `vm` context with a fake `window`, `fetch` and `api`, then drives
the real code.

> **Gotcha if you extend it:** `providers.js` detects a CORS/network failure with
> `err instanceof TypeError`. The sandbox is a separate realm, so a `TypeError` minted in the test
> file is *not* an `instanceof` the sandbox's `TypeError` and the fallback would never trigger. Use
> the harness's `corsFailure: true` option, which mints the error inside the sandbox.

## Configuring the live provider tests

`tests/Integration/Ai/ResponsesApiLiveTest.php` talks to a **real OpenAI Responses API server**.

**What it is for.** Because the transport lives in JavaScript, these tests cannot exercise
`providers.js` either. What they *can* do — and nothing else does — is pin the **wire-format
assumptions `providers.js` is built on** against a real server: the `output[] → message →
output_text` shape (with `reasoning` items interleaved), that `instructions` steers the reply, that
streaming emits typed events and **no `[DONE]` sentinel**, that a `previous_response_id` genuinely
resumes the conversation server-side, and that a *stale* one is genuinely rejected (the self-healing
retry has nothing to trigger on otherwise). If the API's shape ever changes, the SPA would break
silently inside the webview; these fail loudly instead.

**Setup.** Copy the sample and fill it in — `tests/.env` is gitignored, so your key stays local:

```bash
cp tests/.env.sample tests/.env
$EDITOR tests/.env
composer test:integration
```

| Key | Required | Meaning |
|---|---|---|
| `GRAFIDA_TEST_RESPONSES_ENDPOINT` | **yes** | Base URL, *without* `/responses` (the test appends it). |
| `GRAFIDA_TEST_RESPONSES_MODEL` | **yes** | A model that supports the Responses API. |
| `GRAFIDA_TEST_RESPONSES_KEY` | no | API key. Leave empty for a local server that needs no auth. |
| `GRAFIDA_TEST_RESPONSES_PROVIDER` | no | The `providers.json` key the test service is built from. Only affects `AiProxy`'s host allow-listing, so the default `custom_responses` suits any endpoint; use `openai` to exercise that preset. |

Set neither required key and the tests **skip** with a message naming them. A variable exported in
your shell overrides `tests/.env`, so a one-off run needs no edit:

```bash
GRAFIDA_TEST_RESPONSES_MODEL=gpt-5 composer test:integration
```

**A local server works too**, and is the cheap way to run these — point `ENDPOINT` at e.g.
`http://192.168.1.10:1234/v1` and leave `KEY` empty. Two caveats specific to local servers:

- These tests go through PHP (`AiProxy` → curl), so the app's usual browser-side constraints — the
  server needing **CORS** enabled, and macOS **ATS** blocking plain HTTP from the webview — do
  **not** apply here. A server that passes these tests can still fail in the running app for those
  reasons; see the AI facts in `CLAUDE.md`.
- `testUnknownPreviousResponseIdIsRejected` asserts that a garbage chain id is rejected with a 4xx.
  OpenAI does this. A local server may simply *ignore* the unknown field instead — if that one test
  fails, it is telling you something real about that server (the self-healing retry has nothing to
  trigger on there), not that the test is wrong.

These tests cost real tokens and make several sequential model calls, so they are deliberately not
part of any automated loop.
