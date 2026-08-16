<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application\Provider;

use Grafida\Application\Container;
use Grafida\Debug\RecordingTransport;
use Grafida\Debug\RequestLog;
use Grafida\Debug\RequestLogService;
use Grafida\Http\HttpClient;
use Grafida\Http\Security\UrlGuard;
use Grafida\Http\Security\UrlPolicy;
use Grafida\Http\Transport;
use Grafida\Joomla\ApiClient;
use Grafida\Storage\SettingsRepository;
use Joomla\DI\ServiceProviderInterface;

/**
 * Registers the shared HTTP transports — one per distinct timeout the
 * application uses — and the `ApiClient` instances built on top of them.
 *
 * Today six `HttpClient`s get built per request cycle, several of them
 * invisibly through default constructor arguments in `FaviconService`,
 * `ReferenceService` and `EditorCssService`. This provider makes the five
 * distinct timeouts explicit, named, and shared.
 *
 * The three **site-facing** transports (`http.default`/`http.short`/
 * `http.reference`) are wrapped in {@see RecordingTransport}, feeding the
 * container-shared {@see RequestLog} — that ring buffer is what the Request
 * Log screen reads and what gh-37's Debug setting toggles. `http.ai` is
 * deliberately left unwrapped: AI traffic is not "requests to the site", may
 * be huge, and carries a different provider's key. `http.diagnostics` is also
 * unwrapped — `Site\ConnectionDiagnostics` records into its own private sink
 * per run, so wrapping this transport too would double-record every probe
 * into the shared log.
 *
 * ⚠️ **`http.ai` being unwrapped is now a security property, not just a
 * housekeeping one.** AI traffic must never reach the Request Log: the log is
 * exportable to a file the user may attach to a bug report, and an AI exchange
 * carries a different provider's API key together with the whole article body
 * and the entire conversation. `RequestLog` is reachable only through
 * {@see self::recorded()}, and `http.ai` is the one transport that does not go
 * through it — see `RequestLogIsolationTest`, which pins that.
 *
 * Each transport also carries the {@see UrlPolicy} it is allowed to speak. This
 * is the only place the AI exemption is granted, and granting it is one line
 * long and visible in isolation on purpose.
 */
final class HttpProvider implements ServiceProviderInterface
{
    public function register(\Joomla\DI\Container $container): void
    {
        $container->share(RequestLogService::class, static fn (Container $c): RequestLogService
            => new RequestLogService($c->get(SettingsRepository::class)));

        $container->share(RequestLog::class, static fn (Container $c): RequestLog
            => new RequestLog($c->get(RequestLogService::class)));

        // One guard instance, shared by every transport, so its DNS answers are
        // memoised across the whole request cycle rather than per client.
        $container->share(UrlGuard::class, static fn (): UrlGuard => new UrlGuard());

        $container->share('http.default', static fn (Container $c): RecordingTransport => self::recorded($c, self::site($c)));
        $container->share('http.short', static fn (Container $c): RecordingTransport => self::recorded($c, self::site($c, 5)));
        $container->share('http.reference', static fn (Container $c): RecordingTransport => self::recorded($c, self::site($c, 8)));

        // The one transport on `UrlPolicy::AiService`, i.e. the one allowed to
        // speak cleartext HTTP — and only to a loopback/private/link-local
        // address. Everything else in the app is on `UrlPolicy::Site`, which has
        // no such exemption.
        $container->share('http.ai', static fn (Container $c): HttpClient
            => new HttpClient(300, UrlPolicy::AiService, $c->get(UrlGuard::class)));

        // Deliberately *not* wrapped in a RecordingTransport: ConnectionDiagnostics
        // builds its own recorder over this transport for each diagnose run, so a
        // diagnose never fills the shared Request Log with duplicates of itself.
        $container->share('http.diagnostics', static fn (Container $c): HttpClient => self::site($c, 15));

        $container->share(ApiClient::class, static function (Container $c): ApiClient {
            /** @var Transport $http */
            $http = $c->get('http.default');

            return new ApiClient($http);
        });

        // ReferenceService takes an ApiClient built on the 8s transport — a
        // *different* ApiClient instance from the shared one above.
        $container->share('api.client.reference', static function (Container $c): ApiClient {
            /** @var Transport $http */
            $http = $c->get('http.reference');

            return new ApiClient($http);
        });
    }

    /** An HTTPS-only transport — the default for everything that is not the AI provider. */
    private static function site(Container $c, int $timeout = 30): HttpClient
    {
        return new HttpClient($timeout, UrlPolicy::Site, $c->get(UrlGuard::class));
    }

    /** Wraps a site-facing `HttpClient` so its exchanges feed the shared Request Log. */
    private static function recorded(Container $c, HttpClient $inner): RecordingTransport
    {
        /** @var RequestLog $sink */
        $sink = $c->get(RequestLog::class);

        return new RecordingTransport($inner, $sink);
    }
}
