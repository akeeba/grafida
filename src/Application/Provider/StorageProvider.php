<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application\Provider;

use Grafida\Ai\AiChatRepository;
use Grafida\Ai\AiServiceManager;
use Grafida\Ai\AiServiceRepository;
use Grafida\Ai\AiToolRepository;
use Grafida\Application\Container;
use Grafida\Article\DraftRepository;
use Grafida\Media\MediaRepository;
use Grafida\Reference\ReferenceRepository;
use Grafida\Site\FaviconRepository;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Grafida\Storage\DatabaseFactory;
use Grafida\Storage\Migrator;
use Grafida\Storage\SettingsRepository;
use Grafida\Storage\StorageService;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\ServiceProviderInterface;

/**
 * Registers the migrated SQLite connection, the migrator, and every
 * repository plus the local-storage maintenance service.
 */
final class StorageProvider implements ServiceProviderInterface
{
    public function register(\Joomla\DI\Container $container): void
    {
        $container->share(DatabaseInterface::class, static function (Container $c): DatabaseInterface {
            /** @var string $path */
            $path = $c->get('db.path');
            /** @var string $migrationsDir */
            $migrationsDir = $c->get('migrations.dir');

            $db = (new DatabaseFactory())->create($path);
            (new Migrator($db, $migrationsDir))->migrate();

            return $db;
        });

        $container->share(Migrator::class, static function (Container $c): Migrator {
            /** @var string $migrationsDir */
            $migrationsDir = $c->get('migrations.dir');

            return new Migrator($c->get(DatabaseInterface::class), $migrationsDir);
        });

        $container->share(
            SettingsRepository::class,
            static fn (Container $c): SettingsRepository => new SettingsRepository($c->get(DatabaseInterface::class))
        );

        $container->share(
            SiteRepository::class,
            static fn (Container $c): SiteRepository => new SiteRepository($c->get(DatabaseInterface::class))
        );

        $container->share(
            FaviconRepository::class,
            static fn (Container $c): FaviconRepository => new FaviconRepository($c->get(DatabaseInterface::class))
        );

        $container->share(
            DraftRepository::class,
            static fn (Container $c): DraftRepository => new DraftRepository($c->get(DatabaseInterface::class))
        );

        $container->share(
            MediaRepository::class,
            static fn (Container $c): MediaRepository => new MediaRepository($c->get(DatabaseInterface::class))
        );

        $container->share(
            ReferenceRepository::class,
            static fn (Container $c): ReferenceRepository => new ReferenceRepository($c->get(DatabaseInterface::class))
        );

        $container->share(
            AiChatRepository::class,
            static fn (Container $c): AiChatRepository => new AiChatRepository($c->get(DatabaseInterface::class))
        );

        $container->share(
            AiServiceRepository::class,
            static fn (Container $c): AiServiceRepository => new AiServiceRepository($c->get(DatabaseInterface::class))
        );

        $container->share(
            AiToolRepository::class,
            static fn (Container $c): AiToolRepository => new AiToolRepository($c->get(DatabaseInterface::class))
        );

        $container->share(StorageService::class, static function (Container $c): StorageService {
            return new StorageService(
                $c->get(DatabaseInterface::class),
                $c->get(SiteService::class),
                $c->get(AiServiceManager::class),
            );
        });
    }
}
