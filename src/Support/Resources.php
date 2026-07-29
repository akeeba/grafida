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
 * Resolves the directory that holds bundled resources that must be read from
 * the real filesystem: the language files (loaded with parse_ini_file) and the
 * SQL migrations (enumerated with glob). Neither function supports the phar://
 * stream wrapper, so when the application is compiled into a self-contained
 * binary these resources are extracted to the writable data directory once.
 *
 * In development (not running from a phar) the project root is returned and
 * nothing is copied.
 */
final class Resources
{
    private static ?string $base = null;

    /** Directory containing `language/` and `storage/migrations/`. */
    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }

        $pharUrl = \Phar::running(true);

        if ($pharUrl === '') {
            // Development: read straight from the project tree.
            return self::$base = \dirname(__DIR__, 2);
        }

        $target = Paths::dataDir() . \DIRECTORY_SEPARATOR . 'resources';

        self::sync($pharUrl . '/language', $target . \DIRECTORY_SEPARATOR . 'language');
        self::sync($pharUrl . '/storage/migrations', $target . \DIRECTORY_SEPARATOR . 'storage' . \DIRECTORY_SEPARATOR . 'migrations');

        return self::$base = $target;
    }

    /** Directory containing the SQL migration files. */
    public static function migrationsDir(): string
    {
        return self::base() . \DIRECTORY_SEPARATOR . 'storage' . \DIRECTORY_SEPARATOR . 'migrations';
    }

    /**
     * Directory containing the bundled documentation (`docs/`), read by
     * {@see \Grafida\Help\HelpService} for the in-app Help screen.
     *
     * Unlike the language files and the migrations this is deliberately **not**
     * extracted through {@see self::sync()} and does not go through
     * {@see self::base()}: nothing here needs a function that the phar stream
     * wrapper cannot serve. The pages are read with `file_get_contents()`, which
     * handles `phar://` perfectly well, and they are enumerated from
     * `docs/_manifest.json` rather than with `glob()` — which is the one call
     * that would have forced an extraction. Copying a few hundred KiB of
     * Markdown into the data directory on every launch to gain nothing would be
     * pure waste.
     */
    public static function docsDir(): string
    {
        $pharUrl = \Phar::running(true);

        // Inside a phar the separator is always '/', never DIRECTORY_SEPARATOR.
        return $pharUrl === ''
            ? \dirname(__DIR__, 2) . \DIRECTORY_SEPARATOR . 'docs'
            : $pharUrl . '/docs';
    }

    /**
     * Copies a directory tree from $source (which may be a phar:// URL) to the
     * real-filesystem $destination, overwriting files whose size differs. Small
     * and idempotent: it runs on every launch.
     */
    private static function sync(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            return;
        }

        if (!is_dir($destination)) {
            @mkdir($destination, 0700, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), \strlen($source) + 1);
            $relative = str_replace('/', \DIRECTORY_SEPARATOR, $relative);
            $dest     = $destination . \DIRECTORY_SEPARATOR . $relative;

            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    @mkdir($dest, 0700, true);
                }

                continue;
            }

            if (is_file($dest) && filesize($dest) === $item->getSize()) {
                continue; // already extracted, unchanged
            }

            $contents = @file_get_contents($item->getPathname());

            if ($contents !== false) {
                @file_put_contents($dest, $contents);
            }
        }
    }
}
