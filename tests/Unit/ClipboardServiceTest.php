<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Clipboard\ClipboardService;
use Grafida\Clipboard\WindowsClipboard;
use Grafida\Secret\ProcessRunner;

final class ClipboardServiceTest extends TestCase
{
    /**
     * A runner that answers one named program and reports every other as
     * missing, so a test can pin which tool the service reached for.
     */
    private function runnerFor(string $program, int $code, string $stdout): ProcessRunner
    {
        return new class ($program, $code, $stdout) extends ProcessRunner {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(
                private readonly string $program,
                private readonly int $code,
                private readonly string $stdout,
            ) {}

            public function run(array $command, ?string $stdin = null): array
            {
                $this->calls[] = $command[0];

                return str_contains($command[0], $this->program)
                    ? [$this->code, $this->stdout, '']
                    : [127, '', 'not found'];
            }

            public function exists(string $program): bool
            {
                return $program === $this->program;
            }
        };
    }

    public function testReadsTheClipboardThroughThePlatformTool(): void
    {
        $program = match (\PHP_OS_FAMILY) {
            'Darwin'  => 'pbpaste',
            'Windows' => 'powershell',
            default   => 'wl-paste',
        };

        $service = new ClipboardService($this->runnerFor($program, 0, "hello\nthere"));

        $this->assertSame("hello\nthere", $service->readText());
    }

    /**
     * An empty clipboard is a successful read of nothing, and must stay
     * distinguishable from a failure — the SPA pastes nothing for the former and
     * reports an error for the latter.
     */
    public function testAnEmptyClipboardIsAnEmptyStringNotNull(): void
    {
        $program = match (\PHP_OS_FAMILY) {
            'Darwin'  => 'pbpaste',
            'Windows' => 'powershell',
            default   => 'wl-paste',
        };

        $service = new ClipboardService($this->runnerFor($program, 0, ''));

        $this->assertSame('', $service->readText());
    }

    public function testAFailedReadIsNull(): void
    {
        $service = new ClipboardService($this->runnerFor('nothing-matches', 1, ''));

        $this->assertNull($service->readText());
    }

    /**
     * On Windows the native FFI read must be preferred and PowerShell never
     * spawned when it succeeds — a `powershell.exe` cold start is about a second
     * and this runs under a keystroke on a single-threaded kernel.
     */
    public function testWindowsPrefersTheNativeReadOverPowerShell(): void
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only fallback ordering.');
        }

        $runner  = $this->runnerFor('powershell', 0, 'from powershell');
        $service = new ClipboardService($runner, $this->fakeWindowsClipboard(true, 'from ffi'));

        $this->assertSame('from ffi', $service->readText());
        $this->assertSame([], $runner->calls, 'PowerShell must not be spawned');
    }

    /** A host without FFI still gets its clipboard, through PowerShell. */
    public function testWindowsFallsBackToPowerShellWhenFfiIsUnavailable(): void
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only fallback ordering.');
        }

        $service = new ClipboardService(
            $this->runnerFor('powershell', 0, 'from powershell'),
            $this->fakeWindowsClipboard(false, null)
        );

        $this->assertSame('from powershell', $service->readText());
    }

    private function fakeWindowsClipboard(bool $available, ?string $text): WindowsClipboard
    {
        return new class ($available, $text) extends WindowsClipboard {
            public function __construct(
                private readonly bool $isAvailable,
                private readonly ?string $text,
            ) {}

            public function available(): bool
            {
                return $this->isAvailable;
            }

            public function readText(): ?string
            {
                return $this->text;
            }
        };
    }

    /**
     * TinyMCE's plain-text-to-HTML conversion splits on \n, so a Windows or
     * classic-Mac line ending has to be normalised or it survives as a control
     * character inside the pasted paragraph.
     */
    public function testNormalisesLineEndings(): void
    {
        $program = match (\PHP_OS_FAMILY) {
            'Darwin'  => 'pbpaste',
            'Windows' => 'powershell',
            default   => 'wl-paste',
        };

        $service = new ClipboardService($this->runnerFor($program, 0, "a\r\nb\rc\nd"));

        $this->assertSame("a\nb\nc\nd", $service->readText());
    }
}
