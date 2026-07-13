<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 *
 * PHPUnit bootstrap: Composer's autoloader, plus an optional `tests/.env`.
 *
 * `tests/.env` is gitignored and holds the credentials the LIVE provider tests need
 * (see tests/README.md and tests/.env.sample). It is entirely optional — without it
 * those tests skip and the whole suite still passes.
 *
 * Dotenv::load() does not override a variable that is already set in the real
 * environment, so `FOO=bar composer test` still wins over the file.
 */

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/.env';

if (is_file($envFile) && is_readable($envFile)) {
    // usePutenv so the values are visible to getenv(), not just $_ENV / $_SERVER.
    (new Dotenv())->usePutenv()->load($envFile);
}
