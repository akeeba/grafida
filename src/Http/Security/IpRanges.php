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
 * Classifies IP addresses as "loopback / private / link-local" or not.
 *
 * This is the test behind the one place Grafida tolerates cleartext HTTP: an AI
 * model served from the same machine or the same LAN (Ollama, LM Studio,
 * llama.cpp, a GPU box in the next room). Such a service is routinely reachable
 * only over `http://`, and there is no meaningful attacker between the app and
 * `127.0.0.1`; a hostname that resolves anywhere on the public Internet gets no
 * such tolerance.
 *
 * ⚠️ **The blocks are a deliberate, closed list, not "everything RFC 6890 calls
 * special".** Multicast, the documentation ranges, 100.64/10 (CGNAT — carrier
 * infrastructure, emphatically not "your own LAN"), 192.0.0.0/24 and the rest
 * are **not** here: each of them is either unroutable-and-useless as an endpoint
 * or a genuinely remote address, and admitting one would widen the cleartext
 * exemption without making a single real local model reachable. Add to this list
 * only for an address you would be willing to send an unencrypted API key to.
 *
 * All comparisons are made on the packed `inet_pton()` form, bit by bit, so no
 * part of this depends on how the address happened to be spelled.
 */
final class IpRanges
{
    /**
     * IPv4 blocks reachable without leaving the machine or the local network.
     *
     * @var list<array{0: string, 1: int}> CIDR base address and prefix length.
     */
    private const V4_BLOCKS = [
        ['0.0.0.0', 8],         // "this network" — 0.0.0.0 is also "the local host"
        ['10.0.0.0', 8],        // RFC 1918 private
        ['127.0.0.0', 8],       // loopback
        ['169.254.0.0', 16],    // RFC 3927 link-local (incl. APIPA)
        ['172.16.0.0', 12],     // RFC 1918 private
        ['192.168.0.0', 16],    // RFC 1918 private
    ];

    /**
     * IPv6 blocks, over and above the IPv4-mapped forms of the blocks above.
     *
     * `fe80::/64` rather than the whole of `fe80::/10` is intentional and is what
     * was asked for: the link-local *unicast* range actually in use is the first
     * /64, and the remainder of the /10 is not assigned to it.
     *
     * @var list<array{0: string, 1: int}>
     */
    private const V6_BLOCKS = [
        ['::1', 128],           // loopback
        ['64:ff9b:1::', 48],    // RFC 8215 local-use IPv4/IPv6 translation
        ['fe80::', 64],         // link-local unicast
    ];

    /**
     * Is this a loopback, private or link-local address?
     *
     * An IPv4-mapped IPv6 address (`::ffff:10.0.0.1`) is unwrapped first and
     * judged as the IPv4 address it carries — otherwise the exemption could be
     * evaded, or an honest local endpoint spelled that way wrongly refused.
     *
     * @param string $ip A numeric address. Anything that is not one is `false`:
     *                   a caller must resolve a host name before asking.
     */
    public static function isPrivateOrLocal(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (\strlen($packed) === 16) {
            $unwrapped = self::unwrapV4Mapped($packed);

            if ($unwrapped !== null) {
                $packed = $unwrapped;
            }
        }

        $blocks = \strlen($packed) === 4 ? self::V4_BLOCKS : self::V6_BLOCKS;

        foreach ($blocks as [$base, $prefix]) {
            $packedBase = @inet_pton($base);

            if ($packedBase !== false && self::inBlock($packed, $packedBase, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Is this string a numeric IPv4 or IPv6 address rather than a host name? */
    public static function isIpAddress(string $host): bool
    {
        return filter_var(self::unbracket($host), \FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Strips the square brackets an IPv6 literal wears inside a URL.
     *
     * `parse_url()` hands back `[::1]` for `http://[::1]:8080/`, which no IP
     * function will accept.
     */
    public static function unbracket(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    /**
     * Returns the packed IPv4 address carried by an IPv4-mapped or
     * IPv4-compatible IPv6 address, or null if this is neither.
     */
    private static function unwrapV4Mapped(string $packed16): ?string
    {
        // ::ffff:a.b.c.d — 80 zero bits, 16 one bits, then the address.
        if (str_starts_with($packed16, str_repeat("\x00", 10) . "\xff\xff")) {
            return substr($packed16, 12, 4);
        }

        // ::a.b.c.d (deprecated IPv4-compatible form). Excluding :: and ::1,
        // which are IPv6 addresses in their own right and matched as such.
        if (str_starts_with($packed16, str_repeat("\x00", 12))) {
            $tail = substr($packed16, 12, 4);

            if ($tail !== "\x00\x00\x00\x00" && $tail !== "\x00\x00\x00\x01") {
                return $tail;
            }
        }

        return null;
    }

    /** Bitwise prefix comparison of two same-length packed addresses. */
    private static function inBlock(string $packed, string $packedBase, int $prefix): bool
    {
        if (\strlen($packed) !== \strlen($packedBase)) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        $oddBits    = $prefix % 8;

        if ($wholeBytes > 0 && strncmp($packed, $packedBase, $wholeBytes) !== 0) {
            return false;
        }

        if ($oddBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $oddBits) & 0xFF;

        return (\ord($packed[$wholeBytes]) & $mask) === (\ord($packedBase[$wholeBytes]) & $mask);
    }
}
