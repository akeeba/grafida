# Grafida

**Edit Joomla! content on your desktop.**

Grafida is a cross-platform desktop application for creating and editing
[Joomla!](https://www.joomla.org) articles through the Joomla Web Services (REST) API.
Connect to one or more Joomla 5.4+ sites, write articles in a rich editor, work offline,
and publish when you are ready.

It is built with [Boson](https://bosonphp.com) (PHP on the desktop) and runs natively on
**macOS, Windows, and Linux**.

## Features

- **Multiple sites** — connect to several Joomla sites; API tokens are stored in your OS
  secret store (macOS Keychain, Windows DPAPI, Linux libsecret), never in plaintext unless
  you explicitly opt in.
- **Rich editing** — TinyMCE 7 with a custom *Read more* button that inserts the Joomla
  introtext/fulltext separator, styled with your site template's `editor.css`.
- **Categories, tags, and access levels** — picked from live, cached site data; new tags are
  created automatically on publish.
- **Custom fields** — edit the supported core field types; the app warns you when a required
  field uses a type only Joomla's backend can edit (and offers the article HTML to copy).
- **Media** — pick and upload to the Joomla Media Manager; images added offline are stored
  locally and uploaded automatically on publish.
- **Markdown import** — convert a Markdown file to HTML in one click.
- **Offline drafts** — everything is saved locally in SQLite; publishing is a deliberate action.
- **Translated** — English (en-GB) plus Greek, French, German, Spanish, Italian, and Portuguese,
  with automatic OS-language detection and a manual override.

## Requirements

- A Joomla **5.4 or later** site with the Web Services API enabled and an API token for a user
  who has the `core.login.api` permission.
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

For distribution, sign the macOS bundle with a Developer ID identity and notarise it (the
script only applies an ad-hoc signature, which is enough to run locally); sign the Windows
installer with `signtool`.

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
