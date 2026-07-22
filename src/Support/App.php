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
