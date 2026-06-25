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
 * Stores secrets via libsecret's `secret-tool` (GNOME Keyring / KWallet bridge).
 */
final class LinuxSecretStore implements SecretStore
{
    private const APP = 'Grafida';

    public function __construct(
        private readonly ProcessRunner $runner = new ProcessRunner(),
    ) {}

    public function isAvailable(): bool
    {
        return \PHP_OS_FAMILY === 'Linux' && $this->runner->exists('secret-tool');
    }

    public function set(string $reference, string $secret): void
    {
        // secret-tool reads the secret value from stdin (no trailing newline kept).
        [$code, , $stderr] = $this->runner->run([
            'secret-tool', 'store',
            '--label=' . self::APP,
            'application', self::APP,
            'account', $reference,
        ], $secret);

        if ($code !== 0) {
            throw new SecretStoreException('secret-tool store failed: ' . trim($stderr));
        }
    }

    public function get(string $reference): ?string
    {
        [$code, $stdout] = $this->runner->run([
            'secret-tool', 'lookup',
            'application', self::APP,
            'account', $reference,
        ]);

        if ($code !== 0) {
            return null;
        }

        return rtrim($stdout, "\r\n");
    }

    public function delete(string $reference): void
    {
        $this->runner->run([
            'secret-tool', 'clear',
            'application', self::APP,
            'account', $reference,
        ]);
    }

    public function isSecure(): bool
    {
        return true;
    }
}
