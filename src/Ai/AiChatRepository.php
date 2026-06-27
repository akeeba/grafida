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
 * Data-access for AI conversations and their message transcripts.
 */
final class AiChatRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    /**
     * Returns all chats for a draft (metadata only; messages are not loaded).
     *
     * @return list<AiChat>
     */
    public function forDraft(int $draftId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ai_chats WHERE draft_id = ? ORDER BY updated_at DESC'
        );
        $stmt->execute([$draftId]);

        /** @var list<array{id?: int|string|null, draft_id: int|string, service_id: int|string|null, title: string}> $rows */
        $rows = $stmt->fetchAll();

        return array_values(array_map(static fn (array $r): AiChat => AiChat::fromRow($r), $rows));
    }

    /**
     * Returns a single chat with its messages ordered by sort_order, or null when not found.
     */
    public function find(int $id): ?AiChat
    {
        $chatStmt = $this->pdo->prepare('SELECT * FROM ai_chats WHERE id = ?');
        $chatStmt->execute([$id]);

        /** @var array{id?: int|string|null, draft_id: int|string, service_id: int|string|null, title: string}|false $row */
        $row = $chatStmt->fetch();

        if ($row === false) {
            return null;
        }

        $msgStmt = $this->pdo->prepare(
            'SELECT * FROM ai_chat_messages WHERE chat_id = ? ORDER BY sort_order ASC'
        );
        $msgStmt->execute([$id]);

        /** @var list<array{id?: int|string|null, chat_id: int|string|null, role: string, content: string, tool_key: string|null, sort_order: int|string}> $msgRows */
        $msgRows  = $msgStmt->fetchAll();
        $messages = array_values(array_map(static fn (array $r): AiMessage => AiMessage::fromRow($r), $msgRows));

        return AiChat::fromRow($row, $messages);
    }

    /**
     * Persists a new chat together with its initial messages in one transaction.
     *
     * Returns the new chat's id.
     */
    public function create(AiChat $chat): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->beginTransaction();

        try {
            $chatStmt = $this->pdo->prepare(
                'INSERT INTO ai_chats (draft_id, service_id, title, created_at, updated_at) '
                . 'VALUES (:draft_id, :service_id, :title, :created_at, :updated_at)'
            );
            // Distinct placeholders: PDO's native SQLite prepares (emulation off) reject
            // re-using one named parameter twice with a "column index out of range" error.
            $chatStmt->execute([
                ':draft_id'   => $chat->draftId,
                ':service_id' => $chat->serviceId,
                ':title'      => $chat->title,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            $chatId = (int) $this->pdo->lastInsertId();

            if ($chat->messages !== []) {
                // Use positional params for repeated inserts in a loop to avoid
                // named-placeholder collisions across iterations.
                $msgStmt = $this->pdo->prepare(
                    'INSERT INTO ai_chat_messages (chat_id, role, content, tool_key, sort_order, created_at) '
                    . 'VALUES (?, ?, ?, ?, ?, ?)'
                );

                foreach ($chat->messages as $message) {
                    $msgStmt->execute([
                        $chatId,
                        $message->role,
                        $message->content,
                        $message->toolKey,
                        $message->sortOrder,
                        $now,
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $chatId;
    }

    /** Updates the title of a chat. */
    public function rename(int $id, string $title): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ai_chats SET title = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$title, gmdate('Y-m-d H:i:s'), $id]);
    }

    /** Deletes a chat and, via ON DELETE CASCADE, all its messages. */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ai_chats WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Replaces all messages for a chat in one transaction.
     *
     * Deletes every existing message for the chat and inserts the supplied list.
     *
     * @param list<AiMessage> $messages
     */
    public function replaceMessages(int $chatId, array $messages): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->beginTransaction();

        try {
            $del = $this->pdo->prepare('DELETE FROM ai_chat_messages WHERE chat_id = ?');
            $del->execute([$chatId]);

            if ($messages !== []) {
                $ins = $this->pdo->prepare(
                    'INSERT INTO ai_chat_messages (chat_id, role, content, tool_key, sort_order, created_at) '
                    . 'VALUES (?, ?, ?, ?, ?, ?)'
                );

                foreach ($messages as $message) {
                    $ins->execute([
                        $chatId,
                        $message->role,
                        $message->content,
                        $message->toolKey,
                        $message->sortOrder,
                        $now,
                    ]);
                }
            }

            $upd = $this->pdo->prepare(
                'UPDATE ai_chats SET updated_at = ? WHERE id = ?'
            );
            $upd->execute([$now, $chatId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }
}
