<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application\Provider;

use Boson\Component\Http\Static\StaticProviderInterface;
use Grafida\Application\Container;
use Grafida\Application\Kernel;
use Grafida\Display\DisplayModeService;
use Grafida\Editor\SlashToolsService;
use Grafida\Field\FieldSupport;
use Grafida\FrontController;
use Grafida\Http\ApiController;
use Grafida\Http\HttpClient;
use Grafida\I18n\LanguageService;
use Grafida\Markdown\MarkdownService;
use Grafida\Storage\SettingsRepository;
use Grafida\Support\App;
use Grafida\Support\Paths;
use Grafida\Support\UrlOpener;
use Grafida\Update\UpdateService;
use Joomla\DI\ServiceProviderInterface;

/**
 * Registers the remaining application-level services — language/display/editor
 * settings, Markdown, field support, the URL opener, the update checker —
 * and the entry points (`Kernel` and `FrontController`) built on top of the
 * `ApiController` that {@see ControllerProvider} assembles from the
 * per-domain controllers.
 */
final class AppProvider implements ServiceProviderInterface
{
    public function register(\Joomla\DI\Container $container): void
    {
        $container->share(LanguageService::class, static function (Container $c): LanguageService {
            /** @var string $basePath */
            $basePath = $c->get('base.path');

            return new LanguageService($c->get(SettingsRepository::class), $basePath);
        });

        $container->share(
            DisplayModeService::class,
            static fn (Container $c): DisplayModeService => new DisplayModeService($c->get(SettingsRepository::class))
        );

        $container->share(
            SlashToolsService::class,
            static fn (Container $c): SlashToolsService => new SlashToolsService($c->get(SettingsRepository::class))
        );

        $container->share(MarkdownService::class, static fn (): MarkdownService => new MarkdownService());

        $container->share(FieldSupport::class, static fn (): FieldSupport => new FieldSupport());

        $container->share(UrlOpener::class, static fn (): UrlOpener => new UrlOpener());

        $container->share(UpdateService::class, static function (Container $c): UpdateService {
            /** @var HttpClient $http */
            $http = $c->get('http.short');

            return new UpdateService($http, App::VERSION, Paths::updatesFile());
        });

        $container->share(Kernel::class, static function (Container $c): Kernel {
            /** @var StaticProviderInterface $static */
            $static = $c->get('static.provider');

            return new Kernel($static, $c->get(ApiController::class));
        });

        $container->share(
            FrontController::class,
            static fn (Container $c): FrontController => new FrontController($c->get(Kernel::class))
        );
    }
}
