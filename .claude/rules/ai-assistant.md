---
description: Grafida AI assistant — JS-primary streaming transport, the three SSE dialects, Responses-API chaining, CORS/ATS traps, multimodal rules and the panel UI. Lifted verbatim from CLAUDE.md.
paths:
  - "src/Ai/**"
  - "assets/private/js/ai/**"
  - "src/Http/Controller/AiServiceController.php"
  - "src/Http/Controller/AiChatController.php"
  - "tests/Integration/Ai/**"
---

# Grafida AI assistant facts

Moved verbatim out of `CLAUDE.md` so it loads only when you are working on the AI assistant.
The PHP-side service inventory stays in `CLAUDE.md`'s `src/Ai/` Layout bullet.

## Key AI assistant facts

- **Transport is inverted vs. AITiny (JS-primary, not PHP-primary).** The `boson://` kernel cannot
  stream — `Http\Json::response()` buffers the whole body and the SPA awaits `res.json()` whole — so
  the **provider call runs in the SPA's JavaScript**, which streams the SSE response token-by-token.
  `assets/private/js/ai/providers.js` (`window.GrafidaAI`) ports AITiny's provider request builders +
  **three** wire dialects, switched on `sse_dialect` at three sites (`buildRequest`, `readSseStream`,
  `parseFullResponse`):
  **`openai_completions`** — Chat Completions (`data:` lines, `choices[].delta.content`, `[DONE]`
  sentinel). This is the `else` branch at all three sites, so **any unknown or legacy dialect value
  degrades to it** — that is the whole backward-compatibility story, no aliasing needed.
  **`anthropic`** — `event:`/`data:` pairs, `content_block_delta`/`text_delta`, `message_stop`.
  **`openai_responses`** — OpenAI's Responses API (`/responses`), used by the **OpenAI** and **Custom
  (OpenAI Responses API)** providers *only*; every OpenAI-*compatible* third party (Scaleway, Groq,
  Mistral, …) stays on `openai_completions`, since they implement Chat Completions and have no
  Responses API. It sends `instructions` + `input` (**not** `messages`) and `max_output_tokens` (mapped
  from the `max_completion_tokens` param); its SSE is `event:`/`data:` pairs where every payload carries
  a `type`, so the parser dispatches on `type` and ignores the `event:` lines — `response.output_text.
  delta` carries the text and `response.completed` is terminal, with **no `[DONE]` sentinel**. A
  non-streaming reply nests its text under `output[]` → `type:"message"` → `content[]` →
  `type:"output_text"` (interleaved `reasoning` items must be skipped), not at a single path.
  `sendChat()` fetches `GET /api/ai/services/{id}/resolved` (endpoint + dialect + model + params **and
  the API key**), then streams directly from the provider. **PHP stays the source of truth** for
  services, prompts/tools and saved chats — only the HTTP call moved to JS.
- **The Responses API is stateful; `_history` is still authoritative.** With the per-service **Store**
  param (`store`, **default on**) the provider retains the response, so a follow-up sends only the new
  user turn plus `previous_response_id` instead of re-uploading the whole transcript — which matters
  because Grafida embeds the entire article HTML in the first user turn. The chain is **persisted on
  the chat** (`ai_chats.previous_response_id` / `.last_response_at`) so a remembered conversation
  resumes server-side across restarts. `panel.js` reuses it only when *all* hold: the id exists, it
  came from the **same service** (a tool may target a different one, and a chain from service A is
  meaningless to service B), and it is within the service's **retention window** (`store_retention_days`,
  **default 15**). Both params are shown in Settings **only** for providers whose dialect is
  `openai_responses` — the UI gates on the *dialect*, never the provider key, so a new Responses
  provider needs no JS change. Two rules keep this safe: **any abort or provider error clears the
  chain** (an aborted response may be stored partial, and the error path already retracts the user turn
  from `_history`, so the server copy no longer mirrors it), and a `previous_response_id` the provider
  **rejects** (HTTP 404 / "previous response not found") triggers **one automatic retry with the full
  history** — the window is a guess about the provider's retention policy, not a guarantee, so an
  expired chain must degrade to a working call rather than surface as an error. Correctness never
  depends on the chain; it is purely an optimisation. `.grafida` exports deliberately **omit** it (a
  response id is a local, provider-specific artefact, like `site_id`/`media_blobs` ids).
- **`POST /api/ai/proxy` is the non-streaming fallback.** When a provider's browser **CORS** blocks the
  direct `fetch()` (caught as a `TypeError`) or streaming is off, `sendChat()` retries once through this
  **dumb, host-allowlisted forwarder** (`AiProxy` validates the target host equals the configured
  service endpoint host — no open relay — and never injects the key; the JS supplies headers).
- **LM Studio (and other local OpenAI-compatible servers) MUST have CORS enabled.** The direct
  streaming `fetch()` runs from the webview's `boson://app` origin, so a JSON POST triggers a CORS
  **preflight**. If the local server doesn't answer it (LM Studio defaults to CORS **off** — turn on
  *Enable CORS* in its Developer/server settings, or run the server with the CORS flag), the direct
  fetch fails, `sendChat()` falls back to `POST /api/ai/proxy`, and that **synchronous** PHP call
  **occupies the single-threaded `boson://` kernel for the whole reply** — so the rest of the UI's
  API calls stall ("the interface freezes") and nothing streams (the answer arrives all at once).
  Enabling CORS restores live streaming and keeps the kernel free. This is the first thing to check
  when a user reports the AI panel hanging with a local model.
- **On macOS, plain-HTTP provider endpoints also need an ATS exception, or every reply falls back to
  the proxy regardless of CORS.** `WKWebView` (the webview macOS Boson uses) enforces App Transport
  Security on JS-initiated `fetch()`/XHR from web content; a plain-`http://` endpoint — e.g. LM Studio
  on a LAN box (`http://192.168.x.x:1234`) — is blocked before the request leaves the process, thrown
  as a network `TypeError` just like a CORS failure, so `sendChat()` silently takes the same synchronous
  proxy path even with CORS fully enabled server-side. `scripts/make-macos-app.sh` sets
  `NSAppTransportSecurity → NSAllowsArbitraryLoadsInWebContent` in the generated `Info.plist` to lift
  this for the webview specifically (Joomla REST calls are unaffected — those go through PHP `curl`
  server-side, never through the webview, so ATS never applied to them). Linux (WebKitGTK) and Windows
  (WebView2) have no equivalent restriction.
- **The API key is handed to local JS per call.** This is a deliberate desktop-only trade-off (JS and
  PHP are equally-trusted local code; the SPA loads no remote content) and the price of streaming —
  do not "fix" it by moving the call back to PHP (that kills streaming).
- **Multimodal is a per-service opt-in, and `content` stays a string.** A model that can see gets the
  article's pictures alongside its HTML, gated on the service's **`multimodal`** param — another
  `params_json` key like `stream`/`store`, so it needs **no migration**. Unlike those it defaults
  **off**: most models are text-only and reject an image part outright, so it cannot be inferred; the
  Settings AI-Services form offers it as a plain Yes/No for **every** dialect (all three support
  vision). The turn shape is the load-bearing decision: `content` remains a **plain string**
  everywhere — `_history`, `ai_chat_messages`, `_renderRichText`, `_stripDocContext`, `.grafida`
  export — and the images ride alongside as a separate **`images`** array of base64 data: URIs on the
  first user turn (like the display-only `tool` flag). `providers.js`'s **`toWireTurn()`** folds them
  into the dialect's array-of-parts shape **on the wire only**, so a turn with no images produces a
  byte-identical request to before: `openai_responses` → `input_text`/`input_image` (a bare data:
  URI), `anthropic` → `text` + `image` with the base64 payload and `media_type` **split apart** (it
  will not take a data: URI, so an unparseable one is dropped rather than sent malformed),
  `openai_completions` (and any unknown dialect) → `text`/`image_url`. `images` is **not** persisted
  with a remembered chat — the pictures belong to the article, which is re-read on every fresh
  conversation. Chaining is unaffected: images ride with the doc context, which only ever goes in the
  first turn.
- **The article's images are collected through three different paths** (`_resolveImageSource()` in
  `panel.js`, called by `_collectDocumentImages()` as it walks the editor body in document order).
  A tagged `data-grafida-media-id` image — which since gh-36 is how a pasted/dropped/picked local
  image is inserted (`boson://app/api/media/{id}/raw?rev=…` `src`, not a `data:` URI; see
  `src/Media/`) — is fetched by id via `GET /api/media/{id}`, the same JSON data:-URI endpoint the
  intro/full-text preview uses, so this path is unaffected by *how* the blob is referenced; a bare
  `data:` URI with no tag (still possible for a paste that has not run the tagging hook yet, or an
  as-yet-unmigrated legacy draft body) is used as-is; and an **already-published** image is a plain
  URL the webview **cannot fetch itself** — the same CORS/ATS wall the AI transport hits — so
  `GET /api/sites/{id}/image?url=…`
  (`MediaController::siteImage()` → **`Media\SiteImageFetcher`**) pulls the bytes server-side and
  returns a data: URI. That fetcher mirrors `AiProxy`'s allowlist: the resolved URL's host must equal
  the site's own, so an image on a CDN or a hotlinked third party is **refused and skipped**, not
  fetched; it sniffs the MIME from the bytes (a misconfigured server's `Content-Type` would only get
  rejected by the vision API later) and caps the size. It needs **no API token** — the image is
  public — so it only requires the site to exist, not to be connected. One unreachable picture never
  fails the message: it is dropped and the rest still go.
- **Images are downscaled to 1024px and capped at 8 per request** (`_downscaleImage()`, a canvas
  re-encode to JPEG). Joomla bakes a photo's full intrinsic size into the tag, so an article image is
  routinely 4000px — several MB of base64 per turn, for a picture every vision model downsamples on
  arrival anyway. A decode/canvas failure falls back to the original URI: an oversized image beats
  none.
- **UI:** a docked right-hand `#ai-panel` in the editor (`assets/private/js/ai/panel.js`) hosts the
  streaming conversation; the **document (title + HTML) is embedded as context in the first message**
  and follow-ups resend the whole history. A TinyMCE **AI Assistant** toolbar button toggles the panel
  and an **AI tools** menu button runs any configured writing tool against the document; that menu also
  always ends with a separated **"Custom…"** item (`GrafidaAIPanel.openCustom()`) — present even when no
  tools are configured — that opens the panel with an empty chat for a free-form prompt, so the
  ask-anything path is discoverable from the tools menu, not only via the AI Assistant toggle.
  Each tool item shows its FontAwesome icon (matching the Settings AI Tools list): TinyMCE's icon
  registry cannot use the FA webfont directly, so `addIcon` is fed an **HTML `<span class="fa-solid
  fa-…">`** (not an SVG) whose FA `font-family`/`font-weight` are harvested once at runtime from
  `::before` via `getComputedStyle` (`faIconInlineStyle()` in `app.js`) — the SVG-free webfont
  technique ported from AITiny. Tool `icon` values are stored **prefix-less** (`check`, not
  `fa-check`) since the render sites prepend `fa-solid fa-`. The Settings AI-Tools form picks the
  icon with `iconPicker()` (`app.js`) — a searchable drop-down of every icon rendered as its glyph —
  not a type-the-name text field; it keeps the chosen name in a hidden input, so callers read it like
  a plain text field. Its catalogue comes from `iconCatalog()`, which **parses the shipped
  `css/fontawesome.min.css` at runtime** (each icon is a `.fa-<name>{--fa:"\f0c5"}` rule) rather than
  hard-coding a list — FontAwesome is NPM-managed and gitignored, so a bundled list would rot on
  every version bump, and that stylesheet only carries names we ship a webfont for. A rule may group
  several comma-separated alias selectors for one glyph (`.fa-dollar-sign,.fa-usd{…}`); **take every
  name in the group** — matching only the last selector silently drops ~479 names (searching
  "dollar" would find nothing), and the selector order is alphabetical, so it cannot tell an alias
  from the canonical name. The grid renders in 240-cell pages as it scrolls, so a keystroke never
  re-lays-out all ~2000 icons.
  **Both AI toolbar entries (`aitools aiassistant`) are only added to the editor toolbar when at least
  one AI service is configured** (`hasAiService` in `initTinyMCE()` gates the `aiToolbarSegment`
  appended to the `toolbar` string from `State.aiServices.length`): with no provider connection there
  is nothing for them to talk to, so they would be a dead end. The buttons are still *registered* in
  `setup` (harmless) — they are merely omitted from the toolbar layout. The gate is read at editor
  init, so adding a first service then re-opening the editor makes them appear.
  **Every entry point that shows the panel (the toolbar toggle, a tool, "Custom…") leaves TinyMCE's
  fullscreen mode first** (`_exitEditorFullscreen()` in `panel.js`, via the fullscreen plugin's
  `isFullscreen()` + the `mceFullScreen` toggle command): the panel is docked in the app layout,
  *outside* the TinyMCE container, so a fullscreen editor paints its viewport-filling overlay over
  it and the button looks inert. In fullscreen the toggle therefore always **opens** (never closes)
  the panel — a panel the user cannot see is not one they meant to close — and an already-open panel
  is revealed as-is rather than re-opened, since `_openPanel()` resets the conversation. The panel has
  a **header** (`#ai-panel-header`) with the title plus **New chat** (`#ai-btn-new`, offers to remember
  the current chat then resets) and **Close** (`#ai-btn-close`, runs the close/remember flow and hides)
  buttons — the TinyMCE toolbar toggle is no longer the only way to close it. Each reply
  offers Insert-into-editor / Copy. Closing a non-empty chat offers to **remember** it: an unsaved
  draft is auto-saved first, a blank title is auto-generated via a short non-streaming completion, and
  the transcript is saved. Saved chats appear in the panel's **AI Chats** banner (open/continue/rename/
  delete). Assistant replies are the model's HTML (or Markdown, for the Generate tool); the panel
  renders them as **formatted** text — **and so are user/tool prompt bubbles** (`_buildUserBubble()`),
  since tool prompts and many typed prompts are Markdown. A **tool's prompt is styled apart from what
  the user typed**: `openWithTool()` sends the tool's prompt verbatim as the first user turn, and on the
  accent bubble that long instruction block dominated the panel (and read as shouting), so it renders as
  `.ai-bubble-instructions` — muted surface, a thin accent stripe down its right edge, and a wand-icon
  **“Instructions”** header (`GRAFIDA_LBL_AI_INSTRUCTIONS`) naming what it is; typed turns keep the accent
  bubble. `_isToolPrompt()` decides: the `tool` flag `_sendMessage()` writes onto the `_history` entry is
  authoritative, and since that flag is **display-only** it is stripped when the messages array is
  assembled (`_history` is mapped to bare `{role, content}` — never send it to a provider) and is
  therefore absent from a chat reloaded from the DB, where the fallback matches the text against the
  configured tools' prompts instead. Because the output is untrusted, rendering is
  **sanitised server-side**: `panel.js`'s `_renderRichText()` shows the raw text as plain text first
  (always-safe placeholder) then calls `POST /api/ai/render`, which `Ai\AiRenderer` turns into safe
  HTML — auto-detecting Markdown vs HTML, converting Markdown via the existing CommonMark
  `MarkdownService`, and sanitising the result with **Symfony's `HtmlSanitizer`** (the W3C safe-element
  subset + relative links/medias, **plus the `class`/`style` attributes** — article markup relies on
  editor.css classes and inline styling, and Insert drops this same sanitised HTML into the article, so
  stripping them would mangle styled content; script/iframe/event-handlers and `javascript:` URLs stay
  blocked). Only that returned HTML is set as `innerHTML`; if the call fails the
  plain-text placeholder stays. **Streaming replies format live** (chatbot-style), not only at the end:
  `_createStreamRenderer()` re-renders the accumulating reply through the same `/api/ai/render` pipeline,
  throttled to ~200 ms, with sequenced results so a slow/stale render can never roll the view backwards;
  `finish()` does the authoritative final render. Reflow jumps as blocks resolve are expected/acceptable.
  **A reasoning model's "thinking" is shown live and kept out of the reply.** A reasoning model can
  spend a long time on its scratchpad before the first word arrives, which reads as a stuck panel, so
  `readSseStream()` surfaces reasoning deltas through a **separate `onThinking`** callback (never
  accumulating them into the returned `text`) and `panel.js`'s `_createThinkingBlock()` lazily prepends
  a collapsible block to the streaming bubble: a brain icon + a pulsing italic muted
  `GRAFIDA_LBL_AI_THINKING` line, click-to-toggle the scratchpad itself, relabelled
  `GRAFIDA_LBL_AI_THOUGHT_PROCESS` (pulse off) on the first reply token. The field is per-dialect and
  there is **no Chat Completions standard**: `openai_responses` → `response.reasoning_summary_text.delta`
  *and* `response.reasoning_text.delta` (which one you get depends on the model and on whether a summary
  was requested); `anthropic` → the `thinking_delta` variant of `content_block_delta`;
  `openai_completions` → `delta.reasoning_content` (DeepSeek, and LM Studio which follows it) **or**
  `delta.reasoning` (OpenRouter). A provider emitting none simply never fires the callback and no block
  appears. The scratchpad is Markdown as often as the reply is, so it gets its **own**
  `_createStreamRenderer()` and is formatted through the same `/api/ai/render` (CommonMark + sanitiser)
  pipeline — but **only while unfolded**: each render is a round-trip through the single-threaded
  `boson://` kernel, so formatting text nobody is looking at would compete with the reply's own renders.
  The accumulated text is kept as a `textContent` placeholder meanwhile, so unfolding is instant and the
  formatting lands a moment later. That renderer is created with `scrollConversation: false` (its
  `onApply` scrolls the block's own capped-height box instead) — the block can be unfolded long after
  its message scrolled away, and re-rendering it must not yank the conversation to the bottom. The
  typography comes free because the prose rules in `app.css` are keyed on **`.ai-rich` alone**, not
  `.ai-bubble-text.ai-rich`: that class is only ever set by the render pipeline, so it *means*
  "sanitised rendered prose"; only rules that depend on a bubble's background stay bubble-scoped.
  The thinking lives **outside `.ai-bubble-text`**, so the reply's own renderer never touches it, and it
  is deliberately **not** in `_history`: Insert/Copy operate on the reply string alone, and the
  scratchpad is neither resent to the provider nor saved with a remembered chat.
  **Copy** uses the **raw** model output; **Insert** re-renders it through the same
  `/api/ai/render` pipeline (Markdown→HTML + sanitise) before dropping it into TinyMCE — the reply is
  frequently Markdown (the Generate tool) or loose HTML, and `editor.insertContent()` needs real HTML,
  so inserting the raw text would leak literal Markdown (`**bold**`, `#` headings, …) into the article. The same `provider`/`tool` config is managed from two
  **Settings** cards (AI Services, AI Tools).
