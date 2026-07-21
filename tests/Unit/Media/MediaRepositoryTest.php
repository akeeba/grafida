<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Media;

use Grafida\Media\MediaRepository;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\TestCase;
use Joomla\Database\DatabaseInterface;

final class MediaRepositoryTest extends TestCase
{
    private DatabaseInterface $db;
    private MediaRepository $media;

    protected function setUp(): void
    {
        $this->db   = TestDatabase::memory();
        $connection = TestDatabase::connection($this->db);
        $connection->exec(
            'INSERT INTO sites (id, title, base_url, created_at, updated_at) '
            . "VALUES (1, 'Site', 'https://example.com', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );
        $connection->exec(
            'INSERT INTO drafts (id, site_id, title, alias, access, language, state, html, fields_json, '
            . 'tags_json, images_json, created_at, updated_at) '
            . "VALUES (1, 1, 'My Draft', 'my-draft', 1, '*', 1, '<p></p>', '[]', '[]', '{}', "
            . "'2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );

        $this->media = new MediaRepository($this->db);
    }

    public function testStoreRecordsSizeUpdatedAtAndDimensions(): void
    {
        $id = $this->media->store(1, null, 'a.png', 'image/png', 'twelve-bytes', 640, 480);

        $meta = $this->media->findMeta($id);
        self::assertNotNull($meta);
        self::assertSame(strlen('twelve-bytes'), $meta['size']);
        self::assertNotNull($meta['updated_at']);
        self::assertSame(640, $meta['width']);
        self::assertSame(480, $meta['height']);
    }

    public function testFindMetaNeverReturnsTheDataKey(): void
    {
        $id   = $this->media->store(1, null, 'a.png', 'image/png', 'bytes');
        $meta = $this->media->findMeta($id);

        self::assertNotNull($meta);
        self::assertArrayNotHasKey('data', $meta);
    }

    public function testFindMetaReturnsNullForMissingId(): void
    {
        self::assertNull($this->media->findMeta(999999));
    }

    public function testListForSiteOrdersByMostRecentEditAndJoinsDraftTitle(): void
    {
        $connection = TestDatabase::connection($this->db);

        $older = $this->media->store(1, 1, 'older.png', 'image/png', 'a');
        // Backdate the older row's timestamps so ordering is deterministic
        // rather than relying on two store() calls landing in different seconds.
        $connection->prepare('UPDATE media_blobs SET created_at = ?, updated_at = ? WHERE id = ?')
            ->execute(['2020-01-01 00:00:00', '2020-01-01 00:00:00', $older]);

        $newer = $this->media->store(1, null, 'newer.png', 'image/png', 'b');
        $connection->prepare('UPDATE media_blobs SET created_at = ?, updated_at = ? WHERE id = ?')
            ->execute(['2025-01-01 00:00:00', '2025-01-01 00:00:00', $newer]);

        $rows = $this->media->listForSite(1);

        self::assertCount(2, $rows);
        self::assertSame($newer, $rows[0]['id'], 'most recently edited blob must come first');
        self::assertSame($older, $rows[1]['id']);
        self::assertSame('My Draft', $rows[1]['draft_title'], 'the owning draft title must be joined in');
        self::assertNull($rows[0]['draft_title'], 'a blob with no draft_id has no owning article title');
    }

    public function testListForSiteOnlyReturnsRowsForThatSite(): void
    {
        $connection = TestDatabase::connection($this->db);
        $connection->exec(
            "INSERT INTO sites (id, title, base_url, created_at, updated_at) "
            . "VALUES (2, 'Other', 'https://other.example', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );

        $this->media->store(1, null, 'a.png', 'image/png', 'a');
        $this->media->store(2, null, 'b.png', 'image/png', 'b');

        self::assertCount(1, $this->media->listForSite(1));
        self::assertCount(1, $this->media->listForSite(2));
    }

    public function testReplaceDataBumpsUpdatedAtAndClearsRemoteLinkage(): void
    {
        $id = $this->media->store(1, null, 'a.png', 'image/png', 'old-bytes');
        $this->media->markUploaded($id, 'local-images:/grafida/a.png', 'images/grafida/a.png');

        $before = $this->media->findMeta($id);
        self::assertNotNull($before);
        self::assertSame('local-images:/grafida/a.png', $before['remote_path']);
        self::assertSame('images/grafida/a.png', $before['remote_url']);

        $this->media->replaceData($id, 'new-bytes', 'image/jpeg', 100, 200);

        $blob = $this->media->find($id);
        self::assertNotNull($blob);
        self::assertSame('new-bytes', $blob['data']);

        // The trap from step 2: replacing the bytes must clear the previous
        // upload's remote linkage, or a re-publish would reuse the OLD file's
        // path/URL for the NEW bytes.
        self::assertNull($blob['remote_path'], 'remote_path must be cleared after an edit');
        self::assertNull($blob['remote_url'], 'remote_url must be cleared after an edit');

        $after = $this->media->findMeta($id);
        self::assertNotNull($after);
        self::assertSame('image/jpeg', $after['mime']);
        self::assertSame(100, $after['width']);
        self::assertSame(200, $after['height']);
        self::assertSame(strlen('new-bytes'), $after['size']);
        self::assertNotNull($after['updated_at']);
    }

    public function testRenameChangesFilenameOnly(): void
    {
        $id = $this->media->store(1, null, 'old.png', 'image/png', 'bytes');

        $this->media->rename($id, 'new.png');

        $meta = $this->media->findMeta($id);
        self::assertNotNull($meta);
        self::assertSame('new.png', $meta['filename']);
    }

    public function testDeleteRemovesTheRow(): void
    {
        $id = $this->media->store(1, null, 'a.png', 'image/png', 'bytes');

        $this->media->delete($id);

        self::assertNull($this->media->findMeta($id));
        self::assertNull($this->media->find($id));
    }
}
