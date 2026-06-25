<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Site;

use PDO;

/**
 * Data-access for the `sites` table.
 */
final class SiteRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    /** @return list<Site> */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM sites ORDER BY title COLLATE NOCASE');

        if ($stmt === false) {
            return [];
        }

        /** @var list<array{id?: int|string|null, title: string, base_url: string, api_base: string|null, secret_ref: string|null, insecure_token: string|int|null, default_language?: string}> $rows */
        $rows = $stmt->fetchAll();

        return array_values(array_map(static fn (array $row): Site => Site::fromRow($row), $rows));
    }

    public function find(int $id): ?Site
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sites WHERE id = ?');
        $stmt->execute([$id]);
        /** @var array{id?: int|string|null, title: string, base_url: string, api_base: string|null, secret_ref: string|null, insecure_token: string|int|null, default_language?: string}|false $row */
        $row = $stmt->fetch();

        return $row !== false ? Site::fromRow($row) : null;
    }

    /** Returns the raw plaintext token stored for an insecure site (or null). */
    public function insecureToken(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT insecure_token FROM sites WHERE id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    public function insert(
        string $title,
        string $baseUrl,
        ?string $apiBase,
        ?string $secretRef,
        ?string $insecureToken,
        string $defaultLanguage = '*',
    ): int {
        $now  = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO sites (title, base_url, api_base, secret_ref, insecure_token, default_language, created_at, updated_at) '
            . 'VALUES (:title, :base_url, :api_base, :secret_ref, :insecure_token, :lang, :now, :now)'
        );
        $stmt->execute([
            ':title'          => $title,
            ':base_url'       => $baseUrl,
            ':api_base'       => $apiBase,
            ':secret_ref'     => $secretRef,
            ':insecure_token' => $insecureToken,
            ':lang'           => $defaultLanguage,
            ':now'            => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $title,
        string $baseUrl,
        ?string $apiBase,
        ?string $secretRef,
        ?string $insecureToken,
        string $defaultLanguage = '*',
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE sites SET title = :title, base_url = :base_url, api_base = :api_base, '
            . 'secret_ref = :secret_ref, insecure_token = :insecure_token, default_language = :lang, '
            . 'updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            ':title'          => $title,
            ':base_url'       => $baseUrl,
            ':api_base'       => $apiBase,
            ':secret_ref'     => $secretRef,
            ':insecure_token' => $insecureToken,
            ':lang'           => $defaultLanguage,
            ':now'            => gmdate('Y-m-d H:i:s'),
            ':id'             => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM sites WHERE id = ?');
        $stmt->execute([$id]);
    }
}
