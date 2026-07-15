#!/usr/bin/env bash
#
# Grafida — edit Joomla! articles on your desktop.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# Wraps the compiled Boson binary (build/macos/<arch>/) into a macOS .app bundle.
# The Boson compiler emits a bare executable + dylib + assets; this assembles the
# standard Contents/MacOS layout, writes Info.plist, and ad-hoc code-signs it so
# it launches on Apple Silicon.
#
# Usage:  scripts/make-macos-app.sh [arch]      (arch defaults to the host: arm64|amd64)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARCH="${1:-$([ "$(uname -m)" = "arm64" ] && echo arm64 || echo amd64)}"

# Boson names the macOS arm64 output directory "aarch64"; amd64 stays "amd64".
case "$ARCH" in
  arm64|aarch64) ARCH=arm64; SRC_DIR="$ROOT/build/macos/aarch64" ;;
  amd64|x86_64)  ARCH=amd64; SRC_DIR="$ROOT/build/macos/amd64" ;;
  *) echo "Unknown arch: $ARCH" >&2; exit 1 ;;
esac

BIN="$SRC_DIR/grafida"
if [ ! -x "$BIN" ]; then
  echo "Compiled binary not found at $BIN — run 'composer compile' (or boson compile -c boson.macos.json) first." >&2
  exit 1
fi

VERSION="${GRAFIDA_VERSION:-$(sed -nE "s/.*VERSION = '([^']+)'.*/\1/p" "$ROOT/src/Support/App.php" | head -1)}"
# The bundle is written beside its source binary (per-arch) so building both the
# arm64 and amd64 .app/.dmg in one run does not clobber a single shared path.
APP="$SRC_DIR/Grafida.app"
MACOS="$APP/Contents/MacOS"

RES="$APP/Contents/Resources"

echo "Assembling $APP (arch: $ARCH, version: $VERSION)"
rm -rf "$APP"
mkdir -p "$MACOS" "$RES"

# Boson's compiled binary is a phpmicro self-executable: a Mach-O stub with the
# PHP payload appended after the code signature. codesign rejects trailing data,
# so such a combined binary can never be Developer-ID signed. When the binary
# was compiled against the PATCHED SFX runtime (build/sfx/macos-<cpu>.standard.sfx,
# built from the nikosdion/phpmicro `sibling-phar` fork — see
# build/readme/01-macos-signing.md) we can split it instead: the clean Mach-O
# stub becomes the bundle executable and the payload moves to
# Contents/Resources/grafida.phar, which the patched stub finds at run time
# (it looks for "<self>.phar" then "../Resources/<self>.phar"). Data files must
# live in Resources — codesign refuses non-code files inside Contents/MacOS.
# The phar's own directory is then Resources, and Boson's entrypoint mounts
# `assets/public` and the libboson dylib relative to the phar, so the assets go
# to Resources too and the dylib (real file in MacOS, where signed code lives)
# gets a symlink there.
SIBLING_SFX=0
if LC_ALL=C grep -aq "next to this executable" "$BIN"; then
  # The Mach-O image ends where its last segment ends — max(fileoff+filesize)
  # over every LC_SEGMENT_64 — and the appended PHP payload starts there. Do NOT
  # key off LC_CODE_SIGNATURE: only the arm64 SFX ships with an (ad-hoc)
  # signature (Apple Silicon requires one); the cross-compiled x86_64 SFX has no
  # LC_CODE_SIGNATURE at all, so a dataoff+datasize probe returns nothing and the
  # amd64 build wrongly looks unsplittable. The last-segment end is present on
  # both arches (and when a signature exists, __LINKEDIT's filesize already covers
  # it, so the two agree on arm64).
  MACHO_END="$(otool -l "$BIN" 2>/dev/null | awk '
    /cmd LC_SEGMENT_64/ {seg = 1}
    seg && /fileoff/    {fo = $2}
    seg && /filesize/   {end = fo + $2; if (end > max) max = end; seg = 0}
    END                 {if (max) print max}')"
  FILE_SIZE="$(stat -f%z "$BIN")"
  if [ -n "$MACHO_END" ] && [ "$FILE_SIZE" -gt "$MACHO_END" ]; then
    SIBLING_SFX=1
  else
    echo "Warning: patched SFX detected but could not locate the appended payload — using the legacy (unsignable) layout." >&2
  fi
fi

if [ "$SIBLING_SFX" = 1 ]; then
  echo "Patched sibling-payload SFX detected: splitting $((FILE_SIZE - MACHO_END)) payload bytes into Contents/Resources/grafida.phar"
  head -c "$MACHO_END" "$BIN" > "$MACOS/grafida"
  tail -c "+$((MACHO_END + 1))" "$BIN" > "$RES/grafida.phar"
  cp "$SRC_DIR"/*.dylib "$MACOS/" 2>/dev/null || true
  [ -d "$SRC_DIR/assets" ] && cp -R "$SRC_DIR/assets" "$RES/assets"
  for dylib in "$MACOS"/*.dylib; do
    [ -f "$dylib" ] && ln -s "../MacOS/$(basename "$dylib")" "$RES/$(basename "$dylib")"
  done
else
  # Legacy layout: the combined self-executable with its data beside it. Runs
  # locally (ad-hoc) but can never be Developer-ID signed / notarised.
  cp "$BIN" "$MACOS/grafida"
  cp "$SRC_DIR"/*.dylib "$MACOS/" 2>/dev/null || true
  [ -d "$SRC_DIR/assets" ] && cp -R "$SRC_DIR/assets" "$MACOS/assets"
fi
chmod +x "$MACOS/grafida"

# Application icon. Generate it from the master SVG if it is missing.
ICNS="$ROOT/build/icon/Grafida.icns"
if [ ! -f "$ICNS" ] && [ -x "$ROOT/scripts/make-icons.sh" ]; then
  "$ROOT/scripts/make-icons.sh" >/dev/null
fi
if [ -f "$ICNS" ]; then
  cp "$ICNS" "$APP/Contents/Resources/Grafida.icns"
else
  echo "Warning: $ICNS not found — bundle will use the generic app icon." >&2
fi

cat > "$APP/Contents/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>CFBundleName</key><string>Grafida</string>
    <key>CFBundleDisplayName</key><string>Grafida</string>
    <key>CFBundleIdentifier</key><string>com.akeeba.grafida</string>
    <key>CFBundleVersion</key><string>${VERSION}</string>
    <key>CFBundleShortVersionString</key><string>${VERSION}</string>
    <key>CFBundleExecutable</key><string>grafida</string>
    <key>CFBundleIconFile</key><string>Grafida</string>
    <key>CFBundlePackageType</key><string>APPL</string>
    <key>CFBundleInfoDictionaryVersion</key><string>6.0</string>
    <key>LSMinimumSystemVersion</key><string>14.0</string>
    <key>NSHighResolutionCapable</key><true/>
    <key>LSApplicationCategoryType</key><string>public.app-category.productivity</string>
    <key>NSHumanReadableCopyright</key><string>Copyright (c) 2026 Nicholas K. Dionysopoulos. GNU GPL v3 or later.</string>
    <key>NSAppTransportSecurity</key>
    <dict>
        <key>NSAllowsArbitraryLoadsInWebContent</key><true/>
    </dict>
</dict>
</plist>
PLIST

# Code signing.
#
# For DISTRIBUTION set MACOS_SIGN_IDENTITY to a "Developer ID Application: …"
# identity (see build/readme/01-macos-signing.md). We then sign inside-out — the
# bundled dylib(s), then the main executable, then the whole bundle — each with
# the hardened runtime (--options runtime) and the entitlements the Boson SFX +
# bundled PHP runtime need (build/macos/entitlements.plist). This REPLACES the
# binary's original ad-hoc "micro.sfx" signature, which cannot be notarised.
#
# For LOCAL dev (MACOS_SIGN_IDENTITY unset) we keep the previous behaviour: the
# pre-signed SFX is left alone and we only ad-hoc sign the bundled dylib. A
# bundle-level ad-hoc signature around a pre-signed SFX is unreliable, so we skip
# it — the app runs locally as-is (but such a build cannot be notarised).
SIGN_IDENTITY="${MACOS_SIGN_IDENTITY:-}"
ENTITLEMENTS="${MACOS_ENTITLEMENTS:-$ROOT/build/macos/entitlements.plist}"

if [ -n "$SIGN_IDENTITY" ]; then
  if ! command -v codesign >/dev/null 2>&1; then
    echo "MACOS_SIGN_IDENTITY is set but 'codesign' was not found — install Xcode or the Command Line Tools." >&2
    exit 1
  fi
  if [ ! -f "$ENTITLEMENTS" ]; then
    echo "Entitlements file not found at $ENTITLEMENTS." >&2
    exit 1
  fi
  echo "Signing with Developer ID identity: $SIGN_IDENTITY"

  # 1) inner dylibs first (normal Mach-O files — these sign cleanly)
  for dylib in "$MACOS"/*.dylib; do
    [ -f "$dylib" ] || continue
    codesign --force --timestamp --options runtime --sign "$SIGN_IDENTITY" "$dylib"
  done

  if [ "$SIBLING_SFX" != 1 ]; then
    echo "" >&2
    echo "ERROR: cannot Developer-ID sign $MACOS/grafida." >&2
    echo "  The binary was compiled against a STOCK Boson SFX, whose appended PHP payload" >&2
    echo "  makes it unsignable. Build the patched sibling-payload SFX and drop it in" >&2
    echo "  build/sfx/macos-<cpu>.standard.sfx, then recompile — see" >&2
    echo "  build/readme/01-macos-signing.md." >&2
    exit 1
  fi

  # 2) the main executable (a clean Mach-O stub after the payload split above)
  codesign --force --timestamp --options runtime \
    --entitlements "$ENTITLEMENTS" --sign "$SIGN_IDENTITY" "$MACOS/grafida"

  # 3) the whole bundle
  codesign --force --timestamp --options runtime \
    --entitlements "$ENTITLEMENTS" --sign "$SIGN_IDENTITY" "$APP"

  codesign --verify --deep --strict --verbose=2 "$APP"
  echo "Signed and verified: $APP"
elif command -v codesign >/dev/null 2>&1; then
  if [ "$SIBLING_SFX" = 1 ]; then
    # Local dev with the split layout: ad-hoc sign the whole bundle (all
    # Mach-O files are clean, so this works — it just isn't notarised).
    for dylib in "$MACOS"/*.dylib; do
      [ -f "$dylib" ] && codesign --force --sign - "$dylib" >/dev/null 2>&1 || true
    done
    codesign --force --sign - "$MACOS/grafida" >/dev/null 2>&1 || true
    codesign --force --sign - "$APP" >/dev/null 2>&1 || true
  else
    # Local dev, legacy layout: best-effort ad-hoc signature on the bundled
    # dylib only (a bundle-level signature around the pre-signed combined SFX
    # is unreliable).
    for dylib in "$MACOS"/*.dylib; do
      [ -f "$dylib" ] && codesign --force --sign - "$dylib" >/dev/null 2>&1 || true
    done
  fi
fi

echo "Done: $APP"
