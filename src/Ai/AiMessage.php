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
 * A single turn in an AI conversation transcript.
 *
 * Only user and assistant roles are stored; system prompt and document context
 * are injected at call time and never persisted.
 */
final class AiMessage
{
    public function __construct(
        public ?int $id,
        public ?int $chatId,
        public string $role,
        public string $content,
        public ?string $toolKey,
        public int $sortOrder,
    ) {}

    /**
     * @param array{id?: int|string|null, chat_id: int|string|null, role: string,
     *             content: string, tool_key: string|null, sort_order: int|string} $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            chatId: $row['chat_id'] !== null ? (int) $row['chat_id'] : null,
            role: $row['role'],
            content: $row['content'],
            toolKey: $row['tool_key'],
            sortOrder: (int) $row['sort_order'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'chatId'    => $this->chatId,
            'role'      => $this->role,
            'content'   => $this->content,
            'toolKey'   => $this->toolKey,
            'sortOrder' => $this->sortOrder,
        ];
    }
}
