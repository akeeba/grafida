<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Reference;

use Grafida\Html\CssRebaser;
use Grafida\Http\HttpClient;
use Grafida\Http\Transport;
use Grafida\Site\Site;

/**
 * Loads a site template's editor.css, rebases its relative url() references to
 * absolute URLs, and caches the result. On any failure (including a 5 second
 * timeout) it falls back to the cached copy; if there is none, it returns null
 * so the editor simply runs without site-specific styling.
 */
final class EditorCssService
{
    /**
     * Last-resort locations, tried only after every template
     * {@see TemplateDiscovery} found has been ruled out. They cover a stock
     * Cassiopeia, then Joomla's own shared editor stylesheet — which is what a
     * template without an `editor.css` of its own effectively falls back to, so
     * it is the honest final answer rather than no styling at all.
     */
    private const CANDIDATE_PATHS = [
        '/media/templates/site/cassiopeia/css/editor.css',
        '/templates/cassiopeia/css/editor.css',
        '/media/system/css/editor.css',
    ];

    public function __construct(
        private readonly ReferenceRepository $repository,
        private readonly TemplateDiscovery $templates,
        private readonly CssRebaser $rebaser = new CssRebaser(),
        private readonly Transport $http = new HttpClient(5),
    ) {}

    /**
     * Returns the editor CSS for a site, refreshing from the network when
     * possible and otherwise serving the cached copy.
     */
    public function load(Site $site): ?string
    {
        $fresh = $this->fetch($this->candidatesFor($site));

        if ($fresh !== null && $site->id !== null) {
            $this->repository->putEditorCss($site->id, $fresh);

            return $fresh;
        }

        return $site->id !== null ? $this->repository->getEditorCss($site->id) : null;
    }

    /**
     * The ordered URLs to try for a site: the user's explicit override first (it
     * exists precisely because the guesses were wrong), then the stylesheet of
     * each discovered template, then the stock-Cassiopeia fallbacks.
     *
     * @return list<string>
     */
    private function candidatesFor(Site $site): array
    {
        $paths = [];

        foreach ($this->templates->templates($site) as $template) {
            $paths[] = '/media/templates/site/' . $template . '/css/editor.css';
            $paths[] = '/templates/' . $template . '/css/editor.css';
        }

        foreach (self::CANDIDATE_PATHS as $path) {
            $paths[] = $path;
        }

        $urls = $site->editorCssUrl !== null ? [$this->absolute($site, $site->editorCssUrl)] : [];

        foreach ($paths as $path) {
            $urls[] = $site->baseUrl . $path;
        }

        return array_values(array_unique($urls));
    }

    /** Resolves the user's override, which may be an absolute URL or a site-root-relative path. */
    private function absolute(Site $site, string $url): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            return $url;
        }

        return rtrim($site->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    /**
     * @param list<string> $urls
     */
    private function fetch(array $urls): ?string
    {
        foreach ($urls as $url) {
            try {
                $response = $this->http->request('GET', $url);
            } catch (\Throwable) {
                continue;
            }

            if ($response->isSuccess() && trim($response->body) !== '') {
                return $this->rebaser->rebase($response->body, $url);
            }
        }

        return null;
    }
}
