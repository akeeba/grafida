#!/usr/bin/env bash
#
# Grafida — Joomla content editing, untethered.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# Mirrors docs/ into the grafida-site repository's desktop-docs section, so the
# marketing site's documentation stays in step with the in-app Help screen.
#
# This is a VERBATIM copy because content/docs/desktop/ consumes the same
# _manifest.json as the app, and grafida-site renders the files as-is.
#
# grafida-site is a SEPARATE sibling repository (assumed checked out at
# ../grafida-site relative to this one, override with GRAFIDA_SITE_DIR). This
# script only writes to the working tree there — it does not commit or push,
# since that repository's own history is not this build's to manage.
#
# Usage:  scripts/sync-site-docs.sh [--dry-run]

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCS_DIR="$ROOT/docs"
SITE_DIR="${GRAFIDA_SITE_DIR:-$ROOT/../grafida-site}"
TARGET_DIR="$SITE_DIR/content/docs/desktop"

DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

if [ ! -f "$DOCS_DIR/_manifest.json" ]; then
  echo "sync-site-docs: $DOCS_DIR/_manifest.json not found." >&2
  exit 1
fi

if [ ! -d "$SITE_DIR" ]; then
  echo "sync-site-docs: $SITE_DIR not found (checkout missing, or set GRAFIDA_SITE_DIR)." >&2
  exit 1
fi

if [ "$DRY_RUN" = "1" ]; then
  echo "Dry run: would mirror $DOCS_DIR/ into $TARGET_DIR/"
  rsync -a --delete --dry-run "$DOCS_DIR"/ "$TARGET_DIR"/
  exit 0
fi

mkdir -p "$TARGET_DIR"
rsync -a --delete "$DOCS_DIR"/ "$TARGET_DIR"/

echo "Copied docs/ into $TARGET_DIR"

if [ -d "$SITE_DIR/.git" ] && [ -n "$(git -C "$SITE_DIR" status --porcelain -- content/docs/desktop)" ]; then
  echo
  echo "grafida-site has uncommitted documentation changes — commit and push them there when ready:"
  git -C "$SITE_DIR" status --short -- content/docs/desktop
fi
