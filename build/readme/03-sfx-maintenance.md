# SFX runtime maintenance playbook

Operational notes for the patched `micro.sfx` runtimes that make Grafida's
macOS builds code-signable. Background and rationale:
[`02-signing-architecture.md`](02-signing-architecture.md); Apple-side setup and
build recipe: [`01-macos-signing.md`](01-macos-signing.md).

The binaries are built by GitHub Actions on
[`nikosdion/phpmicro`](https://github.com/nikosdion/phpmicro) (branch
`sibling-phar`, workflow `.github/workflows/build-sfx.yml`) and published to the
rolling **`sfx-latest`** release. `scripts/fetch-sfx.sh` downloads them into the
gitignored `build/sfx/` — automatically from the Phing `prepare-sfx` step and
`scripts/build-all.sh`, best-effort, and it **never overwrites an existing
file** unless you pass `--force`.

## When Boson bumps its PHP version

Boson's runtime PHP version and our SFX **must match the same minor** (the SFX
*is* the PHP runtime; the phar only needs a compatible engine). When a Boson
update moves to a new PHP minor (e.g. 8.4 → 8.5):

1. Edit `PHP_VERSION` in the fork's `.github/workflows/build-sfx.yml` and push
   to `sibling-phar` → CI refreshes the `sfx-latest` release automatically.
2. On the build machine: `scripts/fetch-sfx.sh --force`.
3. Rebuild + sign, and run the app once (`phing package-macos-arm`, launch).

## When Boson changes its SFX extension list

Our `EXTENSIONS` in `build-sfx.yml` mirrors Boson's *standard* macOS edition:
`STANDARD_SFX_EXTENSIONS` in
`vendor/boson-php/compiler/src/Target/MacOSBuiltinTarget.php`. After a Boson
upgrade, diff that constant against the workflow's list; if it changed, update
the workflow, push, `fetch-sfx.sh --force`, rebuild, test.

## When bumping Boson itself

1. `composer update boson-php/*` as usual, then compare (as above): PHP version,
   extension list, and skim `AssemblyTargetTask` / `FindCustomSfxPathnameTask`
   in the compiler for changes to the assembly format or the custom-`sfx`
   config key our `compile-target.php` relies on.
2. Rebuild, sign, notarise, and launch-test. If anything smells wrong, verify
   the stub first (stale-binary check below), then run the app with
   `MICRO_TRACE_OPEN=1` to watch the payload-offset hooks.
3. Also re-test whether the detour is still needed at all — if a stock Boson
   binary ever signs cleanly, retire the fork (see the end of 01).

## Keeping the fork healthy

* **Rebase/merge upstream** (`static-php/phpmicro`, branch `master`) into
  `sibling-phar` occasionally — at the latest when bumping the PHP version,
  since new PHP minors usually need new upstream micro fixes. The patch
  surface is deliberately tiny: `php_micro_fileinfo.c` (sibling fallback,
  payload-path override) and `php_micro_hooks.c` (`MICRO_TRACE_OPEN`).
* Every push to `sibling-phar` rebuilds and republishes the SFX binaries. The
  CI smoke-tests appended, `<self>.phar` and `../Resources/` payload modes —
  a build from unpatched sources cannot reach the release.
* The workflow needs no secrets; it authenticates static-php-cli's tool
  downloads with the default `GITHUB_TOKEN` (without it, SPC hits the runners'
  anonymous GitHub API rate limit and fails in `doctor`).

## Building the SFX locally (CI bypass)

Full recipe in 01. Gotchas that will bite you:

* static-php-cli's source is named **`php-micro`** — a `micro:` prefix in
  `-G`/`-L` is **silently ignored** and you build *stock* phpmicro.
* SPC caches aggressively. When iterating on the C patch:
  `rm -rf downloads/php-micro source/php-src buildroot` before rebuilding.
* For local patch iteration prefer `-L "php-micro:/path/to/phpmicro"` (uses
  your working tree directly, no push needed). Note `-L` puts nothing in
  `downloads/` — verify the result *behaviourally* (sibling smoke test), not by
  grepping downloads.

## Troubleshooting checklist

1. **Stale binary?** The classic trap: a failed compile leaves the previous
   binary in `build/<os>/<arch>/`, and everything downstream happily signs the
   wrong bits. Verify:
   `head -c $(stat -f%z build/sfx/<sfx>) build/macos/<dir>/grafida | md5` must
   equal `md5 build/sfx/<sfx>`. (`compile-target.php` pre-cleans output dirs
   precisely to prevent this — do not bypass it by calling `boson compile`
   directly.)
2. **Phantom phar corruption** (`zlib: data error`, "actual filesize
   mismatch")? Some reads are missing the payload offset. Run with
   `MICRO_TRACE_OPEN=1` and check every open of the payload path reports
   `match=1`. Historic cause: a non-canonical (`..`) payload path.
3. **App won't start after splitting?** The stub was probably built from a
   stock SFX (no sibling fallback). `strings <stub> | grep -c "next to this
   executable"` — 0 means stock.
4. **`codesign` complains about a subcomponent?** A data file crept into
   `Contents/MacOS`. Only signed Mach-O files may live there.
5. **Verification commands** for a finished build: see "Verifying a finished
   build" in 01 (`codesign --verify --deep --strict`, `spctl -a --type install`,
   `stapler validate`).
