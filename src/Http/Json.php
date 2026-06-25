<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http;

use Boson\Component\Http\Response;
use Boson\Contracts\Http\ResponseInterface;

/**
 * Builds JSON HTTP responses for the internal API.
 */
final class Json
{
    /**
     * @param mixed $data
     */
    public static function ok($data = null, int $status = 200): ResponseInterface
    {
        return self::response(['ok' => true, 'data' => $data], $status);
    }

    /**
     * @param array<string, mixed> $extra Extra fields merged into the payload (e.g. an error code).
     */
    public static function error(string $message, int $status = 400, array $extra = []): ResponseInterface
    {
        return self::response(['ok' => false, 'error' => $message] + $extra, $status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function response(array $payload, int $status): ResponseInterface
    {
        $body = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return new Response((string) $body, $status, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }
}
