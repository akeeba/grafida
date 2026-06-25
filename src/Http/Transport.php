<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http;

/**
 * Performs HTTP requests. Implemented by {@see HttpClient} and faked in tests.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     *
     * @throws HttpException on transport failure.
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
