<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Joomla;

use Grafida\Http\HttpClient;
use Grafida\Http\HttpException;
use Grafida\Http\HttpResponse;
use Grafida\Http\Transport;

/**
 * Client for the Joomla 4/5 Web Services (REST) API.
 *
 * Talks JSON:API to a single site, identified by a bare site root URL and an
 * API token. The actual API entry point ("api base") is discovered once via
 * {@see self::probeApiBase()} and then reused for every call.
 *
 * Routes used (all relative to "{apiBase}/v1"):
 *   GET    content/articles            POST content/articles   PATCH content/articles/{id}
 *   GET    content/categories
 *   GET    tags                        POST tags
 *   GET    users/levels
 *   GET    fields/content/articles
 *   GET    media/files                 POST media/files
 */
final class ApiClient
{
    public function __construct(
        private readonly Transport $http = new HttpClient(),
    ) {}

    // ---------------------------------------------------------------------
    //  URL handling
    // ---------------------------------------------------------------------

    /**
     * Reduces any URL the user might paste (bare, or with an API suffix already
     * appended) to the bare site root, without a trailing slash.
     */
    public static function normaliseRoot(string $url): string
    {
        $url = trim($url);
        $url = preg_replace('#\?.*$#', '', $url) ?? $url;   // drop query string
        $url = rtrim($url, '/');

        // Strip any API suffix the user may have included, longest first.
        foreach (['/index.php/api', '/api/index.php', '/api', '/index.php'] as $suffix) {
            if (str_ends_with(strtolower($url), $suffix)) {
                $url = substr($url, 0, -\strlen($suffix));

                break;
            }
        }

        return rtrim($url, '/');
    }

    /**
     * Candidate API base URLs to probe, in preference order.
     *
     * "/index.php/api" works without server URL rewriting and is therefore the
     * most reliable, so it is tried first; the rewrite-only "/api" form is last.
     *
     * @return list<string>
     */
    public static function candidateBases(string $root): array
    {
        $root = self::normaliseRoot($root);

        return [
            $root . '/index.php/api',
            $root . '/api/index.php',
            $root . '/api',
        ];
    }

    // ---------------------------------------------------------------------
    //  Connection test / endpoint discovery
    // ---------------------------------------------------------------------

    /**
     * Probes the candidate API bases and returns the first one that responds as
     * a working Joomla API endpoint for the given token.
     *
     * @throws ApiException If a base is reachable but authentication/permissions
     *                      fail (so the user gets a useful message), or if no
     *                      candidate behaves like a Joomla API endpoint.
     */
    public function probeApiBase(string $root, string $token): string
    {
        $authError = null;

        foreach (self::candidateBases($root) as $base) {
            try {
                $response = $this->raw('GET', $base . '/v1/users/levels', $token);
            } catch (HttpException) {
                continue; // transport failure: try the next candidate
            }

            if ($response->isSuccess() && $this->looksLikeJsonApi($response)) {
                return $base;
            }

            // Reached a Joomla API endpoint, but the token/permission is wrong.
            if (($response->status === 401 || $response->status === 403) && $this->looksLikeJsonApi($response)) {
                $authError = new ApiException(
                    'The site was reached but the API token was rejected. Check the token and that the user has the "core.login.api" permission.',
                    $response->status,
                    $this->extractErrors($response)
                );
            }
        }

        if ($authError !== null) {
            throw $authError;
        }

        throw new ApiException('Could not find a working Joomla Web Services API endpoint at this URL.');
    }

    // ---------------------------------------------------------------------
    //  Resource helpers
    // ---------------------------------------------------------------------

    /**
     * @param array<string, scalar> $query
     *
     * @return list<array<string, mixed>>
     */
    public function listArticles(string $base, string $token, array $query = []): array
    {
        return $this->collection($base, $token, 'content/articles', $query);
    }

    /** @return array<string, mixed> */
    public function getArticle(string $base, string $token, int $id): array
    {
        return $this->resource($base, $token, 'content/articles/' . $id);
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed> The created article resource.
     */
    public function createArticle(string $base, string $token, array $attributes): array
    {
        $response = $this->send($base, $token, 'POST', 'content/articles', 'articles', $attributes);

        return $this->unwrapResource($response);
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed> The updated article resource.
     */
    public function updateArticle(string $base, string $token, int $id, array $attributes): array
    {
        $response = $this->send($base, $token, 'PATCH', 'content/articles/' . $id, 'articles', $attributes);

        return $this->unwrapResource($response);
    }

    /** @return list<array<string, mixed>> */
    public function listCategories(string $base, string $token): array
    {
        return $this->collection($base, $token, 'content/categories', ['filter[extension]' => 'com_content', 'page[limit]' => 0]);
    }

    /** @return list<array<string, mixed>> */
    public function listTags(string $base, string $token): array
    {
        return $this->collection($base, $token, 'tags', ['page[limit]' => 0]);
    }

    /** @return array<string, mixed> The created tag resource (includes its new id). */
    public function createTag(string $base, string $token, string $title): array
    {
        $response = $this->send($base, $token, 'POST', 'tags', 'tags', [
            'title'     => $title,
            'published' => 1,
            'language'  => '*',
        ]);

        return $this->unwrapResource($response);
    }

    /** @return list<array<string, mixed>> */
    public function listAccessLevels(string $base, string $token): array
    {
        return $this->collection($base, $token, 'users/levels', ['page[limit]' => 0]);
    }

    /**
     * Lists the site's installed content languages (the `#__languages` table),
     * each with `lang_code`, `title`, `title_native` and `published`. These are
     * the languages an article can be assigned to.
     *
     * @return list<array<string, mixed>>
     */
    public function listContentLanguages(string $base, string $token): array
    {
        return $this->collection($base, $token, 'languages/content', ['page[limit]' => 0]);
    }

    /** @return list<array<string, mixed>> */
    public function listArticleFields(string $base, string $token): array
    {
        return $this->collection($base, $token, 'fields/content/articles', ['page[limit]' => 0]);
    }

    /**
     * Uploads a file to the Media Manager.
     *
     * @param string $path        Destination path under the adapter, e.g. "images/grafida/photo.jpg".
     * @param string $rawContents Raw (not base64) file contents.
     *
     * @return array<string, mixed> The created media resource (its `url` is the public URL).
     */
    public function uploadMedia(string $base, string $token, string $path, string $rawContents): array
    {
        $response = $this->send($base, $token, 'POST', 'media/files', 'media', [
            'path'    => $path,
            'content' => base64_encode($rawContents),
        ]);

        return $this->unwrapResource($response);
    }

    // ---------------------------------------------------------------------
    //  Low-level request plumbing
    // ---------------------------------------------------------------------

    /**
     * Sends a write request whose attributes are wrapped in a JSON:API document.
     *
     * @param array<string, mixed> $attributes
     */
    private function send(string $base, string $token, string $method, string $route, string $type, array $attributes): HttpResponse
    {
        $documentEncoded = json_encode([
            'data' => [
                'type'       => $type,
                'attributes' => $attributes,
            ],
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $document = $documentEncoded !== false ? $documentEncoded : '{}';

        $response = $this->raw($method, $base . '/v1/' . $route, $token, $document);

        $this->assertSuccess($response);

        return $response;
    }

    /**
     * Fetches a collection resource and returns the flattened list of items
     * (id merged into attributes for convenience).
     *
     * @param array<string, scalar> $query
     *
     * @return list<array<string, mixed>>
     */
    private function collection(string $base, string $token, string $route, array $query = []): array
    {
        $url = $base . '/v1/' . $route;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $response = $this->raw('GET', $url, $token);
        $this->assertSuccess($response);

        $data = $response->json()['data'] ?? [];
        $out  = [];

        foreach (is_array($data) ? $data : [] as $item) {
            if (is_array($item)) {
                $out[] = $this->flatten($item);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function resource(string $base, string $token, string $route): array
    {
        $response = $this->raw('GET', $base . '/v1/' . $route, $token);
        $this->assertSuccess($response);

        return $this->unwrapResource($response);
    }

    /** @return array<string, mixed> */
    private function unwrapResource(HttpResponse $response): array
    {
        $data = $response->json()['data'] ?? [];

        return $this->flatten(is_array($data) ? $data : []);
    }

    /**
     * Flattens a JSON:API resource object into a plain associative array,
     * exposing `id` alongside the attribute values.
     *
     * @param array<int|string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function flatten(array $resource): array
    {
        $attributes = $resource['attributes'] ?? [];
        $raw        = is_array($attributes) ? $attributes : [];

        // Rebuild with string keys — JSON object keys are always strings.
        $flat = [];
        foreach ($raw as $k => $v) {
            $flat[(string) $k] = $v;
        }

        if (isset($resource['id'])) {
            $id         = $resource['id'];
            $flat['id'] = is_numeric($id) ? (int) $id : (is_string($id) ? $id : null);
        }

        return $flat;
    }

    /**
     * Performs a raw request with the standard Joomla API headers.
     */
    private function raw(string $method, string $url, string $token, ?string $body = null): HttpResponse
    {
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'X-Joomla-Token' => $token,
            'Accept'        => 'application/vnd.api+json',
        ];

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->http->request($method, $url, $headers, $body);
    }

    private function assertSuccess(HttpResponse $response): void
    {
        if ($response->isSuccess()) {
            return;
        }

        $errors = $this->extractErrors($response);

        throw new ApiException(
            $errors === []
                ? sprintf('The Joomla API returned HTTP %d.', $response->status)
                : implode('; ', $errors),
            $response->status,
            $errors
        );
    }

    /** @return list<string> */
    private function extractErrors(HttpResponse $response): array
    {
        $errors = $response->json()['errors'] ?? [];
        $out    = [];

        foreach (is_array($errors) ? $errors : [] as $error) {
            if (!is_array($error)) {
                continue;
            }

            $title  = isset($error['title']) && is_string($error['title']) ? $error['title'] : '';
            $detail = isset($error['detail']) && is_string($error['detail']) ? ': ' . $error['detail'] : '';
            $out[]  = trim($title . $detail, ': ');
        }

        return array_values(array_filter($out, static fn (string $s): bool => $s !== ''));
    }

    private function looksLikeJsonApi(HttpResponse $response): bool
    {
        $type = $response->header('content-type') ?? '';

        if (str_contains($type, 'json')) {
            return true;
        }

        $decoded = json_decode($response->body, true);

        return is_array($decoded) && (isset($decoded['data']) || isset($decoded['errors']));
    }
}
