#!/usr/bin/env bash
#
# Grafida — Joomla content editing, untethered.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# Packages the compiled Linux binary (build/linux/<arch>/) into a portable
# .tar.gz: the binary + the libboson runtime .so + the bundled assets, plus the
# application icon, the .desktop launcher and the per-user XDG installer
# (build/linux-install.sh, renamed install.sh inside the archive).
#
# Usage:  scripts/make-linux-tarball.sh [arch]      (arch: amd64|arm64, default amd64)
#
# Output: build/dist/Grafida-<version>-linux-<arch>.tar.gz

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARCH="${1:-amd64}"

# Boson names the Linux arm64 output directory "aarch64"; amd64 stays "amd64".
case "$ARCH" in
  amd64|x86_64)  ARCH=amd64; BOSON_DIR=amd64 ;;
  arm64|aarch64) ARCH=arm64; BOSON_DIR=aarch64 ;;
  *) echo "Unknown arch: $ARCH" >&2; exit 1 ;;
esac

SRC_DIR="$ROOT/build/linux/$BOSON_DIR"
LINUX_BIN="$SRC_DIR/grafida"
if [ ! -f "$LINUX_BIN" ]; then
  echo "Compiled binary not found at $LINUX_BIN — run 'phing git-linux-$([ "$ARCH" = amd64 ] && echo x86 || echo arm)' first." >&2
  exit 1
fi

VERSION="${GRAFIDA_VERSION:-$(sed -nE "s/.*VERSION = '([^']+)'.*/\1/p" "$ROOT/src/Support/App.php" | head -1)}"
ICON_DIR="$ROOT/assets/logo"
DIST="$ROOT/build/dist"
mkdir -p "$DIST"

echo "Packaging Linux ($ARCH, version: $VERSION): .tar.gz"

STAGE_ROOT="$ROOT/build/.stage-linux-$BOSON_DIR"
STAGE="$STAGE_ROOT/grafida"
rm -rf "$STAGE_ROOT"; mkdir -p "$STAGE"
cp -R "$SRC_DIR/." "$STAGE/"

# Desktop integration: ship the icon, the .desktop launcher and the per-user
# installer (see build/linux-install.sh).
cp "$ICON_DIR/grafida.png"     "$STAGE/grafida.png"
cp "$ICON_DIR/grafida.desktop" "$STAGE/grafida.desktop"
cp "$ROOT/build/linux-install.sh" "$STAGE/install.sh"
chmod +x "$STAGE/install.sh" "$STAGE/grafida"

TGZ="$DIST/Grafida-${VERSION}-linux-${ARCH}.tar.gz"
rm -f "$TGZ"
tar -czf "$TGZ" -C "$STAGE_ROOT" grafida
rm -rf "$STAGE_ROOT"

echo "Done: $TGZ"
