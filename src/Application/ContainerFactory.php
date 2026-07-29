<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application;

use Grafida\Application\Provider\AiProvider;
use Grafida\Application\Provider\AppProvider;
use Grafida\Application\Provider\ControllerProvider;
use Grafida\Application\Provider\HttpProvider;
use Grafida\Application\Provider\SiteProvider;
use Grafida\Application\Provider\StorageProvider;
use Grafida\Support\Paths;
use Grafida\Support\Resources;

/**
 * Builds the application's DI container: registers the parameters every
 * service provider reads, then the providers themselves.
 */
final class ContainerFactory
{
    /**
     * @param array<string, mixed> $parameters Overrides for the default
     *     parameters below. `static.provider` has no default and must be
     *     supplied by the caller (`index.php`) for `Kernel`/`FrontController`
     *     to be resolvable.
     */
    public static function create(array $parameters = []): Container
    {
        $container = new Container();

        /** @var array<string, mixed> $defaults */
        $defaults = [
            'db.path'        => Paths::databaseFile(),
            'migrations.dir' => Resources::migrationsDir(),
            'base.path'      => Resources::base(),
            'docs.dir'       => Resources::docsDir(),
            'dialog'         => null,
            'secret.store'   => null,
        ];

        foreach (array_merge($defaults, $parameters) as $key => $value) {
            $container->share($key, $value);
        }

        $container->registerServiceProvider(new StorageProvider())
            ->registerServiceProvider(new HttpProvider())
            ->registerServiceProvider(new SiteProvider())
            ->registerServiceProvider(new AiProvider())
            ->registerServiceProvider(new ControllerProvider())
            ->registerServiceProvider(new AppProvider());

        return $container;
    }
}
