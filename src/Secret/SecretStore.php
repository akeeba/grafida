<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Secret;

/**
 * Abstraction over an OS secret store. Implementations persist a secret string
 * (a Joomla API token) under an opaque reference and retrieve or delete it.
 */
interface SecretStore
{
    /** Whether this store is usable on the current machine right now. */
    public function isAvailable(): bool;

    /** Stores (or replaces) the secret for the given reference. */
    public function set(string $reference, string $secret): void;

    /** Returns the stored secret, or null if there is none. */
    public function get(string $reference): ?string;

    /** Deletes the secret for the given reference (no-op if absent). */
    public function delete(string $reference): void;

    /** Whether secrets handled by this store are encrypted/OS-protected (true) or plaintext (false). */
    public function isSecure(): bool;
}
