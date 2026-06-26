<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Storage;

use Grafida\Secret\ProcessRunner;
use Grafida\Site\SiteService;
use Grafida\Support\Paths;
use PDO;

/**
 * Local-storage maintenance: reporting where the SQLite database lives, opening
 * its containing folder in the OS file browser, and resetting all local data.
 */
final class StorageService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SiteService $sites,
        private readonly ProcessRunner $runner = new ProcessRunner(),
    ) {}

    /**
     * Describes the on-disk SQLite database.
     *
     * @return array{path: string, directory: string, exists: bool, size: int}
     */
    public function info(): array
    {
        $path   = Paths::databaseFile();
        $exists = is_file($path);
        $size   = $exists ? filesize($path) : false;

        return [
            'path'      => $path,
            'directory' => \dirname($path),
            'exists'    => $exists,
            'size'      => $size !== false ? $size : 0,
        ];
    }

    /**
     * Reveals the database's containing folder in the desktop's default file
     * browser (Finder, Explorer, or the freedesktop file manager).
     */
    public function openContainingFolder(): void
    {
        $directory = \dirname(Paths::databaseFile());

        $command = match (\PHP_OS_FAMILY) {
            'Darwin'  => ['open', $directory],
            'Windows' => ['explorer', $directory],
            default   => ['xdg-open', $directory],
        };

        [$code, , $stderr] = $this->runner->run($command);

        // explorer.exe returns a non-zero exit code even when it succeeds, so we
        // only treat a failure as fatal on the platforms that report it reliably.
        if ($code !== 0 && \PHP_OS_FAMILY !== 'Windows') {
            $message = trim($stderr);

            throw new \RuntimeException($message !== '' ? $message : 'Unable to open the folder.');
        }
    }

    /**
     * Wipes every trace of local data: stored API tokens (from the OS secret
     * store) and all application rows, leaving an empty but fully-migrated
     * database behind.
     */
    public function reset(): void
    {
        // Delete sites through the service so their OS-stored tokens go too.
        foreach ($this->sites->list() as $site) {
            if ($site->id !== null) {
                $this->sites->delete($site->id);
            }
        }

        // Foreign keys are disabled for the bulk wipe so table order can't matter.
        $this->pdo->exec('PRAGMA foreign_keys = OFF');

        try {
            foreach ($this->userTables() as $table) {
                $this->pdo->exec('DELETE FROM "' . $table . '"');
            }
        } finally {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Names of all application tables, excluding SQLite internals and the
     * migration bookkeeping table (whose contents must survive a reset).
     *
     * @return list<string>
     */
    private function userTables(): array
    {
        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' "
            . "AND name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations'"
        );

        /** @var list<string> $tables */
        $tables = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

        return $tables;
    }
}
