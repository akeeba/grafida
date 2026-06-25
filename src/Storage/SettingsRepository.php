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
 * Key/value application settings (e.g. the UI language override).
 */
final class SettingsRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? $default : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (key, value) VALUES (:k, :v) '
            . 'ON CONFLICT(key) DO UPDATE SET value = :v'
        );
        $stmt->execute([':k' => $key, ':v' => $value]);
    }
}
