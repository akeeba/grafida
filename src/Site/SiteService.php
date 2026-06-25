<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Site;

use Grafida\Joomla\ApiClient;
use Grafida\Secret\SecretStore;

/**
 * Application service that orchestrates site persistence, API-token storage and
 * connection testing.
 */
final class SiteService
{
    public function __construct(
        private readonly SiteRepository $repository,
        private readonly ApiClient $api,
        private readonly ?SecretStore $secureStore,
    ) {}

    /** @return list<Site> */
    public function list(): array
    {
        return $this->repository->all();
    }

    public function find(int $id): ?Site
    {
        return $this->repository->find($id);
    }

    public function hasSecureStore(): bool
    {
        return $this->secureStore !== null;
    }

    /**
     * Resolves the API token for a site from wherever it is stored.
     */
    public function tokenFor(Site $site): ?string
    {
        if ($site->secretRef !== null && $this->secureStore !== null) {
            return $this->secureStore->get($site->secretRef);
        }

        if ($site->id !== null) {
            return $this->repository->insecureToken($site->id);
        }

        return null;
    }

    /**
     * Verifies a URL + token against a live Joomla site and returns the working
     * API base URL.
     *
     * @throws \Grafida\Joomla\ApiException
     */
    public function testConnection(string $url, string $token): string
    {
        return $this->api->probeApiBase($url, $token);
    }

    /**
     * Creates a new site, testing the connection first and storing the token in
     * the most secure place available.
     *
     * @throws SecureStoreUnavailableException When no OS store is available and
     *                                         $allowInsecure is false.
     * @throws \Grafida\Joomla\ApiException    When the connection test fails.
     */
    public function create(string $title, string $url, string $token, bool $allowInsecure = false): Site
    {
        $root    = ApiClient::normaliseRoot($url);
        $apiBase = $this->testConnection($root, $token);

        [$secretRef, $insecureToken] = $this->persistToken($token, $allowInsecure);

        $id = $this->repository->insert(
            title: $title !== '' ? $title : $root,
            baseUrl: $root,
            apiBase: $apiBase,
            secretRef: $secretRef,
            insecureToken: $insecureToken,
        );

        $site = $this->repository->find($id);
        \assert($site !== null);

        return $site;
    }

    /**
     * Updates an existing site. A non-null $token replaces the stored token and
     * re-tests the connection.
     *
     * @throws SecureStoreUnavailableException
     * @throws \Grafida\Joomla\ApiException
     */
    public function update(int $id, string $title, string $url, ?string $token, bool $allowInsecure = false): Site
    {
        $existing = $this->repository->find($id);

        if ($existing === null) {
            throw new \InvalidArgumentException('Unknown site #' . $id);
        }

        $root          = ApiClient::normaliseRoot($url);
        $effectiveToken = $token ?? $this->tokenFor($existing);

        if ($effectiveToken === null) {
            throw new \RuntimeException('No API token available for site #' . $id);
        }

        $apiBase = $this->testConnection($root, $effectiveToken);

        $secretRef     = $existing->secretRef;
        $insecureToken = $existing->isInsecure() ? $this->repository->insecureToken($id) : null;

        if ($token !== null) {
            // Re-store the (possibly new) token; clean up any previous secret.
            if ($existing->secretRef !== null && $this->secureStore !== null) {
                $this->secureStore->delete($existing->secretRef);
            }

            [$secretRef, $insecureToken] = $this->persistToken($token, $allowInsecure);
        }

        $this->repository->update($id, $title !== '' ? $title : $root, $root, $apiBase, $secretRef, $insecureToken);

        $site = $this->repository->find($id);
        \assert($site !== null);

        return $site;
    }

    public function delete(int $id): void
    {
        $site = $this->repository->find($id);

        if ($site?->secretRef !== null && $this->secureStore !== null) {
            $this->secureStore->delete($site->secretRef);
        }

        $this->repository->delete($id);
    }

    /**
     * Decides where to store the token and returns [secretRef, insecureToken].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function persistToken(string $token, bool $allowInsecure): array
    {
        if ($this->secureStore !== null) {
            $ref = 'grafida-site-' . bin2hex(random_bytes(8));
            $this->secureStore->set($ref, $token);

            return [$ref, null];
        }

        if (!$allowInsecure) {
            throw new SecureStoreUnavailableException(
                'No OS secret store is available on this platform.'
            );
        }

        return [null, $token];
    }
}
