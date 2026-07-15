# Code-signing architecture — why there is a phpmicro fork

This is the anchor document for how Grafida's binaries become code-signable, why
a **forked phpmicro** is involved, and which design decisions were made on
purpose. Read this before touching anything signing-related, bumping Boson, or
wondering "can we get rid of that fork yet?". Companion documents:

* [`01-macos-signing.md`](01-macos-signing.md) — the Apple-side setup
  (certificates, notarisation), the build recipe, and the low-level analysis of
  why a stock Boson binary cannot be signed.
* [`03-sfx-maintenance.md`](03-sfx-maintenance.md) — the operational playbook
  (PHP version bumps, extension list changes, rebasing the fork, troubleshooting).

## The problem, in one paragraph

`boson compile` produces a **phpmicro self-executable**: a native Mach-O/PE stub
with the application PHAR **concatenated after it** — and therefore *after the
binary's code-signature region*. Apple's `codesign` requires the signature to be
the trailing content of the file, so the combined binary fails signing with
"main executable failed strict validation". The payload cannot be moved inside
the Mach-O either (the Node.js/postject trick), because stock phpmicro locates
it as "Mach-O image end → EOF" — the two constraints are mutually exclusive.
Full empirical analysis: `01-macos-signing.md`, "Why a stock Boson binary cannot
be signed".

## The solution, in one paragraph

We patch **phpmicro** (the ~5-file C SAPI that is the stub) with a small,
additive fallback: **when the executable carries no appended payload, load it
from a sibling file** — `"<self>.phar"`, then `"../Resources/<basename>.phar"`
(the macOS app-bundle layout). The macOS packaging script then *splits* the
Boson-compiled binary: the clean, signable stub becomes
`Contents/MacOS/grafida`, the payload becomes
`Contents/Resources/grafida.phar`, and the whole bundle signs, notarises and
staples like any normal app. Appended-payload mode is untouched, so the same
patched runtime works identically on Linux/Windows and in unsigned dev builds.

## The moving parts

```
nikosdion/phpmicro (branch sibling-phar)        ← the C patch lives here
  └─ .github/workflows/build-sfx.yml            ← CI: builds micro.sfx per platform
       │  (stock static-php-cli, pointed at the repo itself via -L)
       ▼
  GitHub release "sfx-latest" (rolling)         ← macos-{aarch64,x86_64}.standard.sfx
       │                                           + windows-x86_64.standard.sfx + .sha256
       ▼  scripts/fetch-sfx.sh                  ← download + SHA-256 verify (auto via
       │                                           Phing prepare-sfx / build-all.sh)
       ▼
  build/sfx/<os>-<cpu>.standard.sfx             ← gitignored, per-machine
       │
       ▼  build/tasks/compile-target.php        ← injects target `sfx` key when present;
       │                                           pre-cleans output dirs; --all for build-all.sh
       ▼
  boson compile                                 ← stub = OUR runtime, payload appended as usual
       │
       ├─▼  scripts/make-macos-app.sh           ← detects patched runtime, SPLITS stub/payload,
       │  │                                        Resources layout, signs (ad-hoc or Dev-ID)
       │  ▼  scripts/make-dmg.sh                ← DMG, notarytool submit, staple
       │
       └─▼  scripts/make-windows-installer.sh   ← detects patched runtime, SPLITS grafida.exe into
          │                                        a clean stub + sibling grafida.phar (offset from
          │                                        build/tasks/pe-sfxsize.php), signs ONLY the stub
          │                                        (Authenticode via Jsign — sign-windows-exe.sh),
          ▼                                        NSIS bundles both (HAVE_PHAR)
       Grafida-<v>-windows-amd64-Setup.exe      ← installer PE also signed (no PHAR overlay)
```

The Windows split is the exact analogue of the macOS one. `pe-sfxsize.php`
computes the split offset the same way phpmicro's `_micro_init_sfxsize()` does at
run time — `max(PointerToRawData + SizeOfRawData)` over the PE sections — and
asserts Boson's extra-ini magic (`fd f6 69 e6`) sits there before splitting, so a
wrong boundary can never ship. `sign-windows-exe.sh` additionally **refuses** any
PE that still carries a PHAR overlay (it runs `pe-sfxsize.php` as a tripwire), so
the combined binary can never be signed by accident again.

Key properties:

* **Everything degrades gracefully.** No `build/sfx/` (offline, fresh machine,
  fetch failure) → stock Boson runtime, legacy combined binary, ad-hoc
  signature; Developer-ID signing aborts with an explanatory error instead of a
  cryptic codesign failure.
* **The committed `boson.json` is untouched.** The `sfx` key is injected into
  the throwaway per-build config only when the file actually exists (Boson
  errors out on a dangling `sfx` path).
* **The patch is the only fork content that matters.** The SFX is otherwise a
  100% stock static-php-cli build (PHP 8.4, Boson's *standard* macOS extension
  list, enumerated in `vendor/boson-php/compiler/src/Target/MacOSBuiltinTarget.php`).

## Why a fork — and why *this* fork

* **Why fork phpmicro at all?** The fix must live in the stub's C code (where
  the payload is located at runtime). No packaging trick can work: signing
  requires no trailing data, stock phpmicro requires trailing data — verified
  empirically (see 01). Upstream had no external-payload mode.
* **Why fork `static-php/phpmicro` and not `dixyes/phpmicro`?** Because
  static-php-cli (the only practical way to build micro) pins its `php-micro`
  source to `static-php/phpmicro`. Forking what actually gets built avoids any
  drift between "the code we patched" and "the code SPC compiles".
* **Why NOT fork `boson-php/backend-src`?** It was considered (it is Boson's
  own SPC fork, from whose releases Boson downloads its stock SFX binaries).
  Unnecessary: a stock crazywhalecc/static-php-cli build of the patched micro
  works — the notarised app runs — so there is nothing in Boson's SPC fork we
  need. Revisit only if a Boson runtime ever behaves differently from our
  stock-SPC build.
* **Why does the CI live on the phpmicro fork and not in Grafida?** The SFX is
  a pure function of (phpmicro source, PHP version, extension list) — nothing
  Grafida-specific. Building it where the source lives means every push to
  `sibling-phar` refreshes the binaries for all platforms, and Grafida only
  ever *downloads*. Note phpmicro cannot build itself (it is a PHP SAPI); the
  workflow checks out static-php-cli as a tool and points it back at the repo
  with `-L "php-micro:$GITHUB_WORKSPACE"`.
* **Why is signing NOT done in that CI?** Signing needs the Developer ID
  certificate/keychain, the notary profile, and the app payload — all of which
  live on the release build machine. The fork only produces the unsigned,
  app-agnostic runtime; Grafida's packaging replaces its ad-hoc signature
  anyway.
* **Why not upstream the patch?** Deliberately deferred until battle-tested.
  When the time comes: the patch is `php_micro_fileinfo.c` +
  `php_micro_hooks.c` (the `MICRO_TRACE_OPEN` aid) on `nikosdion/phpmicro`;
  candidates are a PR to `static-php/phpmicro` (and/or `dixyes/phpmicro`), and
  pointing Boson at it. If upstream ever ships an equivalent (or Boson emits a
  signable layout natively), the fork, the CI, `fetch-sfx.sh` and the split
  logic can all be retired — see the re-test note at the end of 01.

## Non-obvious design decisions (learned the hard way)

1. **The sibling path is `realpath()`-canonicalised in the C patch.** phpmicro's
   offset stream hooks match the payload file by *exact path string*; PHP
   normalises paths internally. The `..` in the `../Resources` candidate made
   *some* phar reads miss the offset hooks → phantom `zlib: data error` /
   "internal corruption of phar" failures that cost hours. Never construct the
   payload path with `..` left in.
2. **Data files must live in `Contents/Resources`.** `codesign` refuses any
   non-code file inside `Contents/MacOS` ("code object is not signed at all").
   Consequently the phar's `__DIR__` is Resources, and Boson's generated
   entrypoint mounts `assets/public` + the libboson dylib relative to the phar
   — so `assets/` are copied to Resources and the dylib (a *real*, signed file
   in MacOS) gets a **symlink** in Resources.
3. **The split payload is not a bare phar.** Boson appends
   `[\xfd\xf6\x69\xe6][4-byte len][ini]` (the boson.json `ini` settings) before
   the PHAR. The patched stub parses this extra-INI block in sibling mode
   exactly as it does in appended mode — do not "clean" the split file down to
   the phar bytes.
4. **Boson's compile cleanup chokes on the previous `Grafida.app`** (it cannot
   delete the symlink-containing bundle) and aborts — historically *silently
   leaving a stale binary* that the packaging then signed. `compile-target.php`
   pre-cleans every target output dir, and `build-all.sh` compiles through
   `compile-target.php --all` for the same reason. When behaviour ever seems to
   contradict the code you just changed, first check the stub:
   `head -c $(stat -f%z build/sfx/<sfx>) <binary> | md5` must equal
   `md5 build/sfx/<sfx>`.
5. **static-php-cli's micro source is named `php-micro`**, not `micro`. A
   `-G "micro:…"`/`-L "micro:…"` override is **silently ignored** and you build
   stock phpmicro. Always verify the patch is present in what you built — the
   CI's sibling-mode smoke tests exist precisely for this.
6. **Debug aid:** any patched binary logs every plain-file open and whether the
   payload-offset hooks engaged when run with `MICRO_TRACE_OPEN=1`.

## Platform status

| Platform | Status |
| --- | --- |
| macOS arm64 | **Signed + notarised, verified end-to-end** (CI-built SFX, split bundle, `spctl`: "Notarized Developer ID") |
| macOS x86_64 | SFX built by CI, sibling mode verified under Rosetta; full sign/notarise pass still to be exercised on a real build |
| Windows | **Authenticode-signed via split** (Jsign/Azure Trusted Signing). Same trailing-data problem, same cure: the phpmicro `PHP_WIN32` branch now has the sibling-payload fallback, the CI builds `windows-x86_64.standard.sfx`, and `make-windows-installer.sh` splits `grafida.exe` into a signed stub + sibling `grafida.phar`. Signing the *combined* binary corrupts the PHAR's trailing signature — it fails at startup on `Phar::mapPhar` ("grafida.exe has a broken signature"); `sign-windows-exe.sh` now refuses to sign a PHAR-bearing PE |
| Linux | No OS-enforced signature gate — nothing to do; stock behaviour everywhere |
