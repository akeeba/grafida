---
description: Grafida's internal boson:// API — the Router, the per-area controllers, exception-to-status mapping, the two-kinds-of-transport-failure rule (gh-29) and the no-store caching rule (gh-35). Lifted verbatim from CLAUDE.md's Layout section.
paths:
  - "src/Http/**"
  - "src/Application/**"
  - "src/Debug/**"
---

# The internal API (`src/Http/`)

The `src/Http/` notes from `CLAUDE.md`'s `## Layout` section. Verbatim — the leading `- ` and
two-space continuation indent are the original bullet formatting.

- `src/Http/` — `HttpClient` (curl/stream transport to Joomla), `Json`, and the internal API.
  `ApiController` is now only a **dispatcher** (~120 lines): it assembles a `Router` from the
  controllers and maps exceptions to responses (`PublishBlockedException` → 422,
  `SecureStoreUnavailableException` → 409, `ApiException` → 502, `HttpException` → 503,
  `\Throwable` → 500).
  The 422 carries **`fieldLabels`, `overridableLabels` and `canForce`** (gh-59): the first two split
  the required custom fields Grafida cannot edit by whether the draft holds a value for them, and
  `canForce` says whether a retry with `{force: true}` could succeed. The SPA's `apiFetch()` lifts
  all three onto the thrown `Error` — along with **`err.upstreamStatus`**, which is the payload's
  `status` (what the *site* answered) and is emphatically **not** `res.status`, our own kernel's 502.
  That distinction is what lets a publish tell a Joomla form-validation 400 from any other API
  failure.
  ⚠️ **A transport failure is not one error, but two** (gh-29). `HttpClient::requestCurl()` now
  passes `curl_errno()` through on `HttpException`, and `isConnectivityFailure()` checks it
  against the errnos that mean "never reached a server" (DNS failure, refused/unreachable
  connection, timeout — `6`/`7`/`28`, plus the send/recv/proxy variants). `ApiController::dispatch()`
  maps a connectivity failure to `{code: "network_unreachable"}` / HTTP 503 with the raw cURL text
  demoted to a `detail` field, and anything else (a TLS handshake failure, say) to
  `{code: "transport"}` / 503 — deliberately **not** the friendly wording, since telling someone to
  check their internet connection over a bad certificate would be actively misleading. The
  stream-wrapper fallback (`requestStream()`, used when ext-curl is absent) always constructs with
  `curlErrno = 0`, so it degrades to the generic `transport` code — it has no machine-readable cause
  to classify.
  `Router` holds a real route table — `{name}` placeholders compile to anchored regexes (`{key}` →
  `[A-Za-z0-9_\-]+`, `{file}` → that plus a short extension, anything else → `\d+`; none of the
  three admits a `/` or a bare `..`, so a traversal attempt matches no pattern and dies at the
  router's own 404 rather than reaching a handler), and
  handlers resolve their controller **from the container on match**, so a request builds one
  controller, not nine. A path that matches with an unregistered method returns **405**; an
  unmatched path **404**. `RouteContext` carries the matched parameters, the parsed body and
  the request. The handlers live in `src/Http/Controller/`: `BootstrapController`,
  `SiteController`, `ArticleController`, `DraftController`, `MediaController`,
  `AiServiceController`, `AiChatController`, `SettingsController`, `HelpController` — each a
  container service taking **only** the collaborators it uses (1–7 each; the old `ApiController`
  had 24). `HelpController` (`/api/help…`, the bundled documentation — see
  `.claude/rules/documentation.md`) is the extreme case and a useful reminder of the shape: one
  dependency, no site, no network, no database. The
  abstract `Controller` base is deliberately **dependency-free** (only the `str()`/`int()` body
  parsers); the shared site/article helpers (`requireSite`, `connectedSite`, `siteArray`,
  `withCategoryTitles`, the JSON:API relationship readers) live in `Grafida\Http\SiteContext`,
  an injected collaborator — composition, not a god base class. Controllers must never call
  each other; share through the injected services.
  ⚠️ **`SiteContext::withCategoryTitles()` looks the categories up best-effort** (`categories($site,
  false, true)`), and must stay that way (gh-29). A category *title* is a decoration on a list that
  is already in hand, so a site we cannot reach must never fail the list itself — and one of its two
  callers, `DraftController::listDrafts()`, is otherwise a **purely local** read. A strict lookup
  there is what took the whole Articles screen down on an offline machine with a cold reference
  cache: the Local Articles tab needs no network at all, but the screen-level fetch threw before it
  could render, so only the error block was left. Offline, the drafts tab must work and only the
  Remote Articles tab may show an error.
  ⚠️ **Nothing the internal API answers may be cached by the webview** (gh-35). `boson://app/api/…`
  is an ordinary URL as far as WKWebView/WebView2 are concerned, so a GET whose response says
  nothing about freshness is cached heuristically — in a **disk-backed, app-scoped** cache that
  outlives an app restart *and* a local-storage reset (after a reset the next site is id 1 again,
  so the very same URL can be answered from a pre-reset response with our PHP never running).
  This was found while investigating gh-35 and is **not** what caused it — that was a
  `reference_cache` snapshot from the site the record was originally connected to — but it is a
  real hazard with the same symptom, so it is closed off. Two independent opt-outs:
  the SPA's single `apiFetch()` chokepoint sends **`cache: 'no-store'`** — the load-bearing one,
  since suppressing the *lookup* is what makes an already-poisoned entry self-heal — and
  `Http\Json::response()` sets `Cache-Control: no-store` on every response so they are
  self-describing for any caller that does not go through `apiFetch()`. Note this is unrelated to
  the **`reference_cache`** SQLite cache (see `src/Reference/`), which is deliberately permanent
  and authoritative for rendering — the manual Refresh button remains — but which the SPA also
  quietly freshens in the background since gh-42 (see `src/Reference/` and the `assets/private/`
  notes below).
  `SiteController` also exposes **Diagnose Connection** (`POST /api/sites/diagnose`, delegating
  to `Site\ConnectionDiagnostics`) alongside the existing `/api/sites/test`, and
  `SettingsController` exposes the Request Log (gh-37, see `src/Debug/`): `POST
  /api/settings/request-log` (the on/off toggle), `GET /api/request-log` (the stored entries),
  `POST /api/request-log/clear`, and `POST /api/request-log/export` — which, like
  `DraftExportService`'s `.grafida` export, asks for a destination **folder** rather than a
  file (`POST /api/dialog/select-directory`): Boson's `DialogApiInterface` has no Save-As
  dialog, so the filename (`grafida-request-log-<timestamp>.json`) is derived and the file
  written server-side instead.

# The native event loop (`src/Application/BosonApplication`, `EventLoopThrottle`)

⚠️ **Boson's event loop is a busy-wait, and it costs about half a CPU core with the app idle.**
`Boson\Application::run()` is `do { $poller->next(); } while ($this->isRunning)`, and the stock
`SaucerPoller::next()` separates two iterations by `usleep(1)` — *one microsecond*. It rotates
between three task types, and every third iteration crosses FFI into
`saucer_application_run_once()`, which pumps the entire native event loop (on macOS, a full
`-[NSApplication nextEventMatchingMask:untilDate:inMode:dequeue:]` round trip through the
CFRunLoop). At tens of thousands of iterations a second that is a permanent, load-independent CPU
burn. Measured with Grafida sitting idle on the Articles screen on an M5: **~49% of a core** in the
PHP process — while the WebKit content process that was actually rendering the window sat at 0.0%.

`EventLoopThrottle` fixes it by adding a **2 ms sleep after an idle iteration**, which takes the
same measurement to **~1% of a core**. The loop still pumps native events several hundred times a
second, i.e. an event is picked up well inside one frame at 120 Hz.

Three things about its shape are deliberate:

- **It is a decorator over the poller `Application::createApplicationPoller()` builds, not a
  replacement for it.** That poller carries a deferred task which flips `Application::$isRunning`
  and dispatches `ApplicationStarted`; the closure captures state a subclass cannot write
  (`$isRunning` is `public private(set)`, `$listener` is private), so a from-scratch poller would
  have to reimplement Boson's `@internal` microtask bookkeeping and could never start the app.
  `BosonApplication::createApplicationPoller()` therefore calls `parent::` and wraps the result.
- **The throttle must be woken, or it trades idle CPU for per-request latency.** A sleeping loop
  notices a `boson://` request up to three sleeps late. Once, that is invisible; charged to *every*
  request in a burst it is not. So `index.php` calls `$app->wake()` after the front controller
  answers, which runs the loop unthrottled for 100 ms — a page's worth of API calls goes at the
  upstream loop's full speed, and only a genuinely quiet app is throttled. Anything else that
  learns the app is busy should call `wake()` too.
- **It is not a Grafida-specific problem and the fix is not macOS-specific.** The same busy loop
  runs on WebKitGTK and WebView2; the throttle sits above the platform layer and helps everywhere.

`tests/Unit/EventLoopThrottleTest.php` pins the two behaviours that matter — an idle iteration
sleeps, an iteration inside the wake window does not.
