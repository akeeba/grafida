<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Site;

use Grafida\Storage\QueryBuilderSupport;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Data-access for the `sites` table.
 */
final class SiteRepository
{
    use QueryBuilderSupport;

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /** @return list<Site> */
    public function all(): array
    {
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->qn('sites'))
            ->order($this->qn('title') . ' COLLATE NOCASE');

        /** @var list<array{id?: int|string|null, title: string, base_url: string, api_base: string|null, secret_ref: string|null, insecure_token: string|int|null, default_language?: string, editor_css_url?: string|null}> $rows */
        $rows = $this->db->setQuery($query)->loadAssocList();

        return array_values(array_map(static fn (array $row): Site => Site::fromRow($row), $rows));
    }

    public function find(int $id): ?Site
    {
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->qn('sites'))
            ->where($this->qn('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        /** @var array{id?: int|string|null, title: string, base_url: string, api_base: string|null, secret_ref: string|null, insecure_token: string|int|null, default_language?: string, editor_css_url?: string|null}|null $row */
        $row = $this->db->setQuery($query)->loadAssoc();

        return $row !== null ? Site::fromRow($row) : null;
    }

    /** Returns the raw plaintext token stored for an insecure site (or null). */
    public function insecureToken(int $id): ?string
    {
        $query = $this->db->createQuery()
            ->select($this->qn('insecure_token'))
            ->from($this->qn('sites'))
            ->where($this->qn('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $value = $this->db->setQuery($query)->loadResult();

        return $this->toStringOrNull($value);
    }

    public function insert(
        string $title,
        string $baseUrl,
        ?string $apiBase,
        ?string $secretRef,
        ?string $insecureToken,
        string $defaultLanguage = '*',
        ?string $editorCssUrl = null,
    ): int {
        $now = gmdate('Y-m-d H:i:s');

        $query = $this->db->createQuery()
            ->insert($this->qn('sites'))
            ->columns([
                $this->qn('title'),
                $this->qn('base_url'),
                $this->qn('api_base'),
                $this->qn('secret_ref'),
                $this->qn('insecure_token'),
                $this->qn('default_language'),
                $this->qn('editor_css_url'),
                $this->qn('created_at'),
                $this->qn('updated_at'),
            ])
            ->values(':title, :base_url, :api_base, :secret_ref, :insecure_token, :lang, :editor_css_url, :created_at, :updated_at')
            ->bind(':title', $title, ParameterType::STRING)
            ->bind(':base_url', $baseUrl, ParameterType::STRING)
            ->bind(':api_base', $apiBase, $apiBase === null ? ParameterType::NULL : ParameterType::STRING)
            ->bind(':secret_ref', $secretRef, $secretRef === null ? ParameterType::NULL : ParameterType::STRING)
            ->bind(':insecure_token', $insecureToken, $insecureToken === null ? ParameterType::NULL : ParameterType::STRING)
            ->bind(':lang', $defaultLanguage, ParameterType::STRING)
            ->bind(':editor_css_url', $editorCssUrl, $editorCssUrl === null ? ParameterType::NULL : ParameterType::STRING)
            ->bind(':created_at', $now, ParameterType::STRING)
            ->bind(':updated_at', $now, ParameterType::STRING);

        $this->db->setQuery($query)->execute();

        return $this->lastInsertId();
    }

    public function update(
        int $id,
        string $title,
        string $baseUrl,
        ?string $apiBase,
        ?string $secretRef,
        ?string $insecureToken,
        string $defaultLanguage = '*',
        ?string $editorCssUrl = null,
    ): void {
        $now = gmdate('Y-m-d H:i:s');

        $query = $this->db->createQuery()
            ->update($this->qn('sites'))
            ->set($this->qn('title') . ' = :title')
            ->set($this->qn('base_url') . ' = :base_url')
            ->set($this->qn('api_base') . ' = :api_base')
            ->set($this->qn('secret_ref') . ' = :secret_ref')
            ->set($this->qn('insecure_token') . ' = :insecure_token')
            ->set($this->qn('default_language') . ' = :lang')
            ->set($this->qn('editor_css_url') . ' = :editor_css_url')
            ->set($this->qn('updated_at') . ' = :now')
            ->where($this->qn('id') . ' = :id')
            ->bind(':title', $title, ParameterType::STRING)
            ->bind(':base_url', $baseUrl, ParameterType::STRING)
            ->bind(':api_base', $apiBase, $apiBase === null ? ParameterType::NULL : ParameterType::STRING)
            ->bind(':secret_ref', $secretRef, $secretRef === null ? ParameterType::NULL : ParameterType::STRING)
            ->bind(':insecure_token', $insecureToken, $insecureToken === null ? ParameterType::NULL : ParameterType::STRING)
            ->bind(':lang', $defaultLanguage, ParameterType::STRING)
            ->bind(':editor_css_url', $editorCssUrl, $editorCssUrl === null ? ParameterType::NULL : ParameterType::STRING)
            ->bind(':now', $now, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    public function delete(int $id): void
    {
        $query = $this->db->createQuery()
            ->delete($this->qn('sites'))
            ->where($this->qn('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }
}
