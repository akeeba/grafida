<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Http;

use Grafida\Debug\RecordingTransport;
use Grafida\Http\HttpClient;
use Grafida\Tests\Support\TestContainer;
use PHPUnit\Framework\TestCase;

/**
 * AI traffic must never reach the Request Log.
 *
 * The log is a debugging aid the user can export to a file and attach to a bug report, and an AI
 * exchange carries a third party's API key, the whole article body and the entire conversation —
 * none of which anyone means to hand over when they send a connection problem to support. The
 * separation is structural: recording is a transport *decorator*, so a transport that is not
 * wrapped cannot be recorded, and `http.ai` is the one that is not wrapped.
 *
 * This is pinned as a test rather than left to the comment in `HttpProvider` because it is the kind
 * of property a well-meaning tidy-up ("why is this one built differently?") silently removes.
 */
final class RequestLogIsolationTest extends TestCase
{
    public function testAiTransportIsNotWiredIntoTheRequestLog(): void
    {
        $container = TestContainer::create();

        $this->assertInstanceOf(
            HttpClient::class,
            $container->get('http.ai'),
            'http.ai must be a bare HttpClient, never wrapped in a recorder'
        );

        $this->assertNotInstanceOf(RecordingTransport::class, $container->get('http.ai'));
    }

    public function testSiteTransportsAreWiredIntoTheRequestLog(): void
    {
        $container = TestContainer::create();

        foreach (['http.default', 'http.short', 'http.reference'] as $service) {
            $this->assertInstanceOf(
                RecordingTransport::class,
                $container->get($service),
                $service . ' is site-facing and must feed the Request Log'
            );
        }
    }

    public function testDiagnosticsTransportIsNotWiredIntoTheSharedLog(): void
    {
        // ConnectionDiagnostics records into a private sink of its own per run,
        // so wrapping this one too would double-record every probe.
        $this->assertNotInstanceOf(
            RecordingTransport::class,
            TestContainer::create()->get('http.diagnostics')
        );
    }
}
