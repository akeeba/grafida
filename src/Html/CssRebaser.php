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
 * Rewrites relative `url(...)` references inside an editor.css so they resolve
 * against the remote site, regardless of where the editor loads the CSS from.
 *
 * Absolute URLs (http:, https:, protocol-relative //, and data:) are left
 * untouched. Root-relative URLs (/foo) are prefixed with the site origin.
 * Document-relative URLs (foo, ../foo) are resolved against the URL the CSS
 * file itself was fetched from.
 */
final class CssRebaser
{
    /**
     * @param string $css        The stylesheet text.
     * @param string $cssUrl      Absolute URL the CSS was fetched from.
     */
    public function rebase(string $css, string $cssUrl): string
    {
        $origin  = $this->origin($cssUrl);
        $cssDir  = $this->directoryOf($cssUrl);

        return (string) preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            function (array $m) use ($origin, $cssDir): string {
                $quote = $m[1];
                $url   = trim($m[2]);

                $resolved = $this->resolve($url, $origin, $cssDir);

                return 'url(' . $quote . $resolved . $quote . ')';
            },
            $css
        );
    }

    private function resolve(string $url, string $origin, string $cssDir): string
    {
        // Already absolute or non-resolvable — leave as-is.
        if ($url === ''
            || str_starts_with($url, 'data:')
            || str_starts_with($url, 'http://')
            || str_starts_with($url, 'https://')
            || str_starts_with($url, '//')
            || str_starts_with($url, '#')) {
            return $url;
        }

        // Root-relative: prepend the origin.
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }

        // Document-relative: resolve against the CSS file's directory.
        return $this->collapse($cssDir . '/' . $url);
    }

    private function origin(string $url): string
    {
        $parts  = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? '';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    private function directoryOf(string $url): string
    {
        $origin    = $this->origin($url);
        $parsedPath = parse_url($url, \PHP_URL_PATH);
        $path       = is_string($parsedPath) ? $parsedPath : '/';
        $dir        = rtrim(str_replace('\\', '/', \dirname($path)), '/');

        return $origin . ($dir === '' ? '' : $dir);
    }

    /** Collapses "." and ".." segments in an absolute URL. */
    private function collapse(string $url): string
    {
        $origin = $this->origin($url);
        $path   = (string) parse_url($url, \PHP_URL_PATH);

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $origin . '/' . implode('/', $segments);
    }
}
