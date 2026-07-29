<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http\Controller;

use Boson\Api\Dialog\DialogApiInterface;
use Boson\Contracts\Http\ResponseInterface;
use Grafida\Debug\RequestLog;
use Grafida\Debug\RequestLogService;
use Grafida\Debug\RequestRecord;
use Grafida\Display\DisplayModeService;
use Grafida\Editor\AutoCloseTagsService;
use Grafida\Editor\SlashToolsService;
use Grafida\Editor\SpellCheckService;
use Grafida\Http\Json;
use Grafida\Http\RouteContext;
use Grafida\Http\Router;
use Grafida\I18n\LanguageService;
use Grafida\I18n\UiStrings;
use Grafida\Markdown\MarkdownService;
use Grafida\Reference\MetadataCacheService;
use Grafida\Site\LastSiteService;
use Grafida\Storage\StorageService;
use Grafida\Support\App;
use Grafida\Support\UrlOpener;
use Grafida\Update\UpdateService;

/**
 * Handles the miscellaneous settings/system endpoints: language, display
 * mode, the editor's slash commands, storage maintenance, the update checker,
 * Markdown conversion, the native URL opener, the Request Log (gh-37's Debug
 * setting), and the native file/directory dialogs.
 */
final class SettingsController extends Controller
{
    public function __construct(
        private readonly MarkdownService $markdown,
        private readonly LanguageService $language,
        private readonly DisplayModeService $displayMode,
        private readonly SlashToolsService $slashTools,
        private readonly SpellCheckService $spellCheck,
        private readonly AutoCloseTagsService $autoCloseTags,
        private readonly LastSiteService $lastSite,
        private readonly UrlOpener $urlOpener,
        private readonly UpdateService $updates,
        private readonly StorageService $storage,
        private readonly RequestLog $requestLog,
        private readonly RequestLogService $requestLogService,
        private readonly MetadataCacheService $metadataCache,
        private readonly ?DialogApiInterface $dialog = null,
    ) {}

    public function registerRoutes(Router $router): void
    {
        $router->add('POST', '/api/markdown', fn (RouteContext $ctx): ResponseInterface => $this->convertMarkdown($ctx->body()));
        $router->add('POST', '/api/settings/language', fn (RouteContext $ctx): ResponseInterface => $this->setLanguage($ctx->body()));
        $router->add('POST', '/api/settings/display-mode', fn (RouteContext $ctx): ResponseInterface => $this->setDisplayMode($ctx->body()));
        $router->add('GET', '/api/settings/system-theme', fn (RouteContext $ctx): ResponseInterface => $this->systemTheme());
        $router->add('POST', '/api/settings/slash-tools', fn (RouteContext $ctx): ResponseInterface => $this->setSlashTools($ctx->body()));
        $router->add('POST', '/api/settings/spell-check', fn (RouteContext $ctx): ResponseInterface => $this->setSpellCheck($ctx->body()));
        $router->add('POST', '/api/settings/auto-close-tags', fn (RouteContext $ctx): ResponseInterface => $this->setAutoCloseTags($ctx->body()));
        $router->add('POST', '/api/settings/last-site', fn (RouteContext $ctx): ResponseInterface => $this->setLastSite($ctx->body()));
        $router->add('POST', '/api/settings/request-log', fn (RouteContext $ctx): ResponseInterface => $this->setRequestLog($ctx->body()));
        $router->add('POST', '/api/settings/metadata-cache', fn (RouteContext $ctx): ResponseInterface => $this->setMetadataCache($ctx->body()));
        $router->add('GET', '/api/request-log', fn (RouteContext $ctx): ResponseInterface => $this->requestLog());
        $router->add('POST', '/api/request-log/clear', fn (RouteContext $ctx): ResponseInterface => $this->clearRequestLog());
        $router->add('POST', '/api/request-log/export', fn (RouteContext $ctx): ResponseInterface => $this->exportRequestLog($ctx->body()));
        $router->add('GET', '/api/update', fn (RouteContext $ctx): ResponseInterface => $this->updateStatus());
        $router->add('GET', '/api/settings/storage', fn (RouteContext $ctx): ResponseInterface => $this->storageInfo());
        $router->add('POST', '/api/settings/storage/open', fn (RouteContext $ctx): ResponseInterface => $this->openStorageFolder());
        $router->add('POST', '/api/settings/storage/reset', fn (RouteContext $ctx): ResponseInterface => $this->resetStorage());
        $router->add('POST', '/api/open-url', fn (RouteContext $ctx): ResponseInterface => $this->openUrl($ctx->body()));
        $router->add('POST', '/api/dialog/open-file', fn (RouteContext $ctx): ResponseInterface => $this->openFile($ctx->body()));
        $router->add('POST', '/api/dialog/select-directory', fn (RouteContext $ctx): ResponseInterface => $this->selectDirectory($ctx->body()));
    }

    /** @param array<string, mixed> $body */
    public function openUrl(array $body): ResponseInterface
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
    public function openFile(array $body): ResponseInterface
    {
        if ($this->dialog === null) {
            return Json::error('Native file dialog is unavailable', 503);
        }

        $filter = match ($this->str($body, 'filter', 'any')) {
            'image'    => ['*.png', '*.jpg', '*.jpeg', '*.gif', '*.webp', '*.svg', '*.bmp', '*.avif'],
            'markdown' => ['*.md', '*.markdown', '*.txt'],
            'grafida'  => ['*.grafida'],
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

    /**
     * Open a native OS directory-picker (Boson has no "Save As" file dialog,
     * so exporting a draft asks for a destination folder instead and the
     * filename is derived from the draft).
     *
     * @param array<string, mixed> $body
     */
    public function selectDirectory(array $body): ResponseInterface
    {
        if ($this->dialog === null) {
            return Json::error('Native file dialog is unavailable', 503);
        }

        $path = $this->dialog->selectDirectory();

        if ($path === null || $path === '') {
            return Json::ok(['cancelled' => true]);
        }

        return Json::ok(['path' => $path]);
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
            'grafida'       => 'application/json',
            default         => 'application/octet-stream',
        };
    }

    /** @param array<string, mixed> $body */
    public function convertMarkdown(array $body): ResponseInterface
    {
        return Json::ok(['html' => $this->markdown->toHtml($this->str($body, 'markdown'))]);
    }

    /** @param array<string, mixed> $body */
    public function setLanguage(array $body): ResponseInterface
    {
        $this->language->setOverride($this->str($body, 'tag', LanguageService::AUTO));

        return Json::ok([
            'language' => $this->language->currentTag(),
            'strings'  => $this->language->strings(UiStrings::KEYS),
        ]);
    }

    /** @param array<string, mixed> $body */
    public function setDisplayMode(array $body): ResponseInterface
    {
        $mode = $this->displayMode->set($this->str($body, 'mode', DisplayModeService::AUTO));

        return Json::ok(['displayMode' => $mode]);
    }

    /** @param array<string, mixed> $body */
    public function setSlashTools(array $body): ResponseInterface
    {
        $enabled = $this->slashTools->set($this->bool($body, 'enabled', true));

        return Json::ok(['slashTools' => $enabled]);
    }

    /** @param array<string, mixed> $body */
    public function setSpellCheck(array $body): ResponseInterface
    {
        $enabled = $this->spellCheck->set($this->bool($body, 'enabled', true));

        return Json::ok(['spellCheck' => $enabled]);
    }

    /** @param array<string, mixed> $body */
    public function setAutoCloseTags(array $body): ResponseInterface
    {
        $mode = $this->autoCloseTags->set($this->str($body, 'mode', AutoCloseTagsService::FULL));

        return Json::ok(['autoCloseTags' => $mode]);
    }

    /**
     * Remembers the site the user just selected, so it is re-selected on the
     * next launch. A zero/absent id clears the stored preference.
     *
     * Also clears the Request Log: the log's requests belong to whichever
     * site was active when they were made, so switching sites starts a fresh
     * buffer rather than mixing two sites' exchanges together.
     *
     * @param array<string, mixed> $body
     */
    public function setLastSite(array $body): ResponseInterface
    {
        $id = $this->int($body, 'siteId');
        $this->lastSite->set($id > 0 ? $id : null);
        $this->requestLog->clear();

        return Json::ok(['lastSiteId' => $id > 0 ? $id : null]);
    }

    /** Re-probes the OS light/dark preference so "auto" can follow it at runtime. */
    public function systemTheme(): ResponseInterface
    {
        return Json::ok(['systemPrefersDark' => $this->displayMode->systemPrefersDark()]);
    }

    /**
     * Reports whether a newer version is available (refreshing the 12-hour cache
     * from the CDN if needed). Called asynchronously by the SPA at start-up.
     */
    public function updateStatus(): ResponseInterface
    {
        return Json::ok($this->updates->status());
    }

    public function storageInfo(): ResponseInterface
    {
        return Json::ok($this->storage->info());
    }

    public function openStorageFolder(): ResponseInterface
    {
        $this->storage->openContainingFolder();

        return Json::ok();
    }

    public function resetStorage(): ResponseInterface
    {
        $this->storage->reset();

        return Json::ok();
    }

    /**
     * Toggles the Request Log (Debug) setting. Turning it off also empties
     * the buffer immediately — an implicit "clear" the user would otherwise
     * have to trigger separately, and the honest thing to do since a
     * disabled log should show nothing next time it is enabled.
     *
     * @param array<string, mixed> $body
     */
    public function setRequestLog(array $body): ResponseInterface
    {
        $enabled = $this->requestLogService->set($this->bool($body, 'enabled', false));

        if (!$enabled) {
            $this->requestLog->clear();
        }

        return Json::ok(['requestLog' => $enabled]);
    }

    /**
     * Updates either or both of the site-metadata cache preferences (gh-42
     * round 2). Each field is applied only when present in the body, so the
     * SPA can send one without clobbering the other — the two selectors in
     * Settings fire independent `change` events. Responds with the
     * **effective** (clamped) values rather than echoing the request, which is
     * what lets the SPA see a server-side clamp happen.
     *
     * @param array<string, mixed> $body
     */
    public function setMetadataCache(array $body): ResponseInterface
    {
        if (\array_key_exists('resetOnStart', $body)) {
            $this->metadataCache->setResetOnStart($this->bool($body, 'resetOnStart'));
        }

        if (\array_key_exists('ttlMinutes', $body)) {
            $this->metadataCache->setTtlMinutes($this->int($body, 'ttlMinutes', MetadataCacheService::DEFAULT_TTL_MINUTES));
        }

        return Json::ok([
            'metadataResetOnStart' => $this->metadataCache->resetOnStart(),
            'metadataCacheTtl'     => $this->metadataCache->ttlMinutes(),
        ]);
    }

    /** The Request Log screen's data: whether it is enabled and every entry currently stored, newest first. */
    public function requestLog(): ResponseInterface
    {
        return Json::ok([
            'enabled' => $this->requestLogService->current(),
            'entries' => array_map(static fn (RequestRecord $r): array => $r->toArray(), $this->requestLog->entries()),
        ]);
    }

    public function clearRequestLog(): ResponseInterface
    {
        $this->requestLog->clear();

        return Json::ok();
    }

    /**
     * Writes the current Request Log to a JSON file in the given directory —
     * the same "ask for a destination folder" pattern as
     * {@see \Grafida\Http\Controller\DraftController::exportDraft()}, since
     * Boson has no native Save-As dialog. Redaction is automatic:
     * {@see RequestRecord::toArray()} is the only way a record is serialised
     * and it always redacts, so there is no separate export-specific
     * redaction path to keep in sync.
     *
     * @param array<string, mixed> $body
     */
    public function exportRequestLog(array $body): ResponseInterface
    {
        $directory = $this->str($body, 'directory');

        if ($directory === '' || !is_dir($directory) || !is_writable($directory)) {
            return Json::error('A valid, writable destination folder is required.', 400);
        }

        $payload = [
            'app'        => App::NAME . ' ' . App::VERSION,
            'exportedAt' => gmdate('Y-m-d H:i:s'),
            'entries'    => array_map(static fn (RequestRecord $r): array => $r->toArray(), $this->requestLog->entries()),
        ];

        $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return Json::error('Could not encode the request log', 500);
        }

        $filename = 'grafida-request-log-' . gmdate('Ymd-His') . '.json';
        $path     = rtrim($directory, '/\\') . \DIRECTORY_SEPARATOR . $filename;

        if (@file_put_contents($path, $json) === false) {
            return Json::error('Could not write the export file', 500);
        }

        return Json::ok(['path' => $path, 'filename' => $filename]);
    }
}
