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
use Grafida\Http\HttpException;
use Grafida\Http\Transport;
use Grafida\Joomla\ApiClient;
use Grafida\Joomla\ApiException;
use Grafida\Site\Site;
use Grafida\Site\SiteService;

/**
 * Works out which site templates are in play, most likely first, from two
 * independent witnesses.
 *
 * The **template styles API** is authoritative: it names the site's default
 * style's template outright. It is also the only way to see a **child**
 * template, because Joomla resolves a child's assets against its parent
 * whenever the child does not override them — a child that only ships an
 * `editor.css` (never loaded on the front-end) renders no asset URL of its own
 * and so is invisible to any amount of page scanning.
 *
 * Scanning the **home page** for the asset paths Joomla emits
 * (`/media/templates/site/<name>/…` since 4.1, `/templates/<name>/…` before
 * that) is kept as the second witness. It needs no permissions at all, and on a
 * site running a child template it conveniently yields the *parent* — exactly
 * the right fallback for a child that inherits its parent's `editor.css`.
 *
 * The names are cached per site, so a site that is briefly unreachable keeps
 * resolving its template instead of falling back to the stock-Cassiopeia
 * guesses.
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
        private readonly SiteService $sites,
        private readonly ApiClient $api,
        private readonly Transport $http = new HttpClient(5),
    ) {}

    /**
     * Returns the template name(s), most likely first, refreshing from the
     * network when possible and otherwise serving the cached list.
     *
     * More than one name legitimately comes back — a child template and its
     * parent, a multilingual site's per-language homes, or a page pulling an
     * asset from another template — so callers should treat this as an ordered
     * set of candidates rather than a single answer.
     *
     * @return list<string>
     */
    public function templates(Site $site): array
    {
        $names = [];

        // Keyed, so the API's (authoritative) answers keep their lead, the home
        // page's contribute whatever they add, and duplicates collapse.
        foreach ([...$this->fromApi($site), ...$this->fromHomePage($site)] as $name) {
            $names[$name] = true;
        }

        $fresh = array_keys($names);

        if ($fresh !== [] && $site->id !== null) {
            $this->repository->put($site->id, self::CACHE_KIND, ['names' => $fresh]);

            return $fresh;
        }

        return $site->id !== null ? $this->cached($site->id) : [];
    }

    /**
     * The templates behind the site's *home* styles, the default one first.
     *
     * Only home styles are considered. A style bound to some menu item tells us
     * nothing here — we cannot know which menu item an article will render
     * under — while every *unassigned* style names a template that is merely
     * installed, and trying its `editor.css` would put a stylesheet the site
     * does not use ahead of the honest fallbacks.
     *
     * @return list<string>
     */
    private function fromApi(Site $site): array
    {
        $token = $this->sites->tokenFor($site);

        if ($token === null || $site->apiBase === null) {
            return [];
        }

        try {
            $styles = $this->api->listTemplateStyles($site->apiBase, $token);
        } catch (ApiException | HttpException) {
            return [];
        }

        $default = [];
        $language = [];

        foreach ($styles as $style) {
            $name = $style['template'] ?? null;
            $home = $style['home'] ?? null;

            if (!is_string($name) || $name === '' || in_array($name, self::IGNORED, true)) {
                continue;
            }

            // `home` is "1" for the site default, a language tag ("en-GB") for a
            // multilingual site's per-language home, and "0"/"" for the rest.
            $home = is_scalar($home) ? (string) $home : '';

            if ($home === '1') {
                $default[] = $name;
            } elseif ($home !== '' && $home !== '0') {
                $language[] = $name;
            }
        }

        return [...$default, ...$language];
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
    private function fromHomePage(Site $site): array
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
