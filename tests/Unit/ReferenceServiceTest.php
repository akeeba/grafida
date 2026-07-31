<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Joomla\ApiClient;
use Grafida\Reference\ReferenceRepository;
use Grafida\Reference\ReferenceService;
use Grafida\Site\Site;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Grafida\Site\UnicodeAliases;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\Support\FakeTransport;
use Joomla\Database\DatabaseInterface;

/**
 * gh-42: {@see ReferenceService::fetchedAt()} is what lets the SPA decide
 * whether a site's cached reference data looks stale enough to freshen in
 * the background.
 */
final class ReferenceServiceTest extends TestCase
{
    private DatabaseInterface $db;
    private ReferenceRepository $repository;
    private ReferenceService $service;
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->db         = TestDatabase::memory();
        $this->repository = new ReferenceRepository($this->db);
        $this->transport   = new FakeTransport();
        $siteService       = new SiteService(new SiteRepository($this->db), new ApiClient($this->transport), null);
        $this->service     = new ReferenceService($this->repository, $siteService);
    }

    /**
     * Inserts a bare site row (reference_cache references sites via a foreign
     * key) and returns the entity for it.
     *
     * The row carries a plaintext token so a site returned with an $apiBase is
     * one {@see ReferenceService::unicodeSlugs()} would really call out for —
     * which is what lets a test assert that an override stops it.
     */
    private function seedSite(?string $apiBase = null, string $unicodeAliases = UnicodeAliases::AUTO): Site
    {
        $now = gmdate('Y-m-d H:i:s');
        $pdo = TestDatabase::connection($this->db);
        $pdo->prepare(
            'INSERT INTO sites (title, base_url, insecure_token, unicode_aliases, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(['Site', 'https://example.test', 'tok', $unicodeAliases, $now, $now]);

        return new Site(
            (int) $pdo->lastInsertId(),
            'Site',
            'https://example.test',
            $apiBase,
            null,
            true,
            unicodeAliases: $unicodeAliases,
        );
    }

    public function testReturnsNullWhenNothingIsCached(): void
    {
        $site = $this->seedSite();

        self::assertNull($this->service->fetchedAt($site));
    }

    public function testReturnsTheOldestFetchedAtAcrossAllRefreshableKinds(): void
    {
        $site = $this->seedSite();

        // Deliberately out of order and with distinct timestamps, so a naive
        // "last write wins" implementation would fail this.
        $this->repository->put((int) $site->id, ReferenceService::KIND_TAGS, []);
        $this->setFetchedAt((int) $site->id, ReferenceService::KIND_TAGS, '2026-01-01 12:00:00');

        $this->repository->put((int) $site->id, ReferenceService::KIND_CATEGORIES, []);
        $this->setFetchedAt((int) $site->id, ReferenceService::KIND_CATEGORIES, '2025-06-15 08:30:00');

        $this->repository->put((int) $site->id, ReferenceService::KIND_LEVELS, []);
        $this->setFetchedAt((int) $site->id, ReferenceService::KIND_LEVELS, '2026-03-01 00:00:00');

        $this->repository->put((int) $site->id, ReferenceService::KIND_FIELDS, []);
        $this->setFetchedAt((int) $site->id, ReferenceService::KIND_FIELDS, '2026-02-01 00:00:00');

        $this->repository->put((int) $site->id, ReferenceService::KIND_LANGUAGES, []);
        $this->setFetchedAt((int) $site->id, ReferenceService::KIND_LANGUAGES, '2026-01-15 00:00:00');

        self::assertSame('2025-06-15 08:30:00', $this->service->fetchedAt($site));
    }

    public function testReturnsNullWhenOneRefreshableKindIsMissing(): void
    {
        $site = $this->seedSite();

        // Every kind except tags — a partially warmed cache is, for
        // freshness purposes, no cache.
        $this->repository->put((int) $site->id, ReferenceService::KIND_CATEGORIES, []);
        $this->repository->put((int) $site->id, ReferenceService::KIND_LEVELS, []);
        $this->repository->put((int) $site->id, ReferenceService::KIND_FIELDS, []);
        $this->repository->put((int) $site->id, ReferenceService::KIND_LANGUAGES, []);

        self::assertNull($this->service->fetchedAt($site));
    }

    public function testConfigKindDoesNotInfluenceTheResult(): void
    {
        $site = $this->seedSite();

        // Only KIND_CONFIG is cached — none of the five refreshable kinds —
        // so the result must still be null, not the config's timestamp.
        $this->repository->put((int) $site->id, ReferenceService::KIND_CONFIG, ['unicodeslugs' => true]);

        self::assertNull($this->service->fetchedAt($site));

        // Warm every refreshable kind but leave KIND_CONFIG's timestamp far
        // newer; it must never win, and never lose either.
        foreach (
            [
                ReferenceService::KIND_CATEGORIES,
                ReferenceService::KIND_TAGS,
                ReferenceService::KIND_LEVELS,
                ReferenceService::KIND_FIELDS,
                ReferenceService::KIND_LANGUAGES,
            ] as $kind
        ) {
            $this->repository->put((int) $site->id, $kind, []);
            $this->setFetchedAt((int) $site->id, $kind, '2026-01-01 00:00:00');
        }

        $this->setFetchedAt((int) $site->id, ReferenceService::KIND_CONFIG, '2099-01-01 00:00:00');

        self::assertSame('2026-01-01 00:00:00', $this->service->fetchedAt($site));
    }

    /**
     * gh-42 round 2: {@see ReferenceRepository::clearAll()} backs the opt-in
     * "reset site metadata cache on startup" preference. It must be a real,
     * unconditional delete across every site and kind, and must leave
     * `editor_css_cache` alone — that cache has its own refresh path and cost
     * profile and is not part of this preference.
     */
    public function testClearAllDropsEveryRowAcrossSitesButLeavesEditorCssCacheAlone(): void
    {
        $siteA = $this->seedSite();
        $siteB = $this->seedSite();

        $this->repository->put((int) $siteA->id, ReferenceService::KIND_CATEGORIES, []);
        $this->repository->put((int) $siteA->id, ReferenceService::KIND_TAGS, []);
        $this->repository->put((int) $siteB->id, ReferenceService::KIND_LEVELS, []);
        $this->repository->putEditorCss((int) $siteA->id, 'body { color: red; }');
        $this->repository->putEditorCss((int) $siteB->id, 'body { color: blue; }');

        $this->repository->clearAll();

        self::assertNull($this->repository->get((int) $siteA->id, ReferenceService::KIND_CATEGORIES));
        self::assertNull($this->repository->get((int) $siteA->id, ReferenceService::KIND_TAGS));
        self::assertNull($this->repository->get((int) $siteB->id, ReferenceService::KIND_LEVELS));

        // Untouched: editor.css has its own refresh path (the manual Refresh
        // button), not the metadata cache reset.
        self::assertSame('body { color: red; }', $this->repository->getEditorCss((int) $siteA->id));
        self::assertSame('body { color: blue; }', $this->repository->getEditorCss((int) $siteB->id));
    }

    /**
     * gh-61: reading `unicodeslugs` off a site needs `core.admin`, so for most
     * tokens "we could not read it" and "it is off" were the same answer. The
     * per-site tri-state is the way out, and it has to win outright — including
     * over a cached value read back when the site *was* connected as a Super
     * User, which is the case that would otherwise look like the setting did
     * nothing.
     */
    public function testAnOverrideBeatsTheCachedConfigurationValue(): void
    {
        $yes = $this->seedSite(unicodeAliases: UnicodeAliases::YES);
        $this->repository->put((int) $yes->id, ReferenceService::KIND_CONFIG, ['unicodeslugs' => false]);

        self::assertTrue($this->service->unicodeSlugs($yes));

        $no = $this->seedSite(unicodeAliases: UnicodeAliases::NO);
        $this->repository->put((int) $no->id, ReferenceService::KIND_CONFIG, ['unicodeslugs' => true]);

        self::assertFalse($this->service->unicodeSlugs($no));
    }

    /**
     * An override is an answer, not a hint: nothing is asked of the site, even
     * on the refresh path that exists precisely to ask it. This is also what
     * keeps `sync()` from spending a request per site connect on a value the
     * user has already stated.
     */
    public function testAnOverrideMakesNoRequestEvenOnRefresh(): void
    {
        $site = $this->seedSite('https://example.test/index.php/api', UnicodeAliases::YES);

        self::assertTrue($this->service->unicodeSlugs($site, true));
        self::assertSame([], $this->transport->requests);
    }

    /**
     * And it writes nothing either, so switching back to Automatic re-reads the
     * site rather than inheriting whatever the user asserted while it was on.
     */
    public function testAnOverrideDoesNotPoisonTheConfigCache(): void
    {
        $site = $this->seedSite('https://example.test/index.php/api', UnicodeAliases::YES);

        $this->service->unicodeSlugs($site, true);

        self::assertNull($this->repository->get((int) $site->id, ReferenceService::KIND_CONFIG));
    }

    /** Automatic is unchanged: the cached value answers, as it always did. */
    public function testAutomaticStillReadsTheCachedConfigurationValue(): void
    {
        $site = $this->seedSite();
        $this->repository->put((int) $site->id, ReferenceService::KIND_CONFIG, ['unicodeslugs' => true]);

        self::assertTrue($this->service->unicodeSlugs($site));
        self::assertSame([], $this->transport->requests);
    }

    private function setFetchedAt(int $siteId, string $kind, string $fetchedAt): void
    {
        TestDatabase::connection($this->db)
            ->prepare('UPDATE reference_cache SET fetched_at = ? WHERE site_id = ? AND kind = ?')
            ->execute([$fetchedAt, $siteId, $kind]);
    }
}
