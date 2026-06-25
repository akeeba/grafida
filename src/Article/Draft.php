<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Article;

/**
 * A locally-stored article draft.
 */
final class Draft
{
    /**
     * @param array<string, mixed> $fields Custom field values keyed by field name.
     * @param list<string>         $tags   Tag titles (existing or new).
     * @param array<string, mixed> $images Joomla article `images` object.
     */
    public function __construct(
        public ?int $id,
        public int $siteId,
        public ?int $remoteId,
        public string $title,
        public string $alias,
        public ?int $catid,
        public int $access,
        public string $language,
        public int $state,
        public string $html,
        public array $fields = [],
        public array $tags = [],
        public array $images = [],
        public string $metadesc = '',
        public string $metakey = '',
    ) {}

    /**
     * @param array{id?: int|string|null, site_id: int|string, remote_id: int|string|null,
     *             title: string, alias: string, catid: int|string|null, access: int|string,
     *             language: string, state: int|string, html: string, fields_json: string,
     *             tags_json: string, images_json: string, metadesc?: string, metakey?: string} $row
     */
    public static function fromRow(array $row): self
    {
        $fieldsRaw = json_decode($row['fields_json'], true);
        $tagsRaw   = json_decode($row['tags_json'], true);
        $imagesRaw = json_decode($row['images_json'], true);

        /** @var array<string, mixed> $fields */
        $fields = is_array($fieldsRaw) ? $fieldsRaw : [];
        /** @var list<string> $tags */
        $tags   = is_array($tagsRaw) ? array_values(array_filter($tagsRaw, 'is_string')) : [];
        /** @var array<string, mixed> $images */
        $images = is_array($imagesRaw) ? $imagesRaw : [];

        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            siteId: (int) $row['site_id'],
            remoteId: $row['remote_id'] !== null ? (int) $row['remote_id'] : null,
            title: $row['title'],
            alias: $row['alias'],
            catid: $row['catid'] !== null ? (int) $row['catid'] : null,
            access: (int) $row['access'],
            language: $row['language'],
            state: (int) $row['state'],
            html: $row['html'],
            fields: $fields,
            tags: $tags,
            images: $images,
            metadesc: $row['metadesc'] ?? '',
            metakey: $row['metakey'] ?? '',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'siteId'    => $this->siteId,
            'remoteId'  => $this->remoteId,
            'title'     => $this->title,
            'alias'     => $this->alias,
            'catid'     => $this->catid,
            'access'    => $this->access,
            'language'  => $this->language,
            'state'     => $this->state,
            'html'      => $this->html,
            'fields'    => $this->fields,
            'tags'      => $this->tags,
            'images'    => $this->images,
            'metadesc'  => $this->metadesc,
            'metakey'   => $this->metakey,
        ];
    }
}
