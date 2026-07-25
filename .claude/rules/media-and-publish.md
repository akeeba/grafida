---
description: Grafida's offline media blobs and the publish pipeline — inline image references, the gh-36/gh-43 image rules, upload-on-publish rewriting, and the Media Manager screen. Lifted verbatim from CLAUDE.md's Layout section.
paths:
  - "src/Media/**"
  - "src/Publish/**"
  - "src/Html/**"
---

# Media & publishing

The `src/Media/` and `src/Publish/PublishService.php` notes from `CLAUDE.md`'s `## Layout`
section, kept together because they are one pipeline: a picture pasted into an article is a
local blob until a publish uploads it and rewrites the HTML. Verbatim — the leading `- ` and
two-space continuation indent are the original bullet formatting.

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
  file-dialog note in `CLAUDE.md`) — so local uploads go exclusively through the Source-field "Choose file…",
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
