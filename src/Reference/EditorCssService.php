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
 *
 * ⚠️ **The cache is read first and the network is not touched at all when it
 * hits.** Finding the stylesheet is expensive — {@see TemplateDiscovery} costs a
 * styles-API call plus a home-page fetch, and {@see self::candidatesFor()} then
 * walks up to eight URLs at 5 seconds apiece — and this used to be paid on
 * *every* editor open, in front of the user, before TinyMCE was created. It is
 * now paid only when {@see self::load()} is asked to `$refresh`: when a site is
 * connected or edited, and on a metadata refresh (manual or TTL-driven), which
 * is where every other per-site cache is warmed too.
 *
 * A **miss is cached as well** — an empty string, meaning "we looked and this
 * site has no editor.css we can reach" — or a site whose template ships none
 * would pay the whole candidate walk forever. It is written only when the site
 * actually answered: if every candidate threw, the site was unreachable and
 * nothing was learned, so the next open is free to try again.
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
     * Returns the editor CSS for a site: the cached copy when there is one, and
     * a fresh look over the network only when there is not, or when the caller
     * explicitly asks to refresh.
     *
     * A refresh that finds nothing keeps whatever was already cached — a site
     * that is briefly unreachable must not lose its styling.
     */
    public function load(Site $site, bool $refresh = false): ?string
    {
        $cached = $site->id !== null ? $this->repository->getEditorCss($site->id) : null;

        if (!$refresh && $cached !== null) {
            // '' is the cached miss: the site has no editor.css we can reach.
            return $cached !== '' ? $cached : null;
        }

        [$fresh, $answered] = $this->fetch($this->candidatesFor($site));

        if ($site->id === null) {
            return $fresh;
        }

        if ($fresh !== null) {
            $this->repository->putEditorCss($site->id, $fresh);

            return $fresh;
        }

        // Remember the miss, so the candidate walk is not repeated on every
        // editor open — but only if the site answered at all, and never over an
        // existing stylesheet (this run may simply have been offline).
        if ($answered && $cached === null) {
            $this->repository->putEditorCss($site->id, '');
        }

        return $cached !== null && $cached !== '' ? $cached : null;
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
     * Walks the candidates and returns the first stylesheet found, alongside
     * whether *any* candidate produced an HTTP response at all.
     *
     * That second value is what separates "this site has no editor.css" from
     * "we could not reach this site", which the caller needs before it writes a
     * miss to the cache.
     *
     * @param list<string> $urls
     *
     * @return array{0: ?string, 1: bool}
     */
    private function fetch(array $urls): array
    {
        $answered = false;

        foreach ($urls as $url) {
            try {
                $response = $this->http->request('GET', $url);
            } catch (\Throwable) {
                continue;
            }

            $answered = true;

            if ($response->isSuccess() && trim($response->body) !== '') {
                return [$this->rebaser->rebase($response->body, $url), true];
            }
        }

        return [null, $answered];
    }
}
