<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http;

use Grafida\Http\Security\UrlGuard;
use Grafida\Http\Security\UrlPolicy;

/**
 * Minimal HTTP client used to talk to remote Joomla sites and AI providers.
 *
 * Uses cURL when the extension is available (the common case) and falls back to
 * the PHP stream wrapper otherwise, so the application keeps working even on a
 * runtime built without ext-curl.
 *
 * Security
 * --------
 * Every instance carries a {@see UrlPolicy} and a {@see UrlGuard}, and **no
 * request leaves this class without passing them** — not the first one, and not
 * any redirect hop. Enforcing it here rather than at the dozen call sites is the
 * point: a service added next year is covered without anyone remembering to
 * cover it. See {@see UrlGuard} for the rules themselves.
 *
 * ⚠️ **Redirects are followed by hand, not by libcurl.** `CURLOPT_FOLLOWLOCATION`
 * pursues the whole chain inside a single `curl_exec()`, with the request headers
 * — the `Authorization` token among them — resent to each hop, and returns only
 * the final response. There is no callback that can veto a hop, so the credential
 * would already have been sent by the time we could look. Following the chain
 * ourselves costs one `curl_exec()` per hop and lets {@see UrlGuard} approve each
 * destination *before* it is contacted.
 */
final class HttpClient implements Transport
{
    /** How many redirect hops to follow before giving up, matching the old `CURLOPT_MAXREDIRS`. */
    private const MAX_REDIRECTS = 5;

    /** Statuses that carry a `Location` we should act on. */
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private readonly UrlGuard $guard;

    public function __construct(
        private readonly int $timeout = 30,
        private readonly UrlPolicy $policy = UrlPolicy::Site,
        ?UrlGuard $guard = null,
    ) {
        $this->guard = $guard ?? new UrlGuard();
    }

    /**
     * Performs an HTTP request, following any permitted redirects.
     *
     * @param string                $method  GET, POST, PATCH, DELETE, ...
     * @param string                $url     Absolute URL.
     * @param array<string, string> $headers Header name => value.
     * @param string|null           $body    Raw request body, or null.
     *
     * @throws HttpException                                        on transport failure (DNS, connection, timeout).
     * @throws InsecureUrlException                                 when the URL is not permitted by the policy.
     * @throws \Grafida\Http\Security\BlockedRedirectException       when a redirect would leave the original domain.
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->guard->assertAllowed($url, $this->policy);

        $currentUrl = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $response = \function_exists('curl_init')
                ? $this->requestCurl($method, $currentUrl, $headers, $body)
                : $this->requestStream($method, $currentUrl, $headers, $body);

            $location = $this->redirectTarget($response);

            if ($location === null) {
                return $response;
            }

            $next = self::absoluteUrl($currentUrl, $location);

            if ($next === null) {
                throw new HttpException(
                    sprintf('Cannot follow the redirect from %s: "%s" is not a usable URL.', $currentUrl, $location)
                );
            }

            // Judged against the URL the *caller* asked for, never against the
            // previous hop, so a chain of small steps cannot walk off the domain.
            $this->guard->assertRedirectAllowed($url, $next, $this->policy);

            // The method and body are carried across every hop, 303 included —
            // what `CURLOPT_POSTREDIR => CURL_REDIR_POST_ALL` used to do, and it
            // is load-bearing rather than a detail. Downgrading a redirected
            // POST/PATCH to a bodyless GET is how a publish to a site that
            // redirects (http→https, www, trailing slash) silently no-ops:
            // Joomla answers the GET with the unchanged article and 200 OK, and
            // Grafida reports success while nothing was written.
            $currentUrl = $next;
        }

        throw new HttpException(
            sprintf('Too many redirects (more than %d) starting at %s.', self::MAX_REDIRECTS, $url)
        );
    }

    /**
     * Returns the `Location` this response redirects to, or null if it does not.
     */
    private function redirectTarget(HttpResponse $response): ?string
    {
        if (!\in_array($response->status, self::REDIRECT_STATUSES, true)) {
            return null;
        }

        $location = $response->headers['location'] ?? '';

        return trim($location) !== '' ? trim($location) : null;
    }

    /**
     * Resolves a `Location` value against the URL it was returned from.
     *
     * The header is allowed to be relative, and real servers use every form of
     * it. Returns null when the result would not be a usable absolute URL.
     */
    public static function absoluteUrl(string $base, string $location): ?string
    {
        $parts = parse_url($location);

        if ($parts === false) {
            return null;
        }

        // Already absolute.
        if (isset($parts['scheme'], $parts['host'])) {
            return $location;
        }

        $baseParts = parse_url($base);

        if (!\is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        // Protocol-relative: //host/path
        if (str_starts_with($location, '//')) {
            return $baseParts['scheme'] . ':' . $location;
        }

        $authority = $baseParts['scheme'] . '://' . $baseParts['host']
            . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');

        // Root-relative: /path
        if (str_starts_with($location, '/')) {
            return $authority . $location;
        }

        // Path-relative: resolved against the base URL's directory.
        $basePath  = $baseParts['path'] ?? '/';
        $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);

        if ($directory === '') {
            $directory = '/';
        }

        return $authority . $directory . $location;
    }

    /**
     * One request, one response — redirects are **not** followed here.
     *
     * @param array<string, string> $headers
     */
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
            // Off, and it must stay off: see the class docblock. The chain is
            // walked by request(), which vets each hop before it is contacted.
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_CONNECTTIMEOUT => $this->timeout,
            \CURLOPT_TIMEOUT        => $this->timeout,
            \CURLOPT_HTTPHEADER     => $headerLines,
            // Certificate validation, stated rather than inherited. These are
            // libcurl's defaults, but they are also the single most commonly
            // disabled pair in PHP code, so an invalid, expired, self-signed or
            // wrong-host certificate failing the request is written down here as
            // a decision instead of left to look like an oversight.
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
            \CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return \strlen($line);
            },
        ]);

        // Confine libcurl to the two schemes we ever mean to speak. Without this
        // a redirect to file:// or scp:// is something libcurl is perfectly happy
        // to attempt; the guard rejects those too, but a transport should not be
        // able to reach the local filesystem at all.
        if (\defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($ch, \CURLOPT_PROTOCOLS_STR, 'http,https');
        } else {
            curl_setopt($ch, \CURLOPT_PROTOCOLS, \CURLPROTO_HTTP | \CURLPROTO_HTTPS);
        }

        if ($body !== null) {
            curl_setopt($ch, \CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($ch);

        // No curl_close(): the handle is freed when it goes out of scope. The call has
        // been a no-op since PHP 8.0 and is deprecated as of 8.5.
        if ($result === false) {
            throw new HttpException('HTTP request failed: ' . curl_error($ch), curl_errno($ch));
        }

        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);

        return new HttpResponse($status, (string) $result, $responseHeaders);
    }

    /**
     * One request, one response — redirects are **not** followed here.
     *
     * @param array<string, string> $headers
     */
    private function requestStream(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $headerString = '';
        foreach ($headers as $name => $value) {
            $headerString .= $name . ': ' . $value . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method'          => strtoupper($method),
                'header'          => $headerString,
                'content'         => $body ?? '',
                'timeout'         => $this->timeout,
                'ignore_errors'   => true,
                // As with cURL above: request() vets each hop itself, so the
                // wrapper must hand the 3xx back rather than chase it.
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
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
