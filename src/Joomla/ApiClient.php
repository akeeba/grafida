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
 *   GET    config/application
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

    /**
     * Lists articles as a single page, returning both the flattened items and the
     * pagination metadata Joomla emits (`meta['total-pages']`). Use this — rather
     * than {@see listArticles()} — when paginating a browsable list, so the whole
     * collection is never fetched at once.
     *
     * @param array<string, scalar> $query
     *
     * @return array{items: list<array<string, mixed>>, totalPages: int}
     */
    public function listArticlesPage(string $base, string $token, array $query = []): array
    {
        $url = $base . '/v1/content/articles';
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $response = $this->raw('GET', $url, $token);
        $this->assertSuccess($response);

        $json = $response->json();
        $data = $json['data'] ?? [];
        $out  = [];

        foreach (is_array($data) ? $data : [] as $item) {
            if (is_array($item)) {
                $out[] = $this->flatten($item);
            }
        }

        $meta       = is_array($json['meta'] ?? null) ? $json['meta'] : [];
        $totalPages = isset($meta['total-pages']) && is_numeric($meta['total-pages'])
            ? (int) $meta['total-pages']
            : 1;

        return ['items' => $out, 'totalPages' => max(1, $totalPages)];
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
     * Lists the site's template styles, each with `template` (the template's
     * directory name), `title` and `home` — where `home = "1"` marks the site's
     * default style and `home = "<lang tag>"` a language's home on a
     * multilingual site.
     *
     * This is the only way to learn the name of a **child** template: Joomla
     * resolves a child's assets against its parent whenever the child does not
     * override them, so a child that only ships an `editor.css` (which the
     * front-end never loads) leaves no trace on a rendered page for
     * {@see \Grafida\Reference\TemplateDiscovery} to scan.
     *
     * The route is served by `plg_webservices_templates` (enabled out of the
     * box) and needs `core.manage` on com_templates. Joomla only mints API
     * tokens for Super Users by default, so that holds for a token we can use —
     * but a site that widened the token plugin's allowed groups may still answer
     * 403, so callers must treat a thrown {@see ApiException} as "unknown"
     * rather than as a failure.
     *
     * @return list<array<string, mixed>>
     */
    public function listTemplateStyles(string $base, string $token): array
    {
        return $this->collection($base, $token, 'templates/styles/site', ['page[limit]' => 0]);
    }

    /**
     * Reads one key of the site's Global Configuration.
     *
     * `GET v1/config/application` serves `configuration.php` — including the
     * database password and the site secret — so this deliberately returns a
     * single named value rather than the whole map: nothing we do not ask for
     * can end up cached on disk. The route also needs `core.admin`, which an
     * article author will not have, so a caller must treat a thrown
     * {@see ApiException} (403) as "unknown", not as a failure.
     *
     * The view paginates (defaulting to 20 items) and reads the `offset` and
     * `limit` page variables without defaulting them individually, so both are
     * always sent — and `limit = 0` would divide by zero server-side, unlike
     * every other collection route here. Each item is a one-key object, hence
     * the scan.
     */
    public function getConfigValue(string $base, string $token, string $key): mixed
    {
        $items = $this->collection($base, $token, 'config/application', [
            'page[offset]' => 0,
            'page[limit]'  => 500,
        ]);

        foreach ($items as $item) {
            if (array_key_exists($key, $item)) {
                return $item[$key];
            }
        }

        return null;
    }

    /**
     * Uploads a file to the Media Manager.
     *
     * @param string $path        Destination path under the adapter, e.g. "images/grafida/photo.jpg".
     * @param string $rawContents Raw (not base64) file contents.
     * @param bool   $override    Overwrite an existing file at the same path.
     *
     * @return array<string, mixed> The created media resource (its `url` is the public URL).
     */
    public function uploadMedia(string $base, string $token, string $path, string $rawContents, bool $override = false): array
    {
        // Media responses don't follow the same single-resource contract as
        // articles/tags across Joomla versions; skip the resource assertion and
        // rely on the `url` (with its own fallback in PublishService::uploadBlob).
        $response = $this->send($base, $token, 'POST', 'media/files', 'media', [
            'path'     => $path,
            'content'  => base64_encode($rawContents),
            'override' => $override,
        ], false);

        return $this->unwrapResource($response);
    }

    /**
     * Creates a new folder in the Media Manager.
     *
     * A POST to `media/files` with a `path` but no `content` is treated by
     * com_media as a folder-create (see its ApiModel::save()).
     *
     * @param string $path Adapter path of the new folder, e.g. "local-images:/grafida/new".
     */
    public function createMediaFolder(string $base, string $token, string $path): void
    {
        $this->send($base, $token, 'POST', 'media/files', 'media', ['path' => $path], false);
    }

    /**
     * Renames or moves a Media Manager file/folder.
     *
     * com_media's edit task reads the *existing* path from the URL segment and the
     * *new* path from the body; with no `content` it performs a move/rename. We pass
     * `override:false` so a rename never silently clobbers an existing target.
     */
    public function renameMedia(string $base, string $token, string $oldPath, string $newPath): void
    {
        $encoded  = json_encode(['path' => $newPath, 'override' => false], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $document = $encoded !== false ? $encoded : '{}';

        $response = $this->raw('PATCH', $this->mediaItemUrl($base, $oldPath), $token, $document);
        $this->assertSuccess($response);
    }

    /**
     * Overwrites the contents of an existing Media Manager file (used to save an
     * edited image). Sending the same `path` plus `content` makes com_media run a
     * no-op move (override:true) followed by an in-place content update.
     *
     * @param string $rawContents Raw (not base64) file contents.
     */
    public function updateMediaContent(string $base, string $token, string $path, string $rawContents): void
    {
        $encoded  = json_encode(
            ['path' => $path, 'content' => base64_encode($rawContents), 'override' => true],
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        $document = $encoded !== false ? $encoded : '{}';

        $response = $this->raw('PATCH', $this->mediaItemUrl($base, $path), $token, $document);
        $this->assertSuccess($response);
    }

    /** Deletes a Media Manager file or folder. */
    public function deleteMedia(string $base, string $token, string $path): void
    {
        $response = $this->raw('DELETE', $this->mediaItemUrl($base, $path), $token);
        $this->assertSuccess($response);
    }

    /**
     * Fetches a single media file including its base64 `content`, so the SPA can
     * load the bytes for in-app image editing without tainting a canvas with a
     * cross-origin image.
     *
     * @return array<string, mixed> Flattened resource: `content` (base64), `mime_type`, `name`, …
     */
    public function getMediaFile(string $base, string $token, string $path): array
    {
        $response = $this->raw('GET', $this->mediaItemUrl($base, $path) . '?url=1&content=1', $token);
        $this->assertSuccess($response);

        return $this->unwrapResource($response);
    }

    /**
     * Lists the configured Media Manager adapters (filesystems). The first is the
     * default; each carries a `path` like "local-images:/" used to browse its root.
     *
     * @return list<array<string, mixed>>
     */
    public function listMediaAdapters(string $base, string $token): array
    {
        return $this->collection($base, $token, 'media/adapters');
    }

    /**
     * Builds the URL for a single media item. The path goes into the route segment
     * (`v1/media/files/:path`, whose route pattern is `.*`), so '/' and ':' must stay
     * literal for the route to match; everything else is percent-encoded.
     */
    private function mediaItemUrl(string $base, string $path): string
    {
        $encoded = str_replace(['%2F', '%3A'], ['/', ':'], rawurlencode($path));

        return $base . '/v1/media/files/' . $encoded;
    }

    /**
     * Lists the contents of a Media Manager folder.
     *
     * @param string $path Adapter path to browse, e.g. "local-images:/" for the
     *                     images root, or "" to list the default adapter root.
     *
     * @return list<array<string, mixed>> Folder and file entries (each carries a
     *                                    `type` of "dir"/"file"; files include `url`).
     */
    public function listMedia(string $base, string $token, string $path = ''): array
    {
        $query = ['url' => 1];

        if ($path !== '') {
            $query['path'] = $path;
        }

        return $this->collection($base, $token, 'media/files', $query);
    }

    // ---------------------------------------------------------------------
    //  Low-level request plumbing
    // ---------------------------------------------------------------------

    /**
     * Sends a write request. Joomla's Web Services API uses the JSON:API
     * `{data:{type,attributes}}` envelope only for *responses*; *write* requests
     * (create/update) take a flat top-level JSON object of field values — wrapping
     * them in `data`/`attributes` makes Joomla find no recognised fields and bind
     * nothing (a silent no-op on PATCH, returning the unchanged resource). The
     * record id for an update comes from the URL, not the body.
     *
     * `$type` is only the expected JSON:API type of the *response* resource, used
     * to confirm the write actually returned the saved item (see assertWroteResource).
     *
     * @param array<string, mixed> $attributes
     */
    private function send(string $base, string $token, string $method, string $route, string $type, array $attributes, bool $expectResource = true): HttpResponse
    {
        $documentEncoded = json_encode($attributes, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $document        = $documentEncoded !== false ? $documentEncoded : '{}';

        $response = $this->raw($method, $base . '/v1/' . $route, $token, $document);

        $this->assertSuccess($response);

        if ($expectResource) {
            $this->assertWroteResource($response, $type);
        }

        return $response;
    }

    /**
     * A 2xx status only means the request was *accepted*, not that our write was
     * *applied*. A redirect that strips the body (a proxy or a server rewrite
     * turning a POST/PATCH into a GET) yields a perfectly valid 200 whose payload
     * is a *collection* (for a create that landed on the list endpoint) or some
     * unrelated document — never the single resource a write returns. Assert the
     * response is the saved resource of the expected `type` carrying an `id`, so a
     * silent no-op surfaces as an error instead of a false "published" success.
     */
    private function assertWroteResource(HttpResponse $response, string $type): void
    {
        $data = $response->json()['data'] ?? null;

        $isSingleResource = is_array($data) && $data !== [] && !array_is_list($data);
        $idValue          = $isSingleResource ? ($data['id'] ?? null) : null;
        $hasId            = (is_int($idValue) || is_string($idValue)) && (string) $idValue !== '';
        $typeMatches      = $isSingleResource
            && (!isset($data['type']) || (is_string($data['type']) && $data['type'] === $type));

        if ($isSingleResource && $hasId && $typeMatches) {
            return;
        }

        throw new ApiException(
            'The server returned a success status but not the saved resource — the request was likely '
            . 'redirected and its body dropped (e.g. an http→https or trailing-slash rewrite). The change '
            . 'was not applied.',
            $response->status
        );
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
     * exposing `id` alongside the attribute values. The raw `relationships`
     * object is preserved untouched: Joomla exposes an article's category and
     * tags only as relationships (never as attributes), so consumers that need
     * those linkages read them from here.
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

        if (isset($resource['relationships']) && is_array($resource['relationships'])) {
            $flat['relationships'] = $resource['relationships'];
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
