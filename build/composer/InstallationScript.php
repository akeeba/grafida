<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Build;

use Composer\Script\Event;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Composer install hooks that place the NPM-fetched front-end libraries (TinyMCE, CodeMirror,
 * FontAwesome) into their final, gitignored locations under assets/private/.
 *
 * Driven entirely by the `extra.copy-static` and `extra.minify` blocks in composer.json, so
 * adding/removing a vendored file is a data change, never a code change. Deliberately
 * self-contained (no third-party runtime dependency) — it runs during `composer install`,
 * before the application autoloader is necessarily usable.
 */
abstract class InstallationScript
{
	/**
	 * Copy NPM dependencies from node_modules/ into their final locations.
	 *
	 * Reads `extra.copy-static`, a list of definitions:
	 *   - type "file"   : copy a single file (`from` → `to`).
	 *   - type "folder" : recursively copy a directory (`from` → `to`), optionally limited to a
	 *                     list of `names` glob patterns and optionally wiping `to` first (`clean`).
	 * All paths are relative to the project root.
	 *
	 * @noinspection PhpUnused
	 */
	public static function copyStatic(Event $event): void
	{
		$io   = $event->getIO();
		$root = self::projectRoot();

		foreach ($event->getComposer()->getPackage()->getExtra()['copy-static'] ?? [] as $def)
		{
			$type = $def['type'] ?? 'file';
			$from = $root . '/' . trim((string) ($def['from'] ?? ''), '/');
			$to   = $root . '/' . trim((string) ($def['to'] ?? ''), '/');

			if (empty($def['from']) || empty($def['to']))
			{
				continue;
			}

			if ($type === 'file')
			{
				if (!self::copyFile($from, $to))
				{
					$io->writeError('<warning>Grafida vendoring: missing source ' . $from . '</warning>');
				}

				continue;
			}

			if (!is_dir($from))
			{
				$io->writeError('<warning>Grafida vendoring: missing source folder ' . $from . '</warning>');

				continue;
			}

			if (!empty($def['clean']))
			{
				self::deleteTree($to);
			}

			self::copyFolder($from, $to, $def['names'] ?? null);
		}

		$io->write('<info>Grafida vendoring: static files copied</info>');
	}

	/**
	 * Minify the plain-source files copied by copyStatic() into the `.min` filenames the SPA
	 * references. Reads `extra.minify`: `js` files are run through `npx terser`, `css` files
	 * through `npx cleancss`. Outputs that are newer than their source are skipped.
	 *
	 * @noinspection PhpUnused
	 */
	public static function minify(Event $event): void
	{
		$io   = $event->getIO();
		$root = self::projectRoot();
		$defs = $event->getComposer()->getPackage()->getExtra()['minify'] ?? [];

		foreach (($defs['js'] ?? []) as $relative)
		{
			$in  = $root . '/' . trim((string) $relative, '/');
			$out = preg_replace('/\.js$/', '.min.js', $in);

			if (!self::needsMinify($in, $out))
			{
				continue;
			}

			$command = 'npx terser ' . escapeshellarg($in)
				. ' --compress --mangle --comments false'
				. ' --output ' . escapeshellarg($out);

			self::run($command, $io);
		}

		foreach (($defs['css'] ?? []) as $relative)
		{
			$in  = $root . '/' . trim((string) $relative, '/');
			$out = preg_replace('/\.css$/', '.min.css', $in);

			if (!self::needsMinify($in, $out))
			{
				continue;
			}

			$command = 'npx cleancss -o ' . escapeshellarg($out) . ' ' . escapeshellarg($in);

			self::run($command, $io);
		}

		$io->write('<info>Grafida vendoring: CodeMirror minified</info>');
	}

	/**
	 * Copy a single file, creating the target directory as needed. Returns false when the source
	 * is missing.
	 */
	private static function copyFile(string $from, string $to): bool
	{
		if (!is_file($from))
		{
			return false;
		}

		$dir = dirname($to);

		if (!is_dir($dir))
		{
			mkdir($dir, 0755, true);
		}

		return copy($from, $to);
	}

	/**
	 * Recursively copy every file under $from into $to, preserving the relative tree. When $names
	 * is a non-empty list of glob patterns, only matching basenames are copied.
	 *
	 * @param  array<string>|null  $names
	 */
	private static function copyFolder(string $from, string $to, ?array $names = null): void
	{
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$fromLength = strlen(rtrim($from, '/')) + 1;

		/** @var SplFileInfo $file */
		foreach ($iterator as $file)
		{
			if (!$file->isFile())
			{
				continue;
			}

			if (!empty($names) && !self::matchesAny($file->getFilename(), $names))
			{
				continue;
			}

			$target = rtrim($to, '/') . '/' . substr($file->getPathname(), $fromLength);
			$dir    = dirname($target);

			if (!is_dir($dir))
			{
				mkdir($dir, 0755, true);
			}

			copy($file->getPathname(), $target);
		}
	}

	/**
	 * @param  array<string>  $patterns
	 */
	private static function matchesAny(string $name, array $patterns): bool
	{
		foreach ($patterns as $pattern)
		{
			if (fnmatch($pattern, $name))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively delete a directory tree (used by the `clean` flag so a library upgrade does not
	 * leave behind files removed upstream).
	 */
	private static function deleteTree(string $path): void
	{
		if (!is_dir($path))
		{
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		/** @var SplFileInfo $item */
		foreach ($iterator as $item)
		{
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}

		rmdir($path);
	}

	private static function needsMinify(string $in, string $out): bool
	{
		if (!is_file($in))
		{
			return false;
		}

		return !is_file($out) || filemtime($out) < filemtime($in);
	}

	private static function run(string $command, \Composer\IO\IOInterface $io): void
	{
		$cwd = getcwd();
		chdir(self::projectRoot());
		passthru($command, $status);
		chdir($cwd === false ? self::projectRoot() : $cwd);

		if ($status !== 0)
		{
			$io->writeError('<warning>Grafida vendoring: command failed (' . $status . '): ' . $command . '</warning>');
		}
	}

	private static function projectRoot(): string
	{
		return dirname(__DIR__, 2);
	}
}
