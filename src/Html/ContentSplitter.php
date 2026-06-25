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
 * Splits article HTML into Joomla's introtext / fulltext on the "read more"
 * marker — an `<hr>` element carrying the `readmore` class.
 *
 * Everything before the marker is the introtext; everything after is the
 * fulltext. If there is no marker the whole content is introtext.
 */
final class ContentSplitter
{
    /**
     * @return array{introtext: string, fulltext: string}
     */
    public function split(string $html): array
    {
        if (trim($html) === '') {
            return ['introtext' => '', 'fulltext' => ''];
        }

        $dom  = HtmlDocument::load($html);
        $body = HtmlDocument::body($dom);

        $marker = $this->findMarker($body);

        if ($marker === null) {
            return ['introtext' => trim($html), 'fulltext' => ''];
        }

        $intro = '';
        $full  = '';
        $seen  = false;

        // Iterate a static list because we mutate the live node list below.
        foreach (iterator_to_array($body->childNodes) as $node) {
            if ($node === $marker) {
                $seen = true;

                continue; // drop the marker itself
            }

            $html = HtmlDocument::saveNode($dom, $node);

            if ($seen) {
                $full .= $html;
            } else {
                $intro .= $html;
            }
        }

        return ['introtext' => trim($intro), 'fulltext' => trim($full)];
    }

    /**
     * Counts the read-more markers in the content (used by the editor to enforce
     * "at most one").
     */
    public function countMarkers(string $html): int
    {
        if (trim($html) === '') {
            return 0;
        }

        $dom   = HtmlDocument::load($html);
        $count = 0;

        foreach ($dom->getElementsByTagName('hr') as $hr) {
            if ($this->hasReadMoreClass($hr)) {
                ++$count;
            }
        }

        return $count;
    }

    private function findMarker(\DOMNode $body): ?\DOMElement
    {
        foreach ($body->childNodes as $node) {
            if ($node instanceof \DOMElement
                && strtolower($node->nodeName) === 'hr'
                && $this->hasReadMoreClass($node)) {
                return $node;
            }
        }

        return null;
    }

    private function hasReadMoreClass(\DOMElement $element): bool
    {
        $splitResult = preg_split('/\s+/', $element->getAttribute('class'));
        $classes     = $splitResult !== false ? $splitResult : [];

        return in_array('readmore', $classes, true);
    }
}
