#!/usr/bin/env bash
# ============================================================================
# Grafida — build & package everything, for every supported platform.
# Joomla content editing, untethered.
#
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# License: GNU General Public License version 3, or later
#
# This is the one-shot build pipeline invoked by `composer build`. It:
#
#   1. Compiles self-contained binaries for every target in boson.json
#      (php vendor/bin/boson compile → macOS arm64/amd64, Windows amd64,
#      Linux amd64/arm64, PHAR).
#   2. Packages each platform's distributable into build/dist/:
#        - macOS  : .app bundle + compressed .dmg  (per arch; macOS host only)
#        - Linux  : .tar.gz of the binary + runtime .so + assets + a per-user
#                   desktop installer (build/linux-install.sh)
#        - Windows: NSIS installer via native `makensis` (cross-compiles on
#                   macOS/Linux); falls back to a portable .zip if absent
#        - PHAR   : the cross-platform grafida.phar
#
# Design notes (mirrors the `composer update`-friendly behaviour of sibling
# projects):
#   - If the Boson compiler is absent (e.g. installed --no-dev), the script
#     no-ops with a friendly message and exit 0.
#   - A failing `boson compile` is fatal (exit 1). Per-platform packaging is
#     tolerant: a missing binary is a skip (warning), but a packaging tool that
#     genuinely errors marks the run as failed (exit 1) so problems stay visible.
# ============================================================================

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$SCRIPT_DIR")"
cd "$ROOT"

BOSON="vendor/bin/boson"
DIST="build/dist"
ICON_DIR="assets/logo"
VERSION="${GRAFIDA_VERSION:-$(sed -nE "s/.*VERSION = '([^']+)'.*/\1/p" src/Support/App.php | head -1)}"

PRODUCED=()
WARNINGS=()
FAIL=0

heading() { printf '\n==> %s\n' "$*"; }
warn()    { echo "  WARNING: $*" >&2; WARNINGS+=("$*"); }

# ---------------------------------------------------------------------------
# 0. Guard — is the Boson compiler available? (skip cleanly if not)
# ---------------------------------------------------------------------------
if [[ ! -f "$BOSON" ]]; then
    echo "Grafida: '$BOSON' not found — skipping build/package."
    echo "  Run 'composer install' (with dev dependencies) to enable building."
    exit 0
fi

# Make sure the per-platform icons exist (regenerated from the master SVG).
if [[ ! -f "$ICON_DIR/Grafida.icns" || ! -f "$ICON_DIR/Grafida.ico" ]]; then
    heading "Generating application icons (scripts/make-icons.sh)"
    bash scripts/make-icons.sh || warn "icon generation reported problems"
fi

# The front-end libraries (TinyMCE, CodeMirror, FontAwesome) are npm-managed and
# gitignored; boson.json bundles assets/private at COMPILE time, so they must exist
# on disk first. Re-run the vendoring step (idempotent — an mtime guard skips files
# already in place). Requires node + npm on the build host.
if [[ ! -f "assets/private/js/tinymce/tinymce.min.js" ]] || [[ "${GRAFIDA_REFRESH_ASSETS:-0}" == "1" ]]; then
    heading "Vendoring front-end libraries (composer run-script vendor:assets)"
    composer run-script vendor:assets || warn "front-end vendoring reported problems"
fi

# The patched sibling-payload micro.sfx runtimes make macOS builds Developer-ID
# signable (see build/readme/01-macos-signing.md). Fetch the CI-built ones into
# the gitignored build/sfx/ when missing — best effort: without them the build
# falls back to the stock (unsignable) Boson runtime.
heading "Fetching patched micro.sfx runtimes (scripts/fetch-sfx.sh)"
bash scripts/fetch-sfx.sh || warn "could not fetch all patched SFX runtimes — affected targets use the stock runtime"

# ---------------------------------------------------------------------------
# 1. Compile all targets (fatal on failure)
# ---------------------------------------------------------------------------
# compile-target.php --all wraps `boson compile` for every target: it drops the
# compiler's stale box-config/entrypoint cache, injects the custom SFX runtimes
# from build/sfx/ into a throwaway config, and pre-cleans the per-target output
# dirs (Boson's own cleanup chokes on a leftover Grafida.app and would abort,
# silently leaving a stale binary).
heading "Compiling binaries for all targets (compile-target.php --all) — v$VERSION"
if ! php build/tasks/compile-target.php --all; then
    echo "ERROR: 'boson compile' failed. Aborting." >&2
    exit 1
fi

mkdir -p "$DIST"

# Strip macOS Finder cruft the compiler may have mounted from the source assets,
# so it does not leak into any platform's package.
find build/macos build/linux build/windows build/phar -name '.DS_Store' -delete 2>/dev/null || true

# ---------------------------------------------------------------------------
# 2. macOS — .app + .dmg, per architecture (macOS host only; needs hdiutil)
# ---------------------------------------------------------------------------
if [[ "$(uname -s)" == "Darwin" ]]; then
    for ARCH in arm64 amd64; do
        [[ "$ARCH" == "arm64" ]] && BOSON_DIR="aarch64" || BOSON_DIR="amd64"
        BIN="build/macos/$BOSON_DIR/grafida"

        if [[ -f "$BIN" ]]; then
            heading "Packaging macOS ($ARCH): .app + .dmg"
            if GRAFIDA_VERSION="$VERSION" bash scripts/make-macos-app.sh "$ARCH" \
               && GRAFIDA_VERSION="$VERSION" bash scripts/make-dmg.sh "$ARCH"; then
                PRODUCED+=("$DIST/Grafida-${VERSION}-macos-${ARCH}.dmg")
            else
                warn "macOS $ARCH packaging failed"
                FAIL=1
            fi
        else
            warn "macOS $ARCH binary missing ($BIN) — skipped"
        fi
    done
else
    warn "Host is not macOS — skipping .app/.dmg packaging (run on macOS to build these)"
fi

# ---------------------------------------------------------------------------
# 3. Linux — .tar.gz (portable: binary + libboson .so + assets + installer)
# ---------------------------------------------------------------------------
for ARCH in amd64 arm64; do
    [[ "$ARCH" == "arm64" ]] && BOSON_DIR="aarch64" || BOSON_DIR="amd64"
    LINUX_BIN="build/linux/$BOSON_DIR/grafida"

    if [[ -f "$LINUX_BIN" ]]; then
        heading "Packaging Linux ($ARCH): .tar.gz"
        if GRAFIDA_VERSION="$VERSION" bash scripts/make-linux-tarball.sh "$ARCH"; then
            PRODUCED+=("$DIST/Grafida-${VERSION}-linux-${ARCH}.tar.gz")
        else
            warn "Linux $ARCH tarball failed"
            FAIL=1
        fi
    else
        warn "Linux $ARCH binary missing ($LINUX_BIN) — skipped"
    fi
done

# ---------------------------------------------------------------------------
# 4. Windows — NSIS installer if available, else a portable .zip
# ---------------------------------------------------------------------------
WIN_BIN="build/windows/amd64/grafida.exe"
if [[ -f "$WIN_BIN" ]]; then
    heading "Packaging Windows (amd64): NSIS installer (or portable .zip fallback)"
    if GRAFIDA_VERSION="$VERSION" bash scripts/make-windows-installer.sh; then
        # The script emits a Setup.exe when makensis is present, else a portable .zip.
        SETUP="$DIST/Grafida-${VERSION}-windows-amd64-Setup.exe"
        ZIP_OUT="$DIST/Grafida-${VERSION}-windows-amd64.zip"
        if [[ -f "$SETUP" ]]; then
            PRODUCED+=("$SETUP")
        elif [[ -f "$ZIP_OUT" ]]; then
            PRODUCED+=("$ZIP_OUT")
            warn "No NSIS compiler found — produced a portable .zip. Install NSIS ('brew install makensis') for a native Windows installer."
        fi
    else
        warn "Windows packaging failed"
        FAIL=1
    fi
else
    warn "Windows binary missing ($WIN_BIN) — skipped"
fi

# ---------------------------------------------------------------------------
# 5. PHAR (cross-platform; emitted by the compiler)
# ---------------------------------------------------------------------------
if [[ -f "build/phar/grafida.phar" ]]; then
    heading "Packaging PHAR"
    if GRAFIDA_VERSION="$VERSION" bash scripts/make-phar-dist.sh; then
        PRODUCED+=("$DIST/Grafida-${VERSION}.phar")
    else
        warn "PHAR packaging failed"
        FAIL=1
    fi
else
    warn "PHAR missing (build/phar/grafida.phar) — skipped"
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
heading "Build summary (v$VERSION)"
if [[ ${#PRODUCED[@]} -gt 0 ]]; then
    echo "Artifacts in $DIST/:"
    for a in "${PRODUCED[@]}"; do echo "  [ok] $a"; done
else
    echo "No artifacts were produced."
    FAIL=1
fi
if [[ ${#WARNINGS[@]} -gt 0 ]]; then
    echo "Notes:"
    for w in "${WARNINGS[@]}"; do echo "  [!]  $w"; done
fi

exit "$FAIL"
