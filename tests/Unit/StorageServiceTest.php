<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Ai\AiServiceManager;
use Grafida\Ai\AiServiceRepository;
use Grafida\Http\HttpResponse;
use Grafida\Joomla\ApiClient;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Grafida\Storage\StorageService;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\Support\ArraySecretStore;
use Grafida\Tests\Unit\Support\FakeTransport;
use Joomla\Database\DatabaseInterface;

final class StorageServiceTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::memory();
    }

    private function transport(): FakeTransport
    {
        $ok = new HttpResponse(
            200,
            '{"data":[{"type":"levels","id":"1","attributes":{"title":"Public"}}]}',
            ['Content-Type' => 'application/vnd.api+json']
        );

        return (new FakeTransport())
            ->on('https://example.com/index.php/api/v1/users/levels', $ok);
    }

    /**
     * Builds a StorageService over the shared secret store, plus a site and an
     * AI service whose secrets both live in it.
     *
     * @return array{0: StorageService, 1: ArraySecretStore}
     */
    private function seed(): array
    {
        $store = new ArraySecretStore();

        $sites = new SiteService(
            new SiteRepository($this->db),
            new ApiClient($this->transport()),
            $store
        );
        $sites->create('My Site', 'https://example.com', 'site-token');

        $ai = new AiServiceManager(new AiServiceRepository($this->db), $store);
        $ai->create([
            'name'     => 'Some model',
            'provider' => 'openai',
            'endpoint' => 'https://api.example.com/v1',
            'model'    => 'gpt-whatever',
            'key'      => 'ai-key',
        ]);

        return [new StorageService($this->db, $sites, $ai), $store];
    }

    public function testResetDeletesEveryStoredSecret(): void
    {
        [$storage, $store] = $this->seed();

        self::assertContains('site-token', array_values($store->secrets));
        self::assertContains('ai-key', array_values($store->secrets));

        $storage->reset();

        // The OS secret store is not a table, so a bulk DELETE reaches none of
        // it — both kinds of secret have to be deleted through their own
        // service, or the keychain entry outlives the row naming it and becomes
        // an orphan nothing can find again.
        self::assertSame([], $store->secrets);
    }

    public function testResetEmptiesEveryApplicationTable(): void
    {
        [$storage] = $this->seed();

        $storage->reset();

        $pdo = TestDatabase::connection($this->db);

        foreach (['sites', 'ai_services'] as $table) {
            self::assertSame(
                0,
                (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(),
                $table . ' should be empty after a reset'
            );
        }
    }

    public function testResetKeepsTheMigrationBookkeeping(): void
    {
        [$storage] = $this->seed();

        $pdo    = TestDatabase::connection($this->db);
        $before = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();

        self::assertGreaterThan(0, $before);

        $storage->reset();

        // 04_ai_chat_response_chain.sql is a bare ALTER TABLE and is not
        // re-runnable, which is what makes this bookkeeping load-bearing.
        self::assertSame(
            $before,
            (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()
        );
    }
}
