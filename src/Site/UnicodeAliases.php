<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Site;

/**
 * The per-site "Site uses Unicode Aliases" setting (gh-61).
 *
 * Joomla's `unicodeslugs` Global Configuration option decides which of the two
 * alias algorithms a title is slugified with, and reading it over the API needs
 * `core.admin` — which a token belonging to anything less than a Super User does
 * not have. Before this, such a site silently fell back to "off" and a Greek or
 * Chinese title produced a timestamp alias with no explanation. So the value is
 * a **tri-state**: `auto` (ask the site, the previous behaviour and still the
 * default) plus a straight `yes`/`no` the user can state themselves.
 *
 * There is deliberately no enum: the value is a plain TEXT column, may be NULL
 * on rows written before the migration, and is echoed to the SPA as-is.
 */
final class UnicodeAliases
{
    /** Read `unicodeslugs` from the site; fall back to Joomla's default (off). */
    public const AUTO = 'auto';

    /** The site has Unicode Aliases turned on; do not ask it. */
    public const YES = 'yes';

    /** The site has Unicode Aliases turned off; do not ask it. */
    public const NO = 'no';

    /** @var list<string> Every accepted value, in the order the form offers them. */
    public const AVAILABLE = [self::AUTO, self::YES, self::NO];

    /**
     * Snaps any stored or posted value onto one of {@see AVAILABLE}.
     *
     * NULL, the empty string and anything unrecognised become {@see AUTO} —
     * the pre-gh-61 behaviour, which is what a row written before the migration
     * (and a form that did not send the field) has to mean.
     */
    public static function normalise(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, self::AVAILABLE, true) ? $value : self::AUTO;
    }
}
