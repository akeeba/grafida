<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Http;

use Grafida\Http\InsecureUrlException;
use Grafida\Http\Security\BlockedRedirectException;
use Grafida\Http\Security\DnsResolver;
use Grafida\Http\Security\UrlGuard;
use Grafida\Http\Security\UrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules deciding what Grafida is allowed to connect to, and where it will follow a redirect.
 *
 * DNS is stubbed throughout: these assertions are about the policy, and a test whose result depends
 * on what a real resolver answers today is a test that fails on an aeroplane.
 */
final class UrlGuardTest extends TestCase
{
    // ---------------------------------------------------------------------
    //  Site policy — HTTPS, with no exceptions at all
    // ---------------------------------------------------------------------

    public function testSiteHttpsIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard()->assertAllowed('https://example.com/index.php/api', UrlPolicy::Site);
    }

    public function testSiteHttpIsRefused(): void
    {
        $this->expectException(InsecureUrlException::class);

        $this->guard()->assertAllowed('http://example.com/index.php/api', UrlPolicy::Site);
    }

    public function testSiteHttpToLocalhostIsStillRefused(): void
    {
        // The AI carve-out is for AI services only. A local Joomla install still
        // gets an API token sent to it, and localhost is not a reason to stop
        // encrypting — it is only a reason a local *model* has no certificate.
        $this->expectException(InsecureUrlException::class);

        $this->guard(['localhost' => ['127.0.0.1']])
            ->assertAllowed('http://localhost/index.php/api', UrlPolicy::Site);
    }

    /** @return iterable<string, array{0: string}> */
    public static function unusableUrls(): iterable
    {
        yield 'a local file' => ['file:///etc/passwd'];
        yield 'another protocol entirely' => ['ftp://example.com/x'];
        yield 'a bare path with no scheme or host' => ['/index.php/api'];
        yield 'empty' => [''];
    }

    #[DataProvider('unusableUrls')]
    public function testNonHttpSchemesAreRefusedUnderEitherPolicy(string $url): void
    {
        $this->expectException(InsecureUrlException::class);

        $this->guard()->assertAllowed($url, UrlPolicy::AiService);
    }

    // ---------------------------------------------------------------------
    //  AI policy — HTTPS, or cleartext to something on this machine / LAN
    // ---------------------------------------------------------------------

    public function testAiHttpsIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard()->assertAllowed('https://api.openai.com/v1/responses', UrlPolicy::AiService);
    }

    public function testAiHttpToLoopbackLiteralIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard()->assertAllowed('http://127.0.0.1:11434/v1/chat/completions', UrlPolicy::AiService);
    }

    public function testAiHttpToBracketedIpv6LoopbackIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard()->assertAllowed('http://[::1]:1234/v1/chat/completions', UrlPolicy::AiService);
    }

    public function testAiHttpToLanAddressIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard()->assertAllowed('http://192.168.1.40:1234/v1/chat/completions', UrlPolicy::AiService);
    }

    public function testAiHttpToNameResolvingLocallyIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard(['localhost' => ['127.0.0.1', '::1']])
            ->assertAllowed('http://localhost:11434/api/chat', UrlPolicy::AiService);
    }

    public function testAiHttpToPublicHostIsRefused(): void
    {
        $this->expectException(InsecureUrlException::class);

        $this->guard(['api.example.com' => ['93.184.216.34']])
            ->assertAllowed('http://api.example.com/v1/chat/completions', UrlPolicy::AiService);
    }

    public function testAiHttpToUnresolvableHostIsRefused(): void
    {
        // "We could not look it up" is not "it is safe". A name we cannot resolve
        // must not inherit the local-network exemption.
        $this->expectException(InsecureUrlException::class);

        $this->guard()->assertAllowed('http://nowhere.invalid/v1/chat', UrlPolicy::AiService);
    }

    public function testAiHttpToHostMixingLocalAndPublicAddressesIsRefused(): void
    {
        // The DNS-rebinding shape: one answer inside the exemption, one outside.
        // Every address has to qualify, or the name does not.
        $this->expectException(InsecureUrlException::class);

        $this->guard(['sneaky.example.com' => ['127.0.0.1', '93.184.216.34']])
            ->assertAllowed('http://sneaky.example.com/v1/chat', UrlPolicy::AiService);
    }

    // ---------------------------------------------------------------------
    //  Redirects
    // ---------------------------------------------------------------------

    public function testRedirectWithinTheSameBaseDomainIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        $guard = $this->guard();

        $guard->assertRedirectAllowed('https://www.example.com/a', 'https://example.com/a', UrlPolicy::Site);
        $guard->assertRedirectAllowed('https://example.com/a', 'https://foobar.example.com/a', UrlPolicy::Site);
        $guard->assertRedirectAllowed('https://www.example.com/a', 'https://www.example.com/b', UrlPolicy::Site);
    }

    public function testRedirectAcrossDomainsIsRefused(): void
    {
        $this->expectException(BlockedRedirectException::class);

        $this->guard()->assertRedirectAllowed(
            'https://www.example.com/a',
            'https://example.org/a',
            UrlPolicy::Site
        );
    }

    public function testRedirectToALookalikeDomainIsRefused(): void
    {
        $this->expectException(BlockedRedirectException::class);

        $this->guard()->assertRedirectAllowed(
            'https://example.com/a',
            'https://example.com.attacker.net/a',
            UrlPolicy::Site
        );
    }

    public function testRedirectAcrossTwoDomainsUnderTheSameCcTldSuffixIsRefused(): void
    {
        // The base domain is example.co.uk, not co.uk — otherwise every .co.uk
        // site would be a permitted redirect target for every other one.
        $this->expectException(BlockedRedirectException::class);

        $this->guard()->assertRedirectAllowed(
            'https://www.example.co.uk/a',
            'https://attacker.co.uk/a',
            UrlPolicy::Site
        );
    }

    public function testRedirectWithinACcTldBaseDomainIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard()->assertRedirectAllowed(
            'https://www.example.co.uk/a',
            'https://example.co.uk/a',
            UrlPolicy::Site
        );
    }

    public function testRedirectDowngradingToHttpIsRefused(): void
    {
        // Same domain, so the domain rule passes — the scheme policy is what has
        // to catch this, which is why the destination is re-judged in full.
        $this->expectException(InsecureUrlException::class);

        $this->guard()->assertRedirectAllowed(
            'https://www.example.com/a',
            'http://www.example.com/a',
            UrlPolicy::Site
        );
    }

    public function testRedirectIsNotRefusedForDifferingIpAddresses(): void
    {
        // Anycast CDNs, round-robin records and separate www/apex CNAME targets
        // all make "same name family, different addresses" the normal case. A
        // same-IP rule would break apex-to-www on most hosting in existence.
        $this->expectNotToPerformAssertions();

        $this->guard([
            'www.example.com' => ['203.0.113.10'],
            'example.com'     => ['198.51.100.20'],
        ])->assertRedirectAllowed('https://www.example.com/a', 'https://example.com/a', UrlPolicy::Site);
    }

    /** @param array<string, list<string>> $map */
    private function guard(array $map = []): UrlGuard
    {
        return new UrlGuard(new StubDnsResolver($map));
    }
}

/** A resolver that knows exactly what the test told it, and nothing else. */
final class StubDnsResolver implements DnsResolver
{
    /** @param array<string, list<string>> $map */
    public function __construct(private readonly array $map = []) {}

    /** @return list<string> */
    public function resolve(string $host): array
    {
        $host = strtolower(trim($host, '[]'));

        if (filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->map[$host] ?? [];
    }
}
