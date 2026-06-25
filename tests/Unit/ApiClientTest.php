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

    public function testCreateArticleWrapsAttributesInJsonApiDocument(): void
    {
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
        self::assertSame('articles', $sent['data']['type']);
        self::assertSame('Hello', $sent['data']['attributes']['title']);
        self::assertSame(2, $sent['data']['attributes']['catid']);
    }
}
