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
use Grafida\Display\DisplayModeService;
use Grafida\Editor\SlashToolsService;
use Grafida\Http\Json;
use Grafida\Http\RouteContext;
use Grafida\Http\Router;
use Grafida\I18n\LanguageService;
use Grafida\I18n\UiStrings;
use Grafida\Markdown\MarkdownService;
use Grafida\Storage\StorageService;
use Grafida\Support\UrlOpener;
use Grafida\Update\UpdateService;

/**
 * Handles the miscellaneous settings/system endpoints: language, display
 * mode, the editor's slash commands, storage maintenance, the update checker,
 * Markdown conversion, the native URL opener, and the native file/directory
 * dialogs.
 */
final class SettingsController extends Controller
{
    public function __construct(
        private readonly MarkdownService $markdown,
        private readonly LanguageService $language,
        private readonly DisplayModeService $displayMode,
        private readonly SlashToolsService $slashTools,
        private readonly UrlOpener $urlOpener,
        private readonly UpdateService $updates,
        private readonly StorageService $storage,
        private readonly ?DialogApiInterface $dialog = null,
    ) {}

    public function registerRoutes(Router $router): void
    {
        $router->add('POST', '/api/markdown', fn (RouteContext $ctx): ResponseInterface => $this->convertMarkdown($ctx->body()));
        $router->add('POST', '/api/settings/language', fn (RouteContext $ctx): ResponseInterface => $this->setLanguage($ctx->body()));
        $router->add('POST', '/api/settings/display-mode', fn (RouteContext $ctx): ResponseInterface => $this->setDisplayMode($ctx->body()));
        $router->add('GET', '/api/settings/system-theme', fn (RouteContext $ctx): ResponseInterface => $this->systemTheme());
        $router->add('POST', '/api/settings/slash-tools', fn (RouteContext $ctx): ResponseInterface => $this->setSlashTools($ctx->body()));
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
}
