---
description: Grafida SPA front-end (assets/private) — TinyMCE/CodeMirror wiring, editor gotchas, media browser, theming, layout, spell checking. Lifted verbatim from CLAUDE.md's Layout section.
paths:
  - "assets/private/**"
---

# Grafida SPA front-end (`assets/private/`)

These are the front-end notes from `CLAUDE.md`'s `## Layout` section, moved here so they load
only when you are working under `assets/private/`. They are **verbatim** — the leading `- ` and
two-space continuation indent are the original bullet formatting.

⚠️ Do **not** move this file to `assets/private/CLAUDE.md`: `boson.json`'s `build.directories`
bundles `assets/private` wholesale into every shipped binary, so a memory file placed there would
ship inside the app.

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
  ⚠️ **A stored timestamp is never handed to `Date.parse()`** — `js/util/datetime.js`
  (`window.GrafidaDateTime = {parse, format}`, gh-53) is the one place a naive UTC `Y-m-d H:i:s`
  becomes a `Date`, building it component-wise from `Date.UTC()` because WKWebView does not parse
  that form reliably (the trap already documented for `ai_chats.last_response_at`,
  `drafts.updated_at` and `reference_cache.fetchedAt`). Anywhere the app only needs to **order**
  such values it still compares the strings directly — that format sorts lexicographically in
  chronological order — and does not come here at all; this module exists for the one thing string
  comparison cannot do, showing a timestamp to a person. Two things worth knowing:
  `parse()` **round-trips the components back out of the built `Date`**, which is load-bearing
  rather than paranoid — `Date.UTC()` does not reject an out-of-range component, it rolls it over,
  so Joomla's MySQL null date (`0000-00-00 00:00:00`, still found in legacy `modified` columns)
  would otherwise render as a real date in 1899; and `format()` renders in the machine's **own**
  time zone and `State.language`, which can differ from the same article's date in Joomla's
  back-end (site/user time zone). It is loaded **before** `app.js` (unlike the `js/editor/` modules,
  which load after it) since it is self-contained; `app.js`'s `formatStamp()` wrapper guards its
  absence all the same. `tests/js/datetime.test.mjs` covers it.
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
  `addon/edit/{matchbrackets,closetag}` + `addon/fold/xml-fold` + `addon/dialog` +
  `addon/search/{search,searchcursor,jump-to-line}` + the `material-darker` dark theme) instead of
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
  **Tag auto-closing is a three-way preference, not on/off** (gh-52, see
  `Editor\AutoCloseTagsService`): the `closetag` addon binds two independent keys — `'>'`
  (`whenOpening`, inserting `</p>` when you finish typing `<p>`) and `'/'` (`whenClosing`,
  completing a closing tag once you have typed its `</`) — and `autoCloseTagsOption()` maps
  `State.autoCloseTags` onto `true` / `{whenOpening: false}` / `false`. The middle one is the mode
  for editing existing markup: nothing appears unbidden, but a closing tag you have started is
  finished for you. `AUTO_CLOSE_TAGS_CHOICES` must stay in step with PHP's `AVAILABLE`. The
  preference is read where the instance is built and **never applied live** — the source editor is
  a `showModal()` overlay, so Settings cannot be reached while one is open; `applyAutoCloseTagsChange()`
  deliberately has no live-update branch (`setOption('autoCloseTags', …)` *would* work — the option
  handler adds/removes the keymap — there is simply no way to reach it).
  ⚠️ **`addon/fold/xml-fold` is vendored for `closetag`, not for folding** (we ship no fold
  addon), and must load **before** it. `closingTagExists()` is guarded by
  `if (!CodeMirror.scanForClosingTag) return false;`, which xml-fold is the only provider of — so
  without it the check silently degrades to "no closing tag exists" and the addon inserts one even
  when a matching tag already follows, exactly the case gh-52 complains about. It is a silent
  quality regression, not an error: nothing throws, the auto-close is just dumber than upstream's.
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
  filterable command menu — headings, lists, inline code, a preformatted block, dummy text, quote,
  read more, images, link, table, source code, fullscreen. Code and Pre sit directly below Ordered
  List and share `js/editor/codeformats.js`'s format actions with the editor shortcuts. Ported from Brian Teeman's
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
  so the built-in names (`shortcuts`, `keyboardnav`, `versions`) must be repeated to
  keep them; and the built-in "Handy Shortcuts" tab is a **hard-coded table** — it does not read
  the editor's shortcut registry, which is why an `addShortcut()` never appears there and Grafida
  needs a tab of its own. A dialog `table` cell and an `htmlpanel` are both set via **innerHTML**,
  so anything user-supplied (an AI tool's title) goes through `escapeHtmlText()`;
  `helpShortcutText()` mirrors the help plugin's own `convertText()` so our rows render as
  ⌘/⌃/⇧ glyphs on macOS and `Ctrl + …` elsewhere, and its output is therefore HTML (escape a
  sentence *before* interpolating a shortcut into its `%s`).
  ⚠️ **No link in that dialog can be opened, so neither built-in tab made of links is shipped as it
  comes** (gh-21). A TinyMCE dialog renders a link as a `target="_blank"` anchor and nothing in this
  app answers that: Boson's webview opens no new window, and the SPA routes every external URL
  through `api.openUrl()` explicitly. So **`plugins` is dropped outright** — it is a list of links
  and nothing else, headed by TinyMCE's advertisement for the premium plugins we neither ship nor
  can load — and **`versions` is replaced by `versionHelpTab()`**, which repeats the built-in tab
  verbatim minus the anchor (`help_tabs` lets a tab *object* named `versions` replace the built-in
  of that name). The version sentence is kept rather than dropped with it because it is what someone
  quotes when filing a bug, and it is translated through **TinyMCE's** catalogue, not ours — it is a
  sentence about TinyMCE that already ships in every language pack. Anything added to the dialog
  later has to clear the same bar. (This replaced a first attempt at gh-21 that registered Grafida's
  own editor features — the slash-command menu, the AI assistant — as empty plugins answering
  `getMetadata()` so they would be *named* in the plugins tab; with the tab gone, so is that
  machinery.)
  Code and Pre use literal **Alt+Shift+C/P** shortcuts on every platform (registered by
  `js/editor/codeformats.js`); the older Blockquote shortcut remains literal Ctrl+Shift+Q.
  **Cmd/Ctrl+Shift+V pastes the clipboard as plain text in one keystroke** —
  `bindPastePlainShortcut()` in `app.js`, which is deliberately four lines long:
  `preventDefault()`, then fetch the clipboard from the backend, then insert. Everything
  interesting about it is *why* it is that short.
  It is **not** `mceTogglePlainTextPaste`, the command behind **Edit ▸ Paste as text**: that
  toggles a *mode* and would need a second Cmd/Ctrl+V to paste anything. Insertion goes through
  TinyMCE's own `mceInsertClipboardContent` with a **`text`** payload (`{text: …}`), which is what
  the paste plugin itself uses, so entity escaping and the blank-line→paragraph / newline→`<br>`
  conversion behave identically to a real paste. Don't hand-roll that conversion.
  ⚠️ **Nothing in the page may read or trigger the clipboard without the user confirming it.**
  Three routes look obvious and all three are shut, which is the whole reason the backend is
  involved:
  - `navigator.clipboard.readText()` — WKWebView answers an unprivileged read with a **system beep
    and a one-item "Paste" callout that must be clicked**. Worse than the menu item it replaces.
    (A Chromium harness cannot reproduce this: Chromium binds the chord itself, so the fallback is
    never reached. It was reported from the running app.)
  - `execCommand('paste')` / TinyMCE's `execCommand('Paste')` — refused outright;
    `queryCommandSupported('paste')` is `false`. Note TinyMCE's returns **`true`** while pasting
    nothing, so this failure looks like success.
  - toggle-the-mode-then-paste — founders on the same refusal. TinyMCE can only *strip* a paste the
    user performs; it can never perform one. That is precisely why Edit ▸ Paste as text is a mode.
  - a real `paste` event — only exists on a webview that binds the chord natively, which WKWebView
    and WebKitGTK do not.
  So `GET /api/clipboard/text` → `Grafida\Clipboard\ClipboardService` reads it server-side, and the
  SPA end is `api.getClipboardText()`. **Do not "simplify" this back into the page.**
  ⚠️ The `preventDefault()` is **unconditional and not cosmetic**. On macOS Cmd+Shift+V is an AppKit
  *menu* key equivalent (Edit ▸ Paste and Match Style); a Boson window has no menu bar, so the key
  otherwise reaches no responder and **the system beeps**. Cancelling marks it handled and silences
  that — and, on Chromium, also suppresses the native paste-as-plain-text command that would
  otherwise fire *as well*, which is what keeps one keystroke to one paste on every platform.
  Cancelling is also why this is a raw `keydown` handler and not `addShortcut()`.
  An earlier version branched on `Env.browser.isChromium()` to consume Chromium's native paste event
  as a fast path, with a `setTimeout` fallback behind it and armed/handled flags to prevent a double
  paste. It worked, and it was deleted: the backend read is a few milliseconds on every platform
  (Windows included, see below), so the branch bought nothing but the need to reason about
  microtask-versus-task ordering against the webview's default action.
  `codeformats.js` also appends the inline-backtick Code pattern through `text_patterns_lookup`
  (so TinyMCE's defaults survive) and handles a bare triple-backtick paragraph on Enter itself:
  TinyMCE's public enter-triggered block pattern deliberately skips a marker with no following text,
  which is exactly the normal Markdown fence.
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
  ⚠️ **The editor sidebar's Custom Fields section is scoped to the article's category** (gh-56), and is the
  one part of the sidebar with a repaint path of its own: `renderCustomFields(draft)` refills
  `#editor-custom-fields` (both the inputs *and* the unsupported-fields notice) from
  `fieldsForCategory(list, catid)`, and the Category `<select>`'s own `change` handler calls it. The
  filter is deliberately a one-line `categoryIds.includes(id)` — PHP's `Field\FieldCategoryScope`
  has already expanded each field's assignment down the category tree and sends `categoryIds`
  (`null` = every category) in the references payload, so the actual Joomla rule is **not**
  reimplemented here. A field with no `categoryIds` array at all stays visible everywhere (an
  unknown assignment must never hide a field the user may have typed into).
  ⚠️ **A `media` field's control is the one that does not expose a `.value`.** Its value is a
  three-subfield record collapsing into a JSON string (see `CLAUDE.md`'s `src/Field/` bullet), so
  `buildMediaFieldInput()` keeps the record in a closure and hangs a **`_getMediaValue()`** getter on
  the container — the same trick `buildTagsInput()`'s `_getTags` uses — and `collectDraftFormData()`
  has a `media` branch beside the `checkboxes`/`radio` ones. `encodeMediaFieldValue()` /
  `decodeMediaFieldValue()` mirror PHP's `MediaFieldValue`, `MEDIA_FIELD_KEYS`' order included:
  the collected object is JSON-compared against `State.editorBaseline`, so a control that
  re-serialised its keys in a different order would read as an edit nobody made. The control itself
  is deliberately `buildImageBlock()` minus the parts `accessiblemedia` has no room for (no caption,
  no CSS class — those are `#__content.images` subfields) and with **one** Browse button rather than
  two, since `openMediaBrowser(siteId, {allowUpload: true})`'s own footer already covers picking a
  file off this machine. A local pick is held as the shared `grafida-media://N` sentinel and its
  local URL cached in `State.mediaPreviews`, exactly as the intro/full-text picker does.
  ⚠️ **`collectDraftFormData()` therefore cannot build `fields` from the rendered inputs alone.**
  It seeds the object from `State.currentDraft.fields`, keeping only entries that carry a value,
  before the rendered inputs overwrite it — otherwise changing category would wipe the fields
  leaving scope, and the next save would lose them for good. The empty ones are dropped so that
  merely *visiting* a category does not add keys and make an unedited article look dirty, and the
  result is **key-sorted** because `isEditorDirty()` compares this object's JSON and the insertion
  order would otherwise follow whichever fields the current category happens to render. The change
  handler writes the collected `fields` back onto `State.currentDraft` before repainting — nothing
  else remembers them.
  ⚠️ **A category drop-down's tree indent is Joomla's `- ` prefix, never leading spaces**
  (gh-40): `categoryTreeOptions()` is the single place both the editor sidebar's Category
  select and the Articles screen's category filters get their `[id, label]` pairs, ordered
  by `lft` and indented by `level` relative to the shallowest category present (never by
  `parent_id` — the hidden ROOT node's id must not be assumed). The indent characters are
  load-bearing: HTML collapses leading whitespace in an `<option>`, so the space-padded
  labels this replaced rendered as a flat list.
  ⚠️ **`openEditorScreen()` empties the screen before it fills it, and nothing it does may abort the
  TinyMCE step.** `resetEditorScreen()` runs *first* — destroying the editor instance and blanking
  the sidebar, title and alias — because everything on this screen belongs to one article, the
  screen is on display the whole time it is being rebuilt, and `State.currentDraft` is already
  re-pointed by the time we get here. Leaving the teardown to `initTinyMCE()` (the *last* step,
  which still does its own for the callers that re-create the editor in place) meant any failure
  before it left the **previous** article standing: pressing New Article after backing out of one
  showed that article, and saving would have written its body into the new draft. The sidebar render
  is then wrapped in a `try` (toast `GRAFIDA_MSG_SIDEBAR_FAILED`) so it can never skip the editor —
  a bad sidebar is a bad sidebar, not a dead editor. The inner half of the same guarantee is in
  **`renderCustomFields()`**, which builds each field in its own `try` and folds a field it could
  not build into the unsupported-fields notice: a field definition arrives from the site verbatim,
  so an unanticipated shape is a permanent possibility and must cost that field alone.
  ⚠️ **`fieldOptionRows()` is why list / radio / checkboxes fields render at all.** Joomla stores a
  field's options as a *subform object* keyed `options0`, `options1`, … — never an array — and
  `#__fields.fieldparams` reaches the SPA decoded verbatim, nothing in between reshaping it, so
  iterating it directly threw. Each row is `{name, value}`, where `name` is the **label**.
  ⚠️ **`openEditorScreen()` waits on nothing.** Writing an article is a *local* operation, so the
  sidebar is rendered and TinyMCE is created from whatever is already cached — including nothing at
  all — and `loadEditorSiteData()` then fills the reference data and the site stylesheet in
  afterwards, fire-and-forget. It used to `await` both fetches first, which is how a newly added or
  slow site could leave the editor screen empty for as long as its server took to answer: Boson
  serves one `boson://` request at a time (so the two queue), a cold reference cache costs one HTTP
  request **per custom field**, and `EditorCssService::load()` used to re-run template discovery plus
  a walk over every candidate URL on every open (it is cache-first now — see
  `.claude/rules/joomla-api-and-references.md`). The worst a site may cost the editor is empty
  drop-downs and an unstyled editing surface. Four things this rests on:
  - **`State.editorBaseline` is snapshotted before the background load**, so the late repaint must
    not change what `collectDraftFormData()` reads back, or a pristine article would look edited.
  - ⚠️ **`preserveUnknownOption()` is what makes that true, and it is load-bearing against data
    loss, not cosmetics.** These `<select>`s *are* the draft: a select with no option for the stored
    value silently falls back to its first one, and the next save writes that. With an empty
    category list an article quietly lost its category; with an empty language list it reverted to
    All; with no access levels it was downgraded to Public. An empty list means "not loaded yet" far
    more often than "the site has none", so the stored value is kept and labelled
    (`GRAFIDA_OPT_NOT_LOADED`, or the tag itself for a language, which is self-explanatory).
  - **`repaintEditorSidebar(siteId)`** is the one form-preserving repaint (`collectDraftFormData()`
    merged back over the draft), shared with `applyRefreshedReferences()`. It never touches TinyMCE.
    ⚠️ **It also re-takes `State.editorBaseline` when the form was not dirty going in**, which
    `preserveUnknownOption()` cannot substitute for: that helper can only *relabel* a value a
    drop-down already carries, and the custom fields are the half it cannot reach — the reference
    data decides which of them are rendered at all, `collectDraftFormData()` reads `fields` back off
    exactly those inputs, and a field list arriving after the snapshot therefore adds keys the
    baseline could not possibly have contained. On a cold SPA reference cache (i.e. the first
    article opened after launch, on any site with custom fields) that made an untouched article
    prompt "Unsaved changes" on the way out. Re-baselining a *dirty* form is what the guard exists
    to prevent — it would silently adopt the user's unsaved edits as the saved state.
  - **A late stylesheet is loaded into the content iframe** (`applyEditorCssToOpenEditor()` →
    `editor.dom.loadCSS()`), never applied by re-initialising TinyMCE, which would discard the undo
    history and cursor of someone already typing. The Styles drop-down is not rebuilt (its class
    list is baked into the init `formats`), so a stylesheet arriving this late contributes its
    classes on the next open. `siteContentCss()` / `colorSchemeStyleFor()` are shared with
    `initTinyMCE()` — ⚠️ note `siteContentCss()` (the **site's** stylesheet, as a blob URL) and
    `editorContentCss()` (the name of TinyMCE's **built-in** content CSS, `default`/`dark`) are two
    different functions one letter apart in meaning; naming the first one `editorContentCss` silently
    shadowed the second and fed an object to `content_css`, which TinyMCE resolved into a bogus
    `<link>` at the *site root*. The late path restates the `color-scheme` declaration, because the init
    committed to dark on the assumption that there was no stylesheet and a light-only sheet's own
    `color:` rules on a dark canvas are unreadable. `State.editorCss` is a **single untagged slot**,
    so the late handler drops a reply that arrives after the user has moved to another site.
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
  **The Help screen** (`#help-screen`, `loadHelpScreen()` / `renderHelpToc()` / `openHelpPage()`)
  shows the documentation bundled in `docs/` — the same Markdown files that are published as the
  project's GitHub wiki. Two panes, each scrolling independently: the table of contents (a
  recursively rendered tree from `docs/_manifest.json`, with a filter box that keeps a node whenever
  any *descendant* matches, or the only route to a matching page would be hidden) and the rendered
  page. `#help-screen` sets `overflow: hidden` so the screen itself never grows a second scrollbar
  around the one that matters. `State.helpContents` is fetched once per session (it cannot change
  without a new build) and `State.helpSlug` remembers the open page, so leaving and re-entering the
  screen comes back to where you were. ⚠️ Three things:
  - **`openHelpPage()` writes the rendered Markdown with `innerHTML`** — the only whole-document
    `innerHTML` in the app. Safe *because of where it comes from*: `docs/` ships inside the binary,
    is never user input, and PHP has already applied GitHub's own escaping (`DisallowedRawHtml`).
    Never point it at anything fetched from a site.
  - ⚠️ **No link inside a rendered page may be followed normally**, for the same reason the TinyMCE
    Help dialog ships no link-only tabs (gh-21): the webview opens no new window, and a same-window
    navigation would replace the SPA with no way back. `initHelpLinks()` routes `data-help-external`
    anchors through `api.openUrl()`, `data-help-page` anchors through `openHelpPage()`, lets a bare
    `#fragment` through to the browser, and **swallows everything else** — the catch-all
    `preventDefault()` is what stops an unclassified link (a `mailto:`, or whatever a future change
    fails to tag) from navigating the webview away.
  - **Contextual help is pure markup.** Any element anywhere in the app carrying
    `data-help-page="Some-Slug"` opens the Help screen on that page, via a document-level delegation
    in `initHelpLinks()` that excludes `#help-page` itself (so a link inside a rendered page is not
    handled twice). A contextual help button therefore needs no JavaScript of its own and localises
    its tooltip through `applyStrings()`'s existing `data-i18n-title` pass.
  The contents pane **collapses and resizes**, for the 13"-laptop case where 220px of sidebar plus
  250px of contents leaves the page itself too narrow. Three things about it differ from the app's
  other collapsibles, all deliberately:
  - It collapses **to nothing**, not to an icon rail. Its entries are page titles, so there are no
    icons a rail could show, and giving the width back is the entire point.
  - Its toggle (`#help-toc-toggle`) therefore lives in the **screen header**, outside the pane it
    hides — inside, collapsing would take the way back with it. `setupCollapsible()` already takes
    the toggle id separately from the aside id, so this needed no change to that helper. It is
    static markup and sits deliberately *outside* `#help-actions`, which `renderHelpActions()`
    clears and rebuilds on every page change.
  - The width is written to **`--help-toc-width` on `#help-layout`**, not to `#help-toc`: the pane
    is a grid *track*, and a width set on the item cannot widen the track constraining it.
    `syncHelpTocToggle()` mirrors the collapsed state onto the layout as a class rather than the CSS
    deriving it with `:has()`, and onto the toggle as `aria-pressed` — one fixed label, like the
    display-mode switch, instead of two strings that must stay each other's opposite.
  ⚠️ The drag handle `#help-toc-resizer` is positioned against `#help-layout` and **not** against
  `#help-toc`: that pane scrolls, so a handle inside it would scroll away with the content and be
  clipped by its `overflow`. It sits in the 20px grid gutter, overlapping neither pane.
  The documentation is **English only** — one source shared with a wiki that has a flat page
  namespace — so `applyStrings()` re-renders only the screen's chrome, never the page or its titles.
  The rest is in `.claude/rules/documentation.md`.
  **Collapsible/resizable layout** (`initLayoutControls()` in `app.js`): the left **`#sidebar`**
  and the editor metadata **`#editor-sidebar`** ("Article properties") each carry an `.icon-toggle`
  button (`#sidebar-toggle` / `#editor-sidebar-toggle`) that toggles a `.collapsed` class — the left
  sidebar collapses to a 56px icon-only rail, the metadata sidebar to a 40px rail; the state persists
  in `localStorage` (`grafida.sidebarCollapsed` / `grafida.propsCollapsed`). The Help screen's
  contents pane is a third `setupCollapsible()` caller (`grafida.helpTocCollapsed`) — see the Help
  screen notes above for why it alone collapses to nothing rather than to a rail.
  Two panels are width-**resizable** by dragging a `col-resize` handle, both through the shared
  **`setupPanelResize(resizerId, opts)`**: `#ai-panel` via `#ai-panel-resizer` on its *left* edge
  (`setupAiPanelResize()`, clamped 280px…`min(innerWidth−360, 760)`, persisted in
  `grafida.aiPanelWidth`) and the Help contents via `#help-toc-resizer` on its *right*
  (`setupHelpTocResize()`, 160px…`min(innerWidth−520, 460)`, `grafida.helpTocWidth`). The helper
  parameterises exactly the two things that differed — **`edge`** (`-1` when the handle is on the
  panel's left, `+1` on the right) and **`apply`** (the AI panel is a flex child and sets its own
  width; the Help contents is a grid *track*, so its width goes to a custom property on the grid
  container and setting it on the element would do nothing) — while pointer capture, clamping, the
  `resizing-col` body class that suppresses text selection mid-drag, and the localStorage write are
  shared, having been identical in both. Toggle buttons localise their tooltip/`aria-label` via a
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
