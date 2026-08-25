## Grafida 0.3

### Highlights

* **Security hardening.** Site URLs must now be HTTPS — Test Connection, Diagnose, Create and
  Edit Site all reject `http://` (and any other non-HTTPS scheme) before any request is sent, and
  the rule is enforced in the HTTP transport itself so it covers every request the app makes:
  favicons, template stylesheets, site images, the update check. Redirects are vetted one hop at
  a time and only followed within the original base domain, and a redirect that would downgrade
  to plain HTTP is refused. An AI service must use HTTPS unless it's on this machine or your
  local network, so local models (Ollama, LM Studio, llama.cpp) keep working while a remote
  provider's API key is never sent in the clear.
* **Lower idle CPU use.** The native event loop was a busy wait costing about half a CPU core with
  the app just sitting idle; it's now under 1%.
* **macOS 15 Sequoia is now the minimum** — the bundled webview library cannot load on macOS 14 or
  earlier.
* **Media Manager: Local Media tab.** Crop, resize, rename and delete images before they're
  published, without touching the site
  ([gh-36](https://github.com/akeeba/grafida/issues/36)).
* **Custom fields of type Media** can now be edited using Grafida's own media browser — site media
  or a local picture uploaded on publish. Editing a site article now also carries its custom field
  values, including types Grafida can't display, and "Publish anyway" lets you publish an article
  that has required custom fields Grafida can't edit
  ([gh-59](https://github.com/akeeba/grafida/issues/59)).
* **Sites:** choose which Media Manager filesystem and folder a published article's images are
  uploaded to ([gh-57](https://github.com/akeeba/grafida/issues/57)), and say whether the site
  uses Unicode Aliases for tokens that can't read it from Global Configuration
  ([gh-61](https://github.com/akeeba/grafida/issues/61)). The article alias is now transliterated
  per the article's language (German, French, Greek), mirroring Joomla's own rules.
* **Diagnose Connection and an optional Request Log.** See the exact request and response when a
  site connection fails, or record the last 20 requests to the site with JSON export
  ([gh-37](https://github.com/akeeba/grafida/issues/37)).
* **Light / dark / follow-system switch in the sidebar**, so you can change the interface theme
  without leaving the open article ([gh-41](https://github.com/akeeba/grafida/issues/41)).
* **In-editor image tools.** Right-click an image for Edit image (crop/resize/rotate/flip a local
  picture), CSS class and Reset size; the in-article media browser can also pick a Local media
  image not yet published ([gh-43](https://github.com/akeeba/grafida/issues/43)).
* **Built-in Help screen**, with the same documentation also published as the GitHub wiki
  ([gh-55](https://github.com/akeeba/grafida/issues/55)).
* **AI content normalisation.** Strips the invisible characters (zero-width spaces, bidi and tag
  characters, exotic spaces) AI tools tend to leave in text — from AI replies, imported Markdown,
  plain-text pastes and anything you publish. On by default, with a three-way setting.
* Smaller conveniences: Ctrl/Cmd+N starts a new article, Ctrl/Cmd+Shift+V pastes the clipboard as
  plain text, a Reload metadata button on the Articles page, control over how long site metadata
  is cached, control over how the source-code editor closes HTML tags, and MiniMax as a built-in
  AI service provider.

### Notable fixes

* The Read More separator of an article written in Joomla was invisible in the editor, and lost on
  publishing ([gh-71](https://github.com/akeeba/grafida/issues/71)).
* **macOS 14 Sonoma:** the application quit instantly, with no window and no error message
  ([gh-58](https://github.com/akeeba/grafida/issues/58)).
* Severe editor lag, and a frozen source code editor, after pasting a large image
  ([gh-36](https://github.com/akeeba/grafida/issues/36)).
* The editor would not load while a slow or newly added site was still answering.
* Opening an article before the site's metadata had loaded could strip its category, access level
  or language on save.
* Editor content stuck in dark mode when the site's `editor.css` switches on
  `prefers-color-scheme` ([gh-38](https://github.com/akeeba/grafida/issues/38)).
* The editor showed every custom field the site has, not just the ones the article's category
  uses — and a required field belonging to another category made publishing impossible
  ([gh-56](https://github.com/akeeba/grafida/issues/56)).
* New Article opened with no editor at all, or with the article you had just backed out of, when a
  single custom field the sidebar couldn't render took the whole editor down with it.
* A list, radio or checkboxes custom field could not be rendered at all, because Joomla doesn't
  store its options as a list.

See the [`CHANGELOG`](CHANGELOG) for the full list of changes.

### Downloads

| Platform | Download |
| --- | --- |
| macOS (Apple Silicon) | [`Grafida-0.3-macos-arm64.dmg`](https://github.com/akeeba/grafida/releases/download/0.3/Grafida-0.3-macos-arm64.dmg) |
| macOS (Intel) | [`Grafida-0.3-macos-amd64.dmg`](https://github.com/akeeba/grafida/releases/download/0.3/Grafida-0.3-macos-amd64.dmg) |
| Windows (x64) | [`Grafida-0.3-windows-amd64-Setup.exe`](https://github.com/akeeba/grafida/releases/download/0.3/Grafida-0.3-windows-amd64-Setup.exe) |
| Linux (x64) | [`Grafida-0.3-linux-amd64.tar.gz`](https://github.com/akeeba/grafida/releases/download/0.3/Grafida-0.3-linux-amd64.tar.gz) |
| Linux (ARM64) | [`Grafida-0.3-linux-arm64.tar.gz`](https://github.com/akeeba/grafida/releases/download/0.3/Grafida-0.3-linux-arm64.tar.gz) |
| Any (PHAR) | [`Grafida-0.3.phar`](https://github.com/akeeba/grafida/releases/download/0.3/Grafida-0.3.phar) |
