<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http;

/**
 * Immutable HTTP response returned by {@see HttpClient}.
 */
final readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers Lower-cased header name => value.
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Decodes the body as JSON.
     *
     * @return array<mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
