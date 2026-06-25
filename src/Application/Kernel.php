<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application;

use Boson\Component\Http\Response;
use Boson\Component\Http\Static\StaticProviderInterface;
use Boson\Contracts\Http\RequestInterface;
use Boson\Contracts\Http\ResponseInterface;
use Grafida\Article\DraftRepository;
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
use Grafida\Secret\SecretStoreFactory;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Grafida\Storage\Database;
use Grafida\Storage\SettingsRepository;
use Grafida\Support\Resources;
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
    ) {
        $pdo      = $pdo ?? Database::get();
        $basePath = $basePath ?? Resources::base();

        $settings    = new SettingsRepository($pdo);
        $secureStore = SecretStoreFactory::secureStore();
        $apiClient   = new ApiClient();

        $siteService = new SiteService(new SiteRepository($pdo), $apiClient, $secureStore);
        $referenceRepo = new ReferenceRepository($pdo);
        $references  = new ReferenceService($referenceRepo, $apiClient, $siteService);
        $editorCss   = new EditorCssService($referenceRepo);
        $drafts      = new DraftRepository($pdo);
        $media       = new MediaRepository($pdo);
        $publish     = new PublishService($siteService, $apiClient, $references, $drafts, $media);
        $language    = new LanguageService($settings, $basePath);

        $this->api = new ApiController(
            sites: $siteService,
            references: $references,
            editorCss: $editorCss,
            drafts: $drafts,
            media: $media,
            publish: $publish,
            markdown: new MarkdownService(),
            language: $language,
            fields: new FieldSupport(),
            apiClient: $apiClient,
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
