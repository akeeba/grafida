<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Http\HttpResponse;
use Grafida\Tests\Unit\Support\FakeTransport;
use Grafida\Update\UpdateService;

final class UpdateServiceTest extends TestCase
{
    private const URL = 'https://cdn.example.com/grafida.json';

    public function testCanonicalUpdateUrlUsesGrafidaApp(): void
    {
        $this->assertSame('https://grafida.app/updates/grafida.json', UpdateService::UPDATE_URL);
    }

    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/grafida-update-test-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheFile);
    }

    private function service(FakeTransport $http, string $current = '0.1'): UpdateService
    {
        return new UpdateService($http, $current, $this->cacheFile, self::URL);
    }

    private function payload(string $version): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'version'  => $version,
            'date'     => '2026-07-01',
            'infoURL'  => 'https://github.com/grafida/grafida/releases/tag/' . $version,
            'download' => 'https://example.com/Grafida-' . $version . '.dmg',
        ]));
    }

    public function testReportsANewerVersionAndCachesTheDocument(): void
    {
        $http   = (new FakeTransport())->on(self::URL, $this->payload('0.2'));
        $status = $this->service($http)->status();

        $this->assertTrue($status['available']);
        $this->assertSame('0.2', $status['version']);
        $this->assertSame('https://github.com/grafida/grafida/releases/tag/0.2', $status['infoURL']);
        $this->assertSame('https://example.com/Grafida-0.2.dmg', $status['download']);
        $this->assertFileExists($this->cacheFile);
        $this->assertCount(1, $http->requests);
    }

    public function testSameVersionIsNotAnUpdate(): void
    {
        $http   = (new FakeTransport())->on(self::URL, $this->payload('0.1'));
        $status = $this->service($http)->status();

        $this->assertFalse($status['available']);
        $this->assertNull($status['version']);
        $this->assertNull($status['download']);
    }

    public function testFreshCacheIsNotRefetched(): void
    {
        file_put_contents($this->cacheFile, json_encode(['version' => '0.2', 'infoURL' => 'x']));

        $http   = new FakeTransport();
        $status = $this->service($http)->status();

        $this->assertTrue($status['available']);
        $this->assertSame([], $http->requests, 'A fresh cache must not trigger a network fetch.');
    }

    public function testStaleCacheIsRefetched(): void
    {
        file_put_contents($this->cacheFile, json_encode(['version' => '0.2']));
        // Backdate the cache well beyond the 12-hour window.
        touch($this->cacheFile, time() - 13 * 60 * 60);

        $http = (new FakeTransport())->on(self::URL, $this->payload('0.3'));

        $status = $this->service($http)->status();

        $this->assertSame('0.3', $status['version']);
        $this->assertCount(1, $http->requests);
    }

    public function testFailedFetchWithoutCacheWritesEmptyDocument(): void
    {
        $http   = (new FakeTransport())->throwFor(self::URL);
        $status = $this->service($http)->status();

        $this->assertFalse($status['available']);
        $this->assertFileExists($this->cacheFile);
        $this->assertSame('{}', trim((string) file_get_contents($this->cacheFile)));
    }

    public function testFailedFetchFallsBackToExistingCache(): void
    {
        file_put_contents($this->cacheFile, json_encode(['version' => '0.9']));
        touch($this->cacheFile, time() - 13 * 60 * 60);

        $http   = (new FakeTransport())->throwFor(self::URL);
        $status = $this->service($http)->status();

        $this->assertTrue($status['available']);
        $this->assertSame('0.9', $status['version']);
    }
}
