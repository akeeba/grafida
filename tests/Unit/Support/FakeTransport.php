<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Support;

use Grafida\Http\HttpException;
use Grafida\Http\HttpResponse;
use Grafida\Http\Transport;

/**
 * In-memory HTTP transport for tests. Responses are queued by an exact URL, or
 * a default response is returned. Records every request for assertions.
 */
final class FakeTransport implements Transport
{
    /** @var array<string, HttpResponse> */
    private array $byUrl = [];

    /** @var list<string> */
    private array $throwForUrls = [];

    /** @var list<array{method: string, url: string, headers: array<string,string>, body: ?string}> */
    public array $requests = [];

    public function __construct(
        private readonly HttpResponse $default = new HttpResponse(404, ''),
    ) {}

    public function on(string $url, HttpResponse $response): self
    {
        $this->byUrl[$url] = $response;

        return $this;
    }

    public function throwFor(string $url): self
    {
        $this->throwForUrls[] = $url;

        return $this;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if (in_array($url, $this->throwForUrls, true)) {
            throw new HttpException('Simulated transport failure for ' . $url);
        }

        return $this->byUrl[$url] ?? $this->default;
    }
}
