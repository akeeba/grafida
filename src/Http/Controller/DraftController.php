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
use Grafida\Article\Draft;
use Grafida\Article\DraftExportService;
use Grafida\Article\DraftRepository;
use Grafida\Http\Json;
use Grafida\Http\RouteContext;
use Grafida\Http\Router;
use Grafida\Http\SiteContext;
use Grafida\Media\InlineImageExtractor;
use Grafida\Publish\PublishService;

/**
 * Handles `/api/sites/{id}/drafts`, `/api/drafts/{id}[/publish|/export|/import]`
 * and `POST /api/drafts/import` — local draft CRUD, publishing, and the
 * `.grafida` export/import file format.
 */
final class DraftController extends Controller
{
    public function __construct(
        private readonly SiteContext $siteContext,
        private readonly DraftRepository $drafts,
        private readonly DraftExportService $draftExport,
        private readonly PublishService $publish,
        private readonly InlineImageExtractor $inlineImages,
    ) {}

    public function registerRoutes(Router $router): void
    {
        $router->add('POST', '/api/drafts/import', fn (RouteContext $ctx): ResponseInterface => $this->importDraft($ctx->body()));
        $router->add('GET', '/api/sites/{id}/drafts', fn (RouteContext $ctx): ResponseInterface => $this->listDrafts($ctx->int('id')));
        $router->add('POST', '/api/sites/{id}/drafts', fn (RouteContext $ctx): ResponseInterface => $this->saveDraft($ctx->int('id'), null, $ctx->body()));
        $router->add('POST', '/api/drafts/{id}/export', fn (RouteContext $ctx): ResponseInterface => $this->exportDraft($ctx->int('id'), $ctx->body()));
        $router->add('POST', '/api/drafts/{id}/import', fn (RouteContext $ctx): ResponseInterface => $this->importDraftInto($ctx->int('id'), $ctx->body()));
        $router->add('GET', '/api/drafts/{id}', fn (RouteContext $ctx): ResponseInterface => $this->getDraft($ctx->int('id')));
        $router->add('PUT', '/api/drafts/{id}', fn (RouteContext $ctx): ResponseInterface => $this->saveDraft(null, $ctx->int('id'), $ctx->body()));
        $router->add('DELETE', '/api/drafts/{id}', fn (RouteContext $ctx): ResponseInterface => $this->deleteDraft($ctx->int('id')));
        $router->add('POST', '/api/drafts/{id}/publish', fn (RouteContext $ctx): ResponseInterface => $this->publishDraft($ctx->int('id')));
    }

    public function listDrafts(int $siteId): ResponseInterface
    {
        $drafts = array_map(
            static fn (Draft $d): array => $d->toArray(),
            $this->drafts->forSite($siteId)
        );

        return Json::ok($this->siteContext->withCategoryTitles($drafts, $this->siteContext->requireSite($siteId)));
    }

    public function getDraft(int $id): ResponseInterface
    {
        $draft = $this->drafts->find($id);

        if ($draft === null) {
            return Json::error('Draft not found', 404);
        }

        // One-time legacy migration (gh-36): a draft saved before local media
        // references existed may still carry inline base64 <img src="data:…">.
        // Convert it to fast boson://…/raw references and persist the result so
        // this only ever runs once per draft. A brand-new draft opened straight
        // from the "New article" flow has no id yet — nothing to persist, and
        // nothing to convert either (it starts empty).
        $converted = $this->inlineImages->extract($draft->html, $draft->siteId, $draft->id);

        if ($converted !== $draft->html) {
            $draft->html = $converted;

            if ($draft->id !== null) {
                $this->drafts->update($draft);
            }
        }

        return Json::ok($draft->toArray());
    }

    /** @param array<string, mixed> $body */
    public function saveDraft(?int $siteId, ?int $draftId, array $body): ResponseInterface
    {
        if ($draftId !== null) {
            $existing = $this->drafts->find($draftId);

            if ($existing === null) {
                return Json::error('Draft not found', 404);
            }

            // A draft may be re-pointed at another site (which unlinks it from any
            // remote article); honour the client's siteId, falling back to its own.
            $siteId = $this->int($body, 'siteId', $existing->siteId);
        }

        $remoteIdVal = $body['remoteId'] ?? null;
        $catidVal    = $body['catid'] ?? null;
        $fieldsRaw   = $body['fields'] ?? null;
        $tagsRaw     = $body['tags'] ?? null;
        $imagesRaw   = $body['images'] ?? null;

        /** @var array<string, mixed> $fields */
        $fields = is_array($fieldsRaw) ? $fieldsRaw : [];
        /** @var array<string, mixed> $images */
        $images = is_array($imagesRaw) ? $imagesRaw : [];
        /** @var list<string> $tags */
        $tags   = is_array($tagsRaw)
            ? array_values(array_filter(array_map(static fn (mixed $t): mixed => is_string($t) ? $t : null, $tagsRaw), static fn (mixed $t): bool => $t !== null))
            : [];

        $draft = new Draft(
            id: $draftId,
            siteId: $siteId ?? $this->int($body, 'siteId'),
            remoteId: is_numeric($remoteIdVal) ? (int) $remoteIdVal : null,
            title: $this->str($body, 'title'),
            alias: $this->str($body, 'alias'),
            catid: is_numeric($catidVal) ? (int) $catidVal : null,
            access: $this->int($body, 'access', 1),
            language: $this->str($body, 'language', '*'),
            state: $this->int($body, 'state', 1),
            html: $this->str($body, 'html'),
            fields: $fields,
            tags: $tags,
            images: $images,
            metadesc: $this->str($body, 'metadesc'),
            metakey: $this->str($body, 'metakey'),
            createdByAlias: $this->str($body, 'createdByAlias'),
        );

        if ($draftId === null) {
            $newId = $this->drafts->insert($draft);
            $saved = $this->drafts->find($newId);
        } else {
            $this->drafts->update($draft);
            $saved = $this->drafts->find($draftId);
        }

        return Json::ok($saved?->toArray());
    }

    public function deleteDraft(int $id): ResponseInterface
    {
        $this->drafts->delete($id);

        return Json::ok();
    }

    /**
     * Writes a draft (all visible fields, offline images and saved AI chats,
     * but never its site/remote-article linkage) to a `.grafida` JSON file in
     * the given directory.
     *
     * @param array<string, mixed> $body
     */
    public function exportDraft(int $id, array $body): ResponseInterface
    {
        $directory = $this->str($body, 'directory');

        if ($directory === '' || !is_dir($directory)) {
            return Json::error('A valid destination folder is required.', 400);
        }

        $draft = $this->drafts->find($id);

        if ($draft === null) {
            return Json::error('Draft not found', 404);
        }

        $payload = $this->draftExport->export($id);

        $slug     = self::slugForFilename($draft->alias !== '' ? $draft->alias : $draft->title);
        $filename = ($slug !== '' ? $slug : 'draft') . '.grafida';
        $path     = rtrim($directory, '/\\') . \DIRECTORY_SEPARATOR . $filename;

        $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        if ($json === false || @file_put_contents($path, $json) === false) {
            return Json::error('Could not write the export file', 500);
        }

        return Json::ok(['path' => $path, 'filename' => $filename]);
    }

    private static function slugForFilename(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /**
     * Imports a `.grafida` payload as a brand-new draft on the given site.
     *
     * @param array<string, mixed> $body
     */
    public function importDraft(array $body): ResponseInterface
    {
        $siteId     = $this->int($body, 'siteId', 0);
        $payloadRaw = $body['payload'] ?? null;

        if ($siteId <= 0 || !is_array($payloadRaw)) {
            return Json::error('A siteId and payload are required.', 400);
        }

        /** @var array<string, mixed> $payload */
        $payload = $payloadRaw;
        $draft   = $this->draftExport->importAsNewDraft($siteId, $payload);

        return Json::ok($draft->toArray(), 201);
    }

    /**
     * Replaces an existing draft's content with an imported `.grafida`
     * payload, keeping the draft's own id, site and remote-article linkage.
     *
     * @param array<string, mixed> $body
     */
    public function importDraftInto(int $id, array $body): ResponseInterface
    {
        $payloadRaw = $body['payload'] ?? null;

        if (!is_array($payloadRaw)) {
            return Json::error('A payload is required.', 400);
        }

        if ($this->drafts->find($id) === null) {
            return Json::error('Draft not found', 404);
        }

        /** @var array<string, mixed> $payload */
        $payload = $payloadRaw;
        $draft   = $this->draftExport->replaceDraft($id, $payload);

        return Json::ok($draft->toArray());
    }

    public function publishDraft(int $id): ResponseInterface
    {
        $draft = $this->drafts->find($id);

        if ($draft === null) {
            return Json::error('Draft not found', 404);
        }

        $site   = $this->siteContext->requireSite($draft->siteId);
        $result = $this->publish->publish($draft, $site);

        return Json::ok($result);
    }
}
