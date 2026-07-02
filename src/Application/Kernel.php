<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application;

use Boson\Api\Dialog\DialogApiInterface;
use Boson\Component\Http\Response;
use Boson\Component\Http\Static\StaticProviderInterface;
use Boson\Contracts\Http\RequestInterface;
use Boson\Contracts\Http\ResponseInterface;
use Grafida\Ai\AiChatRepository;
use Grafida\Ai\AiProxy;
use Grafida\Ai\AiRenderer;
use Grafida\Ai\AiServiceManager;
use Grafida\Ai\AiServiceRepository;
use Grafida\Ai\AiToolRepository;
use Grafida\Ai\Defaults;
use Grafida\Article\DraftExportService;
use Grafida\Article\DraftRepository;
use Grafida\Display\DisplayModeService;
use Grafida\Field\FieldSupport;
use Grafida\Http\ApiController;
use Grafida\I18n\LanguageService;
use Grafida\Joomla\ApiClient;
use Grafida\Markdown\MarkdownService;
use Grafida\Media\MediaRepository;
use Grafida\Publish\PublishService;
use Grafida\Reference\EditorCssService;
use Grafida\Reference\ReferenceRepository;
use Grafida\Reference\ReferenceService;
use Grafida\Secret\SecretStore;
use Grafida\Secret\SecretStoreFactory;
use Grafida\Site\FaviconRepository;
use Grafida\Site\FaviconService;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Grafida\Http\HttpClient;
use Grafida\Storage\Database;
use Grafida\Storage\SettingsRepository;
use Grafida\Storage\StorageService;
use Grafida\Support\App;
use Grafida\Support\Paths;
use Grafida\Support\Resources;
use Grafida\Support\UrlOpener;
use Grafida\Update\UpdateService;
use PDO;

/**
 * Composition root and request dispatcher.
 *
 * Wires every repository and service together, then routes incoming Boson
 * scheme requests: `/api/...` paths go to the JSON API controller, everything
 * else is served as a static asset or the single-page-app shell.
 */
final class Kernel
{
    private readonly ApiController $api;

    public function __construct(
        private readonly StaticProviderInterface $static,
        ?PDO $pdo = null,
        ?string $basePath = null,
        ?DialogApiInterface $dialog = null,
        SecretStore|false|null $secureStore = null,
    ) {
        $pdo      = $pdo ?? Database::get();
        $basePath = $basePath ?? Resources::base();

        // null  → use the factory (production default)
        // false → no secure store (test: force insecure-fallback path)
        // SecretStore instance → use the provided store (test: in-memory stub)
        if ($secureStore === null) {
            $secureStore = SecretStoreFactory::secureStore();
        } elseif ($secureStore === false) {
            $secureStore = null;
        }

        $settings    = new SettingsRepository($pdo);
        $aiToolRepo  = new AiToolRepository($pdo);
        $aiChatRepo  = new AiChatRepository($pdo);
        $aiDefaults  = new Defaults();
        $apiClient   = new ApiClient();

        $siteService = new SiteService(new SiteRepository($pdo), $apiClient, $secureStore);
        $favicons    = new FaviconService(new FaviconRepository($pdo));
        $referenceRepo = new ReferenceRepository($pdo);
        $references  = new ReferenceService($referenceRepo, $siteService);
        $editorCss   = new EditorCssService($referenceRepo);
        $drafts      = new DraftRepository($pdo);
        $media       = new MediaRepository($pdo);
        $publish     = new PublishService($siteService, $apiClient, $references, $drafts, $media);
        $draftExport = new DraftExportService($drafts, $media, $aiChatRepo);
        $language    = new LanguageService($settings, $basePath);
        $displayMode = new DisplayModeService($settings);
        $storage     = new StorageService($pdo, $siteService);
        $aiServices  = new AiServiceManager(new AiServiceRepository($pdo), $secureStore);
        $aiProxy     = new AiProxy($aiServices, $aiDefaults, new HttpClient(300));
        $markdown    = new MarkdownService();
        $updates     = new UpdateService(new HttpClient(5), App::VERSION, Paths::updatesFile());

        $this->api = new ApiController(
            sites: $siteService,
            favicons: $favicons,
            references: $references,
            editorCss: $editorCss,
            drafts: $drafts,
            media: $media,
            publish: $publish,
            draftExport: $draftExport,
            markdown: $markdown,
            aiRenderer: new AiRenderer($markdown),
            language: $language,
            displayMode: $displayMode,
            fields: new FieldSupport(),
            apiClient: $apiClient,
            storage: $storage,
            urlOpener: new UrlOpener(),
            aiServices: $aiServices,
            aiDefaults: $aiDefaults,
            aiTools: $aiToolRepo,
            aiChats: $aiChatRepo,
            settings: $settings,
            aiProxy: $aiProxy,
            updates: $updates,
            dialog: $dialog,
        );
    }

    public function handle(RequestInterface $request): ResponseInterface
    {
        $path = (string) $request->url->path;

        if (str_starts_with($path, '/api/')) {
            return $this->api->dispatch($request);
        }

        $static = $this->static->findFileByRequest($request);

        if ($static !== null) {
            return $static;
        }

        // Single-page-app shell for any other route.
        $shell = @file_get_contents(\dirname(__DIR__, 2) . '/assets/private/view/index.html');

        return new Response((string) $shell, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
