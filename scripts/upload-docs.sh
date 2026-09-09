#!/usr/bin/env bash
#
# Grafida — Joomla content editing, untethered.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# Publishes the in-app Help pages to the Grafida.app site over SFTP.
#
#     scripts/upload-docs.sh [--dry-run] [--verbose]
#
# The pages under docs/ are BOTH the app's own Help (compiled into every binary — boson.json lists
# docs/ in build.directories) and the public documentation at grafida.app. This script mirrors the
# second from the first. It uploads the Markdown pages, `_manifest.json` and everything in
# `docs/images/` — nothing else in the directory, and no other subdirectory, because the
# documentation set is deliberately flat apart from that one image folder
# (.claude/rules/documentation.md).
#
# ⚠️ **This is NOT a release step**, which is why it is its own script and its own document
# (build/readme/05-documentation-publishing.md). A typo fix, a corrected instruction or a new
# screenshot is worth publishing the day it is committed; waiting for the next release would leave
# the site wrong for weeks. The reverse is also true — the site can be republished without building
# anything, and an installed copy keeps whatever pages it shipped with.
#
# ⚠️ **The manifest is uploaded LAST, deliberately.** It is the table of contents; every other file
# is a page it points at. Sending it after its pages means a reader who loads the site mid-upload
# sees the old contents, never a new entry linking to a page that is not there yet.
#
# ⚠️ **Nothing is ever deleted from the server.** A page renamed or dropped from the documentation
# set leaves its old file behind, still reachable by its old URL. Remove it by hand, in the same
# session, or the site keeps serving a page the app no longer has.
#
# CONFIGURATION
#
# Read from build/build.properties (the gitignored private build configuration — copy
# build/build.sample.properties and fill it in) or from the environment, which wins:
#
#   docs.sftp.host    DOCS_SFTP_HOST    required   the ~/.ssh/config Host alias to connect to (a
#                                                  real hostname works too, but see below for why
#                                                  the alias is the point)
#   docs.sftp.path    DOCS_SFTP_PATH    required   the target directory, absolute or relative to
#                                                  the login directory. It must already exist; the
#                                                  script does not create it.
#   docs.sftp.user    DOCS_SFTP_USER    optional   only if ~/.ssh/config sets `User` for the host.
#                                                  ⚠️ **Without either, ssh connects as your LOCAL
#                                                  login name**, which on shared hosting is never
#                                                  the right account — set one or the other.
#   docs.sftp.port    DOCS_SFTP_PORT    optional   omit it and ~/.ssh/config's `Port` for the host
#                                                  applies, itself defaulting to 22
#
# ⚠️ **The connection is `~/.ssh/config`'s business, not this script's.** Identity, user, port,
# jump hosts and agent all come from the entry for the host. That is deliberate: the key lives in
# 1Password, reached through its SSH agent by an `IdentityAgent` line in that file, and every
# connection is authorised by a biometric prompt the agent raises itself. There is consequently
# **no key setting here, and no password setting, and neither should be added** — a path to a key
# file would route around the agent.
#
# ⚠️ **`sftp -b` implies `BatchMode=yes`**, which stops *ssh* from prompting — for a password, a
# passphrase, or an unknown host key. It does not stop the 1Password prompt, which is the agent's
# own window and out of ssh's hands entirely. So the biometric prompt still appears and still has
# to be answered; what fails outright is a host that wants a password, or one whose key is not yet
# in known_hosts. Connect once by hand to accept the host key.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCS_DIR="$ROOT/docs"
IMAGE_DIR="$DOCS_DIR/images"
PROPERTIES_FILE="${GRAFIDA_BUILD_PROPERTIES:-$ROOT/build/build.properties}"

dry_run=0
verbose=0

die() { echo "upload-docs: error: $*" >&2; exit 1; }

usage() {
    sed -n '7,9p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    echo
    echo "  --dry-run   list what would be sent and print the SFTP batch, connect to nothing"
    echo "  --verbose   pass -v to sftp (prints the protocol conversation, no credentials)"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run) dry_run=1 ;;
        --verbose) verbose=1 ;;
        -h|--help) usage; exit 0 ;;
        *) die "unknown option: $1 (try --help)" ;;
    esac
    shift
done

# --- configuration -----------------------------------------------------------------------------

# Reads one key from build/build.properties, applying Phing's rules as far as they matter here: a
# line is `key=value`, `;` and `#` start a comment, the last assignment wins, and the value is
# trimmed. The file is never sourced — it holds a GitHub token and the CDN password, and nothing in
# it should ever be executed.
property_value() {
    local key="$1" line

    [ -f "$PROPERTIES_FILE" ] || return 0

    line="$(grep -E "^[[:space:]]*${key//./\\.}[[:space:]]*=" "$PROPERTIES_FILE" | tail -n 1 || true)"
    [ -n "$line" ] || return 0

    printf '%s' "${line#*=}" | tr -d '\r' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//'
}

host="${DOCS_SFTP_HOST:-$(property_value docs.sftp.host)}"
target="${DOCS_SFTP_PATH:-$(property_value docs.sftp.path)}"
user="${DOCS_SFTP_USER:-$(property_value docs.sftp.user)}"
port="${DOCS_SFTP_PORT:-$(property_value docs.sftp.port)}"

missing=()
[ -n "$host" ] || missing+=("docs.sftp.host")
[ -n "$target" ] || missing+=("docs.sftp.path")

if [ ${#missing[@]} -gt 0 ]; then
    echo "upload-docs: error: not configured — ${missing[*]} unset in the environment and in" >&2
    echo "             $PROPERTIES_FILE" >&2
    echo "             See the header of this script, or build/readme/05-documentation-publishing.md." >&2
    exit 1
fi

[ -d "$DOCS_DIR" ] || die "no documentation directory at $DOCS_DIR"
[ -f "$DOCS_DIR/_manifest.json" ] || die "no _manifest.json in $DOCS_DIR"

# --- what to send ------------------------------------------------------------------------------

shopt -s nullglob

pages=("$DOCS_DIR"/*.md)

shopt -s nocaseglob
images=("$IMAGE_DIR"/*.png "$IMAGE_DIR"/*.jpg "$IMAGE_DIR"/*.jpeg "$IMAGE_DIR"/*.gif "$IMAGE_DIR"/*.svg "$IMAGE_DIR"/*.webp)
shopt -u nocaseglob

[ ${#pages[@]} -gt 0 ] || die "no Markdown pages in $DOCS_DIR"

# Every slug the manifest names must have a page to open. Publishing a table of contents that
# points at a file the server does not have is the one failure a reader sees immediately, and the
# check costs nothing. `composer test` pins the same agreement from the other side
# (HelpRoutingTest::testEveryAdvertisedPageRenders()).
for slug in $(grep -oE '"slug"[[:space:]]*:[[:space:]]*"[^"]+"' "$DOCS_DIR/_manifest.json" | sed -E 's/.*"([^"]+)"$/\1/'); do
    [ -f "$DOCS_DIR/$slug.md" ] || die "_manifest.json lists \"$slug\" but $slug.md does not exist"
done

for page in "${pages[@]}"; do
    slug="$(basename "$page" .md)"

    grep -qE "\"slug\"[[:space:]]*:[[:space:]]*\"${slug}\"" "$DOCS_DIR/_manifest.json" \
        || echo "upload-docs: warning: $slug.md is in no manifest entry — it will be uploaded but nothing links to it" >&2
done

# --- send it -----------------------------------------------------------------------------------

batch="$(mktemp)"
trap 'rm -f "$batch"' EXIT

{
    printf 'cd "%s"\n' "$target"

    for page in "${pages[@]}"; do
        printf 'put "%s" "%s"\n' "$page" "$(basename "$page")"
    done

    # `-mkdir` because an existing directory is the normal case and its error would otherwise abort
    # the batch. `images/` is the one subdirectory the documentation set has.
    if [ ${#images[@]} -gt 0 ]; then
        printf -- '-mkdir "images"\n'

        for image in "${images[@]}"; do
            printf 'put "%s" "images/%s"\n' "$image" "$(basename "$image")"
        done
    fi

    # The manifest goes last; see the header.
    printf 'put "%s" "_manifest.json"\n' "$DOCS_DIR/_manifest.json"
    printf 'bye\n'
} > "$batch"

# Both are ~/.ssh/config's answer to give when unset, so leave them out of the destination
# entirely rather than guessing a default here and overriding the file.
destination="${user:+$user@}${host}"

# Which account this actually connects as is worth printing, because getting it wrong is the
# likeliest misconfiguration and the error it produces — a bare "Permission denied" — does not say
# so. `ssh -G` resolves the whole config for the host without connecting to anything, and its
# answer when nothing sets `User` is your local login name.
resolved_user="$user"

if [ -z "$resolved_user" ]; then
    ssh_g=(ssh -G)
    if [ -n "$port" ]; then ssh_g+=(-p "$port"); fi

    resolved_user="$("${ssh_g[@]}" "$host" 2>/dev/null | awk '$1 == "user" { print $2; exit }' || true)"

    if [ "$resolved_user" = "$(id -un)" ]; then
        echo "upload-docs: warning: no docs.sftp.user and no \`User\` for $host in ~/.ssh/config —" >&2
        echo "             connecting as your local login name, \"$resolved_user\". Set one of the" >&2
        echo "             two if that is wrong." >&2
    fi
fi

echo "Publishing ${#pages[@]} page(s), ${#images[@]} image(s) and _manifest.json"
echo "        to ${resolved_user:-?}@${host}${port:+ (port $port)}:${target}"

if [ "$dry_run" -eq 1 ]; then
    echo
    echo "--dry-run — the SFTP batch that would have run:"
    echo
    sed 's/^/    /' "$batch"
    exit 0
fi

sftp_args=()

if [ -n "$port" ]; then sftp_args+=(-P "$port"); fi
if [ "$verbose" -eq 1 ]; then sftp_args+=(-v); fi

sftp_args+=(-b "$batch")

echo "Answer the 1Password prompt if one appears."

sftp "${sftp_args[@]}" "$destination"

echo "Done. Remember that renamed or removed pages are still on the server; delete them by hand."
