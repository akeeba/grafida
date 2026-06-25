<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Secret;

use Grafida\Support\Paths;

/**
 * Stores secrets on Windows using DPAPI (Data Protection API) via PowerShell.
 *
 * The token is encrypted for the current user with
 * {@see https://learn.microsoft.com/dotnet/api/system.security.cryptography.protecteddata ProtectedData}
 * (CurrentUser scope) and the resulting ciphertext is written to a per-reference
 * file under the application data directory. Only the logged-in Windows user can
 * decrypt it, which satisfies the "protected storage" requirement.
 */
final class WindowsSecretStore implements SecretStore
{
    public function __construct(
        private readonly ProcessRunner $runner = new ProcessRunner(),
        private readonly ?string $secretsDir = null,
    ) {}

    public function isAvailable(): bool
    {
        return \PHP_OS_FAMILY === 'Windows' && $this->runner->exists('powershell');
    }

    public function set(string $reference, string $secret): void
    {
        $script = <<<'PS'
            $ErrorActionPreference = 'Stop'
            Add-Type -AssemblyName System.Security
            $plain = [Console]::In.ReadToEnd()
            $bytes = [System.Text.Encoding]::UTF8.GetBytes($plain)
            $enc = [System.Security.Cryptography.ProtectedData]::Protect($bytes, $null, 'CurrentUser')
            [Console]::Out.Write([Convert]::ToBase64String($enc))
            PS;

        [$code, $stdout, $stderr] = $this->runPowerShell($script, $secret);

        if ($code !== 0) {
            throw new SecretStoreException('DPAPI protect failed: ' . trim($stderr));
        }

        file_put_contents($this->file($reference), $stdout);
    }

    public function get(string $reference): ?string
    {
        $file = $this->file($reference);

        if (!is_file($file)) {
            return null;
        }

        $cipher = (string) file_get_contents($file);

        $script = <<<'PS'
            $ErrorActionPreference = 'Stop'
            Add-Type -AssemblyName System.Security
            $b64 = [Console]::In.ReadToEnd()
            $enc = [Convert]::FromBase64String($b64)
            $bytes = [System.Security.Cryptography.ProtectedData]::Unprotect($enc, $null, 'CurrentUser')
            [Console]::Out.Write([System.Text.Encoding]::UTF8.GetString($bytes))
            PS;

        [$code, $stdout] = $this->runPowerShell($script, $cipher);

        return $code === 0 ? $stdout : null;
    }

    public function delete(string $reference): void
    {
        $file = $this->file($reference);

        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function isSecure(): bool
    {
        return true;
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function runPowerShell(string $script, string $stdin): array
    {
        $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE'));

        return $this->runner->run([
            'powershell', '-NoProfile', '-NonInteractive', '-EncodedCommand', $encoded,
        ], $stdin);
    }

    private function file(string $reference): string
    {
        $dir = $this->secretsDir ?? (Paths::dataDir() . \DIRECTORY_SEPARATOR . 'secrets');

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir . \DIRECTORY_SEPARATOR . sha1($reference) . '.bin';
    }
}
