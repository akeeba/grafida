# Grafida — AI assistant orientation

Grafida is a cross-platform **desktop application** (macOS, Windows, Linux) for creating
and editing **Joomla! articles** through the Joomla Web Services (REST) API. It is built in
**PHP 8.4** with [**Boson**](https://bosonphp.com), uses **SQLite** for all local storage
(via **`joomla/database`**'s `SqliteDriver`, wired through a **`joomla/di`** container),
and **TinyMCE 8** as the HTML editor. Licensed **GNU GPL v3 or later**. Dev happens on macOS.

## Scope (what we deliberately do NOT support)

Grafida is an **offline article editor**: it composes article HTML locally and publishes it
through the REST API. It is **not** the Joomla back-end and does **not** reuse the Joomla
WYSIWYG editor environment. Consequently we do **not** support — and will not try to emulate —
**page builders** (SP Page Builder, JSN, Quix, etc.), **editor-button/editor-xtd plugins**
(e.g. the article/image/page-break/module-insert buttons, sliders, tabs, third-party
shortcode buttons), or **custom/alternative media managers**. Article bodies are plain HTML
authored in TinyMCE; images go through Grafida's own offline media picker (see `src/Media/`),
not a site-side media-manager plugin. Don't add features that depend on server-side editor
plugins or builder shortcodes.

## How Boson works here (important)

Boson runs a native webview and bundles a PHP runtime. There is **no `webview->bind` RPC**.
Instead `index.php` registers a handler for the `boson://` scheme; every request is turned
into a PSR-style `Boson\Component\Http\Request` and answered with a `Response`. The
front-end (a plain HTML/CSS/JS SPA under `assets/private`) talks to PHP by calling
`fetch('boson://app/api/...')`.

Request flow: `index.php` → `Grafida\FrontController` → `Grafida\Application\Kernel` →
either `Grafida\Http\ApiController` (paths under `/api/`) or a static asset / the SPA shell.
**The kernel is a pure `Request → Response` function**, so the whole back-end is testable
without opening a window (see `tests/Feature/ApiRoutingTest.php`).

**The composition root is a DI container, not the Kernel.** `index.php` builds a
`Grafida\Application\Container` (a thin `Joomla\DI\Container` subclass — see `src/Application/`)
via `ContainerFactory::create()` and pulls `FrontController` out of it; `Kernel` is now just
`(StaticProviderInterface $static, ApiController $api)`. Nothing is `new`ed ad-hoc and there is
no global/singleton database object — add a service by registering it in a **service provider**
(`src/Application/Provider/`), not by editing a constructor chain.

**File pickers must go through the native dialog, not `<input type="file">`.** Boson's
webview does not wire up the HTML file-input open-panel callback (WKWebView on macOS,
WebKitGTK on Linux), so an in-page `<input type="file">` `.click()` silently does nothing.
`index.php` therefore passes `$app->dialog` (Boson's `DialogApiInterface`) into the container
as the **`dialog` parameter**, from where it reaches `SettingsController`, and the SPA opens
files via `POST /api/dialog/open-file` (`api.openFile(filter)`, filter
`image`/`markdown`/`any`): `SettingsController::openFile()` calls `selectFile()`, reads the
chosen file and returns `{name, mime, dataBase64}` (or `{cancelled:true}`). `uploadLocalImage()`
(intro/full-text images, the in-editor/media-browser "Choose file…" button) and
`importMarkdown()` consume it. The dialog dependency is **nullable** so the kernel stays
window-free in tests (a null dialog makes the endpoint return 503).

## Layout

- `src/Application/` — the **composition root**. `Container` is a thin `Joomla\DI\Container`
  subclass whose only job is to give `get()` a generic return type (the parent's is `mixed`,
  which PHPStan level max cannot use). `ContainerFactory::create(array $parameters = [])`
  registers the parameters, then the five `Provider/` service providers (`StorageProvider`,
  `HttpProvider`, `SiteProvider`, `AiProvider`, `AppProvider`, `ControllerProvider`). The
  parameters are the app's only configuration seams — override them and you get a different
  app without touching a constructor:
  `db.path` (default `Paths::databaseFile()`; `':memory:'` in tests), `migrations.dir`,
  `base.path`, `static.provider`, `dialog` (nullable), and `secret.store` — which is
  **tri-state**: `null` → `SecretStoreFactory::secureStore()` (production), `false` → no store
  (forces the insecure-plaintext fallback path), a `SecretStore` instance → used as-is.
  The `DatabaseInterface` factory **connects *and* migrates**, so every consumer receives a
  migrated database. `Kernel` is `(StaticProviderInterface, ApiController)`.
- `src/Http/` — `HttpClient` (curl/stream transport to Joomla), `Json`, and the internal API.
  `ApiController` is now only a **dispatcher** (~120 lines): it assembles a `Router` from the
  controllers and maps exceptions to responses (`PublishBlockedException` → 422,
  `SecureStoreUnavailableException` → 409, `ApiException` → 502, `HttpException` → 503,
  `\Throwable` → 500).
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
  `Router` holds a real route table — `{name}` placeholders compile to anchored regexes, and
  handlers resolve their controller **from the container on match**, so a request builds one
  controller, not nine. A path that matches with an unregistered method returns **405**; an
  unmatched path **404**. `RouteContext` carries the matched parameters, the parsed body and
  the request. The handlers live in `src/Http/Controller/`: `BootstrapController`,
  `SiteController`, `ArticleController`, `DraftController`, `MediaController`,
  `AiServiceController`, `AiChatController`, `SettingsController` — each a container service
  taking **only** the collaborators it uses (3–7 each; the old `ApiController` had 24). The
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
- `src/Joomla/ApiClient.php` — Joomla REST client: base-URL normalisation + probing, JSON:API.
  `probeApiBase()` remembers the first **connectivity** `HttpException` across the candidate bases
  and, when no candidate ever answers, rethrows it rather than reporting "no working API endpoint
  found" (gh-29) — offline must not be blamed on the URL. An auth failure (401/403 from a candidate
  that did answer) still takes priority over a transport failure on another candidate.
- `src/Secret/` — OS secret stores (macOS `security`, Linux `secret-tool`, Windows DPAPI) + factory.
  Windows DPAPI runs through **`WindowsDpapi`** (a direct FFI call into `crypt32.dll`), **not** a
  `powershell.exe` spawn — see the Windows build note below for why (the multi-second UI stall).
- `src/Site/` — site entity, repository, `SiteService` (token storage + connection test).
  `FaviconService` (5s fetch) parses the site home page for `<link rel="icon">` / Apple
  touch icons, downloads the largest one (falling back to `/apple-touch-icon.png` then
  `/favicon.ico`), and caches the raw bytes in `site_favicons` (`FaviconRepository`).
  `sync()` is best-effort, run when a site is connected/updated (and on the manual metadata
  refresh); the cached icon is sent to the SPA as each site's `favicon` data: URI (in the
  `bootstrap`/sites payloads) and shown as a 64×64 rounded square on the Sites page and below
  the sidebar site dropdown. Under that sidebar favicon sits a **Visit site** button
  (`GRAFIDA_BTN_OPEN_SITE`, rendered by `renderSidebarFavicon()`) opening the site's `baseUrl` in
  the OS browser via `api.openUrl()`; like the favicon it only exists while a site is selected,
  and the collapsed icon rail hides it along with the whole `#site-selector`.
- `src/Reference/` — cached categories/tags/levels/fields + `EditorCssService` (5s fetch, rebase, cache).
  `EditorCssService` does **not** guess the template. `TemplateDiscovery` learns the names from **two
  witnesses**, in order. First the **template styles API** (`ApiClient::listTemplateStyles()` →
  `GET v1/templates/styles/site`), which names the template behind each style outright; only the site's
  **home** styles are taken — `home = "1"` (the default) first, then any `home = "<lang tag>"`
  (a multilingual site's per-language homes) — because a style bound to a menu item says nothing about
  which item an article will render under, and an *unassigned* style names a merely-installed template
  whose `editor.css` must never outrank the honest fallbacks. Second, it scans the site's **home page**
  for the asset paths Joomla renders — `/media/templates/site/<name>/` (4.1+) or the legacy
  `/templates/<name>/`. It scans the raw HTML rather than the DOM: the name appears in `<link>`/`<script>`
  attributes but equally inside inline `@import`/`url()`, and each is an equally good witness. `system`
  is ignored (Joomla's shared assets, not a template), and the merged names are cached per site under the
  `template` kind so an unreachable site still resolves its template.
  ⚠️ **The API is not an optimisation — it is the only thing that can see a child template** (gh-3).
  Joomla resolves a child's assets against its parent whenever the child does not override them, so a
  child that ships nothing but an `editor.css` (which the front-end never loads) renders no asset URL of
  its own and is **structurally invisible** to any page scan — the home page names only the parent, whose
  `editor.css` is a 200 and would win forever. Keeping the page scan as the second witness is what makes
  that parent the *correct next candidate* when a child does inherit its parent's stylesheet.
  `EditorCssService::candidatesFor()` then tries, in
  order: the site's **manual `editor_css_url` override**, each discovered template's
  `css/editor.css` (media path then legacy), and finally the stock-Cassiopeia guesses, ending at
  `/media/system/css/editor.css` — Joomla's own shared editor stylesheet, which is what a template
  without an `editor.css` effectively falls back to. (The pre-5 `/templates/system/css/editor.css` is
  **not** a candidate: it 404s on a modern Joomla, costing only a timeout.) The override is
  a per-site column (an absolute URL or a site-root-relative path) surfaced as the Sites form's
  "Editor CSS URL" field — it exists for templates that serve the stylesheet from an unconventional
  place, which no amount of sniffing can find. ⚠️ Unlike the API token, an empty override **clears**
  the stored value rather than keeping it (`SiteService::update()`), so the form always sends the field.
  `ReferenceService` uses a short-timeout (8s) API client; `sync()` warms the cache best-effort
  when a site is connected/updated, and opening the editor falls back to cache per-list (only the
  manual refresh button surfaces fetch errors).
  ⚠️ **`reference_cache` is permanent server-side and stays authoritative for rendering — a
  screen always paints from the cache first — but it is no longer freshened *only* by the manual
  button** (gh-42; previously a category added on the site stayed invisible until the user pressed
  it, and the button itself missed the Articles screen's own filter-dropdown cache). `fetchedAt()`
  reports the **oldest** `fetched_at` across the five refreshable kinds (`KIND_CATEGORIES`,
  `KIND_TAGS`, `KIND_LEVELS`, `KIND_FIELDS`, `KIND_LANGUAGES` — deliberately excluding
  `KIND_CONFIG`, whose route needs `core.admin` and would otherwise report "never fetched"
  forever on most sites), or `null` when any of those five has never been cached — a partially
  warmed cache is, for freshness purposes, no cache. `SiteController::references()` sends it as
  the payload's `fetchedAt` key. Invalidation is now **three** things (gh-42 round 2): the manual
  Refresh/Reload metadata buttons; a **configurable TTL** (`Reference\MetadataCacheService`'s
  `metadata_cache_ttl` setting, default 60 minutes, `0` = never) driving the SPA's fire-and-forget
  background refresh (`ensureFreshReferences()` in `app.js`, no toast, no error surfaced, so an
  offline site keeps opening from cache exactly as before); and an **opt-in startup cache reset**
  (`metadata_reset_on_start`, **default off**) which *deletes every row* in `reference_cache` (via
  `ReferenceRepository::clearAll()`, leaving `editor_css_cache` alone — that cache has its own
  refresh path) at process start, through `MetadataCacheService::resetIfRequested()` called once
  per process from `BootstrapController::bootstrap()`. ⚠️ It defaults **off** because an
  unconditional refetch at launch reads as a hang on a slow or unstable connection — the same
  real-world constraint that keeps `request_log` off by default — and because the delete is real:
  an unreachable site then renders empty category/tag/language lists until it can be reached, not
  a stale-but-usable cache. Both preferences live in the generic `settings` key/value store, so
  neither needed a migration; `MetadataCacheService::TTL_CHOICES` in PHP and
  `METADATA_TTL_CHOICES` in `app.js` must stay in step, or a value the SPA offers would silently
  snap back to the 1-hour default with no explanation. `MetadataCacheService` is a **container
  singleton** (registered in `SiteProvider` with `share()`) — load-bearing, not stylistic, since
  `resetIfRequested()`'s once-per-process guard depends on every `container->get()` call within a
  process returning the same instance. See the `assets/private/` SPA notes below for the
  front-end half.
  `unicodeSlugs()` caches one Global Configuration value under the `config` kind — `unicodeslugs`,
  the "Unicode Aliases" option, which the alias preview needs (see `src/Article/`). It is the one
  thing here that is **never strict**, whatever the caller asks: `GET v1/config/application` needs
  `core.admin`, which an article author normally lacks, so a 403 is the healthy case for most sites
  and must not fail the manual refresh — an unreadable value degrades to the cached answer, then to
  `false` (Joomla's default). `ApiClient::getConfigValue()` returns a **single named** value, not
  the map: that route serves `configuration.php`, secret and database password included, so nothing
  unasked-for can reach the cache.
- `src/Field/FieldSupport.php` — supported field-type subset + required-unsupported guard.
- `src/Article/` — `Draft` entity + repository (local drafts). A draft remembers the
  `site_id` + `remote_id` it mirrors; `findByRemote()` locates an existing draft for a
  remote article and `update()` can re-point a draft at another site (which unlinks it).
  The **alias (URL slug)** is an editable field in the editor, shown as an input with an
  attached "regenerate" add-on button (`#editor-alias-input` / `#btn-regenerate-alias`)
  directly below the title. The SPA's `makeAlias()` mirrors Joomla's
  `ApplicationHelper::stringUrlSafe()`, which is **two** algorithms picked by the site's
  `unicodeslugs` Global Configuration option (the references payload's `unicodeSlugs` flag, see
  `src/Reference/`), so `aliasSlug(text, unicodeSlugs)` mirrors both: off (Joomla's default) →
  `OutputFilter::stringURLSafe` (NFKD transliteration → lowercase → whitespace-to-dash → strip
  non-`[a-z0-9-]` → trim dashes), on → `OutputFilter::stringUrlUnicodeSlug` (letters kept as they
  are: only URL-breaking punctuation becomes a space, `?` is dropped, lowercase, runs of **spaces**
  — not whitespace at large — to a dash, no dash trimming). This is why a Greek title yields
  `καλημέρα-κόσμε` on a Unicode-alias site but nothing at all on a transliterating one; either way
  an empty result (Joomla counts an all-dashes alias as empty, as `Table\Content::check()` does)
  falls back to a `Y-m-d-H-i-s` timestamp.
  Distinct from that URL slug, the **Created by Alias** (`created_by_alias`, a person's by-line —
  Joomla shows it instead of the publishing account's name) is a plain sidebar text input
  (`#editor-created-by-alias`, gh-8). It is the one article attribute `PublishService` sends
  **unconditionally**, where `metadesc`/`metakey` are sent only when non-empty: an empty value is
  meaningful ("credit the real author"), and a PATCH backfills every column we omit from the
  *existing* record, so an alias the user cleared could otherwise never be cleared on the site. The
  draft is authoritative because importing a remote article reads the site's value back into it.
  ⚠️ The write survives because `ApiController::save()` filters the body through com_content's
  `article.xml` form — a field absent from that form is silently dropped, so *any* new article
  attribute must be checked against it first.
  `regenerateAlias(force)` fills the alias from the title on the title's **blur** only when the
  alias is empty (never clobbering a hand-edited one), while the button always regenerates.
  Joomla re-slugifies whatever alias we send on publish, so this is a faithful preview.
  Editing a remote article fetches its full content via `GET /api/sites/{id}/articles/{articleId}`
  (body recovered by `ApiController::remoteArticleBody()`: it prefers discrete `introtext` /
  `fulltext` attributes if the API ever exposes them — a Joomla PR proposes this — otherwise it
  falls back to the combined `text` attribute and heuristically splits intro/full on the
  `"\r\n \r\n"` separator Joomla inserts between them; the recovered split is re-emitted as the
  editor's `<hr class="readmore">` marker so it survives the round-trip to publishing; category
  and tags come from the JSON:API `relationships` block, which `ApiClient::flatten()` preserves,
  tag IDs resolved to titles) and
  opens it as an **unsaved** draft — drafts (new or imported) are only written to the DB on
  the first Save, so an unchanged remote article leaves no local draft.
  The remote-article list (`GET /api/sites/{id}/articles`) is a **paginated, sorted and
  filtered** browse, mirroring Joomla's back-end article list: `ApiController::remoteArticles()`
  reads `page`/`limit`/`ordering`/`direction` plus the supported filters (`search`, `category`,
  `tag`, `language`, `state`, `featured`, `checked_out`) from the query string, validates the
  sort column against a whitelist (`ARTICLE_ORDERING`, drawn from the model's `filter_fields`),
  and forwards them to the REST API as `list[ordering|direction]` + `filter[…]` + `page[limit|
  offset]`. `ApiClient::listArticlesPage()` returns the page's items **and** the pagination total
  (Joomla's `meta['total-pages']`). Default sort is `a.id` desc. The Articles page is split into
  two tabs — **Local Articles** and **Remote Articles** (`State.articlesTab`, default `drafts`;
  the user-facing label is “Local Articles” but the internal entity/state/routes remain *draft*) —
  each with its own filter/sort toolbar, list and prev/next pagination. The tab strip carries a
  right-aligned **network-activity indicator** (`#articles-net-indicator`): `apiFetch()` keeps a
  global in-flight-request counter (`netActivityCount`) and `updateNetActivityIndicator()` shows a
  spinner + the `GRAFIDA_MSG_LOADING` label while the counter is > 0, so it is clear whether data
  is still loading. The Remote Articles tab
  renders the server-paginated toolbar (search, sort column + direction, category/tag/language/
  state/featured/checked-out dropdowns, per-page limit, clear-filters). The Local Drafts tab
  offers the same shape, but drafts are loaded in full per visit and **searched/sorted/filtered/
  paginated entirely client-side** (`filteredSortedDrafts()` / `renderDraftsTab()`); its toolbar
  is the subset of fields a draft actually carries (search over title+alias; sort by
  modified/created/title/category/language/state, defaulting to **Date modified desc** — a working
  list, so what you touched last comes first, matching the `updated_at DESC` order
  `DraftRepository::listBySite()` already returns; category/tag/language/state filters; per-page
  limit) — no featured/checked-out/hits/author controls, and (unlike the remote tab) **no id sort**:
  the id a local row shows is the *Joomla* id of the article it mirrors, which a draft only has once
  published, so ordering by it would sort half the list by a value the other half lacks. The two
  date sorts run off `Draft::toArray()`'s `createdAt`/`updatedAt` — naive UTC `Y-m-d H:i:s` exactly
  as stored, which the SPA compares **as strings** (that format sorts lexicographically in
  chronological order), never via `Date.parse()`, which WKWebView mishandles for the naive form (the
  same trap as `ai_chats.last_response_at`). `DraftExportService` enumerates its fields explicitly
  rather than using `toArray()`, so the timestamps stay out of the `.grafida` format. Because drafts
  store tag *titles* (not ids),
  the drafts tab's tag filter matches on title. The drafts tab's **empty state**
  (`buildDraftsEmptyState()`) is two-way: when the filters merely exclude everything it is the
  plain `GRAFIDA_MSG_NO_DRAFTS` line, but when there are **no drafts at all** it shows
  `GRAFIDA_MSG_NO_DRAFTS_YET` plus the two ways out — a primary **New article** button
  (`openNewArticle()`) and a secondary **List site articles** button
  (`GRAFIDA_BTN_LIST_SITE_ARTICLES`, switches to the Remote Articles tab). A remote article that
  is already mirrored by a local draft (same site + `remote_id`) **stays** in the remote list
  (it is not hidden), tagged with an extra `GRAFIDA_LBL_HAS_LOCAL_DRAFT` "Local article" badge and
  a left accent; clicking it opens the existing draft rather than re-importing the article
  (`openEditorFor()` reuses the matching draft). Both tabs render each row through the shared
  `buildArticleItem()`, whose title is preceded by a fixed-width (`fa-fw`) **publish-state icon**
  (`articleStateIcon()` / the `ARTICLE_STATE_ICONS` map): check/green published, xmark/red
  unpublished, box-archive/blue archived, trash/muted trashed. The colours follow Joomla's
  semantics; a distinct glyph per state (plus a `role="img"` + `aria-label`) is what carries the
  meaning without them. Between that icon and the title sits the **Joomla article id**
  (`articleJoomlaId()`, rendered as a muted monospace `#123`) — on a remote row its own `id`; on a
  local row the `remoteId` of the article it mirrors, since a draft's `id` is a key in our own
  `drafts` table and means nothing on the site. A draft that has never been published therefore
  shows no id at all, which is exactly why the drafts tab offers no id sort. The API only accepts a
  **single** category/tag and an INT `state`, so there is no multi-select or "all states"; an
  author filter is omitted (no local user list).
  `DraftExportService` builds and consumes the portable **`.grafida`** file format (plain JSON
  under a `.grafida` extension): every visible field, saved AI chats and any locally-picked
  (not-yet-published) images, but **never** `site_id`/`remote_id` or the local `media_blobs`/
  `ai_services` row ids (those are local-install specifics with no portable meaning). A
  `grafida-media://N` sentinel in `images.image_intro`/`image_fulltext` is resolved to an
  embedded base64 blob under `offlineMedia`, keyed by an export-local ref (`grafida-media://
  export:mN` — the `m` prefix stops PHP auto-casting a numeric-looking key to an int). **Inline
  body images went through the same treatment in gh-36**: an `<img>` referencing a local blob is
  now a `boson://app/api/media/{id}/raw` reference, not a self-carrying `data:` URI, so
  `exportHtml()` walks the body for that prefix (via `InlineMedia::idFromLocalUrl()`, public for
  exactly this reuse) and embeds each referenced blob under `offlineMedia` too — deduped by blob
  id within one export, so two `<img>` tags pointing at the same picture embed it once. Import's
  `importHtml()` mirrors this: it rematerialises each ref as a **fresh** blob (sharing the same
  `$resolvedRefs`/dedup map the intro/full-text loop uses, so a ref reused by both a subfield and
  the body still becomes one new row) and points the rewritten `<img>` at the new blob's
  `LocalMediaUrl`. A **legacy** export (from before gh-36, still carrying real `data:` URIs in
  `html`) is handled too: import finishes by running `InlineImageExtractor::extract()` over the
  result, the same conversion a legacy *draft* gets on open (see `src/Media/` below), so an old
  `.grafida` file still ends up with local-URL references. `FORMAT_VERSION` is `2` (informational
  only — nothing gates on it; the importer handles both shapes unconditionally). Boson has
  **no native "Save As" dialog** (`DialogApiInterface`
  only offers open-file/open-directory pickers), so export asks for a destination **folder**
  (`POST /api/dialog/select-directory` → `selectDirectory()`) and writes `<alias-or-title>
  .grafida` into it server-side; import reuses the existing open-file dialog with a new
  `'grafida'` filter. Two import endpoints: `POST /api/drafts/import` (`importAsNewDraft()`) —
  creates a brand-new draft on the given site — and `POST /api/drafts/{id}/import`
  (`replaceDraft()`) — used by the editor's "Replace from file…" button to overwrite an
  **already-open, just-saved** draft's content and saved AI chats while explicitly preserving
  its own id/`site_id`/`remote_id`, so a replaced draft stays linked to the same site and
  (if any) the same remote article.
- `src/Debug/` — the recording substrate behind **Diagnose Connection** and the opt-in
  **Request Log** (gh-37): `RequestRecord` (one captured HTTP exchange), `Redactor`,
  `BodyFormatter`, `RecordingTransport`, `RequestLog`/`RequestLogService`, and the
  `RecordSink` interface both `ArraySink` and `RequestLog` itself implement. The log is an
  **in-memory ring buffer** (`RequestLog`, capacity 20) — not a table — because it is cleared
  at app start, on every site switch and whenever the setting is turned off, so nothing about
  it is meant to outlive the process; the on/off flag rides in the generic `settings` key/value store
  (`request_log`, **default off** — unlike `slash_tools`/`spell_check`, which default on)
  and needs no migration. Recording is a **`Transport` decorator** (`RecordingTransport`),
  not a change to `HttpClient`, which stays a dumb transport — this is what lets *Diagnose
  Connection* (`Grafida\Site\ConnectionDiagnostics`) work with the Request Log switched off:
  it builds a throwaway `ApiClient` over a `RecordingTransport` writing to a private
  `ArraySink`, never touching the shared log. `http.default`/`http.short`/`http.reference`
  (see `HttpProvider`) are wrapped into the container-shared `RequestLog`; **`http.ai` is
  not** (AI traffic is not "requests to the site", may be huge, and carries a different
  provider's key), and **`http.diagnostics` is also deliberately unwrapped** — a diagnose
  run records into its own `ArraySink`, so wrapping this transport too would double-record
  every probe into the shared log.
  ⚠️ **Redaction is unconditional.** `RequestRecord::toArray()` is the only serialisation
  path a record ever goes through — whether it is bound for the Request Log screen, the
  Diagnose Connection panel, or the JSON export — and it always masks `Authorization`/
  `X-Joomla-Token` (and any literal occurrence of the token elsewhere in a URL or body) down
  to first-4 + dots + last-4. There is no separate export-only redaction path to fall out of
  sync with the screen.
  Bodies are **capped at 64 KiB per direction at capture time** (`BodyFormatter::cap()`,
  applied by `RecordingTransport` before a record is even built — a media upload is a
  multi-megabyte base64 blob, and keeping 20 of those in memory is not acceptable) and
  described by kind — `none`/`text`/`json`/`binary`: JSON is pretty-printed, binary renders
  as the localised "(… binary data …)" marker, and an empty body is omitted rather than
  shown blank.
- `src/Media/` — offline image blobs (`media_blobs`) + `SiteImageFetcher` (fetches a published
  article image for a multimodal AI request — see the AI facts). `ApiClient::listMedia()` browses the
  site's Media Manager (`GET /v1/media/files`); `ApiController` exposes it as
  `GET /api/sites/{id}/media?path=…` and serves an offline blob's data: URI back to the SPA
  via `GET /api/media/{id}` (to preview a not-yet-published intro/full-text image).
  ⚠️ **A pasted/dropped/picked image is no longer inlined as a `data:` URI in the article body**
  (gh-36): `MediaRepository` still stores the bytes in `media_blobs` (now also carrying
  `updated_at`/`width`/`height`/`size`, added by `storage/migrations/07_media_blobs_local.sql` —
  not re-runnable, following the `04_ai_chat_response_chain.sql` pattern), but the editor
  references it by a **local URL the Boson kernel itself serves**:
  `boson://app/api/media/{id}/raw?rev=<token>` (`MediaController::mediaBlobRaw()`, ~60 bytes
  regardless of image size, vs. a 2.3 MiB screenshot's ~3.1 MiB of base64 previously sitting in
  the editor DOM, `State` *and* the `drafts.html` column — which is what made the CodeMirror
  source editor freeze for seconds on a couple of pasted screenshots). `rev` is this endpoint's
  analogue of `mediaDisplayUrl()`'s `grafida_rev` (gh-4): a blob's bytes can be replaced in place
  by the Local Media tab's image editor, so the URL must change on every edit or the webview's
  disk cache paints the old picture — except here the token is derived
  (`Grafida\Media\LocalMediaUrl::token()`, `sha1($revisedAt . '|' . $id)` truncated to 8 chars,
  `$revisedAt` = `updated_at ?? created_at`) rather than looked up from Joomla's own metadata,
  since there is no Joomla record yet. `LocalMediaUrl::build(id, revisedAt)` is the **only** place
  either PHP or JS should construct this URL — mirrored byte-for-byte in JS by
  `assets/private/js/editor/localmedia.js`'s `window.GrafidaLocalMedia` (its own from-scratch
  synchronous SHA-1, since `window.crypto.subtle` is Promise-only and this runs inside a
  synchronous TinyMCE hook), an IIFE loaded in `index.html` right after `app.js` alongside
  `slashtools.js`/`csstheme.js`. The endpoint answers **raw bytes, not JSON** — the only route in
  the app that does — with `Content-Type` restricted to a small allow-list
  (`MediaController::ALLOWED_RAW_MIME_TYPES`), `Content-Disposition: inline`,
  `X-Content-Type-Options: nosniff` and, like every other internal-API response (gh-35),
  `Cache-Control: no-store`. `GET /api/media/{id}` (the JSON data:-URI form above) still exists
  for the AI panel's per-image fetch and the intro/full-text preview cache — only the *article
  body* stopped using it. A **Local Media tab** on the Media Manager screen
  (`GET /api/sites/{id}/local-media`, works offline) lists every not-yet-published blob — filename,
  dimensions, size, which local draft (if any) uses it, and a "Published" badge once `remote_url`
  is set — and offers the same in-app crop/resize/rotate/flip editor the Site Media tab's entries
  get (`openImageEditor()` now takes a small `{name, mime, loadDataUri(), save()}` descriptor so
  one implementation serves both tabs, and — since gh-43 — the article editor's own image
  context toolbar too, via `localMediaEditorDescriptorBase(entry, afterSave)`, which factors out
  everything but what happens after a successful save: the grid re-renders itself, an in-article
  edit has no grid on screen to touch), plus rename, save-to-disk
  (`POST /api/media/{id}/save-to-disk`, folder picker + server write, since Boson has no Save-As
  dialog) and delete.
  ⚠️ **Editing a blob's bytes in place leaves every `<img>` that references it wearing a stale,
  possibly-distorted `width`/`height`** (gh-43) — TinyMCE bakes the *intrinsic* size onto every
  inserted image (its paste/drop path inserts `<img width="…" height="…">`, and the Insert/Edit
  Image dialog auto-fills Dimensions from `naturalWidth`/`naturalHeight`), so a crop/resize
  changes the blob's real dimensions but nothing revisits the tag's already-baked-in attributes,
  and a plain `src` swap alone stretches/squashes the old picture into the old aspect ratio. The
  fix is one rule, `Grafida\Media\ImageDimensions::fit(attrW, attrH, oldW, oldH, newW, newH)` —
  see its doc comment (and `.plans/00-overview.md`'s truth table) for the exact cases: no
  attributes → leave alone; the tag's size still matches the blob's *old* intrinsic size (never
  hand-resized) → adopt the new intrinsic size wholesale; otherwise a deliberate in-article size →
  keep the attribute that is present and re-ratio the other so the picture is never distorted;
  any dimension unknown → leave alone — **implemented twice, and the two must stay in step**:
  `ImageDimensions::fit()` in PHP and `assets/private/js/editor/localmedia.js`'s
  `GrafidaLocalMedia.fitDimensions()` in JS (same argument order, same rounding), because the rule
  has to apply in two different runtimes. The **server** half runs even with the article
  **closed** — the reported bug crops a blob from the Local Media tab, then reopens an article
  that never had TinyMCE running to fix itself — via `Grafida\Media\LocalMediaSync::resync()`
  (a container-registered service, `DraftRepository::listReferencingMedia()` finds every draft on
  the blob's site whose `html` LIKEs its `…/media/{id}/raw` URL) calling
  `Html\InlineMedia::resyncLocalImage()`, which walks every matching `<img>` (matched the same
  tolerant way `rewriteOfflineImages()` is — by `src` or by the `data-grafida-media-id` tag,
  whichever resolves), rewrites `src` to the freshly built URL and applies `ImageDimensions::fit()`
  to `width`/`height`, and returns the HTML byte-identical when nothing matched so an unaffected
  draft is never even written back (`DraftRepository::updateHtml()` bumps `updated_at`, since the
  rendered content genuinely changed). `MediaController::updateLocalMediaContent()` captures the
  blob's *old* width/height **before** `replaceData()` overwrites them, calls `resync()` with old
  + new, and now answers `{id, url, width, height, oldWidth, oldHeight}` — the two `old*` fields
  are additive, purely so the **live** half, `refreshLocalMediaReferences(id, result)` in
  `app.js`, can run the identical rule against an **already-open** editor's DOM (one undo step:
  every `img[data-grafida-media-id="N"]`'s `src` *and*, via `fitDimensions()`, `width`/`height`)
  without a second round trip to fetch them. `GET /api/media/{id}` (`mediaBlob()`) grew the same
  way — additively — from `{id, dataUri}` to `{id, dataUri, filename, mime, width, height}`: the
  AI panel's per-image fetch and the intro/full-text preview cache only ever read `dataUri`, so
  they are unaffected, while the new fields let a caller (the context toolbar's **Edit image**
  item, below) build an image-editor descriptor without a second request.
  **Legacy drafts** (saved before gh-36, still carrying real `data:` images in `html`) are
  converted the first time they are opened: `DraftController::getDraft()` runs
  `Grafida\Media\InlineImageExtractor::extract()`, which decodes each `data:` image into a fresh
  `media_blobs` row and rewrites the `<img>` to the local URL (persisted back to the draft only
  when it already has an id — an unsaved draft stays unsaved, matching the rest of the editor's
  save model). `InlineImageExtractor::storeDataUri()` is the shared decode-and-store primitive;
  `PublishService`'s own untagged-`data:`-image path (see below) calls the same method rather than
  duplicating it.
  **Media Manager screen** — a sidebar item (`data-screen="media"`) split into two tabs, modelled
  on the Articles screen's tab strip: **Site Media** (below) and **Local Media** (above). Site
  Media is the full online manager for the site's Media Manager and works only while the site is
  connected — that "not connected" guard is now scoped to its own tab panel rather than blanking
  the whole screen, since Local Media works offline regardless. The SPA
  (`loadMediaScreen()` in `app.js`) seeds the root from `GET /api/sites/{id}/media/adapters`
  (the first adapter is the default filesystem; its `path` like `local-images:/` is the root),
  browses folders with the existing `browseMedia`, and renders a card grid (folders + **all**
  files, not only images) with breadcrumb navigation and an adapter switcher when >1 filesystem
  exists. Actions map onto com_media's REST CRUD (verified against 5.4 source — see Joomla API
  facts): upload (`POST …/media/files` → `ApiClient::uploadMedia` with `override`), create folder
  (`POST …/media/folder` → `createMediaFolder`, a POST with a path but no content), rename
  (`POST …/media/rename` → `renameMedia`, a PATCH on the item URL whose body carries the new
  path; the server derives the new path from the old so the item stays in its folder), delete
  (`DELETE …/media?path=…` → `deleteMedia`), and an in-app **image editor** (crop/resize/rotate/
  flip on a `<canvas>`, saved via `POST …/media/content` → `updateMediaContent`, a PATCH that
  sends the same path + new base64 content). The editor loads the source bytes through the
  backend (`GET …/media/file` → `getMediaFile`, returning a data: URI) so the canvas is **not**
  tainted by a cross-origin `<img>` (which would block `toBlob`); it only opens raster types
  (`png`/`jpg`/`jpeg`/`webp`). PATCH/DELETE put the adapter-qualified path in the **URL segment**
  (`v1/media/files/:path`, route pattern `.*`), so `ApiClient::mediaItemUrl()` keeps `/` and `:`
  literal and percent-encodes the rest.
  **Crop is a mode, so arming it has to be visible without moving the mouse** (gh-31). It used to
  change nothing but the stage's `cursor`, which only shows once you happen to hover the image, so
  the button read as inert. `updateCropUi()` is now the single place that renders crop state, and it
  drives four signals at once: the stage gets an accent ring, an `.img-editor-prompt` scrim dims the
  image behind a "drag a rectangle" instruction, the button flips to **Cancel crop** (label *and*
  icon, via `setIconBtnLabel()` — colour alone is not an accessible state cue, and `aria-pressed`
  carries it to assistive tech), and the statusbar hint stops being muted advice and becomes live
  state. Once a rectangle exists the prompt hides — the selection box's own 9999px scrim takes over
  as the highlight — and the hint turns into a `GRAFIDA_LBL_CROP_SELECTION` readout of the selection
  in **source** pixels (`sel` is in *display* pixels, so it is divided by `State.imgEditorScale`).
  Apply crop is `disabled` until the selection is usable, since applying a zero-sized one silently
  did nothing; a bare click (which leaves exactly that) clears the selection so the prompt returns.
  ⚠️ **Every `<img>` showing a Media Manager entry must go through `mediaDisplayUrl(entry)`, never the
  bare `entry.url`.** Joomla returns the plain static file URL, so after an in-app edit rewrites the file
  the webview keeps painting its cached copy — the crop *does* save and the image editor shows it (those
  bytes arrive through PHP via `getMediaFile`, which no browser cache sees), while the card thumbnail and
  the preview still show the picture as it was (gh-4). The helper stamps the entry's `modified_date` onto
  the URL as a `grafida_rev` parameter, so each revision is a distinct URL. It is **display-only**: what a
  media pick contributes to an *article* (`browseImageMedia()`, the TinyMCE `file_picker_callback`) stays
  the bare `url`, or the cache-buster would be published into the article HTML.
- `src/Html/` — `ContentSplitter` (read-more split), `CssRebaser`, `InlineMedia`, `HtmlDocument`.
- `src/Publish/PublishService.php` — the publish pipeline (media upload, tags, fields, split, POST/PATCH).
  Every write carries a `version_note` — `GRAFIDA_MSG_VERSION_NOTE` ("Created using %1$s %2$s",
  filled from `App::NAME`/`App::VERSION`) — so a revision names the tool that wrote it in Joomla's
  version history (gh-17; see the Joomla API facts for the `jform` mechanism that carries it and
  why a site with history off silently drops it). It is translated with
  `LanguageService::translateIn()` into the **article's** language (`$draft->language`), not the
  interface one: it is read on the site, beside the article it describes. This is the codebase's
  only server-side-translated string.
  After a successful publish the SPA (`showPostPublishDialog()`) asks what to do with the local
  draft: **Delete Local Article** (the default/focused action — removes the draft and returns to the list,
  the published article remaining in the remote list) or **Keep Local Article** (leaves the editor open to
  edit and re-publish). A draft's `images` object holds Joomla's eight `image_intro*` / `image_fulltext*` subfields; the
  intro/full-text image picked from a local file is stored as a `grafida-media://N` sentinel that
  `resolveImages()` uploads (via the shared offline-blob upload) and swaps for a public URL on publish.
  The SPA's editor "Images" section lets you pick a local file, browse the site's media, or paste a URL,
  and includes Joomla's `image_*_alt_empty` "decorative image" toggle. The same picker backs TinyMCE's
  Insert/Edit Image dialog: its Source-field browse button (`file_picker_callback`, gated by
  `file_picker_types: 'image'`) opens the media browser, with a "Choose file…" button for a local file.
  To **edit an already-inserted image** (dimensions, description, alignment, CSS), selecting it shows a
  floating context toolbar (`addContextToolbar('grafidaImageTools')`, predicate = `img`) whose **Image**
  item re-opens that same dialog; the dialog carries the Dimensions fields (`image_dimensions`), an
  Advanced tab (`image_advtab`: inline style, border, spacing) and — via `a11y_advanced_options: true`,
  matching Joomla's own editor — an **"Image is decorative"** checkbox that empties the alt text and
  marks the image with an empty `alt` so a screen reader skips it (gh-5). This is the inline-image
  counterpart of the intro/full-text images' `image_*_alt_empty` toggle. Double-clicking the image or the
  toolbar/Insert-menu **Image** button work too. The same context toolbar's **CSS class…** item
  (`imageclass` button) opens a small prompt to set any free-text CSS class(es) on the image (the
  Insert/Edit Image dialog has no class field); it pre-fills the current `class` and writes it back in one
  undo step (empty clears it). The toolbar gained two more items in gh-43: **Edit image**
  (`localimageedit`) reopens the same crop/resize/rotate/flip editor the Media Manager's Local
  Media tab uses (see `src/Media/` above) on the selected `<img>`'s own blob, so a picture pasted
  straight into the article can be touched up without leaving it; it is enabled only when the
  selected node resolves to a **local** blob id — read the self-healing way the tagging hook
  already does (the `data-grafida-media-id` attribute first, falling back to parsing the id out of
  the local URL) — so a site-media or external `<img>` (neither) leaves the button disabled rather
  than doing nothing on click. **Reset size** (`resetsize`) restores the selected `<img>`'s
  `width`/`height` to its own decoded `naturalWidth`/`naturalHeight` — the issue's secondary
  complaint, "no way to reset the dimensions" — and is deliberately generic (it reads the
  *displayed* image, no fetch needed) so it works for a local blob, a published site-media image
  or an external URL alike, unlike Edit image which only makes sense for a local one.
  ⚠️ **All three of those items live on the right-click menu as well, and that is the route users
  actually take** — the floating toolbar alone was rightly called unusable: it only appears once the
  image is *selected*, and right-clicking an unselected image opens the context menu instead, so
  reaching **Edit image** meant right-click → Escape → click → toolbar. They are therefore also
  registered as a `grafidaimage` **context-menu section** (`addContextMenu`) and the `contextmenu`
  init option is set to `'link image grafidaimage table'` so the section renders directly below the
  stock **Image** item. Two things follow from that: each action is a plain function taking the
  `<img>` it acts on (`promptImageClass`/`editLocalImage`/`resetImageSize` in `initTinyMCE()`'s
  `setup`) rather than reading `editor.selection.getNode()` itself, since the context menu hands its
  `update()` the clicked element outright; and because `update()` re-runs per right-click with that
  element in hand, **Edit image** is simply *omitted* there for a non-local image, where the toolbar
  button — registered once, for every image — has to be shown disabled instead. Setting
  `contextmenu` explicitly **replaces** the stock list rather than extending it (same trap as
  `menu.tools` and `help_tabs`); the names dropped from it — `linkchecker`, `editimage`,
  `spellchecker`, `configurepermanentpen` — are all premium plugins Grafida does not load, and an
  unregistered section is skipped anyway, so listing them would only imply we ship them.
  The editor `content_style` also forces
  `img { max-width: 100%; height: auto }`: Joomla bakes a photo's full intrinsic size into the tag (e.g.
  `width="4032"`), and without a constraining rule the picture overflows the editor's scroll box and becomes
  un-clickable in the WKWebView (broken hit-testing) — scaling it to fit keeps it selectable/editable, and
  only the editor view is affected (the published `width`/`height` are untouched).
  The `content_style` likewise forces `body { margin: 0; padding: 1rem }` so the editing surface always has
  breathing room (gh-23): TinyMCE's built-in content CSS carries `body{margin:1rem}`, but that CSS is loaded
  **only when the site supplies no `editor.css`** (`content_css` is the site stylesheet otherwise), so a site
  `editor.css` that sets no body spacing — e.g. Bootstrap's Reboot `body{margin:0}`, where the real front-end
  pads the article via wrapper containers Grafida's editor has no equivalent of — leaves the text flush against
  the iframe edge and the focus ring clipping the first characters. `content_style` is injected after
  `content_css` so it applies in both branches; the `margin:0` keeps the inset a uniform 1rem rather than
  doubling to 2rem in the built-in-CSS branch. Editor-only, like the `img` rule — `editor.css` never reaches
  the published article.
  TinyMCE's own **"Upload" tab is disabled** (`image_uploadtab: false`) because its "Browse for an image"
  dropzone creates a plain `<input type="file">` that Boson's webview never opens (see the native
  file-dialog note above) — so local uploads go exclusively through the Source-field "Choose file…",
  which calls the native picker. The **quickbars insert toolbar is disabled** for the same reason
  (`quickbars_insert_toolbar: false`, gh-6): the toolbar it pops up on every empty line offers a
  `quickimage` button that clicks that same dead `<input type="file">`. The `quickbars` plugin stays
  loaded — its selection and image context toolbars are unaffected.
  a local pick is inserted as `<img src="boson://app/api/media/{id}/raw?rev=…"
  data-grafida-media-id="N">` (`GRAFIDA_MEDIA_ATTR`, mirroring `InlineMedia::ATTRIBUTE`) — **not**
  a `data:` URI (gh-36, see `src/Media/` above) — so `PublishService` uploads the referenced blob
  on publish; a site-media pick is inserted as its public URL. The tagging attribute is
  self-healing rather than trusted blindly: a `SetContent`/`NodeChange` hook derives the id from
  the `<img>`'s own `src` first (`localMediaIdFromUrl()`, parsing the local URL — this is what
  survives a save/reload/import) and only falls back to a same-session `State.inlineMediaByUri`
  map for a `data:` image that has not been tagged yet, so the attribute is never the *only* place
  the id is remembered. On publish, `InlineMedia::rewriteOfflineImages()` rewrites **every**
  offline image, in **either** form — local-URL or `data:` — not just tagged ones: an image
  **pasted or dropped straight into the editor** on a platform/path that still lands as a bare
  `<img src="data:...">` with no tag (or a draft saved before gh-36) never passes through the
  in-editor upload handler, so it carries no `data-grafida-media-id`;
  `PublishService::uploadInlineImage()` decodes and stores such an untagged `data:` URI on the fly
  (via `InlineImageExtractor::storeDataUri()`, the same primitive the legacy-draft migration uses)
  so it is uploaded too, instead of leaking a giant broken inline blob into the published HTML. A
  local-URL image whose blob has since been **deleted** (Local Media tab, or the DB was reset) is
  a distinct failure from an untagged `data:` image: there is no fallback data to fall back to, so
  it aborts the publish with a dedicated `ApiException` — a plain-English literal like every other
  message in this class (none of them run through `LanguageService::translateIn()`, which is
  reserved for site-facing strings like `version_note`) — rather than publishing a broken
  `boson://` src that resolves to nothing on the live site. Any other media upload that fails (or
  returns no usable result) aborts the publish with a clear error too.
  Each uploaded image is rebuilt as the **same `<img>` Joomla's own media field emits** —
  `<img src="images/…" width=… height=… loading="lazy" data-path="local-images:/…">` (`mediaInfo()`): a
  site-root-relative `src`, the intrinsic `width`/`height`, and the `data-path` adapter linkage to the
  Media-Manager entry. **The upload path is relative to the default Media adapter's root** (`grafida/<file>`,
  NOT `images/grafida/<file>`): the default `local-images` adapter is rooted at the site's `images/`
  directory, so an `images/`-prefixed path writes the file to `images/images/grafida/…` while the article
  still points at `images/grafida/…` — a broken image. After a successful media upload `PublishService`
  also **writes the rewritten HTML back into the local draft** (so the stored draft mirrors what was
  published and a re-publish does not upload the images again); `data-path` is added to the editor's
  `extended_valid_elements` so it survives a TinyMCE round-trip.
- `src/Update/UpdateService.php` — the **update checker**. On startup the SPA calls
  `GET /api/update` (`api.checkUpdate()`) **fire-and-forget after the initial render**, so a slow
  fetch never blocks start-up. `UpdateService::status()` refreshes a per-user cache of the
  CDN-published update JSON (`https://cdn.akeeba.com/updates/grafida.json`, built by the
  `UpdateJson` release task: `{version,date,infoURL,download,releaseNotes}`) **at most once every 12
  hours** — the "last fetched" time is the cache file's mtime. The cache lives in the per-user
  **config** dir (`Paths::updatesFile()`/`configDir()` — Linux `$XDG_CONFIG_HOME/grafida/updates.json`
  (falls back `~/.config`), macOS `~/Library/Application Support/Grafida/updates.json`, Windows
  `%APPDATA%\Grafida\updates.json`); note config ≠ data on Linux only. A failed fetch falls back to
  any existing cache, or writes an empty `{}` (so the 12-hour back-off applies to failures too and it
  does not refetch every launch). `status()` compares the cached `version` with `App::VERSION` via
  `version_compare` and returns `{available, version, infoURL, download}`. When available, the SPA
  (`renderUpdateNotice()`) shows a **bold green “New version available”** message
  (`GRAFIDA_MSG_UPDATE_AVAILABLE`) above the sidebar-footer version label, with a **Download** button
  (`GRAFIDA_BTN_DOWNLOAD`) that opens the release's `infoURL` (the GitHub release page) in the OS
  browser via `api.openUrl()`. The Kernel wires it with a short-timeout `HttpClient(5)`.
- `src/Display/DisplayModeService.php` — persists the interface display-mode preference
  (`auto`/`light`/`dark`) in `settings`; sent to the SPA as the `bootstrap` payload's
  `displayMode` key and written via `POST /api/settings/display-mode`. Because Boson's
  webview does **not** report `prefers-color-scheme` reliably (on macOS it always reports
  dark), `systemPrefersDark()` probes the OS appearance directly (macOS `defaults read -g
  AppleInterfaceStyle`, Windows `AppsUseLightTheme` registry DWORD, Linux gsettings
  `color-scheme`/`gtk-theme`) → `true`/`false`/`null` when undetectable; it is sent in the
  `bootstrap` payload as `systemPrefersDark` and re-probed on demand via
  `GET /api/settings/system-theme`. The SPA's `systemPrefersDark()` trusts that value to
  resolve `auto`, only falling back to the media query when it is `null`, and re-probes on
  window `focus` so `auto` follows OS theme changes at runtime; it sets
  `<html data-theme="light|dark">`;
  TinyMCE follows the app theme (skin `oxide`/`oxide-dark`); its editing surface switches to
  the dark built-in content CSS only when the site supplies no `editor.css`.
  The preference has **two** controls (gh-41): the Settings screen's select and a
  tri-state button group in the sidebar (`#theme-switch`, `fa-sun`/`fa-moon`/
  `fa-display`, above the nav and separated from it by a rule), so the theme can be
  changed without leaving an open article. Both go through
  `applyDisplayModeChange(mode, {silent})` and both are re-rendered by it, so they
  can never disagree; the sidebar one passes `silent` because its effect *is* the
  confirmation. Applying to an open editor needs nothing new — `applyTheme(true)`
  already re-creates TinyMCE from `getContent()`, so the article survives the switch.
  The buttons are `aria-pressed` toggles rather than a `role="radiogroup"` (which
  would oblige us to implement roving arrow-key focus for no gain), and they are
  **static markup** so `applyStrings()` localises their tooltips via the existing
  `data-i18n-title` pass — `renderThemeSwitch()` only flips `.active`/`aria-pressed`.
  `applyStrings()` also honours **`data-i18n-aria`** — aria-label only, for a
  container that needs an accessible name but must not sprout a hover tooltip.
- `src/Editor/SlashToolsService.php` — persists whether the editor's slash-command menu is
  enabled (`settings` key `slash_tools`, **default on**), sent to the SPA as the `bootstrap`
  payload's `slashTools` key and written via `POST /api/settings/slash-tools`. Same shape as
  `DisplayModeService` (the `settings` table is a generic key/value store, so a new preference
  needs **no migration**); the boolean is encoded `'1'`/`'0'`. See the slash-commands note under
  `assets/private/` for the feature itself.
- `src/Editor/SpellCheckService.php` — persists whether the editor's native spell checking is
  enabled (`settings` key `spell_check`, **default on**), sent to the SPA as the `bootstrap`
  payload's `spellCheck` key and written via `POST /api/settings/spell-check`. Same shape as
  `SlashToolsService`. The stored value drives TinyMCE's `browser_spellcheck`, i.e. the editing
  body's `spellcheck` attribute — the **authoritative per-element gate**: WebKit will not check an
  element with `spellcheck="false"` even when its global continuous-checking flag is on, so this
  preference alone turns the underlining off on every platform. The macOS master flag
  ({@see MacSpellCheck}) stays **unconditionally enabled** at startup precisely so the attribute
  can toggle it live: `WebContinuousSpellCheckingEnabled` is read once and cached by WebKit, so
  forcing *it* false would need a restart to re-enable — the per-element attribute, which WebKit
  re-evaluates immediately, is what the toggle drives (`applySpellCheckChange()` also updates an
  open editor's body attribute so no re-init is needed). ⚠️ Turning it back **on** at runtime only
  marks text edited afterwards, not already-loaded content — an inherent WebKit quirk. See the
  spell-checking note under `assets/private/` (gh-24).
- `src/Markdown/`, `src/I18n/` — Markdown import; language service. `I18n\UiStrings::KEYS` is the
  canonical list of UI string keys shipped to the SPA (used by `BootstrapController` and
  `SettingsController`) — so a key the SPA never reads (`GRAFIDA_MSG_VERSION_NOTE`, resolved
  server-side in `PublishService`) belongs in `en-GB.ini` but **not** in `KEYS`, or it is shipped
  to a front-end with no use for it. `LanguageService::translateIn($key, $tag)` translates into a
  **named** language instead of the interface one, for the few strings whose reader is not the
  person at the keyboard; an unshipped tag (including Joomla's `*` / All) falls back to
  `translate()`, i.e. interface language → en-GB → the key. It caches one catalogue per tag.
- `src/Storage/` — SQLite + migrations, on **`joomla/database`**. There is **no global DB object**:
  the container owns the single `Joomla\Database\DatabaseInterface` instance. `SqliteDatabase`
  extends `Joomla\Database\Sqlite\SqliteDriver` and overrides `connect()` to apply the pragmas the
  app depends on (`journal_mode = WAL`, `foreign_keys = ON` — the AI-chat cascade deletes need it —
  and `busy_timeout = 5000`); `DatabaseFactory` builds one from a path.
  `Migrator` applies `storage/migrations/*.sql` in lexicographic order, exactly once each, tracked
  by file name in `schema_migrations`. **Its bookkeeping runs through the driver but each migration
  file's body is still handed to the raw `\PDO::exec()`** (via `getConnection()`) — deliberately: the
  `.sql` files hold multiple statements *and* `--` comments, which a prepared statement cannot run
  and `DatabaseDriver::splitSql()` (a naive `;` splitter that does not strip comments) would mangle.
  `04_ai_chat_response_chain.sql` is two bare `ALTER TABLE … ADD COLUMN`s and is **not** re-runnable,
  which is what makes the `schema_migrations` bookkeeping load-bearing — and why
  `StorageService::reset()` wipes every table *except* that one.
  `StorageService` reports the DB file path, opens its folder in the OS file browser
  (`open`/`explorer`/`xdg-open`), and resets local storage (deletes tokens + wipes all
  tables, keeping `schema_migrations`). Exposed under `/api/settings/storage[/open|/reset]`.
  ⚠️ `PRAGMA foreign_keys` is a **no-op inside a transaction**, so `reset()` must never be wrapped
  in one.
- `src/Ai/` — the **AI chat assistant** (chat with an LLM about the open article, the document
  supplied as context; modelled on the Joomla AITiny plugin, **text only — no AI images**).
  `AiServiceManager` is CRUD over configured **AI services** (`ai_services`), each a named
  provider connection (provider + endpoint + model + params); the API key lives in the OS keychain
  (reference `grafida.ai_service.{id}`, insecure-plaintext fallback like sites). **Multiple services
  are supported**; one may be flagged default, else the **lowest id** wins (`default()`). `Defaults`
  loads bundled `resources/{defaults.json,voices.json,providers.json}` (ported from AITiny: the base
  **system prompt**, the writing **tools** generate/proofread/friendly/professional/concise, the
  tone-of-voice library, and the **provider table** — OpenAI, Anthropic, Cohere, DeepSeek, Google,
  Groq, Mistral, OpenRouter, Perplexity, Scaleway, GitHub, Custom (OpenAI Completions API), Custom
  (OpenAI Responses API) — each with endpoint/auth/chat-path/models-path/`sse_dialect`). The dialect
  is **never persisted**: `ai_services` stores only the provider *key*, and chat-path/auth/models-path/
  dialect are derived from `providers.json` at runtime, so changing the table needs no DB migration.
  `effectiveTools()` overlays the code defaults with `ai_tools` DB overrides
  + custom tools; **each tool may target its own service** (`service_id`).
  ⚠️ **An override row is written whole, but a PATCH may carry any subset of the fields** (gh-28), so
  `AiServiceController::updateAiTool()` fills what the body omits from `effectiveTool($key)` — the
  tool *as it currently resolves*, bundled defaults included — never from the override row alone.
  Falling back to the row alone is what let the list's enable/disable toggle (which sends nothing but
  `enabled`) blank a bundled tool's title, icon, prompt and tone the first time it was pressed; and
  `isCustom` is likewise preserved from the existing row, since a custom tool demoted to a built-in
  override matches no bundled key and disappears from the list entirely. That damage went unnoticed
  because `effectiveTools()` used to **ignore** an override's `title`/`icon`/`override_system` — which
  was the reported bug: a saved icon never took effect. Those three are authoritative now, so a
  pre-0.3 toggle-written row (recognised by `Defaults::isToggleOnlyRow()`: a title equal to the tool
  key, or empty — never something the edit form sends) contributes **only** its `enabled` flag.
  `AiChatRepository` persists
  **saved chats** (`ai_chats` + `ai_chat_messages`) linked to a draft; deleting the draft cascades
  them away. `ai_chats` also carries the Responses-API conversation chain
  (`previous_response_id` + `last_response_at`, the latter **ISO-8601 UTC** — unlike the other
  timestamp columns — because the SPA compares it against `Date.now()` and WKWebView's `Date.parse()`
  does not reliably handle the naive `gmdate('Y-m-d H:i:s')` form); see the AI facts below.
  Transport is deliberately **inverted vs. AITiny — see the AI transport facts below**.
  Endpoints: `/api/ai/services[...]` (+ `/default`, `/resolved`), `/api/ai/tools[...]`,
  `/api/ai/system-prompt`, `/api/ai/proxy`, `/api/ai/render` (sanitise a reply for display — see the
  AI facts), `/api/ai/chats[...]`, `/api/drafts/{id}/chats`.
- `src/Support/` — `Resources`/`Paths` (filesystem locations), `App` (app identity/legal
  metadata: name, `VERSION`, copyright, licence + FSF URL, the verbatim Joomla! trademark
  disclaimer — sent to the SPA in the `bootstrap` payload's `app` key), and `UrlOpener`
  (opens an external http(s) URL in the OS default browser; backs `POST /api/open-url`).
  The sidebar footer shows the version and opens an About dialog using this metadata.
- `assets/private/` — SPA (`view/index.html`, `css/`, `js/`, `js/tinymce/`).
  **The three front-end libraries — TinyMCE 8, CodeMirror 5, FontAwesome 7 Free — are
  NPM-managed, not committed.** Their pinned versions live in `package.json`; running
  `composer run-script vendor:assets` (also fired automatically by `composer install`/`update`
  via `post-install-cmd`/`post-update-cmd`) does `npm install` then copies the prescribed subset
  into `assets/private/` per the `extra.copy-static` / `extra.minify` manifests in `composer.json`
  (`build/composer/InstallationScript.php` does the work). The copy targets — `js/tinymce/`,
  `js/codemirror/`, `css/{fontawesome,solid}.min.css`, `webfonts/` — are therefore **gitignored**.
  CodeMirror's npm package ships only unminified source, so the install step minifies it (terser +
  clean-css) into the `*.min.js`/`*.min.css` the HTML references. To update a library, bump its
  version in `package.json` and re-run `vendor:assets`. (`node`+`npm` are now build prerequisites;
  `scripts/build-all.sh` runs `vendor:assets` before `boson compile` because `boson.json` bundles
  `assets/private` at compile time.)
  ⚠️ **TinyMCE 8 requires a license key or it starts read-only** (v7 only warned when the key was
  absent). Grafida is a GPL build, so `tinymce.init()` passes `license_key: 'gpl'` (in `app.js`,
  beside `promotion`/`branding`); dropping it silently disables editing.
  UI icons use the **FontAwesome 7 Free** solid font (`css/fontawesome.min.css`
  + `css/solid.min.css` + `webfonts/fa-solid-900.woff2`) — never images/emoji. Action
  buttons carry a leading `<i class="fa-solid fa-…" aria-hidden="true">` before the label;
  in `app.js` use the `icon()` / `iconBtn()` helpers.
  ⚠️ **Every pane-level error/empty placeholder goes through `stateBlock()` / `errorState()`**
  (gh-29), never a hand-rolled `<div class="alert alert-error">` or a bespoke class: before this,
  the same caught error rendered three different ways — a narrow square in the Media Manager's CSS
  grid, a full-width bar with no icon in the Articles list, and unstyled centred text (a different
  class entirely) in the media browser modal. `errorState(err, {onRetry})` is the one call site an
  API-fetch `catch` should use; it reads `err.code === 'network_unreachable'` (see `src/Http/`) to
  show `GRAFIDA_MSG_OFFLINE` with the raw server message demoted to a muted `.state-block-detail`
  line, or the server's own message unchanged for anything else, so it can never swallow a real
  error. `.state-block`'s `grid-column: 1 / -1` is **load-bearing, unconditionally** — several of
  these are appended straight into an `auto-fill` CSS grid (the Media Manager grid, the media
  browser modal's grid), where a plain block would occupy a single `minmax()` column and render as a
  narrow square; the rule is inert (a no-op) in a non-grid parent, so one helper serves both shapes.
  An `onRetry` callback is attached only where the enclosing loader is idempotent and safe to
  re-run wholesale — never where a candidate handler mutates shared state before fetching, in which
  case either the outer loader is used instead or the retry is omitted entirely (an error block with
  no retry button is fine; a retry that leaves the screen half-built is not). Deliberately **left
  alone**: the Request Log's per-entry error line (a field inside a rendered record, not a pane
  placeholder), the connection-test result panel (an inline form result with its own layout), and
  the `typeof tinymce === 'undefined'` fatal (a full-page condition, not a list/grid item).
  Source-code editing uses vendored
  **CodeMirror 5** (`js/codemirror/`: `lib/` + `mode/{xml,javascript,css,htmlmixed}` +
  `addon/edit/{matchbrackets,closetag}` + `addon/dialog` + `addon/search/{search,searchcursor,
  jump-to-line}` + the `material-darker` dark theme) instead of
  TinyMCE's stock `code` plugin: that plugin is dropped from the `plugins` list, and a
  custom `sourcecode` toolbar button + Tools-menu item (registered in the editor `setup`)
  opens `openSourceCodeEditor()` — a `showModal()` dialog hosting a CodeMirror `htmlmixed`
  editor (light `default` / dark `material-darker` theme, matched to `State.resolvedTheme`).
  Save writes the source back via `editor.setContent(…, {source_view: true})` in one undo step.
  **Search/replace** (gh-34) is the stock CM5 search bar, *not* the Find/next/previous/all panel
  in the issue's screenshot — that is Joomla's CodeMirror **6**, a different library we do not
  ship. Four things worth knowing:
  - **TinyMCE never sees the find chord**, despite the editor living behind an editor button: the
    source editor is the SPA's own `showModal()` overlay in the top-level document, outside
    TinyMCE's container *and* its iframe, so its shortcut handlers never fire there. The two
    document-level chords in `app.js` (Ctrl/Cmd+S, Ctrl/Cmd+,) don't collide either.
  - `codeSearchKeys()` binds **platform-native chords only** — Cmd on macOS, Ctrl elsewhere,
    per the gh-13 rationale — to the *persistent* commands (`findPersistent` &co.); replace,
    replace-all and `Alt-G` jump-to-line come from CodeMirror's own default keymap.
  - ⚠️ **`makeCodeSearchPersistent()` is load-bearing twice over.** CodeMirror's "persistent" bar
    is only persistent against Enter — it still closes on focusout, so clicking into the code to
    fix what you found would drop the bar and every match highlight; the governing `closeOnBlur`
    option is hard-coded inside the addon, so the helper wraps the instance's `openDialog` to
    force it (closing any open dialog first, since without blur-closing two would stack). It then
    installs a **capture-phase Escape guard**, because with the bar open and focus in the code
    Escape would otherwise reach `_modalEscHandler` and discard the whole edit.
  - The dialogs' English literals are localised through CodeMirror's **`phrases`** option
    (`codeSearchPhrases()`), which is keyed by the addon's own source strings — so the map's keys
    are `'Search:'`, `'Replace all:'` … and must not be tidied. Yes/No reuse `GRAFIDA_BTN_YES`/`_NO`.
  `addon/search/matchesonscrollbar` (scrollbar match markers) is deliberately **not** vendored:
  `annotatescrollbar` only paints when `cm.display.barWidth` is non-zero, which overlay scrollbars
  (macOS, and Chromium generally) make 0 — it would render on some platforms and not others.
  The toolbar also carries a **"Styles" drop-down** (`styleselect`, a custom `addMenuButton`
  registered in `setup`) that applies a CSS class to the selection the way Joomla's editor does.
  Its class list is `editorStyleClasses()` — class names `parseEditorCssClasses()` discovers in the
  site's cached `editor.css` **plus** a small built-in `EDITOR_CLASS_DEFAULTS` fallback set (deduped,
  alphabetical). Application is automatic: a non-collapsed text selection is wrapped in a
  `<span class="…">` (an `inline` format), a mere cursor sets the class on the enclosing block (a
  `selector` format over common block/img/anchor tags — it never changes the tag). Each class is
  pre-registered as a `grafidaInline_N` / `grafidaBlock_N` format pair in the init `formats` option;
  menu items are toggles whose active state mirrors `editor.formatter.match()`.
  **Slash commands** (`js/editor/slashtools.js`, `window.GrafidaSlashTools`, gh-9): typing `/` opens a
  filterable command menu — headings, lists, dummy text, quote, read more, images, link, table,
  source code, fullscreen. Ported from Brian Teeman's
  [slashtools](https://github.com/brianteeman/slashtools) TinyMCE plugin (GPLv3), **not integrated**:
  upstream ships as a Joomla extension wired up through TinyMCE's "External Plugin URLs" setting, and
  neither half exists here — `js/tinymce/` is npm-vendored and **gitignored** (a plugin file dropped
  in there would be untracked and wiped by the next `vendor:assets`) and Grafida sets no
  `external_plugins`. So it is a plain IIFE loaded after `app.js`, calling
  `editor.ui.registry.addAutocompleter` (the only one in the codebase) from `initTinyMCE()`'s `setup`.
  Four things worth knowing:
  - **The off switch is enforced in `fetch`, not at registration.** `fetchItems()` returns `[]` when
    `State.slashTools` is false and an autocompleter with no results shows no popup, so toggling the
    Settings option takes effect on an already-open editor — unlike the `hasAiService` toolbar gate,
    which is baked in at init and needs a re-open.
  - ⚠️ **The placeholder images are PNG, minted on a `<canvas>` — upstream's SVG would break a
    publish.** `Html\InlineMedia::rewriteOfflineImages()` uploads *every* offline image (local-URL
    or legacy `data:`, see `src/Media/`) to the site's Media Manager, and Joomla rejects SVG by
    default, so an SVG placeholder left in an article aborts the publish outright.
  - **Labels are localised but filtering also matches English `keywords`**, so `/head` still finds the
    headings on a translated UI. The label is `t(key)` resolved per `fetch`, so a language switch
    needs no re-registration. A keyword matches only at the **start of one of its words**, not as a
    substring: `/ordered` would otherwise surface the *bulleted* list first (its own keyword is
    `unordered`) — and the first item is the one Enter picks.
  - ⚠️ **A command inserts translated text, so it must escape it** — and *which* escaper depends on
    the context. `escapeHtmlText()` serialises a text node, so it escapes `&`/`<`/`>` but leaves a
    **double quote alone**: fine for element content (the headings, list item, quotation), useless for
    an attribute value, where a quote in a translation closes the attribute early. The placeholder
    `<img>` therefore goes through **`editor.dom.createHTML('img', {...})`**, which escapes attributes
    properly.
  - **TinyMCE ships no `h1`/`h2`/`h3` icons** (upstream's heading items silently render a fallback
    glyph), hence the `grafida-h1..3` `addIcon` calls; and upstream's "Ordered list" inserts a `<ul>`,
    fixed here to `<ol>`.
  `tests/js/slashtools.test.mjs` covers the filtering, separator collapse, off switch and what each
  command inserts. The read-more item shares `app.js`'s `insertReadMore()` with the toolbar button
  (which is what refuses a second separator).
  **The Help dialog is the only in-app editor documentation**, so `menu.tools.items` keeps the
  stock `help` item (the overridden Tools menu would otherwise drop it, leaving the dialog
  reachable by Alt+0 alone), and `help_tabs` (`editorHelpTabs()` in `app.js`) adds a **Grafida**
  tab listing the app's own shortcuts plus, when an AI service is configured, an **AI assistant**
  tab (gh-13). Two traps: `help_tabs` **replaces** the default tab list rather than extending it,
  so the built-in names (`shortcuts`, `keyboardnav`, `plugins`, `versions`) must be repeated to
  keep them; and the built-in "Handy Shortcuts" tab is a **hard-coded table** — it does not read
  the editor's shortcut registry, which is why an `addShortcut()` never appears there and Grafida
  needs a tab of its own. A dialog `table` cell and an `htmlpanel` are both set via **innerHTML**,
  so anything user-supplied (an AI tool's title) goes through `escapeHtmlText()`;
  `helpShortcutText()` mirrors the help plugin's own `convertText()` so our rows render as
  ⌘/⌃/⇧ glyphs on macOS and `Ctrl + …` elsewhere, and its output is therefore HTML (escape a
  sentence *before* interpolating a shortcut into its `%s`).
  ⚠️ **A shortcut's modifier gate is `hasPrimaryModifier()`, never `e.ctrlKey || e.metaKey`**: on
  Windows `metaKey` is the **Windows key**, whose chords belong to the OS (Win+S opens Windows
  Search), so accepting either key on every platform binds us to a chord we don't own (gh-13). It
  resolves to Cmd on macOS and Ctrl elsewhere — the same mapping TinyMCE's own `meta` modifier
  uses, which is why `addShortcut('meta+s', …)` needed no such fix.
  **Spell checking** uses the native webview checker (`browser_spellcheck`, driven by the
  `spell_check` setting — **default on**, toggled from the Options page, see `SpellCheckService`) —
  the bundled TinyMCE spellchecker plugin was removed in v6+ and the replacement is a premium cloud
  service we won't use in an offline editor. This sets `spellcheck="true"` on the editing body and defers to the
  OS/webview dictionary (WKWebView/`NSSpellChecker` on macOS, WebKitGTK on Linux, WebView2 on Windows);
  suggestions appear in the *native* context menu via **Ctrl/Cmd + right-click** (TinyMCE's own context
  menu intercepts a plain right-click). ⚠️ **That native menu must be explicitly enabled or the
  suggestions are unreachable** (gh-26): Boson leaves the webview context menu **off** in non-debug
  builds (`WebViewCreateInfo::$contextMenu` defaults to `$app->isDebug`), so a release build had no
  native menu to fall back to — on Windows/WebView2 (`AreDefaultContextMenusEnabled = false`)
  Ctrl+right-click did nothing at all; macOS/WKWebView happened to still surface its spelling menu,
  which is why it looked platform-specific. `index.php` therefore passes
  `WebViewCreateInfo(contextMenu: true)`. This is safe: TinyMCE `preventDefault`s its own
  plain-right-click menu (the silver theme's desktop-show path), so no double menu appears inside the
  editor, and it steps aside (no `preventDefault`) on Ctrl+right-click so the native menu shows. The
  deliberate side effect is that right-clicking the SPA chrome *outside* the editor now shows the
  webview's native menu too (native text-editing entries in inputs, harmless elsewhere); `devTools`
  stays debug-gated, so "Inspect element" does not appear in production. ⚠️ **On macOS the checker is dead until continuous spell
  checking is enabled, and Boson gives no way to enable it — so Grafida must** (gh-24). WKWebView gates
  *all* native spell checking (even a freshly typed misspelling) on the `WebContinuousSpellCheckingEnabled`
  NSUserDefaults flag, which its text checker reads once, lazily, on the first check (WebKit's
  `TextCheckerMac.mm`). A normal Mac app flips this from its **Edit ▸ Spelling ▸ "Check Spelling While
  Typing"** menu item (`-toggleContinuousSpellChecking:`); Boson wires up no menu bar, so on any machine
  where no other WebKit app has already turned it on the flag stays off and nothing is ever underlined —
  which is why it appeared to "work for one person and not another" on identical code. `index.php` fixes
  this by calling `Grafida\Editor\MacSpellCheck::enable()` **before the app boots** (so it precedes the
  first spell-check): it sets the flag to `true` in Grafida's **own** preferences domain (never the
  global one — that would change every WebKit app) via CoreFoundation's **CFPreferences** C API through
  FFI. CFPreferences is plain C, dodging the arm64 `objc_msgSend` variadic-calling-convention hazard, and
  unlike a `defaults write` subprocess it spawns nothing. Best-effort — a failure just leaves spell
  checking off, as before. Linux/Windows webviews have no such gate. **The spell-check language is an OS setting Grafida cannot
  override** — there is no JS/HTML API to pin a dictionary and no native hook into Boson's webview. On
  macOS in particular, results depend on System Settings → Keyboard → Text Input → Spelling: set to a
  fixed language (e.g. "U.S. English"), text in any other language is flagged wholesale; set to
  "Automatic by Language", per-sentence detection works but **only among the languages enabled in that
  list** (so a German writer must enable German there). Windows/Linux likewise defer to their OS
  spell-check configuration. This is a documented limitation, not a bug.
  **The editor content follows the app's resolved colour scheme, not the webview's lie** (gh-38).
  The content iframe loads the site's `editor.css` (`State.editorCss`), and Boson's webview
  misreports `prefers-color-scheme` to that document exactly as it does everywhere else — always
  dark on macOS, the same lie `Display\DisplayModeService::systemPrefersDark()` exists to work
  around for the app chrome. A stylesheet with automatic dark mode (e.g. Bootstrap 5.3 built with
  `$color-mode-type: media-query`) therefore rendered the editor content permanently dark, whatever
  Grafida's own theme was. There is no way to make the webview report the truth, so
  `js/editor/csstheme.js` (`window.GrafidaCssTheme.resolveColorScheme(css, scheme)`) resolves those
  `prefers-color-scheme` media queries **in the CSS text itself**, against `State.resolvedTheme`,
  before the stylesheet becomes the Blob URL `initTinyMCE()` hands to `content_css`: a query
  requiring the resolved scheme has the feature stripped so its block applies unconditionally
  (recognising the plain `(prefers-color-scheme: dark)` form, the boolean `(prefers-color-scheme)`
  form, and both Level-4 negation spellings — `not all and (…)` and `(not (…))`); a query requiring
  the other scheme is removed, dropping the whole `@media` block if nothing else keeps it alive. It
  is a pure string transform (no app.js globals), string/comment-aware so a `content: "@media {"`
  declaration cannot derail the scan, and recurses into a kept block's body so a nested `@media`
  inside `@media`/`@supports`/`@layer` is resolved too. It is **deliberately conservative**: a
  prelude with a top-level `or`, a query mentioning both schemes, or any `prefers-color-scheme`
  mention in a form it does not confidently parse, is left **exactly as found**, contents
  included — mangling a real-world stylesheet is a far worse failure mode than leaving today's
  behaviour in place. ⚠️ `State.editorCss` itself stays **raw** — `parseEditorCssClasses()` (the
  Styles drop-down) must keep seeing every class name, including one that only appears inside a
  dark block, and the transform must be re-runnable against the original when the theme changes,
  which it is: `applyTheme(true)` → `initTinyMCE()` re-resolves it every time (see that function's
  doc comment). The call site guards against the module being absent or throwing, falling back to
  the untransformed CSS — the editor must never fail to open over this. `content_style` also gets a
  `:root { color-scheme: … }` declaration so the iframe's UA-rendered bits (form controls,
  scrollbars) agree with the theme too; in dark mode this is emitted **only** when the editing
  surface is actually dark (no site stylesheet at all, or the site stylesheet's own dark rules
  actually matched — the transform's `matched` flag) — otherwise a light-only stylesheet's own
  `color:` rules would sit on a forced-dark canvas and become unreadable. In light mode it is always
  safe to emit (light is the UA default, so it can only correct the webview's wrong guess).
  **The editor UI language follows the interface language.** `tinymce.init()` is given a
  `language` + `language_url` (`editorLanguage()` / the `TINYMCE_LANGS` map in `app.js`) pointing
  at the matching pack vendored under `js/tinymce/langs/` (`el`, `fr-FR`, `de`, `es`, `it`,
  `pt-PT` — sourced from the `tinymce-i18n` `langs8/` packs for TinyMCE 8, whose two-part tags use
  RFC5646 hyphenated filenames, `fr-FR.js`/`pt-PT.js`, not the v7 underscore form). **en-GB has no
  pack** — TinyMCE's
  built-in UI is English — so it (and any unmapped tag) falls through to the English default with no
  `language` set. `language_url` is an absolute `/js/tinymce/langs/<code>.js` path because the init's
  `document_base_url` is the *site* URL, which would otherwise mis-resolve a relative path. Adding a
  shipped language needs **both** its pack name added to the `tinymce-i18n` langs filter in
  `composer.json`'s `extra.copy-static` (so `vendor:assets` copies it into `js/tinymce/langs/`) **and**
  a `TINYMCE_LANGS` entry in `app.js` (none for languages TinyMCE has no pack for — they get the
  English editor UI). This is the editor UI *chrome*; it is unrelated
  to the spell-check dictionary (an OS setting, above) and the article content language.
  The active site is remembered **server-side**: `rememberLastSite()` persists it via
  `POST /api/settings/last-site` (`Site\LastSiteService`, the generic `settings` key/value store —
  key `last_site`, so no migration) and it rides back in the `bootstrap` payload as `lastSiteId`
  (seeded into `State.lastSiteId`, which `recallLastSite()` reads). ⚠️ It is **not** kept only in
  `localStorage` — Boson's webview does **not** persist `localStorage` across an app restart, which
  is the one moment the preference must survive, so a `localStorage` copy is written too but only as
  a same-session cache; the server value is authoritative. `rememberLastSite()` writes fire-and-forget
  and only when the id actually changed (dedupes the per-render call in `renderSiteSelector()`), and
  a since-deleted remembered site self-heals — `renderSiteSelector()` validates the id against the
  live site list and falls back to the first site, persisting that as the new last site. On startup
  `bootstrap()` opens the **Articles** page (instead of **Sites**) when at least one site is
  defined and a previously remembered last active site is still in the list — the remembered id
  is read *before* `renderSiteSelector()` writes its first-site fallback, so a freshly added but
  never-selected site does not trigger the Articles default.
  ⚠️ **A category drop-down's tree indent is Joomla's `- ` prefix, never leading spaces**
  (gh-40): `categoryTreeOptions()` is the single place both the editor sidebar's Category
  select and the Articles screen's category filters get their `[id, label]` pairs, ordered
  by `lft` and indented by `level` relative to the shallowest category present (never by
  `parent_id` — the hidden ROOT node's id must not be assumed). The indent characters are
  load-bearing: HTML collapses leading whitespace in an `<option>`, so the space-padded
  labels this replaced rendered as a flat list.
  **`State.references` is tagged with the site it belongs to** (`State.referencesSiteId`, gh-42):
  it is a single slot shared by every screen (the editor sidebar, `makeAlias()`'s alias preview,
  `collectDraftFormData()`'s custom fields), so nothing may read it directly — every reader goes
  through `cachedReferences(siteId)`, which returns `null` unless the slot's tag matches, and every
  writer goes through `setCachedReferences(siteId, refs)`. This is what stops the editor from ever
  reusing another site's categories, which the previous untagged slot could not rule out. A
  metadata reload (the Sites-screen button, the editor sidebar's own button — which sits directly
  below **Tags**, next to the Category/Access/Language/Tags group it refreshes, not at the
  sidebar's bottom — the Articles screen's own **Reload metadata** button next to its tab strip, or
  the background freshening below) always goes through **`invalidateSiteReferences(siteId)`** —
  the single place that drops **both** per-site caches of the site's reference data,
  `State.references` *and* the Articles screen's independent `State.articleListRefs` (whose
  omission from the reload path was the gh-42 bug: the category/tag/language filter drop-downs kept
  whatever they were first built with for the whole session, even after a successful refresh) —
  followed by **`applyRefreshedReferences(siteId, refs)`**, which re-seeds the slot and repaints
  whichever screen is showing data derived from it. For the Articles screen this is now a
  **surgical** repaint rather than a full `loadArticlesScreen()` teardown (which would refetch
  drafts *and* the remote page and reset scroll on every background refresh): it re-seeds
  `State.articleListRefs` straight from the payload in hand, calls
  **`reconcileArticleFilters()`** to clear any selected category/tag/language that no longer exists
  in the refreshed lists (drafts' tag filter matches on **title**, the remote tab's on **id** — both
  are checked), rebuilds just the two filter bars in place via **`rebuildArticleFilterBars()`**, and
  reloads the remote list only when its own filters actually changed (the drafts list is always
  cheap to re-render, being pure client-side filtering). ⚠️ `reconcileArticleFilters()` treats an
  **empty refreshed category list** as "could not read the site" and clears nothing — every Joomla
  site has at least Uncategorised, and a references payload for a site with no stored token comes
  back as empty lists with a perfectly successful 200, so an unreachable site or a startup reset
  racing an offline network must never look like every filter silently vanished. The **tag and
  language** lists get no such treatment: plenty of real sites genuinely have neither, and a filter
  on one that has since been deleted must still be cleared. Its language check also mirrors
  `languageFilterOptions()`'s own `published` filter, or a filter could survive with no option left
  to display it. The Articles reload button (`reloadArticlesMetadata()`) shows an extra
  `GRAFIDA_MSG_FILTERS_RESET` toast when reconciliation actually cleared something, since a filter
  resetting itself with no explanation would otherwise look like a bug. The editor is re-rendered
  through its own form-preserving path (`collectDraftFormData()` merged back over the draft) when
  it is open on that site — never TinyMCE itself. `reloadSiteMetadata()` now returns the
  `{remote, drafts}` reset-flags object (or `false` on failure) instead of a bare boolean, so a
  caller can tell whether a filter was cleared.
  Because `reference_cache` is otherwise permanent server-side (see `src/Reference/`),
  **`ensureFreshReferences(siteId, fetchedAt)`** quietly calls `applyRefreshedReferences()` in the
  background after the screen has already rendered from cache, once `fetchedAt` is older than the
  **configurable TTL** (`State.metadataCacheTtl`, minutes; `referencesMaxAgeMs()` converts it, `0`
  meaning automatic refreshing is switched off entirely) — mirroring
  `Reference\MetadataCacheService::TTL_CHOICES` in PHP via `METADATA_TTL_CHOICES` in `app.js`, which
  must be kept in step. ⚠️ **There is no "once per session" rule any more** (gh-42 round 2 removed
  it): round 1's `State.referencesFreshened` unconditionally refreshed every site once per launch
  regardless of the TTL, which is exactly the always-on startup refresh the issue says must be
  opt-in — that behaviour is now `Reference\MetadataCacheService`'s `metadata_reset_on_start`
  preference (default off, see `src/Reference/`), a real server-side cache delete, not an SPA
  refresh. `State.referencesRefreshing` (a `Set`) replaces it purely as an **in-flight guard**:
  it stops two overlapping callers firing the same refresh, and breaks the re-entrancy loop
  `ensureFreshReferences()` → `applyRefreshedReferences()` → (Articles screen) →
  `loadArticleFilterRefs()` → `ensureFreshReferences()` again. It is fire-and-forget: no toast, no
  error surfaced, and a failure simply leaves the site available to retry on the next stale check —
  so an offline site opens exactly as it did before any of this existed. ⚠️ `fetchedAt` is a naive
  UTC `Y-m-d H:i:s` string (see `src/Reference/`) and is compared **as a string** against
  `utcStampAgo()`'s own naive UTC stamp, never via `Date.parse()` — the same WKWebView mishandling
  already documented for `ai_chats.last_response_at` and `drafts.updated_at`. Both
  `metadataResetOnStart` and `metadataCacheTtl` ride in the `bootstrap` payload and are editable
  from the Settings screen's **Site metadata** card (`renderMetadataTtlSetting()` /
  `renderMetadataResetSetting()`, `POST /api/settings/metadata-cache` — sending only the field that
  changed, so the two selectors' independent `change` events never clobber each other; the response
  carries the **effective**, clamped values, which the SPA writes back so a server-side clamp is
  visible rather than silently reverted on the next render).
  **Collapsible/resizable layout** (`initLayoutControls()` in `app.js`): the left **`#sidebar`**
  and the editor metadata **`#editor-sidebar`** ("Article properties") each carry an `.icon-toggle`
  button (`#sidebar-toggle` / `#editor-sidebar-toggle`) that toggles a `.collapsed` class — the left
  sidebar collapses to a 56px icon-only rail, the metadata sidebar to a 40px rail; the state persists
  in `localStorage` (`grafida.sidebarCollapsed` / `grafida.propsCollapsed`). The **`#ai-panel`** is
  width-**resizable** by dragging `#ai-panel-resizer`, a `col-resize` handle on its left edge
  (`setupAiPanelResize()`, pointer events, clamped 280px…`min(innerWidth−360, 760)`); the chosen
  width persists in `grafida.aiPanelWidth`. Toggle buttons localise their tooltip/`aria-label` via a
  `data-i18n-title` attribute (`applyStrings()` sets both `title` and `aria-label` from it).
  The collapsed left rail is icon-only, so `syncSidebarTooltips()` mirrors each nav item's (and the
  footer's) visible label into a `title` **only while collapsed**, and keeps the `aria-label` set in
  both states — a collapsed item's label is `display:none`, leaving it with no accessible name at
  all. It re-runs on toggle, on `applyStrings()` (language switch) and from `renderSidebarFooter()`
  (the version label is filled in asynchronously by `bootstrap()`), since it copies rendered text
  rather than looking keys up itself. The sidebar's **Request Log** item (`#nav-request-log`,
  below Settings, gh-37) is `hidden` unless `State.requestLog` is on — an opt-in Debug setting,
  not an always-present link — so `renderSidebarNav()` toggles its `hidden` attribute and, because
  hiding or showing an item changes which labels the collapsed rail has to mirror, **must also
  re-run `syncSidebarTooltips()`** itself rather than waiting for one of that function's other
  three triggers to fire on its own. ⚠️ The `hidden` attribute alone does **not** hide it: the UA's
  `[hidden] { display: none }` loses to `app.css`'s `nav#main-nav a { display: flex }`, so the rule
  is restated as `nav#main-nav a[hidden]` — without it the item shows even with the setting off.
  **The in-article media browser (`openMediaBrowser()`) grew a second tab** (gh-43), mirroring the
  Media Manager screen's own `buildMediaTabs()`/`applyMediaTab()` pattern (same
  `articles-tabs`/`articles-tab` classes): **Site media** is the original online Joomla Media
  Manager browse, unchanged; **Local media** lists offline `media_blobs` (`GET
  /api/sites/{id}/local-media`) — an image already pasted/dropped/picked into *some* draft but not
  yet published — so it can be reused elsewhere without leaving the editor to go find it, and,
  reading nothing but local SQLite, it **works with the site unreachable**, independently of
  whatever the Site tab's own fetch is doing. Tab state is a plain closure variable local to the
  modal instance, not `State` — `State.mediaTab` belongs to the Media Manager screen, a different
  UI the modal can be open over. Both tabs resolve the picker with the same `{url, name, mediaId?}`
  shape, so `file_picker_callback`/`browseImageMedia()` need not know which tab a pick came from;
  a Local entry's `url` is used exactly as returned (it already carries its own `rev`
  cache-buster, see `src/Media/LocalMediaUrl` above), unlike the Site tab's `mediaDisplayUrl(f)`.
- `language/<tag>/<tag>.ini` — translations, one file per language (e.g. `language/de-DE/de-DE.ini`).
  (There is **no** Joomla `.sys.ini` or `language/grafida.xml` manifest, and the files are **not**
  named `<tag>.com_grafida.ini` — Grafida is a desktop app, not a Joomla component. `LanguageService`
  loads each catalogue with joomla/language's empty-extension "internal" naming, i.e. a bare `<tag>.ini`.)
  The shipped-language list is **not** hard-coded: `LanguageService::available()` discovers it at
  runtime by scanning `language/` for every `<tag>/<tag>.ini` and reading that file's
  `GRAFIDA_LANGUAGE_ENDONYM` key (the language's name in its own tongue) for the label; the default
  (en-GB) sorts first, the rest by endonym. So every `.ini` MUST carry `GRAFIDA_LANGUAGE_ENDONYM`,
  and adding a translation needs no code change (the list is sent to the SPA as `bootstrap`'s
  `availableLanguages` tag => endonym map).
- `storage/migrations/*.sql` — schema. `.plans/` — implementation step notes (gitignored).
- `build/glossaries/` — per-language translation glossaries.
- `build/icon/` — application icon. `grafida.svg` is the **single master** (clipart pencil
  drawing a capital “J”); `scripts/make-icons.sh` rasterises it into `Grafida.icns` (macOS),
  `Grafida.ico` (Windows), a `png/` set + `grafida.png` (Linux), all committed. Wiring:
  `make-macos-app.sh` copies the `.icns` into the bundle + `Info.plist` (`CFBundleIconFile`);
  the Windows installer bundles the `.ico` beside `grafida.exe`; Linux ships `grafida.desktop`
  + a hicolor PNG. `build/` is otherwise gitignored — the whitelisted exceptions are
  `build/icon/`, `build/glossaries/`, `build/composer/` (the npm-vendoring install script), and the
  two packaging sources `build/linux-install.sh` + `build/windows-installer.nsi` (see
  `build/.gitignore`). Re-run make-icons after editing the SVG.

## Build & packaging (one step)

**Front-end vendoring:** the build host needs `node`+`npm`. The npm-managed libraries (TinyMCE,
CodeMirror, FontAwesome — see the `assets/private/` note above) are gitignored, so `build-all.sh`
runs `composer run-script vendor:assets` before compiling to populate `assets/private` (which
`boson.json` bundles at compile time).

`composer build` → `scripts/build-all.sh` is the **one-shot** compile-and-package pipeline; it
runs `boson compile` (every target in `boson.json`) then packages each platform into
**`build/dist/`** (gitignored) by delegating to the per-platform `scripts/make-*.sh` helpers (the
same ones the Phing `package-*` targets call — single source of truth). The version comes from
`App::VERSION` (override via `GRAFIDA_VERSION`). Per-platform packaging is tolerant (missing binary →
warn+skip), but a failing compile or a genuine packaging-tool error is fatal. Pieces:
- macOS (arm64+amd64, macOS host only): `scripts/make-macos-app.sh <arch>` assembles
  `build/macos/<bosondir>/Grafida.app` — Boson names the arm64 dir `aarch64`, amd64 stays
  `amd64` — then `scripts/make-dmg.sh <arch>` wraps it (via `hdiutil`) into
  `Grafida-<v>-macos-<arch>.dmg` with an `/Applications` symlink. **The DMG has a branded
  Finder layout**: it mounts the writable UDRW image, drops the background artwork into
  `.background/background.tiff`, then `osascript`s Finder to hide the toolbar, set a 640×400
  window with a 128px icon view, apply the background, and position the app at `{160,210}`
  next to `/Applications` at `{480,210}` (the background draws the header + a "drag onto
  Applications" arrow in the gap between them). It also writes a `.VolumeIcon.icns` — **after**
  the Finder styling, since opening the volume in Finder deletes a pre-existing one. The whole
  styling stage is **best-effort**: a missing asset or an `osascript` failure (e.g. the macOS
  automation-permission-to-control-Finder TCC prompt is denied) only warns and still emits a
  functional plain DMG. The background is a committed raster (`build/icon/dmg-background.{png,
  @2x.png,tiff}`) rendered from the single SVG master `build/icon/dmg-background.svg` by
  `scripts/make-dmg-background.sh` — a multi-resolution `.tiff` (`tiffutil -cathidpicheck`) for
  retina — following the same "SVG master → committed raster" pattern as the app icons;
  `scripts/make-icons.sh` invokes it too, so one command refreshes all visual assets. **Code signing works via a
  patched SFX runtime** — docs: `build/readme/01-macos-signing.md` (Apple setup + recipe +
  unsignability analysis), `02-signing-architecture.md` (architecture, why the fork exists,
  design decisions), `03-sfx-maintenance.md` (PHP/Boson bump playbook, troubleshooting): a
  `micro.sfx` built with static-php-cli from the `nikosdion/phpmicro` fork's `sibling-phar`
  branch (which adds an additive fallback — no appended payload → load `"<self>.phar"`, then
  `"../Resources/<self>.phar"`, realpath-canonicalised so the offset stream hooks keep
  matching) lives in the gitignored `build/sfx/<os>-<cpu>.standard.sfx`. **The fork's own
  GitHub Actions (`build-sfx.yml`) builds the SFX for macOS arm64+x86_64 AND Windows x86_64 on
  every push to `sibling-phar`** (via stock static-php-cli pointed at the repo with `-L`; Windows
  is a separate `build-windows` job — MSVC/php-sdk toolchain, PowerShell smoke test) and publishes
  them to the rolling `sfx-latest` release; `scripts/fetch-sfx.sh` downloads + SHA-256-verifies
  the assets (`macos-{aarch64,x86_64}` + `windows-x86_64`) into `build/sfx/` (never overwrites
  existing files; `--force` re-downloads) and runs automatically from the Phing `prepare-sfx` step
  (macOS git targets) and `build-all.sh` — best-effort, offline builds fall back to the stock runtime.
  `build/tasks/compile-target.php` injects the SFX as the Boson target's `sfx` when present,
  and pre-cleans the output dirs — Boson's cleanup chokes on the previous `Grafida.app`,
  silently leaving a stale binary; `build-all.sh` compiles through `compile-target.php --all`
  for the same reason. `make-macos-app.sh` detects the patched runtime, splits the
  compiled binary into a clean Mach-O stub (`Contents/MacOS/grafida`) + payload
  (`Contents/Resources/grafida.phar`; codesign refuses data files in `Contents/MacOS`, so
  `assets/` also live in Resources with a dylib symlink for the phar's mounts), and signs the
  whole bundle — ad-hoc by default, Developer ID + hardened runtime + notarisation when
  `MACOS_SIGN_IDENTITY`/`MACOS_NOTARY_PROFILE` are set (verified end-to-end: notarisation
  Accepted, `spctl` "Notarized Developer ID"). Without `build/sfx/` the legacy combined-binary
  layout is used and Developer-ID signing aborts with a clear error.
- Linux (amd64+arm64): `scripts/make-linux-tarball.sh <arch>` builds a `.tar.gz` of the per-arch
  output dir (binary + `libboson-linux-*.so` + `assets/`) plus the icon, `grafida.desktop`, and
  `build/linux-install.sh` (renamed `install.sh`) — a per-user XDG desktop-integration installer.
- Windows (amd64): `scripts/make-windows-installer.sh` compiles `build/windows-installer.nsi` with
  **NSIS** `makensis`, which runs natively on macOS/Linux (no Wine/Docker/Windows) →
  `Grafida-<v>-windows-amd64-Setup.exe` (per-user install in `%LOCALAPPDATA%\Programs\Grafida`).
  Falls back to a portable `.zip` if `makensis` is absent. **Authenticode signing works the same
  way as macOS — by splitting.** `boson compile` appends the PHAR after the PE stub; signing the
  combined binary appends the certificate *past* the PHAR and corrupts its trailing signature, so
  the app dies at startup on `Phar::mapPhar` ("grafida.exe has a broken signature"). So when the
  patched SFX (`build/sfx/windows-x86_64.standard.sfx`) is present, `make-windows-installer.sh`
  splits `grafida.exe` into a clean PE stub + sibling `grafida.phar` (offset from
  `build/tasks/pe-sfxsize.php`, which replicates phpmicro's `max(PointerToRawData+SizeOfRawData)`
  and asserts Boson's extra-ini magic `fd f6 69 e6` sits there), signs **only the stub** (Jsign/Azure
  Trusted Signing via `scripts/sign-windows-exe.sh`, gated on `WINDOWS_SIGN_OP_ITEM`), and NSIS
  ships both (`HAVE_PHAR`). `sign-windows-exe.sh` **refuses** to sign any PE still carrying a PHAR
  overlay (runs `pe-sfxsize.php` as a tripwire), so the combined binary can never be signed by
  accident; the installer `Setup.exe` (NSIS overlay, not a PHAR) signs fine. Without the patched
  SFX the unsigned combined binary ships (it works); if signing is configured but the SFX is
  missing, the script aborts rather than emit a broken signed binary. Docs:
  `build/readme/04-exe-signing-on-macos.md`, `02-signing-architecture.md`.
  **The installer bundles the Visual C++ 2015-2022 runtime app-local.** Boson's
  `libboson-windows-x86_64.dll` imports `MSVCP140*`/`VCRUNTIME140*`, which a clean Windows
  (especially Server) lacks — without them grafida.exe dies at startup with an FFI *"The
  specified module could not be found"*. Windows resolves a DLL's imports from the app dir first,
  so the four CRT DLLs ride next to grafida.exe (no admin, unlike the machine-wide redist). They
  are collected by the phpmicro `build-windows` CI (the only Windows box with VS) and published as
  `vc-runtime-x86_64.zip`; `scripts/fetch-sfx.sh` downloads + extracts them to `build/sfx/vc-runtime/`,
  `make-windows-installer.sh` copies them into the package dir, and NSIS ships every `*.dll` beside
  the exe (`File "${SRCDIR}/*.dll"` — libboson + the VC runtime). Best-effort: a build without the
  fetched runtime just omits them.
  **The flashing CMD window is suppressed at startup.** grafida.exe runs on a console-subsystem
  PHP runtime (the phpmicro SFX is a CLI build), so Windows gives it a console. `index.php` hides
  it immediately via FFI (`ShowWindow(GetConsoleWindow(), SW_HIDE)`), which also stops the
  per-click flashing: the console subprocesses the backend spawns (`Grafida\Secret\ProcessRunner`
  — the registry theme probe) **inherit** the hidden console instead of each popping a fresh visible
  one. **The secret store no longer spawns at all:** the old `WindowsSecretStore` shelled out to a
  whole `powershell.exe` (~1s cold start) for every DPAPI protect/unprotect, and because the
  `boson://` kernel is single-threaded that froze the UI on every request needing a stored secret
  (site token, AI key) — the multi-second stall. `Grafida\Secret\WindowsDpapi` now calls
  `crypt32.dll`'s `CryptProtectData`/`CryptUnprotectData` **directly via FFI** (sub-millisecond, no
  subprocess); it is byte-compatible with the .NET `ProtectedData` CurrentUser/no-entropy blob the
  PowerShell path wrote, so existing secrets keep working, and PowerShell remains a fallback only
  when FFI is unavailable. `WindowsSecretStore` also memoises decrypted secrets for the session (it
  is a container singleton) and no longer probes with `where powershell`. The registry theme probe
  (`DisplayModeService::windowsPrefersDark()`, on window focus) also **no longer spawns**: it reads
  the `AppsUseLightTheme` DWORD directly via FFI (`Grafida\Display\WindowsThemeReader`, calling
  `advapi32.dll`'s `RegGetValueA`), because `proc_open` does not pass `CREATE_NO_WINDOW` and a
  `reg.exe` child could still briefly flash a console even with the hidden console; `reg.exe` remains
  a fallback only when FFI is unavailable.
- PHAR: `scripts/make-phar-dist.sh` copies the compiler's `build/phar/grafida.phar` to `Grafida-<v>.phar`.

**Binaries-only build (no packaging):** `build.xml` (root) is a **Phing** buildfile whose default
target `git` (also `composer build:git`) compiles the native binary for **every** platform but stops
short of the installers/DMG — `git` depends on six per-platform targets (`git-macos-arm`,
`git-macos-x86`, `git-win-x86`, `git-linux-x86`, `git-linux-arm`, `git-phar`). **Phing is expected as a
globally-installed command** (`phing` on the PATH — like the other Akeeba projects; it is deliberately
*not* a Composer dev dependency), so `composer build:git` just shells out to `phing git`. Because
`boson compile` builds *all* `boson.json` targets in one pass with no per-OS CLI flag, each target
shells out to `build/tasks/compile-target.php`, which filters the master `boson.json` down to the one
requested `--type`/`--arch` at runtime (pinning an explicit `root` so the throwaway single-target config
can live in `build/.temp/`), drops the stale box/entrypoint cache, then runs `boson compile
--config=<temp>`. All six depend on a guarded `prepare` (sub-targets `prepare-composer` +
`prepare-icons` + `prepare-assets`) that, only when their output is missing, runs `composer install`
(so a fresh `git clone … && phing` bootstraps itself — and since Composer's post-install-cmd runs
`vendor:assets`, that also vendors the front-end libraries), re-rasterises the icons, and vendors the
front-end libraries (force a re-vendor with `-Drefresh.assets=1`); Phing runs `prepare` once per
invocation. `prepare` also runs **`set-version`** (`build/tasks/set-version.php`): the **`CHANGELOG`
is the single source of truth for the version** — its topmost entry's heading ends with the version
number (Akeeba convention, e.g. `Grafida 0.1`; parsed like Akeeba's `AutoVersionTask`), and the step
stamps it into `App::VERSION` in `src/Support/App.php` before every compile (idempotent; no-ops when
already current). `GRAFIDA_VERSION` overrides the CHANGELOG. So every `git-*` build (and transitively
`package-*`/`run`) reports the CHANGELOG version in the binary and the About dialog.

**Private build configuration:** `build.xml` loads `build/build.properties` (gitignored — holds
secrets, never committed; a missing file is tolerated). The committed `build/build.sample.properties`
is the template (`cp` it to `build/build.properties` and fill in). It carries the plumbing for the
not-yet-built update mechanism: GitHub Releases (`github.organization`, `github.repository`,
`github.token` — the PAT) and CDN upload over FTP (`cdn.ftp.hostname`, `cdn.ftp.username`,
`cdn.ftp.password`, `cdn.ftp.directory`). The plan: publish a release to GitHub Releases, then use the
`build/tasks/UpdateJson.php` Phing task (organization/repository/token/outfile attributes) to fetch the
latest release's metadata into an `update.json` and upload it to the CDN. (`build/.gitignore` ignores
everything under `build/` except a whitelist, so `build.properties` is ignored automatically; the
sample is explicitly whitelisted.)

**Packaged build via Phing:** the `package` target (also `composer build:package`) builds *and*
packages every platform into `build/dist/` — it depends on six per-platform `package-*` targets
(`package-macos-arm/-x86`, `package-win-x86`, `package-linux-x86/-arm`, `package-phar`), and each
`package-X` depends on its matching `git-X` (so it compiles the binary first) then shells out to the
relevant `scripts/make-*.sh` helper. This is the Phing equivalent of `scripts/build-all.sh`
(`composer build`); both produce the same artifacts through the same per-platform scripts, so use
whichever entry point you prefer (`build-all.sh` adds a tolerant warn-and-continue summary across all
platforms, the Phing targets let you build/package a single platform on demand).

**Run on this host:** the `run` target (also `composer start`) compiles the binary for the *current*
host and launches it. Since Phing `depends` is static, `run` resolves the host's OS+arch at runtime
(`<os family>` + `uname -m`) into `run.*` properties, dispatches the matching `git-*` compile with
`<phingcall>`, then executes the bare self-contained binary directly from its output dir (e.g.
`build/macos/aarch64/grafida`; `grafida.exe` on Windows) — *not* the `.app`/installer, which belong to
the `package-*` targets. macOS arm64→`git-macos-arm`, macOS x86_64→`git-macos-x86`,
Linux aarch64→`git-linux-arm`, Linux x86_64→`git-linux-x86`, Windows→`git-win-x86`; an unrecognised
host fails with a clear message.

**Tests:** the `tests` target (depends only on `prepare-composer`, not the full `prepare`) runs
`composer test` — the PHPUnit suites (unit + integration + feature) **and `test:js`**. `phpunit.xml`
sets `failOnEmptyTestSuite="false"` because `tests/Integration/` was originally scaffolding only;
without that flag PHPUnit fails the whole run on an empty suite before the feature suite executes.
- **`composer test:js`** (`node --test 'tests/js/**/*.test.mjs'`) covers the SPA modules PHPUnit
  **cannot** reach: `assets/private/js/ai/providers.js` (the AI transport — the provider call runs in
  the SPA, see the AI facts), `assets/private/js/editor/slashtools.js` (the slash-command menu),
  `assets/private/js/editor/csstheme.js` (the editor colour-scheme rewriter, gh-38), and
  `assets/private/js/editor/localmedia.js` (the `boson://app/api/media/{id}/raw?rev=…` URL
  builder/parser, gh-36 — its own synchronous SHA-1 is what the rev token is verified against —
  **plus**, since gh-43, `fitDimensions()`, the JS half of the image-resync sizing rule whose PHP
  twin is `Grafida\Media\ImageDimensions::fit()`, see `src/Media/` above).
  For all four it is the only automated coverage. It uses node's built-in test runner and loads the
  browser IIFE in a `vm` context with fakes for the globals app.js supplies (`window`/`fetch`/`api`,
  or `State`/`t`/`editor`); no bundler and no new dependency (node is already a build prerequisite).
  ⚠️ **The sandbox is its own realm**, which bites twice: providers.js detects a CORS failure with
  `err instanceof TypeError`, so a stub must mint that error **inside** the sandbox or the fallback
  never triggers; and a value *returned* from the sandbox (slashtools' `fetchItems()` array) fails a
  strict deep-equal against an outer-realm literal on the prototype alone, so re-home it first
  (`Array.from()`) or compare field by field.
- **`tests/Integration/Ai/ResponsesApiLiveTest.php`** talks to a **real** OpenAI *Responses API*
  server. It pins the wire-format assumptions providers.js is built on (the `output[]`→`output_text`
  shape, `instructions`, the typed SSE events with **no `[DONE]`**, and that a `previous_response_id`
  really does resume server-side and a stale one really is rejected) — if OpenAI changes the shape,
  the JS would break silently in the webview; this fails loudly instead. It is **skipped unless
  configured** via `GRAFIDA_TEST_RESPONSES_ENDPOINT` + `_MODEL` (+ `_KEY` for a hosted provider,
  `_PROVIDER` to override the providers.json key). A local LM Studio server works as the endpoint.
- **Test configuration lives in `tests/.env`** (gitignored — it holds provider credentials); copy
  `tests/.env.sample` to create it. `tests/bootstrap.php` (the PHPUnit `bootstrap`) loads it with
  **symfony/dotenv** (a dev dependency), and a variable exported in the real environment still wins,
  so `FOO=bar composer test` overrides the file. **`tests/README.md` documents all of this** — the
  suites, how to configure and run the live tests, and the two traps in them (a local server may
  *ignore* an unknown `previous_response_id` rather than rejecting it, and these tests go through PHP
  so they do not exercise the CORS/ATS constraints the SPA hits).

**Release:** `all` is an alias for `package`. `release` (depends on `all`) is the standard release
process: build+package every platform, then (1) create a **published GitHub release** with the
installers/DMGs/PHAR as assets and `RELEASENOTES.md` as the description, (2) build `grafida.json` from
that release, and (3) upload it to the CDN over **FTPS**. It needs the `github.*` + `cdn.ftp.*`
properties from `build/build.properties` (it fails early with a clear message if they're unset). Three
custom Phing tasks under `build/tasks/` (namespace `tasks\`, taskdef'd with `classpath="…/build"`)
back it, all curl-based (no extra binaries, matching `UpdateJson.php`): **`GitHubRelease`** (creates a
draft release, uploads each nested-`<fileset>` asset, then publishes — so a partial release is never
visible), **`UpdateJson`** (fetches the latest release's metadata into `grafida.json`), and
**`FtpsUpload`** (uploads over explicit FTPS — `CURLUSESSL_ALL`). The version comes from the CHANGELOG
via `set-version.php --print`. `UpdateJson` treats a release as downloadable when it has any asset
ending in `.zip`/`.exe`/`.dmg`/`.tar.gz` (`UpdateJson::ASSET_EXTENSIONS` / `isDownloadableAsset()`); its
`grafida.json` `download` field is provisionally the **first** such asset — a real per-platform download
map is for when the update mechanism itself is built.

## Key Joomla API facts (verified against Joomla 5.4 source)

- API base is reliably `{siteRoot}/index.php/api`; the rewrite form `{siteRoot}/api` needs
  server rules. `ApiClient` normalises any pasted URL to the bare root and **probes** to find
  the working base, persisting it per site.
- Auth header: `Authorization: Bearer <token>` (also sends `X-Joomla-Token`). User needs `core.login.api`.
- **A token-bearing user is not necessarily a Super User.** `plg_user_token`'s `allowedUserGroups`
  defaults to `"8"` (Super Users), but an administrator can allow a dedicated group to receive tokens
  and grant it `core.login.api`; see `docs/Custom API access.md`. The user's normal Joomla permissions
  still govern every API operation. Treat admin-only routes as optional: they may return 403 for a
  non-Super-User token, so callers must degrade rather than throw (see `listTemplateStyles()`).
- Articles: `POST/PATCH /v1/content/articles[/{id}]`. **Write bodies are a flat
  top-level JSON object of field values** — Joomla's JSON:API `{data:{type,attributes}}`
  envelope is for *responses only*; wrapping a write makes Joomla bind nothing and
  silently return the unchanged resource (a PATCH no-op). The record id for an update
  comes from the URL, not the body. Send the body as the discrete `introtext` /
  `fulltext` columns — **not** the combined `articletext` field. On a PATCH the API
  controller backfills every real DB column we omit from the *existing* record, and
  `Content::bind()` ends with `parent::bind()`, overwriting the introtext/fulltext it
  derives from `articletext` with the backfilled OLD values — so a PATCH that sends
  only `articletext` silently reverts the body (a create has no backfill, so it worked).
  Sending `introtext`/`fulltext` keeps them present in the data, never backfilled.
  Custom field values go under `com_fields`. Tags
  are an array of IDs. (`ApiClient::send()` posts the flat body; only responses are unwrapped.)
  ⚠️ **A write is filtered through the component's edit form, so only fields declared there survive.**
  `ApiController::save()` runs `$model->validate($form, $data)` and saves the *returned* `$validData`
  — an attribute with no matching field in `administrator/components/com_content/forms/article.xml`
  is dropped **silently** (no error, the API returns a resource that just ignored it). So before
  adding any article attribute, confirm it is in that form; likewise it is only readable back if it
  is listed in the API's `JsonapiView` (`$fieldsToRenderItem`/`List`). `created_by_alias` satisfies
  both.
- **The version note is settable over the API, by accident rather than design** (gh-17).
  `version_note` is not a `#__content` column and never touches the article table: it reaches the
  history because `ApiController::save()` does `$this->input->set('jform', $data)` — copying the
  whole posted body into the request input, a line whose *stated* purpose is com_fields' catid
  lookup — and `plg_behaviour_versionable`, firing later on `onTableAfterStore`, reads the note
  back out of `$input->get('jform')['version_note']`. So a plain `version_note` key in our flat
  write body lands in Joomla's version history. It survives the form filter because
  `article.xml` declares the field; `Table::bind()` iterates the table's own properties, so the
  extra key is ignored rather than treated as an unknown column. **A site with com_content's
  `save_history` off (Joomla's default) stores nothing** — the plugin checks the param and returns
  *before* reading the note, so it is a silent no-op, never an error. `Versioning::store()` also
  dedupes on the content hash: an unchanged re-publish adds no row, and a matching hash with a
  different note *updates* the existing row's note.
- Media upload: `POST /v1/media/files` with `{path, content:<base64>}`; the response `url` is public.
- Template styles: `GET /v1/templates/styles/site` (the `webservices/templates` plugin, **enabled out of
  the box** — `base.sql`'s `plg_webservices_templates` row has `enabled = 1`). Needs `core.manage` on
  com_templates, so it can return 403 for non-Super-User tokens; treat it as optional. The list view
  renders `id`, `template`, `title`, `home`, `client_id`, …; `template` is the template's **directory
  name** and `home` is `"1"` for the site default, a **language tag** for a multilingual site's
  per-language home, and `"0"` otherwise. `page[limit]=0` means "all" here (unlike the config route).
  This is the **only** way to learn a child template's name — see `src/Reference/`.
- Global Configuration: `GET /v1/config/application` (the `webservices/config` plugin) needs
  **`core.admin`** — a plain author's token gets a 403, so treat it as optional. Its view does not
  serve one resource with all the settings: it emits **one single-attribute resource per key**, all
  sharing the same id, and **paginates** them (default limit 20) — so a caller must send
  `page[offset]` *and* `page[limit]` (it reads both without individual defaults) and scan the items.
  `page[limit]=0`, which every other collection route here uses to mean "all", **divides by zero**
  server-side. The payload is `configuration.php` verbatim, `secret` and `password` included — read
  what you need, never cache the lot.

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

## Conventions

- Every PHP file starts with the GPLv3 copyright docblock. `declare(strict_types=1)`.
- `composer test` runs the suite; `composer linter:check` runs PHPStan (level max + strict rules).
- Add new UI strings to `language/en-GB/en-GB.ini` (canonical) and the `KEYS`
  list in `Grafida\I18n\UiStrings`, then translate. **See the translation flow below.**
- **Never `new` a service — register it in a provider** (`src/Application/Provider/`) and let the
  container inject it. There is no global database object and no singleton to reach for.
- **Adding an endpoint** = a handler method on the right `src/Http/Controller/` class + one line in
  that controller's `registerRoutes()`. Nothing else changes — no constructor chain to thread.
- **Data access goes through `Joomla\Database\DatabaseInterface`, query-builder-first**
  (`$db->createQuery()->select(…)->from($db->quoteName(…))->where(… . ' = :id')->bind(':id', $id, ParameterType::INTEGER)`).
  Drop to raw `setQuery('…')` + `bind()` only where the builder has no vocabulary: the `ON CONFLICT`
  upserts, `PRAGMA`s, and the `sqlite_master` introspection query. Three traps, all of which have
  already bitten this codebase:
  - **`bind()` takes its value BY REFERENCE.** Bind from a variable (or an array *element*), never
    from an expression or literal — and the variable must still hold the right value at
    `execute()` time, not just at `bind()` time.
  - **`$query->insert()->set()` emits MySQL-only syntax** (`INSERT INTO t SET a = …`), which SQLite
    rejects. Always use `insert()->columns([…])->values('…')`.
  - **`loadAssoc()`/`loadResult()` return `null`** on no rows, not PDO's `false`.
  Upserts use SQLite's `ON CONFLICT(key) DO UPDATE SET col = excluded.col` so no placeholder is ever
  bound twice (native prepares reject a re-used named parameter with "column index out of range").
- Tests build the app from the container: `tests/Support/TestContainer::create()` gives a fully-wired
  app on an in-memory, already-migrated database (it takes the `secret.store` tri-state and an
  optional dialog stub); `TestDatabase::memory()` gives a bare `DatabaseInterface` for repository
  unit tests. The **Feature suite is the API contract** — it drives `Kernel::handle()` over every
  route and asserts status + JSON shape. Do not edit an assertion to make a refactor pass.
- ⚠️ `composer phpcs:check` currently fails on **every** `src/` file, including at pristine `HEAD` —
  the installed php-cs-fixer disagrees with the committed formatting. It is **not** a usable gate;
  match the surrounding style by eye and rely on `linter:check` + `test`.
- Never build a localised sentence by concatenating fragments around an injected value — word
  order differs per language. Keep each message a single string with `%s` placeholders and
  interpolate in the SPA with `formatNodes(t('KEY'), node)` (returns text/DOM nodes to spread
  into `el()`), mirroring Joomla's `Text::sprintf()`.

## Translation flow (must be followed every time)

The canonical source is **en-GB**. Translations use the **Joomla INI** format. Before each
translation run, consult the per-language glossary in `build/glossaries/<tag>.md` (create it
if missing) and update it with any new terms — glossaries keep terminology consistent. A new
language needs only its `<tag>/<tag>.ini` file (e.g. `language/de-DE/de-DE.ini`) — there is no
`.sys.ini` and no manifest to register it in (`LanguageService` discovers languages by scanning
the directory at runtime). Each `<tag>.ini` MUST include a `GRAFIDA_LANGUAGE_ENDONYM`
key holding the language's name in its own tongue (e.g. `"Français (France)"`) — `LanguageService`
reads it to build the runtime language list, so the new language appears in the UI automatically.
When a generated file is large, write it in ~10–12 KiB chunks, each
ending on a whole line. The shipped languages are: en-GB (source), el-GR, fr-FR, de-DE,
es-ES, it-IT, pt-PT.
