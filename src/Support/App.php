<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Support;

/**
 * Single source of truth for the application's identity and legal metadata.
 *
 * These values are surfaced verbatim in the About dialog. The copyright,
 * licence and the Joomla! trademark disclaimer are legal text and must never
 * be translated, so they live here as constants (not in the language files).
 */
final class App
{
    /** Human-readable application name. */
    public const NAME = 'Grafida';

    /** Application version (semantic versioning). */
    public const VERSION = '0.3';

    /**
     * Oldest macOS version Grafida can run on.
     *
     * ⚠️ This number is **not ours to choose**. boson-php/saucer ships a prebuilt
     * `libboson-darwin-universal.dylib` and the compiler copies it verbatim into every macOS
     * binary; upstream builds it on the latest runner with no `CMAKE_OSX_DEPLOYMENT_TARGET`,
     * so it is linked `minos 15.0` against a libc++ symbol that simply does not exist on
     * earlier systems. On anything older the library cannot be loaded at all and the app dies
     * inside `FFI::cdef()` (gh-58).
     *
     * Three consumers: {@see \Grafida\Startup\StartupCheck} (the runtime pre-flight),
     * `scripts/make-macos-app.sh` (`LSMinimumSystemVersion`, read out of this file with sed),
     * and `DylibDeploymentTargetTest`, which fails the suite if the shipped library's floor
     * ever rises above this value. Re-check on every boson-php bump with
     * `vtool -show-build vendor/boson-php/saucer/bin/libboson-darwin-universal.dylib`.
     */
    public const MIN_MACOS = '15.0';

    /**
     * {@see MIN_MACOS} as a person would say it, for the start-up alerts.
     *
     * Apple's marketing name cannot be derived from the number, so it is written out — and it
     * must move whenever `MIN_MACOS` does, which is why the two sit together.
     */
    public const MIN_MACOS_NAME = 'macOS 15 Sequoia';

    /** Copyright line. */
    public const COPYRIGHT = 'Copyright © 2026 Nicholas K. Dionysopoulos';

    /** Licence name. */
    public const LICENSE = 'GNU General Public License version 3, or later';

    /** Canonical URL of the full licence text on the FSF's website. */
    public const LICENSE_URL = 'https://www.gnu.org/licenses/gpl-3.0.html';

    /**
     * Joomla! trademark disclaimer. Required, must be displayed verbatim, and
     * must never be translated.
     */
    public const JOOMLA_DISCLAIMER = 'This application is not affiliated with or endorsed by the Joomla! Project. It is not supported or warranted by the Joomla! Project or Open Source Matters. The Joomla! logo is used under a limited license granted by Open Source Matters, the trademark holder in the United States and other countries.';

    /**
     * The metadata payload sent to the front-end at start-up.
     *
     * @return array{name: string, version: string, copyright: string, license: string, licenseUrl: string, disclaimer: string}
     */
    public static function info(): array
    {
        return [
            'name'       => self::NAME,
            'version'    => self::VERSION,
            'copyright'  => self::COPYRIGHT,
            'license'    => self::LICENSE,
            'licenseUrl' => self::LICENSE_URL,
            'disclaimer' => self::JOOMLA_DISCLAIMER,
        ];
    }
}
