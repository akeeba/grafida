<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Reference;

use Grafida\Http\HttpClient;
use Grafida\Http\Transport;
use Grafida\Site\Site;

/**
 * Works out which site template is active by looking at the asset URLs the
 * site's own home page emits.
 *
 * Joomla exposes the active template's name in every asset path it renders
 * (`/media/templates/site/<name>/…` since 4.1, `/templates/<name>/…` before
 * that), which is the only way to learn it without `core.admin` — the Global
 * Configuration route that serves the `template` value needs that permission,
 * and an article author's token does not have it.
 *
 * The discovered names are cached per site, so a site that is briefly
 * unreachable keeps resolving its template instead of falling back to the
 * stock-Cassiopeia guesses.
 */
final class TemplateDiscovery
{
    /** reference_cache kind under which the discovered template names live. */
    public const CACHE_KIND = 'template';

    /**
     * Template names that name no real template directory: `system` is Joomla's
     * shared fallback assets, not a site template.
     */
    private const IGNORED = ['system'];

    public function __construct(
        private readonly ReferenceRepository $repository,
        private readonly Transport $http = new HttpClient(5),
    ) {}

    /**
     * Returns the active template name(s), most likely first, refreshing from
     * the site's home page when possible and otherwise serving the cached list.
     *
     * More than one name can legitimately come back (a page may pull an asset
     * from another template), so callers should treat this as an ordered set of
     * candidates rather than a single answer.
     *
     * @return list<string>
     */
    public function templates(Site $site): array
    {
        $fresh = $this->fetch($site);

        if ($fresh !== [] && $site->id !== null) {
            $this->repository->put($site->id, self::CACHE_KIND, ['names' => $fresh]);

            return $fresh;
        }

        return $site->id !== null ? $this->cached($site->id) : [];
    }

    /** @return list<string> */
    private function cached(int $siteId): array
    {
        $row = $this->repository->get($siteId, self::CACHE_KIND);

        if ($row === null || !isset($row['payload']['names']) || !is_array($row['payload']['names'])) {
            return [];
        }

        return array_values(array_filter($row['payload']['names'], is_string(...)));
    }

    /** @return list<string> */
    private function fetch(Site $site): array
    {
        try {
            $response = $this->http->request('GET', rtrim($site->baseUrl, '/') . '/');
        } catch (\Throwable) {
            return [];
        }

        if (!$response->isSuccess() || trim($response->body) === '') {
            return [];
        }

        return $this->parse($response->body);
    }

    /**
     * Extracts template names from a rendered page's asset paths.
     *
     * This scans the raw HTML rather than walking the DOM on purpose: the name
     * appears in `<link>`/`<script>` attributes but equally inside inline
     * `<style>` blocks (`@import`, `url()`) and preload hints, and every one of
     * those is an equally good witness.
     *
     * @return list<string>
     */
    private function parse(string $html): array
    {
        // The media path is checked first so its (authoritative, Joomla 4.1+)
        // matches are ordered ahead of any legacy /templates/<name>/ ones.
        $patterns = [
            '#/media/templates/site/([A-Za-z0-9_.-]+)/#',
            '#(?<!/media)/templates/([A-Za-z0-9_.-]+)/#',
        ];

        $names = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $name) {
                if (!in_array($name, self::IGNORED, true)) {
                    // Keyed, so first appearance wins and duplicates collapse.
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }
}
