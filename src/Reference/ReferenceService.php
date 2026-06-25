<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Reference;

use Grafida\Joomla\ApiClient;
use Grafida\Site\Site;
use Grafida\Site\SiteService;

/**
 * Loads and caches the reference data a site needs while editing: categories,
 * tags, view access levels and custom-field definitions.
 *
 * Each list is fetched from the API and cached in SQLite. Reads return the
 * cached copy unless a refresh is requested or the cache is empty.
 */
final class ReferenceService
{
    public const KIND_CATEGORIES = 'categories';
    public const KIND_TAGS       = 'tags';
    public const KIND_LEVELS     = 'levels';
    public const KIND_FIELDS     = 'fields';
    public const KIND_LANGUAGES  = 'languages';

    public function __construct(
        private readonly ReferenceRepository $repository,
        private readonly ApiClient $api,
        private readonly SiteService $sites,
    ) {}

    /** @return list<array<string, mixed>> */
    public function categories(Site $site, bool $refresh = false): array
    {
        return $this->load($site, self::KIND_CATEGORIES, $refresh, fn (string $b, string $t) => $this->api->listCategories($b, $t));
    }

    /** @return list<array<string, mixed>> */
    public function tags(Site $site, bool $refresh = false): array
    {
        return $this->load($site, self::KIND_TAGS, $refresh, fn (string $b, string $t) => $this->api->listTags($b, $t));
    }

    /** @return list<array<string, mixed>> */
    public function accessLevels(Site $site, bool $refresh = false): array
    {
        return $this->load($site, self::KIND_LEVELS, $refresh, fn (string $b, string $t) => $this->api->listAccessLevels($b, $t));
    }

    /** @return list<array<string, mixed>> */
    public function fields(Site $site, bool $refresh = false): array
    {
        return $this->load($site, self::KIND_FIELDS, $refresh, fn (string $b, string $t) => $this->api->listArticleFields($b, $t));
    }

    /**
     * The site's installed content languages (what an article may be assigned to).
     *
     * @return list<array<string, mixed>>
     */
    public function contentLanguages(Site $site, bool $refresh = false): array
    {
        return $this->load($site, self::KIND_LANGUAGES, $refresh, fn (string $b, string $t) => $this->api->listContentLanguages($b, $t));
    }

    /** Refreshes every reference list for a site from the network. */
    public function refreshAll(Site $site): void
    {
        $this->categories($site, true);
        $this->tags($site, true);
        $this->accessLevels($site, true);
        $this->fields($site, true);
        $this->contentLanguages($site, true);
    }

    /**
     * @param callable(string, string): list<array<string, mixed>> $fetch
     *
     * @return list<array<string, mixed>>
     */
    private function load(Site $site, string $kind, bool $refresh, callable $fetch): array
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
            return [];
        }

        $data = $fetch($site->apiBase, $token);
        $this->repository->put($site->id, $kind, $data);

        return $data;
    }
}
