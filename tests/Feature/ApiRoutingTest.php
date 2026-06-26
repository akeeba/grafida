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
use Boson\Component\Http\Static\StaticProviderInterface;
use Boson\Contracts\Http\RequestInterface;
use Boson\Contracts\Http\ResponseInterface;
use Grafida\Application\Kernel;
use Grafida\Storage\Database;
use Grafida\Storage\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the kernel's HTTP routing without opening a window — the kernel is a
 * pure Request -> Response function.
 */
final class ApiRoutingTest extends TestCase
{
    private ?\PDO $lastPdo = null;

    private function kernel(): Kernel
    {
        $pdo = Database::connect(':memory:');
        (new Migrator($pdo))->migrate();
        $this->lastPdo = $pdo;

        $static = new class implements StaticProviderInterface {
            public function findFileByRequest(RequestInterface $request): ?ResponseInterface
            {
                return null;
            }
        };

        return new Kernel($static, $pdo, \dirname(__DIR__, 2));
    }

    /** Inserts a bare site row (drafts reference sites via a foreign key). */
    private function seedSite(string $title = 'Site'): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->lastPdo?->prepare(
            'INSERT INTO sites (title, base_url, created_at, updated_at) VALUES (?, ?, ?, ?)'
        )->execute([$title, 'https://example.test', $now, $now]);

        return (int) ($this->lastPdo?->lastInsertId() ?? 0);
    }

    private function call(Kernel $kernel, string $method, string $path, ?string $body = null): array
    {
        $request  = new Request($method, 'boson://app' . $path, [], $body ?? '');
        $response = $kernel->handle($request);

        return [(int) (string) $response->status, json_decode((string) $response->body, true)];
    }

    public function testBootstrapReturnsAppState(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/bootstrap');

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertSame('Grafida', $json['data']['strings']['GRAFIDA_APP_TITLE']);
        self::assertArrayHasKey('el-GR', $json['data']['availableLanguages']);
        self::assertSame([], $json['data']['sites']);
    }

    public function testBootstrapReturnsAppMetadata(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/bootstrap');

        self::assertSame(200, $status);
        self::assertSame('Grafida', $json['data']['app']['name']);
        self::assertSame(\Grafida\Support\App::VERSION, $json['data']['app']['version']);
        self::assertStringContainsString('GNU General Public License', $json['data']['app']['license']);
        self::assertStringStartsWith('https://www.gnu.org/', $json['data']['app']['licenseUrl']);
        self::assertStringContainsString('Open Source Matters', $json['data']['app']['disclaimer']);
    }

    public function testMarkdownConversion(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'POST', '/api/markdown', json_encode(['markdown' => '# Hello']));

        self::assertSame(200, $status);
        self::assertStringContainsString('<h1>Hello</h1>', $json['data']['html']);
    }

    public function testUnknownRouteIs404(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/nope');

        self::assertSame(404, $status);
        self::assertFalse($json['ok']);
    }

    public function testLanguageOverridePersists(): void
    {
        $kernel = $this->kernel();

        [$status, $json] = $this->call($kernel, 'POST', '/api/settings/language', json_encode(['tag' => 'fr-FR']));

        self::assertSame(200, $status);
        self::assertSame('fr-FR', $json['data']['language']);
    }

    public function testStorageInfoReportsDatabasePath(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/settings/storage');

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertStringEndsWith('grafida.sqlite', $json['data']['path']);
        self::assertArrayHasKey('directory', $json['data']);
    }

    public function testDraftRoundTripsArticleImages(): void
    {
        $kernel  = $this->kernel();
        $siteId  = $this->seedSite();

        $images = [
            'image_intro'           => 'images/banner.jpg',
            'image_intro_alt'       => 'A banner',
            'image_intro_alt_empty' => '1',
            'image_intro_caption'   => 'Caption',
            'float_intro'           => 'right',
            'image_fulltext'        => 'grafida-media://7',
        ];

        [$status, $created] = $this->call(
            $kernel,
            'POST',
            '/api/sites/' . $siteId . '/drafts',
            json_encode(['title' => 'With images', 'images' => $images])
        );

        self::assertSame(200, $status);
        self::assertSame('images/banner.jpg', $created['data']['images']['image_intro']);
        self::assertSame('grafida-media://7', $created['data']['images']['image_fulltext']);

        [, $fetched] = $this->call($kernel, 'GET', '/api/drafts/' . $created['data']['id']);
        self::assertSame($images['image_intro_alt'], $fetched['data']['images']['image_intro_alt']);
        self::assertSame('1', $fetched['data']['images']['image_intro_alt_empty']);
        self::assertSame('right', $fetched['data']['images']['float_intro']);
    }

    public function testMediaBlobMissingIs404(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/media/999');

        self::assertSame(404, $status);
        self::assertFalse($json['ok']);
    }

    public function testResetStorageWipesData(): void
    {
        $kernel = $this->kernel();
        $siteId = $this->seedSite();

        // Seed a draft directly so the reset has something to remove.
        [, $created] = $this->call($kernel, 'POST', '/api/sites/' . $siteId . '/drafts', json_encode(['title' => 'Doomed']));
        self::assertTrue($created['ok']);

        [$status, $json] = $this->call($kernel, 'POST', '/api/settings/storage/reset');
        self::assertSame(200, $status);
        self::assertTrue($json['ok']);

        [, $drafts] = $this->call($kernel, 'GET', '/api/sites/' . $siteId . '/drafts');
        self::assertSame([], $drafts['data']);
    }
}
