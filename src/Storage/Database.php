<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Storage;

use Grafida\Support\Paths;
use Grafida\Support\Resources;
use PDO;

/**
 * Thin wrapper that creates and configures the application's SQLite connection.
 */
final class Database
{
    private static ?PDO $shared = null;

    /**
     * Returns the process-wide shared PDO connection to the application database,
     * creating and migrating it on first use.
     */
    public static function get(): PDO
    {
        if (self::$shared instanceof PDO) {
            return self::$shared;
        }

        self::$shared = self::connect(Paths::databaseFile());

        (new Migrator(self::$shared, Resources::migrationsDir()))->migrate();

        return self::$shared;
    }

    /**
     * Creates a PDO SQLite connection with sensible pragmas.
     *
     * @param string $file Path to the database file, or ':memory:' for tests.
     */
    public static function connect(string $file): PDO
    {
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    /** Replaces the shared connection (used by the test suite). */
    public static function setShared(?PDO $pdo): void
    {
        self::$shared = $pdo;
    }
}
