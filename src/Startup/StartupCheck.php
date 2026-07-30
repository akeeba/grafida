<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Startup;

use Grafida\Secret\ProcessRunner;
use Grafida\Support\App;

/**
 * Pre-flight checks that run before the Boson application — and therefore before the webview,
 * the container, the database and the SPA — exists.
 *
 * There is exactly one at the moment: the bundled webview library cannot be loaded on macOS
 * older than {@see App::MIN_MACOS}, and without this check the app dies with an uncaught
 * `FFI\Exception` that a Finder-launched bundle throws away unseen (gh-58).
 *
 * `LSMinimumSystemVersion` in the app bundle's `Info.plist` already makes LaunchServices refuse
 * the launch with Apple's own alert, which is the better experience — but it only covers the
 * `.app`. The PHAR distribution has no bundle at all, and running `Contents/MacOS/grafida`
 * straight from a terminal bypasses LaunchServices, so the check has to exist here too.
 *
 * ⚠️ Anything reported from here is **hard-coded English**. This runs before the SPA, so
 * `UiStrings` and the language files are not reachable yet; adding keys for it would ship
 * strings nothing can read.
 */
final class StartupCheck
{
    /**
     * EX_UNAVAILABLE: a component this build needs is not available on this machine.
     *
     * Distinct from PHP's own 255 on a fatal error and from a plain 1, so a wrapper script can
     * tell "this OS cannot run Grafida" from "Grafida crashed".
     */
    public const EXIT_UNSUPPORTED = 69;

    /**
     * Points Boson at a webview library of the user's own, and — because saying so is a
     * statement that you know what you are doing — switches the macOS version gate off.
     */
    public const LIBRARY_ENV = 'GRAFIDA_BOSON_LIBRARY';

    /** Where macOS records its product version. Readable without a subprocess. */
    private const VERSION_PLIST = '/System/Library/CoreServices/SystemVersion.plist';

    /** Generous cap on the plist read; the real file is well under a kilobyte. */
    private const VERSION_PLIST_MAX = 8192;

    public function __construct(
        private readonly ProcessRunner $runner = new ProcessRunner(),
        private readonly string $versionPlist = self::VERSION_PLIST,
    ) {}

    /**
     * A ready-to-show explanation when this Mac is too old to run Grafida, else null.
     *
     * ⚠️ Deliberately fails **open** at every step: an undetectable or unparseable version
     * lets the launch proceed. Refusing to start on a machine we merely could not identify
     * would be a worse bug than the one this guards against, and a genuine load failure a
     * moment later is still caught and explained by {@see FailureReporter}.
     */
    public function unsupportedMacOs(): ?string
    {
        if (\PHP_OS_FAMILY !== 'Darwin' || $this->libraryOverride() !== null) {
            return null;
        }

        $running = $this->macOsVersion();

        if ($running === null || self::isAtLeast($running, App::MIN_MACOS)) {
            return null;
        }

        return self::message($running);
    }

    /**
     * An explicit webview library path for {@see \Boson\ApplicationCreateInfo::$library}, or
     * null to let Boson find the one it ships.
     *
     * The escape hatch for the situation gh-58 describes: the shipped library's macOS floor is
     * upstream's to set, not ours, so someone who re-links it themselves can point Grafida at
     * their copy instead of being told their Mac is unsupported. A path that does not exist is
     * ignored, so a typo degrades to Boson's own detection rather than to a dlopen error.
     *
     * ⚠️ It cannot lift the `Info.plist` gate: `LSMinimumSystemVersion` is enforced by
     * LaunchServices before PHP runs, so a Finder double-click of the shipped `.app` is still
     * refused. The binary inside the bundle, and the PHAR, are what this unlocks.
     *
     * @return non-empty-string|null
     */
    public function libraryOverride(): ?string
    {
        $path = getenv(self::LIBRARY_ENV);

        if (!\is_string($path)) {
            return null;
        }

        $path = trim($path);

        if ($path === '' || !is_file($path)) {
            return null;
        }

        return $path;
    }

    /**
     * This Mac's macOS product version, or null when it cannot be established.
     *
     * The plist is a plain file read, so the common path spawns nothing — and it is the more
     * trustworthy of the two sources: the macOS 11 compatibility shim that makes an old binary
     * see `10.16` works by substituting `SystemVersionCompat.plist` for callers of the version
     * APIs, which a direct read of the real file is not subject to. `sw_vers` covers a system
     * where that file has moved or cannot be read.
     */
    public function macOsVersion(): ?string
    {
        $plist = @file_get_contents($this->versionPlist, false, null, 0, self::VERSION_PLIST_MAX);

        if (\is_string($plist)) {
            $version = self::productVersion($plist);

            if ($version !== null) {
                return $version;
            }
        }

        [$code, $stdout] = $this->runner->run(['/usr/bin/sw_vers', '-productVersion']);
        $version         = trim($stdout);

        if ($code === 0 && $version !== '') {
            return $version;
        }

        return null;
    }

    /**
     * `ProductVersion` out of a `SystemVersion.plist` document, or null if it is not there.
     *
     * Pure and public so the parsing can be unit-tested from a fixture instead of only on the
     * machine the tests happen to run on.
     */
    public static function productVersion(string $plist): ?string
    {
        $matched = preg_match(
            '#<key>\s*ProductVersion\s*</key>\s*<string>\s*([^<\s]+)\s*</string>#',
            $plist,
            $matches
        );

        // The capture group cannot match an empty string, so a match is always a usable value.
        return $matched === 1 ? $matches[1] : null;
    }

    /**
     * Whether $running is at least $required. Pure; the comparison the gate turns on.
     *
     * ⚠️ Two traps, both pinned by {@see \Grafida\Tests\Unit\Startup\StartupCheckTest}:
     *
     * `version_compare('15', '15.0', '>=')` is **false** — a missing component sorts *below*
     * `0` — so both sides are padded to three components first. And an **unparseable** running
     * version is reported as supported, not as too old: this is the fail-open policy of
     * {@see unsupportedMacOs()}, and putting it here keeps it in one place. Do not "harden" it.
     */
    public static function isAtLeast(string $running, string $required): bool
    {
        $left  = self::normalise($running);
        $right = self::normalise($required);

        if ($left === null || $right === null) {
            return true;
        }

        return version_compare($left, $right, '>=');
    }

    /** A dotted version padded to three numeric components, or null if it is not one. */
    private static function normalise(string $version): ?string
    {
        $version = trim($version);

        if (preg_match('/^\d+(?:\.\d+){0,2}$/', $version) !== 1) {
            return null;
        }

        $parts = array_pad(explode('.', $version), 3, '0');

        return implode('.', $parts);
    }

    /** The unsupported-macOS explanation shown to the user. */
    private static function message(string $running): string
    {
        return \sprintf(
            "Grafida needs %s or later. This Mac is running macOS %s.\n\n"
            . 'The webview library Grafida is built on cannot be loaded by an earlier macOS, so '
            . 'this build cannot run here. Updating macOS is the only fix.',
            App::MIN_MACOS_NAME,
            $running
        );
    }
}
