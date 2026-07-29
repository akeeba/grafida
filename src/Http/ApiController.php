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
use Grafida\Http\Controller\AiChatController;
use Grafida\Http\Controller\AiServiceController;
use Grafida\Http\Controller\ArticleController;
use Grafida\Http\Controller\BootstrapController;
use Grafida\Http\Controller\DraftController;
use Grafida\Http\Controller\HelpController;
use Grafida\Http\Controller\MediaController;
use Grafida\Http\Controller\SettingsController;
use Grafida\Http\Controller\SiteController;
use Grafida\Joomla\ApiException;
use Grafida\Publish\PublishBlockedException;
use Grafida\Site\SecureStoreUnavailableException;

/**
 * Routes and handles the application's internal JSON API (the front-end calls
 * these via fetch('boson://app/api/...')).
 *
 * This class is a thin dispatcher: every actual handler lives on one of the
 * per-domain controllers under {@see \Grafida\Http\Controller}, each a
 * container service taking only the collaborators its own handlers use.
 * `ApiController` assembles the full route table from them and keeps the
 * exception-to-HTTP-status mapping that used to live at the end of the old
 * monolithic `dispatch()`.
 */
final class ApiController
{
    private readonly Router $router;

    public function __construct(
        private readonly BootstrapController $bootstrap,
        private readonly SiteController $siteController,
        private readonly ArticleController $articleController,
        private readonly DraftController $draftController,
        private readonly MediaController $mediaController,
        private readonly AiServiceController $aiServiceController,
        private readonly AiChatController $aiChatController,
        private readonly SettingsController $settingsController,
        private readonly HelpController $helpController,
    ) {
        $this->router = $this->buildRouter();
    }

    public function dispatch(RequestInterface $request): ResponseInterface
    {
        $method = strtoupper((string) $request->method);
        $path   = (string) $request->url->path;
        $body   = $this->body($request);

        try {
            return $this->router->dispatch($method, $path, $body, $request);
        } catch (PublishBlockedException $e) {
            return Json::error($e->getMessage(), 422, [
                'code'        => 'publish_blocked',
                'fieldLabels' => $e->fieldLabels,
            ]);
        } catch (SecureStoreUnavailableException $e) {
            return Json::error($e->getMessage(), 409, ['code' => 'secure_store_unavailable']);
        } catch (ApiException $e) {
            return Json::error($e->getMessage(), 502, ['code' => 'joomla_api', 'status' => $e->status]);
        } catch (HttpException $e) {
            // A transport failure the user can act on ("you are offline / the site is
            // down") versus one they cannot ("TLS handshake failed"). Only the former
            // gets the friendly code; the rest stay a generic transport error so we
            // never tell someone to check their internet connection over a bad
            // certificate. See gh-29.
            $connectivity = $e->isConnectivityFailure();

            return Json::error($e->getMessage(), 503, [
                'code'   => $connectivity ? 'network_unreachable' : 'transport',
                'detail' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            return Json::error($e->getMessage(), 500, ['code' => 'internal']);
        }
    }

    /**
     * Registers every `/api/...` route by delegating to each per-domain
     * controller's own `registerRoutes()` — this is the full route table the
     * old hand-rolled `preg_match` chain (and, later, the monolithic
     * `ApiController::buildRouter()`) implemented.
     */
    private function buildRouter(): Router
    {
        $router = new Router();

        $this->bootstrap->registerRoutes($router);
        $this->siteController->registerRoutes($router);
        $this->articleController->registerRoutes($router);
        $this->draftController->registerRoutes($router);
        $this->mediaController->registerRoutes($router);
        $this->aiServiceController->registerRoutes($router);
        $this->aiChatController->registerRoutes($router);
        $this->settingsController->registerRoutes($router);
        $this->helpController->registerRoutes($router);

        return $router;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(RequestInterface $request): array
    {
        $raw = $request->body;

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
