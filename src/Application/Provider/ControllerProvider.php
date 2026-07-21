<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application\Provider;

use Boson\Api\Dialog\DialogApiInterface;
use Grafida\Ai\AiChatRepository;
use Grafida\Ai\AiProxy;
use Grafida\Ai\AiRenderer;
use Grafida\Ai\AiServiceManager;
use Grafida\Ai\AiToolRepository;
use Grafida\Ai\Defaults;
use Grafida\Application\Container;
use Grafida\Article\DraftExportService;
use Grafida\Article\DraftRepository;
use Grafida\Debug\RequestLog;
use Grafida\Debug\RequestLogService;
use Grafida\Display\DisplayModeService;
use Grafida\Editor\SlashToolsService;
use Grafida\Editor\SpellCheckService;
use Grafida\Field\FieldSupport;
use Grafida\Http\ApiController;
use Grafida\Http\Controller\AiChatController;
use Grafida\Http\Controller\AiServiceController;
use Grafida\Http\Controller\ArticleController;
use Grafida\Http\Controller\BootstrapController;
use Grafida\Http\Controller\DraftController;
use Grafida\Http\Controller\MediaController;
use Grafida\Http\Controller\SettingsController;
use Grafida\Http\Controller\SiteController;
use Grafida\Http\SiteContext;
use Grafida\I18n\LanguageService;
use Grafida\Joomla\ApiClient;
use Grafida\Markdown\MarkdownService;
use Grafida\Media\InlineImageExtractor;
use Grafida\Media\MediaRepository;
use Grafida\Media\SiteImageFetcher;
use Grafida\Publish\PublishService;
use Grafida\Reference\EditorCssService;
use Grafida\Reference\ReferenceService;
use Grafida\Site\ConnectionDiagnostics;
use Grafida\Site\FaviconService;
use Grafida\Site\LastSiteService;
use Grafida\Site\SiteService;
use Grafida\Storage\SettingsRepository;
use Grafida\Storage\StorageService;
use Grafida\Support\UrlOpener;
use Grafida\Update\UpdateService;
use Joomla\DI\ServiceProviderInterface;

/**
 * Registers the nine per-domain controllers `ApiController` dispatches to —
 * each wired with only the collaborators its own handlers use, which is what
 * keeps any single controller from growing back into a 24-dependency god
 * object — and `ApiController` itself, now just a thin dispatcher over them.
 */
final class ControllerProvider implements ServiceProviderInterface
{
    public function register(\Joomla\DI\Container $container): void
    {
        $container->share(BootstrapController::class, static function (Container $c): BootstrapController {
            return new BootstrapController(
                sites: $c->get(SiteService::class),
                lastSite: $c->get(LastSiteService::class),
                siteContext: $c->get(SiteContext::class),
                language: $c->get(LanguageService::class),
                displayMode: $c->get(DisplayModeService::class),
                slashTools: $c->get(SlashToolsService::class),
                spellCheck: $c->get(SpellCheckService::class),
                aiDefaults: $c->get(Defaults::class),
                aiServices: $c->get(AiServiceManager::class),
                aiTools: $c->get(AiToolRepository::class),
                requestLog: $c->get(RequestLog::class),
                requestLogService: $c->get(RequestLogService::class),
            );
        });

        $container->share(SiteController::class, static function (Container $c): SiteController {
            return new SiteController(
                sites: $c->get(SiteService::class),
                favicons: $c->get(FaviconService::class),
                references: $c->get(ReferenceService::class),
                siteContext: $c->get(SiteContext::class),
                fields: $c->get(FieldSupport::class),
                editorCss: $c->get(EditorCssService::class),
                diagnostics: $c->get(ConnectionDiagnostics::class),
            );
        });

        $container->share(ArticleController::class, static function (Container $c): ArticleController {
            return new ArticleController(
                siteContext: $c->get(SiteContext::class),
                references: $c->get(ReferenceService::class),
                apiClient: $c->get(ApiClient::class),
            );
        });

        $container->share(DraftController::class, static function (Container $c): DraftController {
            return new DraftController(
                siteContext: $c->get(SiteContext::class),
                drafts: $c->get(DraftRepository::class),
                draftExport: $c->get(DraftExportService::class),
                publish: $c->get(PublishService::class),
                inlineImages: $c->get(InlineImageExtractor::class),
            );
        });

        $container->share(MediaController::class, static function (Container $c): MediaController {
            return new MediaController(
                siteContext: $c->get(SiteContext::class),
                apiClient: $c->get(ApiClient::class),
                media: $c->get(MediaRepository::class),
                siteImages: $c->get(SiteImageFetcher::class),
            );
        });

        $container->share(AiServiceController::class, static function (Container $c): AiServiceController {
            return new AiServiceController(
                aiServices: $c->get(AiServiceManager::class),
                settings: $c->get(SettingsRepository::class),
                aiDefaults: $c->get(Defaults::class),
                aiTools: $c->get(AiToolRepository::class),
            );
        });

        $container->share(AiChatController::class, static function (Container $c): AiChatController {
            return new AiChatController(
                aiProxy: $c->get(AiProxy::class),
                aiRenderer: $c->get(AiRenderer::class),
                aiChats: $c->get(AiChatRepository::class),
            );
        });

        $container->share(SettingsController::class, static function (Container $c): SettingsController {
            /** @var ?DialogApiInterface $dialog */
            $dialog = $c->get('dialog');

            return new SettingsController(
                markdown: $c->get(MarkdownService::class),
                language: $c->get(LanguageService::class),
                displayMode: $c->get(DisplayModeService::class),
                slashTools: $c->get(SlashToolsService::class),
                spellCheck: $c->get(SpellCheckService::class),
                lastSite: $c->get(LastSiteService::class),
                urlOpener: $c->get(UrlOpener::class),
                updates: $c->get(UpdateService::class),
                storage: $c->get(StorageService::class),
                requestLog: $c->get(RequestLog::class),
                requestLogService: $c->get(RequestLogService::class),
                dialog: $dialog,
            );
        });

        $container->share(ApiController::class, static function (Container $c): ApiController {
            return new ApiController(
                bootstrap: $c->get(BootstrapController::class),
                siteController: $c->get(SiteController::class),
                articleController: $c->get(ArticleController::class),
                draftController: $c->get(DraftController::class),
                mediaController: $c->get(MediaController::class),
                aiServiceController: $c->get(AiServiceController::class),
                aiChatController: $c->get(AiChatController::class),
                settingsController: $c->get(SettingsController::class),
            );
        });
    }
}
