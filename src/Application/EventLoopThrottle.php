<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application;

use Boson\Poller\PollerInterface;
use Boson\Poller\SuspensionInterface;

/**
 * Keeps the Boson event loop from burning a CPU core while the app sits idle.
 *
 * Boson's `Application::run()` is a busy loop: it calls `$poller->next()` for as long as the app
 * lives, and the stock poller separates two iterations by `usleep(1)` — one microsecond. Every
 * third iteration crosses FFI into `saucer_application_run_once()`, which pumps the whole native
 * event loop (on macOS, a full `-[NSApplication nextEventMatchingMask:…]` round trip). At tens of
 * thousands of iterations a second that costs about **half a core, permanently**, with the window
 * doing nothing at all — measured at ~49% of a core on an M5 with Grafida idle on the Articles
 * screen, against 0.0% for the WebKit content process that was actually showing it.
 *
 * This decorator adds a second sleep after the inner poller has run, so the loop spins a few
 * hundred times a second instead of tens of thousands. That is still far above any rate a person
 * can perceive — a native event is picked up within about {@see self::IDLE_SLEEP_US} × 3
 * microseconds, i.e. well under one frame at 120 Hz — and it takes the idle cost to under 1% of a
 * core.
 *
 * The one thing a fixed sleep would slow down is a *burst* of `boson://` requests, where each
 * round trip would pay the same delay again. So the throttle is dropped for
 * {@see self::BUSY_WINDOW} seconds after every {@see wake()} call: `index.php` wakes it each time
 * the front controller answers a request, which means a page's worth of API calls runs at the
 * upstream loop's full speed and only a genuinely quiet app is throttled.
 *
 * It is a decorator rather than a replacement because the poller the parent builds carries a
 * deferred task that flips `Application::$isRunning` and fires `ApplicationStarted` — a closure
 * over state a subclass cannot reach ({@see \Boson\Application::$isRunning} is `private(set)`).
 * Wrapping keeps that, and keeps us out of Boson's `@internal` microtask bookkeeping entirely.
 */
final class EventLoopThrottle implements PollerInterface
{
    /**
     * How long to sleep, in microseconds, after an idle loop iteration.
     */
    private const int IDLE_SLEEP_US = 2000;

    /**
     * How long, in seconds, a {@see wake()} keeps the loop running unthrottled.
     */
    private const float BUSY_WINDOW = 0.1;

    /**
     * `microtime(true)` up to which the throttle stays out of the way.
     */
    private float $busyUntil = 0.0;

    public function __construct(
        private readonly PollerInterface $poller,
    ) {}

    /**
     * Declare the app busy: run the event loop unthrottled for the next {@see self::BUSY_WINDOW}
     * seconds. Called whenever the app does something on behalf of the user, so that a sequence of
     * requests is not charged the idle sleep once per request.
     */
    public function wake(): void
    {
        $this->busyUntil = \microtime(true) + self::BUSY_WINDOW;
    }

    public function next(): void
    {
        $this->poller->next();

        if (\microtime(true) < $this->busyUntil) {
            return;
        }

        \usleep(self::IDLE_SLEEP_US);
    }

    public function createSuspension(): SuspensionInterface
    {
        return $this->poller->createSuspension();
    }

    public function throw(\Throwable $e): int|string
    {
        return $this->poller->throw($e);
    }

    public function defer(callable $task): int|string
    {
        return $this->poller->defer($task);
    }

    public function repeat(callable $task): int|string
    {
        return $this->poller->repeat($task);
    }

    public function delay(float $delay, callable $task): int|string
    {
        return $this->poller->delay($delay, $task);
    }

    public function timer(float $interval, callable $task): int|string
    {
        return $this->poller->timer($interval, $task);
    }

    public function cancel(int|string $taskId): void
    {
        $this->poller->cancel($taskId);
    }
}
