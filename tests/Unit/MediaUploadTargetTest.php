<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Http\HttpResponse;
use Grafida\Joomla\ApiClient;
use Grafida\Media\MediaUploadTarget;
use Grafida\Site\Site;
use Grafida\Tests\Unit\Support\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * gh-57: an upload path must name its adapter. Left unqualified, Joomla resolves
 * it against com_media's `file_path` parameter — `files` on a stock install —
 * so a stock site put every published image in `files/`, not `images/`.
 */
final class MediaUploadTargetTest extends TestCase
{
    private const BASE = 'https://example.com/index.php/api';

    private const ADAPTERS_URL = self::BASE . '/v1/media/adapters?page%5Blimit%5D=100&page%5Boffset%5D=0';

    private function site(?string $adapter = null, ?string $folder = null): Site
    {
        return new Site(
            id: 1,
            title: 'Site',
            baseUrl: 'https://example.com',
            apiBase: self::BASE,
            secretRef: null,
            hasInsecureToken: true,
            mediaAdapter: $adapter,
            mediaFolder: $folder,
        );
    }

    /** @param list<array{provider_id?: string, name?: string, path?: string}> $adapters */
    private function targetWithAdapters(array $adapters, ?FakeTransport &$transport = null): MediaUploadTarget
    {
        $data = [];

        foreach ($adapters as $i => $adapter) {
            $data[] = [
                'type'       => 'adapters',
                'id'         => $adapter['path'] ?? ('adapter-' . $i),
                'attributes' => $adapter,
            ];
        }

        $transport = new FakeTransport();
        $transport->on(
            self::ADAPTERS_URL,
            new HttpResponse(
                200,
                json_encode(['data' => $data]),
                ['Content-Type' => 'application/vnd.api+json'],
            ),
        );

        return new MediaUploadTarget(new ApiClient($transport));
    }

    public function testAutomaticResolutionPrefersTheImagesFilesystem(): void
    {
        // A stock Joomla: images first, files second — and Joomla's own default
        // adapter would be the *second* one.
        $target = $this->targetWithAdapters([
            ['provider_id' => 'local', 'name' => 'images', 'path' => 'local-images:/'],
            ['provider_id' => 'local', 'name' => 'files', 'path' => 'local-files:/'],
        ]);

        self::assertSame(
            'local-images:/grafida/photo.png',
            $target->pathFor($this->site(), self::BASE, 'tok', 'photo.png'),
        );
    }

    public function testAutomaticResolutionFallsBackToTheFirstFilesystem(): void
    {
        $target = $this->targetWithAdapters([
            ['provider_id' => 'local', 'name' => 'media', 'path' => 'local-media:/'],
            ['provider_id' => 'local', 'name' => 'files', 'path' => 'local-files:/'],
        ]);

        self::assertSame(
            'local-media:/grafida/photo.png',
            $target->pathFor($this->site(), self::BASE, 'tok', 'photo.png'),
        );
    }

    public function testTheResolvedFilesystemIsAskedForOncePerSite(): void
    {
        $transport = null;
        $target    = $this->targetWithAdapters(
            [['provider_id' => 'local', 'name' => 'images', 'path' => 'local-images:/']],
            $transport,
        );

        $target->pathFor($this->site(), self::BASE, 'tok', 'a.png');
        $target->pathFor($this->site(), self::BASE, 'tok', 'b.png');
        $target->pathFor($this->site(), self::BASE, 'tok', 'c.png');

        self::assertNotNull($transport);
        self::assertCount(1, $transport->requests, 'One adapters request, not one per image');
    }

    public function testAnUnreadableAdapterListDegradesToAnUnqualifiedPath(): void
    {
        // A site we cannot reach (or a token that may not list filesystems) must
        // still publish — with exactly the path Grafida sent before gh-57.
        $target = new MediaUploadTarget(new ApiClient((new FakeTransport())->throwForAll(6)));

        self::assertSame('grafida/photo.png', $target->pathFor($this->site(), self::BASE, 'tok', 'photo.png'));
    }

    public function testTheSitesOwnSettingWinsAndCostsNoRequest(): void
    {
        $transport = null;
        $target    = $this->targetWithAdapters(
            [['provider_id' => 'local', 'name' => 'images', 'path' => 'local-images:/']],
            $transport,
        );

        self::assertSame(
            'local-files:/blog/photo.png',
            $target->pathFor($this->site('local-files:/', 'blog'), self::BASE, 'tok', 'photo.png'),
        );
        self::assertNotNull($transport);
        self::assertSame([], $transport->requests);
    }

    /**
     * @return list<array{0: ?string, 1: string}>
     */
    public static function adapterValues(): array
    {
        return [
            [null, ''],
            ['', ''],
            ['   ', ''],
            ['local-images', 'local-images:/'],
            ['local-images:', 'local-images:/'],
            ['local-images:/', 'local-images:/'],
            ['local-images:///', 'local-images:/'],
        ];
    }

    #[DataProvider('adapterValues')]
    public function testAdapterNormalisation(?string $stored, string $expected): void
    {
        self::assertSame($expected, MediaUploadTarget::normaliseAdapter($stored));
    }

    /**
     * @return list<array{0: ?string, 1: string}>
     */
    public static function folderValues(): array
    {
        return [
            [null, 'grafida'],
            ['', 'grafida'],
            ['   ', 'grafida'],
            ['/', 'grafida'],
            ['blog', 'blog'],
            ['/blog/2026/', 'blog/2026'],
            ['blog\\2026', 'blog/2026'],
            // Pasted straight out of the Media Manager, adapter and all.
            ['local-images:/blog', 'blog'],
        ];
    }

    #[DataProvider('folderValues')]
    public function testFolderNormalisation(?string $stored, string $expected): void
    {
        self::assertSame($expected, MediaUploadTarget::normaliseFolder($stored));
    }
}
