<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Media;

use PDO;

/**
 * Data-access for offline media blobs (images inserted while editing offline).
 */
final class MediaRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    /**
     * Stores a raw image and returns its new id.
     */
    public function store(int $siteId, ?int $draftId, string $filename, string $mime, string $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO media_blobs (site_id, draft_id, filename, mime, data, created_at) '
            . 'VALUES (:site, :draft, :name, :mime, :data, :now)'
        );
        $stmt->bindValue(':site', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':draft', $draftId, $draftId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':name', $filename);
        $stmt->bindValue(':mime', $mime);
        $stmt->bindValue(':data', $data, PDO::PARAM_LOB);
        $stmt->bindValue(':now', gmdate('Y-m-d H:i:s'));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array{id: int, filename: string, mime: string, data: string, remote_url: ?string}|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, filename, mime, data, remote_url FROM media_blobs WHERE id = ?');
        $stmt->execute([$id]);

        /** @var array{id: int|string, filename: string, mime: string, data: string, remote_url: string|null}|false $row */
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id'         => (int) $row['id'],
            'filename'   => $row['filename'],
            'mime'       => $row['mime'],
            'data'       => $row['data'],
            'remote_url' => $row['remote_url'] !== null ? $row['remote_url'] : null,
        ];
    }

    /** Records the remote path/URL after a successful upload. */
    public function markUploaded(int $id, string $remotePath, string $remoteUrl): void
    {
        $stmt = $this->pdo->prepare('UPDATE media_blobs SET remote_path = ?, remote_url = ? WHERE id = ?');
        $stmt->execute([$remotePath, $remoteUrl, $id]);
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
}
