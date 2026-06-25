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
 * Helpers for loading an HTML fragment into a DOMDocument and serialising parts
 * of it back to a string, preserving UTF-8 and without injecting a doctype or
 * wrapping <html>/<body> tags into the output.
 */
final class HtmlDocument
{
    public static function load(string $html): \DOMDocument
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);

        // The XML encoding declaration forces UTF-8 interpretation; LIBXML flags
        // stop a doctype / html / body wrapper from being implied where possible.
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . '<div id="grafida-root">' . $html . '</div>',
            \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD | \LIBXML_NOERROR | \LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /**
     * Returns the element that holds the loaded fragment's children (our wrapper
     * div, or the body if the parser produced one).
     */
    public static function body(\DOMDocument $dom): \DOMElement
    {
        $root = $dom->getElementById('grafida-root');

        if ($root instanceof \DOMElement) {
            return $root;
        }

        $body = $dom->getElementsByTagName('body')->item(0);

        if ($body instanceof \DOMElement) {
            return $body;
        }

        return $dom->documentElement ?? $dom->appendChild($dom->createElement('div'));
    }

    public static function saveNode(\DOMDocument $dom, \DOMNode $node): string
    {
        return (string) $dom->saveHTML($node);
    }

    /** Serialises the inner HTML of the fragment wrapper. */
    public static function innerHtml(\DOMDocument $dom): string
    {
        $body = self::body($dom);
        $html = '';

        foreach ($body->childNodes as $child) {
            $html .= self::saveNode($dom, $child);
        }

        return $html;
    }
}
