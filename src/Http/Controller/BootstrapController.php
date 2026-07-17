<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http\Controller;

use Boson\Contracts\Http\ResponseInterface;
use Grafida\Ai\AiServiceManager;
use Grafida\Ai\AiToolRepository;
use Grafida\Ai\Defaults;
use Grafida\Display\DisplayModeService;
use Grafida\Editor\SlashToolsService;
use Grafida\Field\FieldSupport;
use Grafida\Http\Json;
use Grafida\Http\RouteContext;
use Grafida\Http\Router;
use Grafida\Http\SiteContext;
use Grafida\I18n\LanguageService;
use Grafida\I18n\UiStrings;
use Grafida\Site\SiteService;
use Grafida\Support\App;

/** Handles `GET /api/bootstrap`, the app-state payload the SPA loads at start-up. */
final class BootstrapController extends Controller
{
    public function __construct(
        private readonly SiteService $sites,
        private readonly SiteContext $siteContext,
        private readonly LanguageService $language,
        private readonly DisplayModeService $displayMode,
        private readonly SlashToolsService $slashTools,
        private readonly Defaults $aiDefaults,
        private readonly AiServiceManager $aiServices,
        private readonly AiToolRepository $aiTools,
    ) {}

    public function registerRoutes(Router $router): void
    {
        $router->add('GET', '/api/bootstrap', fn (RouteContext $ctx): ResponseInterface => $this->bootstrap());
    }

    public function bootstrap(): ResponseInterface
    {
        // The AI subsystem is optional: a failure assembling it (e.g. a missing
        // bundled resource in a build) must never blank the rest of the app, so
        // it degrades to "no AI configured" rather than failing the whole payload.
        $ai = $this->aiBootstrap();

        return Json::ok([
            'strings'             => $this->language->strings(UiStrings::KEYS),
            'language'            => $this->language->currentTag(),
            'languageOverride'    => $this->language->override(),
            'availableLanguages'  => $this->language->available(),
            'displayMode'         => $this->displayMode->current(),
            'systemPrefersDark'   => $this->displayMode->systemPrefersDark(),
            'slashTools'          => $this->slashTools->current(),
            'secureStore'         => $this->sites->hasSecureStore(),
            'supportedFieldTypes' => FieldSupport::SUPPORTED,
            'sites'               => array_map($this->siteContext->siteArray(...), $this->sites->list()),
            'app'                 => App::info(),
            'aiServices'          => $ai['aiServices'],
            'aiDefaultServiceId'  => $ai['aiDefaultServiceId'],
            'aiProviders'         => $ai['aiProviders'],
            'secureStoreAi'       => $ai['secureStoreAi'],
            'aiTools'             => $ai['aiTools'],
        ]);
    }

    /**
     * Assembles the AI portion of the bootstrap payload, degrading to empty data
     * on any failure so a broken AI subsystem cannot take down the whole SPA.
     *
     * @return array{aiServices: list<array<string, mixed>>, aiDefaultServiceId: int|null, aiProviders: array<string, mixed>, secureStoreAi: bool, aiTools: list<array<string, mixed>>}
     */
    private function aiBootstrap(): array
    {
        try {
            $allTools     = $this->aiDefaults->effectiveTools($this->aiTools);
            $enabledTools = array_values(array_filter($allTools, static fn (array $t): bool => $t['enabled']));

            return [
                'aiServices'         => array_map(static fn ($s) => $s->toArray(), $this->aiServices->list()),
                'aiDefaultServiceId' => $this->aiServices->default()?->id,
                'aiProviders'        => $this->aiDefaults->providers(),
                'secureStoreAi'      => $this->aiServices->hasSecureStore(),
                'aiTools'            => $enabledTools,
            ];
        } catch (\Throwable) {
            return [
                'aiServices'         => [],
                'aiDefaultServiceId' => null,
                'aiProviders'        => [],
                'secureStoreAi'      => false,
                'aiTools'            => [],
            ];
        }
    }
}
