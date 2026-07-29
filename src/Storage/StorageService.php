<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Storage;

use Grafida\Ai\AiServiceManager;
use Grafida\Secret\ProcessRunner;
use Grafida\Site\SiteService;
use Grafida\Support\Paths;
use Joomla\Database\DatabaseInterface;

/**
 * Local-storage maintenance: reporting where the SQLite database lives, opening
 * its containing folder in the OS file browser, and resetting all local data.
 */
final class StorageService
{
    use QueryBuilderSupport;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly SiteService $sites,
        private readonly AiServiceManager $aiServices,
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
     * Wipes every trace of local data: stored secrets (from the OS secret store)
     * and all application rows, leaving an empty but fully-migrated database
     * behind.
     *
     * ⚠️ **Both kinds of secret have to be deleted through their own service,
     * before the bulk wipe.** The OS secret store is not a table, so a `DELETE
     * FROM` reaches none of it, and once the row carrying the `secret_ref` is
     * gone there is nothing left to say which keychain entry belonged to us —
     * the entry becomes an orphan no later run can find. That is exactly what
     * used to happen to AI service keys, whose loop was missing while the site
     * one was here from the start.
     */
    public function reset(): void
    {
        // Delete sites through the service so their OS-stored tokens go too.
        foreach ($this->sites->list() as $site) {
            if ($site->id !== null) {
                $this->sites->delete($site->id);
            }
        }

        // Likewise the AI services, whose API keys live under their own
        // `grafida.ai_service.{id}` references.
        foreach ($this->aiServices->list() as $service) {
            if ($service->id !== null) {
                $this->aiServices->delete($service->id);
            }
        }

        // PRAGMA foreign_keys is a no-op inside a transaction, so this bulk wipe
        // is deliberately not wrapped in one — table order doesn't matter either way.
        $this->db->setQuery('PRAGMA foreign_keys = OFF')->execute();

        try {
            foreach ($this->userTables() as $table) {
                $this->db->setQuery(
                    'DELETE FROM ' . $this->qn($table)
                )->execute();
            }
        } finally {
            $this->db->setQuery('PRAGMA foreign_keys = ON')->execute();
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
        // sqlite_master introspection has no builder vocabulary.
        /** @var list<string> $tables */
        $tables = $this->db->setQuery(
            "SELECT name FROM sqlite_master WHERE type = 'table' "
            . "AND name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations'"
        )->loadColumn();

        return $tables;
    }
}
