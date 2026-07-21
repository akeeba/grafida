<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http\Controller;

use Boson\Component\Http\Response;
use Boson\Contracts\Http\ResponseInterface;
use Grafida\Http\Json;
use Grafida\Http\RouteContext;
use Grafida\Http\Router;
use Grafida\Http\SiteContext;
use Grafida\Joomla\ApiClient;
use Grafida\Media\ImageInfo;
use Grafida\Media\LocalMediaUrl;
use Grafida\Media\MediaRepository;
use Grafida\Media\SiteImageException;
use Grafida\Media\SiteImageFetcher;

/**
 * Handles `/api/sites/{id}/media*` (the online Media Manager screen),
 * `GET /api/media/{id}` (a data: URI preview of an offline draft image
 * blob), `GET /api/media/{id}/raw` (the same blob's raw bytes — what an
 * article's `<img src>` actually points at, gh-36) and the Local Media tab's
 * CRUD over `media_blobs` (`/api/sites/{id}/local-media`, `/api/media/{id}
 * /rename`, `/api/media/{id}/content`, `DELETE /api/media/{id}`,
 * `/api/media/{id}/save-to-disk`).
 */
final class MediaController extends Controller
{
    /**
     * The mime types `mediaBlobRaw()` will actually echo back with a
     * matching `Content-Type`. A stored blob's mime comes from our own
     * upload/paste handlers, so it is not attacker-controlled — but this
     * allowlist means a malformed or hand-edited row can never make the
     * webview treat a blob's bytes as, say, `text/html`; anything outside it
     * falls back to `application/octet-stream`, which the browser will not
     * execute or render as markup.
     *
     * @var list<string>
     */
    private const array ALLOWED_RAW_MIME_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/svg+xml',
    ];

    public function __construct(
        private readonly SiteContext $siteContext,
        private readonly ApiClient $apiClient,
        private readonly MediaRepository $media,
        private readonly SiteImageFetcher $siteImages,
    ) {}

    public function registerRoutes(Router $router): void
    {
        $router->add('GET', '/api/sites/{id}/media', fn (RouteContext $ctx): ResponseInterface => $this->browseMedia($ctx->int('id'), $ctx->request()->url->query->get('path', '') ?? ''));
        $router->add('POST', '/api/sites/{id}/media', fn (RouteContext $ctx): ResponseInterface => $this->uploadOfflineMedia($ctx->int('id'), $ctx->body()));
        $router->add('DELETE', '/api/sites/{id}/media', fn (RouteContext $ctx): ResponseInterface => $this->deleteSiteMedia($ctx->int('id'), $ctx->request()->url->query->get('path', '') ?? ''));
        $router->add('GET', '/api/sites/{id}/media/adapters', fn (RouteContext $ctx): ResponseInterface => $this->mediaAdapters($ctx->int('id')));
        $router->add('GET', '/api/sites/{id}/media/file', fn (RouteContext $ctx): ResponseInterface => $this->siteMediaFile($ctx->int('id'), $ctx->request()->url->query->get('path', '') ?? ''));
        $router->add('POST', '/api/sites/{id}/media/files', fn (RouteContext $ctx): ResponseInterface => $this->uploadSiteMedia($ctx->int('id'), $ctx->body()));
        $router->add('POST', '/api/sites/{id}/media/folder', fn (RouteContext $ctx): ResponseInterface => $this->createSiteMediaFolder($ctx->int('id'), $ctx->body()));
        $router->add('POST', '/api/sites/{id}/media/rename', fn (RouteContext $ctx): ResponseInterface => $this->renameSiteMedia($ctx->int('id'), $ctx->body()));
        $router->add('POST', '/api/sites/{id}/media/content', fn (RouteContext $ctx): ResponseInterface => $this->updateSiteMediaContent($ctx->int('id'), $ctx->body()));
        $router->add('GET', '/api/media/{id}', fn (RouteContext $ctx): ResponseInterface => $this->mediaBlob($ctx->int('id')));
        $router->add('GET', '/api/sites/{id}/image', fn (RouteContext $ctx): ResponseInterface => $this->siteImage($ctx->int('id'), $ctx->request()->url->query->get('url', '') ?? ''));
        // {id} placeholders compile to an anchored `\d+` regex (Router::compile()), so
        // `/api/media/{id}/raw` is a distinct pattern from `/api/media/{id}` above and
        // cannot be swallowed by it — the router tries every registered pattern in turn.
        $router->add('GET', '/api/media/{id}/raw', fn (RouteContext $ctx): ResponseInterface => $this->mediaBlobRaw($ctx->int('id')));
        $router->add('DELETE', '/api/media/{id}', fn (RouteContext $ctx): ResponseInterface => $this->deleteLocalMedia($ctx->int('id')));
        $router->add('POST', '/api/media/{id}/rename', fn (RouteContext $ctx): ResponseInterface => $this->renameLocalMedia($ctx->int('id'), $ctx->body()));
        $router->add('POST', '/api/media/{id}/content', fn (RouteContext $ctx): ResponseInterface => $this->updateLocalMediaContent($ctx->int('id'), $ctx->body()));
        $router->add('GET', '/api/sites/{id}/local-media', fn (RouteContext $ctx): ResponseInterface => $this->listLocalMedia($ctx->int('id')));
        $router->add('POST', '/api/media/{id}/save-to-disk', fn (RouteContext $ctx): ResponseInterface => $this->saveLocalMediaToDisk($ctx->int('id'), $ctx->body()));
    }

    /**
     * Fetches an article image published on the site and returns it as a data: URI.
     *
     * Used when handing an article to a multimodal model: an already-published
     * article references its images by URL, and the webview cannot fetch those
     * itself (CORS / macOS ATS), so the bytes come through PHP.
     *
     * Unlike the rest of this controller this needs no API token — the image is
     * public — so it only requires the site to exist, not to be connected.
     */
    public function siteImage(int $siteId, string $url): ResponseInterface
    {
        $site = $this->siteContext->requireSite($siteId);

        try {
            return Json::ok($this->siteImages->fetch($site, $url));
        } catch (SiteImageException $e) {
            return Json::error($e->getMessage(), $e->httpStatus);
        }
    }

    /**
     * Lists a folder of the site's Media Manager so the editor can pick an
     * existing image for the intro / full-text article image.
     */
    public function browseMedia(int $siteId, string $path): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        [, $token, $base] = $conn;
        $entries = $this->apiClient->listMedia($base, $token, $path);

        return Json::ok(['path' => $path, 'entries' => $entries]);
    }

    /** Lists the site's Media Manager adapters (filesystems) for the Media Manager screen. */
    public function mediaAdapters(int $siteId): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        [, $token, $base] = $conn;

        return Json::ok(['adapters' => $this->apiClient->listMediaAdapters($base, $token)]);
    }

    /** Returns a media file's bytes as a data: URI so the SPA can load it for editing. */
    public function siteMediaFile(int $siteId, string $path): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }
        if ($path === '') {
            return Json::error('A media path is required.', 400);
        }

        [, $token, $base] = $conn;
        $file    = $this->apiClient->getMediaFile($base, $token, $path);
        $content = $this->str($file, 'content');
        $mime    = $this->str($file, 'mime_type', 'application/octet-stream');

        if ($content === '') {
            return Json::error('The media file has no readable content.', 404);
        }

        return Json::ok([
            'dataUri' => 'data:' . $mime . ';base64,' . $content,
            'mime'    => $mime,
            'name'    => $this->str($file, 'name'),
        ]);
    }

    /**
     * Uploads a file directly to the site's Media Manager (the online Media Manager
     * screen, distinct from uploadOfflineMedia which stores an offline draft blob).
     *
     * @param array<string, mixed> $body
     */
    public function uploadSiteMedia(int $siteId, array $body): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        $path = $this->str($body, 'path');
        $raw  = base64_decode($this->str($body, 'dataBase64'), true);

        if ($path === '') {
            return Json::error('A destination path is required.', 400);
        }
        if ($raw === false) {
            return Json::error('Invalid file data', 400);
        }

        $overrideVal = $body['override'] ?? false;
        [, $token, $base] = $conn;
        $resource = $this->apiClient->uploadMedia($base, $token, $path, $raw, (bool) $overrideVal);

        return Json::ok($resource, 201);
    }

    /** @param array<string, mixed> $body */
    public function createSiteMediaFolder(int $siteId, array $body): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        $path = $this->str($body, 'path');

        if ($path === '') {
            return Json::error('A folder path is required.', 400);
        }

        [, $token, $base] = $conn;
        $this->apiClient->createMediaFolder($base, $token, $path);

        return Json::ok(null, 201);
    }

    /**
     * Renames a Media Manager file/folder within its current directory. The new
     * path is derived from the old path so the item stays in the same folder.
     *
     * @param array<string, mixed> $body
     */
    public function renameSiteMedia(int $siteId, array $body): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        $oldPath = $this->str($body, 'oldPath');
        $newName = trim($this->str($body, 'newName'));

        if ($oldPath === '' || $newName === '') {
            return Json::error('The current path and a new name are required.', 400);
        }
        if (str_contains($newName, '/') || str_contains($newName, ':')) {
            return Json::error('A name cannot contain "/" or ":".', 400);
        }

        $idx     = strrpos($oldPath, '/');
        $dir     = $idx === false ? '' : substr($oldPath, 0, $idx);
        $newPath = $dir . '/' . $newName;

        [, $token, $base] = $conn;
        $this->apiClient->renameMedia($base, $token, $oldPath, $newPath);

        return Json::ok(['path' => $newPath]);
    }

    /** @param array<string, mixed> $body */
    public function updateSiteMediaContent(int $siteId, array $body): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        $path = $this->str($body, 'path');
        $raw  = base64_decode($this->str($body, 'dataBase64'), true);

        if ($path === '') {
            return Json::error('A media path is required.', 400);
        }
        if ($raw === false) {
            return Json::error('Invalid image data', 400);
        }

        [, $token, $base] = $conn;
        $this->apiClient->updateMediaContent($base, $token, $path, $raw);

        return Json::ok(['path' => $path]);
    }

    /** Deletes a file/folder from the site's Media Manager. */
    public function deleteSiteMedia(int $siteId, string $path): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }
        if ($path === '') {
            return Json::error('A media path is required.', 400);
        }

        [, $token, $base] = $conn;
        $this->apiClient->deleteMedia($base, $token, $path);

        return Json::ok();
    }

    /** Returns the data: URI of a stored offline image blob (for editor previews). */
    public function mediaBlob(int $id): ResponseInterface
    {
        $dataUri = $this->media->dataUri($id);

        if ($dataUri === null) {
            return Json::error('Media not found', 404);
        }

        return Json::ok(['id' => $id, 'dataUri' => $dataUri]);
    }

    /**
     * Stores a pasted/dropped/picked local image as an offline blob and
     * returns a **local URL**, not the `data:` URI this endpoint used to
     * answer with — the editor (`images_upload_handler`,
     * `uploadLocalImage()`) inserts that URL directly into the article HTML
     * instead of embedding the bytes, which is the whole point of gh-36: a
     * 2.3 MiB screenshot used to become ~3.1 MiB of base64 sitting in the
     * editor DOM, `State` and the `drafts.html` column. `width`/`height` are
     * returned alongside for callers that need the image's intrinsic size
     * without a round trip through the browser's own `<img>` decode — the
     * `media_blobs` row now carries them too (`MediaRepository::store()`),
     * computed here, once, from the same bytes being persisted.
     *
     * @param array<string, mixed> $body
     */
    public function uploadOfflineMedia(int $siteId, array $body): ResponseInterface
    {
        $raw = base64_decode($this->str($body, 'dataBase64'), true);

        if ($raw === false) {
            return Json::error('Invalid image data', 400);
        }

        [$width, $height] = ImageInfo::dimensions($raw);

        $draftIdVal = $body['draftId'] ?? null;
        $mediaId    = $this->media->store(
            $siteId,
            is_numeric($draftIdVal) ? (int) $draftIdVal : null,
            $this->str($body, 'filename', 'image.png'),
            $this->str($body, 'mime', 'image/png'),
            $raw,
            $width,
            $height,
        );

        $meta = $this->media->findMeta($mediaId);
        \assert($meta !== null);

        return Json::ok([
            'id'     => $mediaId,
            'url'    => LocalMediaUrl::build($mediaId, $meta['updated_at'] ?? $meta['created_at']),
            'width'  => $width,
            'height' => $height,
        ], 201);
    }

    /**
     * Serves a stored offline media blob's raw bytes — the load-bearing
     * endpoint of gh-36: `Html\InlineMedia`/the SPA's editor point an
     * article's `<img src>` at this URL (via {@see LocalMediaUrl::build()})
     * instead of embedding a `data:` URI, so a multi-megabyte pasted
     * screenshot no longer bloats the editor DOM/`State`/the `drafts.html`
     * column.
     *
     * Deliberately **not** `Json::ok()` — this must be a plain byte response,
     * not JSON, since it is what an `<img>` tag or a "Save image as" fetches.
     * A missing id still answers in JSON, though (`Json::error()`), so the
     * SPA's existing error handling keeps working for the cases where this
     * *is* called from `fetch()` (e.g. re-deriving dimensions).
     */
    public function mediaBlobRaw(int $id): ResponseInterface
    {
        $meta = $this->media->findMeta($id);

        if ($meta === null) {
            return Json::error('Media not found', 404);
        }

        $data = $this->media->data($id);

        if ($data === null) {
            return Json::error('Media not found', 404);
        }

        $mime = in_array($meta['mime'], self::ALLOWED_RAW_MIME_TYPES, true)
            ? $meta['mime']
            : 'application/octet-stream';

        return new Response($data, 200, [
            'Content-Type'        => $mime,
            'Content-Length'      => (string) strlen($data),
            'Content-Disposition' => 'inline; filename="' . addslashes($meta['filename']) . '"',
            'X-Content-Type-Options' => 'nosniff',
            // Same rationale as Http\Json::response(): the webview caches custom-scheme
            // GETs heuristically when a response says nothing about freshness, in a
            // disk-backed cache that survives an app restart *and* a local-storage
            // reset (gh-35) — and here the bytes genuinely can change in place (the
            // Local Media image editor's crop/resize/rotate/flip save), so a cached
            // response really would be wrong. The read itself is a local SQLite
            // lookup; there is nothing to gain by letting the cache serve it instead.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma'        => 'no-cache',
        ]);
    }

    /**
     * Lists every offline media blob belonging to a site for the Media
     * Manager screen's **Local Media** tab. Unlike the rest of this
     * controller this needs no site connection — `media_blobs` is entirely
     * local storage, nothing here talks to the Joomla REST API.
     */
    public function listLocalMedia(int $siteId): ResponseInterface
    {
        $entries = array_map(
            static function (array $row): array {
                $revisedAt = $row['updated_at'] ?? $row['created_at'];

                return [
                    'id'         => $row['id'],
                    'filename'   => $row['filename'],
                    'mime'       => $row['mime'],
                    'width'      => $row['width'],
                    'height'     => $row['height'],
                    'size'       => $row['size'],
                    'createdAt'  => $row['created_at'],
                    'updatedAt'  => $row['updated_at'],
                    'draftId'    => $row['draft_id'],
                    'draftTitle' => $row['draft_title'],
                    // Whether these bytes already exist on the site (a previous
                    // publish uploaded them) — the Local Media tab's "published"
                    // badge, see MediaRepository::listForSite()'s doc comment.
                    'remoteUrl'  => $row['remote_url'],
                    'url'        => LocalMediaUrl::build($row['id'], $revisedAt),
                ];
            },
            $this->media->listForSite($siteId),
        );

        return Json::ok(['entries' => $entries]);
    }

    /**
     * Renames a local media blob (the Local Media tab's Rename action).
     *
     * The extension is always re-derived from the blob's *actual* stored
     * mime (mirroring `renameSiteMedia()`'s "/" / ":" guard for the online
     * Media Manager), so a rename can only change what the file is called,
     * never what the webview believes it is.
     *
     * @param array<string, mixed> $body
     */
    public function renameLocalMedia(int $id, array $body): ResponseInterface
    {
        $meta = $this->media->findMeta($id);

        if ($meta === null) {
            return Json::error('Media not found', 404);
        }

        $requested = trim($this->str($body, 'filename'));

        if ($requested === '') {
            return Json::error('A filename is required.', 400);
        }
        if (str_contains($requested, '/') || str_contains($requested, '\\') || str_contains($requested, ':')) {
            return Json::error('A filename cannot contain "/", "\\" or ":".', 400);
        }

        $withoutExtension = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $requested);
        $base             = $withoutExtension !== null && $withoutExtension !== '' ? $withoutExtension : 'image';
        $filename         = $base . '.' . ImageInfo::extensionForMime($meta['mime']);

        $this->media->rename($id, $filename);

        $updated = $this->media->findMeta($id);
        \assert($updated !== null);

        return Json::ok([
            'id'       => $id,
            'filename' => $filename,
            'url'      => LocalMediaUrl::build($id, $updated['updated_at'] ?? $updated['created_at']),
        ]);
    }

    /**
     * Replaces a local media blob's bytes — the Local Media tab's in-app
     * image editor (crop/resize/rotate/flip) save path, and also reachable
     * once an already-inserted local-media image is re-edited from within
     * the article editor. Dimensions are re-derived from the new bytes
     * rather than trusted from the caller.
     *
     * @param array<string, mixed> $body
     */
    public function updateLocalMediaContent(int $id, array $body): ResponseInterface
    {
        $meta = $this->media->findMeta($id);

        if ($meta === null) {
            return Json::error('Media not found', 404);
        }

        $raw = base64_decode($this->str($body, 'dataBase64'), true);

        if ($raw === false) {
            return Json::error('Invalid image data', 400);
        }

        $mime              = $this->str($body, 'mime', $meta['mime']);
        [$width, $height]  = ImageInfo::dimensions($raw);

        $this->media->replaceData($id, $raw, $mime, $width, $height);

        $updated = $this->media->findMeta($id);
        \assert($updated !== null);

        return Json::ok([
            'id'     => $id,
            'url'    => LocalMediaUrl::build($id, $updated['updated_at'] ?? $updated['created_at']),
            'width'  => $updated['width'],
            'height' => $updated['height'],
        ]);
    }

    /**
     * Deletes a local media blob outright (the Local Media tab's Delete
     * action). It is legitimate to delete a blob still referenced by a
     * draft's HTML — the UI warns before doing so, but the reference itself
     * is left alone here and simply renders as a broken image afterwards,
     * which is honest about what happened; scrubbing the reference out of
     * the draft's stored HTML is not this endpoint's job.
     */
    public function deleteLocalMedia(int $id): ResponseInterface
    {
        $meta = $this->media->findMeta($id);

        if ($meta === null) {
            return Json::error('Media not found', 404);
        }

        $this->media->delete($id);

        return Json::ok();
    }

    /**
     * Writes a local media blob's bytes to a file in the given directory —
     * the Local Media tab's "Save to disk" action. Boson has no Save-As
     * dialog (`DialogApiInterface` only offers open-file/open-directory
     * pickers), so the SPA asks for a destination **folder**
     * (`POST /api/dialog/select-directory`, same as `.grafida` export and
     * the Request Log export) and the file is written server-side under the
     * blob's own stored filename.
     *
     * @param array<string, mixed> $body
     */
    public function saveLocalMediaToDisk(int $id, array $body): ResponseInterface
    {
        $directory = $this->str($body, 'directory');

        if ($directory === '' || !is_dir($directory)) {
            return Json::error('A valid destination folder is required.', 400);
        }

        $meta = $this->media->findMeta($id);

        if ($meta === null) {
            return Json::error('Media not found', 404);
        }

        $data = $this->media->data($id);

        if ($data === null) {
            return Json::error('Media not found', 404);
        }

        $path = rtrim($directory, '/\\') . \DIRECTORY_SEPARATOR . $meta['filename'];

        if (@file_put_contents($path, $data) === false) {
            return Json::error('Could not write the file', 500);
        }

        return Json::ok(['path' => $path]);
    }
}
