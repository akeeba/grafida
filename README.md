# Grafida

**Edit Joomla! content on your desktop.**

Grafida is a cross-platform desktop application for creating and editing
[Joomla!](https://www.joomla.org) articles through the Joomla Web Services (REST) API.
Connect to one or more Joomla 5.4+ sites, write articles in a rich editor, work offline,
and publish when you are ready.

It is built with [Boson](https://bosonphp.com) (PHP on the desktop) and runs natively on
**macOS, Windows and Linux**.

## Features

- **Multiple sites** — connect to several Joomla sites; API tokens are stored in your OS
  secret store (macOS Keychain, Windows DPAPI, Linux libsecret), never in plaintext unless
  you explicitly opt in.
- **Rich editing** — TinyMCE 7 with a custom *Read more* button that inserts the Joomla
  introtext/fulltext separator, styled with your site template's `editor.css`.
- **Categories, tags and access levels** — picked from live, cached site data; new tags are
  created automatically on publish.
- **Custom fields** — edit the supported core field types; the app warns you when a required
  field uses a type only Joomla's backend can edit (and offers the article HTML to copy).
- **Media** — pick and upload to the Joomla Media Manager; images added offline are stored
  locally and uploaded automatically on publish.
- **Markdown import** — convert a Markdown file to HTML in one click.
- **Offline drafts** — everything is saved locally in SQLite; publishing is a deliberate action.
- **Translated** — English (en-GB) plus Greek, French, German, Spanish, Italian and Portuguese,
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
3. Choose a site, then browse its articles or start a **New article**.
4. Pick a category, access level and tags; write your content; insert a **Read more** break
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

# Build a native binary for the current platform:
composer compile                     # all targets in boson.json, output in ./build

# macOS only — build just this platform, then wrap it in a .app bundle:
php vendor/bin/boson compile -c boson.macos.json
scripts/make-macos-app.sh            # produces build/macos/Grafida.app
```

`boson compile` bundles a PHP runtime and produces a self-contained executable. End users do
not need PHP installed. On macOS, `scripts/make-macos-app.sh` assembles the bare binary into a
standard `Grafida.app` (Info.plist + `Contents/MacOS`). The bundled language files and SQL
migrations are extracted once, on first launch, into the application data directory (because
`parse_ini_file()`/`glob()` cannot read from inside the packed binary).

For distribution, sign the bundle with a Developer ID identity and notarise it (the script
only applies an ad-hoc signature, which is enough to run locally).

## Testing

```bash
composer test            # unit + feature + integration suites
composer linter:check    # PHPStan static analysis
```

The back-end is a pure `Request → Response` function, so the unit and feature suites run
without opening a window.

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
