<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http\Security;

use Grafida\Http\InsecureUrlException;

/**
 * Decides whether an outbound request may be made at all, and whether a redirect
 * may be followed.
 *
 * This is the **single** place both questions are answered, and it sits inside
 * {@see \Grafida\Http\HttpClient} rather than at the call sites. That is
 * deliberate: Grafida reaches the network from a dozen services (the API client,
 * the favicon fetcher, template discovery, the editor-CSS walk, the site image
 * proxy, the update check, the AI proxy), several of them built from a URL that
 * came in over the internal API, and a policy that has to be *remembered* at each
 * of those is a policy that will eventually be forgotten at one of them. Enforced
 * in the transport, a new caller is covered the day it is written.
 *
 * ⚠️ **`ApiClient::normaliseRoot()` still rejects a non-HTTPS site URL at the
 * input boundary and must keep doing so.** The two are not redundant. The guard
 * is the backstop that no request escapes; the boundary check is what lets the
 * Sites form say "this must be an HTTPS URL" while the user is typing, instead of
 * accepting the URL, storing it, and failing on every subsequent operation.
 */
final class UrlGuard
{
    /**
     * Second-level labels short enough to be a public-suffix component rather
     * than a name somebody registered — `co.uk`, `com.au`, `ac.jp`, `org.nz`.
     *
     * See {@see self::baseDomain()} for why this heuristic is acceptable here
     * and would not be elsewhere.
     */
    private const MAX_SUFFIX_LABEL_LENGTH = 3;

    public function __construct(
        private readonly DnsResolver $dns = new SystemDnsResolver(),
    ) {}

    /**
     * Throws unless this URL may be requested under the given policy.
     *
     * @throws InsecureUrlException When the scheme is not permitted, or the URL
     *                              is too malformed to judge. A URL we cannot
     *                              parse is refused rather than passed to cURL to
     *                              interpret — the two do not always agree about
     *                              where the host ends, and that disagreement is
     *                              exactly what a crafted URL exploits.
     */
    public function assertAllowed(string $url, UrlPolicy $policy): void
    {
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        $host   = parse_url($url, \PHP_URL_HOST);

        if (!\is_string($host) || $host === '') {
            throw new InsecureUrlException(
                sprintf('The URL "%s" has no host name and cannot be requested.', $url)
            );
        }

        if ($scheme === 'https') {
            return;
        }

        if ($scheme !== 'http') {
            throw new InsecureUrlException(
                sprintf(
                    'Only HTTPS URLs may be requested (got "%s").',
                    $scheme !== '' ? $scheme . ':' : 'a URL with no scheme'
                )
            );
        }

        if ($policy === UrlPolicy::Site) {
            throw new InsecureUrlException(
                'Only HTTPS URLs are allowed for sites. A plain HTTP URL would put the site\'s '
                . 'API token on the wire in the clear, so no request is attempted.'
            );
        }

        if (!$this->resolvesOnlyToPrivateAddresses($host)) {
            throw new InsecureUrlException(
                sprintf(
                    'Plain HTTP is only allowed for an AI service on this machine or the local '
                    . 'network; "%s" is not one, so no request is attempted. Use an HTTPS endpoint.',
                    IpRanges::unbracket($host)
                )
            );
        }
    }

    /**
     * Throws unless a redirect from `$originalUrl` to `$location` may be followed.
     *
     * Two things have to hold, and the first is the one people forget: the
     * destination must satisfy the policy in its own right, so an HTTPS request
     * cannot be talked down to cleartext by a `301`. The second is that it stays
     * on the same base domain — `www.example.com` may redirect to `example.com`
     * or to `foobar.example.com`, and to nowhere else.
     *
     * ⚠️ **The destination is deliberately *not* required to resolve to the same
     * IP address as the original host**, and adding that check would break a
     * large share of real sites rather than protect them. Behind an anycast CDN
     * (Cloudflare and every one of its competitors) the address returned for a
     * name varies by resolver, by PoP and by lookup; round-robin records rotate;
     * and `www` and the apex routinely sit on entirely disjoint address sets
     * because they are different CNAME targets. A same-IP rule would therefore
     * reject the single most common redirect on the Internet — apex to `www` —
     * on the majority of hosting in use. The scheme policy plus certificate
     * validation is what actually protects the hop: reaching a different address
     * is normal, but presenting a valid certificate for a name inside the same
     * registrable domain is not something an off-path attacker can arrange.
     *
     * @param string $originalUrl The URL the caller asked for — **not** the
     *                            previous hop. A chain of individually-plausible
     *                            hops must not be able to walk away from the
     *                            host the caller actually chose to trust.
     *
     * @throws BlockedRedirectException When the destination is a different site.
     * @throws InsecureUrlException     When the destination fails the policy.
     */
    public function assertRedirectAllowed(string $originalUrl, string $location, UrlPolicy $policy): void
    {
        $this->assertAllowed($location, $policy);

        $fromHost = strtolower(IpRanges::unbracket((string) parse_url($originalUrl, \PHP_URL_HOST)));
        $toHost   = strtolower(IpRanges::unbracket((string) parse_url($location, \PHP_URL_HOST)));

        if ($fromHost === $toHost) {
            return;
        }

        if ($this->baseDomain($fromHost) !== $this->baseDomain($toHost)) {
            throw new BlockedRedirectException(sprintf(
                'Refusing to follow a redirect from "%s" to "%s": it leaves the original domain.',
                $fromHost,
                $toHost
            ));
        }
    }

    /** Do every one of this host's addresses sit in a loopback/private/link-local block? */
    private function resolvesOnlyToPrivateAddresses(string $host): bool
    {
        $host = IpRanges::unbracket($host);

        if (IpRanges::isIpAddress($host)) {
            return IpRanges::isPrivateOrLocal($host);
        }

        $addresses = $this->dns->resolve($host);

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            // *Every* address must qualify, not merely one. A name answering with
            // both 127.0.0.1 and a public address is the classic rebinding shape,
            // and there is no reason a genuine local model would ever do it.
            if (!IpRanges::isPrivateOrLocal($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The registrable part of a host name — `www.example.com` and
     * `foobar.example.com` both reduce to `example.com`.
     *
     * ⚠️ **This is a heuristic, not the Public Suffix List**, and it is used only
     * here, only to decide whether two hosts are related enough for a redirect
     * between them to be plausible. Its one failure mode is taking *more* labels
     * than a true PSL lookup would — treating `example.co.uk` as its own base
     * domain is right, and treating `foo.bar.it` as one is merely stricter than
     * necessary. It never takes *fewer*, which is the direction that would
     * wrongly admit a redirect, so the error is always on the safe side. Do not
     * reuse it for anything where over-strictness is not the safe direction, such
     * as cookie scoping.
     */
    private function baseDomain(string $host): string
    {
        $host   = rtrim(strtolower($host), '.');
        $labels = explode('.', $host);
        $count  = \count($labels);

        if ($count <= 2) {
            return $host;
        }

        $take = 2;

        // A two-letter TLD is a country code, and a short label in front of it is
        // almost always part of the public suffix rather than a registered name.
        if (
            \strlen($labels[$count - 1]) === 2
            && \strlen($labels[$count - 2]) <= self::MAX_SUFFIX_LABEL_LENGTH
        ) {
            $take = 3;
        }

        return implode('.', \array_slice($labels, -min($take, $count)));
    }
}
