---
description: Grafida's cached site reference data (categories/tags/levels/fields/languages, editor.css and template discovery) plus the verified Joomla 5.4 REST API contracts. Lifted verbatim from CLAUDE.md.
paths:
  - "src/Reference/**"
  - "src/Joomla/**"
  - "src/Publish/**"
  - "src/Field/**"
  - "src/Site/**"
---

# Site reference cache & Joomla REST API contracts

Two blocks from `CLAUDE.md`, kept together because they are the same subject from two sides:
what we cache about a site, and the API contracts that govern reading it. Verbatim.

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
- Article custom fields: `GET /v1/fields/content/articles` (gh-56). ⚠️ **The list endpoint cannot tell you
  which categories a field is used in, and the route accepts no filter that would.** `com_fields`'
  `JsonapiView` puts `assigned_cat_ids` in `$fieldsToRenderItem` but **not** in
  `$fieldsToRenderList`; and `ApiController::displayList()` builds every list model with
  `['ignore_request' => true]`, which sets `__state_set` and so stops `populateState()` from ever
  running — the `filter[…]` array `ListModel::populateState()` would have read is therefore never
  looked at, and `FieldsController` forwards nothing but `filter.context` of its own accord. So
  `filter[assigned_cat_ids]` silently does nothing, and the **item** endpoint
  (`GET /v1/fields/content/articles/{id}`, `ApiClient::getArticleField()`), one request per field,
  is the only way. `ReferenceService::fetchFields()` pays that cost when the `fields` cache is
  refreshed, never on an editor open, and degrades a failed item request to `[0]` — "used in every
  category", the behaviour Grafida had before it read the assignment at all.
  The **rule** it feeds, reimplemented in `Field\FieldCategoryScope` from `FieldsHelper::getFields()`
  + `FieldsModel::getListQuery()`: no rows in `#__fields_categories` (reported as `[0]`) → every
  category; assigned categories → those **and all their descendants** (Joomla walks *up* from the
  article's category, matching a field pinned to any ancestor); `[-1]` → "Only Use In Subform",
  never on an article form; an article with **no** category is filtered by nothing and sees
  everything. `PublishService` scopes `$fieldDefs` through it before the required-unsupported guard
  and before `mapFields()`, so a required field belonging to another category can no longer make an
  article unpublishable — which is the bug this exists for. Note the category tree is read
  **best-effort** there: it only ever *widens* the scope, so an unreachable site with a cold
  category cache must not fail the publish over it.
