<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Publish;

use Grafida\Article\Draft;
use Grafida\Article\DraftRepository;
use Grafida\Field\FieldSupport;
use Grafida\Html\ContentSplitter;
use Grafida\Html\InlineMedia;
use Grafida\Joomla\ApiClient;
use Grafida\Media\MediaRepository;
use Grafida\Reference\ReferenceService;
use Grafida\Site\Site;
use Grafida\Site\SiteService;

/**
 * Publishes a local draft to its Joomla site.
 *
 * Pipeline:
 *   1. Block if the site requires unsupported custom field types.
 *   2. Upload offline images and swap their data: URIs for public URLs.
 *   3. Create any tags that do not yet exist and resolve all tags to IDs.
 *   4. Split the HTML into introtext / fulltext on the read-more marker.
 *   5. Map supported custom-field values into `com_fields`.
 *   6. POST a new article (or PATCH an existing one) and remember its remote ID.
 */
final class PublishService
{
    private const READMORE_MARKER = "\n<hr id=\"system-readmore\" />\n";

    public function __construct(
        private readonly SiteService $sites,
        private readonly ApiClient $api,
        private readonly ReferenceService $references,
        private readonly DraftRepository $drafts,
        private readonly MediaRepository $media,
        private readonly FieldSupport $fields = new FieldSupport(),
        private readonly ContentSplitter $splitter = new ContentSplitter(),
        private readonly InlineMedia $inlineMedia = new InlineMedia(),
    ) {}

    /**
     * @return array{remoteId: int, created: bool}
     *
     * @throws PublishBlockedException        When required unsupported fields exist.
     * @throws \Grafida\Joomla\ApiException   On any API failure.
     * @throws \RuntimeException              When the site is not connectable.
     */
    public function publish(Draft $draft, Site $site): array
    {
        $token = $this->sites->tokenFor($site);

        if ($token === null || $site->apiBase === null) {
            throw new \RuntimeException('The site is not connected; test the connection first.');
        }

        $base = $site->apiBase;

        $fieldDefs = $this->references->fields($site);
        $this->guardRequiredUnsupportedFields($fieldDefs, $draft->html);

        $html = $this->uploadOfflineMedia($draft, $site, $base, $token);

        $tagIds = $this->resolveTags($draft->tags, $site, $base, $token);

        $split      = $this->splitter->split($html);
        $articletext = $split['fulltext'] === ''
            ? $split['introtext']
            : $split['introtext'] . self::READMORE_MARKER . $split['fulltext'];

        // Always-present attributes.
        $attributes = [
            'title'       => $draft->title,
            'catid'       => $draft->catid,
            'access'      => $draft->access,
            'state'       => $draft->state,
            'language'    => $draft->language,
            'articletext' => $articletext,
        ];

        // Optional attributes, included only when they carry a value.
        if ($draft->alias !== '') {
            $attributes['alias'] = $draft->alias;
        }
        if ($draft->metadesc !== '') {
            $attributes['metadesc'] = $draft->metadesc;
        }
        if ($draft->metakey !== '') {
            $attributes['metakey'] = $draft->metakey;
        }
        if ($draft->images !== []) {
            $attributes['images'] = $draft->images;
        }
        if ($tagIds !== []) {
            $attributes['tags'] = $tagIds;
        }
        $mappedFields = $this->mapFields($draft->fields, $fieldDefs);
        if ($mappedFields !== []) {
            $attributes['com_fields'] = $mappedFields;
        }

        if ($draft->remoteId === null) {
            $article = $this->api->createArticle($base, $token, $attributes);
            $created = true;
        } else {
            $article = $this->api->updateArticle($base, $token, $draft->remoteId, $attributes);
            $created = false;
        }

        $articleId = $article['id'] ?? null;
        $remoteId  = is_int($articleId) ? $articleId : (is_numeric($articleId) ? (int) $articleId : ($draft->remoteId ?? 0));

        if ($draft->id !== null && $remoteId > 0) {
            $this->drafts->setRemoteId($draft->id, $remoteId);
        }

        return ['remoteId' => $remoteId, 'created' => $created];
    }

    /**
     * @param list<array<string, mixed>> $fieldDefs
     */
    private function guardRequiredUnsupportedFields(array $fieldDefs, string $html): void
    {
        $blocking = $this->fields->blockingFields($fieldDefs);

        if ($blocking === []) {
            return;
        }

        $labels = array_map(
            static function (array $f): string {
                $label = $f['label'] ?? $f['name'] ?? 'field';

                return is_string($label) ? $label : 'field';
            },
            $blocking
        );

        throw new PublishBlockedException(array_values($labels), $html);
    }

    private function uploadOfflineMedia(Draft $draft, Site $site, string $base, string $token): string
    {
        $pending = $this->inlineMedia->pendingMediaIds($draft->html);

        if ($pending === []) {
            return $draft->html;
        }

        $map = [];

        foreach ($pending as $mediaId) {
            $blob = $this->media->find($mediaId);

            if ($blob === null) {
                continue;
            }

            if ($blob['remote_url'] !== null) {
                $map[$mediaId] = $blob['remote_url'];

                continue;
            }

            $path        = 'images/grafida/' . $this->safeName($blob['filename'], $mediaId);
            $resource    = $this->api->uploadMedia($base, $token, $path, $blob['data']);
            $resourceUrl = $resource['url'] ?? null;
            $url         = is_string($resourceUrl) ? $resourceUrl : ($site->baseUrl . '/' . $path);

            $this->media->markUploaded($mediaId, $path, $url);
            $map[$mediaId] = $url;
        }

        return $this->inlineMedia->applyUploadedUrls($draft->html, $map);
    }

    /**
     * Resolves draft tag titles to Joomla tag IDs, creating any that are new.
     *
     * @param list<string> $tagTitles
     *
     * @return list<int>
     */
    private function resolveTags(array $tagTitles, Site $site, string $base, string $token): array
    {
        if ($tagTitles === []) {
            return [];
        }

        $existing = [];
        foreach ($this->references->tags($site) as $tag) {
            if (isset($tag['title'], $tag['id']) && is_string($tag['title']) && (is_int($tag['id']) || is_string($tag['id']))) {
                $existing[mb_strtolower($tag['title'])] = (int) $tag['id'];
            }
        }

        $ids     = [];
        $created = false;

        foreach ($tagTitles as $title) {
            $title = trim($title);

            if ($title === '') {
                continue;
            }

            $key = mb_strtolower($title);

            if (isset($existing[$key])) {
                $ids[] = $existing[$key];

                continue;
            }

            $new     = $this->api->createTag($base, $token, $title);
            $newId   = $new['id'] ?? null;
            $newIdInt = is_numeric($newId) ? (int) $newId : 0;
            $ids[]          = $newIdInt;
            $existing[$key] = $newIdInt;
            $created        = true;
        }

        if ($created) {
            $this->references->tags($site, true); // refresh cache with the new tags
        }

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    /**
     * @param array<string, mixed>       $values
     * @param list<array<string, mixed>> $fieldDefs
     *
     * @return array<string, mixed>
     */
    private function mapFields(array $values, array $fieldDefs): array
    {
        $supportedNames = [];
        foreach ($fieldDefs as $def) {
            $defName = $def['name'] ?? null;
            $defType = $def['type'] ?? null;
            $name    = is_string($defName) ? $defName : '';
            $type    = is_string($defType) ? $defType : '';
            if ($name !== '' && $this->fields->isSupported($type)) {
                $supportedNames[$name] = true;
            }
        }

        $out = [];
        foreach ($values as $name => $value) {
            if (isset($supportedNames[$name])) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    private function safeName(string $filename, int $mediaId): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?? 'image';
        $name = trim($name, '-');

        if ($name === '' || !str_contains($name, '.')) {
            $name = $mediaId . '-' . ($name === '' ? 'image.png' : $name . '.png');
        } else {
            $name = $mediaId . '-' . $name;
        }

        return $name;
    }
}
