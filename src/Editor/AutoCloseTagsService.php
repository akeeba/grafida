<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Editor;

use Grafida\Storage\SettingsRepository;

/**
 * Stores how aggressively the source-code editor closes HTML tags for you.
 *
 * CodeMirror's `closetag` addon has two independent halves, and this preference
 * is a three-way choice rather than an on/off toggle because they are genuinely
 * useful separately (gh-52):
 *
 * - {@see self::FULL} — both halves. Typing the `>` of `<p>` inserts `</p>`,
 *   and typing `</` completes the nearest open tag.
 * - {@see self::CLOSING} — only the `</` completion. This is the mode for
 *   someone editing existing markup, where an auto-inserted closing tag is
 *   almost always something to delete again, but completing a closing tag you
 *   asked for by typing `</` never gets in the way.
 * - {@see self::OFF} — neither.
 *
 * The SPA maps these onto the addon's `autoCloseTags` option (`true`,
 * `{whenOpening: false}`, `false`). An on/off toggle would have had to make
 * "off" mean "off, except for the `</` completion", which is a lie a label
 * cannot carry.
 *
 * Mirrors {@see SlashToolsService}'s shape — the `settings` table is a generic
 * key/value store, so a new preference needs no migration.
 */
final class AutoCloseTagsService
{
    public const FULL        = 'full';
    public const CLOSING     = 'closing';
    public const OFF         = 'off';
    public const SETTING_KEY = 'auto_close_tags';

    /** @var list<string> */
    public const AVAILABLE = [self::FULL, self::CLOSING, self::OFF];

    public function __construct(private readonly SettingsRepository $settings) {}

    /** The stored preference, defaulting to the full behaviour. */
    public function current(): string
    {
        $mode = $this->settings->get(self::SETTING_KEY, self::FULL) ?? self::FULL;

        return \in_array($mode, self::AVAILABLE, true) ? $mode : self::FULL;
    }

    /** Persists and returns the (validated) preference. */
    public function set(string $mode): string
    {
        $mode = \in_array($mode, self::AVAILABLE, true) ? $mode : self::FULL;
        $this->settings->set(self::SETTING_KEY, $mode);

        return $mode;
    }
}
