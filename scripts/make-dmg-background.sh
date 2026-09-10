#!/usr/bin/env bash
#
# Grafida — Joomla content editing, untethered.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# Rasterises the DMG background artwork master (assets/logo/dmg-background.svg)
# into the assets scripts/make-dmg.sh drops into the disk image:
#
#   assets/logo/dmg-background.png     640x400  (1x)
#   assets/logo/dmg-background@2x.png  1280x800 (2x, retina)
#   assets/logo/dmg-background.tiff    multi-resolution TIFF combining both
#
# Re-run this whenever dmg-background.svg changes, then commit the regenerated
# assets. Mirrors scripts/make-icons.sh: the raster tools are optional (missing
# ones only skip their step) and the whole thing is best-effort.
#
# Tools:
#   rsvg-convert  — SVG -> PNG (preferred; falls back to ImageMagick)
#   magick/convert— SVG -> PNG fallback
#   tiffutil      — combines the two PNGs into a @2x-aware TIFF (macOS only)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ICON_DIR="$ROOT/assets/logo"
SVG="$ICON_DIR/dmg-background.svg"

[ -f "$SVG" ] || { echo "DMG background SVG not found: $SVG" >&2; exit 1; }

# Render the SVG to a PNG of the given pixel size: render_rect <w> <h> <outfile>
render_rect() {
  local w="$1" h="$2" out="$3"
  if command -v rsvg-convert >/dev/null 2>&1; then
    rsvg-convert -w "$w" -h "$h" "$SVG" -o "$out"
  elif command -v magick >/dev/null 2>&1; then
    magick -background none -density 288 "$SVG" -resize "${w}x${h}!" "$out"
  elif command -v convert >/dev/null 2>&1; then
    convert -background none -density 288 "$SVG" -resize "${w}x${h}!" "$out"
  else
    echo "Need rsvg-convert or ImageMagick to rasterise the DMG background." >&2
    exit 1
  fi
}

PNG1X="$ICON_DIR/dmg-background.png"
PNG2X="$ICON_DIR/dmg-background@2x.png"
TIFF="$ICON_DIR/dmg-background.tiff"

echo "Rendering DMG background -> $PNG1X (640x400) and @2x (1280x800)"
render_rect 640 400  "$PNG1X"
render_rect 1280 800 "$PNG2X"

# Combine the two resolutions into a single TIFF whose @2x representation Finder
# picks on retina displays. tiffutil is part of the Xcode Command Line Tools.
if command -v tiffutil >/dev/null 2>&1; then
  echo "Building $TIFF (multi-resolution)"
  tiffutil -cathidpicheck "$PNG1X" "$PNG2X" -out "$TIFF"
else
  echo "tiffutil not found (macOS only) — skipping dmg-background.tiff" >&2
fi

echo "Done. DMG background assets in $ICON_DIR"
