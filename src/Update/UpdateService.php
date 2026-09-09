<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Update;

use Grafida\Http\HttpException;
use Grafida\Http\Transport;

/**
 * Checks whether a newer version of Grafida is available.
 *
 * The update information is published to the CDN as a small JSON document (built
 * at release time by the {@see \tasks\UpdateJson} Phing task) describing the
 * latest stable release: its {@code version}, {@code infoURL} (the GitHub release
 * page) and {@code download} URL.
 *
 * To avoid hammering the CDN, the fetched document is cached in a per-user file
 * and only refreshed when that file is older than 12 hours (the "last fetched"
 * timestamp is simply the file's modification time). A failed fetch with no prior
 * cache still writes an empty {@code {}} document, so a broken network does not
 * trigger a fetch on every startup.
 */
final class UpdateService
{
    /** Canonical URL the release pipeline publishes the update information to. */
    public const UPDATE_URL = 'https://grafida.app/updates/grafida.json';

    /** Refresh the cached information at most once every 12 hours. */
    private const MAX_AGE = 12 * 60 * 60;

    public function __construct(
        private readonly Transport $http,
        private readonly string $currentVersion,
        private readonly string $cacheFile,
        private readonly string $updateUrl = self::UPDATE_URL,
    ) {}

    /**
     * Returns the update status, refreshing the cache from the CDN when stale.
     *
     * @return array{available: bool, version: string|null, infoURL: string|null, download: string|null}
     */
    public function status(): array
    {
        return $this->evaluate($this->cachedOrFresh());
    }

    /**
     * Reads the cached update information, fetching a fresh copy when the cache
     * is missing or older than {@see MAX_AGE}.
     *
     * @return array<array-key, mixed>
     */
    private function cachedOrFresh(): array
    {
        if (is_file($this->cacheFile)) {
            $age = time() - (int) @filemtime($this->cacheFile);

            if ($age >= 0 && $age < self::MAX_AGE) {
                return $this->readCache();
            }
        }

        return $this->fetch();
    }

    /**
     * Fetches the update information from the CDN (best-effort).
     *
     * On any failure it falls back to a previously-cached copy; if there is none,
     * it writes an empty {@code {}} document so the 12-hour back-off applies to
     * failed attempts too.
     *
     * @return array<array-key, mixed>
     */
    private function fetch(): array
    {
        try {
            $response = $this->http->request('GET', $this->updateUrl, [
                'Accept'     => 'application/json',
                'User-Agent' => 'Grafida/' . $this->currentVersion,
            ]);

            if ($response->isSuccess()) {
                $data = json_decode($response->body, true);

                if (is_array($data)) {
                    $this->writeCache($response->body);

                    return $data;
                }
            }
        } catch (HttpException) {
            // Network/transport failure — handled below.
        }

        if (is_file($this->cacheFile)) {
            return $this->readCache();
        }

        // No cache and the fetch failed: record the attempt with an empty document.
        $this->writeCache('{}');

        return [];
    }

    /** @return array<array-key, mixed> */
    private function readCache(): array
    {
        $raw = @file_get_contents($this->cacheFile);

        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    private function writeCache(string $json): void
    {
        $dir = \dirname($this->cacheFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        @file_put_contents($this->cacheFile, $json);
    }

    /**
     * Turns the cached update document into the status returned to the SPA.
     *
     * @param array<array-key, mixed> $info
     *
     * @return array{available: bool, version: string|null, infoURL: string|null, download: string|null}
     */
    private function evaluate(array $info): array
    {
        $version  = isset($info['version']) && is_string($info['version']) ? trim($info['version']) : '';
        $infoURL  = isset($info['infoURL']) && is_string($info['infoURL']) ? $info['infoURL'] : '';
        $download = isset($info['download']) && is_string($info['download']) ? $info['download'] : '';

        $available = $version !== '' && version_compare($version, $this->currentVersion, '>');

        return [
            'available' => $available,
            'version'   => $available ? $version : null,
            'infoURL'   => $available && $infoURL !== '' ? $infoURL : null,
            'download'  => $available && $download !== '' ? $download : null,
        ];
    }
}
