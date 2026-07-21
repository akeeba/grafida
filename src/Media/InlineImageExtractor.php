<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Media;

use Grafida\Html\InlineMedia;

/**
 * Converts a draft's legacy inline `<img src="data:...">` images into
 * `media_blobs` rows referenced by their `boson://app/api/media/{id}/raw`
 * local URL (gh-36).
 *
 * A draft saved before gh-36 embedded a pasted/dropped image as a base64
 * `data:` URI directly in `drafts.html` — routinely several MB for a single
 * screenshot — which is why opening (or source-editing) such a draft used to
 * be slow. `Html\InlineMedia::rewriteToLocalUrls()` still keeps it
 * *publishable* (see that class), but only converting it makes it fast again;
 * this is the one-time migration that runs when the draft is opened
 * ({@see \Grafida\Http\Controller\DraftController::getDraft()}) and, for a
 * legacy `.grafida` export, on import.
 */
final class InlineImageExtractor
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly InlineMedia $inlineMedia = new InlineMedia(),
    ) {}

    /**
     * Replaces every inline `data:` image in the HTML with a stored offline
     * blob referenced by its local URL. Returns the rewritten HTML unchanged
     * when there was nothing to convert (the common case once a draft has
     * been through this once, since a `data:` prefix never reappears).
     *
     * Never throws: a draft that fails to open is a far worse outcome than
     * one that stays slow, so a single unparsable image is left exactly as
     * found (see {@see convert()}) and the rest still convert.
     */
    public function extract(string $html, int $siteId, ?int $draftId): string
    {
        if (!str_contains($html, 'data:')) {
            // Cheap pre-filter: skip the DOM parse entirely for the (eventually
            // universal) case of a draft with no inline data: image left to migrate.
            return $html;
        }

        return $this->inlineMedia->rewriteToLocalUrls(
            $html,
            fn (?int $mediaId, string $dataUri): array => $this->convert($siteId, $draftId, $mediaId, $dataUri),
        );
    }

    /**
     * @return array{src: string, width?: ?int, height?: ?int}
     */
    private function convert(int $siteId, ?int $draftId, ?int $mediaId, string $dataUri): array
    {
        // A *tagged* legacy data: image: the pre-gh-36 upload handler already
        // stored these exact bytes in media_blobs when the image was inserted
        // (data-grafida-media-id names that row), so there is nothing to
        // decode — just point the src at the existing blob.
        if ($mediaId !== null) {
            $existing = $this->localUrlFor($mediaId);

            if ($existing !== null) {
                return $existing;
            }
        }

        // Untagged (pasted/dropped straight into the editor, which never went
        // through the upload handler) or the tag names a since-deleted blob:
        // decode the inline bytes and store them fresh.
        $newId = $this->storeDataUri($siteId, $draftId, $dataUri);

        if ($newId === null) {
            // Could not be decoded as an image at all — leave this one <img>
            // exactly as it was rather than failing the whole conversion.
            return ['src' => $dataUri];
        }

        $result = $this->localUrlFor($newId);

        // storeDataUri() just inserted this row, so it is always found — but
        // PHPStan (and a future refactor) should not have to take that on faith.
        return $result ?? ['src' => $dataUri];
    }

    /**
     * Decodes a `data:` URI image and stores it as a new offline blob,
     * returning its id (or null when the URI cannot be parsed into image
     * bytes). Shared by this migration and {@see \Grafida\Publish\PublishService},
     * which hits the same "untagged inline image" case for one pasted straight
     * into the editor and never uploaded through the normal handler.
     */
    public function storeDataUri(int $siteId, ?int $draftId, string $dataUri): ?int
    {
        if (preg_match('#^data:([^;,]*)(;base64)?,(.*)$#s', $dataUri, $m) !== 1) {
            return null;
        }

        $mime = $m[1] !== '' ? $m[1] : 'image/png';
        $raw  = $m[2] !== '' ? base64_decode($m[3], true) : rawurldecode($m[3]);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $filename = 'inline-image.' . ImageInfo::extensionForMime($mime);
        [$width, $height] = ImageInfo::dimensions($raw);

        return $this->media->store($siteId, $draftId, $filename, $mime, $raw, $width, $height);
    }

    /**
     * @return array{src: string, width: ?int, height: ?int}|null null when the
     *         blob no longer exists (deleted from the Local Media tab).
     */
    private function localUrlFor(int $mediaId): ?array
    {
        $meta = $this->media->findMeta($mediaId);

        if ($meta === null) {
            return null;
        }

        return [
            'src'    => LocalMediaUrl::build($mediaId, $meta['updated_at'] ?? $meta['created_at']),
            'width'  => $meta['width'],
            'height' => $meta['height'],
        ];
    }
}
