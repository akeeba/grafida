<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Media;

/**
 * Builds the `boson://app/api/media/{id}/raw?rev=…` URL a local media blob is
 * referenced by — from the article HTML (gh-36) and from the Local Media
 * tab's listing.
 *
 * The `rev` token exists purely to defeat caching we do not control: the
 * webview's heuristic disk cache of custom-scheme GETs (gh-35), and, more
 * directly here, a plain `<img>` tag repainting its already-loaded copy after
 * an in-app edit — the same problem `mediaDisplayUrl()`'s `grafida_rev` query
 * parameter solves for Media Manager entries (gh-4). The server itself
 * ignores `rev` entirely (see `MediaController::mediaBlobRaw()`); the token
 * only needs to change whenever the blob's bytes do, and BOTH PHP (legacy
 * inline-`data:` draft migration, `.grafida` import) and JS (editor insert,
 * Local Media tab) mint this URL, so they must derive the token identically —
 * hence this single shared place rather than two copies of the formula.
 */
final class LocalMediaUrl
{
    /**
     * @param string $revisedAt the blob's `updated_at`, falling back to
     *        `created_at` when it has never been edited — the same
     *        COALESCE `MediaRepository::listForSite()` sorts by, so a blob
     *        that has never been touched still gets a stable, well-defined
     *        token instead of an empty one
     */
    public static function build(int $id, string $revisedAt): string
    {
        return \sprintf('boson://app/api/media/%d/raw?rev=%s', $id, self::token($id, $revisedAt));
    }

    /**
     * A short, URL-safe cache-busting token. Not a security control — merely
     * a value that changes whenever the blob does, so callers must not read
     * anything into it beyond "differs from the previous revision".
     */
    public static function token(int $id, string $revisedAt): string
    {
        return substr(sha1($revisedAt . '|' . $id), 0, 8);
    }
}
