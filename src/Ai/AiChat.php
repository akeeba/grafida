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
    ) {}

    /**
     * @param array{id?: int|string|null, draft_id: int|string, service_id: int|string|null,
     *             title: string} $row
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
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'draftId'   => $this->draftId,
            'serviceId' => $this->serviceId,
            'title'     => $this->title,
            'messages'  => array_map(static fn (AiMessage $m): array => $m->toArray(), $this->messages),
        ];
    }
}
