#!/usr/bin/env bash
#
# Grafida — edit Joomla! articles on your desktop.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# Publishes docs/ to the project's GitHub wiki.
#
# The wiki is a SEPARATE git repository (<repo>.wiki.git) which nothing in the
# main repository's history touches, so it has to be cloned, written to and
# pushed. This script is the only supported way to change it: the wiki is a
# MIRROR, and anything edited through GitHub's wiki editor is overwritten the
# next time this runs. (Restrict wiki editing to collaborators in the
# repository settings so nobody loses work discovering that the hard way.)
#
# docs/ is almost the wiki tree verbatim — the file names are the wiki page
# names, and links between pages are already written the way the wiki resolves
# them. Exactly three things are done on the way across:
#
#   1. `_manifest.json` becomes `_Sidebar.md` (the wiki's navigation) and is not
#      itself published — it is our table of contents, not a page.
#   2. A page's leading `# H1` is dropped. GitHub renders the page name as a
#      heading above the content, so keeping ours would show the title twice.
#      The H1 stays in the repository files, where it is what makes them
#      readable on their own.
#   3. `_Footer.md` is generated, so every page carries the licence notice and
#      the Joomla!® trademark disclaimer.
#
# Usage:  scripts/sync-wiki.sh [--dry-run]
#
# Needs push access to the wiki repository (the same SSH key as the main one).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCS_DIR="$ROOT/docs"
WORK_DIR="$ROOT/build/wiki-repo"
WIKI_REMOTE="${GRAFIDA_WIKI_REMOTE:-git@github.com:akeeba/grafida.wiki.git}"

DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

if [ ! -f "$DOCS_DIR/_manifest.json" ]; then
  echo "sync-wiki: $DOCS_DIR/_manifest.json not found." >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# 1. Get the wiki repository. GitHub creates it lazily: it does not exist until
#    the first page has been made through the web UI, and cloning a wiki that
#    has never been created fails rather than giving an empty repository.
# ---------------------------------------------------------------------------
if [ -d "$WORK_DIR/.git" ]; then
  echo "Updating existing wiki clone in $WORK_DIR"
  git -C "$WORK_DIR" remote set-url origin "$WIKI_REMOTE"
  git -C "$WORK_DIR" fetch --quiet origin
  git -C "$WORK_DIR" reset --quiet --hard origin/HEAD
else
  echo "Cloning $WIKI_REMOTE"
  rm -rf "$WORK_DIR"
  if ! git clone --quiet "$WIKI_REMOTE" "$WORK_DIR"; then
    echo >&2
    echo "sync-wiki: could not clone the wiki repository." >&2
    echo "If this is the first run, create the wiki first: open" >&2
    echo "  https://github.com/akeeba/grafida/wiki" >&2
    echo "and save any page. GitHub does not create the repository until then." >&2
    exit 1
  fi
fi

# ---------------------------------------------------------------------------
# 2. Mirror the pages. Every .md in the wiki clone is removed first, so a page
#    deleted from docs/ disappears from the wiki too rather than lingering.
# ---------------------------------------------------------------------------
find "$WORK_DIR" -maxdepth 1 -name '*.md' -delete
rm -rf "$WORK_DIR/images"

php -r '
$docsDir = $argv[1];
$workDir = $argv[2];

$manifest = json_decode((string) file_get_contents($docsDir . "/_manifest.json"), true);

if (!is_array($manifest) || !isset($manifest["pages"]) || !is_array($manifest["pages"])) {
    fwrite(STDERR, "sync-wiki: _manifest.json is not readable or has no pages array.\n");
    exit(1);
}

$missing = [];
$sidebar = [];

/**
 * Walks the manifest tree, writing each page into the wiki clone and building
 * the sidebar as a nested Markdown list. The nesting lives ONLY here and in the
 * sidebar: the .md files themselves stay in one flat directory, because a
 * GitHub wiki has a flat page namespace and cannot represent a folder.
 */
$walk = function (array $nodes, int $depth) use (&$walk, &$sidebar, &$missing, $docsDir, $workDir): void {
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }

        $slug  = isset($node["slug"]) ? (string) $node["slug"] : "";
        $title = (string) ($node["title"] ?? $slug);

        if ($slug !== "" && preg_match("/^[A-Za-z0-9_\-]+$/", $slug) !== 1) {
            fwrite(STDERR, "sync-wiki: skipping entry with an unusable slug: " . json_encode($node) . "\n");
            continue;
        }

        // Where the children of this node go. Normally one level in — except under a
        // top-level heading, which renders as a bold section title rather than a
        // list item, so its children ARE the top-level list and must not be
        // indented under a list item that does not exist.
        $childDepth = $depth + 1;

        if ($slug === "") {
            if ($depth === 0) {
                $sidebar[]  = "";
                $sidebar[]  = "**" . $title . "**";
                $sidebar[]  = "";
                $childDepth = 0;
            } else {
                // Deeper down a bold run inside a list reads as a mistake, so a
                // heading is just an unlinked list item.
                $sidebar[] = str_repeat("  ", $depth) . "- " . $title;
            }
        } else {
            $source = $docsDir . "/" . $slug . ".md";

            if (!is_file($source)) {
                $missing[] = $slug;
                continue;
            }

            $body = (string) file_get_contents($source);

            // Drop the leading H1: the wiki prints the page name above the
            // content already. Only a *leading* one, and only when it is the
            // first non-blank line, so an H1 further down (there should not be
            // one) is left alone.
            $body = preg_replace("/\A\s*#[^\n#][^\n]*\n+/", "", $body) ?? $body;

            file_put_contents($workDir . "/" . $slug . ".md", ltrim($body));

            $sidebar[] = str_repeat("  ", $depth) . "- [[" . $title . "|" . $slug . "]]";
        }

        $children = $node["children"] ?? [];

        if (is_array($children) && $children !== []) {
            $walk($children, $childDepth);
        }
    }
};

$walk($manifest["pages"], 0);

if ($missing !== []) {
    fwrite(STDERR, "sync-wiki: these pages are in the manifest but have no file: " . implode(", ", $missing) . "\n");
    exit(1);
}

file_put_contents(
    $workDir . "/_Sidebar.md",
    "### Grafida\n\n" . trim(implode("\n", $sidebar)) . "\n"
);

file_put_contents(
    $workDir . "/_Footer.md",
    "Grafida is free software under the [GNU GPL v3](https://www.gnu.org/licenses/gpl-3.0.html) or later. "
    . "Joomla!® is a registered trademark of Open Source Matters, Inc. Grafida is not affiliated with or "
    . "endorsed by the Joomla! Project or Open Source Matters, Inc.\n\n"
    . "_This wiki is generated from [docs/](https://github.com/akeeba/grafida/tree/main/docs) in the main "
    . "repository. Edits made here are overwritten; please open a pull request against those files instead._\n"
);
' "$DOCS_DIR" "$WORK_DIR"

# Illustrations, if there are any. Flat by design — the manifest is the only
# structure either consumer understands.
if [ -d "$DOCS_DIR/images" ]; then
  mkdir -p "$WORK_DIR/images"
  cp "$DOCS_DIR"/images/* "$WORK_DIR/images/" 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# 3. Commit and push, but only if something actually changed.
# ---------------------------------------------------------------------------
if [ -z "$(git -C "$WORK_DIR" status --porcelain)" ]; then
  echo "Wiki is already up to date."
  exit 0
fi

git -C "$WORK_DIR" add --all
git -C "$WORK_DIR" status --short

if [ "$DRY_RUN" = "1" ]; then
  echo
  echo "Dry run: nothing committed or pushed. The prepared tree is in $WORK_DIR"
  exit 0
fi

REVISION="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)"
git -C "$WORK_DIR" commit --quiet -m "Sync documentation from docs/ (${REVISION})"
git -C "$WORK_DIR" push --quiet origin HEAD

echo "Wiki updated from docs/ (${REVISION})."
