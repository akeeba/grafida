---
description: Grafida build, packaging, code signing (macOS notarisation + Windows Authenticode via the phpmicro sibling-phar SFX) and release process. Lifted verbatim from CLAUDE.md.
paths:
  - "build/**"
  - "scripts/**"
  - "build.xml"
  - "boson.json"
  - "composer.json"
  - "CHANGELOG"
  - "RELEASENOTES.md"
---

# Grafida build & packaging

Moved verbatim out of `CLAUDE.md` so it loads only when you are touching the build system.
Deeper signing documentation lives in `build/readme/01-macos-signing.md`,
`02-signing-architecture.md`, `03-sfx-maintenance.md` and `04-exe-signing-on-macos.md`.

## Build & packaging (one step)

**Front-end vendoring:** the build host needs `node`+`npm`. The npm-managed libraries (TinyMCE,
CodeMirror, FontAwesome — see `.claude/rules/spa-frontend.md`) are gitignored, so `build-all.sh`
runs `composer run-script vendor:assets` before compiling to populate `assets/private` (which
`boson.json` bundles at compile time).

`composer build` → `scripts/build-all.sh` is the **one-shot** compile-and-package pipeline; it
runs `boson compile` (every target in `boson.json`) then packages each platform into
**`build/dist/`** (gitignored) by delegating to the per-platform `scripts/make-*.sh` helpers (the
same ones the Phing `package-*` targets call — single source of truth). The version comes from
`App::VERSION` (override via `GRAFIDA_VERSION`). Per-platform packaging is tolerant (missing binary →
warn+skip), but a failing compile or a genuine packaging-tool error is fatal. Pieces:
- macOS (arm64+amd64, macOS host only): `scripts/make-macos-app.sh <arch>` assembles
  `build/macos/<bosondir>/Grafida.app` — Boson names the arm64 dir `aarch64`, amd64 stays
  `amd64` — then `scripts/make-dmg.sh <arch>` wraps it (via `hdiutil`) into
  `Grafida-<v>-macos-<arch>.dmg` with an `/Applications` symlink. **The DMG has a branded
  Finder layout**: it mounts the writable UDRW image, drops the background artwork into
  `.background/background.tiff`, then `osascript`s Finder to hide the toolbar, set a 640×400
  window with a 128px icon view, apply the background, and position the app at `{160,210}`
  next to `/Applications` at `{480,210}` (the background draws the header + a "drag onto
  Applications" arrow in the gap between them). It also writes a `.VolumeIcon.icns` — **after**
  the Finder styling, since opening the volume in Finder deletes a pre-existing one. The whole
  styling stage is **best-effort**: a missing asset or an `osascript` failure (e.g. the macOS
  automation-permission-to-control-Finder TCC prompt is denied) only warns and still emits a
  functional plain DMG. The background is a committed raster (`build/icon/dmg-background.{png,
  @2x.png,tiff}`) rendered from the single SVG master `build/icon/dmg-background.svg` by
  `scripts/make-dmg-background.sh` — a multi-resolution `.tiff` (`tiffutil -cathidpicheck`) for
  retina — following the same "SVG master → committed raster" pattern as the app icons;
  `scripts/make-icons.sh` invokes it too, so one command refreshes all visual assets. **Code signing works via a
  patched SFX runtime** — docs: `build/readme/01-macos-signing.md` (Apple setup + recipe +
  unsignability analysis), `02-signing-architecture.md` (architecture, why the fork exists,
  design decisions), `03-sfx-maintenance.md` (PHP/Boson bump playbook, troubleshooting): a
  `micro.sfx` built with static-php-cli from the `nikosdion/phpmicro` fork's `sibling-phar`
  branch (which adds an additive fallback — no appended payload → load `"<self>.phar"`, then
  `"../Resources/<self>.phar"`, realpath-canonicalised so the offset stream hooks keep
  matching) lives in the gitignored `build/sfx/<os>-<cpu>.standard.sfx`. **The fork's own
  GitHub Actions (`build-sfx.yml`) builds the SFX for macOS arm64+x86_64 AND Windows x86_64 on
  every push to `sibling-phar`** (via stock static-php-cli pointed at the repo with `-L`; Windows
  is a separate `build-windows` job — MSVC/php-sdk toolchain, PowerShell smoke test) and publishes
  them to the rolling `sfx-latest` release; `scripts/fetch-sfx.sh` downloads + SHA-256-verifies
  the assets (`macos-{aarch64,x86_64}` + `windows-x86_64`) into `build/sfx/` (never overwrites
  existing files; `--force` re-downloads) and runs automatically from the Phing `prepare-sfx` step
  (macOS git targets) and `build-all.sh` — best-effort, offline builds fall back to the stock runtime.
  `build/tasks/compile-target.php` injects the SFX as the Boson target's `sfx` when present,
  and pre-cleans the output dirs — Boson's cleanup chokes on the previous `Grafida.app`,
  silently leaving a stale binary; `build-all.sh` compiles through `compile-target.php --all`
  for the same reason. `make-macos-app.sh` detects the patched runtime, splits the
  compiled binary into a clean Mach-O stub (`Contents/MacOS/grafida`) + payload
  (`Contents/Resources/grafida.phar`; codesign refuses data files in `Contents/MacOS`, so
  `assets/` also live in Resources with a dylib symlink for the phar's mounts), and signs the
  whole bundle — ad-hoc by default, Developer ID + hardened runtime + notarisation when
  `MACOS_SIGN_IDENTITY`/`MACOS_NOTARY_PROFILE` are set (verified end-to-end: notarisation
  Accepted, `spctl` "Notarized Developer ID"). Without `build/sfx/` the legacy combined-binary
  layout is used and Developer-ID signing aborts with a clear error.
- Linux (amd64+arm64): `scripts/make-linux-tarball.sh <arch>` builds a `.tar.gz` of the per-arch
  output dir (binary + `libboson-linux-*.so` + `assets/`) plus the icon, `grafida.desktop`, and
  `build/linux-install.sh` (renamed `install.sh`) — a per-user XDG desktop-integration installer.
- Windows (amd64): `scripts/make-windows-installer.sh` compiles `build/windows-installer.nsi` with
  **NSIS** `makensis`, which runs natively on macOS/Linux (no Wine/Docker/Windows) →
  `Grafida-<v>-windows-amd64-Setup.exe` (per-user install in `%LOCALAPPDATA%\Programs\Grafida`).
  Falls back to a portable `.zip` if `makensis` is absent. **Authenticode signing works the same
  way as macOS — by splitting.** `boson compile` appends the PHAR after the PE stub; signing the
  combined binary appends the certificate *past* the PHAR and corrupts its trailing signature, so
  the app dies at startup on `Phar::mapPhar` ("grafida.exe has a broken signature"). So when the
  patched SFX (`build/sfx/windows-x86_64.standard.sfx`) is present, `make-windows-installer.sh`
  splits `grafida.exe` into a clean PE stub + sibling `grafida.phar` (offset from
  `build/tasks/pe-sfxsize.php`, which replicates phpmicro's `max(PointerToRawData+SizeOfRawData)`
  and asserts Boson's extra-ini magic `fd f6 69 e6` sits there), signs **only the stub** (Jsign/Azure
  Trusted Signing via `scripts/sign-windows-exe.sh`, gated on `WINDOWS_SIGN_OP_ITEM`), and NSIS
  ships both (`HAVE_PHAR`). `sign-windows-exe.sh` **refuses** to sign any PE still carrying a PHAR
  overlay (runs `pe-sfxsize.php` as a tripwire), so the combined binary can never be signed by
  accident; the installer `Setup.exe` (NSIS overlay, not a PHAR) signs fine. Without the patched
  SFX the unsigned combined binary ships (it works); if signing is configured but the SFX is
  missing, the script aborts rather than emit a broken signed binary. Docs:
  `build/readme/04-exe-signing-on-macos.md`, `02-signing-architecture.md`.
  **The installer bundles the Visual C++ 2015-2022 runtime app-local.** Boson's
  `libboson-windows-x86_64.dll` imports `MSVCP140*`/`VCRUNTIME140*`, which a clean Windows
  (especially Server) lacks — without them grafida.exe dies at startup with an FFI *"The
  specified module could not be found"*. Windows resolves a DLL's imports from the app dir first,
  so the four CRT DLLs ride next to grafida.exe (no admin, unlike the machine-wide redist). They
  are collected by the phpmicro `build-windows` CI (the only Windows box with VS) and published as
  `vc-runtime-x86_64.zip`; `scripts/fetch-sfx.sh` downloads + extracts them to `build/sfx/vc-runtime/`,
  `make-windows-installer.sh` copies them into the package dir, and NSIS ships every `*.dll` beside
  the exe (`File "${SRCDIR}/*.dll"` — libboson + the VC runtime). Best-effort: a build without the
  fetched runtime just omits them.
  **The flashing CMD window is suppressed at startup.** grafida.exe runs on a console-subsystem
  PHP runtime (the phpmicro SFX is a CLI build), so Windows gives it a console. `index.php` hides
  it immediately via FFI (`ShowWindow(GetConsoleWindow(), SW_HIDE)`), which also stops the
  per-click flashing: the console subprocesses the backend spawns (`Grafida\Secret\ProcessRunner`
  — the registry theme probe) **inherit** the hidden console instead of each popping a fresh visible
  one. **The secret store no longer spawns at all:** the old `WindowsSecretStore` shelled out to a
  whole `powershell.exe` (~1s cold start) for every DPAPI protect/unprotect, and because the
  `boson://` kernel is single-threaded that froze the UI on every request needing a stored secret
  (site token, AI key) — the multi-second stall. `Grafida\Secret\WindowsDpapi` now calls
  `crypt32.dll`'s `CryptProtectData`/`CryptUnprotectData` **directly via FFI** (sub-millisecond, no
  subprocess); it is byte-compatible with the .NET `ProtectedData` CurrentUser/no-entropy blob the
  PowerShell path wrote, so existing secrets keep working, and PowerShell remains a fallback only
  when FFI is unavailable. `WindowsSecretStore` also memoises decrypted secrets for the session (it
  is a container singleton) and no longer probes with `where powershell`. The registry theme probe
  (`DisplayModeService::windowsPrefersDark()`, on window focus) also **no longer spawns**: it reads
  the `AppsUseLightTheme` DWORD directly via FFI (`Grafida\Display\WindowsThemeReader`, calling
  `advapi32.dll`'s `RegGetValueA`), because `proc_open` does not pass `CREATE_NO_WINDOW` and a
  `reg.exe` child could still briefly flash a console even with the hidden console; `reg.exe` remains
  a fallback only when FFI is unavailable.
- PHAR: `scripts/make-phar-dist.sh` copies the compiler's `build/phar/grafida.phar` to `Grafida-<v>.phar`.

**⚠️ The minimum macOS version is set by the vendored library, not by us.** `boson-php/saucer`
ships a prebuilt `libboson-darwin-universal.dylib` which `boson compile` copies verbatim into
every macOS target (`vendor/boson-php/compiler/src/Target/BuiltinTarget.php` — there is no `sfx`-style
config key to override it), and upstream builds it on `macos-latest` with **no
`CMAKE_OSX_DEPLOYMENT_TARGET`**, so its floor is whatever SDK the runner had: currently `minos 15.0`,
importing a libc++ symbol that does not exist earlier. `vtool -set-build-version` cannot lower it —
the symbol dependency is real (gh-58). Our own artefacts are fine (`minos 12.0`).
So **`App::MIN_MACOS` is the single source of truth**, with three consumers: `make-macos-app.sh`
seds it into `Info.plist`'s `LSMinimumSystemVersion` (a failed read is **fatal** — an *empty* value
means "no minimum" to LaunchServices, which is how gh-58 got as far as launching and then crashing),
`Grafida\Startup\StartupCheck` enforces it at run time for the launches LaunchServices does not gate
(the PHAR, and the binary run from a terminal), and `tests/Unit/Startup/MacosDeploymentFloorTest.php`
fails the suite when the vendored library's floor rises above it — the blind spot that produced the
bug. It parses the Mach-O in PHP (`tests/Support/MachO.php`) rather than shelling out to `vtool`, so
the guard also fires on a Linux or Windows `composer test`. See the checklist step in
`build/readme/03-sfx-maintenance.md`. Escape hatch for a self-built library: `GRAFIDA_BOSON_LIBRARY`
(feeds `ApplicationCreateInfo::$library`), which also switches the run-time gate off.

**Binaries-only build (no packaging):** `build.xml` (root) is a **Phing** buildfile whose default
target `git` (also `composer build:git`) compiles the native binary for **every** platform but stops
short of the installers/DMG — `git` depends on six per-platform targets (`git-macos-arm`,
`git-macos-x86`, `git-win-x86`, `git-linux-x86`, `git-linux-arm`, `git-phar`). **Phing is expected as a
globally-installed command** (`phing` on the PATH — like the other Akeeba projects; it is deliberately
*not* a Composer dev dependency), so `composer build:git` just shells out to `phing git`. Because
`boson compile` builds *all* `boson.json` targets in one pass with no per-OS CLI flag, each target
shells out to `build/tasks/compile-target.php`, which filters the master `boson.json` down to the one
requested `--type`/`--arch` at runtime (pinning an explicit `root` so the throwaway single-target config
can live in `build/.temp/`), drops the stale box/entrypoint cache, then runs `boson compile
--config=<temp>`. All six depend on a guarded `prepare` (sub-targets `prepare-composer` +
`prepare-icons` + `prepare-assets`) that, only when their output is missing, runs `composer install`
(so a fresh `git clone … && phing` bootstraps itself — and since Composer's post-install-cmd runs
`vendor:assets`, that also vendors the front-end libraries), re-rasterises the icons, and vendors the
front-end libraries (force a re-vendor with `-Drefresh.assets=1`); Phing runs `prepare` once per
invocation. `prepare` also runs **`set-version`** (`build/tasks/set-version.php`): the **`CHANGELOG`
is the single source of truth for the version** — its topmost entry's heading ends with the version
number (Akeeba convention, e.g. `Grafida 0.1`; parsed like Akeeba's `AutoVersionTask`), and the step
stamps it into `App::VERSION` in `src/Support/App.php` before every compile (idempotent; no-ops when
already current). `GRAFIDA_VERSION` overrides the CHANGELOG. So every `git-*` build (and transitively
`package-*`/`run`) reports the CHANGELOG version in the binary and the About dialog.

**Private build configuration:** `build.xml` loads `build/build.properties` (gitignored — holds
secrets, never committed; a missing file is tolerated). The committed `build/build.sample.properties`
is the template (`cp` it to `build/build.properties` and fill in). It carries the plumbing for the
not-yet-built update mechanism: GitHub Releases (`github.organization`, `github.repository`,
`github.token` — the PAT) and CDN upload over FTP (`cdn.ftp.hostname`, `cdn.ftp.username`,
`cdn.ftp.password`, `cdn.ftp.directory`). The plan: publish a release to GitHub Releases, then use the
`build/tasks/UpdateJson.php` Phing task (organization/repository/token/outfile attributes) to fetch the
latest release's metadata into an `update.json` and upload it to the CDN. (`build/.gitignore` ignores
everything under `build/` except a whitelist, so `build.properties` is ignored automatically; the
sample is explicitly whitelisted.)

**Packaged build via Phing:** the `package` target (also `composer build:package`) builds *and*
packages every platform into `build/dist/` — it depends on six per-platform `package-*` targets
(`package-macos-arm/-x86`, `package-win-x86`, `package-linux-x86/-arm`, `package-phar`), and each
`package-X` depends on its matching `git-X` (so it compiles the binary first) then shells out to the
relevant `scripts/make-*.sh` helper. This is the Phing equivalent of `scripts/build-all.sh`
(`composer build`); both produce the same artifacts through the same per-platform scripts, so use
whichever entry point you prefer (`build-all.sh` adds a tolerant warn-and-continue summary across all
platforms, the Phing targets let you build/package a single platform on demand).

**Run on this host:** the `run` target (also `composer start`) compiles the binary for the *current*
host and launches it. Since Phing `depends` is static, `run` resolves the host's OS+arch at runtime
(`<os family>` + `uname -m`) into `run.*` properties, dispatches the matching `git-*` compile with
`<phingcall>`, then executes the bare self-contained binary directly from its output dir (e.g.
`build/macos/aarch64/grafida`; `grafida.exe` on Windows) — *not* the `.app`/installer, which belong to
the `package-*` targets. macOS arm64→`git-macos-arm`, macOS x86_64→`git-macos-x86`,
Linux aarch64→`git-linux-arm`, Linux x86_64→`git-linux-x86`, Windows→`git-win-x86`; an unrecognised
host fails with a clear message.

**Tests:** the `tests` target (depends only on `prepare-composer`, not the full `prepare`) runs
`composer test` — the PHPUnit suites (unit + integration + feature) **and `test:js`**. `phpunit.xml`
sets `failOnEmptyTestSuite="false"` because `tests/Integration/` was originally scaffolding only;
without that flag PHPUnit fails the whole run on an empty suite before the feature suite executes.
- **`composer test:js`** (`node --test 'tests/js/**/*.test.mjs'`) covers the SPA modules PHPUnit
  **cannot** reach: `assets/private/js/ai/providers.js` (the AI transport — the provider call runs in
  the SPA, see the AI facts), `assets/private/js/editor/slashtools.js` (the slash-command menu),
  `assets/private/js/editor/csstheme.js` (the editor colour-scheme rewriter, gh-38), and
  `assets/private/js/editor/localmedia.js` (the `boson://app/api/media/{id}/raw?rev=…` URL
  builder/parser, gh-36 — its own synchronous SHA-1 is what the rev token is verified against —
  **plus**, since gh-43, `fitDimensions()`, the JS half of the image-resync sizing rule whose PHP
  twin is `Grafida\Media\ImageDimensions::fit()`, see `.claude/rules/media-and-publish.md`).
  For all four it is the only automated coverage. It uses node's built-in test runner and loads the
  browser IIFE in a `vm` context with fakes for the globals app.js supplies (`window`/`fetch`/`api`,
  or `State`/`t`/`editor`); no bundler and no new dependency (node is already a build prerequisite).
  ⚠️ **The sandbox is its own realm**, which bites twice: providers.js detects a CORS failure with
  `err instanceof TypeError`, so a stub must mint that error **inside** the sandbox or the fallback
  never triggers; and a value *returned* from the sandbox (slashtools' `fetchItems()` array) fails a
  strict deep-equal against an outer-realm literal on the prototype alone, so re-home it first
  (`Array.from()`) or compare field by field.
- **`tests/Integration/Ai/ResponsesApiLiveTest.php`** talks to a **real** OpenAI *Responses API*
  server. It pins the wire-format assumptions providers.js is built on (the `output[]`→`output_text`
  shape, `instructions`, the typed SSE events with **no `[DONE]`**, and that a `previous_response_id`
  really does resume server-side and a stale one really is rejected) — if OpenAI changes the shape,
  the JS would break silently in the webview; this fails loudly instead. It is **skipped unless
  configured** via `GRAFIDA_TEST_RESPONSES_ENDPOINT` + `_MODEL` (+ `_KEY` for a hosted provider,
  `_PROVIDER` to override the providers.json key). A local LM Studio server works as the endpoint.
- **Test configuration lives in `tests/.env`** (gitignored — it holds provider credentials); copy
  `tests/.env.sample` to create it. `tests/bootstrap.php` (the PHPUnit `bootstrap`) loads it with
  **symfony/dotenv** (a dev dependency), and a variable exported in the real environment still wins,
  so `FOO=bar composer test` overrides the file. **`tests/README.md` documents all of this** — the
  suites, how to configure and run the live tests, and the two traps in them (a local server may
  *ignore* an unknown `previous_response_id` rather than rejecting it, and these tests go through PHP
  so they do not exercise the CORS/ATS constraints the SPA hits).

**Release:** `all` is an alias for `package`. `release` (depends on `all`) is the standard release
process: build+package every platform, then (1) create a **published GitHub release** with the
installers/DMGs/PHAR as assets and `RELEASENOTES.md` as the description, (2) build `grafida.json` from
that release, and (3) upload it to the CDN over **FTPS**. It needs the `github.*` + `cdn.ftp.*`
properties from `build/build.properties` (it fails early with a clear message if they're unset). Three
custom Phing tasks under `build/tasks/` (namespace `tasks\`, taskdef'd with `classpath="…/build"`)
back it, all curl-based (no extra binaries, matching `UpdateJson.php`): **`GitHubRelease`** (creates a
draft release, uploads each nested-`<fileset>` asset, then publishes — so a partial release is never
visible), **`UpdateJson`** (fetches the latest release's metadata into `grafida.json`), and
**`FtpsUpload`** (uploads over explicit FTPS — `CURLUSESSL_ALL`). The version comes from the CHANGELOG
via `set-version.php --print`. `UpdateJson` treats a release as downloadable when it has any asset
ending in `.zip`/`.exe`/`.dmg`/`.tar.gz` (`UpdateJson::ASSET_EXTENSIONS` / `isDownloadableAsset()`); its
`grafida.json` `download` field is provisionally the **first** such asset — a real per-platform download
map is for when the update mechanism itself is built.

## Release procedure

The exact procedure that worked for 0.1; it refines the generic Akeeba release-workflow skill.
The version comes from the CHANGELOG's top entry; `-Dversion=X.Y.Z` overrides it and stamps
`App::VERSION`.

1. **Release notes.** `RELEASENOTES.md` (GitHub-flavoured Markdown) must target the upcoming
   version, reviewed against the CHANGELOG. Its Downloads table must use **linked** filenames
   (see "Release-notes downloads" below).
2. **Check credentials.** `build/build.properties` must have non-blank `github.*` and `cdn.ftp.*`
   values, or the release fails early.
3. **Full packaging build.** Clear stale `build/dist/*` first, then `phing package -Dversion=X.Y.Z`.
   This produces the real installers/DMG/tarballs/PHAR (macOS DMGs notarised, Windows
   Authenticode-signed). ⚠️ **Do NOT use `phing git` for the test build** — it emits bare binaries
   only, so installation cannot be tested.
4. ⛔ **HUMAN-VERIFICATION BREAKPOINT — the key step.** Stop after step 3 and wait for the human to
   install and test the **fully packaged artifacts in `build/dist/`** (the DMG/installer), *not*
   the bare `phing git` binaries. Do not tag or publish until they confirm. The breakpoint belongs
   here, after full packaging — putting it earlier, at the bare-binary stage, tests nothing that
   a user will actually run.
5. **Commit** — only if the working tree is dirty. `set-version` is idempotent, so a CHANGELOG that
   already names the version leaves nothing to commit and this step is skipped.
6. **Tag:** `git tag X.Y.Z -sm "Tagging X.Y.Z"` (signed), then `git push origin X.Y.Z`.
7. **Publish:** `phing release -Dversion=X.Y.Z` — repackages all platforms, creates and publishes a
   GitHub release with the six assets, generates `grafida.json`, uploads it over FTPS to the
   BunnyCDN `updates/` directory, and finally pushes `docs/` to the GitHub wiki.
8. **Verify:** `curl https://cdn.akeeba.com/updates/grafida.json` reports the new version, and
   `gh release view X.Y.Z --repo akeeba/grafida` shows `draft=false` with all six assets.

The wiki step is **last on purpose**: it is the only step writing to a repository other than the
release itself, and a wiki that lags the release by a minute is a far smaller problem than a
release that never got published because a wiki clone failed. It is also a standalone target —
`phing wiki` / `composer docs:wiki` — so a documentation fix does not have to wait for a version
bump. See `.claude/rules/documentation.md`; the one thing to know from here is that the wiki is a
**separate git repository** (`akeeba/grafida.wiki.git`) which GitHub creates lazily, so the very
first run needs one page saved through the web UI or the clone fails.

⚠️ **Non-fatal noise to expect:** the Windows `signtool verify` step prints `Timestamp Server
Signature verification: failed` / `Signature verification: failed` when run on macOS. The Azure
Trusted Signing signature **is** applied (the log shows `Signed: …Setup.exe`); this is a local
trust-chain resolution quirk, not a signing failure. Do not treat it as a build break.

The whole flow builds no feature branch — commit on `main`.

## Release-notes downloads

In `RELEASENOTES.md` the Downloads table must **link** each filename to its GitHub release asset,
not list bare filenames, so a reader can click straight through. The URL is entirely predictable —
`https://github.com/akeeba/grafida/releases/download/<TAG>/<FILENAME>`, where `<TAG>` is the version
number (e.g. `0.1`). So a row is:

```
| macOS (Apple Silicon) | [`Grafida-<v>-macos-arm64.dmg`](https://github.com/akeeba/grafida/releases/download/<v>/Grafida-<v>-macos-arm64.dmg) |
```

Do this for every platform row when writing release notes for a new version.

## CHANGELOG style

Entries are **terse** — one short line each. No prose sentences, no "because…"/"so that…"
explanations, no restating what the feature does in three clauses. The CHANGELOG is user-facing
release material (it also drives RELEASENOTES and is the version source of truth), and readers
scan it.

Within each version block, entries are **grouped and ordered by significance**:

1. `!` — critical / breaking
2. `+` — new features
3. `-` — removals
4. `~` — changes to existing behaviour
5. `# [HIGH]` — high-severity bug fixes
6. `# [MEDIUM]`
7. `# [LOW]`

When adding an entry, write the shortest line that identifies the change, then **place it in its
group** — do not simply append to the end of the block.
