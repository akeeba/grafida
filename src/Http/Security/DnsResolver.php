<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http\Security;

/**
 * Resolves a host name to its numeric addresses.
 *
 * An interface rather than a bare `gethostbynamel()` call because {@see UrlGuard}
 * is decided by what DNS answers, and a security rule you cannot write a test for
 * is a security rule nobody can check. {@see SystemDnsResolver} is the real one;
 * the tests supply a fixed map.
 */
interface DnsResolver
{
    /**
     * Returns every address the host resolves to, IPv4 and IPv6 alike.
     *
     * @param string $host A host name, or a numeric address (returned as-is).
     *
     * @return list<string> Empty when the name does not resolve. ⚠️ An empty
     *                      answer is **not** permission: a caller deciding
     *                      whether an address is safe must treat "we do not know"
     *                      as "no".
     */
    public function resolve(string $host): array;
}
