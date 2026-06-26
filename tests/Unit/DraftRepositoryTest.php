<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Article\Draft;
use Grafida\Article\DraftRepository;
use Grafida\Storage\Database;
use Grafida\Storage\Migrator;
use PDO;

final class DraftRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connect(':memory:');
        (new Migrator($this->pdo))->migrate();
        $this->pdo->exec(
            'INSERT INTO sites (id, title, base_url, created_at, updated_at) '
            . "VALUES (1, 'Site', 'https://example.com', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );
        $this->pdo->exec(
            'INSERT INTO sites (id, title, base_url, created_at, updated_at) '
            . "VALUES (2, 'Other', 'https://other.example', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );
    }

    private function repo(): DraftRepository
    {
        return new DraftRepository($this->pdo);
    }

    private function sample(): Draft
    {
        return new Draft(
            id: null,
            siteId: 1,
            remoteId: null,
            title: 'Hello',
            alias: 'hello',
            catid: null,
            access: 1,
            language: '*',
            state: 1,
            html: '<p>Body</p>',
            fields: ['colour' => 'blue'],
            tags: ['news'],
            images: ['image_intro' => 'images/a.png'],
            metadesc: 'desc',
            metakey: 'key',
        );
    }

    public function testInsertPersistsAndRoundTrips(): void
    {
        $id = $this->repo()->insert($this->sample());

        self::assertGreaterThan(0, $id);

        $back = $this->repo()->find($id);

        self::assertNotNull($back);
        self::assertSame('Hello', $back->title);
        self::assertSame(['colour' => 'blue'], $back->fields);
        self::assertSame(['news'], $back->tags);
        self::assertSame(['image_intro' => 'images/a.png'], $back->images);
    }

    public function testUpdateChangesStoredValues(): void
    {
        $repo = $this->repo();
        $id   = $repo->insert($this->sample());

        $draft = $repo->find($id);
        self::assertNotNull($draft);

        $draft->title = 'Changed';
        $draft->html  = '<p>New</p>';
        $repo->update($draft);

        $back = $repo->find($id);
        self::assertNotNull($back);
        self::assertSame('Changed', $back->title);
        self::assertSame('<p>New</p>', $back->html);
        self::assertSame(1, $back->siteId);
    }

    public function testFindByRemoteMatchesSiteAndRemoteId(): void
    {
        $repo  = $this->repo();
        $draft = $this->sample();
        $draft->remoteId = 99;
        $id = $repo->insert($draft);

        self::assertSame($id, $repo->findByRemote(1, 99)?->id);
        self::assertNull($repo->findByRemote(2, 99), 'must not match a different site');
        self::assertNull($repo->findByRemote(1, 100), 'must not match a different remote id');
    }

    public function testUpdateCanRepointDraftToAnotherSite(): void
    {
        $repo  = $this->repo();
        $draft = $this->sample();
        $draft->remoteId = 99;
        $id = $repo->insert($draft);

        // Re-point at site 2 and unlink it from its remote article.
        $moved = $repo->find($id);
        self::assertNotNull($moved);
        $moved->siteId   = 2;
        $moved->remoteId = null;
        $repo->update($moved);

        $back = $repo->find($id);
        self::assertNotNull($back);
        self::assertSame(2, $back->siteId);
        self::assertNull($back->remoteId);
        self::assertCount(0, $repo->forSite(1));
        self::assertCount(1, $repo->forSite(2));
    }

    public function testSetRemoteIdAndDelete(): void
    {
        $repo = $this->repo();
        $id   = $repo->insert($this->sample());

        $repo->setRemoteId($id, 42);
        self::assertSame(42, $repo->find($id)?->remoteId);

        $repo->delete($id);
        self::assertNull($repo->find($id));
        self::assertCount(0, $repo->forSite(1));
    }
}
