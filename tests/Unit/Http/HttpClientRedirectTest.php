<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Http;

use Grafida\Http\HttpClient;
use Grafida\Http\InsecureUrlException;
use Grafida\Http\Security\UrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Redirect handling in the transport.
 *
 * Grafida follows redirects by hand rather than letting libcurl do it, so that {@see UrlGuard} can
 * approve each hop *before* the credential is sent to it. That makes resolving a `Location` header
 * our problem, and servers write it in every form the RFC allows — so the resolution is pinned
 * here. Getting it wrong is not merely a broken link: a `Location` mis-resolved onto the wrong
 * authority is a request sent to the wrong host with the token attached.
 */
final class HttpClientRedirectTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: string|null}> */
    public static function locations(): iterable
    {
        yield 'absolute' => [
            'https://example.com/a/b',
            'https://other.example.com/x',
            'https://other.example.com/x',
        ];

        yield 'root-relative' => [
            'https://example.com/a/b',
            '/x/y',
            'https://example.com/x/y',
        ];

        yield 'root-relative keeps a non-default port' => [
            'https://example.com:8443/a/b',
            '/x',
            'https://example.com:8443/x',
        ];

        yield 'protocol-relative inherits the scheme' => [
            'https://example.com/a/b',
            '//cdn.example.com/x',
            'https://cdn.example.com/x',
        ];

        yield 'path-relative resolves against the directory' => [
            'https://example.com/a/b',
            'c',
            'https://example.com/a/c',
        ];

        yield 'path-relative from a directory URL' => [
            'https://example.com/a/',
            'c',
            'https://example.com/a/c',
        ];

        yield 'no usable base' => [
            'not a url',
            '/x',
            null,
        ];
    }

    #[DataProvider('locations')]
    public function testResolvesLocationHeaders(string $base, string $location, ?string $expected): void
    {
        $this->assertSame($expected, HttpClient::absoluteUrl($base, $location));
    }

    public function testTheFirstRequestIsRefusedBeforeAnyConnectionIsAttempted(): void
    {
        // The point of the check living in request() is that a forbidden URL costs
        // no DNS lookup, no socket and no packet — the exception arrives instantly
        // rather than after a connection timeout.
        $client = new HttpClient(30, UrlPolicy::Site);

        $started = microtime(true);

        try {
            $client->request('GET', 'http://192.0.2.1/index.php/api');
            $this->fail('A plain-HTTP site URL must be refused');
        } catch (InsecureUrlException) {
            // expected
        }

        $this->assertLessThan(
            1.0,
            microtime(true) - $started,
            'The refusal must happen before any connection attempt, not after one times out'
        );
    }
}
