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
 * A remembered AI conversation, linked to a local draft.
 */
final class AiChat
{
    /**
     * @param list<AiMessage> $messages Transcript turns; empty when loaded lazily via forDraft().
     */
    public function __construct(
        public ?int $id,
        public int $draftId,
        public ?int $serviceId,
        public string $title,
        public array $messages = [],
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public ?string $previousResponseId = null,
        public ?string $lastResponseAt = null,
    ) {}

    /**
     * @param array{id?: int|string|null, draft_id: int|string, service_id: int|string|null,
     *             title: string, created_at?: string|null, updated_at?: string|null,
     *             previous_response_id?: string|null, last_response_at?: string|null} $row
     * @param list<AiMessage> $messages
     */
    public static function fromRow(array $row, array $messages = []): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            draftId: (int) $row['draft_id'],
            serviceId: $row['service_id'] !== null ? (int) $row['service_id'] : null,
            title: $row['title'],
            messages: $messages,
            createdAt: isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : null,
            updatedAt: isset($row['updated_at']) && is_string($row['updated_at']) ? $row['updated_at'] : null,
            previousResponseId: isset($row['previous_response_id']) && is_string($row['previous_response_id']) ? $row['previous_response_id'] : null,
            lastResponseAt: isset($row['last_response_at']) && is_string($row['last_response_at']) ? $row['last_response_at'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'draftId'             => $this->draftId,
            'serviceId'           => $this->serviceId,
            'title'               => $this->title,
            'messages'            => array_map(static fn (AiMessage $m): array => $m->toArray(), $this->messages),
            'createdAt'           => $this->createdAt,
            'updatedAt'           => $this->updatedAt,
            'previousResponseId'  => $this->previousResponseId,
            'lastResponseAt'      => $this->lastResponseAt,
        ];
    }
}
