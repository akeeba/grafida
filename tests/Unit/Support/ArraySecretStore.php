<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Support;

use Grafida\Secret\SecretStore;

/** In-memory secret store for tests. */
final class ArraySecretStore implements SecretStore
{
    /** @var array<string, string> */
    public array $secrets = [];

    public function __construct(private readonly bool $available = true) {}

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function set(string $reference, string $secret): void
    {
        $this->secrets[$reference] = $secret;
    }

    public function get(string $reference): ?string
    {
        return $this->secrets[$reference] ?? null;
    }

    public function delete(string $reference): void
    {
        unset($this->secrets[$reference]);
    }

    public function isSecure(): bool
    {
        return true;
    }
}
