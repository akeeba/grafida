<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Secret;

/**
 * Runs an external command without going through a shell, passing arguments as
 * an array (so values never need shell-escaping) and an optional stdin string.
 *
 * Extracted so the OS secret stores can be unit-tested with a fake runner.
 */
class ProcessRunner
{
    /**
     * @param list<string> $command Program plus arguments.
     * @param string|null  $stdin   Data to write to the process' standard input.
     *
     * @return array{0: int, 1: string, 2: string} [exitCode, stdout, stderr]
     */
    public function run(array $command, ?string $stdin = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);

        if (!\is_resource($process)) {
            return [127, '', 'Unable to start process: ' . ($command[0] ?? '')];
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdoutResult = stream_get_contents($pipes[1]);
        $stderrResult = stream_get_contents($pipes[2]);
        $stdout       = $stdoutResult !== false ? $stdoutResult : '';
        $stderr       = $stderrResult !== false ? $stderrResult : '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, $stdout, $stderr];
    }

    /** Whether an executable is present on PATH. */
    public function exists(string $program): bool
    {
        $probe = \PHP_OS_FAMILY === 'Windows' ? 'where' : 'command';
        $args  = \PHP_OS_FAMILY === 'Windows' ? [$probe, $program] : [$probe, '-v', $program];

        // `command -v` is a shell builtin; fall back to `which`.
        if (\PHP_OS_FAMILY !== 'Windows') {
            [$code] = $this->run(['/usr/bin/which', $program]);

            return $code === 0;
        }

        [$code] = $this->run($args);

        return $code === 0;
    }
}
