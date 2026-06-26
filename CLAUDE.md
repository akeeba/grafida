# Grafida — AI assistant orientation

Grafida is a cross-platform **desktop application** (macOS, Windows, Linux) for creating
and editing **Joomla! articles** through the Joomla Web Services (REST) API. It is built in
**PHP 8.4** with [**Boson**](https://bosonphp.com), uses **SQLite** for all local storage,
and **TinyMCE 7** as the HTML editor. Licensed **GNU GPL v3 or later**. Dev happens on macOS.

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

## Layout

- `src/Http/` — `HttpClient` (curl/stream transport to Joomla), internal `ApiController`, `Json`.
- `src/Joomla/ApiClient.php` — Joomla REST client: base-URL normalisation + probing, JSON:API.
- `src/Secret/` — OS secret stores (macOS `security`, Linux `secret-tool`, Windows DPAPI) + factory.
- `src/Site/` — site entity, repository, `SiteService` (token storage + connection test).
- `src/Reference/` — cached categories/tags/levels/fields + `EditorCssService` (5s fetch, rebase, cache).
  `ReferenceService` uses a short-timeout (8s) API client; `sync()` warms the cache best-effort
  when a site is connected/updated, and opening the editor falls back to cache per-list (only the
  manual refresh button surfaces fetch errors).
- `src/Field/FieldSupport.php` — supported field-type subset + required-unsupported guard.
- `src/Article/` — `Draft` entity + repository (local drafts). A draft remembers the
  `site_id` + `remote_id` it mirrors; `findByRemote()` locates an existing draft for a
  remote article and `update()` can re-point a draft at another site (which unlinks it).
  Editing a remote article fetches its full content via `GET /api/sites/{id}/articles/{articleId}`
  (intro+full text rejoined around the read-more marker, tag IDs resolved to titles) and
  opens it as an **unsaved** draft — drafts (new or imported) are only written to the DB on
  the first Save, so an unchanged remote article leaves no local draft.
- `src/Media/` — offline image blobs (`media_blobs`).
- `src/Html/` — `ContentSplitter` (read-more split), `CssRebaser`, `InlineMedia`, `HtmlDocument`.
- `src/Publish/PublishService.php` — the publish pipeline (media upload, tags, fields, split, POST/PATCH).
- `src/Markdown/`, `src/I18n/`, `src/Storage/` — Markdown import, language service, SQLite + migrations.
  `StorageService` reports the DB file path, opens its folder in the OS file browser
  (`open`/`explorer`/`xdg-open`), and resets local storage (deletes tokens + wipes all
  tables, keeping `schema_migrations`). Exposed under `/api/settings/storage[/open|/reset]`.
- `src/Support/` — `Resources`/`Paths` (filesystem locations), `App` (app identity/legal
  metadata: name, `VERSION`, copyright, licence + FSF URL, the verbatim Joomla! trademark
  disclaimer — sent to the SPA in the `bootstrap` payload's `app` key), and `UrlOpener`
  (opens an external http(s) URL in the OS default browser; backs `POST /api/open-url`).
  The sidebar footer shows the version and opens an About dialog using this metadata.
- `assets/private/` — SPA (`view/index.html`, `css/`, `js/`, vendored `js/tinymce/`).
  UI icons use the vendored **FontAwesome 7 Free** solid font (`css/fontawesome.min.css`
  + `css/solid.min.css` + `webfonts/fa-solid-900.woff2`) — never images/emoji. Action
  buttons carry a leading `<i class="fa-solid fa-…" aria-hidden="true">` before the label;
  in `app.js` use the `icon()` / `iconBtn()` helpers.
- `language/<tag>/<tag>.com_grafida.ini` + `language/grafida.xml` — translations + manifest.
- `storage/migrations/*.sql` — schema. `.plans/` — implementation step notes (gitignored).
- `build/glossaries/` — per-language translation glossaries.

## Key Joomla API facts (verified against Joomla 5.4 source)

- API base is reliably `{siteRoot}/index.php/api`; the rewrite form `{siteRoot}/api` needs
  server rules. `ApiClient` normalises any pasted URL to the bare root and **probes** to find
  the working base, persisting it per site.
- Auth header: `Authorization: Bearer <token>` (also sends `X-Joomla-Token`). User needs `core.login.api`.
- Articles: `POST/PATCH /v1/content/articles`, JSON:API `{data:{type:"articles",attributes:{...}}}`.
  Send `articletext` with a `<hr id="system-readmore" />` marker to split introtext/fulltext.
  Custom field values go under `com_fields`. Tags are an array of IDs.
- Media upload: `POST /v1/media/files` with `{path, content:<base64>}`; the response `url` is public.

## Conventions

- Every PHP file starts with the GPLv3 copyright docblock. `declare(strict_types=1)`.
- `composer test` runs the suite; `composer linter:check` runs PHPStan (level max + strict rules).
- Add new UI strings to `language/en-GB/en-GB.com_grafida.ini` (canonical) and the `UI_KEYS`
  list in `ApiController`, then translate. **See the translation flow below.**
- Never build a localised sentence by concatenating fragments around an injected value — word
  order differs per language. Keep each message a single string with `%s` placeholders and
  interpolate in the SPA with `formatNodes(t('KEY'), node)` (returns text/DOM nodes to spread
  into `el()`), mirroring Joomla's `Text::sprintf()`.

## Translation flow (must be followed every time)

The canonical source is **en-GB**. Translations use the **Joomla INI** format. Before each
translation run, consult the per-language glossary in `build/glossaries/<tag>.md` (create it
if missing) and update it with any new terms — glossaries keep terminology consistent. After
creating each `<tag>.com_grafida.ini` (and `.sys.ini`), register the language in
`language/grafida.xml`. When a generated file is large, write it in ~10–12 KiB chunks, each
ending on a whole line. The shipped languages are: en-GB (source), el-GR, fr-FR, de-DE,
es-ES, it-IT, pt-PT.
