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
 * Stores secrets in the macOS login Keychain via the `security` CLI.
 */
final class MacosSecretStore implements SecretStore
{
    private const SERVICE = 'Grafida';
    private const SECURITY = '/usr/bin/security';

    public function __construct(
        private readonly ProcessRunner $runner = new ProcessRunner(),
    ) {}

    public function isAvailable(): bool
    {
        return \PHP_OS_FAMILY === 'Darwin' && is_file(self::SECURITY);
    }

    public function set(string $reference, string $secret): void
    {
        // -U updates the item if it already exists instead of failing.
        [$code, , $stderr] = $this->runner->run([
            self::SECURITY, 'add-generic-password',
            '-U',
            '-s', self::SERVICE,
            '-a', $reference,
            '-w', $secret,
        ]);

        if ($code !== 0) {
            throw new SecretStoreException('Keychain write failed: ' . trim($stderr));
        }
    }

    public function get(string $reference): ?string
    {
        [$code, $stdout] = $this->runner->run([
            self::SECURITY, 'find-generic-password',
            '-w',
            '-s', self::SERVICE,
            '-a', $reference,
        ]);

        if ($code !== 0) {
            return null;
        }

        return rtrim($stdout, "\r\n");
    }

    public function delete(string $reference): void
    {
        $this->runner->run([
            self::SECURITY, 'delete-generic-password',
            '-s', self::SERVICE,
            '-a', $reference,
        ]);
    }

    public function isSecure(): bool
    {
        return true;
    }
}
