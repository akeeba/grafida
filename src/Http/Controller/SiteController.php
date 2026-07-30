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
use Grafida\Field\FieldCategoryScope;
use Grafida\Field\FieldSupport;
use Grafida\Http\Json;
use Grafida\Http\RouteContext;
use Grafida\Http\Router;
use Grafida\Http\SiteContext;
use Grafida\Reference\EditorCssService;
use Grafida\Reference\ReferenceService;
use Grafida\Site\ConnectionDiagnostics;
use Grafida\Site\FaviconService;
use Grafida\Site\Site;
use Grafida\Site\SiteService;

/** Handles `/api/sites*` (site CRUD, connection test, reference cache, editor CSS). */
final class SiteController extends Controller
{
    public function __construct(
        private readonly SiteService $sites,
        private readonly FaviconService $favicons,
        private readonly ReferenceService $references,
        private readonly SiteContext $siteContext,
        private readonly FieldSupport $fields,
        private readonly FieldCategoryScope $fieldScope,
        private readonly EditorCssService $editorCss,
        private readonly ConnectionDiagnostics $diagnostics,
    ) {}

    public function registerRoutes(Router $router): void
    {
        $router->add('POST', '/api/sites/test', fn (RouteContext $ctx): ResponseInterface => $this->testConnection($ctx->body()));
        // Registered before the /api/sites/{id}/... routes' fellow-traveller
        // patterns: {id} only ever matches digits, so "diagnose" cannot be
        // shadowed regardless of order — kept literal-first anyway to match
        // /api/sites/test's convention.
        $router->add('POST', '/api/sites/diagnose', fn (RouteContext $ctx): ResponseInterface => $this->diagnoseConnection($ctx->body()));
        $router->add('GET', '/api/sites', fn (RouteContext $ctx): ResponseInterface => $this->listSites());
        $router->add('POST', '/api/sites', fn (RouteContext $ctx): ResponseInterface => $this->createSite($ctx->body()));
        $router->add('PATCH', '/api/sites/{id}', fn (RouteContext $ctx): ResponseInterface => $this->updateSite($ctx->int('id'), $ctx->body()));
        $router->add('DELETE', '/api/sites/{id}', fn (RouteContext $ctx): ResponseInterface => $this->deleteSite($ctx->int('id')));
        $router->add('GET', '/api/sites/{id}/references', fn (RouteContext $ctx): ResponseInterface => $this->references($ctx->int('id'), false));
        $router->add('POST', '/api/sites/{id}/references/refresh', fn (RouteContext $ctx): ResponseInterface => $this->references($ctx->int('id'), true));
        $router->add('GET', '/api/sites/{id}/editor-css', fn (RouteContext $ctx): ResponseInterface => $this->editorCss($ctx->int('id')));
    }

    /** @param array<string, mixed> $body */
    public function testConnection(array $body): ResponseInterface
    {
        $apiBase = $this->sites->testConnection($this->str($body, 'url'), $this->str($body, 'token'));

        return Json::ok(['apiBase' => $apiBase]);
    }

    /**
     * Runs the same API-base probe as {@see testConnection()}, but reports
     * every candidate base tried — the full request/response exchange,
     * redacted and body-formatted — instead of only the final verdict. Never
     * throws for a failed connection: an unreachable site is the normal case
     * here, and the whole point is to show the user the exchange.
     *
     * The edit-site modal leaves the token field blank to mean "keep the
     * stored token"; when the posted token is empty and an existing `siteId`
     * is given, fall back to that site's stored token via
     * {@see SiteService::tokenFor()}.
     *
     * @param array<string, mixed> $body
     */
    public function diagnoseConnection(array $body): ResponseInterface
    {
        $url   = $this->str($body, 'url');
        $token = $this->str($body, 'token');

        if ($token === '') {
            $siteId = $this->int($body, 'siteId');
            $site   = $siteId > 0 ? $this->sites->find($siteId) : null;

            if ($site !== null) {
                $token = $this->sites->tokenFor($site) ?? '';
            }
        }

        return Json::ok($this->diagnostics->run($url, $token));
    }

    public function listSites(): ResponseInterface
    {
        return Json::ok(array_map($this->siteContext->siteArray(...), $this->sites->list()));
    }

    /** @param array<string, mixed> $body */
    public function createSite(array $body): ResponseInterface
    {
        $allowInsecureVal = $body['allowInsecure'] ?? false;
        $site = $this->sites->create(
            $this->str($body, 'title'),
            $this->str($body, 'url'),
            $this->str($body, 'token'),
            is_bool($allowInsecureVal) ? $allowInsecureVal : (bool) $allowInsecureVal,
            $this->editorCssUrlFrom($body),
        );

        // Warm the categories/tags/languages cache the moment a site is connected,
        // so the first article (new or existing) has its selectors populated.
        $this->warmCaches($site);

        return Json::ok($this->siteContext->siteArray($site), 201);
    }

    /** @param array<string, mixed> $body */
    public function updateSite(int $id, array $body): ResponseInterface
    {
        $tokenVal = $body['token'] ?? null;
        $token    = is_string($tokenVal) && $tokenVal !== '' ? $tokenVal : null;

        $allowInsecureVal = $body['allowInsecure'] ?? false;
        $site = $this->sites->update(
            $id,
            $this->str($body, 'title'),
            $this->str($body, 'url'),
            $token,
            is_bool($allowInsecureVal) ? $allowInsecureVal : (bool) $allowInsecureVal,
            $this->editorCssUrlFrom($body),
        );

        // Re-warm reference data in case the URL, token or editor CSS override changed.
        $this->warmCaches($site);

        return Json::ok($this->siteContext->siteArray($site));
    }

    /**
     * Fills every per-site cache the editor reads, best-effort, on the one
     * request the user already expects to talk to the site.
     *
     * ⚠️ **Everything the editor needs from a site is warmed here, or it is
     * warmed in front of the user while they are trying to write.** That is the
     * whole point: opening an article is a *local* operation and must not wait
     * on a site, so anything it would otherwise have to fetch is fetched at
     * connect/edit time instead. The editor stylesheet is the expensive one —
     * template discovery plus a walk over up to eight candidate URLs — and it
     * is refreshed explicitly, because {@see EditorCssService::load()} is
     * otherwise cache-first and would never look.
     *
     * None of it can fail the save: the reference lists are best-effort by
     * construction and the CSS load swallows its own transport errors.
     */
    private function warmCaches(Site $site): void
    {
        $this->references->sync($site);
        $this->editorCss->load($site, true);
        $this->favicons->sync($site);
    }

    /**
     * The editor.css override as the form sends it: an empty field means "no
     * override, discover it" rather than an empty URL.
     *
     * @param array<string, mixed> $body
     */
    private function editorCssUrlFrom(array $body): ?string
    {
        $value = $this->str($body, 'editorCssUrl');

        return $value !== '' ? $value : null;
    }

    public function deleteSite(int $id): ResponseInterface
    {
        $this->sites->delete($id);

        return Json::ok();
    }

    public function references(int $siteId, bool $refresh): ResponseInterface
    {
        $site = $this->siteContext->requireSite($siteId);

        // Opening the editor (refresh = false) is a best-effort attempt: a list
        // that cannot be fetched falls back to its cached copy with a short
        // timeout. The explicit "refresh" button (refresh = true) is strict so
        // the user sees why a refresh failed.
        $bestEffort = !$refresh;

        $fieldDefs  = $this->references->fields($site, $refresh, $bestEffort);
        $categories = $this->references->categories($site, $refresh, $bestEffort);

        // Each field gets the expanded list of categories Joomla uses it in
        // (`categoryIds`, null = all), so the SPA can re-scope the sidebar on a
        // category change with an includes() test and no round-trip. The tree
        // walk stays here, in the one place that owns the rule.
        $fieldDefs = $this->fieldScope->annotate($fieldDefs, $categories);

        // The explicit refresh also re-downloads the favicon, so the SPA can
        // update the icon shown for the site without a full reload, and
        // re-discovers the editor stylesheet — a template change is exactly the
        // kind of thing "refresh this site's metadata" is expected to pick up,
        // and this is the only path that ever looks for it again (the load is
        // cache-first, see EditorCssService).
        if ($refresh && $site->id !== null) {
            $this->favicons->sync($site);
            $this->editorCss->load($site, true);
        }

        return Json::ok([
            'categories' => $categories,
            'tags'       => $this->references->tags($site, $refresh, $bestEffort),
            'levels'     => $this->references->accessLevels($site, $refresh, $bestEffort),
            'languages'  => $this->references->contentLanguages($site, $refresh, $bestEffort),
            'fields'     => $this->fields->partition($fieldDefs),
            // Drives the SPA's alias preview: with Unicode Aliases on, a Greek
            // title stays Greek instead of being transliterated away.
            'unicodeSlugs' => $this->references->unicodeSlugs($site, $refresh),
            'favicon'    => $refresh && $site->id !== null ? $this->favicons->dataUri($site->id) : null,
            // When the cache was last warmed (the oldest of the refreshable
            // kinds), or null when it has never been fully warmed. Read after
            // the refresh calls above, so a refresh = true request reports the
            // timestamp it just wrote. Drives the SPA's background freshening.
            'fetchedAt' => $this->references->fetchedAt($site),
        ]);
    }

    public function editorCss(int $siteId): ResponseInterface
    {
        $site = $this->siteContext->requireSite($siteId);

        return Json::ok(['css' => $this->editorCss->load($site)]);
    }
}
