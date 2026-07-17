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
 * Stores whether the editor's slash-command menu is enabled.
 *
 * Typing "/" in the editor opens a filterable command menu. It is on by default
 * — it is a productivity win for keyboard-driven authors — but prose that
 * contains a lot of slashes makes the popup a nuisance, so it can be switched
 * off globally from Settings.
 */
final class SlashToolsService
{
    public const SETTING_KEY = 'slash_tools';

    public function __construct(private readonly SettingsRepository $settings) {}

    /** The stored preference, defaulting to enabled. */
    public function current(): bool
    {
        return ($this->settings->get(self::SETTING_KEY, '1') ?? '1') !== '0';
    }

    /** Persists and returns the preference. */
    public function set(bool $enabled): bool
    {
        $this->settings->set(self::SETTING_KEY, $enabled ? '1' : '0');

        return $enabled;
    }
}
