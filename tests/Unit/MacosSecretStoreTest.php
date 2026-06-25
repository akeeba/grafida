<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Secret\MacosSecretStore;
use Grafida\Secret\ProcessRunner;

final class MacosSecretStoreTest extends TestCase
{
    public function testSetGetDeleteRoundTripThroughSecurityCli(): void
    {
        // Emulates the `security` CLI keyed by account argument.
        $runner = new class extends ProcessRunner {
            /** @var array<string, string> */
            public array $items = [];

            public function run(array $command, ?string $stdin = null): array
            {
                $sub = $command[1] ?? '';
                $account = $this->arg($command, '-a');

                return match ($sub) {
                    'add-generic-password' => $this->store($account, $this->arg($command, '-w')),
                    'find-generic-password' => isset($this->items[$account])
                        ? [0, $this->items[$account], '']
                        : [44, '', 'not found'],
                    'delete-generic-password' => $this->forget($account),
                    default => [1, '', 'unknown'],
                };
            }

            private function store(string $account, string $value): array
            {
                $this->items[$account] = $value;

                return [0, '', ''];
            }

            private function forget(string $account): array
            {
                unset($this->items[$account]);

                return [0, '', ''];
            }

            /** @param list<string> $command */
            private function arg(array $command, string $flag): string
            {
                $i = array_search($flag, $command, true);

                return $i !== false ? (string) ($command[$i + 1] ?? '') : '';
            }
        };

        $store = new MacosSecretStore($runner);

        $store->set('ref-1', 'my-token');
        self::assertSame('my-token', $store->get('ref-1'));

        $store->delete('ref-1');
        self::assertNull($store->get('ref-1'));
    }
}
