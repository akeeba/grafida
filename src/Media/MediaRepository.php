<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Media;

use Grafida\Storage\QueryBuilderSupport;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Data-access for offline media blobs (images inserted while editing offline).
 */
final class MediaRepository
{
    use QueryBuilderSupport;

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * Stores a raw image and returns its new id. `$width`/`$height` are the
     * caller's best-known intrinsic dimensions (typically from
     * {@see ImageInfo::dimensions()}); pass null when unknown.
     */
    public function store(
        int $siteId,
        ?int $draftId,
        string $filename,
        string $mime,
        string $data,
        ?int $width = null,
        ?int $height = null,
    ): int {
        $now  = gmdate('Y-m-d H:i:s');
        $size = strlen($data);

        $query = $this->db->createQuery()
            ->insert($this->qn('media_blobs'))
            ->columns([
                $this->qn('site_id'),
                $this->qn('draft_id'),
                $this->qn('filename'),
                $this->qn('mime'),
                $this->qn('data'),
                $this->qn('width'),
                $this->qn('height'),
                $this->qn('size'),
                $this->qn('created_at'),
                $this->qn('updated_at'),
            ])
            ->values(':site, :draft, :name, :mime, :data, :width, :height, :size, :now, :now2')
            ->bind(':site', $siteId, ParameterType::INTEGER)
            ->bind(':draft', $draftId, $draftId === null ? ParameterType::NULL : ParameterType::INTEGER)
            ->bind(':name', $filename, ParameterType::STRING)
            ->bind(':mime', $mime, ParameterType::STRING)
            ->bind(':data', $data, ParameterType::LARGE_OBJECT)
            ->bind(':width', $width, $width === null ? ParameterType::NULL : ParameterType::INTEGER)
            ->bind(':height', $height, $height === null ? ParameterType::NULL : ParameterType::INTEGER)
            ->bind(':size', $size, ParameterType::INTEGER)
            ->bind(':now', $now, ParameterType::STRING)
            ->bind(':now2', $now, ParameterType::STRING);

        $this->db->setQuery($query)->execute();

        return $this->lastInsertId();
    }

    /**
     * @return array{id: int, filename: string, mime: string, data: string, remote_path: ?string, remote_url: ?string}|null
     */
    public function find(int $id): ?array
    {
        $query = $this->db->createQuery()
            ->select([
                $this->qn('id'),
                $this->qn('filename'),
                $this->qn('mime'),
                $this->qn('data'),
                $this->qn('remote_path'),
                $this->qn('remote_url'),
            ])
            ->from($this->qn('media_blobs'))
            ->where($this->qn('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        /** @var array{id: int|string, filename: string, mime: string, data: string, remote_path: string|null, remote_url: string|null}|null $row */
        $row = $this->db->setQuery($query)->loadAssoc();

        if ($row === null) {
            return null;
        }

        return [
            'id'          => (int) $row['id'],
            'filename'    => $row['filename'],
            'mime'        => $row['mime'],
            'data'        => $row['data'],
            'remote_path' => $row['remote_path'] !== null ? $row['remote_path'] : null,
            'remote_url'  => $row['remote_url'] !== null ? $row['remote_url'] : null,
        ];
    }

    /** Records the remote path/URL after a successful upload. */
    public function markUploaded(int $id, string $remotePath, string $remoteUrl): void
    {
        $query = $this->db->createQuery()
            ->update($this->qn('media_blobs'))
            ->set($this->qn('remote_path') . ' = :path')
            ->set($this->qn('remote_url') . ' = :url')
            ->where($this->qn('id') . ' = :id')
            ->bind(':path', $remotePath, ParameterType::STRING)
            ->bind(':url', $remoteUrl, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    /** Returns the data: URI representation of a stored blob. */
    public function dataUri(int $id): ?string
    {
        $blob = $this->find($id);

        if ($blob === null) {
            return null;
        }

        return 'data:' . $blob['mime'] . ';base64,' . base64_encode($blob['data']);
    }

    /**
     * The raw bytes of a blob, and nothing else — kept separate from
     * {@see find()} so the raw-serving endpoint (`GET /api/media/{id}/raw`)
     * can fetch the headers it needs via {@see findMeta()} without also
     * pulling the (potentially multi-megabyte) `data` column into memory
     * twice.
     */
    public function data(int $id): ?string
    {
        $query = $this->db->createQuery()
            ->select($this->qn('data'))
            ->from($this->qn('media_blobs'))
            ->where($this->qn('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        /** @var string|null $data */
        $data = $this->db->setQuery($query)->loadResult();

        return $data;
    }

    /**
     * Everything about a blob except its bytes — for the raw endpoint's
     * headers (mime, size) and for anywhere that needs the metadata without
     * pulling potentially megabytes of `data` into PHP memory.
     *
     * @return array{
     *     id: int, site_id: int, draft_id: ?int, filename: string, mime: string,
     *     width: ?int, height: ?int, size: ?int, remote_path: ?string,
     *     remote_url: ?string, created_at: string, updated_at: ?string
     * }|null
     */
    public function findMeta(int $id): ?array
    {
        $query = $this->db->createQuery()
            ->select([
                $this->qn('id'),
                $this->qn('site_id'),
                $this->qn('draft_id'),
                $this->qn('filename'),
                $this->qn('mime'),
                $this->qn('width'),
                $this->qn('height'),
                $this->qn('size'),
                $this->qn('remote_path'),
                $this->qn('remote_url'),
                $this->qn('created_at'),
                $this->qn('updated_at'),
            ])
            ->from($this->qn('media_blobs'))
            ->where($this->qn('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        /**
         * @var array{
         *     id: int|string, site_id: int|string, draft_id: int|string|null,
         *     filename: string, mime: string, width: int|string|null,
         *     height: int|string|null, size: int|string|null,
         *     remote_path: string|null, remote_url: string|null,
         *     created_at: string, updated_at: string|null
         * }|null $row
         */
        $row = $this->db->setQuery($query)->loadAssoc();

        if ($row === null) {
            return null;
        }

        return $this->metaFromRow($row);
    }

    /**
     * Metadata rows for every blob belonging to a site, newest edit first
     * (falling back to `created_at` for a pre-migration row with no
     * `updated_at`), with the owning draft's title so the Local Media tab can
     * show "used by <article>" — a blob's `draft_id` may be NULL (not yet
     * attached to any draft) or point at a since-deleted draft, either of
     * which must still list the blob, hence the LEFT JOIN.
     *
     * @return list<array{
     *     id: int, site_id: int, draft_id: ?int, filename: string, mime: string,
     *     width: ?int, height: ?int, size: ?int, remote_path: ?string,
     *     remote_url: ?string, created_at: string, updated_at: ?string,
     *     draft_title: ?string
     * }>
     */
    public function listForSite(int $siteId): array
    {
        $query = $this->db->createQuery()
            ->select([
                'm.' . $this->qn('id'),
                'm.' . $this->qn('site_id'),
                'm.' . $this->qn('draft_id'),
                'm.' . $this->qn('filename'),
                'm.' . $this->qn('mime'),
                'm.' . $this->qn('width'),
                'm.' . $this->qn('height'),
                'm.' . $this->qn('size'),
                'm.' . $this->qn('remote_path'),
                'm.' . $this->qn('remote_url'),
                'm.' . $this->qn('created_at'),
                'm.' . $this->qn('updated_at'),
                'd.' . $this->qn('title') . ' AS ' . $this->qn('draft_title'),
            ])
            ->from($this->qn('media_blobs') . ' AS m')
            ->join('LEFT', $this->qn('drafts') . ' AS d ON d.' . $this->qn('id') . ' = m.' . $this->qn('draft_id'))
            ->where('m.' . $this->qn('site_id') . ' = :site')
            ->order('COALESCE(m.' . $this->qn('updated_at') . ', m.' . $this->qn('created_at') . ') DESC')
            ->bind(':site', $siteId, ParameterType::INTEGER);

        /**
         * @var list<array{
         *     id: int|string, site_id: int|string, draft_id: int|string|null,
         *     filename: string, mime: string, width: int|string|null,
         *     height: int|string|null, size: int|string|null,
         *     remote_path: string|null, remote_url: string|null,
         *     created_at: string, updated_at: string|null, draft_title: string|null
         * }> $rows
         */
        $rows = $this->db->setQuery($query)->loadAssocList() ?? [];

        return array_map(
            function (array $row): array {
                $draftTitle = $row['draft_title'];
                unset($row['draft_title']);

                return [...$this->metaFromRow($row), 'draft_title' => $draftTitle];
            },
            $rows,
        );
    }

    /**
     * Replaces a blob's bytes (the Local Media image editor's crop/resize/
     * rotate/flip save path) and bumps its revision. `remote_path`/
     * `remote_url` are cleared: they cache a previous upload of the *old*
     * bytes, and leaving them would make a later publish reuse the stale
     * remote file — see `PublishService::uploadBlob()`, which short-circuits
     * whenever they are set.
     */
    public function replaceData(int $id, string $data, string $mime, ?int $width, ?int $height): void
    {
        $now  = gmdate('Y-m-d H:i:s');
        $size = strlen($data);

        $query = $this->db->createQuery()
            ->update($this->qn('media_blobs'))
            ->set($this->qn('data') . ' = :data')
            ->set($this->qn('mime') . ' = :mime')
            ->set($this->qn('width') . ' = :width')
            ->set($this->qn('height') . ' = :height')
            ->set($this->qn('size') . ' = :size')
            ->set($this->qn('updated_at') . ' = :now')
            ->set($this->qn('remote_path') . ' = NULL')
            ->set($this->qn('remote_url') . ' = NULL')
            ->where($this->qn('id') . ' = :id')
            ->bind(':data', $data, ParameterType::LARGE_OBJECT)
            ->bind(':mime', $mime, ParameterType::STRING)
            ->bind(':width', $width, $width === null ? ParameterType::NULL : ParameterType::INTEGER)
            ->bind(':height', $height, $height === null ? ParameterType::NULL : ParameterType::INTEGER)
            ->bind(':size', $size, ParameterType::INTEGER)
            ->bind(':now', $now, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    /** Renames a blob (the Local Media tab's Rename action), bumping its revision. */
    public function rename(int $id, string $filename): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $query = $this->db->createQuery()
            ->update($this->qn('media_blobs'))
            ->set($this->qn('filename') . ' = :name')
            ->set($this->qn('updated_at') . ' = :now')
            ->where($this->qn('id') . ' = :id')
            ->bind(':name', $filename, ParameterType::STRING)
            ->bind(':now', $now, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    /** Deletes a blob outright (the Local Media tab's Delete action). */
    public function delete(int $id): void
    {
        $query = $this->db->createQuery()
            ->delete($this->qn('media_blobs'))
            ->where($this->qn('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    /**
     * Narrows one `media_blobs` row (minus `data`) to the typed metadata
     * shape shared by {@see findMeta()} and {@see listForSite()}.
     *
     * @param array{
     *     id: int|string, site_id: int|string, draft_id: int|string|null,
     *     filename: string, mime: string, width: int|string|null,
     *     height: int|string|null, size: int|string|null,
     *     remote_path: string|null, remote_url: string|null,
     *     created_at: string, updated_at: string|null
     * } $row
     *
     * @return array{
     *     id: int, site_id: int, draft_id: ?int, filename: string, mime: string,
     *     width: ?int, height: ?int, size: ?int, remote_path: ?string,
     *     remote_url: ?string, created_at: string, updated_at: ?string
     * }
     */
    private function metaFromRow(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'site_id'     => (int) $row['site_id'],
            'draft_id'    => $row['draft_id'] !== null ? (int) $row['draft_id'] : null,
            'filename'    => $row['filename'],
            'mime'        => $row['mime'],
            'width'       => $row['width'] !== null ? (int) $row['width'] : null,
            'height'      => $row['height'] !== null ? (int) $row['height'] : null,
            'size'        => $row['size'] !== null ? (int) $row['size'] : null,
            'remote_path' => $row['remote_path'],
            'remote_url'  => $row['remote_url'],
            'created_at'  => $row['created_at'],
            'updated_at'  => $row['updated_at'],
        ];
    }
}
