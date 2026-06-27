<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Ai;

/**
 * A per-tool override or user-defined custom AI tool.
 *
 * Built-in tool definitions live in code; this entity represents a stored
 * deviation from those defaults, or a brand-new custom tool.
 */
final class AiTool
{
    /**
     * @param array<string, mixed> $params Extra model parameters for this tool (override the service defaults).
     */
    public function __construct(
        public ?int $id,
        public string $toolKey,
        public string $title,
        public string $icon,
        public string $prompt,
        public bool $overrideSystem,
        public string $tone,
        public array $params,
        public ?int $serviceId,
        public bool $isCustom,
        public bool $enabled,
        public int $sortOrder,
    ) {}

    /**
     * @param array{id?: int|string|null, tool_key: string, title: string, icon: string,
     *             prompt: string, override_system: int|string, tone: string, params_json: string,
     *             service_id: int|string|null, is_custom: int|string, enabled: int|string,
     *             sort_order: int|string} $row
     */
    public static function fromRow(array $row): self
    {
        $paramsRaw = json_decode($row['params_json'], true);

        /** @var array<string, mixed> $params */
        $params = is_array($paramsRaw) ? $paramsRaw : [];

        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            toolKey: $row['tool_key'],
            title: $row['title'],
            icon: $row['icon'],
            prompt: $row['prompt'],
            overrideSystem: (bool) $row['override_system'],
            tone: $row['tone'],
            params: $params,
            serviceId: $row['service_id'] !== null ? (int) $row['service_id'] : null,
            isCustom: (bool) $row['is_custom'],
            enabled: (bool) $row['enabled'],
            sortOrder: (int) $row['sort_order'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'toolKey'        => $this->toolKey,
            'title'          => $this->title,
            'icon'           => $this->icon,
            'prompt'         => $this->prompt,
            'overrideSystem' => $this->overrideSystem,
            'tone'           => $this->tone,
            'params'         => $this->params,
            'serviceId'      => $this->serviceId,
            'isCustom'       => $this->isCustom,
            'enabled'        => $this->enabled,
            'sortOrder'      => $this->sortOrder,
        ];
    }
}
