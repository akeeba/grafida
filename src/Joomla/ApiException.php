<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Joomla;

/**
 * Thrown when the Joomla API returns an error response (non-2xx) or a payload
 * that cannot be understood.
 */
final class ApiException extends \RuntimeException
{
    /** @param list<string> $apiErrors Human-readable error details from the JSON:API `errors` array. */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $apiErrors = [],
    ) {
        parent::__construct($message);
    }

    public function isAuthError(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }
}
