<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Feature;

use Boson\Component\Http\Request;
use Boson\Contracts\Http\ResponseInterface;
use Grafida\Application\Container;
use Grafida\Application\Kernel;
use Grafida\Media\MediaRepository;
use Grafida\Tests\Support\TestContainer;
use Grafida\Tests\Support\TestDatabase;
use Joomla\Database\DatabaseInterface;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the gh-36 local-media surface end to end through the kernel:
 * `GET /api/media/{id}/raw` (the endpoint an article `<img src>` actually
 * points at), the Local Media tab's CRUD (`/api/sites/{id}/local-media`,
 * `/api/media/{id}/rename`, `/api/media/{id}/content`, `DELETE
 * /api/media/{id}`), and the changed `POST /api/sites/{id}/media` response
 * shape (step 4's contract change: `{id, url, width, height}`, no more
 * `dataUri`).
 */
final class LocalMediaTest extends TestCase
{
    private Container $container;

    private Kernel $kernel;

    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->container = TestContainer::create();
        $this->kernel     = $this->container->get(Kernel::class);
        $this->db         = $this->container->get(DatabaseInterface::class);
    }

    /** Inserts a bare site row (media_blobs reference sites via a foreign key). */
    private function seedSite(string $title = 'Site'): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $pdo = TestDatabase::connection($this->db);
        $pdo->prepare(
            'INSERT INTO sites (title, base_url, created_at, updated_at) VALUES (?, ?, ?, ?)'
        )->execute([$title, 'https://example.test', $now, $now]);

        return (int) $pdo->lastInsertId();
    }

    private function raw(string $method, string $path, ?string $body = null): ResponseInterface
    {
        return $this->kernel->handle(new Request($method, 'boson://app' . $path, [], $body ?? ''));
    }

    /** @return array{0: int, 1: mixed} */
    private function call(string $method, string $path, ?string $body = null): array
    {
        $response = $this->raw($method, $path, $body);

        return [(int) (string) $response->status, json_decode((string) $response->body, true)];
    }

    private function header(ResponseInterface $response, string $name): ?string
    {
        foreach ($response->headers as $key => $value) {
            if (strtolower((string) $key) === strtolower($name)) {
                return (string) $value;
            }
        }

        return null;
    }

    public function testRawEndpointServesStoredBytesWithMimeAndNoStore(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $id     = $media->store($siteId, null, 'photo.png', 'image/png', 'raw-image-bytes');

        $response = $this->raw('GET', '/api/media/' . $id . '/raw');

        self::assertSame(200, (int) (string) $response->status);
        self::assertSame('image/png', $this->header($response, 'Content-Type'));
        self::assertSame('raw-image-bytes', (string) $response->body);
        self::assertStringContainsString('no-store', (string) $this->header($response, 'Cache-Control'));
    }

    public function testRawEndpointForMissingIdIs404WithJsonBody(): void
    {
        [$status, $json] = $this->call('GET', '/api/media/999999/raw');

        self::assertSame(404, $status);
        self::assertFalse($json['ok']);
    }

    public function testLocalMediaListingCarriesUrlAndNeverBlobData(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $media->store($siteId, null, 'a.png', 'image/png', str_repeat('x', 32));

        [$status, $json] = $this->call('GET', '/api/sites/' . $siteId . '/local-media');

        self::assertSame(200, $status);
        self::assertCount(1, $json['data']['entries']);
        $entry = $json['data']['entries'][0];
        self::assertArrayHasKey('url', $entry);
        self::assertArrayNotHasKey('data', $entry);
        self::assertStringContainsString('boson://app/api/media/', $entry['url']);
    }

    public function testLocalMediaListingWorksWithoutAConnectedSite(): void
    {
        // A site with no stored token is, by definition, "not connected" —
        // the Local Media tab must still work, since media_blobs is entirely
        // local storage and never talks to the site's REST API.
        $siteId = $this->seedSite();

        [$status, $json] = $this->call('GET', '/api/sites/' . $siteId . '/local-media');

        self::assertSame(200, $status);
        self::assertSame([], $json['data']['entries']);
    }

    public function testRenameRejectsSlashColonAndEmpty(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $id     = $media->store($siteId, null, 'a.png', 'image/png', 'bytes');

        foreach (['bad/name', 'bad:name', ''] as $badName) {
            [$status, $json] = $this->call('POST', '/api/media/' . $id . '/rename', json_encode(['filename' => $badName]));
            self::assertSame(400, $status, $badName);
            self::assertFalse($json['ok'], $badName);
        }
    }

    public function testRenameSucceedsAndReflectsInUrl(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $id     = $media->store($siteId, null, 'a.png', 'image/png', 'bytes');

        [$status, $json] = $this->call('POST', '/api/media/' . $id . '/rename', json_encode(['filename' => 'renamed']));

        self::assertSame(200, $status);
        self::assertSame('renamed.png', $json['data']['filename']);
        self::assertStringContainsString((string) $id . '/raw', $json['data']['url']);
    }

    public function testContentUpdateIsReadableBackFromRawAndChangesUrl(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $id     = $media->store($siteId, null, 'a.png', 'image/png', 'original-bytes');

        [, $before] = $this->call('GET', '/api/sites/' . $siteId . '/local-media');
        $originalUrl = $before['data']['entries'][0]['url'];

        // The revision token is derived from updated_at at second resolution
        // (LocalMediaUrl::token()); force a distinct second so the assertion
        // that the URL changes is not a coin flip on how fast the test runs.
        sleep(1);

        $newBytes = base64_encode('new-bytes-after-edit');
        [$status, $json] = $this->call('POST', '/api/media/' . $id . '/content', json_encode(['dataBase64' => $newBytes]));

        self::assertSame(200, $status);
        self::assertNotSame($originalUrl, $json['data']['url']);

        $response = $this->raw('GET', '/api/media/' . $id . '/raw');
        self::assertSame('new-bytes-after-edit', (string) $response->body);
    }

    public function testDeleteThenRawIs404(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $id     = $media->store($siteId, null, 'a.png', 'image/png', 'bytes');

        [$status] = $this->call('DELETE', '/api/media/' . $id);
        self::assertSame(200, $status);

        $response = $this->raw('GET', '/api/media/' . $id . '/raw');
        self::assertSame(404, (int) (string) $response->status);
    }

    public function testUploadOfflineMediaReturnsIdUrlWidthHeightNotDataUri(): void
    {
        $siteId = $this->seedSite();

        // A 1x1 transparent PNG, so ImageInfo::dimensions() has real bytes to sniff.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($png);

        [$status, $json] = $this->call(
            'POST',
            '/api/sites/' . $siteId . '/media',
            json_encode(['dataBase64' => base64_encode($png), 'mime' => 'image/png']),
        );

        self::assertSame(201, $status);
        self::assertArrayHasKey('id', $json['data']);
        self::assertArrayHasKey('url', $json['data']);
        self::assertArrayNotHasKey('dataUri', $json['data']);
        self::assertSame(1, $json['data']['width']);
        self::assertSame(1, $json['data']['height']);
        self::assertStringContainsString('boson://app/api/media/', $json['data']['url']);
    }
}
