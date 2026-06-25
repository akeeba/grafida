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
    /** Common locations a Joomla template exposes its editor stylesheet at. */
    private const CANDIDATE_PATHS = [
        '/media/templates/site/cassiopeia/css/editor.css',
        '/templates/cassiopeia/css/editor.css',
        '/templates/system/css/editor.css',
    ];

    public function __construct(
        private readonly ReferenceRepository $repository,
        private readonly CssRebaser $rebaser = new CssRebaser(),
        private readonly Transport $http = new HttpClient(5),
    ) {}

    /**
     * Returns the editor CSS for a site, refreshing from the network when
     * possible and otherwise serving the cached copy.
     *
     * @param list<string>|null $candidatePaths Override the default search paths
     *                                          (e.g. when the template is known).
     */
    public function load(Site $site, ?array $candidatePaths = null): ?string
    {
        $fresh = $this->fetch($site, $candidatePaths ?? self::CANDIDATE_PATHS);

        if ($fresh !== null && $site->id !== null) {
            $this->repository->putEditorCss($site->id, $fresh);

            return $fresh;
        }

        return $site->id !== null ? $this->repository->getEditorCss($site->id) : null;
    }

    /**
     * @param list<string> $paths
     */
    private function fetch(Site $site, array $paths): ?string
    {
        foreach ($paths as $path) {
            $url = $site->baseUrl . $path;

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
