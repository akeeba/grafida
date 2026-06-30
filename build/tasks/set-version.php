<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

/*
 * Stamp the application version (Grafida\Support\App::VERSION) from the CHANGELOG.
 *
 * The CHANGELOG is the single source of truth for the version: its topmost entry's
 * heading ends with the version number, following the Akeeba convention
 * (e.g. "Grafida 0.1"). This reads that version and writes it into src/Support/App.php
 * so the compiled binary and the SPA's About dialog report it. Run by the build.xml
 * git-* targets (via `prepare`) before every compile.
 *
 * The version is parsed the same way Akeeba's AutoVersionTask does: take the first
 * non-empty line of the CHANGELOG, keep its last whitespace-delimited token, and pull
 * the version number out of it. An optional GRAFIDA_VERSION env var overrides the
 * CHANGELOG (mirroring the build scripts' fallback).
 *
 * Usage:  php build/tasks/set-version.php
 */

$root      = \dirname(__DIR__, 2);
$changelog = $root . '/CHANGELOG';
$appFile   = $root . '/src/Support/App.php';

$override = \getenv('GRAFIDA_VERSION');
$version  = ($override !== false && $override !== '') ? \trim($override) : changelogVersion($changelog);

if ($version === null || $version === '')
{
    \fwrite(\STDERR, "ERROR: could not determine a version (no GRAFIDA_VERSION and no readable version in {$changelog}).\n");
    exit(1);
}

// `--print` just emits the resolved version (no trailing newline) so build.xml can capture it into a
// property via <exec outputProperty="…">. It does not touch App.php.
if (\in_array('--print', $argv, true))
{
    \fwrite(\STDOUT, $version);
    exit(0);
}

if (!\is_file($appFile))
{
    \fwrite(\STDERR, "ERROR: {$appFile} not found.\n");
    exit(1);
}

$source = (string) \file_get_contents($appFile);

// Replace the value of:  public const VERSION = '…';
$pattern = "/(public\\s+const\\s+VERSION\\s*=\\s*')([^']*)(';)/";

if (!\preg_match($pattern, $source, $m))
{
    \fwrite(\STDERR, "ERROR: could not find the VERSION constant in {$appFile}.\n");
    exit(1);
}

if ($m[2] === $version)
{
    \fwrite(\STDOUT, "Application version already {$version} — nothing to do.\n");
    exit(0);
}

$updated = \preg_replace($pattern, '${1}' . $version . '${3}', $source, 1);

if ($updated === null || $updated === $source)
{
    \fwrite(\STDERR, "ERROR: failed to rewrite the VERSION constant in {$appFile}.\n");
    exit(1);
}

\file_put_contents($appFile, $updated);
\fwrite(\STDOUT, "Application version set to {$version} (was {$m[2]}).\n");
exit(0);

/**
 * Extract the version from the topmost CHANGELOG entry: the first non-empty line's
 * last whitespace-delimited token, reduced to its version number. Returns null when
 * the file is missing/empty or no version-looking token is found.
 */
function changelogVersion(string $path): ?string
{
    if (!\is_file($path))
    {
        return null;
    }

    $content = (string) \file_get_contents($path);
    $lines   = \array_filter(\array_map('trim', \explode("\n", $content)), static fn (string $l): bool => $l !== '');

    if ($lines === [])
    {
        return null;
    }

    $firstLine = \array_shift($lines);

    // Drop a leading "<?php die();" guard line if a project ever adds one.
    if (\str_contains($firstLine, '<?'))
    {
        $firstLine = \array_shift($lines) ?? '';
    }

    $parts = \preg_split('/\s+/', \trim($firstLine)) ?: [];
    $token = \end($parts) ?: '';

    if (!\preg_match('/((\d+\.?)+)(((a|alpha|b|beta|rc|dev)\d)*(-[^\s]*)?)?/', $token, $m))
    {
        return null;
    }

    return \rtrim($m[0], '.');
}
