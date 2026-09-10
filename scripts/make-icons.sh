#!/usr/bin/env bash
#
# Grafida — Joomla content editing, untethered.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# Rasterises the master icon (assets/logo/grafida.svg) into every per-platform
# format used when packaging the app:
#
#   assets/logo/png/grafida-<size>.png   PNG set (Linux hicolor theme, sources)
#   assets/logo/Grafida.icns             macOS .app bundle icon
#   assets/logo/Grafida.ico              Windows .exe icon (multi-resolution)
#
# Re-run this whenever grafida.svg changes, then commit the regenerated assets.
#
# Tools (all optional; missing ones only skip their format):
#   rsvg-convert  — SVG -> PNG (preferred; falls back to ImageMagick)
#   magick/convert— builds the .ico (and the SVG fallback)
#   iconutil      — builds the .icns (macOS only)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ICON_DIR="$ROOT/assets/logo"
SVG="$ICON_DIR/grafida.svg"
PNG_DIR="$ICON_DIR/png"

[ -f "$SVG" ] || { echo "Master SVG not found: $SVG" >&2; exit 1; }

# Render the SVG to a PNG of the given square size: render <size> <outfile>
render() {
  local size="$1" out="$2"
  if command -v rsvg-convert >/dev/null 2>&1; then
    rsvg-convert -w "$size" -h "$size" "$SVG" -o "$out"
  elif command -v magick >/dev/null 2>&1; then
    magick -background none -density 384 "$SVG" -resize "${size}x${size}" "$out"
  elif command -v convert >/dev/null 2>&1; then
    convert -background none -density 384 "$SVG" -resize "${size}x${size}" "$out"
  else
    echo "Need rsvg-convert or ImageMagick to rasterise the SVG." >&2
    exit 1
  fi
}

# --- PNG set --------------------------------------------------------------
SIZES=(16 24 32 48 64 128 256 512 1024)
mkdir -p "$PNG_DIR"
echo "Rendering PNG set -> $PNG_DIR"
for s in "${SIZES[@]}"; do
  render "$s" "$PNG_DIR/grafida-$s.png"
done
# Canonical Linux icon (512px) at the icon-dir root.
cp "$PNG_DIR/grafida-512.png" "$ICON_DIR/grafida.png"

# --- macOS .icns ----------------------------------------------------------
if command -v iconutil >/dev/null 2>&1; then
  echo "Building Grafida.icns"
  SET="$(mktemp -d)/Grafida.iconset"
  mkdir -p "$SET"
  render 16   "$SET/icon_16x16.png"
  render 32   "$SET/icon_16x16@2x.png"
  render 32   "$SET/icon_32x32.png"
  render 64   "$SET/icon_32x32@2x.png"
  render 128  "$SET/icon_128x128.png"
  render 256  "$SET/icon_128x128@2x.png"
  render 256  "$SET/icon_256x256.png"
  render 512  "$SET/icon_256x256@2x.png"
  render 512  "$SET/icon_512x512.png"
  render 1024 "$SET/icon_512x512@2x.png"
  iconutil -c icns "$SET" -o "$ICON_DIR/Grafida.icns"
  rm -rf "$(dirname "$SET")"
else
  echo "iconutil not found (macOS only) — skipping Grafida.icns" >&2
fi

# --- Windows .ico ---------------------------------------------------------
ICO_TOOL=""
command -v magick  >/dev/null 2>&1 && ICO_TOOL="magick"
[ -z "$ICO_TOOL" ] && command -v convert >/dev/null 2>&1 && ICO_TOOL="convert"
if [ -n "$ICO_TOOL" ]; then
  echo "Building Grafida.ico"
  "$ICO_TOOL" \
    "$PNG_DIR/grafida-16.png" "$PNG_DIR/grafida-24.png" "$PNG_DIR/grafida-32.png" \
    "$PNG_DIR/grafida-48.png" "$PNG_DIR/grafida-64.png" "$PNG_DIR/grafida-128.png" \
    "$PNG_DIR/grafida-256.png" "$ICON_DIR/Grafida.ico"
else
  echo "ImageMagick not found — skipping Grafida.ico" >&2
fi

# --- macOS DMG background -------------------------------------------------
# Regenerate the disk-image background artwork from its own SVG master too, so
# "refresh the visual assets" stays a single command. Best-effort.
if [ -x "$ROOT/scripts/make-dmg-background.sh" ]; then
  echo "Rendering DMG background (scripts/make-dmg-background.sh)"
  bash "$ROOT/scripts/make-dmg-background.sh" || echo "DMG background generation reported problems" >&2
fi

echo "Done. Icons in $ICON_DIR"
