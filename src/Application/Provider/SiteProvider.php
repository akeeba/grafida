<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application\Provider;

use Grafida\Application\Container;
use Grafida\Article\DraftExportService;
use Grafida\Article\DraftRepository;
use Grafida\Ai\AiChatRepository;
use Grafida\Html\CssRebaser;
use Grafida\Http\SiteContext;
use Grafida\Joomla\ApiClient;
use Grafida\Media\MediaRepository;
use Grafida\Media\SiteImageFetcher;
use Grafida\Publish\PublishService;
use Grafida\Reference\EditorCssService;
use Grafida\Reference\TemplateDiscovery;
use Grafida\Reference\ReferenceRepository;
use Grafida\Reference\ReferenceService;
use Grafida\Http\Transport;
use Grafida\Secret\SecretStore;
use Grafida\Secret\SecretStoreFactory;
use Grafida\Site\FaviconRepository;
use Grafida\Site\FaviconService;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Joomla\DI\ServiceProviderInterface;

/**
 * Registers the resolved secret store plus every site-facing domain service.
 */
final class SiteProvider implements ServiceProviderInterface
{
    public function register(\Joomla\DI\Container $container): void
    {
        // Resolves the `secret.store` tri-state parameter exactly the way
        // Kernel::__construct() does today: null → the platform's secure
        // store (production default), false → no store (forces the
        // insecure-fallback path), a SecretStore instance → used as-is.
        $container->share('secret.store.resolved', static function (Container $c): ?SecretStore {
            /** @var SecretStore|false|null $configured */
            $configured = $c->get('secret.store');

            if ($configured === null) {
                return SecretStoreFactory::secureStore();
            }

            if ($configured === false) {
                return null;
            }

            return $configured;
        });

        $container->share(SiteService::class, static function (Container $c): SiteService {
            /** @var ?SecretStore $secureStore */
            $secureStore = $c->get('secret.store.resolved');

            return new SiteService($c->get(SiteRepository::class), $c->get(ApiClient::class), $secureStore);
        });

        $container->share(FaviconService::class, static function (Container $c): FaviconService {
            /** @var Transport $http */
            $http = $c->get('http.short');

            return new FaviconService($c->get(FaviconRepository::class), $http);
        });

        $container->share(SiteImageFetcher::class, static function (Container $c): SiteImageFetcher {
            /** @var Transport $http */
            $http = $c->get('http.reference');

            return new SiteImageFetcher($http);
        });

        $container->share(ReferenceService::class, static function (Container $c): ReferenceService {
            /** @var ApiClient $api */
            $api = $c->get('api.client.reference');

            return new ReferenceService($c->get(ReferenceRepository::class), $c->get(SiteService::class), $api);
        });

        $container->share(TemplateDiscovery::class, static function (Container $c): TemplateDiscovery {
            /** @var Transport $http */
            $http = $c->get('http.short');

            return new TemplateDiscovery($c->get(ReferenceRepository::class), $http);
        });

        $container->share(EditorCssService::class, static function (Container $c): EditorCssService {
            /** @var Transport $http */
            $http = $c->get('http.short');

            return new EditorCssService(
                $c->get(ReferenceRepository::class),
                $c->get(TemplateDiscovery::class),
                new CssRebaser(),
                $http,
            );
        });

        $container->share(SiteContext::class, static function (Container $c): SiteContext {
            return new SiteContext(
                $c->get(SiteService::class),
                $c->get(FaviconService::class),
                $c->get(ReferenceService::class),
            );
        });

        $container->share(PublishService::class, static function (Container $c): PublishService {
            return new PublishService(
                sites: $c->get(SiteService::class),
                api: $c->get(ApiClient::class),
                references: $c->get(ReferenceService::class),
                drafts: $c->get(DraftRepository::class),
                media: $c->get(MediaRepository::class),
            );
        });

        $container->share(DraftExportService::class, static function (Container $c): DraftExportService {
            return new DraftExportService(
                $c->get(DraftRepository::class),
                $c->get(MediaRepository::class),
                $c->get(AiChatRepository::class),
            );
        });
    }
}
