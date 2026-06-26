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
    private function kernel(): Kernel
    {
        $pdo = Database::connect(':memory:');
        (new Migrator($pdo))->migrate();

        $static = new class implements StaticProviderInterface {
            public function findFileByRequest(RequestInterface $request): ?ResponseInterface
            {
                return null;
            }
        };

        return new Kernel($static, $pdo, \dirname(__DIR__, 2));
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

    public function testResetStorageWipesData(): void
    {
        $kernel = $this->kernel();

        // Seed a draft directly so the reset has something to remove.
        [, $created] = $this->call($kernel, 'POST', '/api/sites/1/drafts', json_encode(['title' => 'Doomed']));
        self::assertTrue($created['ok']);

        [$status, $json] = $this->call($kernel, 'POST', '/api/settings/storage/reset');
        self::assertSame(200, $status);
        self::assertTrue($json['ok']);

        [, $drafts] = $this->call($kernel, 'GET', '/api/sites/1/drafts');
        self::assertSame([], $drafts['data']);
    }
}
