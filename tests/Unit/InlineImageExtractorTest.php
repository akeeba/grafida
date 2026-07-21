<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Html\InlineMedia;
use Grafida\Media\InlineImageExtractor;
use Grafida\Media\LocalMediaUrl;
use Grafida\Media\MediaRepository;
use Grafida\Tests\Support\TestDatabase;
use Joomla\Database\DatabaseInterface;

final class InlineImageExtractorTest extends TestCase
{
    private DatabaseInterface $db;
    private MediaRepository $media;
    private InlineImageExtractor $extractor;

    protected function setUp(): void
    {
        $this->db         = TestDatabase::memory();
        $connection       = TestDatabase::connection($this->db);
        $connection->exec(
            'INSERT INTO sites (id, title, base_url, created_at, updated_at) '
            . "VALUES (1, 'Site', 'https://example.com', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );

        $this->media     = new MediaRepository($this->db);
        $this->extractor = new InlineImageExtractor($this->media);
    }

    public function testHtmlWithNoDataUriIsReturnedUnchanged(): void
    {
        $html = '<p>No images here.</p>';

        self::assertSame($html, $this->extractor->extract($html, 1, null));
    }

    public function testAlreadyLocalUrlIsLeftAlone(): void
    {
        $id  = $this->media->store(1, null, 'a.png', 'image/png', 'bytes', 10, 20);
        $meta = $this->media->findMeta($id);
        self::assertNotNull($meta);

        $url  = LocalMediaUrl::build($id, $meta['updated_at'] ?? $meta['created_at']);
        $html = '<p><img src="' . $url . '"></p>';

        self::assertSame($html, $this->extractor->extract($html, 1, null));
    }

    public function testUntaggedDataImageIsStoredAndReplacedWithLocalUrl(): void
    {
        $b64  = base64_encode('raw-bytes');
        $html = '<p><img src="data:image/png;base64,' . $b64 . '"></p>';

        $out = $this->extractor->extract($html, 1, null);

        self::assertStringNotContainsString('data:image', $out);
        self::assertStringContainsString(InlineMedia::LOCAL_URL_PREFIX, $out);

        $rows = $this->db->setQuery(
            $this->db->createQuery()->select('*')->from($this->db->quoteName('media_blobs'))
        )->loadAssocList();
        self::assertCount(1, $rows);
        self::assertSame('raw-bytes', $rows[0]['data']);
    }

    public function testTaggedDataImageReusesExistingBlobWithoutDuplicating(): void
    {
        $id = $this->media->store(1, null, 'photo.png', 'image/png', 'original-bytes');

        $html = '<p><img src="data:image/png;base64,QUFB" data-grafida-media-id="' . $id . '"></p>';

        $out = $this->extractor->extract($html, 1, null);

        $meta = $this->media->findMeta($id);
        self::assertNotNull($meta);
        $expectedUrl = LocalMediaUrl::build($id, $meta['updated_at'] ?? $meta['created_at']);

        self::assertStringContainsString($expectedUrl, $out);

        $rows = $this->db->setQuery(
            $this->db->createQuery()->select('*')->from($this->db->quoteName('media_blobs'))
        )->loadAssocList();
        // No new row was created for the already-stored blob.
        self::assertCount(1, $rows);
    }

    public function testTaggedImageWithDeletedBlobFallsBackToDecoding(): void
    {
        $b64  = base64_encode('fresh-bytes');
        $html = '<p><img src="data:image/png;base64,' . $b64 . '" data-grafida-media-id="999"></p>';

        $out = $this->extractor->extract($html, 1, null);

        self::assertStringNotContainsString('data:image', $out);

        $rows = $this->db->setQuery(
            $this->db->createQuery()->select('*')->from($this->db->quoteName('media_blobs'))
        )->loadAssocList();
        self::assertCount(1, $rows);
        self::assertSame('fresh-bytes', $rows[0]['data']);
    }

    public function testMalformedDataUriIsLeftUnchanged(): void
    {
        $html = '<p><img src="data:not-a-real-uri"></p>';

        $out = $this->extractor->extract($html, 1, null);

        self::assertStringContainsString('data:not-a-real-uri', $out);
    }

    public function testRunningTwiceProducesNoNewBlobs(): void
    {
        $b64  = base64_encode('idempotent-bytes');
        $html = '<p><img src="data:image/png;base64,' . $b64 . '"></p>';

        $once  = $this->extractor->extract($html, 1, null);
        $twice = $this->extractor->extract($once, 1, null);

        self::assertSame($once, $twice);

        $rows = $this->db->setQuery(
            $this->db->createQuery()->select('*')->from($this->db->quoteName('media_blobs'))
        )->loadAssocList();
        self::assertCount(1, $rows);
    }
}
