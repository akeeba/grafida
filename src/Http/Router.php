<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http;

use Boson\Contracts\Http\RequestInterface;
use Boson\Contracts\Http\ResponseInterface;

/**
 * A minimal route table for the internal `/api/...` API.
 *
 * Routes are registered as `(method, pattern, handler)`; `{name}` placeholders
 * in the pattern are compiled to a named capture group. A placeholder named
 * `key` (the AI tool key, a documentation page slug) matches `[A-Za-z0-9_\-]+`
 * and one named `file` (a documentation image) matches that plus a short
 * extension; every other placeholder is treated as a numeric id and matches
 * only digits, mirroring the `(\d+)` groups the hand-rolled `preg_match` chain
 * used before this class existed.
 *
 * None of the three character classes admits a `/` or a `.` on its own, so a
 * path segment can never carry a `..` traversal into a handler — the file-name
 * validation a handler does on top of that is defence in depth, not the only
 * guard.
 *
 * Every pattern is compiled fully-anchored (`^...$`), so distinct routes
 * cannot collide regardless of registration order — a request path either
 * matches exactly one pattern's shape or none at all.
 *
 * Dispatch behaviour:
 * - path + method both match a registered route → that route's handler runs.
 * - the path matches a registered pattern but no route on it accepts this
 *   method → 405 `Json::error('Method not allowed', 405)`.
 * - no registered pattern matches the path at all → 404
 *   `Json::error('Not found: ' . $path, 404)`.
 */
final class Router
{
    /**
     * @var list<array{method: string, regex: string, handler: callable(RouteContext): ResponseInterface}>
     */
    private array $routes = [];

    /**
     * @param callable(RouteContext): ResponseInterface $handler
     */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => self::compile($pattern),
            'handler' => $handler,
        ];
    }

    /**
     * @param array<string, mixed> $body the already-parsed JSON request body
     */
    public function dispatch(string $method, string $path, array $body, RequestInterface $request): ResponseInterface
    {
        $method      = strtoupper($method);
        $pathMatched = false;

        foreach ($this->routes as $route) {
            $matches = [];

            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $method) {
                $pathMatched = true;

                continue;
            }

            $context = new RouteContext(self::namedParams($matches), $body, $request);

            return ($route['handler'])($context);
        }

        if ($pathMatched) {
            return Json::error('Method not allowed', 405);
        }

        return Json::error('Not found: ' . $path, 404);
    }

    /**
     * Compiles a `{name}` path pattern into a fully-anchored regex with named
     * capture groups.
     */
    private static function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '/\{([A-Za-z0-9_]+)\}/',
            static function (array $m): string {
                $charClass = match ($m[1]) {
                    'key'   => '[A-Za-z0-9_\-]+',
                    'file'  => '[A-Za-z0-9_\-]+\.[A-Za-z0-9]{1,5}',
                    default => '\d+',
                };

                return '(?P<' . $m[1] . '>' . $charClass . ')';
            },
            $pattern,
        );

        \assert(is_string($regex));

        return '#^' . $regex . '$#';
    }

    /**
     * Extracts the named capture groups from a `preg_match` result, discarding
     * the numeric-indexed (whole-match / unnamed) entries.
     *
     * @param array<int|string, string> $matches
     *
     * @return array<string, string>
     */
    private static function namedParams(array $matches): array
    {
        $params = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
