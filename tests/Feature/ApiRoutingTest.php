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
use Grafida\Application\Kernel;
use Grafida\Tests\Support\StubDialog;
use Grafida\Tests\Support\TestContainer;
use Grafida\Tests\Support\TestDatabase;
use Joomla\Database\DatabaseInterface;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the kernel's HTTP routing without opening a window — the kernel is a
 * pure Request -> Response function.
 */
final class ApiRoutingTest extends TestCase
{
    private ?DatabaseInterface $lastDb = null;

    private function kernel(): Kernel
    {
        $container    = TestContainer::create();
        $this->lastDb = $container->get(DatabaseInterface::class);

        return $container->get(Kernel::class);
    }

    /** Inserts a bare site row (drafts reference sites via a foreign key). */
    private function seedSite(string $title = 'Site'): int
    {
        \assert($this->lastDb !== null, 'seedSite() must be called after kernel()');

        $now = gmdate('Y-m-d H:i:s');
        $pdo = TestDatabase::connection($this->lastDb);
        $pdo->prepare(
            'INSERT INTO sites (title, base_url, created_at, updated_at) VALUES (?, ?, ?, ?)'
        )->execute([$title, 'https://example.test', $now, $now]);

        return (int) $pdo->lastInsertId();
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

    public function testSlashToolsDefaultsToEnabled(): void
    {
        [, $boot] = $this->call($this->kernel(), 'GET', '/api/bootstrap');

        self::assertTrue($boot['data']['slashTools']);
    }

    public function testSlashToolsPersists(): void
    {
        $kernel = $this->kernel();

        [$status, $json] = $this->call($kernel, 'POST', '/api/settings/slash-tools', json_encode(['enabled' => false]));

        self::assertSame(200, $status);
        self::assertFalse($json['data']['slashTools']);

        [, $boot] = $this->call($kernel, 'GET', '/api/bootstrap');
        self::assertFalse($boot['data']['slashTools']);

        // Back on again — a stored "off" must not be sticky.
        [, $json] = $this->call($kernel, 'POST', '/api/settings/slash-tools', json_encode(['enabled' => true]));
        self::assertTrue($json['data']['slashTools']);

        [, $boot] = $this->call($kernel, 'GET', '/api/bootstrap');
        self::assertTrue($boot['data']['slashTools']);
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

    public function testDraftRoundTripsCreatedByAlias(): void
    {
        $kernel = $this->kernel();
        $siteId = $this->seedSite();

        [$status, $created] = $this->call(
            $kernel,
            'POST',
            '/api/sites/' . $siteId . '/drafts',
            json_encode(['title' => 'Ghostwritten', 'createdByAlias' => 'Guest Author'])
        );

        self::assertSame(200, $status);
        self::assertSame('Guest Author', $created['data']['createdByAlias']);

        [, $fetched] = $this->call($kernel, 'GET', '/api/drafts/' . $created['data']['id']);
        self::assertSame('Guest Author', $fetched['data']['createdByAlias']);

        // Clearing it must stick: an empty alias means "credit the real author",
        // and PublishService relies on the draft carrying that as a real value.
        [, $updated] = $this->call(
            $kernel,
            'PUT',
            '/api/drafts/' . $created['data']['id'],
            json_encode(['title' => 'Ghostwritten', 'createdByAlias' => ''])
        );

        self::assertSame('', $updated['data']['createdByAlias']);
    }

    public function testDraftOmittingCreatedByAliasDefaultsToEmpty(): void
    {
        $kernel = $this->kernel();
        $siteId = $this->seedSite();

        [$status, $created] = $this->call(
            $kernel,
            'POST',
            '/api/sites/' . $siteId . '/drafts',
            json_encode(['title' => 'No alias'])
        );

        self::assertSame(200, $status);
        self::assertSame('', $created['data']['createdByAlias']);
    }

    public function testMediaBlobMissingIs404(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/media/999');

        self::assertSame(404, $status);
        self::assertFalse($json['ok']);
    }

    /**
     * The multimodal image fetch refuses an off-site URL before it makes any
     * request, so this asserts the guard without touching the network.
     */
    public function testSiteImageRefusesAnOffSiteUrl(): void
    {
        $kernel = $this->kernel();
        $siteId = $this->seedSite();

        [$status, $json] = $this->call(
            $kernel,
            'GET',
            '/api/sites/' . $siteId . '/image?url=' . urlencode('https://evil.example.net/a.png'),
        );

        self::assertSame(403, $status);
        self::assertFalse($json['ok']);
    }

    public function testSiteImageRequiresAUrl(): void
    {
        $kernel = $this->kernel();
        $siteId = $this->seedSite();

        [$status, $json] = $this->call($kernel, 'GET', '/api/sites/' . $siteId . '/image');

        self::assertSame(400, $status);
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

        // Reset wipes the site row too, so the site-scoped listing endpoint would now
        // 404 — query the drafts table directly to confirm the wipe reached it.
        $count = TestDatabase::connection($this->lastDb)->query('SELECT COUNT(*) FROM drafts')->fetchColumn();
        self::assertSame(0, (int) $count);
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

        $kernel = TestContainer::create(dialog: new StubDialog(filePath: $tmp))->get(Kernel::class);
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
        $kernel = TestContainer::create(dialog: new StubDialog())->get(Kernel::class);
        [$status, $json] = $this->call($kernel, 'POST', '/api/dialog/open-file', json_encode(['filter' => 'image']));

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertTrue($json['data']['cancelled']);
    }

    public function testSelectDirectoryIsUnavailableWithoutDialog(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'POST', '/api/dialog/select-directory');

        self::assertSame(503, $status);
        self::assertFalse($json['ok']);
    }

    public function testSelectDirectoryReturnsChosenPath(): void
    {
        $kernel = TestContainer::create(dialog: new StubDialog(directoryPath: '/tmp/somewhere'))->get(Kernel::class);
        [$status, $json] = $this->call($kernel, 'POST', '/api/dialog/select-directory');

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertSame('/tmp/somewhere', $json['data']['path']);
    }

    public function testExportImportAsNewAndReplaceRoundTrip(): void
    {
        $kernel = $this->kernel();

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
