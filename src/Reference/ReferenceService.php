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
use Grafida\Joomla\ApiClient;
use Grafida\Joomla\ApiException;
use Grafida\Http\HttpException;
use Grafida\Site\Site;
use Grafida\Site\SiteService;

/**
 * Loads and caches the reference data a site needs while editing: categories,
 * tags, view access levels and custom-field definitions.
 *
 * Each list is fetched from the API and cached in SQLite. Reads return the
 * cached copy unless a refresh is requested or the cache is empty. A dedicated
 * short-timeout API client is used so a briefly unreachable site cannot hang
 * the editor or the connection flow.
 */
final class ReferenceService
{
    public const KIND_CATEGORIES = 'categories';
    public const KIND_TAGS       = 'tags';
    public const KIND_LEVELS     = 'levels';
    public const KIND_FIELDS     = 'fields';
    public const KIND_LANGUAGES  = 'languages';
    public const KIND_CONFIG     = 'config';

    /**
     * The kinds a staleness check considers. Deliberately excludes
     * {@see KIND_CONFIG}: its route needs `core.admin`, so a 403 is the
     * healthy case for most sites and a site that can never cache it would
     * otherwise report "never fetched" forever and refresh on every visit.
     *
     * @var list<string>
     */
    private const REFRESHABLE_KINDS = [
        self::KIND_CATEGORIES,
        self::KIND_TAGS,
        self::KIND_LEVELS,
        self::KIND_FIELDS,
        self::KIND_LANGUAGES,
    ];

    public function __construct(
        private readonly ReferenceRepository $repository,
        private readonly SiteService $sites,
        private readonly ApiClient $api = new ApiClient(new HttpClient(8)),
    ) {}

    /** @return list<array<string, mixed>> */
    public function categories(Site $site, bool $refresh = false, bool $bestEffort = false): array
    {
        return $this->load($site, self::KIND_CATEGORIES, $refresh, $bestEffort, fn (string $b, string $t) => $this->api->listCategories($b, $t));
    }

    /** @return list<array<string, mixed>> */
    public function tags(Site $site, bool $refresh = false, bool $bestEffort = false): array
    {
        return $this->load($site, self::KIND_TAGS, $refresh, $bestEffort, fn (string $b, string $t) => $this->api->listTags($b, $t));
    }

    /** @return list<array<string, mixed>> */
    public function accessLevels(Site $site, bool $refresh = false, bool $bestEffort = false): array
    {
        return $this->load($site, self::KIND_LEVELS, $refresh, $bestEffort, fn (string $b, string $t) => $this->api->listAccessLevels($b, $t));
    }

    /** @return list<array<string, mixed>> */
    public function fields(Site $site, bool $refresh = false, bool $bestEffort = false): array
    {
        return $this->load($site, self::KIND_FIELDS, $refresh, $bestEffort, fn (string $b, string $t) => $this->fetchFields($b, $t));
    }

    /**
     * The site's article custom fields, each carrying `assigned_cat_ids` — the
     * categories it is used in.
     *
     * That costs **one extra request per field**, because the list endpoint does
     * not render the assignment and the API accepts no category filter (see
     * {@see ApiClient::getArticleField()}). It is paid only when the `fields`
     * cache is actually refreshed, not on every editor open.
     *
     * A field whose item request fails keeps the assignment the list gave it, or
     * falls back to `[0]` — "every category", which is how Grafida behaved
     * before it read the assignment at all. Hiding a field because one request
     * failed would silently drop whatever the user had typed into it, so this
     * degrades towards showing too much rather than too little.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchFields(string $base, string $token): array
    {
        $fields = $this->api->listArticleFields($base, $token);

        foreach ($fields as $index => $field) {
            if (isset($field['assigned_cat_ids'])) {
                continue;
            }

            $id = $field['id'] ?? null;

            if (!is_int($id) || $id <= 0) {
                continue;
            }

            try {
                $item = $this->api->getArticleField($base, $token, $id);
            } catch (ApiException | HttpException) {
                $fields[$index]['assigned_cat_ids'] = [0];

                continue;
            }

            $assigned = $item['assigned_cat_ids'] ?? null;

            $fields[$index]['assigned_cat_ids'] = is_array($assigned) && $assigned !== []
                ? array_values($assigned)
                : [0];
        }

        return $fields;
    }

    /**
     * The site's installed content languages (what an article may be assigned to).
     *
     * @return list<array<string, mixed>>
     */
    public function contentLanguages(Site $site, bool $refresh = false, bool $bestEffort = false): array
    {
        return $this->load($site, self::KIND_LANGUAGES, $refresh, $bestEffort, fn (string $b, string $t) => $this->api->listContentLanguages($b, $t));
    }

    /**
     * Whether the site has the Global Configuration "Unicode Aliases" option
     * (`unicodeslugs`) turned on, i.e. whether an alias keeps its non-ASCII
     * letters instead of being transliterated.
     *
     * Unlike the reference lists this is **never** strict, whatever the caller
     * asks: the route it comes from needs `core.admin`, which a Grafida user
     * publishing articles usually will not have, so a 403 is the normal case
     * for a perfectly healthy site and must not turn the manual refresh into an
     * error. An unreadable value falls back to the cached answer, then to false
     * — Joomla's own default, and the mode Grafida has always assumed.
     */
    public function unicodeSlugs(Site $site, bool $refresh = false): bool
    {
        if ($site->id === null) {
            return false;
        }

        if (!$refresh) {
            $cached = $this->repository->get($site->id, self::KIND_CONFIG);

            if ($cached !== null) {
                return (bool) ($cached['payload']['unicodeslugs'] ?? false);
            }
        }

        $token = $this->sites->tokenFor($site);

        if ($token === null || $site->apiBase === null) {
            return $this->cachedUnicodeSlugs($site->id);
        }

        try {
            $value = $this->api->getConfigValue($site->apiBase, $token, 'unicodeslugs');
        } catch (ApiException | HttpException) {
            return $this->cachedUnicodeSlugs($site->id);
        }

        // A site whose token lacks core.admin answers 403 (handled above); a
        // successful read that simply has no such key is a site we cannot know
        // about either, so leave any cached answer alone rather than write a
        // guess over it.
        if ($value === null) {
            return $this->cachedUnicodeSlugs($site->id);
        }

        // Joomla itself asks `unicodeslugs == 1`, and configuration.php may hold
        // a bool, an int or a numeric string depending on how it was written.
        $enabled = in_array($value, [true, 1, '1'], true);

        $this->repository->put($site->id, self::KIND_CONFIG, ['unicodeslugs' => $enabled]);

        return $enabled;
    }

    private function cachedUnicodeSlugs(int $siteId): bool
    {
        $cached = $this->repository->get($siteId, self::KIND_CONFIG);

        return (bool) ($cached['payload']['unicodeslugs'] ?? false);
    }

    /**
     * When the site's cached reference data was last fetched, as a naive UTC
     * `Y-m-d H:i:s` string — the **oldest** of the reference kinds, so a
     * caller deciding whether to refresh sees the weakest link rather than
     * the strongest.
     *
     * Returns null when any kind has never been cached at all: a partially
     * warmed cache is, for freshness purposes, no cache.
     */
    public function fetchedAt(Site $site): ?string
    {
        if ($site->id === null) {
            return null;
        }

        $oldest = null;

        foreach (self::REFRESHABLE_KINDS as $kind) {
            $cached = $this->repository->get($site->id, $kind);

            if ($cached === null) {
                return null;
            }

            if ($oldest === null || $cached['fetched_at'] < $oldest) {
                $oldest = $cached['fetched_at'];
            }
        }

        return $oldest;
    }

    /**
     * Refreshes every reference list for a site from the network, best-effort.
     *
     * Used when connecting a site (and as a short-timeout attempt when opening
     * the editor) so the cache is warm before any selector is rendered. A
     * network failure for any one list is swallowed, leaving its cached copy —
     * if any — intact; connecting a site never fails because of this.
     */
    public function sync(Site $site): void
    {
        $this->categories($site, true, true);
        $this->tags($site, true, true);
        $this->accessLevels($site, true, true);
        $this->fields($site, true, true);
        $this->contentLanguages($site, true, true);
        $this->unicodeSlugs($site, true);
    }

    /**
     * @param callable(string, string): list<array<string, mixed>> $fetch
     *
     * @return list<array<string, mixed>>
     */
    private function load(Site $site, string $kind, bool $refresh, bool $bestEffort, callable $fetch): array
    {
        if ($site->id === null) {
            return [];
        }

        if (!$refresh) {
            $cached = $this->repository->get($site->id, $kind);

            if ($cached !== null) {
                /** @var list<array<string, mixed>> $payload */
                $payload = $cached['payload'];

                return $payload;
            }
        }

        $token = $this->sites->tokenFor($site);

        if ($token === null || $site->apiBase === null) {
            return $bestEffort ? $this->cachedOrEmpty($site->id, $kind) : [];
        }

        try {
            $data = $fetch($site->apiBase, $token);
        } catch (ApiException | HttpException $e) {
            // Best-effort callers (opening the editor, connecting a site) fall
            // back to the cached copy rather than blanking the whole sidebar or
            // failing the connection; strict callers (the manual refresh button)
            // surface the error.
            if ($bestEffort) {
                return $this->cachedOrEmpty($site->id, $kind);
            }

            throw $e;
        }

        $this->repository->put($site->id, $kind, $data);

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function cachedOrEmpty(int $siteId, string $kind): array
    {
        $cached = $this->repository->get($siteId, $kind);

        if ($cached === null) {
            return [];
        }

        /** @var list<array<string, mixed>> $payload */
        $payload = $cached['payload'];

        return $payload;
    }
}
