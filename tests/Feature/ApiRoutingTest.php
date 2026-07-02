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
        // OS-probed appearance: true/false, or null when undetectable.
        self::assertArrayHasKey('systemPrefersDark', $json['data']);
        self::assertTrue(
            $json['data']['systemPrefersDark'] === null
            || \is_bool($json['data']['systemPrefersDark'])
        );
    }

    public function testSystemThemeEndpoint(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/settings/system-theme');

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertArrayHasKey('systemPrefersDark', $json['data']);
        self::assertTrue(
            $json['data']['systemPrefersDark'] === null
            || \is_bool($json['data']['systemPrefersDark'])
        );
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

    public function testDisplayModePersists(): void
    {
        $kernel = $this->kernel();

        [$status, $json] = $this->call($kernel, 'POST', '/api/settings/display-mode', json_encode(['mode' => 'light']));

        self::assertSame(200, $status);
        self::assertSame('light', $json['data']['displayMode']);

        [, $boot] = $this->call($kernel, 'GET', '/api/bootstrap');
        self::assertSame('light', $boot['data']['displayMode']);
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

    public function testOpenFileIsUnavailableWithoutDialog(): void
    {
        // The default kernel has no native Dialog API wired in.
        [$status, $json] = $this->call($this->kernel(), 'POST', '/api/dialog/open-file', json_encode(['filter' => 'image']));

        self::assertSame(503, $status);
        self::assertFalse($json['ok']);
    }

    public function testOpenFileReturnsSelectedFileContents(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'grafida') . '.png';
        file_put_contents($tmp, 'PNGDATA');

        $kernel = new Kernel($this->kernelStatic(), Database::connect(':memory:'), \dirname(__DIR__, 2), $this->stubDialog($tmp));
        [$status, $json] = $this->call($kernel, 'POST', '/api/dialog/open-file', json_encode(['filter' => 'image']));

        unlink($tmp);

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertSame('image/png', $json['data']['mime']);
        self::assertSame('PNGDATA', base64_decode($json['data']['dataBase64']));
        self::assertStringEndsWith('.png', $json['data']['name']);
    }

    public function testOpenFileReturnsCancelledWhenDismissed(): void
    {
        $kernel = new Kernel($this->kernelStatic(), Database::connect(':memory:'), \dirname(__DIR__, 2), $this->stubDialog(null));
        [$status, $json] = $this->call($kernel, 'POST', '/api/dialog/open-file', json_encode(['filter' => 'image']));

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertTrue($json['data']['cancelled']);
    }

    /** A no-op static provider, shared by the dialog tests. */
    private function kernelStatic(): StaticProviderInterface
    {
        return new class implements StaticProviderInterface {
            public function findFileByRequest(RequestInterface $request): ?ResponseInterface
            {
                return null;
            }
        };
    }

    /** A native Dialog API stub whose file-open returns the given path (or null). */
    private function stubDialog(?string $path): \Boson\Api\Dialog\DialogApiInterface
    {
        return $this->stubDialogWith($path, null);
    }

    /** A native Dialog API stub whose file-open and directory-select return the given paths. */
    private function stubDialogWith(?string $filePath, ?string $directoryPath): \Boson\Api\Dialog\DialogApiInterface
    {
        return new class($filePath, $directoryPath) implements \Boson\Api\Dialog\DialogApiInterface {
            public function __construct(
                private readonly ?string $filePath,
                private readonly ?string $directoryPath,
            ) {}
            public function selectFile(?string $directory = null, iterable $filter = []): ?string
            {
                return $this->filePath;
            }
            public function selectFiles(?string $directory = null, iterable $filter = []): iterable
            {
                return [];
            }
            public function selectDirectory(?string $directory = null, iterable $filter = []): ?string
            {
                return $this->directoryPath;
            }
            public function selectDirectories(?string $directory = null, iterable $filter = []): iterable
            {
                return [];
            }
            public function open(string|\Stringable $uri): void {}
        };
    }

    public function testSelectDirectoryIsUnavailableWithoutDialog(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'POST', '/api/dialog/select-directory');

        self::assertSame(503, $status);
        self::assertFalse($json['ok']);
    }

    public function testSelectDirectoryReturnsChosenPath(): void
    {
        $kernel = new Kernel(
            $this->kernelStatic(),
            Database::connect(':memory:'),
            \dirname(__DIR__, 2),
            $this->stubDialogWith(null, '/tmp/somewhere')
        );
        [$status, $json] = $this->call($kernel, 'POST', '/api/dialog/select-directory');

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertSame('/tmp/somewhere', $json['data']['path']);
    }

    public function testExportImportAsNewAndReplaceRoundTrip(): void
    {
        $pdo    = Database::connect(':memory:');
        (new Migrator($pdo))->migrate();
        $this->lastPdo = $pdo;
        $kernel = new Kernel($this->kernelStatic(), $pdo, \dirname(__DIR__, 2));

        $siteId    = $this->seedSite('Source');
        $targetId  = $this->seedSite('Target');

        [, $created] = $this->call(
            $kernel,
            'POST',
            '/api/sites/' . $siteId . '/drafts',
            json_encode(['title' => 'Hello', 'alias' => 'hello', 'html' => '<p>Body</p>'])
        );
        self::assertTrue($created['ok']);
        $draftId = $created['data']['id'];

        $dir = sys_get_temp_dir() . '/grafida-export-test-' . uniqid();
        mkdir($dir);

        [$status, $exported] = $this->call(
            $kernel,
            'POST',
            '/api/drafts/' . $draftId . '/export',
            json_encode(['directory' => $dir])
        );
        self::assertSame(200, $status);
        self::assertTrue($exported['ok']);
        self::assertFileExists($exported['data']['path']);

        $payload = json_decode((string) file_get_contents($exported['data']['path']), true);
        self::assertSame(1, $payload['grafidaExport']);
        self::assertSame('Hello', $payload['draft']['title']);
        self::assertArrayNotHasKey('siteId', $payload['draft']);

        unlink($exported['data']['path']);
        rmdir($dir);

        // Import as a brand-new draft on a different site.
        [$status, $importedNew] = $this->call(
            $kernel,
            'POST',
            '/api/drafts/import',
            json_encode(['siteId' => $targetId, 'payload' => $payload])
        );
        self::assertSame(201, $status);
        self::assertTrue($importedNew['ok']);
        self::assertSame($targetId, $importedNew['data']['siteId']);
        self::assertNull($importedNew['data']['remoteId']);
        self::assertSame('Hello', $importedNew['data']['title']);
        self::assertNotSame($draftId, $importedNew['data']['id']);

        // Replace an existing draft's content, keeping its own site/remote linkage.
        [, $target] = $this->call(
            $kernel,
            'POST',
            '/api/sites/' . $targetId . '/drafts',
            json_encode(['title' => 'Old', 'html' => '<p>Old</p>'])
        );
        $targetDraftId = $target['data']['id'];

        [$status, $replaced] = $this->call(
            $kernel,
            'POST',
            '/api/drafts/' . $targetDraftId . '/import',
            json_encode(['payload' => $payload])
        );
        self::assertSame(200, $status);
        self::assertTrue($replaced['ok']);
        self::assertSame($targetDraftId, $replaced['data']['id']);
        self::assertSame($targetId, $replaced['data']['siteId']);
        self::assertSame('Hello', $replaced['data']['title']);
        self::assertSame('<p>Body</p>', $replaced['data']['html']);
    }
}
