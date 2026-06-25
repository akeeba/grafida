<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Reference;

use PDO;

/**
 * Stores and retrieves per-site cached reference data (categories, tags, access
 * levels, field definitions) as JSON payloads.
 */
final class ReferenceRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    /**
     * @return array{payload: array<mixed>, fetched_at: string}|null
     */
    public function get(int $siteId, string $kind): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT payload, fetched_at FROM reference_cache WHERE site_id = ? AND kind = ?'
        );
        $stmt->execute([$siteId, $kind]);

        /** @var array{payload: string, fetched_at: string}|false $row */
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $decoded = json_decode($row['payload'], true);

        return [
            'payload'    => is_array($decoded) ? $decoded : [],
            'fetched_at' => $row['fetched_at'],
        ];
    }

    /** @param array<mixed> $payload */
    public function put(int $siteId, string $kind, array $payload): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO reference_cache (site_id, kind, payload, fetched_at) VALUES (:s, :k, :p, :t) '
            . 'ON CONFLICT(site_id, kind) DO UPDATE SET payload = :p, fetched_at = :t'
        );
        $stmt->execute([
            ':s' => $siteId,
            ':k' => $kind,
            ':p' => json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            ':t' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function getEditorCss(int $siteId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT css FROM editor_css_cache WHERE site_id = ?');
        $stmt->execute([$siteId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function putEditorCss(int $siteId, string $css): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO editor_css_cache (site_id, css, fetched_at) VALUES (:s, :c, :t) '
            . 'ON CONFLICT(site_id) DO UPDATE SET css = :c, fetched_at = :t'
        );
        $stmt->execute([':s' => $siteId, ':c' => $css, ':t' => gmdate('Y-m-d H:i:s')]);
    }
}
