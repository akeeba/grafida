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
use Grafida\Article\Draft;
use Grafida\Article\DraftExportService;
use Grafida\Article\DraftRepository;
use Grafida\Media\MediaRepository;
use Grafida\Storage\Database;
use Grafida\Storage\Migrator;
use PDO;

final class DraftExportServiceTest extends TestCase
{
    private PDO $pdo;
    private DraftRepository $drafts;
    private MediaRepository $media;
    private AiChatRepository $chats;
    private DraftExportService $export;

    protected function setUp(): void
    {
        $this->pdo = Database::connect(':memory:');
        (new Migrator($this->pdo))->migrate();
        $this->pdo->exec(
            'INSERT INTO sites (id, title, base_url, created_at, updated_at) '
            . "VALUES (1, 'Site', 'https://example.com', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );
        $this->pdo->exec(
            'INSERT INTO sites (id, title, base_url, created_at, updated_at) '
            . "VALUES (2, 'Other', 'https://other.example', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );

        $this->drafts = new DraftRepository($this->pdo);
        $this->media  = new MediaRepository($this->pdo);
        $this->chats  = new AiChatRepository($this->pdo);
        $this->export = new DraftExportService($this->drafts, $this->media, $this->chats);
    }

    private function draftWithMediaAndChat(): int
    {
        $mediaId = $this->media->store(1, null, 'photo.png', 'image/png', 'raw-bytes');

        $draft = new Draft(
            id: null,
            siteId: 1,
            remoteId: 55,
            title: 'Hello',
            alias: 'hello',
            catid: 3,
            access: 1,
            language: '*',
            state: 1,
            html: '<p>Body</p>',
            fields: ['colour' => 'blue'],
            tags: ['news'],
            images: ['image_intro' => 'grafida-media://' . $mediaId, 'image_fulltext' => ''],
            metadesc: 'desc',
            metakey: 'key',
        );

        $draftId = $this->drafts->insert($draft);

        $this->chats->create(new AiChat(
            id: null,
            draftId: $draftId,
            serviceId: null,
            title: 'Chat about intro',
            messages: [
                new AiMessage(id: null, chatId: null, role: 'user', content: 'Hi', toolKey: null, sortOrder: 0),
                new AiMessage(id: null, chatId: null, role: 'assistant', content: 'Hello!', toolKey: 'generate', sortOrder: 1),
            ],
        ));

        return $draftId;
    }

    public function testExportOmitsSiteAndRemoteIdAndEmbedsMedia(): void
    {
        $draftId = $this->draftWithMediaAndChat();

        $payload = $this->export->export($draftId);

        self::assertSame(1, $payload['grafidaExport']);
        self::assertArrayNotHasKey('siteId', $payload['draft']);
        self::assertArrayNotHasKey('remoteId', $payload['draft']);
        self::assertArrayNotHasKey('id', $payload['draft']);
        self::assertSame('Hello', $payload['draft']['title']);

        self::assertCount(1, $payload['offlineMedia']);
        $ref = array_key_first($payload['offlineMedia']);
        self::assertSame('grafida-media://export:' . $ref, $payload['draft']['images']['image_intro']);
        self::assertSame('raw-bytes', base64_decode($payload['offlineMedia'][$ref]['dataBase64'], true));

        self::assertCount(1, $payload['aiChats']);
        self::assertSame('Chat about intro', $payload['aiChats'][0]['title']);
        self::assertCount(2, $payload['aiChats'][0]['messages']);
        self::assertArrayNotHasKey('serviceId', $payload['aiChats'][0]);
    }

    public function testImportAsNewDraftCreatesFreshDraftAndMediaOnTargetSite(): void
    {
        $draftId = $this->draftWithMediaAndChat();
        $payload = $this->export->export($draftId);

        $imported = $this->export->importAsNewDraft(2, $payload);

        self::assertNotSame($draftId, $imported->id);
        self::assertSame(2, $imported->siteId);
        self::assertNull($imported->remoteId);
        self::assertSame('Hello', $imported->title);

        $newRef = $imported->images['image_intro'];
        self::assertStringStartsWith('grafida-media://', $newRef);
        $newMediaId = (int) substr($newRef, \strlen('grafida-media://'));
        self::assertNotSame(0, $newMediaId);

        $blob = $this->media->find($newMediaId);
        self::assertNotNull($blob);
        self::assertSame('raw-bytes', $blob['data']);

        $chats = $this->chats->forDraft($imported->id ?? 0);
        self::assertCount(1, $chats);
        self::assertSame('Chat about intro', $chats[0]->title);
        self::assertNull($chats[0]->serviceId);
    }

    public function testReplaceDraftKeepsSiteAndRemoteIdButReplacesContent(): void
    {
        $draftId = $this->draftWithMediaAndChat();
        $payload = $this->export->export($draftId);

        // A second, unrelated draft that will be replaced by the exported payload.
        $target = new Draft(
            id: null,
            siteId: 2,
            remoteId: 77,
            title: 'Old title',
            alias: 'old',
            catid: null,
            access: 1,
            language: '*',
            state: 0,
            html: '<p>Old body</p>',
            fields: [],
            tags: [],
            images: [],
            metadesc: '',
            metakey: '',
        );
        $targetId = $this->drafts->insert($target);
        $this->chats->create(new AiChat(id: null, draftId: $targetId, serviceId: null, title: 'Old chat'));

        $replaced = $this->export->replaceDraft($targetId, $payload);

        self::assertSame($targetId, $replaced->id);
        self::assertSame(2, $replaced->siteId, 'site id must be preserved');
        self::assertSame(77, $replaced->remoteId, 'remote id must be preserved');
        self::assertSame('Hello', $replaced->title);
        self::assertSame('<p>Body</p>', $replaced->html);

        $chats = $this->chats->forDraft($targetId);
        self::assertCount(1, $chats);
        self::assertSame('Chat about intro', $chats[0]->title, 'old chats must be replaced by imported ones');
    }
}
