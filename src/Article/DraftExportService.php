<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Article;

use Grafida\Ai\AiChat;
use Grafida\Ai\AiChatRepository;
use Grafida\Ai\AiMessage;
use Grafida\Media\MediaRepository;
use Grafida\Support\App;

/**
 * Builds and consumes the portable `.grafida` draft export format.
 *
 * The format carries every visible article field plus saved AI chats and any
 * locally-picked (not-yet-published) images, embedded as base64. It never
 * carries `site_id` / `remote_id` (those are local-install specifics), nor
 * the local `media_blobs` / `ai_services` row ids (also local-install only).
 */
final class DraftExportService
{
    private const FORMAT_VERSION = 1;

    /** @var list<string> Draft `images` subfields that may hold a `grafida-media://` sentinel. */
    private const IMAGE_MEDIA_KEYS = ['image_intro', 'image_fulltext'];

    private const MEDIA_REF_PREFIX = 'grafida-media://';

    private const EXPORT_REF_PREFIX = self::MEDIA_REF_PREFIX . 'export:';

    public function __construct(
        private readonly DraftRepository $drafts,
        private readonly MediaRepository $media,
        private readonly AiChatRepository $aiChats,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(int $draftId): array
    {
        $draft = $this->drafts->find($draftId);

        if ($draft === null) {
            throw new \RuntimeException('Draft not found');
        }

        [$images, $offlineMedia] = $this->exportImages($draft->images);

        return [
            'grafidaExport' => self::FORMAT_VERSION,
            'exportedAt'    => gmdate('c'),
            'appVersion'    => App::VERSION,
            'draft'         => [
                'title'    => $draft->title,
                'alias'    => $draft->alias,
                'catid'    => $draft->catid,
                'access'   => $draft->access,
                'language' => $draft->language,
                'state'    => $draft->state,
                'html'     => $draft->html,
                'fields'   => $draft->fields,
                'tags'     => $draft->tags,
                'images'   => $images,
                'metadesc' => $draft->metadesc,
                'metakey'  => $draft->metakey,
            ],
            'offlineMedia'  => $offlineMedia,
            'aiChats'       => $this->exportChats($draftId),
        ];
    }

    /**
     * Imports a payload as a brand-new draft on the given site.
     *
     * @param array<string, mixed> $payload
     */
    public function importAsNewDraft(int $siteId, array $payload): Draft
    {
        $data = $this->draftDataFromPayload($payload);

        $draft = new Draft(
            id: null,
            siteId: $siteId,
            remoteId: null,
            title: $data['title'],
            alias: $data['alias'],
            catid: $data['catid'],
            access: $data['access'],
            language: $data['language'],
            state: $data['state'],
            html: $data['html'],
            fields: $data['fields'],
            tags: $data['tags'],
            images: $data['images'],
            metadesc: $data['metadesc'],
            metakey: $data['metakey'],
        );

        $newId = $this->drafts->insert($draft);

        $this->importMedia($newId, $siteId, $data['images'], $payload);
        $this->importChats($newId, $payload);

        return $this->drafts->find($newId) ?? $draft;
    }

    /**
     * Replaces an existing draft's content with an imported payload, keeping
     * the draft's own id, site and remote-article linkage untouched.
     *
     * @param array<string, mixed> $payload
     */
    public function replaceDraft(int $draftId, array $payload): Draft
    {
        $existing = $this->drafts->find($draftId);

        if ($existing === null) {
            throw new \RuntimeException('Draft not found');
        }

        $data = $this->draftDataFromPayload($payload);

        $draft = new Draft(
            id: $draftId,
            siteId: $existing->siteId,
            remoteId: $existing->remoteId,
            title: $data['title'],
            alias: $data['alias'],
            catid: $data['catid'],
            access: $data['access'],
            language: $data['language'],
            state: $data['state'],
            html: $data['html'],
            fields: $data['fields'],
            tags: $data['tags'],
            images: $data['images'],
            metadesc: $data['metadesc'],
            metakey: $data['metakey'],
        );

        $this->drafts->update($draft);

        foreach ($this->aiChats->forDraft($draftId) as $chat) {
            if ($chat->id !== null) {
                $this->aiChats->delete($chat->id);
            }
        }

        $this->importMedia($draftId, $existing->siteId, $data['images'], $payload);
        $this->importChats($draftId, $payload);

        return $this->drafts->find($draftId) ?? $draft;
    }

    /**
     * @param array<string, mixed> $images
     * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>}
     */
    private function exportImages(array $images): array
    {
        /** @var array<string, array<string, mixed>> $offlineMedia */
        $offlineMedia = [];
        $out          = $images;

        foreach (self::IMAGE_MEDIA_KEYS as $key) {
            $value = $images[$key] ?? null;

            if (!is_string($value) || !str_starts_with($value, self::MEDIA_REF_PREFIX)) {
                continue;
            }

            $id   = (int) substr($value, \strlen(self::MEDIA_REF_PREFIX));
            $blob = $this->media->find($id);

            if ($blob === null) {
                continue;
            }

            // Prefixed with a letter so PHP never auto-casts the array key to an
            // int (which a bare numeric string like "0" would trigger).
            $ref                = 'm' . \count($offlineMedia);
            $offlineMedia[$ref] = [
                'filename'   => $blob['filename'],
                'mime'       => $blob['mime'],
                'dataBase64' => base64_encode($blob['data']),
            ];
            $out[$key] = self::EXPORT_REF_PREFIX . $ref;
        }

        return [$out, $offlineMedia];
    }

    /**
     * @return list<array<string, mixed>>
     *
     * Deliberately omits `serviceId`, `previousResponseId` and `lastResponseAt`: the
     * response-id chain is a local, provider-specific artefact with no portable meaning
     * (exactly like `site_id`/`media_blobs` ids, which the format already refuses to
     * carry). `importChats()` therefore creates chats with a null chain, which is
     * correct — an imported chat must resend its full history on the next turn.
     */
    private function exportChats(int $draftId): array
    {
        $chats = [];

        foreach ($this->aiChats->forDraft($draftId) as $summary) {
            $full = $summary->id !== null ? $this->aiChats->find($summary->id) : null;

            if ($full === null) {
                continue;
            }

            $chats[] = [
                'title'    => $full->title,
                'messages' => array_map(
                    static fn (AiMessage $m): array => [
                        'role'      => $m->role,
                        'content'   => $m->content,
                        'toolKey'   => $m->toolKey,
                        'sortOrder' => $m->sortOrder,
                    ],
                    $full->messages
                ),
            ];
        }

        return $chats;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{title: string, alias: string, catid: ?int, access: int, language: string,
     *               state: int, html: string, fields: array<string, mixed>, tags: list<string>,
     *               images: array<string, mixed>, metadesc: string, metakey: string}
     */
    private function draftDataFromPayload(array $payload): array
    {
        $draftRaw = $payload['draft'] ?? [];
        $draftRaw = is_array($draftRaw) ? $draftRaw : [];

        $fieldsRaw = $draftRaw['fields'] ?? [];
        $tagsRaw   = $draftRaw['tags'] ?? [];
        $imagesRaw = $draftRaw['images'] ?? [];

        /** @var array<string, mixed> $fields */
        $fields = is_array($fieldsRaw) ? $fieldsRaw : [];
        /** @var array<string, mixed> $images */
        $images = is_array($imagesRaw) ? $imagesRaw : [];

        return [
            'title'    => is_string($draftRaw['title'] ?? null) ? $draftRaw['title'] : '',
            'alias'    => is_string($draftRaw['alias'] ?? null) ? $draftRaw['alias'] : '',
            'catid'    => is_numeric($draftRaw['catid'] ?? null) ? (int) $draftRaw['catid'] : null,
            'access'   => is_numeric($draftRaw['access'] ?? null) ? (int) $draftRaw['access'] : 1,
            'language' => is_string($draftRaw['language'] ?? null) ? $draftRaw['language'] : '*',
            'state'    => is_numeric($draftRaw['state'] ?? null) ? (int) $draftRaw['state'] : 1,
            'html'     => is_string($draftRaw['html'] ?? null) ? $draftRaw['html'] : '',
            'fields'   => $fields,
            'tags'     => is_array($tagsRaw) ? array_values(array_filter($tagsRaw, 'is_string')) : [],
            'images'   => $images,
            'metadesc' => is_string($draftRaw['metadesc'] ?? null) ? $draftRaw['metadesc'] : '',
            'metakey'  => is_string($draftRaw['metakey'] ?? null) ? $draftRaw['metakey'] : '',
        ];
    }

    /**
     * Re-materialises any embedded offline media as fresh local blobs and
     * rewrites the `grafida-media://export:N` sentinels to point at them.
     *
     * @param array<string, mixed> $images
     * @param array<string, mixed> $payload
     */
    private function importMedia(int $draftId, int $siteId, array $images, array $payload): void
    {
        $offlineMediaRaw = $payload['offlineMedia'] ?? [];
        $offlineMedia    = is_array($offlineMediaRaw) ? $offlineMediaRaw : [];

        $resolved = $images;
        $changed  = false;

        foreach (self::IMAGE_MEDIA_KEYS as $key) {
            $value = $images[$key] ?? null;

            if (!is_string($value) || !str_starts_with($value, self::EXPORT_REF_PREFIX)) {
                continue;
            }

            $ref   = substr($value, \strlen(self::EXPORT_REF_PREFIX));
            $entry = $offlineMedia[$ref] ?? null;

            if (!is_array($entry)) {
                continue;
            }

            $dataBase64 = $entry['dataBase64'] ?? '';
            $data       = base64_decode(is_string($dataBase64) ? $dataBase64 : '', true);

            if ($data === false) {
                continue;
            }

            $mediaId = $this->media->store(
                $siteId,
                $draftId,
                is_string($entry['filename'] ?? null) ? $entry['filename'] : 'image.png',
                is_string($entry['mime'] ?? null) ? $entry['mime'] : 'image/png',
                $data,
            );

            $resolved[$key] = self::MEDIA_REF_PREFIX . $mediaId;
            $changed        = true;
        }

        if (!$changed) {
            return;
        }

        $draft = $this->drafts->find($draftId);

        if ($draft === null) {
            return;
        }

        $draft->images = $resolved;
        $this->drafts->update($draft);
    }

    /** @param array<string, mixed> $payload */
    private function importChats(int $draftId, array $payload): void
    {
        $chatsRaw = $payload['aiChats'] ?? [];
        $chatsRaw = is_array($chatsRaw) ? $chatsRaw : [];

        foreach ($chatsRaw as $chatRaw) {
            if (!is_array($chatRaw)) {
                continue;
            }

            $messagesRaw = $chatRaw['messages'] ?? [];
            $messagesRaw = is_array($messagesRaw) ? $messagesRaw : [];

            $messages = [];

            foreach (array_values($messagesRaw) as $i => $m) {
                if (!is_array($m)) {
                    continue;
                }

                $messages[] = new AiMessage(
                    id: null,
                    chatId: null,
                    role: is_string($m['role'] ?? null) ? $m['role'] : 'user',
                    content: is_string($m['content'] ?? null) ? $m['content'] : '',
                    toolKey: is_string($m['toolKey'] ?? null) ? $m['toolKey'] : null,
                    sortOrder: is_numeric($m['sortOrder'] ?? null) ? (int) $m['sortOrder'] : $i,
                );
            }

            $this->aiChats->create(new AiChat(
                id: null,
                draftId: $draftId,
                serviceId: null,
                title: is_string($chatRaw['title'] ?? null) ? $chatRaw['title'] : '',
                messages: $messages,
            ));
        }
    }
}
