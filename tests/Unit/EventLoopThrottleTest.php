<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Boson\Poller\PollerInterface;
use Boson\Poller\SuspensionInterface;
use Grafida\Application\EventLoopThrottle;
use PHPUnit\Framework\TestCase;

/**
 * The throttle keeps Boson's busy event loop from burning a CPU core while the app is idle.
 *
 * The numbers here are the ones that matter in practice: an idle iteration must actually sleep
 * (that is the whole point), and an iteration right after a wake() must not (that is what keeps a
 * burst of boson:// requests fast).
 */
final class EventLoopThrottleTest extends TestCase
{
    public function testIdleIterationSleeps(): void
    {
        $inner    = new SpyPoller();
        $throttle = new EventLoopThrottle($inner);

        $started = microtime(true);
        $throttle->next();
        $elapsed = microtime(true) - $started;

        $this->assertSame(1, $inner->iterations, 'The inner poller still runs the loop iteration');
        $this->assertGreaterThan(
            0.001,
            $elapsed,
            'An idle iteration sleeps, so the loop does not spin at full speed'
        );
    }

    public function testIterationAfterWakeDoesNotSleep(): void
    {
        $inner    = new SpyPoller();
        $throttle = new EventLoopThrottle($inner);

        $throttle->wake();

        $started = microtime(true);

        for ($i = 0; $i < 5; $i++) {
            $throttle->next();
        }

        $elapsed = microtime(true) - $started;

        $this->assertSame(5, $inner->iterations);
        $this->assertLessThan(
            0.005,
            $elapsed,
            'A woken loop runs unthrottled, so a burst of requests is not delayed'
        );
    }

    public function testThrottleResumesAfterTheBusyWindowLapses(): void
    {
        $inner    = new SpyPoller();
        $throttle = new EventLoopThrottle($inner);

        $throttle->wake();
        // The busy window is 100 ms.
        usleep(150_000);

        $started = microtime(true);
        $throttle->next();
        $elapsed = microtime(true) - $started;

        $this->assertGreaterThan(0.001, $elapsed, 'The busy window expires; the throttle comes back');
    }

    public function testTaskSchedulingIsDelegatedToTheInnerPoller(): void
    {
        $inner    = new SpyPoller();
        $throttle = new EventLoopThrottle($inner);

        $task = static function (): void {};

        $this->assertSame('defer', $throttle->defer($task));
        $this->assertSame('repeat', $throttle->repeat($task));
        $this->assertSame('delay', $throttle->delay(1.0, $task));
        $this->assertSame('timer', $throttle->timer(1.0, $task));

        $throttle->cancel('defer');

        $this->assertSame(['defer'], $inner->cancelled);
    }
}

/**
 * A poller that records what it was asked to do and does nothing else — in particular, it does not
 * sleep, so the timings the test asserts on are the throttle's own.
 */
final class SpyPoller implements PollerInterface
{
    public int $iterations = 0;

    /** @var list<int|string> */
    public array $cancelled = [];

    public function next(): void
    {
        $this->iterations++;
    }

    public function createSuspension(): SuspensionInterface
    {
        throw new \LogicException('Not used by the throttle test');
    }

    public function throw(\Throwable $e): int|string
    {
        return 'throw';
    }

    public function defer(callable $task): int|string
    {
        return 'defer';
    }

    public function repeat(callable $task): int|string
    {
        return 'repeat';
    }

    public function delay(float $delay, callable $task): int|string
    {
        return 'delay';
    }

    public function timer(float $interval, callable $task): int|string
    {
        return 'timer';
    }

    public function cancel(int|string $taskId): void
    {
        $this->cancelled[] = $taskId;
    }
}
