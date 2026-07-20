# Grafida

**Edit Joomla! content on your desktop.**

Grafida is a cross-platform desktop application for creating and editing [Joomla!](https://www.joomla.org) articles. 

> [!IMPORTANT]
> Grafida is focused on content _authoring_, not content management. It only provides an essential subset of features compared to the full Joomla! article editor page.

Grafida uses the Joomla Web Services (REST) API. It's built with [Boson](https://bosonphp.com) (PHP on the desktop) and runs natively on **macOS, Windows, and Linux**. It _does not_ use Electron; Boson uses the resource-efficient WebView provided by your Operating System.

## This software does not work on Windows Home

New installations of Windows enable [Smart App Control (SAC)](https://windowsforum.com/threads/windows-11-sac-toggle-new-option-lets-you-turn-smart-app-control-on-or-off-without-reinstall.393866/) by default. On Windows _Home_ edition (and _only_ the Home edition) this has a rather concerning side effect: it will block a _signed_ application from executing if Microsoft's cloud does not positively report the application as known safe. On the Pro and Enterprise versions of Windows it will allow execution unless the app is positively reported by Microsoft's cloud as malicious.

Windows blocks execution in an objectively misleading manner. It reports that the application failed to load its DLL file. This disingenuously frames the problem as an application error when, in fact, it is Windows itself prohibiting execution for no good reason! To make things worse, there is no obvious error message and no obvious GUI element to allow execution.

If that wasn't bad enough, us developers have no way to rectify this situation. It can only be rectified by having several hundred thousand people using our application… which is, of course, made impossible by the fact that Windows blocks execution and the niche nature of this software.

As per the information in the article linked above, the only way you can use this software on Windows Home is by disabling SAC. We cannot and will not ask users to do that on their machines. Instead, we explicitly mark Windows Home as incompatible and will refuse to respond to any support requests concerning this Operating System as there is no action possible on our end. 

## Key Features

- **Multiple sites**. Connect to several Joomla sites. You can even publish the same content on multiple sites without leaving the editor screen.
- **Rich editing**. Uses the same TinyMCE editor as Joomla, it has full support for Joomla's “Read More”, and it's styled with your site template's `editor.css`.
- **AI assistance on your terms**. Connect to an inference provider, or a local inference app (e.g. LM Studio) to get AI-powered assistance writing and editing your content. Keeps track of the discussions. Completely optional – you won't even see it if you don't configure it. 
- **Categories, tags, and access levels**. Picked from live, cached site data; new tags are created automatically on publish.
- **Joomla Fields (partial support)**. Edit the supported core field types (`calendar`, `checkboxes`, `color`, `integer`, `list`, `radio`, `text`, `textarea`, `url`). The app warns you when a required field uses a type only Joomla's backend can edit (and offers the article HTML to copy).
- **Media**. Pick and upload to the Joomla Media Manager; images added offline are stored locally and uploaded automatically on publish.
- **Markdown import**. Import a Markdown file to HTML in one click.
- **Offline drafts**. Everything is saved locally in SQLite; publishing is a deliberate action.
- **Speaks your language**. English (en-GB; canonical language) plus machine-translated into Greek, French, German, Spanish, Italian, and Portuguese. Automatic Operating System language detection and a manual override. Uses standard Joomla INI language files for easier translation.
- **Dark Mode**. Never again will you burn your retinas writing a blog post at night. Detect the Operating System's setting, lets you override it.
- **Plays nice with your OS**. Application storage and configuration is stored in the OS-prescribed locations. The app tells you exactly where that is.
- **Security first**. API tokens are stored in your OS secret store (macOS Keychain, Windows DPAPI, Linux libsecret), never in plaintext unless you explicitly opt in.

## Requirements

- A Joomla **5.4 or later** site with the Web Services API enabled and an API token for an account
  authorised for API access. Super Users work by default. Non-Super-User accounts can be configured
  for Grafida; see [Custom API access](docs/Custom%20API%20access.md).
- To run a pre-built release: **macOS 14+**, **Windows 10+**, or **Linux** with GTK4 and
  WebKitGTK 6.0 (`libgtk-4-1`, `libwebkitgtk-6.0-4`).

## Usage

1. Launch Grafida and open **Sites → Add site**.
2. Enter a title, your site URL (bare, e.g. `https://example.com`), and an API token. Grafida
   appends the API path for you and tests the connection.
3. Choose a site, then browse its articles, or start a **New article**.
4. Pick a category, access level, and tags; write your content; insert a **Read more** break
   where the introtext should end.
5. Click **Publish** to send the article to your site, or just keep editing — drafts are saved
   locally and automatically.

### Code signing

- **macOS** — releases are **signed with a Developer ID and notarised by Apple** (builds made
  with the patched SFX runtime; see
  [`build/readme/01-macos-signing.md`](build/readme/01-macos-signing.md)). The app opens like
  any other downloaded application. If you run an *older, unsigned* release, right-click
  **Grafida.app** in Finder and choose **Open**, then confirm — or clear the quarantine flag
  with `xattr -dr com.apple.quarantine /Applications/Grafida.app` if macOS reports it as
  “damaged”.
- **Windows** — releases are **Authenticode-signed** with Azure Artifact Signing (via
  [Jsign](https://ebourg.github.io/jsign/); see
  [`build/readme/04-exe-signing-on-macos.md`](build/readme/04-exe-signing-on-macos.md)). Signing
  does not eliminate SmartScreen's "Windows protected your PC" warning for a small install base —
  reputation accumulates with download volume over time — but it shows our publisher name instead
  of "Unknown Publisher" and improves behaviour with enterprise AV/EDR and AppLocker. Click **More
  info → Run anyway** if you still see the warning.
- **Linux** — no signing is involved; nothing extra is required.

## Philosophy

### Raison d'être

Joomla! is an excellent Content _Management_ System. However, its plethora of content management features has made content authoring in the Joomla article edit page fairly painful. This is not a complaint; power comes with complexity, and we – the Joomla community – chose power over simplicity.

Inexperienced users find the tiny content area surrounded by a swarm of inscrutable options utterly bewildering. Experienced users find the tiny content area unusable, having to use external tools for authoring, resulting in excessive copying and pasting. Nobody's happy – which is why we see the overuse (and abuse) of page builders on sites that can't have possibly benefited from using one. They put content first.

You know what else puts content first? Desktop content authoring applications made for WordPress. Remember Windows Live Writer? MarsEdit? What if we could bring that to Joomla? After all, modern Joomla has a rich API which should allow make that possible. 

### Focused content authoring

The driving force behind Grafida is making _content authoring_ simple, but powerful. A title. A big content area. Sit down and work on your words. You need some AI assistance to elucidate your point? Configure it once, and it's right there when you need it. It's like a word processor, but it “speaks” the native data type of your CMS. When you're done, you hit Publish. Spotted a problem? Fix it, hit Publish again. No fuss, no mess, no copy-pasting shenanigans, no stress.

You need to _manage_ your content? Log into your site with your browser. Manage your content with the plethora of options Joomla and its extensions give you. Tell Joomla how to show the article page. Who can edit it. Add OpenGraph images. Work your SEO magic. You don't really need to touch the content for most, if not all, of that anyway.

Content authoring and content management are not mutually exclusive. _They work together_. Any changes you made in Joomla can be brought back to Grafida for further editing and published back into Joomla without messing up your content management options.

The goal is to use Grafida for distraction-free content authoring, and Joomla itself for content management.

## Out-of-scope features

Grafida is not your Joomla article editor page wrapped in a web view. It is a standalone desktop application, designed to work off-line. Between this fundamental design choice and Joomla's architecture, there are several features and interactions with core and third party extensions which are explicitly out-of-scope of this project.

Indicatively:

* **Editor buttons** (typically provided by `editors-xtd` plugins). Not exposed through the API. Rendered server-side by Joomla. Many interact with Joomla extensions in the front- or backend. Cannot implement outside Joomla.
* **Live preview on your site**. Cannot implement outside Joomla. If you're wondering why, look at its core code. It's a clever hack, but it cannot be replicated externally 😊
* **Joomla Fields**. Only a small subset of core field types (calendar, checkboxes, color, integer, list, radio, text, textarea, url) are supported. Other core field types require server-side rendering. Field types provided by third party plugins are explicitly unsupported as we have no way of knowing how they are supposed to be rendered. You need to edit those fields in the backend of your site.
* **Content plugins**. “Plugin codes” such as `{loadmodule 123}` will be rendered as plain text in the preview. Cannot be addressed. These are rendered server-side by Joomla itself using the `onContentPrepare` plugin event which cannot be accessed over the API. 
* **Plugins rendering additional editor fields or tabs**. Some system, content, etc plugins implement additional fields or editors tab. For example, what we do in SocialMagick and AITiny. This hinges on server-side plugin events which are not exposed by the API. Even if that wasn't the case, many fields rely on server-side rendering, or make assumptions about running in the Joomla's backend with a known CSS and JavaScript framework which is not the case for Grafida. 
* **Article Permissions**. This data is not exposed by the API. 
* **Workflows**. Beyond the fact this relies on server-side rendering, we consider this a management –not content authoring– feature, making it explicitly out-of-scope for this project.
* **The full collection of the Joomla article edit page fields**. These fields are not exposed through the API. They depend on your Joomla version and access level, therefore we can't “fake it” by doing our own implementation either.
* **Joomla Media Manager**. Grafida does not actually show you Joomla's Media Manager. It shows you its own, cut-down media manager using the media information it receives over the API. It is intentionally kept simple. This is a design choice which won't change; we are not interested in creating a full-blown media manager!
* **Third party media managers**. Third party media managers are rendered server-side inside Joomla itself. We cannot even access them through the API. Even if we could, it would require creating an explicit implementation for each make and version of third party media manager, making this thoroughly impractical. 
* **Third party content extensions** such as Page Builders, CCKs, etc. They replace Joomla's content management completely, and / or use undocumented data structures which are heavily dependent on the make and version of the third party extension you are using. You would need a separate implementation of each page builder / CCK inside Grafida which is both impractical and morally questionable (it would amount to “stealing” other people's software).

## Building from source

Grafida needs **PHP 8.4+** with the `ffi`, `pdo_sqlite`, `dom`, `mbstring` and `curl`
extensions, plus [Composer](https://getcomposer.org), [Node.js + npm](https://nodejs.org)
(the front-end libraries are vendored via npm, not committed) and
[Phing](https://www.phing.info) installed as a **global** command.

```bash
git clone https://github.com/akeeba/grafida.git
cd grafida
composer install                     # also vendors the front-end libraries via npm

# Compile the binary for THIS host and launch it:
phing run                            # or: composer start

# One step: compile AND package every platform's distributable.
composer build                       # artifacts land in build/dist/
```

The build is driven by [`build.xml`](build.xml) (Phing) alongside the one-shot
`scripts/build-all.sh` pipeline. The Phing targets let you build (or package) one platform at a
time; `git` compiles **binaries only**, `package` also wraps them into the distributables in
**`build/dist/`**:

```bash
phing git                            # compile the native binaries for every platform (no packaging)
phing package                        # build AND package every platform into build/dist/
phing git-macos-arm                  # …or just one platform's binary (also -macos-x86, -win-x86,
phing package-linux-x86              #    -linux-x86, -linux-arm, -phar; and the package-* variants)
```

Equivalent Composer shortcuts: `composer start` (`phing run`), `composer build:git`
(`phing git`), `composer build:package` (`phing package`), and `composer build`
(`scripts/build-all.sh`). Both `build-all.sh` and the Phing `package-*` targets produce the same
artifacts through the same per-platform `scripts/make-*.sh` helpers:

| Platform | Artifact | Packaged by |
| --- | --- | --- |
| macOS (arm64, amd64) | `Grafida-<version>-macos-<arch>.dmg` (a `.app` inside) | `scripts/make-macos-app.sh` + `scripts/make-dmg.sh` *(macOS host only)* |
| Linux (amd64, arm64) | `Grafida-<version>-linux-<arch>.tar.gz` (binary + `.so` + assets + `install.sh`) | `scripts/make-linux-tarball.sh` |
| Windows (amd64) | `Grafida-<version>-windows-amd64-Setup.exe` (or a portable `.zip`) | `scripts/make-windows-installer.sh` (NSIS `makensis`) |
| Any | `Grafida-<version>.phar` | `scripts/make-phar-dist.sh` |

The Windows installer is built with **NSIS**, whose `makensis` compiler runs natively on
macOS and Linux (`brew install makensis`) — no Wine, Docker, or Windows host needed. If
`makensis` is absent the pipeline falls back to a portable `.zip`. The `.dmg` steps need
`hdiutil` and so only run on a macOS host.

**The application version is the topmost entry of the [`CHANGELOG`](CHANGELOG)** (e.g. `Grafida
0.1`). The Phing `git-*` targets stamp it into `App::VERSION` before compiling, so the binary and
the About dialog report it; set `GRAFIDA_VERSION=…` to override the CHANGELOG.

`boson compile` (under the hood) bundles a PHP runtime and produces a self-contained executable.
End users do not need PHP installed. The bundled language files and SQL migrations are extracted
once, on first launch, into the application data directory (because `parse_ini_file()`/`glob()`
cannot read from inside the packed binary).

The macOS packaging script applies an ad-hoc signature, which is enough to run the app
locally on the build machine.

> [!NOTE]
> **macOS Developer ID signing and notarisation work, but need a patched PHP runtime.** A stock
> `boson compile` produces a phpmicro self-executable whose PHP payload is appended *after* the
> binary's code-signature region; Apple's `codesign` requires the signature to be the trailing
> content of the file, so a stock build can never be signed. Grafida solves this with a patched
> phpmicro SFX (the [`nikosdion/phpmicro`](https://github.com/nikosdion/phpmicro) `sibling-phar`
> branch, built via static-php-cli and dropped into `build/sfx/`): the packaging script splits
> the compiled binary into a clean, signable Mach-O stub plus a sibling
> `Contents/Resources/grafida.phar` the stub loads at run time. Without the patched SFX in
> `build/sfx/`, builds fall back to the stock combined binary (ad-hoc signature only). Windows
> doesn't have this structural problem — Authenticode signatures live in a PE certificate table,
> not trailing file content — so the stock `boson compile` output signs directly; Linux is
> unaffected too (no OS-enforced binary-signature gate). Full recipe and technical analysis:
> [`build/readme/01-macos-signing.md`](build/readme/01-macos-signing.md) (macOS) and
> [`build/readme/04-exe-signing-on-macos.md`](build/readme/04-exe-signing-on-macos.md) (Windows,
> signed from a macOS/Linux build host via Azure Artifact Signing + Jsign).

### Application icons

The application icon (a pencil drawing a capital “J”) lives as a single master SVG at
`build/icon/grafida.svg`. Regenerate every per-platform format from it with:

```bash
scripts/make-icons.sh
```

This writes `build/icon/Grafida.icns` (macOS), `build/icon/Grafida.ico` (Windows) and a PNG
set under `build/icon/png/` (Linux), plus a 512px `build/icon/grafida.png`. The generated
files are committed, so you only need to re-run this after editing the SVG.

- **macOS** — `scripts/make-macos-app.sh` copies `Grafida.icns` into the bundle and references
  it from `Info.plist` automatically (regenerating it first if missing).
- **Windows** — embed `build/icon/Grafida.ico` into the compiled `grafida.exe`, e.g. with
  [`rcedit`](https://github.com/electron/rcedit): `rcedit grafida.exe --set-icon Grafida.ico`.
- **Linux** — install the PNGs into the hicolor icon theme (e.g.
  `build/icon/png/grafida-256.png` → `~/.local/share/icons/hicolor/256x256/apps/grafida.png`)
  and install `build/icon/grafida.desktop` (its `Icon=grafida` line resolves against the theme).

## Testing

```bash
composer test            # unit + feature + integration suites
composer linter:check    # PHPStan static analysis
```

The back-end is a pure `Request → Response` function, so the unit and feature suites run
without opening a window.

## Statement on the use of AI

We are using AI-powered agentic code assistants such as Claude Code, OpenAI Codex, Qwen Code, and JetBrains Junie to develop this software. Human developers do the engineering, have the final decision on the feature set and implementation path, and review the generated code.

## License

Grafida is free software, licensed under the **GNU General Public License version 3, or
later**. See [LICENSE.txt](LICENSE.txt).

```
Copyright (c) 2026 Nicholas K. Dionysopoulos

This program is free software: you can redistribute it and/or modify it under the terms of
the GNU General Public License as published by the Free Software Foundation, either version 3
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU General Public License for more details.
```
