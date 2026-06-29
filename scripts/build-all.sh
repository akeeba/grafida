#!/usr/bin/env bash
# ============================================================================
# Grafida — build & package everything, for every supported platform.
# Edit Joomla! articles on your desktop.
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
ICON_DIR="build/icon"
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

# ---------------------------------------------------------------------------
# 1. Compile all targets (fatal on failure)
# ---------------------------------------------------------------------------
# Drop the compiler's generated box config + entrypoint stub so they are always
# rebuilt from the current boson.json. The compiler only regenerates them when
# their mtime is older than boson.json's (CreateBoxConfigTask / CreateEntrypointTask);
# that cache can go stale and silently pack the phar from an out-of-date inclusion
# list (e.g. dropping src/Ai/resources), which then fails at runtime. The cached
# *.sfx runtimes are left untouched so we do not re-download them every build.
rm -f build/.temp/box.json build/.temp/entrypoint.php build/.temp/grafida.phar

heading "Compiling binaries for all targets ($BOSON compile) — v$VERSION"
if ! php "$BOSON" compile; then
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
        STAGE_ROOT="build/.stage-linux-$BOSON_DIR"
        STAGE="$STAGE_ROOT/grafida"
        rm -rf "$STAGE_ROOT"; mkdir -p "$STAGE"
        cp -R "build/linux/$BOSON_DIR/." "$STAGE/"

        # Desktop integration: ship the icon, the .desktop launcher and the
        # per-user installer (see build/linux-install.sh).
        cp "$ICON_DIR/grafida.png"       "$STAGE/grafida.png"
        cp "$ICON_DIR/grafida.desktop"   "$STAGE/grafida.desktop"
        cp "build/linux-install.sh"      "$STAGE/install.sh"
        chmod +x "$STAGE/install.sh" "$STAGE/grafida"

        TGZ="$DIST/Grafida-${VERSION}-linux-${ARCH}.tar.gz"
        rm -f "$TGZ"
        if tar -czf "$TGZ" -C "$STAGE_ROOT" grafida; then
            PRODUCED+=("$TGZ")
        else
            warn "Linux $ARCH tarball failed"
            FAIL=1
        fi
        rm -rf "$STAGE_ROOT"
    else
        warn "Linux $ARCH binary missing ($LINUX_BIN) — skipped"
    fi
done

# ---------------------------------------------------------------------------
# 4. Windows — NSIS installer if available, else a portable .zip
# ---------------------------------------------------------------------------
WIN_BIN="build/windows/amd64/grafida.exe"
if [[ -f "$WIN_BIN" ]]; then
    # makensis runs natively on macOS/Linux, so the installer cross-compiles
    # from this host (no Wine/Docker/Windows). Fallback: a portable .zip.
    MAKENSIS="$(command -v makensis 2>/dev/null || true)"

    if [[ -n "$MAKENSIS" ]]; then
        heading "Packaging Windows (amd64): NSIS installer (native makensis)"
        SETUP="$DIST/Grafida-${VERSION}-windows-amd64-Setup.exe"
        # makensis chdir's to the script dir, so pass ABSOLUTE source/output paths.
        if "$MAKENSIS" -V2 \
            "-DSRCDIR=$ROOT/build/windows/amd64" \
            "-DOUTFILE=$ROOT/$SETUP" \
            "-DLICENSEFILE=$ROOT/LICENSE.txt" \
            "-DICONFILE=$ROOT/$ICON_DIR/Grafida.ico" \
            "-DAPPVERSION=$VERSION" \
            build/windows-installer.nsi; then
            PRODUCED+=("$SETUP")
        else
            warn "NSIS (makensis) build failed"
            FAIL=1
        fi
    else
        heading "Packaging Windows (amd64): portable .zip (no makensis found)"
        warn "No installer compiler found — produced a portable .zip. Install NSIS ('brew install makensis') for a native Windows installer."
        STAGE_ROOT="build/.stage-win"
        STAGE="$STAGE_ROOT/Grafida"
        rm -rf "$STAGE_ROOT"; mkdir -p "$STAGE"
        cp -R "build/windows/amd64/." "$STAGE/"
        cp "$ICON_DIR/Grafida.ico" "$STAGE/Grafida.ico"
        ZIP_OUT="$DIST/Grafida-${VERSION}-windows-amd64.zip"
        rm -f "$ZIP_OUT"
        if command -v zip >/dev/null 2>&1; then
            if ( cd "$STAGE_ROOT" && zip -qr "$ROOT/$ZIP_OUT" "Grafida" ); then
                PRODUCED+=("$ZIP_OUT")
            else
                warn "Windows .zip packaging failed"
                FAIL=1
            fi
        else
            warn "'zip' not available — skipped Windows .zip"
        fi
        rm -rf "$STAGE_ROOT"
    fi
else
    warn "Windows binary missing ($WIN_BIN) — skipped"
fi

# ---------------------------------------------------------------------------
# 5. PHAR (cross-platform; emitted by the compiler)
# ---------------------------------------------------------------------------
if [[ -f "build/phar/grafida.phar" ]]; then
    PHAR_OUT="$DIST/Grafida-${VERSION}.phar"
    cp "build/phar/grafida.phar" "$PHAR_OUT"
    PRODUCED+=("$PHAR_OUT")
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
