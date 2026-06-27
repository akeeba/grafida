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
 * package from one `<tag>.ini` file per language. Missing keys fall back to en-GB.
 */
final class LanguageService
{
    public const DEFAULT_TAG = 'en-GB';
    public const AUTO        = 'auto';
    public const SETTING_KEY = 'ui_language';

    /** Translation key each language file carries to name itself in its own tongue. */
    private const ENDONYM_KEY = 'GRAFIDA_LANGUAGE_ENDONYM';

    /**
     * Extension name passed to joomla/language's {@see Language::load()}. An empty string makes it
     * load a bare `<tag>.ini` (its "internal" naming) rather than `<tag>.<extension>.ini`.
     */
    private const EXTENSION = '';

    private ?Language $language = null;
    private ?Language $fallback = null;
    private ?string $resolvedTag = null;

    /** @var array<string, string>|null Cached tag => endonym map. */
    private ?array $available = null;

    /** @var list<string>|null Cached list of shipped language tags. */
    private ?array $availableTags = null;

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

    /**
     * The languages the application ships, as an ordered tag => endonym map.
     *
     * Discovered at runtime by scanning the language directory: every `<tag>/<tag>.ini`
     * is a shipped language, and its `GRAFIDA_LANGUAGE_ENDONYM` key names it in its own tongue (so
     * adding a translation needs no code change). The default language sorts first, the rest by endonym.
     *
     * @return array<string, string>
     */
    public function available(): array
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $map = [];

        foreach ($this->availableTags() as $tag) {
            $map[$tag] = $this->readEndonym($tag) ?? $tag;
        }

        uksort($map, function (string $a, string $b) use ($map): int {
            if ($a === self::DEFAULT_TAG) {
                return -1;
            }

            if ($b === self::DEFAULT_TAG) {
                return 1;
            }

            return strcoll($map[$a], $map[$b]);
        });

        return $this->available = $map;
    }

    /** The stored override ("auto" when auto-detecting). */
    public function override(): string
    {
        return $this->settings->get(self::SETTING_KEY, self::AUTO) ?? self::AUTO;
    }

    /** Sets and persists the language override (use self::AUTO for auto-detect). */
    public function setOverride(string $tag): void
    {
        $tag = $tag === self::AUTO || $this->isAvailable($tag) ? $tag : self::AUTO;
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

        if ($override !== self::AUTO && $this->isAvailable($override)) {
            return $override;
        }

        $detected = $this->detectOsLanguage();

        return $detected !== null && $this->isAvailable($detected) ? $detected : self::DEFAULT_TAG;
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
            foreach ($this->availableTags() as $tag) {
                if (str_starts_with(strtolower($tag), $lang . '-')) {
                    return $tag;
                }
            }

            return null;
        }

        $candidate = strtolower($parts[0]) . '-' . strtoupper($parts[1]);

        return $this->isAvailable($candidate) ? $candidate : null;
    }

    /** Whether the given tag is one of the languages the application ships. */
    private function isAvailable(string $tag): bool
    {
        return \in_array($tag, $this->availableTags(), true);
    }

    /**
     * Discovers the shipped language tags by scanning the language directory for
     * `<tag>/<tag>.ini` files. en-GB is always present (the canonical source).
     *
     * @return list<string>
     */
    private function availableTags(): array
    {
        if ($this->availableTags !== null) {
            return $this->availableTags;
        }

        $tags = [self::DEFAULT_TAG];

        $dirs = glob($this->basePath . '/language/*', \GLOB_ONLYDIR);

        foreach ($dirs === false ? [] : $dirs as $dir) {
            $tag = basename($dir);

            if ($tag === self::DEFAULT_TAG || \in_array($tag, $tags, true)) {
                continue;
            }

            // Only count it as a shipped language if it actually carries our strings.
            if (is_file($this->iniPath($tag))) {
                $tags[] = $tag;
            }
        }

        return $this->availableTags = $tags;
    }

    /** Absolute path to a language's `<tag>/<tag>.ini` catalogue. */
    private function iniPath(string $tag): string
    {
        return $this->basePath . '/language/' . $tag . '/' . $tag . '.ini';
    }

    /**
     * Reads a language's self-name (endonym) straight from its INI file, without loading the
     * whole catalogue. Returns null when the file or the key is missing.
     */
    private function readEndonym(string $tag): ?string
    {
        $file = $this->iniPath($tag);

        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return null;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (!str_starts_with(ltrim($line), self::ENDONYM_KEY)) {
                    continue;
                }

                if (preg_match('/^\s*' . self::ENDONYM_KEY . '\s*=\s*"(.*)"\s*$/u', $line, $m) === 1) {
                    return $m[1];
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }
}
