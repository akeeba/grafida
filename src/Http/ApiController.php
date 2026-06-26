<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http;

use Boson\Contracts\Http\RequestInterface;
use Boson\Contracts\Http\ResponseInterface;
use Grafida\Article\Draft;
use Grafida\Article\DraftRepository;
use Grafida\Display\DisplayModeService;
use Grafida\Field\FieldSupport;
use Grafida\I18n\LanguageService;
use Grafida\Joomla\ApiClient;
use Grafida\Joomla\ApiException;
use Grafida\Markdown\MarkdownService;
use Grafida\Media\MediaRepository;
use Grafida\Publish\PublishBlockedException;
use Grafida\Publish\PublishService;
use Grafida\Reference\EditorCssService;
use Grafida\Reference\ReferenceService;
use Grafida\Site\FaviconService;
use Grafida\Site\SecureStoreUnavailableException;
use Grafida\Site\Site;
use Grafida\Site\SiteService;
use Grafida\Storage\StorageService;
use Grafida\Support\App;
use Grafida\Support\UrlOpener;

/**
 * Routes and handles the application's internal JSON API (the front-end calls
 * these via fetch('boson://app/api/...')).
 */
final class ApiController
{
    /** Interface strings sent to the front-end at start-up. */
    private const UI_KEYS = [
        'GRAFIDA_APP_TITLE', 'GRAFIDA_APP_SUBTITLE', 'GRAFIDA_NAV_SITES', 'GRAFIDA_NAV_ARTICLES', 'GRAFIDA_NAV_SETTINGS',
        'GRAFIDA_BTN_ADD_SITE', 'GRAFIDA_BTN_EDIT', 'GRAFIDA_BTN_SAVE', 'GRAFIDA_BTN_CANCEL',
        'GRAFIDA_BTN_DELETE', 'GRAFIDA_BTN_YES', 'GRAFIDA_BTN_NO',
        'GRAFIDA_BTN_PUBLISH', 'GRAFIDA_BTN_NEW_ARTICLE', 'GRAFIDA_BTN_IMPORT_MD',
        'GRAFIDA_BTN_TEST_CONNECTION', 'GRAFIDA_BTN_INSERT_READMORE', 'GRAFIDA_BTN_COPY_HTML',
        'GRAFIDA_BTN_SAVE_AND_BACK', 'GRAFIDA_BTN_KEEP_EDITING', 'GRAFIDA_BTN_DISCARD_CHANGES',
        'GRAFIDA_BTN_BACK', 'GRAFIDA_BTN_REFRESH', 'GRAFIDA_BTN_RELOAD_METADATA',
        'GRAFIDA_MSG_REFS_REFRESHED',
        'GRAFIDA_LBL_TITLE', 'GRAFIDA_LBL_URL', 'GRAFIDA_LBL_TOKEN', 'GRAFIDA_LBL_CATEGORY',
        'GRAFIDA_LBL_TAGS', 'GRAFIDA_LBL_ACCESS', 'GRAFIDA_LBL_LANGUAGE', 'GRAFIDA_LBL_STATUS',
        'GRAFIDA_LBL_SETTINGS', 'GRAFIDA_OPT_PUBLISHED', 'GRAFIDA_OPT_UNPUBLISHED',
        'GRAFIDA_OPT_ARCHIVED', 'GRAFIDA_OPT_TRASHED',
        'GRAFIDA_LBL_UI_LANGUAGE', 'GRAFIDA_OPT_AUTO',
        'GRAFIDA_LBL_DISPLAY_MODE', 'GRAFIDA_OPT_DISPLAY_AUTO',
        'GRAFIDA_OPT_DISPLAY_LIGHT', 'GRAFIDA_OPT_DISPLAY_DARK',
        'GRAFIDA_LBL_STORAGE', 'GRAFIDA_LBL_DB_LOCATION', 'GRAFIDA_BTN_OPEN_FOLDER',
        'GRAFIDA_LBL_RESET_STORAGE', 'GRAFIDA_MSG_RESET_STORAGE_DESC', 'GRAFIDA_BTN_RESET_STORAGE',
        'GRAFIDA_MSG_RESET_STORAGE_CONFIRM', 'GRAFIDA_MSG_RESET_STORAGE_DONE',
        'GRAFIDA_MSG_CONNECTION_OK',
        'GRAFIDA_MSG_CONNECTION_FAIL', 'GRAFIDA_MSG_INSECURE_WARNING', 'GRAFIDA_MSG_PUBLISH_OK',
        'GRAFIDA_MSG_PUBLISH_BLOCKED', 'GRAFIDA_MSG_NO_SITES', 'GRAFIDA_MSG_SAVED',
        'GRAFIDA_MSG_UNSAVED_TITLE', 'GRAFIDA_MSG_UNSAVED_CHANGES',
        'GRAFIDA_MSG_DELETE_DRAFT_TITLE', 'GRAFIDA_MSG_DELETE_DRAFT_CONFIRM',
        'GRAFIDA_MSG_DRAFT_DELETED', 'GRAFIDA_MSG_DELETE_SITE_CONFIRM',
        'GRAFIDA_LBL_ABOUT', 'GRAFIDA_BTN_ABOUT', 'GRAFIDA_LBL_VERSION', 'GRAFIDA_LBL_LICENSE',
        'GRAFIDA_ABOUT_VIEW_LICENSE', 'GRAFIDA_BTN_CLOSE',
        'GRAFIDA_LBL_SITE', 'GRAFIDA_MSG_CHANGE_SITE_TITLE', 'GRAFIDA_MSG_CHANGE_SITE_CONFIRM',
        'GRAFIDA_LBL_IMAGES', 'GRAFIDA_LBL_INTRO_IMAGE', 'GRAFIDA_LBL_FULLTEXT_IMAGE',
        'GRAFIDA_LBL_IMAGE_URL', 'GRAFIDA_LBL_IMAGE_ALT', 'GRAFIDA_LBL_IMAGE_CAPTION',
        'GRAFIDA_LBL_IMAGE_DECORATIVE', 'GRAFIDA_LBL_IMAGE_CLASS', 'GRAFIDA_LBL_MEDIA_BROWSER',
        'GRAFIDA_BTN_CHOOSE_FILE', 'GRAFIDA_BTN_BROWSE_MEDIA', 'GRAFIDA_BTN_CLEAR_IMAGE',
        'GRAFIDA_BTN_MEDIA_UP', 'GRAFIDA_LBL_SOURCE_CODE',
        'GRAFIDA_MSG_NO_IMAGE', 'GRAFIDA_MSG_MEDIA_EMPTY',
    ];

    public function __construct(
        private readonly SiteService $sites,
        private readonly FaviconService $favicons,
        private readonly ReferenceService $references,
        private readonly EditorCssService $editorCss,
        private readonly DraftRepository $drafts,
        private readonly MediaRepository $media,
        private readonly PublishService $publish,
        private readonly MarkdownService $markdown,
        private readonly LanguageService $language,
        private readonly DisplayModeService $displayMode,
        private readonly FieldSupport $fields,
        private readonly ApiClient $apiClient,
        private readonly StorageService $storage,
        private readonly UrlOpener $urlOpener,
    ) {}

    public function dispatch(RequestInterface $request): ResponseInterface
    {
        $method = strtoupper((string) $request->method);
        $path   = (string) $request->url->path;

        try {
            return $this->route($method, $path, $request);
        } catch (PublishBlockedException $e) {
            return Json::error($e->getMessage(), 422, [
                'code'        => 'publish_blocked',
                'fieldLabels' => $e->fieldLabels,
            ]);
        } catch (SecureStoreUnavailableException $e) {
            return Json::error($e->getMessage(), 409, ['code' => 'secure_store_unavailable']);
        } catch (ApiException $e) {
            return Json::error($e->getMessage(), 502, ['code' => 'joomla_api', 'status' => $e->status]);
        } catch (\Throwable $e) {
            return Json::error($e->getMessage(), 500, ['code' => 'internal']);
        }
    }

    private function route(string $method, string $path, RequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);

        return match (true) {
            $method === 'GET'  && $path === '/api/bootstrap'        => $this->bootstrap(),
            $method === 'POST' && $path === '/api/sites/test'       => $this->testConnection($body),
            $method === 'GET'  && $path === '/api/sites'            => $this->listSites(),
            $method === 'POST' && $path === '/api/sites'           => $this->createSite($body),
            $method === 'POST' && $path === '/api/markdown'        => $this->convertMarkdown($body),
            $method === 'POST' && $path === '/api/settings/language' => $this->setLanguage($body),
            $method === 'POST' && $path === '/api/settings/display-mode' => $this->setDisplayMode($body),
            $method === 'GET'  && $path === '/api/settings/storage'  => $this->storageInfo(),
            $method === 'POST' && $path === '/api/settings/storage/open'  => $this->openStorageFolder(),
            $method === 'POST' && $path === '/api/settings/storage/reset' => $this->resetStorage(),
            $method === 'POST' && $path === '/api/open-url'          => $this->openUrl($body),

            default => $this->parameterised($method, $path, $body, $request),
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function parameterised(string $method, string $path, array $body, RequestInterface $request): ResponseInterface
    {
        if (preg_match('#^/api/sites/(\d+)$#', $path, $m) === 1) {
            $id = (int) $m[1];

            return match ($method) {
                'PATCH'  => $this->updateSite($id, $body),
                'DELETE' => $this->deleteSite($id),
                default  => Json::error('Method not allowed', 405),
            };
        }

        if ($method === 'GET' && preg_match('#^/api/sites/(\d+)/references$#', $path, $m) === 1) {
            return $this->references((int) $m[1], false);
        }
        if ($method === 'POST' && preg_match('#^/api/sites/(\d+)/references/refresh$#', $path, $m) === 1) {
            return $this->references((int) $m[1], true);
        }
        if ($method === 'GET' && preg_match('#^/api/sites/(\d+)/editor-css$#', $path, $m) === 1) {
            return $this->editorCss((int) $m[1]);
        }
        if ($method === 'GET' && preg_match('#^/api/sites/(\d+)/articles$#', $path, $m) === 1) {
            return $this->remoteArticles((int) $m[1]);
        }
        if ($method === 'GET' && preg_match('#^/api/sites/(\d+)/articles/(\d+)$#', $path, $m) === 1) {
            return $this->remoteArticle((int) $m[1], (int) $m[2]);
        }
        if ($method === 'GET' && preg_match('#^/api/sites/(\d+)/drafts$#', $path, $m) === 1) {
            return $this->listDrafts((int) $m[1]);
        }
        if ($method === 'POST' && preg_match('#^/api/sites/(\d+)/drafts$#', $path, $m) === 1) {
            return $this->saveDraft((int) $m[1], null, $body);
        }
        if ($method === 'GET' && preg_match('#^/api/sites/(\d+)/media$#', $path, $m) === 1) {
            return $this->browseMedia((int) $m[1], $request->url->query->get('path', '') ?? '');
        }
        if ($method === 'POST' && preg_match('#^/api/sites/(\d+)/media$#', $path, $m) === 1) {
            return $this->uploadOfflineMedia((int) $m[1], $body);
        }
        if ($method === 'GET' && preg_match('#^/api/media/(\d+)$#', $path, $m) === 1) {
            return $this->mediaBlob((int) $m[1]);
        }
        if (preg_match('#^/api/drafts/(\d+)$#', $path, $m) === 1) {
            $id = (int) $m[1];

            return match ($method) {
                'GET'    => $this->getDraft($id),
                'PUT'    => $this->saveDraft(null, $id, $body),
                'DELETE' => $this->deleteDraft($id),
                default  => Json::error('Method not allowed', 405),
            };
        }
        if ($method === 'POST' && preg_match('#^/api/drafts/(\d+)/publish$#', $path, $m) === 1) {
            return $this->publishDraft((int) $m[1]);
        }

        return Json::error('Not found: ' . $path, 404);
    }

    // ------------------------------------------------------------------
    //  Handlers
    // ------------------------------------------------------------------

    private function bootstrap(): ResponseInterface
    {
        return Json::ok([
            'strings'             => $this->language->strings(self::UI_KEYS),
            'language'            => $this->language->currentTag(),
            'languageOverride'    => $this->language->override(),
            'availableLanguages'  => LanguageService::AVAILABLE,
            'displayMode'         => $this->displayMode->current(),
            'secureStore'         => $this->sites->hasSecureStore(),
            'supportedFieldTypes' => FieldSupport::SUPPORTED,
            'sites'               => array_map($this->siteArray(...), $this->sites->list()),
            'app'                 => App::info(),
        ]);
    }

    /** @param array<string, mixed> $body */
    private function openUrl(array $body): ResponseInterface
    {
        $this->urlOpener->open($this->str($body, 'url'));

        return Json::ok();
    }

    /** @param array<string, mixed> $body */
    private function testConnection(array $body): ResponseInterface
    {
        $apiBase = $this->sites->testConnection($this->str($body, 'url'), $this->str($body, 'token'));

        return Json::ok(['apiBase' => $apiBase]);
    }

    private function listSites(): ResponseInterface
    {
        return Json::ok(array_map($this->siteArray(...), $this->sites->list()));
    }

    /**
     * The public representation of a site sent to the SPA, augmented with its
     * cached favicon (a data: URI) when one has been downloaded.
     *
     * @return array<string, mixed>
     */
    private function siteArray(Site $site): array
    {
        return $site->toArray() + [
            'favicon' => $site->id !== null ? $this->favicons->dataUri($site->id) : null,
        ];
    }

    /** @param array<string, mixed> $body */
    private function createSite(array $body): ResponseInterface
    {
        $allowInsecureVal = $body['allowInsecure'] ?? false;
        $site = $this->sites->create(
            $this->str($body, 'title'),
            $this->str($body, 'url'),
            $this->str($body, 'token'),
            is_bool($allowInsecureVal) ? $allowInsecureVal : (bool) $allowInsecureVal,
        );

        // Warm the categories/tags/languages cache the moment a site is connected,
        // so the first article (new or existing) has its selectors populated.
        $this->references->sync($site);
        $this->favicons->sync($site);

        return Json::ok($this->siteArray($site), 201);
    }

    /** @param array<string, mixed> $body */
    private function updateSite(int $id, array $body): ResponseInterface
    {
        $tokenVal = $body['token'] ?? null;
        $token    = is_string($tokenVal) && $tokenVal !== '' ? $tokenVal : null;

        $allowInsecureVal = $body['allowInsecure'] ?? false;
        $site = $this->sites->update(
            $id,
            $this->str($body, 'title'),
            $this->str($body, 'url'),
            $token,
            is_bool($allowInsecureVal) ? $allowInsecureVal : (bool) $allowInsecureVal,
        );

        // Re-warm reference data in case the URL or token changed.
        $this->references->sync($site);
        $this->favicons->sync($site);

        return Json::ok($this->siteArray($site));
    }

    private function deleteSite(int $id): ResponseInterface
    {
        $this->sites->delete($id);

        return Json::ok();
    }

    private function references(int $siteId, bool $refresh): ResponseInterface
    {
        $site = $this->requireSite($siteId);

        // Opening the editor (refresh = false) is a best-effort attempt: a list
        // that cannot be fetched falls back to its cached copy with a short
        // timeout. The explicit "refresh" button (refresh = true) is strict so
        // the user sees why a refresh failed.
        $bestEffort = !$refresh;

        $fieldDefs = $this->references->fields($site, $refresh, $bestEffort);

        // The explicit refresh also re-downloads the favicon, so the SPA can
        // update the icon shown for the site without a full reload.
        if ($refresh && $site->id !== null) {
            $this->favicons->sync($site);
        }

        return Json::ok([
            'categories' => $this->references->categories($site, $refresh, $bestEffort),
            'tags'       => $this->references->tags($site, $refresh, $bestEffort),
            'levels'     => $this->references->accessLevels($site, $refresh, $bestEffort),
            'languages'  => $this->references->contentLanguages($site, $refresh, $bestEffort),
            'fields'     => $this->fields->partition($fieldDefs),
            'favicon'    => $refresh && $site->id !== null ? $this->favicons->dataUri($site->id) : null,
        ]);
    }

    private function editorCss(int $siteId): ResponseInterface
    {
        $site = $this->requireSite($siteId);

        return Json::ok(['css' => $this->editorCss->load($site)]);
    }

    private function remoteArticles(int $siteId): ResponseInterface
    {
        $site  = $this->requireSite($siteId);
        $token = $this->sites->tokenFor($site);

        if ($token === null || $site->apiBase === null) {
            return Json::error('The site is not connected.', 409);
        }

        $articles = $this->apiClient
            ->listArticles($site->apiBase, $token, ['page[limit]' => 50, 'list[ordering]' => 'modified', 'list[direction]' => 'desc']);

        return Json::ok($articles);
    }

    /**
     * Fetches a single remote article and returns it shaped as an unsaved draft
     * (id: null) ready for the editor — see {@see self::remoteArticleToDraft()}.
     */
    private function remoteArticle(int $siteId, int $articleId): ResponseInterface
    {
        $site  = $this->requireSite($siteId);
        $token = $this->sites->tokenFor($site);

        if ($token === null || $site->apiBase === null) {
            return Json::error('The site is not connected.', 409);
        }

        $article = $this->apiClient->getArticle($site->apiBase, $token, $articleId);

        return Json::ok($this->remoteArticleToDraft($siteId, $articleId, $article, $site));
    }

    /**
     * Maps a Joomla article resource into an unsaved draft payload. The body is
     * recovered as editor HTML by {@see remoteArticleBody()} (discrete
     * introtext/fulltext when the API exposes them, otherwise heuristically split
     * from the combined `text` attribute); the category and tags come from JSON:API
     * relationships, tag IDs being resolved to titles (best effort) via the
     * reference cache so editing then publishing does not drop them.
     *
     * @param array<string, mixed> $article
     *
     * @return array<string, mixed>
     */
    private function remoteArticleToDraft(int $siteId, int $articleId, array $article, Site $site): array
    {
        $html = $this->remoteArticleBody($article);

        $language = $this->str($article, 'language', '*');

        $catId = $this->firstRelationshipId($article, 'category');

        return [
            'id'       => null,
            'siteId'   => $siteId,
            'remoteId' => $articleId,
            'title'    => $this->str($article, 'title'),
            'alias'    => $this->str($article, 'alias'),
            'catid'    => $catId,
            'access'   => isset($article['access']) && is_numeric($article['access']) ? (int) $article['access'] : 1,
            'language' => $language !== '' ? $language : '*',
            'state'    => isset($article['state']) && is_numeric($article['state']) ? (int) $article['state'] : 1,
            'html'     => $html,
            'fields'   => [],
            'tags'     => $this->remoteTagTitles($article, $site),
            'images'   => $this->remoteImages($article),
            'metadesc' => $this->str($article, 'metadesc'),
            'metakey'  => $this->str($article, 'metakey'),
        ];
    }

    /**
     * Recovers an article's editor HTML from a Joomla article resource, restoring
     * the intro/full-text split as a read-more marker (`<hr class="readmore">`,
     * matching the editor and {@see \Grafida\Html\ContentSplitter}) so it survives
     * the round-trip back to publishing.
     *
     * Joomla's article API currently only returns the combined `text` attribute. A
     * pending Joomla PR would expose discrete `introtext` / `fulltext` attributes;
     * we prefer those if present so we pick up the improvement automatically. Until
     * then we fall back to a heuristic: Joomla joins the two parts with
     * "\r\n \r\n" (CRLF, space, CRLF) in the combined text, which is a reliable
     * separator in most articles. (The API does not preserve the read-more marker,
     * so an article whose body genuinely lacks that sequence is treated as
     * intro-only — the worst case is a missing split, never lost content.)
     *
     * @param array<string, mixed> $article
     */
    private function remoteArticleBody(array $article): string
    {
        if (array_key_exists('introtext', $article) || array_key_exists('fulltext', $article)) {
            $intro = $this->str($article, 'introtext');
            $full  = $this->str($article, 'fulltext');
        } else {
            $parts = explode("\r\n \r\n", $this->str($article, 'text'), 2);
            $intro = $parts[0];
            $full  = $parts[1] ?? '';
        }

        if (trim($full) === '') {
            return trim($intro);
        }

        return trim($intro) . "\n<hr class=\"readmore\">\n" . trim($full);
    }

    /**
     * Resolves a remote article's tags (IDs or tag objects) to titles, which is
     * how a draft stores them. Tags the local reference cache does not know are
     * skipped rather than guessed.
     *
     * @param array<string, mixed> $article
     *
     * @return list<string>
     */
    private function remoteTagTitles(array $article, Site $site): array
    {
        // Joomla exposes an article's tags as a to-many relationship, not an
        // attribute, so the IDs come from `relationships.tags.data[].id`.
        $tagIds = $this->relationshipIds($article, 'tags');

        if ($tagIds === []) {
            return [];
        }

        $titleById = [];
        foreach ($this->references->tags($site) as $tag) {
            $id    = $tag['id'] ?? null;
            $title = $tag['title'] ?? null;
            if ((is_int($id) || is_string($id)) && is_string($title)) {
                $titleById[(int) $id] = $title;
            }
        }

        $out = [];
        foreach ($tagIds as $id) {
            if (isset($titleById[$id])) {
                $out[] = $titleById[$id];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Returns the resource IDs of a JSON:API to-many relationship (or the
     * single ID of a to-one relationship as a one-element list), as preserved
     * by ApiClient's flatten() under the `relationships` key.
     *
     * @param array<string, mixed> $resource
     *
     * @return list<int>
     */
    private function relationshipIds(array $resource, string $name): array
    {
        $relationships = $resource['relationships'] ?? null;

        if (!is_array($relationships) || !isset($relationships[$name]) || !is_array($relationships[$name])) {
            return [];
        }

        $data = $relationships[$name]['data'] ?? null;

        if (!is_array($data)) {
            return [];
        }

        // A to-one relationship's `data` is a single resource-identifier object;
        // a to-many's is a list of them. Normalise both to a list of IDs.
        $entries = isset($data['id']) ? [$data] : $data;
        $ids     = [];

        foreach ($entries as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Returns the ID of a to-one JSON:API relationship (e.g. an article's
     * category), or null when it is absent.
     *
     * @param array<string, mixed> $resource
     */
    private function firstRelationshipId(array $resource, string $name): ?int
    {
        return $this->relationshipIds($resource, $name)[0] ?? null;
    }

    /**
     * Normalises a remote article's `images` attribute (a JSON string or object)
     * to a plain array the draft can store and republish verbatim.
     *
     * @param array<string, mixed> $article
     *
     * @return array<string, mixed>
     */
    private function remoteImages(array $article): array
    {
        $images = $article['images'] ?? null;

        if (is_string($images) && $images !== '') {
            $decoded = json_decode($images, true);
            $images  = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($images)) {
            return [];
        }

        // JSON object keys are strings; rebuild to satisfy the declared shape.
        $out = [];
        foreach ($images as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    private function listDrafts(int $siteId): ResponseInterface
    {
        return Json::ok(array_map(
            static fn (Draft $d): array => $d->toArray(),
            $this->drafts->forSite($siteId)
        ));
    }

    private function getDraft(int $id): ResponseInterface
    {
        $draft = $this->drafts->find($id);

        return $draft === null ? Json::error('Draft not found', 404) : Json::ok($draft->toArray());
    }

    /** @param array<string, mixed> $body */
    private function saveDraft(?int $siteId, ?int $draftId, array $body): ResponseInterface
    {
        if ($draftId !== null) {
            $existing = $this->drafts->find($draftId);

            if ($existing === null) {
                return Json::error('Draft not found', 404);
            }

            // A draft may be re-pointed at another site (which unlinks it from any
            // remote article); honour the client's siteId, falling back to its own.
            $siteId = $this->int($body, 'siteId', $existing->siteId);
        }

        $remoteIdVal = $body['remoteId'] ?? null;
        $catidVal    = $body['catid'] ?? null;
        $fieldsRaw   = $body['fields'] ?? null;
        $tagsRaw     = $body['tags'] ?? null;
        $imagesRaw   = $body['images'] ?? null;

        /** @var array<string, mixed> $fields */
        $fields = is_array($fieldsRaw) ? $fieldsRaw : [];
        /** @var array<string, mixed> $images */
        $images = is_array($imagesRaw) ? $imagesRaw : [];
        /** @var list<string> $tags */
        $tags   = is_array($tagsRaw)
            ? array_values(array_filter(array_map(static fn (mixed $t): mixed => is_string($t) ? $t : null, $tagsRaw), static fn (mixed $t): bool => $t !== null))
            : [];

        $draft = new Draft(
            id: $draftId,
            siteId: $siteId ?? $this->int($body, 'siteId'),
            remoteId: is_numeric($remoteIdVal) ? (int) $remoteIdVal : null,
            title: $this->str($body, 'title'),
            alias: $this->str($body, 'alias'),
            catid: is_numeric($catidVal) ? (int) $catidVal : null,
            access: $this->int($body, 'access', 1),
            language: $this->str($body, 'language', '*'),
            state: $this->int($body, 'state', 1),
            html: $this->str($body, 'html'),
            fields: $fields,
            tags: $tags,
            images: $images,
            metadesc: $this->str($body, 'metadesc'),
            metakey: $this->str($body, 'metakey'),
        );

        if ($draftId === null) {
            $newId = $this->drafts->insert($draft);
            $saved = $this->drafts->find($newId);
        } else {
            $this->drafts->update($draft);
            $saved = $this->drafts->find($draftId);
        }

        return Json::ok($saved?->toArray());
    }

    private function deleteDraft(int $id): ResponseInterface
    {
        $this->drafts->delete($id);

        return Json::ok();
    }

    private function publishDraft(int $id): ResponseInterface
    {
        $draft = $this->drafts->find($id);

        if ($draft === null) {
            return Json::error('Draft not found', 404);
        }

        $site   = $this->requireSite($draft->siteId);
        $result = $this->publish->publish($draft, $site);

        return Json::ok($result);
    }

    /**
     * Lists a folder of the site's Media Manager so the editor can pick an
     * existing image for the intro / full-text article image.
     */
    private function browseMedia(int $siteId, string $path): ResponseInterface
    {
        $site  = $this->requireSite($siteId);
        $token = $this->sites->tokenFor($site);

        if ($token === null || $site->apiBase === null) {
            return Json::error('The site is not connected.', 409);
        }

        $entries = $this->apiClient->listMedia($site->apiBase, $token, $path);

        return Json::ok(['path' => $path, 'entries' => $entries]);
    }

    /** Returns the data: URI of a stored offline image blob (for editor previews). */
    private function mediaBlob(int $id): ResponseInterface
    {
        $dataUri = $this->media->dataUri($id);

        if ($dataUri === null) {
            return Json::error('Media not found', 404);
        }

        return Json::ok(['id' => $id, 'dataUri' => $dataUri]);
    }

    /** @param array<string, mixed> $body */
    private function uploadOfflineMedia(int $siteId, array $body): ResponseInterface
    {
        $raw = base64_decode($this->str($body, 'dataBase64'), true);

        if ($raw === false) {
            return Json::error('Invalid image data', 400);
        }

        $draftIdVal = $body['draftId'] ?? null;
        $mediaId    = $this->media->store(
            $siteId,
            is_numeric($draftIdVal) ? (int) $draftIdVal : null,
            $this->str($body, 'filename', 'image.png'),
            $this->str($body, 'mime', 'image/png'),
            $raw,
        );

        return Json::ok([
            'id'      => $mediaId,
            'dataUri' => $this->media->dataUri($mediaId),
        ], 201);
    }

    /** @param array<string, mixed> $body */
    private function convertMarkdown(array $body): ResponseInterface
    {
        return Json::ok(['html' => $this->markdown->toHtml($this->str($body, 'markdown'))]);
    }

    /** @param array<string, mixed> $body */
    private function setLanguage(array $body): ResponseInterface
    {
        $this->language->setOverride($this->str($body, 'tag', LanguageService::AUTO));

        return Json::ok([
            'language' => $this->language->currentTag(),
            'strings'  => $this->language->strings(self::UI_KEYS),
        ]);
    }

    /** @param array<string, mixed> $body */
    private function setDisplayMode(array $body): ResponseInterface
    {
        $mode = $this->displayMode->set($this->str($body, 'mode', DisplayModeService::AUTO));

        return Json::ok(['displayMode' => $mode]);
    }

    private function storageInfo(): ResponseInterface
    {
        return Json::ok($this->storage->info());
    }

    private function openStorageFolder(): ResponseInterface
    {
        $this->storage->openContainingFolder();

        return Json::ok();
    }

    private function resetStorage(): ResponseInterface
    {
        $this->storage->reset();

        return Json::ok();
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /**
     * Safely reads a string value from a mixed-typed body map.
     *
     * @param array<string, mixed> $body
     */
    private function str(array $body, string $key, string $default = ''): string
    {
        $value = $body[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * Safely reads an int value from a mixed-typed body map.
     *
     * @param array<string, mixed> $body
     */
    private function int(array $body, string $key, int $default = 0): int
    {
        $value = $body[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    private function requireSite(int $id): Site
    {
        $site = $this->sites->find($id);

        if ($site === null) {
            throw new \RuntimeException('Unknown site #' . $id);
        }

        return $site;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(RequestInterface $request): array
    {
        $raw = $request->body;

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
