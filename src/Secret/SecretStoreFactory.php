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
 * Selects the appropriate OS secret store for the current platform.
 */
final class SecretStoreFactory
{
    /**
     * Returns the platform's secure secret store if it is usable right now,
     * otherwise null (the caller must then offer the insecure fallback).
     */
    public static function secureStore(): ?SecretStore
    {
        $candidate = match (\PHP_OS_FAMILY) {
            'Darwin'  => new MacosSecretStore(),
            'Windows' => new WindowsSecretStore(),
            'Linux'   => new LinuxSecretStore(),
            default   => null,
        };

        if ($candidate !== null && $candidate->isAvailable()) {
            return $candidate;
        }

        return null;
    }
}
