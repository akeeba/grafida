# Grafida — AI assistant orientation

Grafida is a cross-platform **desktop application** (macOS, Windows, Linux) for creating
and editing **Joomla! articles** through the Joomla Web Services (REST) API. It is built in
**PHP 8.4** with [**Boson**](https://bosonphp.com), uses **SQLite** for all local storage,
and **TinyMCE 7** as the HTML editor. Licensed **GNU GPL v3 or later**. Dev happens on macOS.

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

Request flow: `index.php` → `Grafida\FrontController` → `Grafida\Application\Kernel`
(the composition root) → either `Grafida\Http\ApiController` (paths under `/api/`) or a
static asset / the SPA shell. **The kernel is a pure `Request → Response` function**, so the
whole back-end is testable without opening a window (see `tests/Feature/ApiRoutingTest.php`).

**File pickers must go through the native dialog, not `<input type="file">`.** Boson's
webview does not wire up the HTML file-input open-panel callback (WKWebView on macOS,
WebKitGTK on Linux), so an in-page `<input type="file">` `.click()` silently does nothing.
`index.php` therefore passes `$app->dialog` (Boson's `DialogApiInterface`) into the
`FrontController` → `Kernel` → `ApiController`, and the SPA opens files via `POST
/api/dialog/open-file` (`api.openFile(filter)`, filter `image`/`markdown`/`any`):
`ApiController::openFile()` calls `selectFile()`, reads the chosen file and returns
`{name, mime, dataBase64}` (or `{cancelled:true}`). `uploadLocalImage()` (intro/full-text
images, the in-editor/media-browser "Choose file…" button) and `importMarkdown()` consume
it. The dialog dependency is **nullable** so the kernel stays window-free in tests (a null
dialog makes the endpoint return 503).

## Layout

- `src/Http/` — `HttpClient` (curl/stream transport to Joomla), internal `ApiController`, `Json`.
- `src/Joomla/ApiClient.php` — Joomla REST client: base-URL normalisation + probing, JSON:API.
- `src/Secret/` — OS secret stores (macOS `security`, Linux `secret-tool`, Windows DPAPI) + factory.
- `src/Site/` — site entity, repository, `SiteService` (token storage + connection test).
  `FaviconService` (5s fetch) parses the site home page for `<link rel="icon">` / Apple
  touch icons, downloads the largest one (falling back to `/apple-touch-icon.png` then
  `/favicon.ico`), and caches the raw bytes in `site_favicons` (`FaviconRepository`).
  `sync()` is best-effort, run when a site is connected/updated (and on the manual metadata
  refresh); the cached icon is sent to the SPA as each site's `favicon` data: URI (in the
  `bootstrap`/sites payloads) and shown as a 64×64 rounded square on the Sites page and below
  the sidebar site dropdown.
- `src/Reference/` — cached categories/tags/levels/fields + `EditorCssService` (5s fetch, rebase, cache).
  `ReferenceService` uses a short-timeout (8s) API client; `sync()` warms the cache best-effort
  when a site is connected/updated, and opening the editor falls back to cache per-list (only the
  manual refresh button surfaces fetch errors).
- `src/Field/FieldSupport.php` — supported field-type subset + required-unsupported guard.
- `src/Article/` — `Draft` entity + repository (local drafts). A draft remembers the
  `site_id` + `remote_id` it mirrors; `findByRemote()` locates an existing draft for a
  remote article and `update()` can re-point a draft at another site (which unlinks it).
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
  is the subset of fields a draft actually carries (search over title+alias; sort by id/title/
  category/language/state; category/tag/language/state filters; per-page limit) — no
  featured/checked-out/hits/author/date controls. Because drafts store tag *titles* (not ids),
  the drafts tab's tag filter matches on title. A remote article that
  is already mirrored by a local draft (same site + `remote_id`) **stays** in the remote list
  (it is not hidden), tagged with an extra `GRAFIDA_LBL_HAS_LOCAL_DRAFT` "Local article" badge and
  a left accent; clicking it opens the existing draft rather than re-importing the article
  (`openEditorFor()` reuses the matching draft). The API only accepts a
  **single** category/tag and an INT `state`, so there is no multi-select or "all states"; an
  author filter is omitted (no local user list).
- `src/Media/` — offline image blobs (`media_blobs`). `ApiClient::listMedia()` browses the
  site's Media Manager (`GET /v1/media/files`); `ApiController` exposes it as
  `GET /api/sites/{id}/media?path=…` and serves an offline blob's data: URI back to the SPA
  via `GET /api/media/{id}` (to preview a not-yet-published intro/full-text image).
  **Media Manager screen** — a full online manager for the site's Media Manager (a sidebar
  item `data-screen="media"`; works only while the site is connected). The SPA
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
- `src/Html/` — `ContentSplitter` (read-more split), `CssRebaser`, `InlineMedia`, `HtmlDocument`.
- `src/Publish/PublishService.php` — the publish pipeline (media upload, tags, fields, split, POST/PATCH).
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
  item re-opens that same dialog; the dialog carries the Dimensions fields (`image_dimensions`) and an
  Advanced tab (`image_advtab`: inline style, border, spacing). Double-clicking the image or the
  toolbar/Insert-menu **Image** button work too. The same context toolbar's **CSS class…** item
  (`imageclass` button) opens a small prompt to set any free-text CSS class(es) on the image (the
  Insert/Edit Image dialog has no class field); it pre-fills the current `class` and writes it back in one
  undo step (empty clears it). The editor `content_style` also forces
  `img { max-width: 100%; height: auto }`: Joomla bakes a photo's full intrinsic size into the tag (e.g.
  `width="4032"`), and without a constraining rule the picture overflows the editor's scroll box and becomes
  un-clickable in the WKWebView (broken hit-testing) — scaling it to fit keeps it selectable/editable, and
  only the editor view is affected (the published `width`/`height` are untouched).
  TinyMCE's own **"Upload" tab is disabled** (`image_uploadtab: false`) because its "Browse for an image"
  dropzone creates a plain `<input type="file">` that Boson's webview never opens (see the native
  file-dialog note above) — so local uploads go exclusively through the Source-field "Choose file…",
  which calls the native picker.
  a local pick is inserted as `<img src="data:…" data-grafida-media-id="N">` (`GRAFIDA_MEDIA_ATTR`,
  mirroring `InlineMedia::ATTRIBUTE`) so `PublishService` uploads it on publish; a site-media pick is
  inserted as its public URL. On publish, `InlineMedia::rewriteDataImages()` rewrites **every** inline
  `data:` image — not just tagged ones: an image **pasted or dropped straight into the editor** (e.g. from
  a web page or another app) never passes through the in-editor upload handler, so it carries no
  `data-grafida-media-id`; `PublishService::uploadInlineImage()` decodes and stores such an untagged data:
  URI on the fly so it is uploaded too, instead of leaking a giant broken inline blob into the published
  HTML. A media upload that fails (or returns no usable result) aborts the publish with a clear error.
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
- `src/Markdown/`, `src/I18n/`, `src/Storage/` — Markdown import, language service, SQLite + migrations.
  `StorageService` reports the DB file path, opens its folder in the OS file browser
  (`open`/`explorer`/`xdg-open`), and resets local storage (deletes tokens + wipes all
  tables, keeping `schema_migrations`). Exposed under `/api/settings/storage[/open|/reset]`.
- `src/Ai/` — the **AI chat assistant** (chat with an LLM about the open article, the document
  supplied as context; modelled on the Joomla AITiny plugin, **text only — no AI images**).
  `AiServiceManager` is CRUD over configured **AI services** (`ai_services`), each a named
  provider connection (provider + endpoint + model + params); the API key lives in the OS keychain
  (reference `grafida.ai_service.{id}`, insecure-plaintext fallback like sites). **Multiple services
  are supported**; one may be flagged default, else the **lowest id** wins (`default()`). `Defaults`
  loads bundled `resources/{defaults.json,voices.json,providers.json}` (ported from AITiny: the base
  **system prompt**, the writing **tools** generate/proofread/friendly/professional/concise, the
  tone-of-voice library, and the **provider table** — OpenAI, Anthropic, Cohere, DeepSeek, Google,
  Groq, Mistral, OpenRouter, Perplexity, Scaleway, GitHub, Custom — each with endpoint/auth/chat-path/
  models-path/SSE-dialect). `effectiveTools()` overlays the code defaults with `ai_tools` DB overrides
  + custom tools; **each tool may target its own service** (`service_id`). `AiChatRepository` persists
  **saved chats** (`ai_chats` + `ai_chat_messages`) linked to a draft; deleting the draft cascades
  them away. Transport is deliberately **inverted vs. AITiny — see the AI transport facts below**.
  Endpoints: `/api/ai/services[...]` (+ `/default`, `/resolved`), `/api/ai/tools[...]`,
  `/api/ai/system-prompt`, `/api/ai/proxy`, `/api/ai/render` (sanitise a reply for display — see the
  AI facts), `/api/ai/chats[...]`, `/api/drafts/{id}/chats`.
- `src/Support/` — `Resources`/`Paths` (filesystem locations), `App` (app identity/legal
  metadata: name, `VERSION`, copyright, licence + FSF URL, the verbatim Joomla! trademark
  disclaimer — sent to the SPA in the `bootstrap` payload's `app` key), and `UrlOpener`
  (opens an external http(s) URL in the OS default browser; backs `POST /api/open-url`).
  The sidebar footer shows the version and opens an About dialog using this metadata.
- `assets/private/` — SPA (`view/index.html`, `css/`, `js/`, `js/tinymce/`).
  **The three front-end libraries — TinyMCE 7, CodeMirror 5, FontAwesome 7 Free — are
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
  UI icons use the **FontAwesome 7 Free** solid font (`css/fontawesome.min.css`
  + `css/solid.min.css` + `webfonts/fa-solid-900.woff2`) — never images/emoji. Action
  buttons carry a leading `<i class="fa-solid fa-…" aria-hidden="true">` before the label;
  in `app.js` use the `icon()` / `iconBtn()` helpers. Source-code editing uses vendored
  **CodeMirror 5** (`js/codemirror/`: `lib/` + `mode/{xml,javascript,css,htmlmixed}` +
  `addon/edit/{matchbrackets,closetag}` + the `material-darker` dark theme) instead of
  TinyMCE's stock `code` plugin: that plugin is dropped from the `plugins` list, and a
  custom `sourcecode` toolbar button + Tools-menu item (registered in the editor `setup`)
  opens `openSourceCodeEditor()` — a `showModal()` dialog hosting a CodeMirror `htmlmixed`
  editor (light `default` / dark `material-darker` theme, matched to `State.resolvedTheme`).
  Save writes the source back via `editor.setContent(…, {source_view: true})` in one undo step.
  The toolbar also carries a **"Styles" drop-down** (`styleselect`, a custom `addMenuButton`
  registered in `setup`) that applies a CSS class to the selection the way Joomla's editor does.
  Its class list is `editorStyleClasses()` — class names `parseEditorCssClasses()` discovers in the
  site's cached `editor.css` **plus** a small built-in `EDITOR_CLASS_DEFAULTS` fallback set (deduped,
  alphabetical). Application is automatic: a non-collapsed text selection is wrapped in a
  `<span class="…">` (an `inline` format), a mere cursor sets the class on the enclosing block (a
  `selector` format over common block/img/anchor tags — it never changes the tag). Each class is
  pre-registered as a `grafidaInline_N` / `grafidaBlock_N` format pair in the init `formats` option;
  menu items are toggles whose active state mirrors `editor.formatter.match()`.
  **Spell checking** uses the native webview checker (`browser_spellcheck: true`) — the bundled
  TinyMCE spellchecker plugin was removed in v6+ and the replacement is a premium cloud service we
  won't use in an offline editor. This sets `spellcheck="true"` on the editing body and defers to the
  OS/webview dictionary (WKWebView/`NSSpellChecker` on macOS, WebKitGTK on Linux, WebView2 on Windows);
  suggestions appear in the *native* context menu via **Ctrl/Cmd + right-click** (TinyMCE's own context
  menu intercepts a plain right-click). **The spell-check language is an OS setting Grafida cannot
  override** — there is no JS/HTML API to pin a dictionary and no native hook into Boson's webview. On
  macOS in particular, results depend on System Settings → Keyboard → Text Input → Spelling: set to a
  fixed language (e.g. "U.S. English"), text in any other language is flagged wholesale; set to
  "Automatic by Language", per-sentence detection works but **only among the languages enabled in that
  list** (so a German writer must enable German there). Windows/Linux likewise defer to their OS
  spell-check configuration. This is a documented limitation, not a bug.
  **The editor UI language follows the interface language.** `tinymce.init()` is given a
  `language` + `language_url` (`editorLanguage()` / the `TINYMCE_LANGS` map in `app.js`) pointing
  at the matching pack vendored under `js/tinymce/langs/` (`el`, `fr_FR`, `de`, `es`, `it`,
  `pt_PT` — sourced from the `tinymce-i18n` packs for TinyMCE 7). **en-GB has no pack** — TinyMCE's
  built-in UI is English — so it (and any unmapped tag) falls through to the English default with no
  `language` set. `language_url` is an absolute `/js/tinymce/langs/<code>.js` path because the init's
  `document_base_url` is the *site* URL, which would otherwise mis-resolve a relative path. Adding a
  shipped language needs **both** its pack name added to the `tinymce-i18n` langs filter in
  `composer.json`'s `extra.copy-static` (so `vendor:assets` copies it into `js/tinymce/langs/`) **and**
  a `TINYMCE_LANGS` entry in `app.js` (none for languages TinyMCE has no pack for — they get the
  English editor UI). This is the editor UI *chrome*; it is unrelated
  to the spell-check dictionary (an OS setting, above) and the article content language.
  The active site is remembered client-side in `localStorage` (`grafida.lastSiteId`, via
  `rememberLastSite()` / `recallLastSite()`); it is not persisted server-side. On startup
  `bootstrap()` opens the **Articles** page (instead of **Sites**) when at least one site is
  defined and a previously remembered last active site is still in the list — the remembered id
  is read *before* `renderSiteSelector()` writes its first-site fallback, so a freshly added but
  never-selected site does not trigger the Articles default.
  **Collapsible/resizable layout** (`initLayoutControls()` in `app.js`): the left **`#sidebar`**
  and the editor metadata **`#editor-sidebar`** ("Article properties") each carry an `.icon-toggle`
  button (`#sidebar-toggle` / `#editor-sidebar-toggle`) that toggles a `.collapsed` class — the left
  sidebar collapses to a 56px icon-only rail, the metadata sidebar to a 40px rail; the state persists
  in `localStorage` (`grafida.sidebarCollapsed` / `grafida.propsCollapsed`). The **`#ai-panel`** is
  width-**resizable** by dragging `#ai-panel-resizer`, a `col-resize` handle on its left edge
  (`setupAiPanelResize()`, pointer events, clamped 280px…`min(innerWidth−360, 760)`); the chosen
  width persists in `grafida.aiPanelWidth`. Toggle buttons localise their tooltip/`aria-label` via a
  `data-i18n-title` attribute (`applyStrings()` sets both `title` and `aria-label` from it).
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
  `build/macos/<bosondir>/Grafida.app` (the bare binary + dylib + `assets/`, Info.plist,
  ad-hoc dylib signature) — Boson names the arm64 dir `aarch64`, amd64 stays `amd64` — then
  `scripts/make-dmg.sh <arch>` wraps it (via `hdiutil`) into `Grafida-<v>-macos-<arch>.dmg`
  with an `/Applications` symlink.
- Linux (amd64+arm64): `scripts/make-linux-tarball.sh <arch>` builds a `.tar.gz` of the per-arch
  output dir (binary + `libboson-linux-*.so` + `assets/`) plus the icon, `grafida.desktop`, and
  `build/linux-install.sh` (renamed `install.sh`) — a per-user XDG desktop-integration installer.
- Windows (amd64): `scripts/make-windows-installer.sh` compiles `build/windows-installer.nsi` with
  **NSIS** `makensis`, which runs natively on macOS/Linux (no Wine/Docker/Windows) →
  `Grafida-<v>-windows-amd64-Setup.exe` (per-user install in `%LOCALAPPDATA%\Programs\Grafida`).
  Falls back to a portable `.zip` if `makensis` is absent.
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
- Media upload: `POST /v1/media/files` with `{path, content:<base64>}`; the response `url` is public.

## Key AI assistant facts

- **Transport is inverted vs. AITiny (JS-primary, not PHP-primary).** The `boson://` kernel cannot
  stream — `Http\Json::response()` buffers the whole body and the SPA awaits `res.json()` whole — so
  the **provider call runs in the SPA's JavaScript**, which streams the SSE response token-by-token.
  `assets/private/js/ai/providers.js` (`window.GrafidaAI`) ports AITiny's provider request builders +
  both SSE dialects (OpenAI `data:`/`[DONE]`; Anthropic `event:`/`content_block_delta`/`message_stop`).
  `sendChat()` fetches `GET /api/ai/services/{id}/resolved` (endpoint + dialect + model + params **and
  the API key**), then streams directly from the provider. **PHP stays the source of truth** for
  services, prompts/tools and saved chats — only the HTTP call moved to JS.
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
- **The API key is handed to local JS per call.** This is a deliberate desktop-only trade-off (JS and
  PHP are equally-trusted local code; the SPA loads no remote content) and the price of streaming —
  do not "fix" it by moving the call back to PHP (that kills streaming).
- **UI:** a docked right-hand `#ai-panel` in the editor (`assets/private/js/ai/panel.js`) hosts the
  streaming conversation; the **document (title + HTML) is embedded as context in the first message**
  and follow-ups resend the whole history. A TinyMCE **AI Assistant** toolbar button toggles the panel
  and an **AI tools** menu button runs any configured writing tool against the document; that menu also
  always ends with a separated **"Custom…"** item (`GrafidaAIPanel.openCustom()`) — present even when no
  tools are configured — that opens the panel with an empty chat for a free-form prompt, so the
  ask-anything path is discoverable from the tools menu, not only via the AI Assistant toggle.
  **Both AI toolbar entries (`aitools aiassistant`) are only added to the editor toolbar when at least
  one AI service is configured** (`hasAiService` in `initTinyMCE()` gates the `aiToolbarSegment`
  appended to the `toolbar` string from `State.aiServices.length`): with no provider connection there
  is nothing for them to talk to, so they would be a dead end. The buttons are still *registered* in
  `setup` (harmless) — they are merely omitted from the toolbar layout. The gate is read at editor
  init, so adding a first service then re-opening the editor makes them appear. The panel has
  a **header** (`#ai-panel-header`) with the title plus **New chat** (`#ai-btn-new`, offers to remember
  the current chat then resets) and **Close** (`#ai-btn-close`, runs the close/remember flow and hides)
  buttons — the TinyMCE toolbar toggle is no longer the only way to close it. Each reply
  offers Insert-into-editor / Copy. Closing a non-empty chat offers to **remember** it: an unsaved
  draft is auto-saved first, a blank title is auto-generated via a short non-streaming completion, and
  the transcript is saved. Saved chats appear in the panel's **AI Chats** banner (open/continue/rename/
  delete). Assistant replies are the model's HTML (or Markdown, for the Generate tool); the panel
  renders them as **formatted** text — **and so are user/tool prompt bubbles** (`_buildUserBubble()`),
  since tool prompts and many typed prompts are Markdown. Because the output is untrusted, rendering is
  **sanitised server-side**: `panel.js`'s `_renderRichText()` shows the raw text as plain text first
  (always-safe placeholder) then calls `POST /api/ai/render`, which `Ai\AiRenderer` turns into safe
  HTML — auto-detecting Markdown vs HTML, converting Markdown via the existing CommonMark
  `MarkdownService`, and sanitising the result with **Symfony's `HtmlSanitizer`** (the W3C safe-element
  subset + relative links/medias). Only that returned HTML is set as `innerHTML`; if the call fails the
  plain-text placeholder stays. **Streaming replies format live** (chatbot-style), not only at the end:
  `_createStreamRenderer()` re-renders the accumulating reply through the same `/api/ai/render` pipeline,
  throttled to ~200 ms, with sequenced results so a slow/stale render can never roll the view backwards;
  `finish()` does the authoritative final render. Reflow jumps as blocks resolve are expected/acceptable.
  Insert/Copy still use the **raw** model output, not the rendered HTML; only Insert routes it into TinyMCE. The same `provider`/`tool` config is managed from two
  **Settings** cards (AI Services, AI Tools).

## Conventions

- Every PHP file starts with the GPLv3 copyright docblock. `declare(strict_types=1)`.
- `composer test` runs the suite; `composer linter:check` runs PHPStan (level max + strict rules).
- Add new UI strings to `language/en-GB/en-GB.ini` (canonical) and the `UI_KEYS`
  list in `ApiController`, then translate. **See the translation flow below.**
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
