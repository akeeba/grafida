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
  `base.path`, `docs.dir` (default `Resources::docsDir()` — note it does **not** go through
  `Resources::base()`, since the docs are read straight out of the phar rather than extracted),
  `static.provider`, `dialog` (nullable), and `secret.store` — which is
  **tri-state**: `null` → `SecretStoreFactory::secureStore()` (production), `false` → no store
  (forces the insecure-plaintext fallback path), a `SecretStore` instance → used as-is.
  The `DatabaseInterface` factory **connects *and* migrates**, so every consumer receives a
  migrated database. `Kernel` is `(StaticProviderInterface, ApiController)`.
- `src/Http/` — `HttpClient` (curl/stream transport to Joomla), `Json`, and the internal API.
  `ApiController` is only a **dispatcher**; a real `Router` route table resolves each handler's
  controller from the container on match, so a request builds one controller, not nine. The
  handlers live in `src/Http/Controller/`, each taking only the collaborators it uses; the shared
  site/article helpers live in the injected `Grafida\Http\SiteContext`, **not** a god base class.
  Controllers must never call each other — share through the injected services.
  (The hand-rolled `HttpClient` exists because `joomla/http` was, at the time, uninstallable on
  the PHP version in use — its laminas-diactoros dependency capped out below it.)
  ⚠️ **Detail is in `.claude/rules/internal-api.md`**, which loads when you touch `src/Http/`,
  `src/Application/` or `src/Debug/`. Two rules worth carrying without it: **a transport failure
  is not one error but two** (a connectivity failure must not be reported the same way as a TLS
  handshake failure — telling someone to check their internet over a bad certificate is actively
  misleading), and **nothing the internal API answers may be cached by the webview** (gh-35).
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
  `reference_cache` is permanent server-side and stays **authoritative for rendering** — a screen
  always paints from the cache first — with three invalidation paths: the manual Refresh buttons, a
  configurable TTL driving a fire-and-forget background refresh, and an opt-in startup cache reset
  (**default off**, because an unconditional refetch at launch reads as a hang on a bad connection).
  ⚠️ **Detail is in `.claude/rules/joomla-api-and-references.md`**, which loads when you touch
  `src/Reference/`, `src/Joomla/`, `src/Publish/`, `src/Field/` or `src/Site/`. The rule most
  easily broken from outside: `TemplateDiscovery` needs the **template styles API**, not just a
  home-page scan — a child template that ships only an `editor.css` renders no asset URL of its
  own and is structurally invisible to any page scan (gh-3).
- `src/Field/FieldSupport.php` — supported field-type subset + required-unsupported guard.
- `src/Article/` — `Draft` entity + repository (local drafts), the alias (URL slug) preview, the
  two-tab Articles screen (Local / Remote) and the portable **`.grafida`** export format. A draft
  remembers the `site_id` + `remote_id` it mirrors; drafts are only written to the DB on the first
  Save, so opening an unchanged remote article leaves no local draft behind.
  ⚠️ **Detail is in `.claude/rules/drafts-and-articles.md`**, which loads when you touch
  `src/Article/` or the draft/article controllers. Two traps worth carrying without it: **any new
  article attribute must first be checked against com_content's `article.xml` form** — a field
  absent from it is dropped *silently* on save; and the draft timestamps are naive UTC strings
  compared **as strings**, never via `Date.parse()`, which WKWebView mishandles for that form.
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
- `src/Media/` — offline image blobs (`media_blobs`) + `SiteImageFetcher`, the Media Manager
  screen (Site + Local tabs) and the in-app crop/resize/rotate/flip image editor.
- `src/Publish/PublishService.php` — the publish pipeline (media upload, tags, fields, split, POST/PATCH).
  ⚠️ **Both are detailed in `.claude/rules/media-and-publish.md`**, which loads when you touch
  `src/Media/`, `src/Publish/` or `src/Html/`. They are one pipeline and are documented together.
  Three rules worth carrying without it: a pasted image is referenced by a **local URL the kernel
  serves** (`boson://app/api/media/{id}/raw?rev=…`), **not** an inlined `data:` URI (gh-36) — the
  latter is what froze the source editor on a couple of screenshots; `rewriteOfflineImages()`
  uploads **every** offline image on publish, tagged or not, so none leaks into the published HTML;
  and the upload path is relative to the media adapter root (`grafida/<file>`, **not**
  `images/grafida/<file>`, which writes to `images/images/…` and yields a broken image).
- `src/Html/` — `ContentSplitter` (read-more split), `CssRebaser`, `InlineMedia`, `HtmlDocument`.
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
- `src/Editor/AutoCloseTagsService.php` — persists how the **source-code editor** closes HTML tags
  (`settings` key `auto_close_tags`, sent as `bootstrap`'s `autoCloseTags`, written via
  `POST /api/settings/auto-close-tags`). Same shape as `SlashToolsService` (generic key/value store,
  **no migration**), but the value is a **three-way choice**, not a boolean — `full` (default) /
  `closing` / `off` — because CodeMirror's `closetag` addon has two independent halves and they are
  genuinely useful separately (gh-52): `whenOpening` inserts `</p>` when you finish typing `<p>`,
  `whenClosing` completes a closing tag once you have typed its `</`. An on/off toggle would have
  had to make "off" mean "off, except the `</` completion", which no label can carry honestly.
  The SPA maps the three onto `true` / `{whenOpening: false}` / `false` in `autoCloseTagsOption()`;
  `AUTO_CLOSE_TAGS_CHOICES` in `app.js` must stay in step with `AutoCloseTagsService::AVAILABLE`,
  which snaps an unknown value back to `full`.
- `src/Help/HelpService.php` — the **in-app documentation** (gh-55). `docs/` is a **single source
  with two consumers**: the sidebar's **Help** screen and the project's **GitHub wiki**, which
  `scripts/sync-wiki.sh` (`phing wiki` / `composer docs:wiki`, and step 4 of `phing release`)
  publishes to. The wiki is the dumber consumer, so **it dictates the source format** — one flat
  directory of `.md` files whose names are the wiki page names, **no YAML front matter** (a wiki
  renders it as visible junk), and inter-page links written as bare relative page names. The table
  of contents is `docs/_manifest.json`, a **tree** (`{slug?, title, children?}`, max depth 4; a
  node with no `slug` is a heading). It is not a convenience: `glob()` does not work on `phar://`,
  so a manifest-driven index is what lets `Resources::docsDir()` read the docs straight out of the
  compiled binary with no extraction step. Endpoints `GET /api/help`, `/api/help/page/{key}`,
  `/api/help/image/{file}`; none of them touches a site, the network or the database, so the Help
  screen works with nothing configured at all.
  ⚠️ **Detail is in `.claude/rules/documentation.md`**, which loads when you touch `docs/`,
  `src/Help/` or `scripts/sync-wiki.sh`. Two rules worth carrying without it: **no link in a
  documentation page may be followed normally** — Boson's webview opens no new window and a
  same-window navigation would replace the SPA with no way back, so an external URL leaves through
  `api.openUrl()` and anything unclassified has its click swallowed (`mailto:` is deliberately
  *not* tagged external, because `UrlOpener` accepts http(s) only and would answer with an error
  toast); and **the manifest nests but the files do not** — a wiki has a flat page namespace, so
  the hierarchy lives in the manifest and never as subdirectories.
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
  (`open`/`explorer`/`xdg-open`), and resets local storage (deletes secrets + wipes all
  tables, keeping `schema_migrations`). Exposed under `/api/settings/storage[/open|/reset]`.
  ⚠️ `PRAGMA foreign_keys` is a **no-op inside a transaction**, so `reset()` must never be wrapped
  in one.
  ⚠️ **Every kind of OS-stored secret needs its own delete loop in `reset()`, run *before* the bulk
  wipe.** The secret store is not a table, so `DELETE FROM` reaches none of it, and once the row
  carrying the `secret_ref` is gone nothing can say which keychain entry was ours — it becomes an
  unfindable orphan. `reset()` therefore loops `SiteService::delete()` **and**
  `AiServiceManager::delete()` (the latter was missing until it was reported through the
  documentation). A future secret gets a third loop; `StorageServiceTest` pins this.
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
  ⚠️ **The front-end notes live in `.claude/rules/spa-frontend.md`**, which loads automatically
  whenever you work under `assets/private/`. Everything about the TinyMCE 8 wiring (including the
  mandatory `license_key: 'gpl'`, without which the editor silently starts **read-only**), the
  npm-vendored and **gitignored** front-end libraries, CodeMirror source editing, the
  slash-command menu, spell checking, editor colour-scheme resolution, the Styles drop-down, the
  media browser and the collapsible layout is in there. Read it before changing anything in the
  SPA. It is deliberately **not** at `assets/private/CLAUDE.md`, because `boson.json` bundles
  `assets/private` wholesale into every shipped binary.
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
- `docs/` — the user documentation, in Markdown. Shipped inside every binary (it is in
  `boson.json`'s `build.directories`) **and** published as the GitHub wiki; see `src/Help/` above.
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

`composer build` → `scripts/build-all.sh` is the one-shot compile-and-package pipeline (every
target in `boson.json`, then per-platform packaging into `build/dist/`). `phing` /
`composer build:git` compiles the binaries only; `phing release` does the full GitHub + CDN
release. The **CHANGELOG is the single source of truth for the version** — `build/tasks/set-version.php`
stamps its topmost heading into `App::VERSION` before every compile. `node`+`npm` are build
prerequisites (the front-end libraries are vendored by `composer run-script vendor:assets`).

⚠️ **The full build, code-signing and release detail lives in `.claude/rules/build-and-packaging.md`**,
which loads automatically when you touch `build/`, `scripts/`, `build.xml`, `boson.json`,
`composer.json`, the CHANGELOG or the release notes. The one rule to carry around without it:
signing on **both** macOS and Windows depends on the patched `sibling-phar` SFX and on
**splitting** the compiled binary into a clean stub + a sibling PHAR — signing a *combined*
binary appends the certificate past the PHAR, corrupts its signature, and the app dies at startup.

## Key Joomla API facts (verified against Joomla 5.4 source)

⚠️ **These live in `.claude/rules/joomla-api-and-references.md`**, alongside the reference-cache
notes they govern; it loads when you touch `src/Joomla/`, `src/Reference/`, `src/Publish/`,
`src/Field/` or `src/Site/`.

The two that cause silent, hard-to-diagnose damage, so they stay here: **write bodies are a flat
top-level JSON object** — Joomla's `{data:{type,attributes}}` JSON:API envelope is for *responses
only*, and wrapping a write makes Joomla bind nothing and return the unchanged resource. And send
the body as the discrete **`introtext` / `fulltext`** columns, **never** the combined `articletext`
field: on a PATCH the controller backfills every column you omit from the existing record, then
`Content::bind()` overwrites what it derived from `articletext` with those backfilled OLD values —
so a PATCH sending only `articletext` **silently reverts the body**. A create has no backfill,
which is why it appears to work.

Also remember a token-bearing user is **not necessarily a Super User**: admin-only routes may 403,
so callers must degrade rather than throw.

## Key AI assistant facts

⚠️ **These live in `.claude/rules/ai-assistant.md`**, which loads automatically when you work
under `src/Ai/`, `assets/private/js/ai/` or the AI controllers.

Two things to carry around without it. **Transport is inverted vs. AITiny (JS-primary, not
PHP-primary):** the `boson://` kernel cannot stream, so the provider call runs in the SPA's
JavaScript, which streams the SSE response token-by-token; PHP stays the source of truth for
services, prompts/tools and saved chats. **Do not "fix" the API key being handed to local JS by
moving the call back to PHP — that kills streaming**; it is a deliberate desktop-only trade-off.
And the assistant is **text only — no AI images**.

The rules file covers the three SSE dialects and where they branch, Responses-API conversation
chaining, the CORS/ATS traps behind "the interface freezes" with a local model, the multimodal
opt-in and image-collection paths, and the panel UI including the server-side sanitising renderer.

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
  into `el()`), mirroring Joomla's `Text::sprintf()`. `formatText()` is the plain-string
  counterpart (toasts); **`formatNodesStrong()` emphasises the template's own literal text**
  instead of the substitution, which is how a `<strong>Created:</strong> 20 Jul 2026` pair is
  rendered from one string rather than from a label key plus a value key — the label, its
  punctuation and its position relative to the value all stay the translator's to decide. All
  three share `interpolateNodes()`.
- **Commit directly on `main`. Do NOT create or switch to feature branches unless explicitly
  asked.** This project is worked solo and the branch overhead is unwanted; this deliberately
  overrides the general "branch first if on the default branch" default. If a branch was created
  by default tooling behaviour, fast-forward merge it into `main` and check `main` back out.
- **Every change adds its CHANGELOG entry**, terse and placed in its significance group
  (`!` `+` `-` `~` `# [HIGH|MEDIUM|LOW]`) rather than appended — the format is documented under
  "CHANGELOG style" in `.claude/rules/build-and-packaging.md`. The CHANGELOG is also the single
  source of truth for the version number.
- **Keep the checked-in documentation current in the same change.** That is *two* things: this
  root `CLAUDE.md` and the path-scoped files under `.claude/rules/` (see the map below). The rules
  files are the easier ones to forget precisely because they are not always in context — but a
  change to the SPA, the AI assistant, media/publishing, drafts, the reference cache, the internal
  API or the build system is almost always made in a session where the matching rules file *was*
  loaded, so update it there and then. If nothing documented changed, leave it alone.

### Where the rest of the documentation lives

`CLAUDE.md` is always in context; each `.claude/rules/*.md` file loads automatically only when you
touch the paths it declares in its `paths:` frontmatter. Each root pointer above keeps its area's
safety-critical prohibition resident, so those never depend on a rules file being loaded.

| File | Loads when you touch |
|---|---|
| `.claude/rules/spa-frontend.md` | `assets/private/**` |
| `.claude/rules/ai-assistant.md` | `src/Ai/**`, `assets/private/js/ai/**`, the AI controllers |
| `.claude/rules/media-and-publish.md` | `src/Media/**`, `src/Publish/**`, `src/Html/**` |
| `.claude/rules/drafts-and-articles.md` | `src/Article/**`, the draft/article controllers |
| `.claude/rules/joomla-api-and-references.md` | `src/Reference/**`, `src/Joomla/**`, `src/Publish/**`, `src/Field/**`, `src/Site/**` |
| `.claude/rules/internal-api.md` | `src/Http/**`, `src/Application/**`, `src/Debug/**` |
| `.claude/rules/documentation.md` | `docs/**`, `src/Help/**`, `scripts/sync-wiki.sh` |
| `.claude/rules/build-and-packaging.md` | `build/**`, `scripts/**`, `build.xml`, `boson.json`, `composer.json`, CHANGELOG, RELEASENOTES.md |

⚠️ A new rules file's `paths:` must actually cover the code it describes, or it will silently
never load. Deep build/signing recipes live in `build/readme/01`–`04`.

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
