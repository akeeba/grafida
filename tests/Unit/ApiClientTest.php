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
use Grafida\Joomla\ApiException;
use Grafida\Tests\Unit\Support\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;

final class ApiClientTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function rootProvider(): iterable
    {
        yield 'bare'            => ['https://example.com', 'https://example.com'];
        yield 'trailing slash'  => ['https://example.com/', 'https://example.com'];
        yield 'with /api'       => ['https://example.com/api', 'https://example.com'];
        yield 'with /index.php/api' => ['https://example.com/index.php/api', 'https://example.com'];
        yield 'with /api/index.php' => ['https://example.com/api/index.php', 'https://example.com'];
        yield 'subfolder'       => ['https://example.com/joomla/index.php/api', 'https://example.com/joomla'];
    }

    #[DataProvider('rootProvider')]
    public function testNormaliseRoot(string $input, string $expected): void
    {
        self::assertSame($expected, ApiClient::normaliseRoot($input));
    }

    public function testCandidateBasesPrefersIndexPhpApi(): void
    {
        $bases = ApiClient::candidateBases('https://example.com');

        self::assertSame([
            'https://example.com/index.php/api',
            'https://example.com/api/index.php',
            'https://example.com/api',
        ], $bases);
    }

    public function testProbeReturnsFirstWorkingBase(): void
    {
        $jsonApi = ['Content-Type' => 'application/vnd.api+json'];
        $transport = new FakeTransport();
        // First candidate fails at transport level, second works.
        $transport->throwFor('https://example.com/index.php/api/v1/users/levels');
        $transport->on(
            'https://example.com/api/index.php/v1/users/levels',
            new HttpResponse(200, '{"data":[{"type":"levels","id":"1","attributes":{"title":"Public"}}]}', $jsonApi)
        );

        $client = new ApiClient($transport);

        self::assertSame('https://example.com/api/index.php', $client->probeApiBase('https://example.com', 'tok'));
    }

    public function testProbeReportsAuthError(): void
    {
        $jsonApi = ['Content-Type' => 'application/vnd.api+json'];
        $transport = new FakeTransport(new HttpResponse(401, '{"errors":[{"title":"Forbidden"}]}', $jsonApi));

        $client = new ApiClient($transport);

        $this->expectException(ApiException::class);
        $client->probeApiBase('https://example.com', 'bad-token');
    }

    public function testListArticlesPageReturnsItemsAndPaginationTotal(): void
    {
        // The browsable list pages through articles, so it must forward the
        // page/limit/sort/filter query and surface Joomla's `meta.total-pages`.
        $body = json_encode([
            'data' => [
                ['type' => 'articles', 'id' => '9', 'attributes' => ['title' => 'Nine']],
                ['type' => 'articles', 'id' => '8', 'attributes' => ['title' => 'Eight']],
            ],
            'meta' => ['total-pages' => 4],
        ]);

        $url       = 'https://example.com/index.php/api/v1/content/articles'
            . '?page%5Blimit%5D=20&page%5Boffset%5D=20&list%5Bordering%5D=a.title&filter%5Bsearch%5D=hi';
        $transport = new FakeTransport();
        $transport->on($url, new HttpResponse(200, (string) $body));

        $client = new ApiClient($transport);
        $result = $client->listArticlesPage('https://example.com/index.php/api', 'tok', [
            'page[limit]'    => 20,
            'page[offset]'   => 20,
            'list[ordering]' => 'a.title',
            'filter[search]' => 'hi',
        ]);

        self::assertSame(4, $result['totalPages']);
        self::assertCount(2, $result['items']);
        self::assertSame(9, $result['items'][0]['id']);
        self::assertSame('Eight', $result['items'][1]['title']);
    }

    public function testListArticlesPageDefaultsToASinglePageWithoutMeta(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, '{"data":[]}'));
        $client    = new ApiClient($transport);
        $result    = $client->listArticlesPage('https://example.com/index.php/api', 'tok');

        self::assertSame([], $result['items']);
        self::assertSame(1, $result['totalPages']);
    }

    public function testListCategoriesFetchesEachPageAtOneHundredItems(): void
    {
        $base = 'https://example.com/index.php/api';
        $firstUrl = $base . '/v1/content/categories?filter%5Bextension%5D=com_content&page%5Blimit%5D=100&page%5Boffset%5D=0';
        $lastUrl  = $base . '/v1/content/categories?filter%5Bextension%5D=com_content&page%5Blimit%5D=100&page%5Boffset%5D=100';
        $transport = new FakeTransport();
        $transport->on($firstUrl, new HttpResponse(200, (string) json_encode([
            'data' => [['type' => 'categories', 'id' => '1', 'attributes' => ['title' => 'First']]],
            'meta' => ['total-pages' => 2],
        ])));
        $transport->on($lastUrl, new HttpResponse(200, (string) json_encode([
            'data' => [['type' => 'categories', 'id' => '101', 'attributes' => ['title' => 'Last']]],
            'meta' => ['total-pages' => 2],
        ])));

        $client = new ApiClient($transport);

        self::assertSame([
            ['title' => 'First', 'id' => 1],
            ['title' => 'Last', 'id' => 101],
        ], $client->listCategories($base, 'tok'));
        self::assertSame([$firstUrl, $lastUrl], array_column($transport->requests, 'url'));
    }

    public function testCreateArticleSendsAFlatFieldBody(): void
    {
        // Joomla's Web Services API takes a flat JSON object of field values for
        // writes (the JSON:API data/attributes envelope is for responses only).
        $transport = new FakeTransport();
        $transport->on(
            'https://example.com/index.php/api/v1/content/articles',
            new HttpResponse(201, '{"data":{"type":"articles","id":"42","attributes":{"title":"Hello"}}}')
        );

        $client  = new ApiClient($transport);
        $article = $client->createArticle('https://example.com/index.php/api', 'tok', ['title' => 'Hello', 'catid' => 2]);

        self::assertSame(42, $article['id']);
        self::assertSame('Hello', $article['title']);

        $sent = json_decode((string) $transport->requests[0]['body'], true);
        self::assertArrayNotHasKey('data', $sent);
        self::assertSame('Hello', $sent['title']);
        self::assertSame(2, $sent['catid']);
    }

    public function testCreateArticleRejectsCollectionResponseFromRedirectDowngrade(): void
    {
        // A POST silently downgraded to a GET lands on the collection endpoint and
        // returns a *list* with a 200 status. That must not read as a success.
        $transport = new FakeTransport();
        $transport->on(
            'https://example.com/index.php/api/v1/content/articles',
            new HttpResponse(200, '{"data":[{"type":"articles","id":"1"},{"type":"articles","id":"2"}]}')
        );

        $client = new ApiClient($transport);

        $this->expectException(ApiException::class);
        $client->createArticle('https://example.com/index.php/api', 'tok', ['title' => 'Hello']);
    }

    public function testUpdateArticleRejectsResponseWithoutAResourceId(): void
    {
        $transport = new FakeTransport();
        $transport->on(
            'https://example.com/index.php/api/v1/content/articles/42',
            new HttpResponse(200, '{"data":{"type":"articles","attributes":{"title":"Hello"}}}')
        );

        $client = new ApiClient($transport);

        $this->expectException(ApiException::class);
        $client->updateArticle('https://example.com/index.php/api', 'tok', 42, ['title' => 'Hello']);
    }

    public function testUpdateArticleSendsAFlatFieldBodyWithoutAnEnvelope(): void
    {
        // The record id for an update comes from the URL; the body is just the
        // changed fields, flat. Wrapping in data/attributes makes Joomla bind
        // nothing and silently return the unchanged article.
        $transport = new FakeTransport();
        $transport->on(
            'https://example.com/index.php/api/v1/content/articles/42',
            new HttpResponse(200, '{"data":{"type":"articles","id":"42","attributes":{"title":"Hello"}}}')
        );

        $client = new ApiClient($transport);
        $client->updateArticle('https://example.com/index.php/api', 'tok', 42, ['title' => 'Hello']);

        $sent = json_decode((string) $transport->requests[0]['body'], true);
        self::assertArrayNotHasKey('data', $sent);
        self::assertSame('Hello', $sent['title']);
    }

    public function testGetArticleExposesTextAttributeAndRelationships(): void
    {
        // Mirrors Joomla's real article API response: the body is the combined
        // `text` attribute and the category/tags are JSON:API relationships,
        // not attributes. flatten() must preserve the relationships block.
        $body = json_encode([
            'data' => [
                'type'          => 'articles',
                'id'            => '7',
                'attributes'    => ['title' => 'Hello', 'text' => '<p>Body</p>'],
                'relationships' => [
                    'category' => ['data' => ['type' => 'categories', 'id' => '9']],
                    'tags'     => ['data' => [
                        ['type' => 'tags', 'id' => '3'],
                        ['type' => 'tags', 'id' => '5'],
                    ]],
                ],
            ],
        ]);

        $transport = new FakeTransport();
        $transport->on('https://example.com/index.php/api/v1/content/articles/7', new HttpResponse(200, (string) $body));

        $client  = new ApiClient($transport);
        $article = $client->getArticle('https://example.com/index.php/api', 'tok', 7);

        self::assertSame('<p>Body</p>', $article['text']);
        self::assertSame('9', $article['relationships']['category']['data']['id']);
        self::assertSame('3', $article['relationships']['tags']['data'][0]['id']);
        self::assertSame('5', $article['relationships']['tags']['data'][1]['id']);
    }

    public function testGetConfigValueScansThePaginatedOneKeyItems(): void
    {
        // com_config's application view serves each config key as its own
        // single-attribute resource, all sharing the same id, and paginates
        // them (20 at a time by default) — hence the explicit page vars.
        $body = json_encode([
            'data' => [
                ['type' => 'application', 'id' => '800', 'attributes' => ['sitename' => 'Example']],
                ['type' => 'application', 'id' => '800', 'attributes' => ['unicodeslugs' => true]],
            ],
        ]);

        $transport = new FakeTransport();
        $transport->on(
            'https://example.com/index.php/api/v1/config/application?page%5Boffset%5D=0&page%5Blimit%5D=500',
            new HttpResponse(200, (string) $body)
        );

        $client = new ApiClient($transport);

        self::assertTrue($client->getConfigValue('https://example.com/index.php/api', 'tok', 'unicodeslugs'));
    }

    public function testGetConfigValueReturnsNullForAnAbsentKey(): void
    {
        $body = json_encode([
            'data' => [
                ['type' => 'application', 'id' => '800', 'attributes' => ['sitename' => 'Example']],
            ],
        ]);

        $transport = new FakeTransport();
        $transport->on(
            'https://example.com/index.php/api/v1/config/application?page%5Boffset%5D=0&page%5Blimit%5D=500',
            new HttpResponse(200, (string) $body)
        );

        $client = new ApiClient($transport);

        self::assertNull($client->getConfigValue('https://example.com/index.php/api', 'tok', 'unicodeslugs'));
    }
}
