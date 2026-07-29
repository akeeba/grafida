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
use Grafida\Http\HttpResponse;
use Grafida\Tests\Support\TestContainer;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\Support\FakeTransport;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use PHPUnit\Framework\TestCase;

/**
 * Pins gh-29's contract for a transport failure while browsing a site's remote
 * articles (`GET /api/sites/{id}/articles`) — the exact screen the issue's
 * screenshots show: a connectivity failure (offline machine, dead DNS, site
 * down) must surface as a *recognisable*, friendly-mappable error rather than
 * the generic `{code: "internal"}` / HTTP 500 every other uncaught throwable
 * gets.
 */
final class ArticleRoutingTest extends TestCase
{
    private ?DatabaseInterface $lastDb = null;

    private const API_BASE = 'https://example.test/index.php/api';

    /** A kernel wired with $fake as the site-facing transport (bypassing the shared Request Log). */
    private function kernelWithFakeTransport(FakeTransport $fake): Kernel
    {
        $container    = TestContainer::create();
        $this->lastDb = $container->get(DatabaseInterface::class);

        $container->set('http.default', static fn (Container $c): FakeTransport => $fake, true);

        return $container->get(Kernel::class);
    }

    /**
     * Inserts a site that is already "connected" — it has a working `api_base`
     * and a plaintext token — so `SiteContext::connectedSite()` succeeds without
     * probing, and the only outbound call the route makes is the articles list
     * itself.
     */
    private function seedConnectedSite(): int
    {
        \assert($this->lastDb !== null, 'seedConnectedSite() must be called after kernelWithFakeTransport()');

        $now = gmdate('Y-m-d H:i:s');
        $pdo = TestDatabase::connection($this->lastDb);
        $pdo->prepare(
            'INSERT INTO sites (title, base_url, api_base, insecure_token, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(['Site', 'https://example.test', self::API_BASE, 'test-token', $now, $now]);

        return (int) $pdo->lastInsertId();
    }

    /** The exact URL ArticleController::remoteArticles() requests with no query-string filters. */
    private function defaultArticlesUrl(): string
    {
        $query = [
            'page[limit]'     => 20,
            'page[offset]'    => 0,
            'list[ordering]'  => 'a.id',
            'list[direction]' => 'desc',
        ];

        return self::API_BASE . '/v1/content/articles?' . http_build_query($query);
    }

    /** @return array{0: int, 1: mixed} */
    private function call(Kernel $kernel, string $method, string $path): array
    {
        $request  = new Request($method, 'boson://app' . $path, [], '');
        $response = $kernel->handle($request);

        return [(int) (string) $response->status, json_decode((string) $response->body, true)];
    }

    public function testConnectivityFailureIsSurfacedAsNetworkUnreachable(): void
    {
        // 6 = CURLE_COULDNT_RESOLVE_HOST: an offline machine or dead DNS.
        $fake   = (new FakeTransport())->throwFor($this->defaultArticlesUrl(), 6);
        $kernel = $this->kernelWithFakeTransport($fake);
        $siteId = $this->seedConnectedSite();

        [$status, $json] = $this->call($kernel, 'GET', "/api/sites/{$siteId}/articles");

        self::assertSame(503, $status);
        self::assertFalse($json['ok']);
        self::assertSame('network_unreachable', $json['code']);
        self::assertNotEmpty($json['detail']);
    }

    public function testNonConnectivityTransportFailureIsSurfacedAsTransport(): void
    {
        // 60 = CURLE_PEER_FAILED_VERIFICATION: we *did* talk to something (a bad
        // certificate), so this must not tell the user to check their internet
        // connection.
        $fake   = (new FakeTransport())->throwFor($this->defaultArticlesUrl(), 60);
        $kernel = $this->kernelWithFakeTransport($fake);
        $siteId = $this->seedConnectedSite();

        [$status, $json] = $this->call($kernel, 'GET', "/api/sites/{$siteId}/articles");

        self::assertSame(503, $status);
        self::assertFalse($json['ok']);
        self::assertSame('transport', $json['code']);
        self::assertNotEmpty($json['detail']);
    }

    /**
     * Listing local drafts is a purely local read, so it must keep working with
     * no network at all. It did not: `SiteContext::withCategoryTitles()` looked
     * the site's categories up *strictly*, so an offline machine with a cold
     * reference cache threw before the route could answer — taking the whole
     * Articles screen down, Local Articles tab included, when only the remote
     * tab actually needs a network (gh-29).
     */
    public function testLocalDraftsAreListedWhileOffline(): void
    {
        // 6 = CURLE_COULDNT_RESOLVE_HOST, on every outbound call: fully offline.
        $fake   = (new FakeTransport())->throwForAll(6);
        $kernel = $this->kernelWithFakeTransport($fake);
        $siteId = $this->seedConnectedSite();
        $this->seedDraft($siteId, 'Offline draft');

        [$status, $json] = $this->call($kernel, 'GET', "/api/sites/{$siteId}/drafts");

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertCount(1, $json['data']);
        self::assertSame('Offline draft', $json['data'][0]['title']);
        // The decoration is simply absent — the list itself is what matters.
        self::assertNull($json['data'][0]['categoryTitle']);
    }

    /**
     * The remote list must carry each article's `created`/`modified` through to
     * the SPA, which renders them on the row (gh-53).
     *
     * Nothing in Grafida *builds* these — com_content's JSON:API view lists them
     * in `$fieldsToRenderList` and `ApiClient::flatten()` hands every attribute
     * of the resource through untouched — which is exactly why the contract is
     * worth pinning: the dates would vanish from both tabs, silently and with no
     * error anywhere, the day `flatten()` grew an attribute whitelist.
     */
    public function testRemoteArticleListCarriesTheJoomlaTimestamps(): void
    {
        $payload = json_encode([
            'data' => [[
                'type'       => 'articles',
                'id'         => '17',
                'attributes' => [
                    'id'       => 17,
                    'title'    => 'Dated article',
                    'alias'    => 'dated-article',
                    'state'    => 1,
                    'catid'    => 2,
                    // Naive UTC, exactly as Joomla stores and serialises it.
                    'created'  => '2026-07-20 09:15:00',
                    'modified' => '2026-07-29 08:30:00',
                ],
            ]],
            'meta' => ['total-pages' => 1],
        ], JSON_THROW_ON_ERROR);

        $fake   = (new FakeTransport())->on($this->defaultArticlesUrl(), new HttpResponse(200, $payload));
        $kernel = $this->kernelWithFakeTransport($fake);
        $siteId = $this->seedConnectedSite();

        [$status, $json] = $this->call($kernel, 'GET', "/api/sites/{$siteId}/articles");

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertCount(1, $json['data']['items']);
        self::assertSame('2026-07-20 09:15:00', $json['data']['items'][0]['created']);
        self::assertSame('2026-07-29 08:30:00', $json['data']['items'][0]['modified']);
    }

    /**
     * A local row shows the *draft's* own timestamps, so `Draft::toArray()` must
     * keep exposing them on the drafts route (gh-53). They are naive UTC strings
     * and stay that way — the SPA parses them component-wise, never with
     * Date.parse() (see js/util/datetime.js).
     */
    public function testDraftListCarriesTheDraftTimestamps(): void
    {
        $fake   = (new FakeTransport())->throwForAll(6);
        $kernel = $this->kernelWithFakeTransport($fake);
        $siteId = $this->seedConnectedSite();
        $this->seedDraft($siteId, 'Dated draft');

        [$status, $json] = $this->call($kernel, 'GET', "/api/sites/{$siteId}/drafts");

        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $json['data'][0]['createdAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $json['data'][0]['updatedAt']);
    }

    private function seedDraft(int $siteId, string $title): void
    {
        \assert($this->lastDb !== null, 'seedDraft() must be called after kernelWithFakeTransport()');

        $now = gmdate('Y-m-d H:i:s');
        TestDatabase::connection($this->lastDb)
            ->prepare('INSERT INTO drafts (site_id, title, alias, catid, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$siteId, $title, 'offline-draft', 7, $now, $now]);
    }
}
