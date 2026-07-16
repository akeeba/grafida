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
use Grafida\Field\FieldSupport;
use Grafida\Http\Json;
use Grafida\Http\RouteContext;
use Grafida\Http\Router;
use Grafida\Http\SiteContext;
use Grafida\Reference\EditorCssService;
use Grafida\Reference\ReferenceService;
use Grafida\Site\FaviconService;
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
        private readonly EditorCssService $editorCss,
    ) {}

    public function registerRoutes(Router $router): void
    {
        $router->add('POST', '/api/sites/test', fn (RouteContext $ctx): ResponseInterface => $this->testConnection($ctx->body()));
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
        $this->references->sync($site);
        $this->favicons->sync($site);

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

        // Re-warm reference data in case the URL or token changed.
        $this->references->sync($site);
        $this->favicons->sync($site);

        return Json::ok($this->siteContext->siteArray($site));
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

        $fieldDefs = $this->references->fields($site, $refresh, $bestEffort);

        // The explicit refresh also re-downloads the favicon, so the SPA can
        // update the icon shown for the site without a full reload.
        if ($refresh && $site->id !== null) {
            $this->favicons->sync($site);
        }

        return Json::ok([
            'categories' => $this->references->categories($site, $refresh, $bestEffort),
            'tags'       => $this->references->tags($site, $refresh, $bestEffort),
            'levels'     => $this->references->accessLevels($site, $refresh, $bestEffort),
            'languages'  => $this->references->contentLanguages($site, $refresh, $bestEffort),
            'fields'     => $this->fields->partition($fieldDefs),
            // Drives the SPA's alias preview: with Unicode Aliases on, a Greek
            // title stays Greek instead of being transliterated away.
            'unicodeSlugs' => $this->references->unicodeSlugs($site, $refresh),
            'favicon'    => $refresh && $site->id !== null ? $this->favicons->dataUri($site->id) : null,
        ]);
    }

    public function editorCss(int $siteId): ResponseInterface
    {
        $site = $this->siteContext->requireSite($siteId);

        return Json::ok(['css' => $this->editorCss->load($site)]);
    }
}
