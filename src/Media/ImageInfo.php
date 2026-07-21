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
 * Intrinsic pixel dimensions of raw image bytes.
 *
 * A single place for the `getimagesizefromstring()` call so it is not
 * reimplemented per caller — `MediaRepository::store()`/`replaceData()` need
 * it to fill the `width`/`height` columns, and `PublishService` needs it to
 * size the published `<img>` for a blob whose Media Manager response did not
 * report dimensions.
 */
final class ImageInfo
{
    /**
     * @return array{0: ?int, 1: ?int} [width, height], both null when the
     *         bytes cannot be decoded as an image (SVG and some AVIF/WebP
     *         fail here — that is fine, every caller treats these as
     *         optional/nullable).
     */
    public static function dimensions(string $data): array
    {
        $info = @getimagesizefromstring($data);

        if ($info === false) {
            return [null, null];
        }

        return [$info[0], $info[1]];
    }

    /**
     * The file extension a mime type is conventionally stored under. Shared
     * by `PublishService` (naming an inline `data:` image before it becomes
     * an offline blob) and the Local Media tab's rename endpoint (normalising
     * a user-typed filename against the blob's *actual* stored mime, so a
     * rename cannot make the filename lie about what the bytes are).
     */
    public static function extensionForMime(string $mime): string
    {
        return match (strtolower(trim($mime))) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/gif'               => 'gif',
            'image/webp'              => 'webp',
            'image/svg+xml'           => 'svg',
            'image/avif'              => 'avif',
            'image/bmp'               => 'bmp',
            default                   => 'png',
        };
    }
}
