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

# Sign the compiled binary itself first, so both the NSIS installer and the
# portable .zip fallback below carry a signed grafida.exe. A no-op unless
# WINDOWS_SIGN_OP_ITEM is set (see scripts/sign-windows-exe.sh) — never runs
# for a local 'phing git-win-x86' compile, only from packaging.
#
# CRUCIAL: grafida.exe is a phpmicro self-executable — the PE stub with the
# application PHAR appended after it. Authenticode signing appends its
# certificate table at EOF, i.e. AFTER the PHAR, which corrupts the PHAR's own
# trailing signature and makes the app die at startup with "grafida.exe has a
# broken signature" (Phar::mapPhar). So we must NEVER sign the combined binary.
#
# When grafida.exe was compiled against the patched sibling-payload SFX
# (build/sfx/windows-x86_64.standard.sfx, from the nikosdion/phpmicro
# `sibling-phar` fork — see build/readme/02-signing-architecture.md), we split
# it, exactly like scripts/make-macos-app.sh does for the .app bundle: the clean
# PE stub becomes grafida.exe and the payload becomes a sibling grafida.phar the
# patched stub loads at run time. Only the clean stub is signed. Everything
# packaged below (NSIS installer or portable .zip) is built from PKG_DIR.
SIGNING=0
[ -n "${WINDOWS_SIGN_OP_ITEM:-}" ] && SIGNING=1

PKG_DIR="$SRC_DIR"   # what we package; the split staging dir when applicable
HAVE_PHAR=0

if LC_ALL=C grep -aq "next to this executable" "$WIN_BIN"; then
  # Patched, sibling-capable stub: split at phpmicro's SFX size (the section
  # end; pe-sfxsize.php also asserts Boson's extra-ini magic sits there).
  SFXSIZE="$(php "$ROOT/build/tasks/pe-sfxsize.php" "$WIN_BIN")" || {
    echo "ERROR: patched Windows SFX detected but could not locate the appended payload in" >&2
    echo "       $WIN_BIN (see the message above). Refusing to package a broken binary." >&2
    exit 1
  }
  STAGE_SPLIT="$ROOT/build/.stage-win-split"
  rm -rf "$STAGE_SPLIT"; mkdir -p "$STAGE_SPLIT"
  echo "Patched sibling-payload SFX detected: splitting $(( $(stat -f%z "$WIN_BIN") - SFXSIZE )) payload bytes into grafida.phar"
  head -c "$SFXSIZE" "$WIN_BIN" > "$STAGE_SPLIT/grafida.exe"
  tail -c "+$((SFXSIZE + 1))" "$WIN_BIN" > "$STAGE_SPLIT/grafida.phar"
  # The runtime DLL and UI assets must sit beside the exe (NSIS/zip copy them).
  cp "$SRC_DIR"/*.dll "$STAGE_SPLIT/" 2>/dev/null || true
  [ -d "$SRC_DIR/assets" ] && cp -R "$SRC_DIR/assets" "$STAGE_SPLIT/assets"
  PKG_DIR="$STAGE_SPLIT"
  HAVE_PHAR=1
  # Sign the CLEAN stub (no appended payload) — this is the entire point.
  "$ROOT/scripts/sign-windows-exe.sh" "$STAGE_SPLIT/grafida.exe"
elif [ "$SIGNING" = 1 ]; then
  echo "" >&2
  echo "ERROR: cannot Authenticode-sign $WIN_BIN." >&2
  echo "  It was compiled against a STOCK Boson SFX, whose appended PHP payload sits" >&2
  echo "  after the executable. Signing it would append the certificate past the PHAR" >&2
  echo "  and corrupt its trailing signature (Phar::mapPhar fails at startup)." >&2
  echo "  Build the patched sibling-payload SFX into build/sfx/windows-x86_64.standard.sfx" >&2
  echo "  (scripts/fetch-sfx.sh) and recompile — see build/readme/02-signing-architecture.md." >&2
  exit 1
else
  echo "Note: unpatched Boson SFX — shipping the UNSIGNED combined grafida.exe (it works," >&2
  echo "      but is not code-signed). Add build/sfx/windows-x86_64.standard.sfx to sign it." >&2
fi

MAKENSIS="$(command -v makensis 2>/dev/null || true)"

if [ -n "$MAKENSIS" ]; then
  echo "Packaging Windows (amd64, version: $VERSION): NSIS installer (native makensis)"
  SETUP="$DIST/Grafida-${VERSION}-windows-amd64-Setup.exe"
  # VIProductVersion needs exactly four numeric components (X.X.X.X) — pad/encode
  # the human version (which may carry an alpha/beta/rc suffix) accordingly.
  VIVERSION="$(php "$ROOT/build/tasks/vi-version.php" "$VERSION")"
  # A split build ships grafida.phar beside the stub; tell the installer to
  # bundle it (the .nsi guards the extra File on HAVE_PHAR).
  NSIS_ARGS=(
    "-DSRCDIR=$PKG_DIR"
    "-DOUTFILE=$SETUP"
    "-DLICENSEFILE=$ROOT/LICENSE.txt"
    "-DICONFILE=$ICON_DIR/Grafida.ico"
    "-DAPPVERSION=$VERSION"
    "-DVIVERSION=$VIVERSION"
  )
  [ "$HAVE_PHAR" = 1 ] && NSIS_ARGS+=("-DHAVE_PHAR=1")
  # makensis chdir's to the script dir, so pass ABSOLUTE source/output paths.
  "$MAKENSIS" -V2 "${NSIS_ARGS[@]}" "$ROOT/build/windows-installer.nsi"
  # Sign the installer executable itself too — it is a PE file end users run
  # directly. (It carries NSIS's own overlay, not a PHAR, so the sign guard in
  # sign-windows-exe.sh lets it through.)
  "$ROOT/scripts/sign-windows-exe.sh" "$SETUP"
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
# PKG_DIR is the split staging dir (stub + grafida.phar + dll + assets) when the
# binary was split above, otherwise the original compiled output.
cp -R "$PKG_DIR/." "$STAGE/"
cp "$ICON_DIR/Grafida.ico" "$STAGE/Grafida.ico"

ZIP_OUT="$DIST/Grafida-${VERSION}-windows-amd64.zip"
rm -f "$ZIP_OUT"
( cd "$STAGE_ROOT" && zip -qr "$ZIP_OUT" "Grafida" )
rm -rf "$STAGE_ROOT"

echo "Done: $ZIP_OUT"
