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
 * Minimal HTTP client used to talk to remote Joomla sites.
 *
 * Uses cURL when the extension is available (the common case) and falls back to
 * the PHP stream wrapper otherwise, so the application keeps working even on a
 * runtime built without ext-curl.
 */
final class HttpClient implements Transport
{
    public function __construct(
        private readonly int $timeout = 30,
    ) {}

    /**
     * Performs an HTTP request.
     *
     * @param string                $method  GET, POST, PATCH, DELETE, ...
     * @param string                $url     Absolute URL.
     * @param array<string, string> $headers Header name => value.
     * @param string|null           $body    Raw request body, or null.
     *
     * @throws HttpException on transport failure (DNS, connection, timeout).
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        if (\function_exists('curl_init')) {
            return $this->requestCurl($method, $url, $headers, $body);
        }

        return $this->requestStream($method, $url, $headers, $body);
    }

    /** @param array<string, string> $headers */
    private function requestCurl(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $ch = curl_init();

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];

        curl_setopt_array($ch, [
            \CURLOPT_URL            => $url,
            \CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS      => 5,
            // Preserve the method and body across 301/302/303 redirects. Without
            // this, libcurl rewrites a redirected POST/PATCH into a bodyless GET,
            // so a publish to a site that redirects (http→https, www, trailing
            // slash) silently no-ops: Joomla returns the unchanged article with
            // 200 OK and Grafida reports success while nothing was written.
            \CURLOPT_POSTREDIR      => \CURL_REDIR_POST_ALL,
            \CURLOPT_CONNECTTIMEOUT => $this->timeout,
            \CURLOPT_TIMEOUT        => $this->timeout,
            \CURLOPT_HTTPHEADER     => $headerLines,
            \CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return \strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, \CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($ch);

        // No curl_close(): the handle is freed when it goes out of scope. The call has
        // been a no-op since PHP 8.0 and is deprecated as of 8.5.
        if ($result === false) {
            throw new HttpException('HTTP request failed: ' . curl_error($ch));
        }

        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);

        return new HttpResponse($status, (string) $result, $responseHeaders);
    }

    /** @param array<string, string> $headers */
    private function requestStream(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $headerString = '';
        foreach ($headers as $name => $value) {
            $headerString .= $name . ': ' . $value . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method'        => strtoupper($method),
                'header'        => $headerString,
                'content'       => $body ?? '',
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);

        if ($responseBody === false) {
            throw new HttpException('HTTP request failed for ' . $url);
        }

        // PHP 8.5 deprecates the magic $http_response_header in favour of
        // http_get_last_response_headers(); the bundled 8.4 runtime only has the
        // former, so prefer the function when it exists and fall back otherwise.
        if (\function_exists('http_get_last_response_headers')) {
            $rawHeaders = http_get_last_response_headers() ?? [];
        } else {
            // Read via get_defined_vars() so the literal $http_response_header
            // token is absent at compile time — it is a compile-time E_DEPRECATED
            // on PHP 8.5+, even on a branch that never runs there. On the bundled
            // PHP 8.4 runtime this resolves the engine-populated headers array.
            $defined = get_defined_vars();
            /** @var list<string> $rawHeaders */
            $rawHeaders = $defined['http_response_header'] ?? [];
        }

        [$status, $responseHeaders] = $this->parseStreamHeaders($rawHeaders);

        return new HttpResponse($status, $responseBody, $responseHeaders);
    }

    /**
     * @param list<string> $rawHeaders
     *
     * @return array{0: int, 1: array<string, string>}
     */
    private function parseStreamHeaders(array $rawHeaders): array
    {
        $status  = 0;
        $headers = [];

        foreach ($rawHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];

                continue;
            }

            $parts = explode(':', $line, 2);
            if (\count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return [$status, $headers];
    }
}
