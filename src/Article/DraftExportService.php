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
use Grafida\Html\HtmlDocument;
use Grafida\Html\InlineMedia;
use Grafida\Media\InlineImageExtractor;
use Grafida\Media\LocalMediaUrl;
use Grafida\Media\MediaRepository;
use Grafida\Support\App;

/**
 * Builds and consumes the portable `.grafida` draft export format.
 *
 * The format carries every visible article field plus saved AI chats and any
 * locally-picked (not-yet-published) images, embedded as base64. It never
 * carries `site_id` / `remote_id` (those are local-install specifics), nor
 * the local `media_blobs` / `ai_services` row ids (also local-install only).
 *
 * Since gh-36 an inline body image is no longer self-carrying — it is a
 * `boson://app/api/media/{id}/raw` reference to a row that means nothing on
 * another install — so `exportHtml()`/`importMedia()` give the body the same
 * `offlineMedia` embed-and-rematerialise treatment `exportImages()` already
 * gave the intro/full-text `grafida-media://N` sentinels, sharing the very
 * same `$offlineMedia` map and `mN` ref counter so refs stay unique across
 * both. A **legacy** inline `data:` image (self-carrying, pre-dating gh-36)
 * is left exactly as it was: it survives the export/import round-trip on its
 * own and {@see \Grafida\Media\InlineImageExtractor} converts it to a blob on
 * the importing install, same as it does for a legacy draft that is merely
 * opened rather than exported.
 */
final class DraftExportService
{
    // Bumped for gh-36: a body image is now embedded under `offlineMedia`
    // (via a `grafida-media://export:mN` sentinel) rather than being
    // self-carrying `data:` bytes inside `html`. Purely informational today —
    // nothing here gates on it, since the importer already handles both an
    // old file's inline `data:` images (left untouched by exportHtml() below,
    // converted by InlineImageExtractor on import) and a new file's refs
    // unconditionally — but a future version-aware importer has something to
    // check against.
    private const FORMAT_VERSION = 2;

    /** @var list<string> Draft `images` subfields that may hold a `grafida-media://` sentinel. */
    private const IMAGE_MEDIA_KEYS = ['image_intro', 'image_fulltext'];

    private const MEDIA_REF_PREFIX = 'grafida-media://';

    private const EXPORT_REF_PREFIX = self::MEDIA_REF_PREFIX . 'export:';

    public function __construct(
        private readonly DraftRepository $drafts,
        private readonly MediaRepository $media,
        private readonly AiChatRepository $aiChats,
        private readonly InlineImageExtractor $inlineImages,
        private readonly InlineMedia $inlineMedia = new InlineMedia(),
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

        /** @var array<string, array<string, mixed>> $offlineMedia */
        $offlineMedia = [];
        $images       = $this->exportImages($draft->images, $offlineMedia);
        // Shares $offlineMedia (and its mN ref counter) with exportImages()
        // above so a body image and an intro/full-text image never collide
        // on the same ref.
        $html = $this->exportHtml($draft->html, $offlineMedia);

        return [
            'grafidaExport' => self::FORMAT_VERSION,
            'exportedAt'    => gmdate('c'),
            'appVersion'    => App::VERSION,
            'draft'         => [
                'title'          => $draft->title,
                'alias'          => $draft->alias,
                'catid'          => $draft->catid,
                'access'         => $draft->access,
                'language'       => $draft->language,
                'state'          => $draft->state,
                'html'           => $html,
                'fields'         => $draft->fields,
                'tags'           => $draft->tags,
                'images'         => $images,
                'metadesc'       => $draft->metadesc,
                'metakey'        => $draft->metakey,
                'createdByAlias' => $draft->createdByAlias,
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
            createdByAlias: $data['createdByAlias'],
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
            createdByAlias: $data['createdByAlias'],
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
     * @param array<string, array<string, mixed>> $offlineMedia written into by
     *        reference so {@see exportHtml()} continues the same `mN` ref
     *        counter rather than restarting it and colliding with these refs
     * @return array<string, mixed>
     */
    private function exportImages(array $images, array &$offlineMedia): array
    {
        $out = $images;

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

        return $out;
    }

    /**
     * Embeds every body `<img>` that references a local media blob
     * (`boson://app/api/media/{id}/raw…`, see {@see \Grafida\Html\InlineMedia})
     * into `$offlineMedia` the same way {@see exportImages()} embeds the
     * intro/full-text images, and rewrites its `src` to the matching
     * `grafida-media://export:mN` sentinel. A **legacy** inline `data:` image
     * is left completely untouched — it is already self-carrying, and
     * {@see \Grafida\Media\InlineImageExtractor} converts it to a blob on the
     * importing install regardless of whether it arrived via export/import or
     * a plain draft open. A local-URL image whose blob has since been deleted
     * (e.g. from the Local Media tab) is also left as-is — a broken image in
     * the export is a far better outcome than a failed export.
     *
     * @param array<string, array<string, mixed>> $offlineMedia written into by
     *        reference, continuing {@see exportImages()}'s ref counter
     */
    private function exportHtml(string $html, array &$offlineMedia): string
    {
        if (trim($html) === '' || !str_contains($html, InlineMedia::LOCAL_URL_PREFIX)) {
            return $html;
        }

        $dom     = HtmlDocument::load($html);
        $changed = false;

        // The same picture is commonly referenced by more than one <img> in
        // one article body (a thumbnail repeated further down, say) — track
        // which ref a blob id already got so a repeat reuses it instead of
        // embedding the same bytes a second time.
        /** @var array<int, string> $refsByMediaId */
        $refsByMediaId = [];

        foreach ($dom->getElementsByTagName('img') as $img) {
            $src = $img->getAttribute('src');

            if (!str_starts_with($src, InlineMedia::LOCAL_URL_PREFIX)) {
                continue;
            }

            $id = $this->inlineMedia->idFromLocalUrl($src);

            if ($id === null) {
                continue;
            }

            $ref = $refsByMediaId[$id] ?? null;

            if ($ref === null) {
                $blob = $this->media->find($id);

                if ($blob === null) {
                    continue;
                }

                $ref                = 'm' . \count($offlineMedia);
                $offlineMedia[$ref] = [
                    'filename'   => $blob['filename'],
                    'mime'       => $blob['mime'],
                    'dataBase64' => base64_encode($blob['data']),
                ];
                $refsByMediaId[$id] = $ref;
            }

            $img->setAttribute('src', self::EXPORT_REF_PREFIX . $ref);
            $img->removeAttribute(InlineMedia::ATTRIBUTE);
            $changed = true;
        }

        return $changed ? HtmlDocument::innerHtml($dom) : $html;
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
     *               images: array<string, mixed>, metadesc: string, metakey: string,
     *               createdByAlias: string}
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
            'title'          => is_string($draftRaw['title'] ?? null) ? $draftRaw['title'] : '',
            'alias'          => is_string($draftRaw['alias'] ?? null) ? $draftRaw['alias'] : '',
            'catid'          => is_numeric($draftRaw['catid'] ?? null) ? (int) $draftRaw['catid'] : null,
            'access'         => is_numeric($draftRaw['access'] ?? null) ? (int) $draftRaw['access'] : 1,
            'language'       => is_string($draftRaw['language'] ?? null) ? $draftRaw['language'] : '*',
            'state'          => is_numeric($draftRaw['state'] ?? null) ? (int) $draftRaw['state'] : 1,
            'html'           => is_string($draftRaw['html'] ?? null) ? $draftRaw['html'] : '',
            'fields'         => $fields,
            'tags'           => is_array($tagsRaw) ? array_values(array_filter($tagsRaw, 'is_string')) : [],
            'images'         => $images,
            'metadesc'       => is_string($draftRaw['metadesc'] ?? null) ? $draftRaw['metadesc'] : '',
            'metakey'        => is_string($draftRaw['metakey'] ?? null) ? $draftRaw['metakey'] : '',
            // Absent from files written before this field existed, hence the ''
            // default — which is why the format version does not need a bump.
            'createdByAlias' => is_string($draftRaw['createdByAlias'] ?? null) ? $draftRaw['createdByAlias'] : '',
        ];
    }

    /**
     * Re-materialises any embedded offline media as fresh **new** local blobs
     * (never aliasing the exporting install's originals — that is what makes
     * importing the same file twice, or importing into the same install it
     * came from, produce two independently-editable copies) and rewrites the
     * `grafida-media://export:mN` sentinels — in both the intro/full-text
     * `images` subfields and the body's `<img>` elements — to point at them.
     * A ref used more than once (the same picture set as both the intro image
     * and inline in the body, say) is stored exactly once; see
     * {@see materializeRef()}. Finally runs {@see \Grafida\Media\InlineImageExtractor}
     * over the body so a **legacy** export's self-carrying inline `data:`
     * images — which never went through the `offlineMedia` embed at all,
     * since they predate gh-36 — are converted to blobs on this install too,
     * exactly as opening an old already-saved draft would.
     *
     * @param array<string, mixed> $images
     * @param array<string, mixed> $payload
     */
    private function importMedia(int $draftId, int $siteId, array $images, array $payload): void
    {
        $offlineMediaRaw = $payload['offlineMedia'] ?? [];
        /** @var array<string, mixed> $offlineMedia */
        $offlineMedia = is_array($offlineMediaRaw) ? $offlineMediaRaw : [];

        /** @var array<string, int> $resolvedRefs ref => newly stored blob id, shared across the images subfields and the body */
        $resolvedRefs = [];

        $resolvedImages = $images;
        $imagesChanged  = false;

        foreach (self::IMAGE_MEDIA_KEYS as $key) {
            $value = $images[$key] ?? null;

            if (!is_string($value) || !str_starts_with($value, self::EXPORT_REF_PREFIX)) {
                continue;
            }

            $mediaId = $this->materializeRef(
                substr($value, \strlen(self::EXPORT_REF_PREFIX)),
                $offlineMedia,
                $resolvedRefs,
                $siteId,
                $draftId,
            );

            if ($mediaId === null) {
                continue;
            }

            $resolvedImages[$key] = self::MEDIA_REF_PREFIX . $mediaId;
            $imagesChanged        = true;
        }

        $draft = $this->drafts->find($draftId);

        if ($draft === null) {
            return;
        }

        $originalHtml = $draft->html;
        $html         = $this->importHtml($originalHtml, $offlineMedia, $resolvedRefs, $siteId, $draftId);
        $html         = $this->inlineImages->extract($html, $siteId, $draftId);

        if ($imagesChanged) {
            $draft->images = $resolvedImages;
        }

        if ($html !== $originalHtml) {
            $draft->html = $html;
        }

        if ($imagesChanged || $html !== $originalHtml) {
            $this->drafts->update($draft);
        }
    }

    /**
     * Rewrites every `<img src="grafida-media://export:mN">` in an imported
     * body to the freshly stored blob's local URL (re-adding
     * `data-grafida-media-id`, mirroring what the editor itself inserts). A
     * ref with no matching `offlineMedia` entry, or one whose blob failed to
     * store, is left exactly as it was — it renders broken, which is honest,
     * rather than failing the whole import.
     *
     * @param array<string, mixed> $offlineMedia
     * @param array<string, int> $resolvedRefs
     */
    private function importHtml(
        string $html,
        array $offlineMedia,
        array &$resolvedRefs,
        int $siteId,
        int $draftId,
    ): string {
        if (trim($html) === '' || !str_contains($html, self::EXPORT_REF_PREFIX)) {
            return $html;
        }

        $dom     = HtmlDocument::load($html);
        $changed = false;

        foreach ($dom->getElementsByTagName('img') as $img) {
            $src = $img->getAttribute('src');

            if (!str_starts_with($src, self::EXPORT_REF_PREFIX)) {
                continue;
            }

            $mediaId = $this->materializeRef(
                substr($src, \strlen(self::EXPORT_REF_PREFIX)),
                $offlineMedia,
                $resolvedRefs,
                $siteId,
                $draftId,
            );
            $meta = $mediaId !== null ? $this->media->findMeta($mediaId) : null;

            if ($meta === null) {
                continue;
            }

            $img->setAttribute('src', LocalMediaUrl::build($mediaId, $meta['updated_at'] ?? $meta['created_at']));
            $img->setAttribute(InlineMedia::ATTRIBUTE, (string) $mediaId);
            $changed = true;
        }

        return $changed ? HtmlDocument::innerHtml($dom) : $html;
    }

    /**
     * Stores an `offlineMedia` entry as a fresh blob, or returns the id
     * already stored for the same `$ref` earlier in this same import — the
     * same ref can legitimately appear twice (e.g. as both an intro image and
     * an inline body image), and it must resolve to one blob, not two.
     * Returns null when the ref has no matching entry or its `dataBase64`
     * cannot be decoded.
     *
     * @param array<string, mixed> $offlineMedia
     * @param array<string, int> $resolvedRefs
     */
    private function materializeRef(
        string $ref,
        array $offlineMedia,
        array &$resolvedRefs,
        int $siteId,
        int $draftId,
    ): ?int {
        if (isset($resolvedRefs[$ref])) {
            return $resolvedRefs[$ref];
        }

        $entry = $offlineMedia[$ref] ?? null;

        if (!is_array($entry)) {
            return null;
        }

        $dataBase64 = $entry['dataBase64'] ?? '';
        $data       = base64_decode(is_string($dataBase64) ? $dataBase64 : '', true);

        if ($data === false) {
            return null;
        }

        $mediaId = $this->media->store(
            $siteId,
            $draftId,
            is_string($entry['filename'] ?? null) ? $entry['filename'] : 'image.png',
            is_string($entry['mime'] ?? null) ? $entry['mime'] : 'image/png',
            $data,
        );

        $resolvedRefs[$ref] = $mediaId;

        return $mediaId;
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
