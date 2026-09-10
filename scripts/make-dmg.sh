#!/usr/bin/env bash
#
# Grafida — Joomla content editing, untethered.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# Wraps a Grafida.app bundle (produced by scripts/make-macos-app.sh) into a
# compressed, distributable .dmg using hdiutil (built into macOS). The disk
# image shows the app next to an /Applications symlink so users can install by
# drag-and-drop.
#
# Usage:  scripts/make-dmg.sh [arch]      (arch defaults to the host: arm64|amd64)
#
# Output: build/dist/Grafida-<version>-macos-<arch>.dmg

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARCH="${1:-$([ "$(uname -m)" = "arm64" ] && echo arm64 || echo amd64)}"

# Boson names the macOS arm64 output directory "aarch64"; amd64 stays "amd64".
case "$ARCH" in
  arm64|aarch64) ARCH=arm64; SRC_DIR="$ROOT/build/macos/aarch64" ;;
  amd64|x86_64)  ARCH=amd64; SRC_DIR="$ROOT/build/macos/amd64" ;;
  *) echo "Unknown arch: $ARCH" >&2; exit 1 ;;
esac

APP="$SRC_DIR/Grafida.app"
if [ ! -d "$APP" ]; then
  echo "App bundle not found at $APP — run scripts/make-macos-app.sh $ARCH first." >&2
  exit 1
fi

VERSION="${GRAFIDA_VERSION:-$(sed -nE "s/.*VERSION = '([^']+)'.*/\1/p" "$ROOT/src/Support/App.php" | head -1)}"
DIST="$ROOT/build/dist"
DMG="$DIST/Grafida-${VERSION}-macos-${ARCH}.dmg"
STAGING="$SRC_DIR/.dmg-staging"
TEMP_DMG="$SRC_DIR/.dmg-temp.dmg"
VOLNAME="Grafida"

mkdir -p "$DIST"

echo "Building DMG (arch: $ARCH, version: $VERSION)"
echo "  Source: $APP"
echo "  Output: $DMG"

# Stage the .app beside an /Applications symlink for drag-to-install.
rm -rf "$STAGING"
mkdir -p "$STAGING"
cp -R "$APP" "$STAGING/"
ln -s /Applications "$STAGING/Applications"

# Size the writable image from the staged contents, plus headroom.
STAGING_KB=$(du -sk "$STAGING" | awk '{print $1}')
DMG_MB=$(( (STAGING_KB / 1024) + 20 ))

rm -f "$TEMP_DMG" "$DMG"
hdiutil create -volname "$VOLNAME" -srcfolder "$STAGING" -ov \
  -format UDRW -size "${DMG_MB}m" "$TEMP_DMG"

# Give the disk image a branded Finder layout: our background artwork, a hidden
# toolbar, and the app icon next to /Applications with an install arrow between
# them (drawn on the background). The writable UDRW image is mounted, dressed via
# Finder AppleScript, then detached; the layout persists in the volume's .DS_Store
# and survives the UDZO convert below.
#
# Best-effort: a missing background asset, or an osascript failure (e.g. the macOS
# automation permission to control Finder has not been granted — the first run
# prompts for it), only warns and leaves a plain-but-working DMG.
BG_TIFF="$ROOT/assets/logo/dmg-background.tiff"
VOL_ICNS="$ROOT/assets/logo/Grafida.icns"
style_dmg() {
  [ -f "$BG_TIFF" ] || { echo "  Background artwork not found ($BG_TIFF) — leaving the DMG unstyled." >&2; return 1; }

  local mount_point
  mount_point="$(hdiutil attach -readwrite -noverify -noautoopen "$TEMP_DMG" \
    | grep -Eo '/Volumes/[^"]+$' | head -1)"
  [ -n "$mount_point" ] && [ -d "$mount_point" ] || { echo "  Could not mount the writable image to style it." >&2; return 1; }

  # Each step's status is checked explicitly rather than via `set -e`: a failing
  # command inside a `{ …; } || …` group does not abort under errexit, so relying
  # on it would let an osascript failure fall through unnoticed.
  local ok=1
  mkdir -p "$mount_point/.background" \
    && cp "$BG_TIFF" "$mount_point/.background/background.tiff" || ok=0

  if [ "$ok" = 1 ]; then
    if osascript <<APPLESCRIPT
tell application "Finder"
  tell disk "$VOLNAME"
    open
    set current view of container window to icon view
    set toolbar visible of container window to false
    set statusbar visible of container window to false
    set the bounds of container window to {400, 150, 1040, 550}
    set theViewOptions to the icon view options of container window
    set arrangement of theViewOptions to not arranged
    set icon size of theViewOptions to 128
    set text size of theViewOptions to 13
    set background picture of theViewOptions to file ".background:background.tiff"
    set position of item "Grafida.app" of container window to {160, 210}
    set position of item "Applications" of container window to {480, 210}
    update without registering applications
    delay 1
    close
  end tell
end tell
APPLESCRIPT
    then
      # Volume icon (shown when the DMG is mounted), set AFTER the Finder styling:
      # opening the volume in Finder above deletes a pre-existing .VolumeIcon.icns,
      # so it must be written once Finder is done with the window. Best-effort.
      if [ -f "$VOL_ICNS" ]; then
        cp "$VOL_ICNS" "$mount_point/.VolumeIcon.icns" || true
        SetFile -a C "$mount_point" 2>/dev/null || true
      fi
    else
      ok=0
    fi
  fi

  [ "$ok" = 1 ] || echo "  Finder styling failed (automation permission?) — the DMG will be plain." >&2

  sync
  hdiutil detach "$mount_point" >/dev/null 2>&1 \
    || hdiutil detach "$mount_point" -force >/dev/null 2>&1 || true
  return $(( ok ? 0 : 1 ))
}

echo "Styling the disk image (Finder layout + background)"
style_dmg || true

hdiutil convert "$TEMP_DMG" -format UDZO -imagekey zlib-level=9 -o "$DMG"

rm -f "$TEMP_DMG"
rm -rf "$STAGING"

# Sign + notarise the finished DMG for distribution.
#
# MACOS_SIGN_IDENTITY  — a "Developer ID Application: …" identity signs the DMG.
# MACOS_NOTARY_PROFILE — a notarytool keychain profile (see
#                        build/readme/01-macos-signing.md) additionally submits
#                        the DMG to Apple and staples the ticket.
# Both unset → the DMG is left unsigned (previous behaviour, fine for local dev).
SIGN_IDENTITY="${MACOS_SIGN_IDENTITY:-}"
NOTARY_PROFILE="${MACOS_NOTARY_PROFILE:-}"

if [ -n "$SIGN_IDENTITY" ]; then
  echo "Signing DMG with Developer ID identity: $SIGN_IDENTITY"
  codesign --force --timestamp --sign "$SIGN_IDENTITY" "$DMG"
fi

if [ -n "$NOTARY_PROFILE" ]; then
  if [ -z "$SIGN_IDENTITY" ]; then
    echo "MACOS_NOTARY_PROFILE is set but MACOS_SIGN_IDENTITY is not — notarisation requires a signed DMG." >&2
    exit 1
  fi
  echo "Submitting DMG for notarisation (profile: $NOTARY_PROFILE) — this can take a few minutes…"
  xcrun notarytool submit "$DMG" --keychain-profile "$NOTARY_PROFILE" --wait
  echo "Stapling notarisation ticket…"
  xcrun stapler staple "$DMG"
  xcrun stapler validate "$DMG"
  echo "Notarised and stapled: $DMG"
fi

echo "Done: $DMG"
