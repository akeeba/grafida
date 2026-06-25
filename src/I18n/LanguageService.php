<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\I18n;

use Grafida\Storage\SettingsRepository;
use Joomla\Language\Language;
use Joomla\Language\LanguageFactory;

/**
 * Resolves the interface language and translates Joomla INI strings.
 *
 * The effective language is, in order of precedence:
 *   1. the user's explicit override (a tag, when not "auto");
 *   2. the operating system language, if it is one Grafida ships;
 *   3. en-GB (the canonical fallback).
 *
 * Strings are loaded with the {@see https://github.com/joomla-framework/language joomla/language}
 * package from the `com_grafida` extension. Missing keys fall back to en-GB.
 */
final class LanguageService
{
    public const DEFAULT_TAG = 'en-GB';
    public const AUTO        = 'auto';
    public const SETTING_KEY = 'ui_language';

    /** Languages the application ships, as tag => endonym. */
    public const AVAILABLE = [
        'en-GB' => 'English (United Kingdom)',
        'el-GR' => 'Ελληνικά (Ελλάδα)',
        'fr-FR' => 'Français (France)',
        'de-DE' => 'Deutsch (Deutschland)',
        'es-ES' => 'Español (España)',
        'it-IT' => 'Italiano (Italia)',
        'pt-PT' => 'Português (Portugal)',
    ];

    private const EXTENSION = 'com_grafida';

    private ?Language $language = null;
    private ?Language $fallback = null;
    private ?string $resolvedTag = null;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly string $basePath,
    ) {}

    /** The language tag actually in use after applying the precedence rules. */
    public function currentTag(): string
    {
        if ($this->resolvedTag === null) {
            $this->resolvedTag = $this->resolveTag();
        }

        return $this->resolvedTag;
    }

    /** The stored override ("auto" when auto-detecting). */
    public function override(): string
    {
        return $this->settings->get(self::SETTING_KEY, self::AUTO) ?? self::AUTO;
    }

    /** Sets and persists the language override (use self::AUTO for auto-detect). */
    public function setOverride(string $tag): void
    {
        $tag = $tag === self::AUTO || isset(self::AVAILABLE[$tag]) ? $tag : self::AUTO;
        $this->settings->set(self::SETTING_KEY, $tag);

        // Force re-resolution on the next translate().
        $this->resolvedTag = null;
        $this->language    = null;
    }

    /** Translates a language key, falling back to en-GB and then the key itself. */
    public function translate(string $key): string
    {
        $this->ensureLoaded();

        if ($this->language !== null && $this->language->hasKey($key)) {
            return $this->language->_($key);
        }

        if ($this->fallback !== null && $this->fallback->hasKey($key)) {
            return $this->fallback->_($key);
        }

        return $key;
    }

    /**
     * Returns every translated string as a flat map, for shipping to the
     * front-end in one call.
     *
     * @param list<string> $keys Keys to resolve.
     *
     * @return array<string, string>
     */
    public function strings(array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            $out[$key] = $this->translate($key);
        }

        return $out;
    }

    private function ensureLoaded(): void
    {
        if ($this->language !== null) {
            return;
        }

        $factory = new LanguageFactory();
        $factory->setLanguageDirectory($this->basePath);

        $this->fallback = $factory->getLanguage(self::DEFAULT_TAG, $this->basePath);
        $this->fallback->load(self::EXTENSION, $this->basePath, self::DEFAULT_TAG);

        $tag = $this->currentTag();

        if ($tag === self::DEFAULT_TAG) {
            $this->language = $this->fallback;

            return;
        }

        $this->language = $factory->getLanguage($tag, $this->basePath);
        $this->language->load(self::EXTENSION, $this->basePath, $tag);
    }

    private function resolveTag(): string
    {
        $override = $this->override();

        if ($override !== self::AUTO && isset(self::AVAILABLE[$override])) {
            return $override;
        }

        $detected = $this->detectOsLanguage();

        return $detected !== null && isset(self::AVAILABLE[$detected]) ? $detected : self::DEFAULT_TAG;
    }

    /**
     * Best-effort detection of the OS language as one of our tags (e.g. "el-GR").
     */
    private function detectOsLanguage(): ?string
    {
        $lcAll      = getenv('LC_ALL');
        $lcMessages = getenv('LC_MESSAGES');
        $lang       = getenv('LANG');
        $raw        = $lcAll !== false && $lcAll !== '' ? $lcAll
            : ($lcMessages !== false && $lcMessages !== '' ? $lcMessages
            : ($lang !== false && $lang !== '' ? $lang : ''));

        if ($raw === '' && \function_exists('locale_get_default')) {
            $raw = locale_get_default();
        }

        if ($raw === '') {
            return null;
        }

        // Normalise e.g. "el_GR.UTF-8" or "el-GR" -> "el-GR".
        $raw = str_replace('_', '-', $raw);
        $raw = explode('.', $raw)[0];
        $parts = explode('-', $raw);

        if (\count($parts) < 2) {
            // Match by language part alone (e.g. "fr" -> "fr-FR").
            $lang = strtolower($parts[0]);
            foreach (array_keys(self::AVAILABLE) as $tag) {
                if (str_starts_with(strtolower($tag), $lang . '-')) {
                    return $tag;
                }
            }

            return null;
        }

        $candidate = strtolower($parts[0]) . '-' . strtoupper($parts[1]);

        return isset(self::AVAILABLE[$candidate]) ? $candidate : null;
    }
}
