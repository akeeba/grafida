<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Http;

use Grafida\Http\Security\IpRanges;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The address classification behind the one place Grafida tolerates cleartext HTTP.
 *
 * Both directions matter and the false-positive direction matters more: an address wrongly called
 * private is an address we would send an unencrypted API key to. The boundary cases below are
 * therefore mostly "just outside the block", which is where an off-by-one in the prefix arithmetic
 * shows up.
 */
final class IpRangesTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function privateAddresses(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'loopback, far end of the /8' => ['127.255.255.254'];
        yield 'this network' => ['0.0.0.0'];
        yield 'RFC 1918 ten' => ['10.1.2.3'];
        yield 'RFC 1918 172.16, bottom' => ['172.16.0.1'];
        yield 'RFC 1918 172.16, top' => ['172.31.255.254'];
        yield 'RFC 1918 192.168' => ['192.168.1.50'];
        yield 'link-local v4' => ['169.254.10.10'];
        yield 'IPv6 loopback' => ['::1'];
        yield 'IPv6 link-local' => ['fe80::1'];
        yield 'IPv6 link-local, far end of the /64' => ['fe80::ffff:ffff:ffff:ffff'];
        yield 'RFC 8215 local-use translation' => ['64:ff9b:1::1'];
        yield 'v4-mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'v4-mapped RFC 1918' => ['::ffff:192.168.0.1'];
    }

    /** @return iterable<string, array{0: string}> */
    public static function publicAddresses(): iterable
    {
        yield 'a public v4' => ['93.184.216.34'];
        yield 'just below the 172.16/12 block' => ['172.15.255.255'];
        yield 'just above the 172.16/12 block' => ['172.32.0.1'];
        yield 'just below the link-local block' => ['169.253.255.255'];
        yield 'just above the link-local block' => ['169.255.0.1'];
        yield 'a public v6' => ['2606:2800:220:1:248:1893:25c8:1946'];
        yield 'outside the fe80::/64 we allow, inside fe80::/10' => ['fe80:0:0:1::1'];
        yield 'not the /48 we allow' => ['64:ff9b:2::1'];
        yield 'NAT64 well-known prefix is not local-use' => ['64:ff9b::1'];
        yield 'v4-mapped public address' => ['::ffff:93.184.216.34'];
        // Unspecified addresses are not somewhere to send a request, and are
        // certainly not "the local machine" for this purpose.
        yield 'the IPv6 unspecified address' => ['::'];
        yield 'not an address at all' => ['example.com'];
        yield 'empty' => [''];
    }

    #[DataProvider('privateAddresses')]
    public function testRecognisesPrivateAndLocalAddresses(string $ip): void
    {
        $this->assertTrue(IpRanges::isPrivateOrLocal($ip), $ip . ' should be treated as local/private');
    }

    #[DataProvider('publicAddresses')]
    public function testRejectsEverythingElse(string $ip): void
    {
        $this->assertFalse(IpRanges::isPrivateOrLocal($ip), $ip . ' must NOT be treated as local/private');
    }

    public function testUnbracketsIpv6Literals(): void
    {
        $this->assertSame('::1', IpRanges::unbracket('[::1]'));
        $this->assertSame('example.com', IpRanges::unbracket('example.com'));
    }

    public function testRecognisesLiteralAddresses(): void
    {
        $this->assertTrue(IpRanges::isIpAddress('10.0.0.1'));
        $this->assertTrue(IpRanges::isIpAddress('[fe80::1]'), 'A bracketed literal is still a literal');
        $this->assertFalse(IpRanges::isIpAddress('localhost'), 'A name must be resolved, not classified');
    }
}
