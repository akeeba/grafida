<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Article;

use PDO;

/**
 * Data-access for locally stored article drafts.
 */
final class DraftRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    /** @return list<Draft> */
    public function forSite(int $siteId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drafts WHERE site_id = ? ORDER BY updated_at DESC');
        $stmt->execute([$siteId]);

        /** @var list<array{id?: int|string|null, site_id: int|string, remote_id: int|string|null, title: string, alias: string, catid: int|string|null, access: int|string, language: string, state: int|string, html: string, fields_json: string, tags_json: string, images_json: string, metadesc?: string, metakey?: string}> $rows */
        $rows = $stmt->fetchAll();

        return array_values(array_map(static fn (array $r): Draft => Draft::fromRow($r), $rows));
    }

    public function find(int $id): ?Draft
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drafts WHERE id = ?');
        $stmt->execute([$id]);
        /** @var array{id?: int|string|null, site_id: int|string, remote_id: int|string|null, title: string, alias: string, catid: int|string|null, access: int|string, language: string, state: int|string, html: string, fields_json: string, tags_json: string, images_json: string, metadesc?: string, metakey?: string}|false $row */
        $row = $stmt->fetch();

        return $row !== false ? Draft::fromRow($row) : null;
    }

    /** Inserts a new draft and returns its id. */
    public function insert(Draft $draft): int
    {
        $now  = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO drafts (site_id, remote_id, title, alias, catid, access, language, state, html, '
            . 'fields_json, tags_json, images_json, metadesc, metakey, created_at, updated_at) VALUES '
            . '(:site_id, :remote_id, :title, :alias, :catid, :access, :language, :state, :html, '
            . ':fields, :tags, :images, :metadesc, :metakey, :now, :now)'
        );
        $stmt->execute($this->bind($draft) + [':now' => $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(Draft $draft): void
    {
        if ($draft->id === null) {
            throw new \InvalidArgumentException('Cannot update a draft without an id.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE drafts SET remote_id = :remote_id, title = :title, alias = :alias, catid = :catid, '
            . 'access = :access, language = :language, state = :state, html = :html, fields_json = :fields, '
            . 'tags_json = :tags, images_json = :images, metadesc = :metadesc, metakey = :metakey, '
            . 'updated_at = :now WHERE id = :id'
        );
        $stmt->execute($this->bind($draft) + [':now' => gmdate('Y-m-d H:i:s'), ':id' => $draft->id]);
    }

    public function setRemoteId(int $id, int $remoteId): void
    {
        $stmt = $this->pdo->prepare('UPDATE drafts SET remote_id = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$remoteId, gmdate('Y-m-d H:i:s'), $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM drafts WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** @return array<string, mixed> */
    private function bind(Draft $draft): array
    {
        return [
            ':site_id'   => $draft->siteId,
            ':remote_id' => $draft->remoteId,
            ':title'     => $draft->title,
            ':alias'     => $draft->alias,
            ':catid'     => $draft->catid,
            ':access'    => $draft->access,
            ':language'  => $draft->language,
            ':state'     => $draft->state,
            ':html'      => $draft->html,
            ':fields'    => json_encode($draft->fields, \JSON_UNESCAPED_UNICODE),
            ':tags'      => json_encode($draft->tags, \JSON_UNESCAPED_UNICODE),
            ':images'    => json_encode($draft->images, \JSON_UNESCAPED_UNICODE),
            ':metadesc'  => $draft->metadesc,
            ':metakey'   => $draft->metakey,
        ];
    }
}
