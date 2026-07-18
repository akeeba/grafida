## Grafida 0.2

### Highlights

* **Slash commands.** Type `/` anywhere in the editor for a filterable menu of headings, lists,
  images, tables, links and more — switch it off in Settings if you prefer the classic toolbar
  only ([gh-9](https://github.com/akeeba/grafida/issues/9)).
* **Article alias and byline.** The URL slug (alias) is now an editable field in the editor,
  auto-generated from the title with a one-click regenerate button; a separate **Created by
  Alias** field lets you credit an author other than the publishing account
  ([gh-8](https://github.com/akeeba/grafida/issues/8)).
* **Vision-capable AI.** Tell Grafida a configured AI model can see images, and the article's own
  pictures are sent alongside its text — useful for tools that comment on or describe what's in
  the article.
* **Smarter editor styling.** Grafida now finds and applies your site's `editor.css` even on a
  custom or child template, not just the stock Cassiopeia
  ([gh-3](https://github.com/akeeba/grafida/issues/3)), and lets you set an Editor CSS URL by
  hand for the rare template that hides it somewhere unconventional.
* **AI "thinking" indicator.** Reasoning models now show a live, click-to-expand "Thinking…"
  panel while they work, instead of a long silent wait.
* **Decorative images.** Mark an image as decorative — in the inline editor or the intro/full-text
  image fields — exactly as Joomla's own editor does, so screen readers skip it
  ([gh-5](https://github.com/akeeba/grafida/issues/5)).
* **Version history note.** Publishing now records a "Created using Grafida" note in Joomla's
  version history, if your site has content versioning enabled
  ([gh-17](https://github.com/akeeba/grafida/issues/17)).
* **Smaller conveniences:** a searchable icon picker for AI tools, an empty Local Articles tab
  that offers to start a new article or browse the site's, `Ctrl/Cmd+,` to jump to Settings from
  anywhere, article lists sortable by date created/modified, a "Visit site" button under the
  sidebar site icon, and a colour-coded publish-state icon plus the Joomla article ID shown
  before every article's title. Windows installs now bundle the Visual C++ runtime they need.

### Notable fixes

* **Windows:** a startup crash ("broken signature") after Authenticode signing, and a
  multi-second UI freeze whenever a stored secret (site token, AI key) was read.
* The editor previously ignored a site's `editor.css` unless the site used the stock Cassiopeia
  template, or used the *parent* template's stylesheet on a site running a child template
  ([gh-3](https://github.com/akeeba/grafida/issues/3)).
* Media Manager: an edited image's thumbnail and preview kept showing the picture as it was
  before the edit ([gh-4](https://github.com/akeeba/grafida/issues/4)).
* Windows: the Save and Settings shortcuts also fired on the Windows key, and a command window
  briefly flashed at startup and on theme detection
  ([gh-13](https://github.com/akeeba/grafida/issues/13)).

See the [`CHANGELOG`](CHANGELOG) for the full list of changes.

### Downloads

| Platform | Download |
| --- | --- |
| macOS (Apple Silicon) | [`Grafida-0.2-macos-arm64.dmg`](https://github.com/akeeba/grafida/releases/download/0.2/Grafida-0.2-macos-arm64.dmg) |
| macOS (Intel) | [`Grafida-0.2-macos-amd64.dmg`](https://github.com/akeeba/grafida/releases/download/0.2/Grafida-0.2-macos-amd64.dmg) |
| Windows (x64) | [`Grafida-0.2-windows-amd64-Setup.exe`](https://github.com/akeeba/grafida/releases/download/0.2/Grafida-0.2-windows-amd64-Setup.exe) |
| Linux (x64) | [`Grafida-0.2-linux-amd64.tar.gz`](https://github.com/akeeba/grafida/releases/download/0.2/Grafida-0.2-linux-amd64.tar.gz) |
| Linux (ARM64) | [`Grafida-0.2-linux-arm64.tar.gz`](https://github.com/akeeba/grafida/releases/download/0.2/Grafida-0.2-linux-arm64.tar.gz) |
| Any (PHAR) | [`Grafida-0.2.phar`](https://github.com/akeeba/grafida/releases/download/0.2/Grafida-0.2.phar) |
