---
description: Grafida's documentation — the docs/ Markdown that is BOTH the in-app Help screen and the GitHub wiki. Source-format constraints, the manifest, the in-app renderer, and the wiki sync.
paths:
  - "docs/**"
  - "src/Help/**"
  - "scripts/sync-wiki.sh"
---

# The documentation (`docs/`)

(gh-55.)

`docs/` is a **single source with two consumers**: the in-app **Help** screen (`src/Help/`,
`src/Http/Controller/HelpController.php`, the SPA's `help` screen) and the project's **GitHub
wiki** at <https://github.com/akeeba/grafida/wiki>, which `scripts/sync-wiki.sh` publishes to.

The wiki is the dumber consumer, so **it dictates the source format and the app adapts** — not the
other way round. That is the single decision everything below follows from.

## Source-format rules (breaking one breaks the wiki silently)

- **One flat directory, one file per page.** The file name *is* the wiki page name:
  `Custom-API-Access.md` → the wiki's "Custom API Access" page. Slugs are `[A-Za-z0-9_-]+` — that
  same character class is the route placeholder (`{key}`) *and* the file name, so a space or a
  slash in a slug is not a style question, it is a 404.
- ⚠️ **No YAML front matter.** A GitHub wiki does not strip it; it renders as visible junk at the
  top of the page. Page metadata lives in `docs/_manifest.json` instead.
- ⚠️ **Every page must be listed in `docs/_manifest.json`, and nothing is discovered by scanning.**
  This is not a preference: `glob()` does not work on a `phar://` path, and the docs are read
  straight out of the compiled binary. A manifest-driven index is what lets
  `Support\Resources::docsDir()` skip the extraction step that `language/` and
  `storage/migrations/` need. `tests/Feature/HelpRoutingTest::testEveryAdvertisedPageRenders()`
  renders every advertised page, so a manifest entry whose file was renamed fails the suite rather
  than becoming a dead link.
- ⚠️ **The manifest nests; the files do not.** A manifest node is
  `{slug?, title, children?}`, to a depth of `HelpService::MAX_DEPTH` (4). A node with no `slug` is
  a **heading** — a section with no page of its own, so a section never has to invent a landing
  page just to exist. But the `.md` files stay in **one flat directory** however deep the tree
  goes, because a GitHub wiki has a flat page namespace and cannot represent a folder. The
  hierarchy lives in the manifest and nowhere else; do not mirror it as subdirectories.
  Two parser rules worth knowing: a node with an *unusable* slug is dropped **whole, children
  included** (it cannot be linked to, so its subtree would be orphaned under an unreachable
  section), and a heading whose children all fell away is dropped too. Because a node is discarded
  *after* its children are parsed, the flat slug index is built by walking the **finished** tree
  (`indexOf()`), never accumulated on the way down — anything recorded during the descent could
  belong to a subtree that no longer exists.
- **Links between pages are written the way the wiki resolves them**: a bare relative page name,
  `[Custom API access](Custom-API-Access)`. A `.md` suffix and a `#fragment` are stripped from the
  slug.
- **Images live in `docs/images/`, flat, referenced as `images/foo.png`.** The renderer rewrites
  that to `/api/help/image/foo.png` by **basename**, so a subdirectory would resolve in the wiki
  and 404 in the app.
  ⚠️ **They ship inside every binary** (`docs/` is in `boson.json`'s `build.directories`), so a
  screenshot's file size is multiplied by every platform we build. Full-resolution 2× PNG captures
  run 0.5–0.7 MB each; run every new one through
  `magick <in> -strip -colors 256 PNG8:<out>` before committing, which is ~3× smaller and visually
  indistinguishable for UI chrome. Screen shots are the whole window (macOS chrome included, matching
  the hand-taken ones); a dialog is cropped to the dialog, as `site.png` is.
  A shot with more to it than fits — a long Diagnose Connection panel — is cropped with a white
  fade at the cut rather than ending abruptly.
  ⚠️ **A screenshot showing the table of contents (`help.png`) goes stale the moment
  `_manifest.json` changes.** Re-take it in the same commit.
  The `verify` skill's HTTP bridge + Chromium harness is how these are produced without a native
  window: it drives the real SPA against the real `Kernel`, so a capture is the shipping UI, not a
  mock-up. Point `db.path` at a **copy** of the real database and never at
  `Paths::databaseFile()` itself.
- **GitHub's alert blockquotes are supported** — `> [!NOTE]`, `> [!TIP]`, `> [!IMPORTANT]`,
  `> [!WARNING]`, `> [!CAUTION]`, written exactly as GitHub wants them. They are *not* part of the
  GFM spec (they are a GitHub rendering feature), so CommonMark's GFM extension does not implement
  them and `HelpService::styleAlerts()` synthesises the callout for the app; the source is untouched
  and the wiki goes on using GitHub's own styling. An **unrecognised** marker (`> [!SOMETHING]`) is
  deliberately left as a plain blockquote, which is what GitHub does with it too.
- **Keep the `# H1`** at the top of each file. It is what makes the file readable on its own, in
  the repository and on GitHub's blob view. The sync script strips it on the way to the wiki, where
  GitHub already prints the page name as a heading.
- **The documentation is English only**, deliberately. A GitHub wiki has a flat page namespace with
  nowhere to put a translated set, and the two consumers share one source. Only the Help screen's
  *chrome* (filter box, buttons, error states) is translated, through the usual
  `language/en-GB/en-GB.ini` + `UiStrings::KEYS` route.

## Rendering (`src/Help/HelpService.php`)

GitHub-Flavoured Markdown with `html_input => 'allow'`. That combination is what makes the two
consumers **agree**: the GFM extension bundles `DisallowedRawHtml`, so exactly the tags GitHub
neutralises (`<script>`, `<style>`, `<iframe>` …) are escaped here too, while the ones a manual
legitimately needs (`<kbd>`, `<sub>`) survive in both places. Stripping HTML outright would have
been safer in the abstract and *wrong* in practice — a page would mean two different things
depending on where it was read.

Two `DocumentParsedEvent` listeners then transform the **AST** — never a regex over the rendered
HTML, because at AST level a URL is unambiguously a URL and a blockquote unambiguously a blockquote,
with no guessing about quoting or about what is inside a fenced code block:

- `rewriteReferences()` retargets links and images (see the link table below).
- `styleAlerts()` turns GitHub's alert blockquotes into callouts. The marker parses as a single
  `Text` node followed by a `Newline` (the unmatched `[` never becomes a link), so it is removed as
  those two nodes and a title paragraph is prepended, carrying the level's icon and label. The icon
  is a real `<i class="fa-solid fa-…" aria-hidden="true">` element, **not** a CSS `content:`
  codepoint — `app.css` hard-codes no glyph anywhere, and a class name cannot silently point at a
  different picture when FontAwesome renumbers. The five labels (Note/Tip/Important/Warning/Caution)
  are English and stay English: they sit inside an English document, so they are deliberately not in
  `UiStrings::KEYS`. In CSS each level sets one `--alert` custom property and the border, tint and
  title colour all read it, so adding a level is one rule rather than four. This is why the converter is assembled by hand
(`new Environment` + `CommonMarkCoreExtension` + `GithubFlavoredMarkdownExtension`) rather than
using `GithubFlavoredMarkdownConverter`, which does not expose an `Environment` to add a listener
to. `Markdown\MarkdownService` (the *import* feature) is a separate, simpler converter and is
unrelated.

⚠️ **The SPA writes a rendered page with `innerHTML`** — the only whole-document `innerHTML` in the
app. That is safe *because of where the HTML comes from*: `docs/` ships inside the binary and is
never user input, and PHP has already applied GitHub's own escaping. Never point `openHelpPage()`
at anything fetched from a site.

## ⚠️ No link in a documentation page may be followed normally

This is the rule most easily broken by someone adding a link and assuming a browser is a browser.
Boson's webview **opens no new window**, so a `target="_blank"` anchor does nothing at all; and a
same-window navigation would replace the entire SPA with the remote page, with no way back — there
is no chrome, no Back button and no history UI. This is the same constraint that made the TinyMCE
Help dialog's link-only tabs unshippable (gh-21).

So every anchor is classified at render time by `HelpService::rewriteReferences()` and acted on by
the delegated handler in `initHelpLinks()`:

| Link in the Markdown | Tagged | What a click does |
|---|---|---|
| `[x](Other-Page)` — relative | `data-help-page="Other-Page"` | `openHelpPage()`, in-app |
| `[x](https://…#frag)` | `data-help-external="1"` | `api.openUrl()` → `Support\UrlOpener` → OS browser, fragment intact |
| `[x](mailto:…)`, other schemes | *nothing* | swallowed — see below |
| `[x](#section)` | *nothing* | left to the browser, which scrolls the pane |

⚠️ **`mailto:` and friends are deliberately left untagged.** `UrlOpener::open()` accepts **http(s)
only** and throws otherwise, so tagging them external would turn a click into an error toast —
worse than nothing. The href stays intact so the link still reads correctly on the wiki, and the
SPA's handler ends with a catch-all `preventDefault()` for any non-fragment anchor it did not
classify, so an unclassified link can never navigate the webview away. CommonMark's
`allow_unsafe_links => false` has already removed `javascript:`, `data:`, `vbscript:` and `file:`
before any of this runs.

## Routes

`GET /api/help` (table of contents), `GET /api/help/page/{key}`, `GET /api/help/image/{file}`.
`{file}` is a placeholder type added to `Http\Router` for this (`name.ext`, no slash, no bare
`..`), so a traversal attempt matches no pattern and dies at the router's 404 — the file-name check
in `HelpService::image()` is defence in depth, not the only guard.

None of it touches a site, the network or the database, so **the Help screen works with nothing
configured at all** — which is exactly when someone is most likely to open it. Keep it that way.

## Contextual help

Any element anywhere in the SPA carrying **`data-help-page="Some-Slug"`** opens the Help screen on
that page — a document-level delegation in `initHelpLinks()`, excluding `#help-page` itself so a
link inside a rendered page is not handled twice. A contextual help button is therefore **pure
markup** in `view/index.html`: it localises its tooltip through `applyStrings()`'s existing
`data-i18n-title` pass and needs no JavaScript of its own.

Every screen reachable from the sidebar carries one, pointing at that screen's own **Reference**
page: Sites, Articles, Media Manager, Settings and Request Log. The Help screen itself does not —
`Help.md` documents it, but that page is already visible in the screen's own table of contents, so a
button there would be pure noise. A new screen gets a button *and* a Reference page; never a button
pointing at a page that does not exist yet.

⚠️ **Write the button as a SIBLING of the screen's action container, never inside it.**
`#media-header-actions`, `#requestlog-actions` and `#help-actions` are all cleared and rebuilt by
their render functions (`renderMediaHeaderActions()`, `renderRequestLogScreen()`,
`renderHelpActions()`), which would destroy a static button placed within them — silently, and only
from the second render onwards.

## Publishing to the wiki (`scripts/sync-wiki.sh`)

`phing wiki` / `composer docs:wiki` (and step 4 of `phing release`). The wiki is a **separate git
repository** (`akeeba/grafida.wiki.git`) that nothing else in the build touches, so it is cloned
into `build/wiki-repo` (gitignored), written, committed and pushed.

- ⚠️ **The wiki is a mirror.** Anything edited through GitHub's wiki editor is overwritten on the
  next sync. Restrict wiki editing to collaborators in the repository settings, and let
  `_Footer.md` (which the script generates) say so on every page.
- ⚠️ **GitHub creates the wiki repository lazily** — it does not exist until one page has been
  saved through the web UI, and cloning a never-created wiki fails rather than yielding an empty
  repository. The script says this in its error message; the first run on a fresh repository needs
  that manual step.
- Exactly **three** transformations happen on the way across, and the file tree is otherwise
  verbatim: `_manifest.json` becomes `_Sidebar.md` (and is not itself published), a leading `# H1`
  is dropped, and `_Footer.md` is generated. Resist adding a fourth — the value of this arrangement
  is that a writer can predict what the wiki will look like from the file in front of them.
- The sidebar mirrors the manifest tree as a nested Markdown list. A **top-level heading** renders
  as a bold section title (`**Section**`) and its children stay at list depth 0 — the bold line
  already supplies the level, so indenting them under a list item that does not exist would just
  add stray whitespace. A heading deeper down is a plain unlinked list item instead, because a bold
  run inside a list reads as a mistake.
- Every `.md` in the clone is deleted before the mirror is written, so a page removed from `docs/`
  disappears from the wiki instead of lingering.
- `--dry-run` prepares `build/wiki-repo` without committing or pushing.
