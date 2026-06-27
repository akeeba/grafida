<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Ai\AiChat;
use Grafida\Ai\AiChatRepository;
use Grafida\Ai\AiMessage;
use Grafida\Ai\AiService;
use Grafida\Ai\AiServiceRepository;
use Grafida\Ai\AiTool;
use Grafida\Ai\AiToolRepository;
use Grafida\Article\Draft;
use Grafida\Article\DraftRepository;
use Grafida\Storage\Database;
use Grafida\Storage\Migrator;
use PDO;

final class AiRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connect(':memory:');
        (new Migrator($this->pdo))->migrate();

        // Seed a site + draft required by ai_chats FK.
        $this->pdo->exec(
            'INSERT INTO sites (id, title, base_url, created_at, updated_at) '
            . "VALUES (1, 'Test Site', 'https://example.com', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function serviceRepo(): AiServiceRepository
    {
        return new AiServiceRepository($this->pdo);
    }

    private function toolRepo(): AiToolRepository
    {
        return new AiToolRepository($this->pdo);
    }

    private function chatRepo(): AiChatRepository
    {
        return new AiChatRepository($this->pdo);
    }

    private function draftRepo(): DraftRepository
    {
        return new DraftRepository($this->pdo);
    }

    private function sampleService(): AiService
    {
        return new AiService(
            id: null,
            name: 'Local Ollama',
            provider: 'ollama',
            endpoint: 'http://localhost:11434',
            model: 'llama3',
            params: ['temperature' => 0.7],
            secretRef: null,
            insecureKey: null,
            isDefault: false,
        );
    }

    private function sampleTool(): AiTool
    {
        return new AiTool(
            id: null,
            toolKey: 'improve-writing',
            title: 'Improve Writing',
            icon: 'fa-pencil',
            prompt: 'Rewrite the following text…',
            overrideSystem: false,
            tone: 'professional',
            params: ['max_completion_tokens' => 512],
            serviceId: null,
            isCustom: false,
            enabled: true,
            sortOrder: 10,
        );
    }

    private function insertDraft(): int
    {
        return $this->draftRepo()->insert(new Draft(
            id: null,
            siteId: 1,
            remoteId: null,
            title: 'Draft Article',
            alias: 'draft-article',
            catid: null,
            access: 1,
            language: '*',
            state: 1,
            html: '<p>Body</p>',
        ));
    }

    private function sampleChat(int $draftId): AiChat
    {
        return new AiChat(
            id: null,
            draftId: $draftId,
            serviceId: null,
            title: 'My Chat',
            messages: [
                new AiMessage(null, null, 'user', 'Hello!', null, 0),
                new AiMessage(null, null, 'assistant', 'Hi there!', null, 1),
            ],
        );
    }

    // -----------------------------------------------------------------------
    // AiService round-trip
    // -----------------------------------------------------------------------

    public function testServiceInsertAndFind(): void
    {
        $repo = $this->serviceRepo();
        $id   = $repo->insert($this->sampleService());

        self::assertGreaterThan(0, $id);

        $back = $repo->find($id);

        self::assertNotNull($back);
        self::assertSame('Local Ollama', $back->name);
        self::assertSame('ollama', $back->provider);
        self::assertSame('http://localhost:11434', $back->endpoint);
        self::assertSame('llama3', $back->model);
        self::assertSame(['temperature' => 0.7], $back->params);
        self::assertNull($back->secretRef);
        self::assertNull($back->insecureKey);
        self::assertFalse($back->isDefault);
    }

    public function testServiceToArrayExcludesApiKey(): void
    {
        $service = new AiService(
            id: 1,
            name: 'My Service',
            provider: 'openai',
            endpoint: '',
            model: 'gpt-4o',
            params: [],
            secretRef: 'ref-abc',
            insecureKey: 'sk-secret',
            isDefault: true,
        );

        $arr = $service->toArray();

        self::assertArrayNotHasKey('secretRef', $arr);
        self::assertArrayNotHasKey('insecureKey', $arr);
        self::assertArrayNotHasKey('secret_ref', $arr);
        self::assertArrayNotHasKey('insecure_key', $arr);
    }

    public function testServiceUpdate(): void
    {
        $repo = $this->serviceRepo();
        $id   = $repo->insert($this->sampleService());

        $svc       = $repo->find($id);
        self::assertNotNull($svc);
        $svc->name  = 'Updated';
        $svc->model = 'llama3.1';
        $repo->update($svc);

        $back = $repo->find($id);
        self::assertNotNull($back);
        self::assertSame('Updated', $back->name);
        self::assertSame('llama3.1', $back->model);
    }

    public function testServiceAll(): void
    {
        $repo = $this->serviceRepo();
        $repo->insert($this->sampleService());
        $svc2 = $this->sampleService();
        $svc2->name = 'Second';
        $repo->insert($svc2);

        $all = $repo->all();

        self::assertCount(2, $all);
        self::assertSame('Local Ollama', $all[0]->name);
        self::assertSame('Second', $all[1]->name);
    }

    public function testServiceDelete(): void
    {
        $repo = $this->serviceRepo();
        $id   = $repo->insert($this->sampleService());

        $repo->delete($id);

        self::assertNull($repo->find($id));
        self::assertCount(0, $repo->all());
    }

    public function testServiceSetAndClearDefault(): void
    {
        $repo = $this->serviceRepo();
        $id1  = $repo->insert($this->sampleService());
        $svc2 = $this->sampleService();
        $svc2->name = 'Second';
        $id2 = $repo->insert($svc2);

        $repo->setDefault($id1);
        self::assertTrue($repo->find($id1)?->isDefault);
        self::assertFalse($repo->find($id2)?->isDefault);

        $repo->setDefault($id2);
        self::assertFalse($repo->find($id1)?->isDefault);
        self::assertTrue($repo->find($id2)?->isDefault);

        $repo->clearDefault();
        self::assertFalse($repo->find($id1)?->isDefault);
        self::assertFalse($repo->find($id2)?->isDefault);
    }

    // -----------------------------------------------------------------------
    // AiTool round-trip
    // -----------------------------------------------------------------------

    public function testToolUpsertInsertsNew(): void
    {
        $repo = $this->toolRepo();
        $id   = $repo->upsert($this->sampleTool());

        self::assertGreaterThan(0, $id);

        $back = $repo->findByKey('improve-writing');

        self::assertNotNull($back);
        self::assertSame('Improve Writing', $back->title);
        self::assertSame('professional', $back->tone);
        self::assertSame(['max_completion_tokens' => 512], $back->params);
        self::assertTrue($back->enabled);
        self::assertSame(10, $back->sortOrder);
    }

    public function testToolUpsertUpdatesExisting(): void
    {
        $repo = $this->toolRepo();
        $id1  = $repo->upsert($this->sampleTool());

        $updated        = $this->sampleTool();
        $updated->title = 'Polish Writing';
        $updated->tone  = 'casual';
        $id2            = $repo->upsert($updated);

        self::assertSame($id1, $id2, 'upsert must return the same id on update');

        $back = $repo->findByKey('improve-writing');
        self::assertNotNull($back);
        self::assertSame('Polish Writing', $back->title);
        self::assertSame('casual', $back->tone);
    }

    public function testToolAll(): void
    {
        $repo  = $this->toolRepo();
        $tool2 = $this->sampleTool();
        $tool2->toolKey    = 'summarise';
        $tool2->sortOrder  = 5;
        $repo->upsert($tool2);
        $repo->upsert($this->sampleTool());

        $all = $repo->all();

        self::assertCount(2, $all);
        // Ordered by sort_order ASC: 5 < 10.
        self::assertSame('summarise', $all[0]->toolKey);
        self::assertSame('improve-writing', $all[1]->toolKey);
    }

    public function testToolDelete(): void
    {
        $repo = $this->toolRepo();
        $repo->upsert($this->sampleTool());

        $repo->delete('improve-writing');

        self::assertNull($repo->findByKey('improve-writing'));
    }

    // -----------------------------------------------------------------------
    // AiChat + AiMessage round-trip
    // -----------------------------------------------------------------------

    public function testChatCreateAndFind(): void
    {
        $draftId = $this->insertDraft();
        $repo    = $this->chatRepo();
        $chatId  = $repo->create($this->sampleChat($draftId));

        self::assertGreaterThan(0, $chatId);

        $back = $repo->find($chatId);

        self::assertNotNull($back);
        self::assertSame($draftId, $back->draftId);
        self::assertSame('My Chat', $back->title);
        self::assertNull($back->serviceId);
        self::assertCount(2, $back->messages);
        self::assertSame('user', $back->messages[0]->role);
        self::assertSame('Hello!', $back->messages[0]->content);
        self::assertSame('assistant', $back->messages[1]->role);
        self::assertSame(1, $back->messages[1]->sortOrder);
    }

    public function testChatForDraftReturnsMetadataOnly(): void
    {
        $draftId = $this->insertDraft();
        $repo    = $this->chatRepo();
        $repo->create($this->sampleChat($draftId));

        $chats = $repo->forDraft($draftId);

        self::assertCount(1, $chats);
        self::assertSame('My Chat', $chats[0]->title);
        // Messages are not loaded lazily by forDraft().
        self::assertCount(0, $chats[0]->messages);
    }

    public function testChatRename(): void
    {
        $draftId = $this->insertDraft();
        $repo    = $this->chatRepo();
        $chatId  = $repo->create($this->sampleChat($draftId));

        $repo->rename($chatId, 'Renamed Chat');

        self::assertSame('Renamed Chat', $repo->find($chatId)?->title);
    }

    public function testChatReplaceMessages(): void
    {
        $draftId = $this->insertDraft();
        $repo    = $this->chatRepo();
        $chatId  = $repo->create($this->sampleChat($draftId));

        $newMessages = [
            new AiMessage(null, null, 'user', 'New question', null, 0),
            new AiMessage(null, null, 'assistant', 'New answer', null, 1),
            new AiMessage(null, null, 'user', 'Follow-up', null, 2),
        ];
        $repo->replaceMessages($chatId, $newMessages);

        $back = $repo->find($chatId);
        self::assertNotNull($back);
        self::assertCount(3, $back->messages);
        self::assertSame('New question', $back->messages[0]->content);
        self::assertSame('Follow-up', $back->messages[2]->content);
    }

    public function testChatDelete(): void
    {
        $draftId = $this->insertDraft();
        $repo    = $this->chatRepo();
        $chatId  = $repo->create($this->sampleChat($draftId));

        $repo->delete($chatId);

        self::assertNull($repo->find($chatId));
    }

    // -----------------------------------------------------------------------
    // Foreign-key cascade assertions
    // -----------------------------------------------------------------------

    public function testForeignKeysAreEnabled(): void
    {
        $result = $this->pdo->query('PRAGMA foreign_keys');
        self::assertNotFalse($result);
        $row = $result->fetch(PDO::FETCH_NUM);
        self::assertIsArray($row);
        self::assertSame('1', (string) $row[0], 'PRAGMA foreign_keys must be ON (1)');
    }

    public function testDeletingDraftCascadesToChatsAndMessages(): void
    {
        $draftId = $this->insertDraft();
        $chatId  = $this->chatRepo()->create($this->sampleChat($draftId));

        // Verify the chat and its messages exist before deletion.
        self::assertNotNull($this->chatRepo()->find($chatId));

        // Delete the draft → should cascade to ai_chats and then ai_chat_messages.
        $this->draftRepo()->delete($draftId);

        self::assertNull($this->chatRepo()->find($chatId), 'chat must be deleted when its draft is deleted');
        self::assertCount(0, $this->chatRepo()->forDraft($draftId), 'no chats must remain for deleted draft');

        // Verify messages are also gone.
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ai_chat_messages WHERE chat_id = ?');
        $stmt->execute([$chatId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'messages must cascade-delete when draft is deleted');
    }

    public function testDeletingChatCascadesToMessages(): void
    {
        $draftId = $this->insertDraft();
        $chatId  = $this->chatRepo()->create($this->sampleChat($draftId));

        // Confirm messages are present.
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ai_chat_messages WHERE chat_id = ?');
        $stmt->execute([$chatId]);
        self::assertSame(2, (int) $stmt->fetchColumn());

        // Delete the chat directly.
        $this->chatRepo()->delete($chatId);

        // Messages must be gone.
        $stmt->execute([$chatId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'messages must cascade-delete when their chat is deleted');
    }

    public function testDeletingServiceNullsToolServiceId(): void
    {
        $serviceId = $this->serviceRepo()->insert($this->sampleService());

        $tool            = $this->sampleTool();
        $tool->serviceId = $serviceId;
        $this->toolRepo()->upsert($tool);

        $this->serviceRepo()->delete($serviceId);

        $back = $this->toolRepo()->findByKey('improve-writing');
        self::assertNotNull($back);
        self::assertNull($back->serviceId, 'tool service_id must be SET NULL when service is deleted');
    }
}
