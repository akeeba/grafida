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
 * {@see DnsResolver} backed by the platform resolver.
 *
 * Answers are memoised for the lifetime of the instance. That is a small saving
 * on a redirect check that asks about the same host twice, but the real reason is
 * consistency: two lookups of the same name a moment apart can legitimately
 * disagree (round-robin records, a short TTL), and a security comparison that
 * changes its mind between the two halves of one decision is worse than a stale
 * one.
 *
 * ⚠️ **This still cannot close the gap between "the name we checked" and "the
 * address cURL connects to".** Only pinning the connection to the address we
 * verified would do that, and libcurl offers no portable way to do it per
 * request here. What the check *does* buy is that a redirect cannot silently
 * move the conversation to an unrelated host, which is the realistic failure —
 * a misconfigured or hijacked redirect, not a racing DNS attacker.
 */
final class SystemDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    private array $cache = [];

    /** @inheritDoc */
    public function resolve(string $host): array
    {
        $host = strtolower(trim(IpRanges::unbracket($host)));

        if ($host === '') {
            return [];
        }

        if (isset($this->cache[$host])) {
            return $this->cache[$host];
        }

        if (IpRanges::isIpAddress($host)) {
            return $this->cache[$host] = [$host];
        }

        $addresses = [];

        $v4 = @gethostbynamel($host);

        if (\is_array($v4)) {
            foreach ($v4 as $address) {
                $addresses[] = $address;
            }
        }

        // dns_get_record() is the only way to see AAAA records from PHP, and it
        // talks to the DNS servers directly rather than through the platform
        // resolver — so it can fail (or be blocked) where gethostbynamel()
        // succeeds. It is additive here: a host with no visible AAAA is simply
        // judged on its A records.
        $v6 = @dns_get_record($host, \DNS_AAAA);

        if (\is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6']) && \is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return $this->cache[$host] = array_values(array_unique($addresses));
    }
}
