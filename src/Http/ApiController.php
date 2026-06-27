<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http;

use Boson\Api\Dialog\DialogApiInterface;
use Boson\Contracts\Http\RequestInterface;
use Boson\Contracts\Http\ResponseInterface;
use Grafida\Ai\AiProxyException;
use Grafida\Ai\AiServiceManager;
use Grafida\Ai\AiTool;
use Grafida\Ai\AiToolRepository;
use Grafida\Ai\Defaults;
use Grafida\Ai\AiProxy;
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
use Grafida\Storage\SettingsRepository;
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
        'GRAFIDA_BTN_DELETE_DRAFT', 'GRAFIDA_BTN_KEEP_DRAFT',
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
        'GRAFIDA_MSG_PUBLISH_BLOCKED', 'GRAFIDA_MSG_POST_PUBLISH_TITLE',
        'GRAFIDA_MSG_POST_PUBLISH_PROMPT', 'GRAFIDA_MSG_NO_SITES', 'GRAFIDA_MSG_SAVED',
        'GRAFIDA_MSG_UNSAVED_TITLE', 'GRAFIDA_MSG_UNSAVED_CHANGES',
        'GRAFIDA_MSG_DELETE_DRAFT_TITLE', 'GRAFIDA_MSG_DELETE_DRAFT_CONFIRM',
        'GRAFIDA_MSG_DRAFT_DELETED', 'GRAFIDA_MSG_DELETE_SITE_CONFIRM',
        'GRAFIDA_LBL_ABOUT', 'GRAFIDA_BTN_ABOUT', 'GRAFIDA_LBL_VERSION', 'GRAFIDA_LBL_LICENSE',
        'GRAFIDA_ABOUT_VIEW_LICENSE', 'GRAFIDA_BTN_CLOSE',
        'GRAFIDA_LBL_SITE', 'GRAFIDA_MSG_CHANGE_SITE_TITLE', 'GRAFIDA_MSG_CHANGE_SITE_CONFIRM',
        'GRAFIDA_LBL_IMAGES', 'GRAFIDA_LBL_INTRO_IMAGE', 'GRAFIDA_LBL_FULLTEXT_IMAGE',
        'GRAFIDA_LBL_IMAGE_URL', 'GRAFIDA_LBL_IMAGE_ALT', 'GRAFIDA_LBL_IMAGE_CAPTION',
        'GRAFIDA_LBL_IMAGE_DECORATIVE', 'GRAFIDA_LBL_IMAGE_CLASS', 'GRAFIDA_BTN_IMAGE_CLASS',
        'GRAFIDA_LBL_MEDIA_BROWSER',
        'GRAFIDA_BTN_CHOOSE_FILE', 'GRAFIDA_BTN_BROWSE_MEDIA', 'GRAFIDA_BTN_CLEAR_IMAGE',
        'GRAFIDA_BTN_MEDIA_UP', 'GRAFIDA_LBL_SOURCE_CODE', 'GRAFIDA_LBL_STYLES',
        'GRAFIDA_MSG_NO_IMAGE', 'GRAFIDA_MSG_MEDIA_EMPTY',
        'GRAFIDA_LBL_LOCAL_DRAFTS', 'GRAFIDA_LBL_REMOTE_ARTICLES', 'GRAFIDA_LBL_HAS_LOCAL_DRAFT',
        'GRAFIDA_MSG_SELECT_SITE', 'GRAFIDA_MSG_LOADING', 'GRAFIDA_MSG_NO_REMOTE_ARTICLES',
        'GRAFIDA_MSG_NO_DRAFTS',
        'GRAFIDA_PLACEHOLDER_SEARCH', 'GRAFIDA_LBL_SORT_BY', 'GRAFIDA_LBL_DIRECTION',
        'GRAFIDA_LBL_PER_PAGE', 'GRAFIDA_SORT_DIR_ASC', 'GRAFIDA_SORT_DIR_DESC',
        'GRAFIDA_SORT_ID', 'GRAFIDA_SORT_TITLE', 'GRAFIDA_SORT_CATEGORY', 'GRAFIDA_SORT_ACCESS',
        'GRAFIDA_SORT_AUTHOR', 'GRAFIDA_SORT_LANGUAGE', 'GRAFIDA_SORT_CREATED', 'GRAFIDA_SORT_MODIFIED',
        'GRAFIDA_SORT_PUBLISH_UP', 'GRAFIDA_SORT_PUBLISH_DOWN', 'GRAFIDA_SORT_HITS',
        'GRAFIDA_SORT_FEATURED', 'GRAFIDA_SORT_STATUS', 'GRAFIDA_SORT_ORDERING',
        'GRAFIDA_FILTER_CATEGORY_ANY', 'GRAFIDA_FILTER_TAG_ANY', 'GRAFIDA_FILTER_LANGUAGE_ANY',
        'GRAFIDA_FILTER_STATE_ANY', 'GRAFIDA_FILTER_FEATURED_ANY', 'GRAFIDA_FILTER_FEATURED_YES',
        'GRAFIDA_FILTER_FEATURED_NO', 'GRAFIDA_FILTER_CHECKEDOUT_ANY', 'GRAFIDA_FILTER_CHECKEDOUT_YES',
        'GRAFIDA_FILTER_CHECKEDOUT_NO', 'GRAFIDA_OPT_LANG_ALL', 'GRAFIDA_BTN_CLEAR_FILTERS',
        'GRAFIDA_BTN_PREV_PAGE', 'GRAFIDA_BTN_NEXT_PAGE', 'GRAFIDA_PAGINATION_INFO',
        'GRAFIDA_NAV_MEDIA', 'GRAFIDA_BTN_UPLOAD', 'GRAFIDA_BTN_NEW_FOLDER', 'GRAFIDA_BTN_RENAME',
        'GRAFIDA_BTN_EDIT_IMAGE', 'GRAFIDA_BTN_DOWNLOAD', 'GRAFIDA_BTN_OPEN',
        'GRAFIDA_LBL_MEDIA', 'GRAFIDA_LBL_FOLDER', 'GRAFIDA_LBL_FILE',
        'GRAFIDA_LBL_NEW_FOLDER', 'GRAFIDA_LBL_FOLDER_NAME', 'GRAFIDA_LBL_RENAME', 'GRAFIDA_LBL_NEW_NAME',
        'GRAFIDA_MSG_MEDIA_UPLOADED', 'GRAFIDA_MSG_MEDIA_FOLDER_CREATED', 'GRAFIDA_MSG_MEDIA_RENAMED',
        'GRAFIDA_MSG_MEDIA_DELETED', 'GRAFIDA_MSG_MEDIA_SAVED',
        'GRAFIDA_MSG_DELETE_MEDIA_TITLE', 'GRAFIDA_MSG_DELETE_MEDIA_CONFIRM',
        'GRAFIDA_MSG_DELETE_FOLDER_CONFIRM',
        'GRAFIDA_MSG_MEDIA_OVERWRITE_TITLE', 'GRAFIDA_MSG_MEDIA_OVERWRITE_CONFIRM',
        'GRAFIDA_MSG_MEDIA_EDIT_LOAD_FAIL', 'GRAFIDA_MSG_MEDIA_ONLINE_ONLY',
        'GRAFIDA_LBL_IMAGE_EDITOR', 'GRAFIDA_BTN_ROTATE_LEFT', 'GRAFIDA_BTN_ROTATE_RIGHT',
        'GRAFIDA_BTN_FLIP_H', 'GRAFIDA_BTN_FLIP_V', 'GRAFIDA_BTN_CROP', 'GRAFIDA_BTN_APPLY_CROP',
        'GRAFIDA_BTN_CANCEL_CROP', 'GRAFIDA_BTN_RESIZE', 'GRAFIDA_BTN_RESET',
        'GRAFIDA_LBL_WIDTH', 'GRAFIDA_LBL_HEIGHT', 'GRAFIDA_LBL_LOCK_ASPECT', 'GRAFIDA_LBL_DIMENSIONS',
        'GRAFIDA_MSG_CROP_HINT', 'GRAFIDA_LBL_MEDIA_PREVIEW',
        'GRAFIDA_NAV_AI',
        'GRAFIDA_LBL_AI_SERVICES', 'GRAFIDA_BTN_ADD_AI_SERVICE',
        'GRAFIDA_LBL_AI_PROVIDER', 'GRAFIDA_LBL_AI_ENDPOINT',
        'GRAFIDA_LBL_AI_MODEL', 'GRAFIDA_LBL_AI_KEY', 'GRAFIDA_LBL_AI_PARAMS',
        'GRAFIDA_LBL_DEFAULT_AI_SERVICE', 'GRAFIDA_BTN_SET_DEFAULT',
        'GRAFIDA_MSG_NO_AI_SERVICES',
        'GRAFIDA_MSG_DELETE_AI_SERVICE_CONFIRM',
        'GRAFIDA_MSG_AI_SERVICE_SAVED', 'GRAFIDA_MSG_AI_SERVICE_DELETED',
        'GRAFIDA_MSG_AI_KEY_PLACEHOLDER', 'GRAFIDA_MSG_AI_INSECURE_WARNING',
        'GRAFIDA_LBL_AI_TOOLS', 'GRAFIDA_BTN_ADD_AI_TOOL',
        'GRAFIDA_LBL_AI_TOOL_KEY', 'GRAFIDA_LBL_AI_TOOL_PROMPT',
        'GRAFIDA_LBL_AI_TONE', 'GRAFIDA_LBL_AI_OVERRIDE_SYSTEM',
        'GRAFIDA_LBL_AI_SORT_ORDER', 'GRAFIDA_LBL_AI_SYSTEM_PROMPT',
        'GRAFIDA_BTN_RESTORE_DEFAULT',
        'GRAFIDA_MSG_NO_AI_TOOLS',
        'GRAFIDA_MSG_DELETE_AI_TOOL_CONFIRM',
        'GRAFIDA_MSG_AI_TOOL_SAVED', 'GRAFIDA_MSG_AI_TOOL_DELETED',
        'GRAFIDA_MSG_AI_SYSTEM_PROMPT_SAVED',
        'GRAFIDA_MSG_AI_HOST_MISMATCH',
        'GRAFIDA_LBL_AI_NAME', 'GRAFIDA_LBL_AI_TEMPERATURE', 'GRAFIDA_LBL_AI_TOP_P',
        'GRAFIDA_LBL_AI_MAX_TOKENS', 'GRAFIDA_LBL_AI_STREAM', 'GRAFIDA_BTN_FETCH_MODELS',
        'GRAFIDA_LBL_AI_TOOL_ICON', 'GRAFIDA_LBL_AI_SERVICE_OVERRIDE',
        'GRAFIDA_OPT_AI_DEFAULT_SERVICE', 'GRAFIDA_OPT_AI_TONE_DEFAULT',
        'GRAFIDA_MSG_AI_DEFAULT_SET', 'GRAFIDA_MSG_AI_MODELS_FAIL', 'GRAFIDA_LBL_AI_CUSTOM_TOOL',
        // AI chat panel (Step 7)
        'GRAFIDA_LBL_AI_CHATS',
        'GRAFIDA_BTN_AI_ASSISTANT', 'GRAFIDA_BTN_AI_TOOLS',
        'GRAFIDA_BTN_SEND', 'GRAFIDA_BTN_STOP',
        'GRAFIDA_BTN_AI_INSERT', 'GRAFIDA_BTN_COPY',
        'GRAFIDA_PLACEHOLDER_AI_CHAT',
        'GRAFIDA_MSG_AI_NO_SERVICE', 'GRAFIDA_MSG_AI_EMPTY',
        'GRAFIDA_MSG_AI_COPIED', 'GRAFIDA_MSG_AI_COPY_FAIL',
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
        private readonly AiServiceManager $aiServices,
        private readonly Defaults $aiDefaults,
        private readonly AiToolRepository $aiTools,
        private readonly SettingsRepository $settings,
        private readonly AiProxy $aiProxy,
        private readonly ?DialogApiInterface $dialog = null,
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
            $method === 'GET'  && $path === '/api/settings/system-theme' => $this->systemTheme(),
            $method === 'GET'  && $path === '/api/settings/storage'  => $this->storageInfo(),
            $method === 'POST' && $path === '/api/settings/storage/open'  => $this->openStorageFolder(),
            $method === 'POST' && $path === '/api/settings/storage/reset' => $this->resetStorage(),
            $method === 'POST' && $path === '/api/open-url'          => $this->openUrl($body),
            $method === 'POST' && $path === '/api/dialog/open-file'  => $this->openFile($body),
            $method === 'GET'  && $path === '/api/ai/services'       => $this->listAiServices(),
            $method === 'POST' && $path === '/api/ai/services'       => $this->createAiService($body),
            $method === 'GET'  && $path === '/api/ai/tools'          => $this->listAiTools(),
            $method === 'PUT'  && $path === '/api/ai/system-prompt'  => $this->setSystemPrompt($body),
            $method === 'POST' && $path === '/api/ai/tools'          => $this->createAiTool($body),
            $method === 'POST' && $path === '/api/ai/proxy'          => $this->aiProxy($body),

            default => $this->parameterised($method, $path, $body, $request),
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function parameterised(string $method, string $path, array $body, RequestInterface $request): ResponseInterface
    {
        if (preg_match('#^/api/ai/tools/([A-Za-z0-9_\-]+)$#', $path, $m) === 1) {
            $key = $m[1];

            return match ($method) {
                'PATCH'  => $this->updateAiTool($key, $body),
                'DELETE' => $this->deleteAiTool($key),
                default  => Json::error('Method not allowed', 405),
            };
        }

        if ($method === 'GET' && preg_match('#^/api/ai/services/(\d+)/resolved$#', $path, $m) === 1) {
            return $this->resolvedAiService((int) $m[1], $request->url->query->get('tool') ?? '');
        }

        if (preg_match('#^/api/ai/services/(\d+)/default$#', $path, $m) === 1) {
            if ($method !== 'POST') {
                return Json::error('Method not allowed', 405);
            }

            return $this->setAiServiceDefault((int) $m[1]);
        }

        if (preg_match('#^/api/ai/services/(\d+)$#', $path, $m) === 1) {
            $id = (int) $m[1];

            return match ($method) {
                'GET'    => $this->getAiService($id),
                'PATCH'  => $this->updateAiService($id, $body),
                'DELETE' => $this->deleteAiService($id),
                default  => Json::error('Method not allowed', 405),
            };
        }

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
            return $this->remoteArticles((int) $m[1], $request);
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
        if (preg_match('#^/api/sites/(\d+)/media$#', $path, $m) === 1) {
            $id = (int) $m[1];

            return match ($method) {
                'GET'    => $this->browseMedia($id, $request->url->query->get('path', '') ?? ''),
                'POST'   => $this->uploadOfflineMedia($id, $body),
                'DELETE' => $this->deleteSiteMedia($id, $request->url->query->get('path', '') ?? ''),
                default  => Json::error('Method not allowed', 405),
            };
        }
        if ($method === 'GET' && preg_match('#^/api/sites/(\d+)/media/adapters$#', $path, $m) === 1) {
            return $this->mediaAdapters((int) $m[1]);
        }
        if ($method === 'GET' && preg_match('#^/api/sites/(\d+)/media/file$#', $path, $m) === 1) {
            return $this->siteMediaFile((int) $m[1], $request->url->query->get('path', '') ?? '');
        }
        if ($method === 'POST' && preg_match('#^/api/sites/(\d+)/media/files$#', $path, $m) === 1) {
            return $this->uploadSiteMedia((int) $m[1], $body);
        }
        if ($method === 'POST' && preg_match('#^/api/sites/(\d+)/media/folder$#', $path, $m) === 1) {
            return $this->createSiteMediaFolder((int) $m[1], $body);
        }
        if ($method === 'POST' && preg_match('#^/api/sites/(\d+)/media/rename$#', $path, $m) === 1) {
            return $this->renameSiteMedia((int) $m[1], $body);
        }
        if ($method === 'POST' && preg_match('#^/api/sites/(\d+)/media/content$#', $path, $m) === 1) {
            return $this->updateSiteMediaContent((int) $m[1], $body);
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
        $aiServiceList  = $this->aiServices->list();
        $aiDefault      = $this->aiServices->default();

        // Only enabled tools, sorted, with each tool's resolved serviceId.
        $allTools     = $this->aiDefaults->effectiveTools($this->aiTools);
        $enabledTools = array_values(array_filter($allTools, static fn (array $t): bool => $t['enabled']));

        return Json::ok([
            'strings'             => $this->language->strings(self::UI_KEYS),
            'language'            => $this->language->currentTag(),
            'languageOverride'    => $this->language->override(),
            'availableLanguages'  => $this->language->available(),
            'displayMode'         => $this->displayMode->current(),
            'systemPrefersDark'   => $this->displayMode->systemPrefersDark(),
            'secureStore'         => $this->sites->hasSecureStore(),
            'supportedFieldTypes' => FieldSupport::SUPPORTED,
            'sites'               => array_map($this->siteArray(...), $this->sites->list()),
            'app'                 => App::info(),
            'aiServices'          => array_map(static fn ($s) => $s->toArray(), $aiServiceList),
            'aiDefaultServiceId'  => $aiDefault?->id,
            'aiProviders'         => $this->aiDefaults->providers(),
            'secureStoreAi'       => $this->aiServices->hasSecureStore(),
            'aiTools'             => $enabledTools,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function openUrl(array $body): ResponseInterface
    {
        $this->urlOpener->open($this->str($body, 'url'));

        return Json::ok();
    }

    /**
     * Open a native OS file-picker and return the chosen file's bytes.
     *
     * Boson's webview (WKWebView on macOS, WebKitGTK on Linux) does not wire up
     * the HTML `<input type="file">` open-panel callback, so an in-page file
     * input never opens a dialog. We instead drive the OS picker through Boson's
     * native Dialog API and hand the file back to the SPA as base64, which then
     * feeds it into the normal media-upload / Markdown-import flow.
     *
     * @param array<string, mixed> $body
     */
    private function openFile(array $body): ResponseInterface
    {
        if ($this->dialog === null) {
            return Json::error('Native file dialog is unavailable', 503);
        }

        $filter = match ($this->str($body, 'filter', 'any')) {
            'image'    => ['*.png', '*.jpg', '*.jpeg', '*.gif', '*.webp', '*.svg', '*.bmp', '*.avif'],
            'markdown' => ['*.md', '*.markdown', '*.txt'],
            default    => [],
        };

        $path = $this->dialog->selectFile(filter: $filter);

        if ($path === null || $path === '') {
            return Json::ok(['cancelled' => true]);
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return Json::error('Could not read the selected file', 400);
        }

        return Json::ok([
            'name'       => basename($path),
            'mime'       => self::mimeForPath($path),
            'dataBase64' => base64_encode($raw),
        ]);
    }

    /** Best-effort MIME type from a file extension (fileinfo is not bundled). */
    private static function mimeForPath(string $path): string
    {
        return match (strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
            'png'           => 'image/png',
            'jpg', 'jpeg'   => 'image/jpeg',
            'gif'           => 'image/gif',
            'webp'          => 'image/webp',
            'svg'           => 'image/svg+xml',
            'bmp'           => 'image/bmp',
            'avif'          => 'image/avif',
            'md', 'markdown' => 'text/markdown',
            'txt'           => 'text/plain',
            default         => 'application/octet-stream',
        };
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

    // ------------------------------------------------------------------
    //  AI service handlers
    // ------------------------------------------------------------------

    private function listAiServices(): ResponseInterface
    {
        return Json::ok(array_map(
            static fn ($s) => $s->toArray(),
            $this->aiServices->list(),
        ));
    }

    private function getAiService(int $id): ResponseInterface
    {
        $service = $this->aiServices->find($id);

        if ($service === null) {
            return Json::error('AI service not found', 404);
        }

        return Json::ok($service->toArray());
    }

    /** @param array<string, mixed> $body */
    private function createAiService(array $body): ResponseInterface
    {
        $allowInsecureVal = $body['allowInsecure'] ?? false;

        $paramsRaw = $body['params'] ?? null;
        /** @var array<string, mixed> $params */
        $params = is_array($paramsRaw) ? $paramsRaw : [];

        $service = $this->aiServices->create([
            'name'          => $this->str($body, 'name'),
            'provider'      => $this->str($body, 'provider'),
            'endpoint'      => $this->str($body, 'endpoint'),
            'model'         => $this->str($body, 'model'),
            'key'           => $this->str($body, 'key'),
            'params'        => $params,
            'allowInsecure' => is_bool($allowInsecureVal) ? $allowInsecureVal : (bool) $allowInsecureVal,
        ]);

        return Json::ok($service->toArray(), 201);
    }

    /** @param array<string, mixed> $body */
    private function updateAiService(int $id, array $body): ResponseInterface
    {
        $existing = $this->aiServices->find($id);

        if ($existing === null) {
            return Json::error('AI service not found', 404);
        }

        $allowInsecureVal = $body['allowInsecure'] ?? false;

        $paramsRaw = $body['params'] ?? null;
        /** @var array<string, mixed> $params */
        $params = is_array($paramsRaw) ? $paramsRaw : $existing->params;

        $data = [
            'params'        => $params,
            'allowInsecure' => is_bool($allowInsecureVal) ? $allowInsecureVal : (bool) $allowInsecureVal,
        ];

        // Only include fields that are explicitly provided in the body.
        if (array_key_exists('name', $body)) {
            $data['name'] = $this->str($body, 'name');
        }
        if (array_key_exists('provider', $body)) {
            $data['provider'] = $this->str($body, 'provider');
        }
        if (array_key_exists('endpoint', $body)) {
            $data['endpoint'] = $this->str($body, 'endpoint');
        }
        if (array_key_exists('model', $body)) {
            $data['model'] = $this->str($body, 'model');
        }

        // Only re-store the key when a non-empty value is supplied.
        $keyVal = $body['key'] ?? null;
        if (is_string($keyVal) && $keyVal !== '') {
            $data['key'] = $keyVal;
        }

        $service = $this->aiServices->update($id, $data);

        return Json::ok($service->toArray());
    }

    private function deleteAiService(int $id): ResponseInterface
    {
        $service = $this->aiServices->find($id);

        if ($service === null) {
            return Json::error('AI service not found', 404);
        }

        $this->aiServices->delete($id);

        return Json::ok();
    }

    private function setAiServiceDefault(int $id): ResponseInterface
    {
        $service = $this->aiServices->find($id);

        if ($service === null) {
            return Json::error('AI service not found', 404);
        }

        $this->aiServices->setDefault($id);

        $updated = $this->aiServices->find($id);

        return Json::ok($updated?->toArray());
    }

    // ------------------------------------------------------------------
    //  AI tool / system-prompt / proxy / resolved-config handlers
    // ------------------------------------------------------------------

    /**
     * Returns the full effective tool list, the current system-prompt (override or
     * bundled default), and all available tones.
     */
    private function listAiTools(): ResponseInterface
    {
        $systemPromptOverride = $this->settings->get('ai_system_prompt');
        $systemPrompt         = ($systemPromptOverride !== null && $systemPromptOverride !== '')
            ? $systemPromptOverride
            : $this->aiDefaults->systemPrompt();

        return Json::ok([
            'tools'        => $this->aiDefaults->effectiveTools($this->aiTools),
            'systemPrompt' => $systemPrompt,
            'tones'        => $this->aiDefaults->tones(),
        ]);
    }

    /**
     * Stores or clears a system-prompt override.
     *
     * An empty/omitted `prompt` key restores the bundled default (the stored
     * override is cleared so the setting is transparent on next read).
     *
     * @param array<string, mixed> $body
     */
    private function setSystemPrompt(array $body): ResponseInterface
    {
        $prompt = $this->str($body, 'prompt');

        if ($prompt === '') {
            // Restore default: store empty string so subsequent reads fall back.
            $this->settings->set('ai_system_prompt', '');
        } else {
            $this->settings->set('ai_system_prompt', $prompt);
        }

        return Json::ok(['systemPrompt' => $prompt !== '' ? $prompt : $this->aiDefaults->systemPrompt()]);
    }

    /**
     * Updates (upserts) a built-in tool's override. The request body may carry
     * any subset of: prompt, params, tone, serviceId, enabled, sortOrder, title, icon.
     *
     * @param array<string, mixed> $body
     */
    private function updateAiTool(string $key, array $body): ResponseInterface
    {
        // A key that begins with nothing can't be overridden if no bundled tool
        // exists — but we allow it for future flexibility. The override is always
        // is_custom = false (PATCH is for built-ins only; POST creates custom tools).
        $existing = $this->aiTools->findByKey($key);

        $paramsRaw = $body['params'] ?? null;
        /** @var array<string, mixed> $params */
        $params = is_array($paramsRaw) ? $paramsRaw : ($existing !== null ? $existing->params : []);

        $serviceIdRaw = $body['serviceId'] ?? null;
        $serviceId    = is_numeric($serviceIdRaw) ? (int) $serviceIdRaw : ($existing !== null ? $existing->serviceId : null);

        $enabledRaw = $body['enabled'] ?? null;
        $enabled    = $enabledRaw !== null ? (bool) $enabledRaw : ($existing !== null ? $existing->enabled : true);

        $sortOrderRaw = $body['sortOrder'] ?? null;
        $sortOrder    = is_numeric($sortOrderRaw) ? (int) $sortOrderRaw : ($existing !== null ? $existing->sortOrder : 0);

        $titleRaw = $body['title'] ?? null;
        $title    = is_string($titleRaw) ? $titleRaw : ($existing !== null ? $existing->title : $key);

        $iconRaw = $body['icon'] ?? null;
        $icon    = is_string($iconRaw) ? $iconRaw : ($existing !== null ? $existing->icon : '');

        $promptRaw = $body['prompt'] ?? null;
        $prompt    = is_string($promptRaw) ? $promptRaw : ($existing !== null ? $existing->prompt : '');

        $toneRaw = $body['tone'] ?? null;
        $tone    = is_string($toneRaw) ? $toneRaw : ($existing !== null ? $existing->tone : '');

        $overrideSystemRaw = $body['overrideSystem'] ?? null;
        $overrideSystem    = $overrideSystemRaw !== null ? (bool) $overrideSystemRaw : ($existing !== null ? $existing->overrideSystem : false);

        $tool = new AiTool(
            id: $existing !== null ? $existing->id : null,
            toolKey: $key,
            title: $title,
            icon: $icon,
            prompt: $prompt,
            overrideSystem: $overrideSystem,
            tone: $tone,
            params: $params,
            serviceId: $serviceId,
            isCustom: false,
            enabled: $enabled,
            sortOrder: $sortOrder,
        );

        $id = $this->aiTools->upsert($tool);

        return Json::ok(array_merge($tool->toArray(), ['id' => $id]));
    }

    /**
     * Creates a new custom tool (is_custom = true).
     *
     * Requires a unique `toolKey` in the body.  If the key already exists the
     * request is rejected with 409.
     *
     * @param array<string, mixed> $body
     */
    private function createAiTool(array $body): ResponseInterface
    {
        $key = trim($this->str($body, 'toolKey'));

        if ($key === '') {
            return Json::error('A toolKey is required to create a custom AI tool.', 400);
        }

        if ($this->aiTools->findByKey($key) !== null) {
            return Json::error('An AI tool with key "' . $key . '" already exists.', 409);
        }

        $paramsRaw = $body['params'] ?? null;
        /** @var array<string, mixed> $params */
        $params = is_array($paramsRaw) ? $paramsRaw : [];

        $serviceIdRaw = $body['serviceId'] ?? null;
        $serviceId    = is_numeric($serviceIdRaw) ? (int) $serviceIdRaw : null;

        $overrideSystemRaw = $body['overrideSystem'] ?? null;
        $overrideSystem    = $overrideSystemRaw !== null ? (bool) $overrideSystemRaw : false;

        $enabledRaw = $body['enabled'] ?? null;
        $enabled    = $enabledRaw !== null ? (bool) $enabledRaw : true;

        $sortOrderRaw = $body['sortOrder'] ?? null;
        $sortOrder    = is_numeric($sortOrderRaw) ? (int) $sortOrderRaw : 0;

        $tool = new AiTool(
            id: null,
            toolKey: $key,
            title: $this->str($body, 'title', $key),
            icon: $this->str($body, 'icon'),
            prompt: $this->str($body, 'prompt'),
            overrideSystem: $overrideSystem,
            tone: $this->str($body, 'tone'),
            params: $params,
            serviceId: $serviceId,
            isCustom: true,
            enabled: $enabled,
            sortOrder: $sortOrder,
        );

        $id = $this->aiTools->upsert($tool);

        return Json::ok(array_merge($tool->toArray(), ['id' => $id]), 201);
    }

    /**
     * Deletes a tool override or custom tool by key.
     */
    private function deleteAiTool(string $key): ResponseInterface
    {
        if ($this->aiTools->findByKey($key) === null) {
            return Json::error('AI tool "' . $key . '" not found.', 404);
        }

        $this->aiTools->delete($key);

        return Json::ok();
    }

    /**
     * Validates and forwards a non-streaming AI provider request.
     *
     * The body must supply `{serviceId, url, method, headers, body}`.  The
     * proxy validates that the target URL's host matches the configured
     * service endpoint — it never injects credentials (the JS side does that).
     *
     * @param array<string, mixed> $body
     */
    private function aiProxy(array $body): ResponseInterface
    {
        $serviceIdRaw = $body['serviceId'] ?? null;

        if (!is_numeric($serviceIdRaw)) {
            return Json::error('A numeric serviceId is required.', 400);
        }

        $url     = $this->str($body, 'url');
        $method  = $this->str($body, 'method', 'POST');
        $rawBody = $this->str($body, 'body');

        $headersRaw = $body['headers'] ?? null;
        /** @var array<string, string> $headers */
        $headers = [];

        if (is_array($headersRaw)) {
            foreach ($headersRaw as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $headers[$k] = $v;
                }
            }
        }

        if ($url === '') {
            return Json::error('A target URL is required.', 400);
        }

        try {
            $result = $this->aiProxy->forward((int) $serviceIdRaw, $url, $method, $headers, $rawBody);
        } catch (AiProxyException $e) {
            return Json::error($e->getMessage(), $e->httpStatus);
        }

        return Json::ok($result);
    }

    /**
     * Returns the complete resolved configuration the SPA transport needs to
     * call the AI provider directly (for streaming via EventSource).
     *
     * The resolved configuration includes:
     * - `endpoint`    — the service's configured base endpoint URL
     * - `chatPath`    — the provider's chat completion path (e.g. `/chat/completions`)
     * - `sseDialect`  — `"openai"` or `"anthropic"`
     * - `model`       — the service's configured model identifier
     * - `authHeader`  — the auth header name (`Authorization` or `X-Api-Key`)
     * - `apiKey`      — the resolved API key (from OS keychain or insecure fallback)
     * - `params`      — merged model params (service params ← tool params overlay)
     *
     * SECURITY NOTE (desktop-only trade-off):
     * Returning the raw API key to local JavaScript is intentional here.
     * Grafida is a single-user desktop application — the "browser" and the
     * "server" run in the same OS process under the same user account.  There is
     * no network boundary between PHP and the webview; exposing the key to the
     * local JS runtime is no less secure than keeping it in PHP, and it is
     * required to allow the SPA to open a native EventSource for SSE streaming
     * (which PHP cannot proxy line-by-line without holding up the request thread).
     */
    private function resolvedAiService(int $id, string $toolKey): ResponseInterface
    {
        $service = $this->aiServices->find($id);

        if ($service === null) {
            return Json::error('AI service not found', 404);
        }

        $providers = $this->aiDefaults->providers();
        $preset    = $providers[$service->provider] ?? null;

        // Resolved endpoint: service's own field (may be empty for preset providers).
        $endpoint = $service->endpoint !== '' ? $service->endpoint : (
            is_array($preset) ? ($preset['endpoint'] ?? '') : ''
        );

        $chatPath   = is_array($preset) ? ($preset['chat_path'] ?? '/chat/completions') : '/chat/completions';
        $sseDialect = is_array($preset) ? ($preset['sse_dialect'] ?? 'openai') : 'openai';
        $authType   = is_array($preset) ? ($preset['auth'] ?? 'bearer') : 'bearer';
        $authHeader = $authType === 'x-api-key' ? 'X-Api-Key' : 'Authorization';

        $apiKey = $this->aiServices->resolveKey($id);

        // Merge params: service params as base, tool-specific params as overlay.
        /** @var array<string, mixed> $params */
        $params = $service->params;

        if ($toolKey !== '') {
            $tool = $this->aiTools->findByKey($toolKey);

            if ($tool !== null && $tool->params !== []) {
                $params = array_merge($params, $tool->params);
            }
        }

        return Json::ok([
            'endpoint'   => $endpoint,
            'chatPath'   => $chatPath,
            'sseDialect' => $sseDialect,
            'model'      => $service->model,
            'authHeader' => $authHeader,
            'apiKey'     => $apiKey,
            'params'     => $params,
        ]);
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

    /**
     * The article columns the SPA may sort by. Each maps to a Joomla
     * `list[ordering]` value drawn from the article list's `filter_fields`
     * (administrator/components/com_content/ArticlesModel), so the API accepts
     * it. Anything outside this set falls back to `a.id`.
     */
    private const ARTICLE_ORDERING = [
        'a.id', 'a.title', 'category_title', 'a.created', 'a.modified',
        'a.publish_up', 'a.publish_down', 'a.access', 'a.state', 'a.featured',
        'a.ordering', 'a.hits', 'a.created_by', 'language',
    ];

    /** Published-state values Joomla's `filter[state]` accepts for articles. */
    private const ARTICLE_STATES = [1, 0, 2, -2];

    /**
     * Returns one page of the site's remote articles, sorted and filtered exactly
     * as Joomla's back-end article list would be. The SPA passes the page, page
     * size, ordering and the supported filters as query parameters; they are
     * forwarded to the Joomla REST API (which exposes `filter[search|category|
     * tag|language|state|featured|checked_out]` and `list[ordering|direction]`)
     * and the page's items are returned alongside the pagination total.
     */
    private function remoteArticles(int $siteId, RequestInterface $request): ResponseInterface
    {
        $site  = $this->requireSite($siteId);
        $token = $this->sites->tokenFor($site);

        if ($token === null || $site->apiBase === null) {
            return Json::error('The site is not connected.', 409);
        }

        $q = $request->url->query;

        $limit = (int) ($q->get('limit') ?? 20);
        $limit = max(1, min(100, $limit));
        $page  = max(1, (int) ($q->get('page') ?? 1));

        $ordering = $q->get('ordering') ?? 'a.id';
        if (!in_array($ordering, self::ARTICLE_ORDERING, true)) {
            $ordering = 'a.id';
        }
        $direction = strtolower($q->get('direction') ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query = [
            'page[limit]'     => $limit,
            'page[offset]'    => ($page - 1) * $limit,
            'list[ordering]'  => $ordering,
            'list[direction]' => $direction,
        ];

        $search = trim($q->get('search') ?? '');
        if ($search !== '') {
            $query['filter[search]'] = $search;
        }

        $category = (int) ($q->get('category') ?? 0);
        if ($category > 0) {
            $query['filter[category]'] = $category;
        }

        $tag = (int) ($q->get('tag') ?? 0);
        if ($tag > 0) {
            $query['filter[tag]'] = $tag;
        }

        $language = trim($q->get('language') ?? '');
        if ($language !== '') {
            $query['filter[language]'] = $language;
        }

        $stateRaw = $q->get('state');
        if ($stateRaw !== null && $stateRaw !== '' && in_array((int) $stateRaw, self::ARTICLE_STATES, true)) {
            $query['filter[state]'] = (int) $stateRaw;
        }

        $featuredRaw = $q->get('featured');
        if ($featuredRaw === '0' || $featuredRaw === '1') {
            $query['filter[featured]'] = (int) $featuredRaw;
        }

        $checkedOutRaw = $q->get('checked_out');
        if ($checkedOutRaw === '0' || $checkedOutRaw === '-1') {
            $query['filter[checked_out]'] = (int) $checkedOutRaw;
        }

        $result = $this->apiClient->listArticlesPage($site->apiBase, $token, $query);

        return Json::ok([
            'items'      => $this->withCategoryTitles($result['items'], $site),
            'page'       => $page,
            'limit'      => $limit,
            'totalPages' => $result['totalPages'],
        ]);
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
     * Annotates each article in a list with a normalised `catid` (int|null) and a
     * human-readable `categoryTitle`, resolved from the site's cached category
     * list. Saved drafts already carry a `catid` attribute; the remote list
     * webservice exposes the category only as a JSON:API relationship — both are
     * accepted. An id the cache does not know leaves `categoryTitle` null.
     *
     * @param list<array<string, mixed>> $articles
     *
     * @return list<array<string, mixed>>
     */
    private function withCategoryTitles(array $articles, Site $site): array
    {
        $titles = [];
        foreach ($this->references->categories($site) as $cat) {
            $id = $cat['id'] ?? null;
            if (is_numeric($id)) {
                $titles[(int) $id] = $this->str($cat, 'title');
            }
        }

        foreach ($articles as &$article) {
            $catId = isset($article['catid']) && is_numeric($article['catid'])
                ? (int) $article['catid']
                : $this->firstRelationshipId($article, 'category');

            $article['catid']         = $catId;
            $article['categoryTitle'] = $catId !== null ? ($titles[$catId] ?? null) : null;
        }
        unset($article);

        return $articles;
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
        $drafts = array_map(
            static fn (Draft $d): array => $d->toArray(),
            $this->drafts->forSite($siteId)
        );

        return Json::ok($this->withCategoryTitles($drafts, $this->requireSite($siteId)));
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
        $conn = $this->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        [, $token, $base] = $conn;
        $entries = $this->apiClient->listMedia($base, $token, $path);

        return Json::ok(['path' => $path, 'entries' => $entries]);
    }

    /** Lists the site's Media Manager adapters (filesystems) for the Media Manager screen. */
    private function mediaAdapters(int $siteId): ResponseInterface
    {
        $conn = $this->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        [, $token, $base] = $conn;

        return Json::ok(['adapters' => $this->apiClient->listMediaAdapters($base, $token)]);
    }

    /** Returns a media file's bytes as a data: URI so the SPA can load it for editing. */
    private function siteMediaFile(int $siteId, string $path): ResponseInterface
    {
        $conn = $this->connectedSite($siteId);

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
    private function uploadSiteMedia(int $siteId, array $body): ResponseInterface
    {
        $conn = $this->connectedSite($siteId);

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
    private function createSiteMediaFolder(int $siteId, array $body): ResponseInterface
    {
        $conn = $this->connectedSite($siteId);

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
    private function renameSiteMedia(int $siteId, array $body): ResponseInterface
    {
        $conn = $this->connectedSite($siteId);

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
    private function updateSiteMediaContent(int $siteId, array $body): ResponseInterface
    {
        $conn = $this->connectedSite($siteId);

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
    private function deleteSiteMedia(int $siteId, string $path): ResponseInterface
    {
        $conn = $this->connectedSite($siteId);

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

    /** Re-probes the OS light/dark preference so "auto" can follow it at runtime. */
    private function systemTheme(): ResponseInterface
    {
        return Json::ok(['systemPrefersDark' => $this->displayMode->systemPrefersDark()]);
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
     * Resolves a site together with the credentials needed to call its REST API.
     * Returns null when the site exists but is not connected (no token / API base),
     * so callers can answer with a 409 rather than a generic failure.
     *
     * @return array{0: Site, 1: string, 2: string}|null [site, token, apiBase]
     */
    private function connectedSite(int $siteId): ?array
    {
        $site  = $this->requireSite($siteId);
        $token = $this->sites->tokenFor($site);

        if ($token === null || $site->apiBase === null) {
            return null;
        }

        return [$site, $token, $site->apiBase];
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
