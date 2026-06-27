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
extensions, plus [Composer](https://getcomposer.org).

```bash
git clone https://github.com/akeeba/grafida.git
cd grafida
composer install

# Run in development (opens the app window):
php index.php

# One step: compile AND package every platform's distributable.
composer build                       # artifacts land in build/dist/
```

`composer build` (which runs `scripts/build-all.sh`) is the one-shot pipeline. It compiles
the self-contained binaries for every target in `boson.json` and then packages each one,
writing the results to **`build/dist/`**:

| Platform | Artifact | Built by |
| --- | --- | --- |
| macOS (arm64, amd64) | `Grafida-<version>-macos-<arch>.dmg` (a `.app` inside) | `scripts/make-macos-app.sh` + `scripts/make-dmg.sh` *(macOS host only)* |
| Linux (amd64, arm64) | `Grafida-<version>-linux-<arch>.tar.gz` (binary + `.so` + assets + `install.sh`) | `scripts/build-all.sh` + `build/linux-install.sh` |
| Windows (amd64) | `Grafida-<version>-windows-amd64-Setup.exe` (or a portable `.zip`) | `build/windows-installer.nsi` via `makensis` |
| Any | `Grafida-<version>.phar` | the Boson compiler |

The Windows installer is built with **NSIS**, whose `makensis` compiler runs natively on
macOS and Linux (`brew install makensis`) — no Wine, Docker, or Windows host needed. If
`makensis` is absent the pipeline falls back to a portable `.zip`. The `.dmg` steps need
`hdiutil` and so only run on a macOS host; the version is read from `App::VERSION` (override
with `GRAFIDA_VERSION=…`).

You can also run individual stages:

```bash
composer compile                     # binaries only, into ./build
php vendor/bin/boson compile -c boson.macos.json   # just this platform
scripts/make-macos-app.sh            # wrap the macOS binary in build/macos/<arch>/Grafida.app
```

`boson compile` bundles a PHP runtime and produces a self-contained executable. End users do
not need PHP installed. The bundled language files and SQL migrations are extracted once, on
first launch, into the application data directory (because `parse_ini_file()`/`glob()` cannot
read from inside the packed binary).

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
