<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Ai;

use PDO;

/**
 * Data-access for AI tool overrides and custom tools.
 */
final class AiToolRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    /** @return list<AiTool> */
    public function all(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ai_tools ORDER BY sort_order ASC, id ASC');
        $stmt->execute([]);

        /** @var list<array{id?: int|string|null, tool_key: string, title: string, icon: string, prompt: string, override_system: int|string, tone: string, params_json: string, service_id: int|string|null, is_custom: int|string, enabled: int|string, sort_order: int|string}> $rows */
        $rows = $stmt->fetchAll();

        return array_values(array_map(static fn (array $r): AiTool => AiTool::fromRow($r), $rows));
    }

    public function findByKey(string $key): ?AiTool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ai_tools WHERE tool_key = ?');
        $stmt->execute([$key]);

        /** @var array{id?: int|string|null, tool_key: string, title: string, icon: string, prompt: string, override_system: int|string, tone: string, params_json: string, service_id: int|string|null, is_custom: int|string, enabled: int|string, sort_order: int|string}|false $row */
        $row = $stmt->fetch();

        return $row !== false ? AiTool::fromRow($row) : null;
    }

    /**
     * Inserts a new tool or replaces an existing one matched by tool_key.
     *
     * Returns the tool's id (existing id on update, new id on insert).
     */
    public function upsert(AiTool $tool): int
    {
        $now      = gmdate('Y-m-d H:i:s');
        $existing = $this->findByKey($tool->toolKey);

        if ($existing === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ai_tools (tool_key, title, icon, prompt, override_system, tone, params_json, '
                . 'service_id, is_custom, enabled, sort_order, created_at, updated_at) VALUES '
                . '(:tool_key, :title, :icon, :prompt, :override_system, :tone, :params, '
                . ':service_id, :is_custom, :enabled, :sort_order, :created_at, :updated_at)'
            );
            // Distinct placeholders: PDO's native SQLite prepares (emulation off) reject
            // re-using one named parameter twice with a "column index out of range" error.
            $stmt->execute($this->bind($tool) + [':created_at' => $now, ':updated_at' => $now]);

            return (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ai_tools SET title = :title, icon = :icon, prompt = :prompt, '
            . 'override_system = :override_system, tone = :tone, params_json = :params, '
            . 'service_id = :service_id, is_custom = :is_custom, enabled = :enabled, '
            . 'sort_order = :sort_order, updated_at = :now WHERE tool_key = :tool_key'
        );
        $stmt->execute($this->bind($tool) + [':now' => $now]);

        return (int) $existing->id;
    }

    public function delete(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ai_tools WHERE tool_key = ?');
        $stmt->execute([$key]);
    }

    /** @return array<string, mixed> */
    private function bind(AiTool $tool): array
    {
        return [
            ':tool_key'        => $tool->toolKey,
            ':title'           => $tool->title,
            ':icon'            => $tool->icon,
            ':prompt'          => $tool->prompt,
            ':override_system' => $tool->overrideSystem ? 1 : 0,
            ':tone'            => $tool->tone,
            ':params'          => json_encode($tool->params, \JSON_UNESCAPED_UNICODE),
            ':service_id'      => $tool->serviceId,
            ':is_custom'       => $tool->isCustom ? 1 : 0,
            ':enabled'         => $tool->enabled ? 1 : 0,
            ':sort_order'      => $tool->sortOrder,
        ];
    }
}
