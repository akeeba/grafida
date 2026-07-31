<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application;

use Boson\Application;
use Boson\Component\Saucer\SaucerInterface;
use Boson\Poller\PollerInterface;

/**
 * Boson's application with a throttled event loop.
 *
 * The only thing this changes is the poller: Boson's own spins at tens of thousands of iterations
 * a second and costs about half a CPU core with the app idle. {@see EventLoopThrottle} explains
 * why, and why it is a decorator around the poller the parent builds rather than a replacement
 * for it.
 *
 * Everything else — the window, the webview, the scheme handling — is the stock behaviour.
 */
final class BosonApplication extends Application
{
    private ?EventLoopThrottle $throttle = null;

    /**
     * Tell the event loop the app is busy, so it runs unthrottled for a moment.
     *
     * Call it whenever the app does work for the user — `index.php` calls it after every
     * `boson://` request the front controller answers — so that a burst of requests is not charged
     * the idle sleep once per request.
     */
    public function wake(): void
    {
        $this->throttle?->wake();
    }

    protected function createApplicationPoller(SaucerInterface $saucer): PollerInterface
    {
        return $this->throttle = new EventLoopThrottle(parent::createApplicationPoller($saucer));
    }
}
