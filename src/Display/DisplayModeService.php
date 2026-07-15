<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Display;

use Grafida\Secret\ProcessRunner;
use Grafida\Storage\SettingsRepository;

/**
 * Stores the user's interface display-mode preference.
 *
 * The stored value is one of {@see self::AVAILABLE}; "auto" follows the
 * operating system's light/dark preference. The SPA would normally resolve
 * "auto" against `prefers-color-scheme`, but Boson's webview does not reliably
 * sync that media feature to the OS appearance (on macOS it reports dark
 * regardless of the system setting), so {@see self::systemPrefersDark()} probes
 * the OS directly and the result is handed to the SPA as the source of truth.
 */
final class DisplayModeService
{
    public const AUTO        = 'auto';
    public const LIGHT       = 'light';
    public const DARK        = 'dark';
    public const SETTING_KEY = 'display_mode';

    /** @var list<string> */
    public const AVAILABLE = [self::AUTO, self::LIGHT, self::DARK];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ProcessRunner $runner = new ProcessRunner(),
        private readonly WindowsThemeReader $windowsTheme = new WindowsThemeReader(),
    ) {}

    /** The stored preference, defaulting to "auto". */
    public function current(): string
    {
        $mode = $this->settings->get(self::SETTING_KEY, self::AUTO) ?? self::AUTO;

        return \in_array($mode, self::AVAILABLE, true) ? $mode : self::AUTO;
    }

    /** Persists and returns the (validated) preference. */
    public function set(string $mode): string
    {
        $mode = \in_array($mode, self::AVAILABLE, true) ? $mode : self::AUTO;
        $this->settings->set(self::SETTING_KEY, $mode);

        return $mode;
    }

    /**
     * Probes the operating system's light/dark appearance preference.
     *
     * Returns true for dark, false for light, or null when the preference
     * cannot be determined (in which case the SPA falls back to
     * `prefers-color-scheme`). Best-effort and silent: any failure is treated
     * as "unknown".
     */
    public function systemPrefersDark(): ?bool
    {
        return match (\PHP_OS_FAMILY) {
            'Darwin'  => $this->macPrefersDark(),
            'Windows' => $this->windowsPrefersDark(),
            default   => $this->linuxPrefersDark(),
        };
    }

    /**
     * macOS exposes the global appearance through the `AppleInterfaceStyle`
     * default, which is set to "Dark" in dark mode and simply absent (the
     * command exits non-zero) in light mode.
     */
    private function macPrefersDark(): bool
    {
        [$code, $stdout] = $this->runner->run(['defaults', 'read', '-g', 'AppleInterfaceStyle']);

        if ($code !== 0) {
            // The key is missing in light mode — that is a definite "light".
            return false;
        }

        return stripos(trim($stdout), 'dark') !== false;
    }

    /**
     * Windows stores the apps appearance under the Personalize registry key:
     * `AppsUseLightTheme` is a DWORD, 0 for dark and 1 for light.
     *
     * Read it natively via FFI (no subprocess) whenever possible — this probe
     * runs on every window focus and a `reg.exe` spawn can flash a console
     * window. Fall back to `reg.exe` only when FFI is unavailable.
     */
    private function windowsPrefersDark(): ?bool
    {
        if ($this->windowsTheme->available()) {
            return $this->windowsTheme->prefersDark();
        }

        [$code, $stdout] = $this->runner->run([
            'reg', 'query',
            'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Themes\\Personalize',
            '/v', 'AppsUseLightTheme',
        ]);

        if ($code !== 0 || preg_match('/0x([0-9a-fA-F]+)/', $stdout, $m) !== 1) {
            return null;
        }

        return hexdec($m[1]) === 0;
    }

    /**
     * GNOME/freedesktop expose the preference via gsettings; a `color-scheme`
     * of "prefer-dark" (or a dark GTK theme name) means dark mode.
     */
    private function linuxPrefersDark(): ?bool
    {
        [$code, $stdout] = $this->runner->run([
            'gsettings', 'get', 'org.gnome.desktop.interface', 'color-scheme',
        ]);

        if ($code === 0) {
            $value = strtolower(trim($stdout));

            if (str_contains($value, 'dark')) {
                return true;
            }

            if (str_contains($value, 'light') || str_contains($value, 'default')) {
                return false;
            }
        }

        [$code, $stdout] = $this->runner->run([
            'gsettings', 'get', 'org.gnome.desktop.interface', 'gtk-theme',
        ]);

        if ($code === 0) {
            return str_contains(strtolower(trim($stdout)), 'dark');
        }

        return null;
    }
}
