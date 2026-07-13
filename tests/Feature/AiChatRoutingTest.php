<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Feature;

use Boson\Component\Http\Request;
use Boson\Component\Http\Static\StaticProviderInterface;
use Boson\Contracts\Http\RequestInterface;
use Boson\Contracts\Http\ResponseInterface;
use Grafida\Application\Kernel;
use Grafida\Storage\Database;
use Grafida\Storage\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the AI chat CRUD routes and verifies that deleting a draft cascades
 * to its saved chats and chat messages.
 */
final class AiChatRoutingTest extends TestCase
{
    private ?\PDO $lastPdo = null;

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function kernel(): Kernel
    {
        $pdo = Database::connect(':memory:');
        (new Migrator($pdo))->migrate();
        $this->lastPdo = $pdo;

        $static = new class implements StaticProviderInterface {
            public function findFileByRequest(RequestInterface $request): ?ResponseInterface
            {
                return null;
            }
        };

        return new Kernel($static, $pdo, \dirname(__DIR__, 2));
    }

    /** Inserts a bare site row so drafts have a valid foreign key. */
    private function seedSite(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->lastPdo?->prepare(
            'INSERT INTO sites (title, base_url, created_at, updated_at) VALUES (?, ?, ?, ?)'
        )->execute(['Test Site', 'https://example.test', $now, $now]);

        return (int) ($this->lastPdo?->lastInsertId() ?? 0);
    }

    /** @return array{0: int, 1: mixed} */
    private function call(Kernel $kernel, string $method, string $path, ?string $body = null): array
    {
        $request  = new Request($method, 'boson://app' . $path, [], $body ?? '');
        $response = $kernel->handle($request);

        return [(int) (string) $response->status, json_decode((string) $response->body, true)];
    }

    // ------------------------------------------------------------------
    //  Tests
    // ------------------------------------------------------------------

    public function testCreateAndListChats(): void
    {
        $kernel  = $this->kernel();
        $siteId  = $this->seedSite();

        // Create a draft.
        [, $draftData] = $this->call(
            $kernel,
            'POST',
            '/api/sites/' . $siteId . '/drafts',
            json_encode(['title' => 'My Draft']),
        );
        self::assertTrue($draftData['ok']);
        $draftId = $draftData['data']['id'];

        // Create a chat for that draft.
        [$status, $json] = $this->call(
            $kernel,
            'POST',
            '/api/ai/chats',
            json_encode([
                'draftId'  => $draftId,
                'title'    => 'First Chat',
                'messages' => [
                    ['role' => 'user',      'content' => 'Hello'],
                    ['role' => 'assistant', 'content' => 'Hi there!'],
                ],
            ]),
        );

        self::assertSame(201, $status);
        self::assertTrue($json['ok']);
        self::assertIsInt($json['data']['id']);
        self::assertSame('First Chat', $json['data']['title']);
        self::assertCount(2, $json['data']['messages']);
        self::assertSame('user', $json['data']['messages'][0]['role']);
        self::assertSame('Hello', $json['data']['messages'][0]['content']);
        self::assertIsString($json['data']['createdAt']);
        self::assertIsString($json['data']['updatedAt']);

        // List chats for the draft.
        [$listStatus, $listJson] = $this->call($kernel, 'GET', '/api/drafts/' . $draftId . '/chats');

        self::assertSame(200, $listStatus);
        self::assertTrue($listJson['ok']);
        self::assertIsArray($listJson['data']);
        self::assertCount(1, $listJson['data']);
        self::assertSame('First Chat', $listJson['data'][0]['title']);
        // List response includes timestamps but no messages.
        self::assertIsString($listJson['data'][0]['createdAt']);
        self::assertSame([], $listJson['data'][0]['messages']);
    }

    public function testGetChatReturnsMessages(): void
    {
        $kernel  = $this->kernel();
        $siteId  = $this->seedSite();

        [, $draftData] = $this->call(
            $kernel,
            'POST',
            '/api/sites/' . $siteId . '/drafts',
            json_encode(['title' => 'Draft']),
        );
        $draftId = $draftData['data']['id'];

        [, $chatData] = $this->call(
            $kernel,
            'POST',
            '/api/ai/chats',
            json_encode([
                'draftId'  => $draftId,
                'title'    => 'Chat',
                'messages' => [
                    ['role' => 'user',      'content' => 'Question?'],
                    ['role' => 'assistant', 'content' => 'Answer!'],
                ],
            ]),
        );
        $chatId = $chatData['data']['id'];

        [$status, $json] = $this->call($kernel, 'GET', '/api/ai/chats/' . $chatId);

        self::assertSame(200, $status);
        self::assertSame('Chat', $json['data']['title']);
        self::assertCount(2, $json['data']['messages']);
        self::assertSame('Question?', $json['data']['messages'][0]['content']);
        self::assertSame('Answer!', $json['data']['messages'][1]['content']);
    }

    public function testGetUnknownChatReturns404(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/ai/chats/9999');

        self::assertSame(404, $status);
        self::assertFalse($json['ok']);
    }

    public function testRenameChat(): void
    {
        $kernel  = $this->kernel();
        $siteId  = $this->seedSite();

        [, $draftData] = $this->call(
            $kernel,
            'POST',
            '/api/sites/' . $siteId . '/drafts',
            json_encode(['title' => 'Draft']),
        );

        [, $chatData] = $this->call(
            $kernel,
            'POST',
            '/api/ai/chats',
            json_encode([
                'draftId'  => $draftData['data']['id'],
                'title'    => 'Old Title',
                'messages' => [['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => 'Hello']],
            ]),
        );
        $chatId = $chatData['data']['id'];

        [$patchStatus, $patched] = $this->call(
            $kernel,
            'PATCH',
            '/api/ai/chats/' . $chatId,
            json_encode(['title' => 'New Title']),
        );

        self::assertSame(200, $patchStatus);
        self::assertSame('New Title', $patched['data']['title']);
    }

    public function testReplaceMessages(): void
    {
        $kernel  = $this->kernel();
        $siteId  = $this->seedSite();

        [, $draftData] = $this->call(
            $kernel,
            'POST',
            '/api/sites/' . $siteId . '/drafts',
            json_encode(['title' => 'Draft']),
        );

        [, $chatData] = $this->call(
            $kernel,
            'POST',
            '/api/ai/chats',
            json_encode([
                'draftId'  => $draftData['data']['id'],
                'title'    => 'Chat',
                'messages' => [['role' => 'user', 'content' => 'Original']],
            ]),
        );
        $chatId = $chatData['data']['id'];

        // Replace messages via PATCH.
        [$patchStatus, $patched] = $this->call(
            $kernel,
            'PATCH',
            '/api/ai/chats/' . $chatId,
            json_encode([
                'messages' => [
                    ['role' => 'user',      'content' => 'New question'],
                    ['role' => 'assistant', 'content' => 'New answer'],
                ],
            ]),
        );

        self::assertSame(200, $patchStatus);
        self::assertCount(2, $patched['data']['messages']);
        self::assertSame('New question', $patched['data']['messages'][0]['content']);
    }

    public function testDeleteChat(): void
    {
        $kernel  = $this->kernel();
        $siteId  = $this->seedSite();

        [, $draftData] = $this->call(
            $kernel,
            'POST',
            '/api/sites/' . $siteId . '/drafts',
            json_encode(['title' => 'Draft']),
        );

        [, $chatData] = $this->call(
            $kernel,
            'POST',
            '/api/ai/chats',
            json_encode([
                'draftId'  => $draftData['data']['id'],
                'title'    => 'To Delete',
                'messages' => [['role' => 'user', 'content' => 'Bye'], ['role' => 'assistant', 'content' => 'Goodbye']],
            ]),
        );
        $chatId = $chatData['data']['id'];

        [$delStatus, $delJson] = $this->call($kernel, 'DELETE', '/api/ai/chats/' . $chatId);
        self::assertSame(200, $delStatus);
        self::assertTrue($delJson['ok']);

        // Subsequent GET must return 404.
        [$getStatus] = $this->call($kernel, 'GET', '/api/ai/chats/' . $chatId);
        self::assertSame(404, $getStatus);
    }

    public function testCreateChatPersistsResponseChain(): void
    {
        $kernel  = $this->kernel();
        $siteId  = $this->seedSite();

        [, $draftData] = $this->call(
            $kernel,
            'POST',
            '/api/sites/' . $siteId . '/drafts',
            json_encode(['title' => 'Draft']),
        );
        $draftId = $draftData['data']['id'];

        [$status, $json] = $this->call(
            $kernel,
            'POST',
            '/api/ai/chats',
            json_encode([
                'draftId'             => $draftId,
                'title'               => 'Resumable Chat',
                'messages'            => [['role' => 'user', 'content' => 'Hi']],
                'previousResponseId'  => 'resp_abc123',
                'lastResponseAt'      => '2026-07-13T10:00:00+00:00',
            ]),
        );

        self::assertSame(201, $status);
        self::assertTrue($json['ok']);
        self::assertSame('resp_abc123', $json['data']['previousResponseId']);
        self::assertSame('2026-07-13T10:00:00+00:00', $json['data']['lastResponseAt']);

        // Read it back via GET too.
        [, $reread] = $this->call($kernel, 'GET', '/api/ai/chats/' . $json['data']['id']);
        self::assertSame('resp_abc123', $reread['data']['previousResponseId']);
        self::assertSame('2026-07-13T10:00:00+00:00', $reread['data']['lastResponseAt']);
    }

    public function testPatchingResponseChainOntoExistingChatPersists(): void
    {
        $kernel  = $this->kernel();
        $siteId  = $this->seedSite();

        [, $draftData] = $this->call(
            $kernel,
            'POST',
            '/api/sites/' . $siteId . '/drafts',
            json_encode(['title' => 'Draft']),
        );

        [, $chatData] = $this->call(
            $kernel,
            'POST',
            '/api/ai/chats',
            json_encode([
                'draftId'  => $draftData['data']['id'],
                'title'    => 'Chat',
                'messages' => [['role' => 'user', 'content' => 'Hi']],
            ]),
        );
        $chatId = $chatData['data']['id'];

        self::assertNull($chatData['data']['previousResponseId']);
        self::assertNull($chatData['data']['lastResponseAt']);

        [$patchStatus, $patched] = $this->call(
            $kernel,
            'PATCH',
            '/api/ai/chats/' . $chatId,
            json_encode([
                'previousResponseId' => 'resp_xyz789',
                'lastResponseAt'     => '2026-07-13T11:30:00+00:00',
            ]),
        );

        self::assertSame(200, $patchStatus);
        self::assertSame('resp_xyz789', $patched['data']['previousResponseId']);
        self::assertSame('2026-07-13T11:30:00+00:00', $patched['data']['lastResponseAt']);

        // Confirm it survives a fresh GET too (not just the PATCH response echo).
        [, $reread] = $this->call($kernel, 'GET', '/api/ai/chats/' . $chatId);
        self::assertSame('resp_xyz789', $reread['data']['previousResponseId']);
        self::assertSame('2026-07-13T11:30:00+00:00', $reread['data']['lastResponseAt']);
    }

    /**
     * A `.grafida` export of a draft must not carry the response-id chain — it is a
     * local, provider-specific artefact with no portable meaning (see DraftExportService).
     */
    public function testExportedGrafidaFileDoesNotContainResponseChain(): void
    {
        $kernel  = $this->kernel();
        $siteId  = $this->seedSite();

        [, $draftData] = $this->call(
            $kernel,
            'POST',
            '/api/sites/' . $siteId . '/drafts',
            json_encode(['title' => 'Exportable Draft']),
        );
        $draftId = $draftData['data']['id'];

        $this->call(
            $kernel,
            'POST',
            '/api/ai/chats',
            json_encode([
                'draftId'             => $draftId,
                'title'               => 'Chat with chain',
                'messages'            => [['role' => 'user', 'content' => 'Hi']],
                'previousResponseId'  => 'resp_should_not_export',
                'lastResponseAt'      => '2026-07-13T12:00:00+00:00',
            ]),
        );

        $directory = sys_get_temp_dir() . '/grafida-export-test-' . uniqid();
        mkdir($directory);

        try {
            [$status, $json] = $this->call(
                $kernel,
                'POST',
                '/api/drafts/' . $draftId . '/export',
                json_encode(['directory' => $directory]),
            );

            self::assertSame(200, $status);
            self::assertTrue($json['ok']);

            $exported = json_decode((string) file_get_contents($json['data']['path']), true);

            self::assertCount(1, $exported['aiChats']);
            self::assertArrayNotHasKey('previousResponseId', $exported['aiChats'][0]);
            self::assertArrayNotHasKey('lastResponseAt', $exported['aiChats'][0]);
            self::assertArrayNotHasKey('serviceId', $exported['aiChats'][0]);
        } finally {
            @unlink($directory . '/exportable-draft.grafida');
            @rmdir($directory);
        }
    }

    /**
     * Deleting a draft via the draft-delete route must cascade to its ai_chats
     * and ai_chat_messages rows (the schema wires this via ON DELETE CASCADE).
     */
    public function testDraftDeletionCascadesToAiChats(): void
    {
        $kernel  = $this->kernel();
        $siteId  = $this->seedSite();

        // Create a draft with a saved chat.
        [, $draftData] = $this->call(
            $kernel,
            'POST',
            '/api/sites/' . $siteId . '/drafts',
            json_encode(['title' => 'Doomed Draft']),
        );
        $draftId = $draftData['data']['id'];

        [, $chatData] = $this->call(
            $kernel,
            'POST',
            '/api/ai/chats',
            json_encode([
                'draftId'  => $draftId,
                'title'    => 'Linked Chat',
                'messages' => [
                    ['role' => 'user',      'content' => 'Will this survive?'],
                    ['role' => 'assistant', 'content' => 'No.'],
                ],
            ]),
        );
        $chatId = $chatData['data']['id'];

        // Verify the chat exists.
        [$beforeStatus] = $this->call($kernel, 'GET', '/api/ai/chats/' . $chatId);
        self::assertSame(200, $beforeStatus, 'Chat should exist before draft deletion');

        // Delete the draft via the draft-delete route.
        [$delStatus, $delJson] = $this->call($kernel, 'DELETE', '/api/drafts/' . $draftId);
        self::assertSame(200, $delStatus);
        self::assertTrue($delJson['ok']);

        // The chat must now be gone (cascade).
        [$afterStatus] = $this->call($kernel, 'GET', '/api/ai/chats/' . $chatId);
        self::assertSame(404, $afterStatus, 'Chat should be removed by cascade when draft is deleted');

        // And the chat_messages must also be gone (verify via direct PDO query).
        $stmt = $this->lastPdo?->prepare('SELECT COUNT(*) FROM ai_chat_messages WHERE chat_id = ?');
        $stmt?->execute([$chatId]);
        $count = (int) ($stmt?->fetchColumn() ?? 0);
        self::assertSame(0, $count, 'ai_chat_messages should be cleared by ON DELETE CASCADE');
    }
}
