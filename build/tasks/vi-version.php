<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

/*
 * Convert a human Grafida version (from the CHANGELOG / GRAFIDA_VERSION, e.g.
 * "0.1", "1.2.3-beta4", "2.0rc1") into the four-numeric-component version
 * NSIS's VIProductVersion directive requires (Windows rejects anything else —
 * see "invalid VIProductVersion format, should be X.X.X.X").
 *
 * major.minor.patch are taken from the version's leading numeric run, padded
 * with trailing .0s to three components. The fourth component encodes
 * stability, so a pre-release always sorts below the stable release that
 * shares its major.minor.patch:
 *
 *   alpha N (1-10)   -> 0-9     (up to 10 alphas)
 *   beta  N (1-91)   -> 10-100  (up to ~90 betas)
 *   rc    N (1-100)  -> 900-999 (up to ~99 release candidates)
 *   stable           -> 1000    (always sorts above every pre-release)
 *
 * Every 0.x version is treated as alpha even without an explicit suffix
 * (0.x is inherently pre-1.0/unstable); an explicit -alpha/-beta/-rc suffix
 * on a 0.x version still wins and is encoded normally.
 *
 * Usage: php build/tasks/vi-version.php <version>
 */

$version = $argv[1] ?? '';

if (\trim($version) === '')
{
    \fwrite(\STDERR, "ERROR: usage: php build/tasks/vi-version.php <version>\n");
    exit(1);
}

try
{
    echo viProductVersion($version);
}
catch (\InvalidArgumentException $e)
{
    \fwrite(\STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);

function viProductVersion(string $version): string
{
    $version = \trim($version);

    if (!\preg_match('/^(\d+(?:\.\d+){0,3})(.*)$/', $version, $m))
    {
        throw new \InvalidArgumentException("'{$version}' does not start with a numeric version.");
    }

    $numeric = \explode('.', $m[1]);
    $rest    = \trim($m[2]);

    // major.minor.patch, padded with trailing .0s; a leading 4th numeric part (if
    // present) is dropped — the 4th component is always the stability tier below.
    $major = $numeric[0] ?? '0';
    $minor = $numeric[1] ?? '0';
    $patch = $numeric[2] ?? '0';

    $tier = stabilityTier($rest, (int) $major);

    return "{$major}.{$minor}.{$patch}.{$tier}";
}

function stabilityTier(string $rest, int $major): int
{
    if ($rest === '')
    {
        // A bare 0.x version is implicitly alpha (nothing below 1.0 is stable),
        // even though nothing in the version string says so.
        return $major === 0 ? 0 : 1000;
    }

    if (!\preg_match('/^[.\-]?(alpha|a|beta|b|rc|dev)\.?(\d+)?$/i', $rest, $m))
    {
        throw new \InvalidArgumentException("Unrecognised pre-release suffix '{$rest}' (expected alpha/a, beta/b, rc or dev, e.g. '-beta2').");
    }

    $kind = \strtolower($m[1]);
    $n    = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 1;

    if ($n < 1)
    {
        throw new \InvalidArgumentException("Pre-release number must be >= 1, got {$n}.");
    }

    return match ($kind)
    {
        'a', 'alpha', 'dev' => clampTier($n - 1, 0, 9, 'alpha'),
        'b', 'beta'         => clampTier(9 + $n, 10, 100, 'beta'),
        'rc'                => clampTier(899 + $n, 900, 999, 'rc'),
        default             => throw new \InvalidArgumentException("Unhandled pre-release kind '{$kind}'."),
    };
}

function clampTier(int $value, int $min, int $max, string $kind): int
{
    if ($value < $min || $value > $max)
    {
        \fwrite(\STDERR, "WARNING: {$kind} number out of the supported range — clamping VIProductVersion tier {$value} to [{$min}, {$max}].\n");

        return \max($min, \min($max, $value));
    }

    return $value;
}
