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
 * Data-access for AI service configurations.
 */
final class AiServiceRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    /** @return list<AiService> */
    public function all(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ai_services ORDER BY id ASC');
        $stmt->execute([]);

        /** @var list<array{id?: int|string|null, name: string, provider: string, endpoint: string, model: string, params_json: string, secret_ref: string|null, insecure_key: string|null, is_default: int|string}> $rows */
        $rows = $stmt->fetchAll();

        return array_values(array_map(static fn (array $r): AiService => AiService::fromRow($r), $rows));
    }

    public function find(int $id): ?AiService
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ai_services WHERE id = ?');
        $stmt->execute([$id]);

        /** @var array{id?: int|string|null, name: string, provider: string, endpoint: string, model: string, params_json: string, secret_ref: string|null, insecure_key: string|null, is_default: int|string}|false $row */
        $row = $stmt->fetch();

        return $row !== false ? AiService::fromRow($row) : null;
    }

    /** Inserts a new AI service and returns its id. */
    public function insert(AiService $service): int
    {
        $now  = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_services (name, provider, endpoint, model, params_json, secret_ref, insecure_key, '
            . 'is_default, created_at, updated_at) VALUES '
            . '(:name, :provider, :endpoint, :model, :params, :secret_ref, :insecure_key, '
            . ':is_default, :created_at, :updated_at)'
        );
        // Distinct placeholders: PDO's native SQLite prepares (emulation off) reject
        // re-using one named parameter twice with a "column index out of range" error.
        $stmt->execute($this->bind($service) + [':created_at' => $now, ':updated_at' => $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(AiService $service): void
    {
        if ($service->id === null) {
            throw new \InvalidArgumentException('Cannot update an AI service without an id.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ai_services SET name = :name, provider = :provider, endpoint = :endpoint, '
            . 'model = :model, params_json = :params, secret_ref = :secret_ref, '
            . 'insecure_key = :insecure_key, is_default = :is_default, '
            . 'updated_at = :now WHERE id = :id'
        );
        $stmt->execute($this->bind($service) + [':now' => gmdate('Y-m-d H:i:s'), ':id' => $service->id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ai_services WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** Sets is_default = 0 for every AI service. */
    public function clearDefault(): void
    {
        $this->pdo->exec('UPDATE ai_services SET is_default = 0');
    }

    /** Marks the given service as the sole default (clears all others first). */
    public function setDefault(int $id): void
    {
        $this->clearDefault();
        $stmt = $this->pdo->prepare('UPDATE ai_services SET is_default = 1 WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** @return array<string, mixed> */
    private function bind(AiService $service): array
    {
        return [
            ':name'         => $service->name,
            ':provider'     => $service->provider,
            ':endpoint'     => $service->endpoint,
            ':model'        => $service->model,
            ':params'       => json_encode($service->params, \JSON_UNESCAPED_UNICODE),
            ':secret_ref'   => $service->secretRef,
            ':insecure_key' => $service->insecureKey,
            ':is_default'   => $service->isDefault ? 1 : 0,
        ];
    }
}
