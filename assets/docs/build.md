# Building from source

Grafida needs **PHP 8.4+** with the `ffi`, `pdo_sqlite`, `dom`, `mbstring` and `curl`
extensions, plus [Composer](https://getcomposer.org), [Node.js + npm](https://nodejs.org)
(the front-end libraries are vendored via npm, not committed) and
[Phing](https://www.phing.info) installed as a **global** command.

```bash
git clone https://github.com/grafida/grafida.git
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

**Bringing your own webview library.** The minimum macOS version above is not ours to choose: the
`boson-php/saucer` package ships a prebuilt `libboson-darwin-universal.dylib` linked with a
deployment target of macOS 15, and the compiler copies it verbatim into every macOS binary. The
minimum lives in one place, `App::MIN_MACOS`, from where `scripts/make-macos-app.sh` writes it into
the bundle's `LSMinimumSystemVersion` and a start-up check enforces it; a unit test fails if the
vendored library's floor ever rises above it. If you re-link that library for an older system, set
`GRAFIDA_BOSON_LIBRARY=/path/to/libboson.dylib` and Grafida will load yours and skip the macOS
version check. Note that `LSMinimumSystemVersion` is enforced by LaunchServices *before* PHP runs,
so this unlocks `Grafida.app/Contents/MacOS/grafida` and the PHAR, not a Finder double-click of the
shipped `.app`.

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

## Application icons

The application icon (a pencil drawing a capital “J”) lives as a single master SVG at
`assets/logo/grafida.svg`. Regenerate every per-platform format from it with:

```bash
scripts/make-icons.sh
```

This writes `assets/logo/Grafida.icns` (macOS), `assets/logo/Grafida.ico` (Windows) and a PNG
set under `assets/logo/png/` (Linux), plus a 512px `assets/logo/grafida.png`. The generated
files are committed, so you only need to re-run this after editing the SVG.

- **macOS** — `scripts/make-macos-app.sh` copies `Grafida.icns` into the bundle and references
  it from `Info.plist` automatically (regenerating it first if missing).
- **Windows** — embed `assets/logo/Grafida.ico` into the compiled `grafida.exe`, e.g. with
  [`rcedit`](https://github.com/electron/rcedit): `rcedit grafida.exe --set-icon Grafida.ico`.
- **Linux** — install the PNGs into the hicolor icon theme (e.g.
  `assets/logo/png/grafida-256.png` → `~/.local/share/icons/hicolor/256x256/apps/grafida.png`)
  and install `assets/logo/grafida.desktop` (its `Icon=grafida` line resolves against the theme).

## Documentation

The Markdown pages under `docs/` are both the in-app Help screen and the Documentation section of
[Grafida.app](https://grafida.app). Publish the site copy with:

```bash
composer docs:publish -- --dry-run   # show what would be sent, connect to nothing
composer docs:publish                # upload over SFTP
```

This is deliberately not a release step: the app serves Help out of its own binary, so a corrected
page is worth publishing the day it is committed. Configure the destination with the `docs.sftp.*`
properties in `build/build.properties` — see
[`build/readme/05-documentation-publishing.md`](build/readme/05-documentation-publishing.md).
