#!/usr/bin/env bash
#
# Grafida — edit Joomla! articles on your desktop.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# Packages the compiled Windows binary (build/windows/amd64/) into an NSIS
# installer. makensis runs natively on macOS/Linux, so the installer
# cross-compiles from this host (no Wine/Docker/Windows). If makensis is absent
# it falls back to a portable .zip.
#
# Usage:  scripts/make-windows-installer.sh
#
# Output: build/dist/Grafida-<version>-windows-amd64-Setup.exe   (NSIS), or
#         build/dist/Grafida-<version>-windows-amd64.zip          (fallback)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

SRC_DIR="$ROOT/build/windows/amd64"
WIN_BIN="$SRC_DIR/grafida.exe"
if [ ! -f "$WIN_BIN" ]; then
  echo "Compiled binary not found at $WIN_BIN — run 'phing git-win-x86' first." >&2
  exit 1
fi

VERSION="${GRAFIDA_VERSION:-$(sed -nE "s/.*VERSION = '([^']+)'.*/\1/p" "$ROOT/src/Support/App.php" | head -1)}"
ICON_DIR="$ROOT/build/icon"
DIST="$ROOT/build/dist"
mkdir -p "$DIST"

MAKENSIS="$(command -v makensis 2>/dev/null || true)"

if [ -n "$MAKENSIS" ]; then
  echo "Packaging Windows (amd64, version: $VERSION): NSIS installer (native makensis)"
  SETUP="$DIST/Grafida-${VERSION}-windows-amd64-Setup.exe"
  # VIProductVersion needs exactly four numeric components (X.X.X.X) — pad/encode
  # the human version (which may carry an alpha/beta/rc suffix) accordingly.
  VIVERSION="$(php "$ROOT/build/tasks/vi-version.php" "$VERSION")"
  # makensis chdir's to the script dir, so pass ABSOLUTE source/output paths.
  "$MAKENSIS" -V2 \
    "-DSRCDIR=$SRC_DIR" \
    "-DOUTFILE=$SETUP" \
    "-DLICENSEFILE=$ROOT/LICENSE.txt" \
    "-DICONFILE=$ICON_DIR/Grafida.ico" \
    "-DAPPVERSION=$VERSION" \
    "-DVIVERSION=$VIVERSION" \
    "$ROOT/build/windows-installer.nsi"
  echo "Done: $SETUP"
  exit 0
fi

# ---------------------------------------------------------------------------
# Fallback: a portable .zip when no NSIS compiler is available.
# ---------------------------------------------------------------------------
echo "Packaging Windows (amd64, version: $VERSION): portable .zip (no makensis found)"
echo "  Install NSIS ('brew install makensis') for a native Windows installer." >&2

if ! command -v zip >/dev/null 2>&1; then
  echo "ERROR: neither 'makensis' nor 'zip' is available — cannot package Windows." >&2
  exit 1
fi

STAGE_ROOT="$ROOT/build/.stage-win"
STAGE="$STAGE_ROOT/Grafida"
rm -rf "$STAGE_ROOT"; mkdir -p "$STAGE"
cp -R "$SRC_DIR/." "$STAGE/"
cp "$ICON_DIR/Grafida.ico" "$STAGE/Grafida.ico"

ZIP_OUT="$DIST/Grafida-${VERSION}-windows-amd64.zip"
rm -f "$ZIP_OUT"
( cd "$STAGE_ROOT" && zip -qr "$ZIP_OUT" "Grafida" )
rm -rf "$STAGE_ROOT"

echo "Done: $ZIP_OUT"
