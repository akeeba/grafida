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
use Grafida\Html\InlineMedia;
use Grafida\Media\InlineImageExtractor;
use Grafida\Media\LocalMediaUrl;
use Grafida\Media\MediaRepository;
use Grafida\Tests\Support\TestDatabase;
use Joomla\Database\DatabaseInterface;

final class DraftExportServiceTest extends TestCase
{
    private DatabaseInterface $db;
    private DraftRepository $drafts;
    private MediaRepository $media;
    private AiChatRepository $chats;
    private DraftExportService $export;

    protected function setUp(): void
    {
        $this->db = TestDatabase::memory();
        $connection = TestDatabase::connection($this->db);
        $connection->exec(
            'INSERT INTO sites (id, title, base_url, created_at, updated_at) '
            . "VALUES (1, 'Site', 'https://example.com', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );
        $connection->exec(
            'INSERT INTO sites (id, title, base_url, created_at, updated_at) '
            . "VALUES (2, 'Other', 'https://other.example', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );

        $this->drafts = new DraftRepository($this->db);
        $this->media  = new MediaRepository($this->db);
        $this->chats  = new AiChatRepository($this->db);
        $this->export = new DraftExportService(
            $this->drafts,
            $this->media,
            $this->chats,
            new InlineImageExtractor($this->media),
        );
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
            createdByAlias: 'Guest Author',
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

        self::assertSame(2, $payload['grafidaExport']);
        self::assertArrayNotHasKey('siteId', $payload['draft']);
        self::assertArrayNotHasKey('remoteId', $payload['draft']);
        self::assertArrayNotHasKey('id', $payload['draft']);
        self::assertSame('Hello', $payload['draft']['title']);
        self::assertSame('Guest Author', $payload['draft']['createdByAlias']);

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
        self::assertSame('Guest Author', $imported->createdByAlias);

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
        self::assertSame('Guest Author', $replaced->createdByAlias);

        $chats = $this->chats->forDraft($targetId);
        self::assertCount(1, $chats);
        self::assertSame('Chat about intro', $chats[0]->title, 'old chats must be replaced by imported ones');
    }

    /**
     * A .grafida file written before created_by_alias existed carries no such key.
     * It must still import, crediting the real author (an empty alias) — which is
     * why adding the field needed no format-version bump.
     */
    public function testImportOfAPayloadWithoutCreatedByAliasDefaultsToEmpty(): void
    {
        $payload = $this->export->export($this->draftWithMediaAndChat());
        unset($payload['draft']['createdByAlias']);

        $imported = $this->export->importAsNewDraft(2, $payload);

        self::assertSame('', $imported->createdByAlias);
    }

    /**
     * gh-36: a body image is a `boson://app/api/media/{id}/raw` reference, not
     * self-carrying `data:` bytes, so it must ride under `offlineMedia` too —
     * and importing must materialise a fresh blob (never point back at the
     * exporting install's row), sharing a ref with the intro image if the same
     * ref happens to be used twice.
     */
    public function testExportEmbedsBodyImagesAndImportRematerialisesThemAsFreshBlobs(): void
    {
        $introId = $this->media->store(1, null, 'intro.png', 'image/png', 'intro-bytes');
        $bodyId  = $this->media->store(1, null, 'body.png', 'image/png', 'body-bytes');
        $meta    = $this->media->findMeta($bodyId);
        self::assertNotNull($meta);
        $bodyUrl = LocalMediaUrl::build($bodyId, $meta['updated_at'] ?? $meta['created_at']);

        $draft = new Draft(
            id: null,
            siteId: 1,
            remoteId: null,
            title: 'With body image',
            alias: 'with-body-image',
            catid: null,
            access: 1,
            language: '*',
            state: 1,
            html: '<p>Before</p><img src="' . $bodyUrl . '" data-grafida-media-id="' . $bodyId . '">'
                . '<p>After <img src="' . $bodyUrl . '"></p>',
            fields: [],
            tags: [],
            images: ['image_intro' => 'grafida-media://' . $introId, 'image_fulltext' => ''],
            metadesc: '',
            metakey: '',
        );
        $draftId = $this->drafts->insert($draft);

        $payload = $this->export->export($draftId);

        // Two distinct local-URL <img> elements referencing the SAME blob
        // must collapse onto one offlineMedia entry, alongside the intro
        // image's own entry — never three.
        self::assertCount(2, $payload['offlineMedia']);
        self::assertStringNotContainsString('boson://', $payload['draft']['html']);
        self::assertStringNotContainsString(InlineMedia::ATTRIBUTE, $payload['draft']['html']);
        preg_match_all('/grafida-media:\/\/export:(m\d+)/', $payload['draft']['html'], $matches);
        self::assertCount(2, $matches[1]);
        self::assertSame($matches[1][0], $matches[1][1], 'both <img> tags reference the same blob and must share one ref');

        $imported = $this->export->importAsNewDraft(2, $payload);

        self::assertStringNotContainsString('grafida-media://export:', $imported->html);
        preg_match_all('/boson:\/\/app\/api\/media\/(\d+)\/raw/', $imported->html, $urlMatches);
        self::assertCount(2, $urlMatches[1]);
        self::assertSame($urlMatches[1][0], $urlMatches[1][1], 'both rewritten <img> tags must point at the same freshly-stored blob');

        $newBodyId = (int) $urlMatches[1][0];
        self::assertNotSame($bodyId, $newBodyId, 'import must never alias the exporting install\'s own blob row');
        $newBlob = $this->media->find($newBodyId);
        self::assertNotNull($newBlob);
        self::assertSame('body-bytes', $newBlob['data']);
    }

    /**
     * A `.grafida` written by a pre-gh-36 Grafida (or one whose body image was
     * never touched by the on-open legacy migration) carries the image
     * self-embedded as a `data:` URI in `html`, not under `offlineMedia`.
     * Importing it must still end up with a `media_blobs` row and a rewritten
     * `boson://` reference — {@see DraftExportService::importAsNewDraft()}
     * runs {@see InlineImageExtractor} over the imported HTML for exactly
     * this reason.
     */
    public function testLegacyExportWithInlineBase64BodyImageImportsIntoABlob(): void
    {
        $b64 = base64_encode('legacy-inline-bytes');

        $payload = [
            'grafidaExport' => 1,
            'draft'         => [
                'title'    => 'Legacy export',
                'alias'    => 'legacy-export',
                'catid'    => null,
                'access'   => 1,
                'language' => '*',
                'state'    => 1,
                'html'     => '<p><img src="data:image/png;base64,' . $b64 . '"></p>',
                'fields'   => [],
                'tags'     => [],
                'images'   => [],
                'metadesc' => '',
                'metakey'  => '',
            ],
            'offlineMedia' => [],
            'aiChats'      => [],
        ];

        $imported = $this->export->importAsNewDraft(1, $payload);

        self::assertStringNotContainsString('data:image', $imported->html);
        self::assertStringContainsString(InlineMedia::LOCAL_URL_PREFIX, $imported->html);

        preg_match('#boson://app/api/media/(\d+)/raw#', $imported->html, $m);
        self::assertNotEmpty($m);
        $blob = $this->media->find((int) $m[1]);
        self::assertNotNull($blob);
        self::assertSame('legacy-inline-bytes', $blob['data']);
    }
}
