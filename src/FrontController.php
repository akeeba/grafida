<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida;

use Boson\Component\Http\Static\StaticProviderInterface;
use Boson\Contracts\Http\RequestInterface;
use Boson\Contracts\Http\ResponseInterface;
use Grafida\Application\Kernel;

/**
 * Entry point invoked for every Boson scheme request. Delegates to the kernel,
 * which serves the API, static assets, or the SPA shell.
 */
final class FrontController
{
    private readonly Kernel $kernel;

    public function __construct(StaticProviderInterface $static)
    {
        $this->kernel = new Kernel($static);
    }

    public function __invoke(RequestInterface $request): ResponseInterface
    {
        return $this->kernel->handle($request);
    }
}
