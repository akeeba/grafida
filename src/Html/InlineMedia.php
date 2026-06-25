<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Html;

/**
 * Handles images that were inserted while editing offline.
 *
 * Such images are embedded as `<img src="data:..." data-grafida-media-id="N">`.
 * Before publishing, the data: URIs must be uploaded and swapped for the real
 * public URLs returned by the Media Manager.
 */
final class InlineMedia
{
    public const ATTRIBUTE = 'data-grafida-media-id';

    /**
     * Returns the media-blob IDs referenced by offline images still carrying a
     * data: URI (i.e. not yet uploaded).
     *
     * @return list<int>
     */
    public function pendingMediaIds(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $dom = HtmlDocument::load($html);
        $ids = [];

        foreach ($dom->getElementsByTagName('img') as $img) {
            $id  = $img->getAttribute(self::ATTRIBUTE);
            $src = $img->getAttribute('src');

            if ($id !== '' && str_starts_with($src, 'data:')) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Replaces the data: URI of each offline image with its uploaded public URL.
     *
     * @param array<int, string> $urls Map of media-blob ID => public URL.
     *
     * @return string The rewritten HTML.
     */
    public function applyUploadedUrls(string $html, array $urls): string
    {
        if (trim($html) === '' || $urls === []) {
            return $html;
        }

        $dom = HtmlDocument::load($html);

        foreach ($dom->getElementsByTagName('img') as $img) {
            $id = $img->getAttribute(self::ATTRIBUTE);

            if ($id === '' || !isset($urls[(int) $id])) {
                continue;
            }

            $img->setAttribute('src', $urls[(int) $id]);
            $img->removeAttribute(self::ATTRIBUTE);
        }

        return HtmlDocument::innerHtml($dom);
    }
}
