<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Storage;

use PDO;

/**
 * Applies SQL migration files in lexicographic order, exactly once each.
 *
 * Migrations live in storage/migrations/NN_name.sql. Applied file names are
 * tracked in the `schema_migrations` table so re-runs are idempotent.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDir = __DIR__ . '/../../storage/migrations',
    ) {}

    public function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)'
        );

        $appliedStmt = $this->pdo->query('SELECT name FROM schema_migrations');
        /** @var list<string> $appliedList */
        $appliedList = $appliedStmt !== false ? $appliedStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $applied     = array_flip($appliedList);

        foreach ($this->migrationFiles() as $file) {
            $name = basename($file);

            if (isset($applied[$name])) {
                continue;
            }

            $sql = (string) file_get_contents($file);

            $this->pdo->beginTransaction();

            try {
                $this->pdo->exec($sql);
                $stmt = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)'
                );
                $stmt->execute([$name, gmdate('Y-m-d H:i:s')]);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();

                throw new \RuntimeException(
                    sprintf('Migration "%s" failed: %s', $name, $e->getMessage()),
                    0,
                    $e
                );
            }
        }
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $globResult = glob(rtrim($this->migrationsDir, '/\\') . \DIRECTORY_SEPARATOR . '*.sql');
        $files      = $globResult !== false ? $globResult : [];

        sort($files, \SORT_STRING);

        return $files;
    }
}
