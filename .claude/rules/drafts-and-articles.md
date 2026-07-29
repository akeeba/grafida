---
description: Grafida local drafts — the Draft entity/repository, the alias (URL slug) preview mirroring Joomla's two algorithms, the two-tab Articles screen, and the portable .grafida export format. Lifted verbatim from CLAUDE.md's Layout section.
paths:
  - "src/Article/**"
  - "src/Http/Controller/DraftController.php"
  - "src/Http/Controller/ArticleController.php"
---

# Local drafts & the Articles screen

The `src/Article/` notes from `CLAUDE.md`'s `## Layout` section. Verbatim — the leading `- `
and two-space continuation indent are the original bullet formatting.

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
  shows no id at all, which is exactly why the drafts tab offers no id sort. Below the alias sits
  the **created/modified line** (`articleDatesLine()`, gh-53), which needed no API work at all:
  `created` and `modified` are both in com_content's `$fieldsToRenderList`, so the *list* endpoint
  emits them per item and `ApiClient::flatten()` hands every attribute through untouched — a
  contract `ArticleRoutingTest` now pins, since an attribute whitelist added to `flatten()` would
  drop the dates from both tabs silently. On a **local** row the dates are the draft's own
  `createdAt`/`updatedAt`, describing the local copy rather than the article on the site — which is
  what that tab lists. ⚠️ Rendering them needs a real `Date`, so they go through
  **`js/util/datetime.js`** (`formatStamp()` in `app.js` is the guarded wrapper) rather than
  `Date.parse()`, which WKWebView mishandles for the naive UTC form — the same trap the date
  *sorts* dodge by comparing the strings directly. The API only accepts a
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
