#!/usr/bin/env bash
#
# Grafida — edit Joomla! articles on your desktop.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# Download the patched sibling-payload micro.sfx runtimes into build/sfx/.
#
# The binaries are built by GitHub Actions on the nikosdion/phpmicro fork
# (branch sibling-phar, workflow build-sfx.yml) and published as assets of the
# rolling "sfx-latest" release. With them in place, macOS builds become
# Developer-ID signable/notarisable and Windows builds become Authenticode
# signable — the compiled binary is split into a clean, signable stub plus a
# sibling <name>.phar payload (see build/readme/01-macos-signing.md and
# build/readme/04-exe-signing-on-macos.md).
#
# Usage:  scripts/fetch-sfx.sh [--force]
#   --force   re-download even when the file is already present
#
# Already-present files are kept (so a locally built SFX is never clobbered
# unless --force is given). A failed download leaves nothing behind; builds
# without build/sfx/ simply fall back to the stock (unsignable) Boson runtime.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE_URL="https://github.com/nikosdion/phpmicro/releases/download/sfx-latest"
ASSETS=(
  macos-aarch64.standard.sfx
  macos-x86_64.standard.sfx
  windows-x86_64.standard.sfx
)

FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

mkdir -p "$ROOT/build/sfx"
FAILED=0

for asset in "${ASSETS[@]}"; do
  target="$ROOT/build/sfx/$asset"
  if [ -f "$target" ] && [ "$FORCE" != 1 ]; then
    echo "✓ $asset already present (use --force to re-download)"
    continue
  fi

  echo "Downloading $asset …"
  tmp="$(mktemp)"
  if ! curl -fsSL -o "$tmp" "$BASE_URL/$asset"; then
    echo "  ✗ download failed — $BASE_URL/$asset" >&2
    rm -f "$tmp"
    FAILED=1
    continue
  fi

  # Verify against the published SHA-256 (best effort: skip if absent).
  if sum_expected="$(curl -fsSL "$BASE_URL/$asset.sha256" 2>/dev/null | awk '{print $1}')" && [ -n "$sum_expected" ]; then
    sum_actual="$(shasum -a 256 "$tmp" | awk '{print $1}')"
    if [ "$sum_actual" != "$sum_expected" ]; then
      echo "  ✗ checksum mismatch for $asset (expected $sum_expected, got $sum_actual)" >&2
      rm -f "$tmp"
      FAILED=1
      continue
    fi
  else
    echo "  (no .sha256 published — skipping checksum verification)"
  fi

  mv "$tmp" "$target"
  chmod 755 "$target"
  echo "  ✓ $target"
done

exit $FAILED
