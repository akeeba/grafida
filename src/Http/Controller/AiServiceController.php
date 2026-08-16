<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http\Controller;

use Boson\Contracts\Http\ResponseInterface;
use Grafida\Ai\AiServiceManager;
use Grafida\Ai\AiTool;
use Grafida\Ai\AiToolRepository;
use Grafida\Ai\Defaults;
use Grafida\Http\InsecureUrlException;
use Grafida\Http\Json;
use Grafida\Http\RouteContext;
use Grafida\Http\Router;
use Grafida\Http\Security\UrlGuard;
use Grafida\Http\Security\UrlPolicy;
use Grafida\Storage\SettingsRepository;

/**
 * Handles `/api/ai/services*`, `/api/ai/tools*` and `PUT /api/ai/system-prompt`
 * — AI service/tool configuration CRUD and the resolved per-service config the
 * SPA transport needs to call a provider directly.
 *
 * ⚠️ **This controller, not just the transport, has to enforce the AI URL
 * policy** — and it is the *only* place that can. Every other outbound request
 * in the app is made by PHP, where {@see \Grafida\Http\HttpClient} is an
 * unavoidable chokepoint; the AI streaming call is made by the SPA's own
 * `fetch()` (see `.claude/rules/ai-assistant.md` for why streaming forces that),
 * so PHP never sees it and cannot block it. What PHP *does* control is
 * `GET /api/ai/services/{id}/resolved`, the endpoint that hands the SPA the URL
 * and the API key to use. Refusing to answer it is therefore the enforcement
 * point for the direct path: no resolved config, no key, no request.
 */
final class AiServiceController extends Controller
{
    public function __construct(
        private readonly AiServiceManager $aiServices,
        private readonly SettingsRepository $settings,
        private readonly Defaults $aiDefaults,
        private readonly AiToolRepository $aiTools,
        private readonly UrlGuard $urlGuard,
    ) {}

    public function registerRoutes(Router $router): void
    {
        $router->add('GET', '/api/ai/services', fn (RouteContext $ctx): ResponseInterface => $this->listAiServices());
        $router->add('POST', '/api/ai/services', fn (RouteContext $ctx): ResponseInterface => $this->createAiService($ctx->body()));
        $router->add('GET', '/api/ai/tools', fn (RouteContext $ctx): ResponseInterface => $this->listAiTools());
        $router->add('PUT', '/api/ai/system-prompt', fn (RouteContext $ctx): ResponseInterface => $this->setSystemPrompt($ctx->body()));
        $router->add('POST', '/api/ai/tools', fn (RouteContext $ctx): ResponseInterface => $this->createAiTool($ctx->body()));
        $router->add('PATCH', '/api/ai/tools/{key}', fn (RouteContext $ctx): ResponseInterface => $this->updateAiTool($ctx->string('key'), $ctx->body()));
        $router->add('DELETE', '/api/ai/tools/{key}', fn (RouteContext $ctx): ResponseInterface => $this->deleteAiTool($ctx->string('key')));
        $router->add('GET', '/api/ai/services/{id}/resolved', fn (RouteContext $ctx): ResponseInterface => $this->resolvedAiService($ctx->int('id'), $ctx->request()->url->query->get('tool') ?? ''));
        $router->add('POST', '/api/ai/services/{id}/default', fn (RouteContext $ctx): ResponseInterface => $this->setAiServiceDefault($ctx->int('id')));
        $router->add('GET', '/api/ai/services/{id}', fn (RouteContext $ctx): ResponseInterface => $this->getAiService($ctx->int('id')));
        $router->add('PATCH', '/api/ai/services/{id}', fn (RouteContext $ctx): ResponseInterface => $this->updateAiService($ctx->int('id'), $ctx->body()));
        $router->add('DELETE', '/api/ai/services/{id}', fn (RouteContext $ctx): ResponseInterface => $this->deleteAiService($ctx->int('id')));
    }

    public function listAiServices(): ResponseInterface
    {
        return Json::ok(array_map(
            static fn ($s) => $s->toArray(),
            $this->aiServices->list(),
        ));
    }

    public function getAiService(int $id): ResponseInterface
    {
        $service = $this->aiServices->find($id);

        if ($service === null) {
            return Json::error('AI service not found', 404);
        }

        return Json::ok($service->toArray());
    }

    /** @param array<string, mixed> $body */
    public function createAiService(array $body): ResponseInterface
    {
        $allowInsecureVal = $body['allowInsecure'] ?? false;

        // Reject a bad endpoint while the user is still looking at the form,
        // rather than storing it and failing on every chat afterwards. The
        // resolved-config check above is the guarantee; this is the manners.
        $endpointError = $this->savedEndpointRejection($this->str($body, 'endpoint'));

        if ($endpointError !== null) {
            return Json::error($endpointError, 400, ['code' => 'insecure_url']);
        }

        $paramsRaw = $body['params'] ?? null;
        /** @var array<string, mixed> $params */
        $params = is_array($paramsRaw) ? $paramsRaw : [];

        $service = $this->aiServices->create([
            'name'          => $this->str($body, 'name'),
            'provider'      => $this->str($body, 'provider'),
            'endpoint'      => $this->str($body, 'endpoint'),
            'model'         => $this->str($body, 'model'),
            'key'           => $this->str($body, 'key'),
            'params'        => $params,
            'allowInsecure' => is_bool($allowInsecureVal) ? $allowInsecureVal : (bool) $allowInsecureVal,
        ]);

        return Json::ok($service->toArray(), 201);
    }

    /** @param array<string, mixed> $body */
    public function updateAiService(int $id, array $body): ResponseInterface
    {
        $existing = $this->aiServices->find($id);

        if ($existing === null) {
            return Json::error('AI service not found', 404);
        }

        $allowInsecureVal = $body['allowInsecure'] ?? false;

        $paramsRaw = $body['params'] ?? null;
        /** @var array<string, mixed> $params */
        $params = is_array($paramsRaw) ? $paramsRaw : $existing->params;

        $data = [
            'params'        => $params,
            'allowInsecure' => is_bool($allowInsecureVal) ? $allowInsecureVal : (bool) $allowInsecureVal,
        ];

        // Only include fields that are explicitly provided in the body.
        if (array_key_exists('name', $body)) {
            $data['name'] = $this->str($body, 'name');
        }
        if (array_key_exists('provider', $body)) {
            $data['provider'] = $this->str($body, 'provider');
        }
        if (array_key_exists('endpoint', $body)) {
            $endpointError = $this->savedEndpointRejection($this->str($body, 'endpoint'));

            if ($endpointError !== null) {
                return Json::error($endpointError, 400, ['code' => 'insecure_url']);
            }

            $data['endpoint'] = $this->str($body, 'endpoint');
        }
        if (array_key_exists('model', $body)) {
            $data['model'] = $this->str($body, 'model');
        }

        // Only re-store the key when a non-empty value is supplied.
        $keyVal = $body['key'] ?? null;
        if (is_string($keyVal) && $keyVal !== '') {
            $data['key'] = $keyVal;
        }

        $service = $this->aiServices->update($id, $data);

        return Json::ok($service->toArray());
    }

    public function deleteAiService(int $id): ResponseInterface
    {
        $service = $this->aiServices->find($id);

        if ($service === null) {
            return Json::error('AI service not found', 404);
        }

        $this->aiServices->delete($id);

        return Json::ok();
    }

    public function setAiServiceDefault(int $id): ResponseInterface
    {
        $service = $this->aiServices->find($id);

        if ($service === null) {
            return Json::error('AI service not found', 404);
        }

        $this->aiServices->setDefault($id);

        $updated = $this->aiServices->find($id);

        return Json::ok($updated?->toArray());
    }

    /**
     * Returns the full effective tool list, the current system-prompt (override or
     * bundled default), and all available tones.
     */
    public function listAiTools(): ResponseInterface
    {
        $systemPromptOverride = $this->settings->get('ai_system_prompt');
        $systemPrompt         = ($systemPromptOverride !== null && $systemPromptOverride !== '')
            ? $systemPromptOverride
            : $this->aiDefaults->systemPrompt();

        return Json::ok([
            'tools'        => $this->aiDefaults->effectiveTools($this->aiTools),
            'systemPrompt' => $systemPrompt,
            'tones'        => $this->aiDefaults->tones(),
        ]);
    }

    /**
     * The tool a given key currently resolves to — the bundled default for a
     * built-in, with any stored override already applied — or null for a key
     * that names neither.
     *
     * @return array{id: int|null, toolKey: string, title: string, icon: string, prompt: string, overrideSystem: bool, tone: string, params: array<string, mixed>, serviceId: int|null, isCustom: bool, enabled: bool, sortOrder: int}|null
     */
    private function effectiveTool(string $key): ?array
    {
        foreach ($this->aiDefaults->effectiveTools($this->aiTools) as $tool) {
            if ($tool['toolKey'] === $key) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Stores or clears a system-prompt override.
     *
     * An empty/omitted `prompt` key restores the bundled default (the stored
     * override is cleared so the setting is transparent on next read).
     *
     * @param array<string, mixed> $body
     */
    public function setSystemPrompt(array $body): ResponseInterface
    {
        $prompt = $this->str($body, 'prompt');

        if ($prompt === '') {
            // Restore default: store empty string so subsequent reads fall back.
            $this->settings->set('ai_system_prompt', '');
        } else {
            $this->settings->set('ai_system_prompt', $prompt);
        }

        return Json::ok(['systemPrompt' => $prompt !== '' ? $prompt : $this->aiDefaults->systemPrompt()]);
    }

    /**
     * Updates (upserts) a built-in tool's override. The request body may carry
     * any subset of: prompt, params, tone, serviceId, enabled, sortOrder, title, icon.
     *
     * @param array<string, mixed> $body
     */
    public function updateAiTool(string $key, array $body): ResponseInterface
    {
        // A key that begins with nothing can't be overridden if no bundled tool
        // exists — but we allow it for future flexibility. The override is always
        // is_custom = false (PATCH is for built-ins only; POST creates custom tools).
        $existing = $this->aiTools->findByKey($key);

        // A PATCH may carry any subset of the fields, but the row it writes is
        // whole — so whatever the body omits has to come from the tool as it
        // stands *right now*, bundled defaults included. Falling back to the
        // override row alone is what let a body carrying only `enabled` (the
        // list's toggle button) blank a bundled tool's title, icon, prompt and
        // tone the first time it was pressed — invisible until gh-28 made the
        // stored title and icon authoritative.
        $current = $this->effectiveTool($key);

        $paramsRaw = $body['params'] ?? null;
        /** @var array<string, mixed> $params */
        $params = is_array($paramsRaw) ? $paramsRaw : ($current['params'] ?? []);

        $serviceIdRaw = $body['serviceId'] ?? null;
        $serviceId    = is_numeric($serviceIdRaw) ? (int) $serviceIdRaw : ($current['serviceId'] ?? null);

        $enabledRaw = $body['enabled'] ?? null;
        $enabled    = $enabledRaw !== null ? (bool) $enabledRaw : ($current['enabled'] ?? true);

        $sortOrderRaw = $body['sortOrder'] ?? null;
        $sortOrder    = is_numeric($sortOrderRaw) ? (int) $sortOrderRaw : ($current['sortOrder'] ?? 0);

        $titleRaw = $body['title'] ?? null;
        $title    = is_string($titleRaw) ? $titleRaw : ($current['title'] ?? $key);

        $iconRaw = $body['icon'] ?? null;
        $icon    = is_string($iconRaw) ? $iconRaw : ($current['icon'] ?? '');

        $promptRaw = $body['prompt'] ?? null;
        $prompt    = is_string($promptRaw) ? $promptRaw : ($current['prompt'] ?? '');

        $toneRaw = $body['tone'] ?? null;
        $tone    = is_string($toneRaw) ? $toneRaw : ($current['tone'] ?? '');

        $overrideSystemRaw = $body['overrideSystem'] ?? null;
        $overrideSystem    = $overrideSystemRaw !== null ? (bool) $overrideSystemRaw : ($current['overrideSystem'] ?? false);

        $tool = new AiTool(
            id: $existing !== null ? $existing->id : null,
            toolKey: $key,
            title: $title,
            icon: $icon,
            prompt: $prompt,
            overrideSystem: $overrideSystem,
            tone: $tone,
            params: $params,
            serviceId: $serviceId,
            // PATCH edits a tool, it never changes what kind of tool it is: a
            // custom tool stays custom (demoting one to a built-in override
            // would leave it matching no bundled key, i.e. gone from the list),
            // and a body for a key with no row yet is a built-in's override.
            isCustom: $existing !== null && $existing->isCustom,
            enabled: $enabled,
            sortOrder: $sortOrder,
        );

        $id = $this->aiTools->upsert($tool);

        return Json::ok(array_merge($tool->toArray(), ['id' => $id]));
    }

    /**
     * Creates a new custom tool (is_custom = true).
     *
     * Requires a unique `toolKey` in the body.  If the key already exists the
     * request is rejected with 409.
     *
     * @param array<string, mixed> $body
     */
    public function createAiTool(array $body): ResponseInterface
    {
        $key = trim($this->str($body, 'toolKey'));

        if ($key === '') {
            return Json::error('A toolKey is required to create a custom AI tool.', 400);
        }

        if ($this->aiTools->findByKey($key) !== null) {
            return Json::error('An AI tool with key "' . $key . '" already exists.', 409);
        }

        $paramsRaw = $body['params'] ?? null;
        /** @var array<string, mixed> $params */
        $params = is_array($paramsRaw) ? $paramsRaw : [];

        $serviceIdRaw = $body['serviceId'] ?? null;
        $serviceId    = is_numeric($serviceIdRaw) ? (int) $serviceIdRaw : null;

        $overrideSystemRaw = $body['overrideSystem'] ?? null;
        $overrideSystem    = $overrideSystemRaw !== null ? (bool) $overrideSystemRaw : false;

        $enabledRaw = $body['enabled'] ?? null;
        $enabled    = $enabledRaw !== null ? (bool) $enabledRaw : true;

        $sortOrderRaw = $body['sortOrder'] ?? null;
        $sortOrder    = is_numeric($sortOrderRaw) ? (int) $sortOrderRaw : 0;

        $tool = new AiTool(
            id: null,
            toolKey: $key,
            title: $this->str($body, 'title', $key),
            icon: $this->str($body, 'icon'),
            prompt: $this->str($body, 'prompt'),
            overrideSystem: $overrideSystem,
            tone: $this->str($body, 'tone'),
            params: $params,
            serviceId: $serviceId,
            isCustom: true,
            enabled: $enabled,
            sortOrder: $sortOrder,
        );

        $id = $this->aiTools->upsert($tool);

        return Json::ok(array_merge($tool->toArray(), ['id' => $id]), 201);
    }

    /**
     * Deletes a tool override or custom tool by key.
     */
    public function deleteAiTool(string $key): ResponseInterface
    {
        if ($this->aiTools->findByKey($key) === null) {
            return Json::error('AI tool "' . $key . '" not found.', 404);
        }

        $this->aiTools->delete($key);

        return Json::ok();
    }

    /**
     * Returns the complete resolved configuration the SPA transport needs to
     * call the AI provider directly (for streaming via EventSource).
     *
     * The resolved configuration includes:
     * - `endpoint`    — the service's configured base endpoint URL
     * - `chatPath`    — the provider's chat completion path (e.g. `/chat/completions`)
     * - `sseDialect`  — `"openai_completions"`, `"openai_responses"` or `"anthropic"`
     * - `model`       — the service's configured model identifier
     * - `authHeader`  — the auth header name (`Authorization` or `X-Api-Key`)
     * - `apiKey`      — the resolved API key (from OS keychain or insecure fallback)
     * - `params`      — merged model params (service params ← tool params overlay)
     *
     * SECURITY NOTE (desktop-only trade-off):
     * Returning the raw API key to local JavaScript is intentional here.
     * Grafida is a single-user desktop application — the "browser" and the
     * "server" run in the same OS process under the same user account.  There is
     * no network boundary between PHP and the webview; exposing the key to the
     * local JS runtime is no less secure than keeping it in PHP, and it is
     * required to allow the SPA to open a native EventSource for SSE streaming
     * (which PHP cannot proxy line-by-line without holding up the request thread).
     */
    public function resolvedAiService(int $id, string $toolKey): ResponseInterface
    {
        $service = $this->aiServices->find($id);

        if ($service === null) {
            return Json::error('AI service not found', 404);
        }

        $providers = $this->aiDefaults->providers();
        $preset    = $providers[$service->provider] ?? null;

        // Resolved endpoint: service's own field (may be empty for preset providers).
        $endpoint = $service->endpoint !== '' ? $service->endpoint : (
            is_array($preset) ? ($preset['endpoint'] ?? '') : ''
        );

        $chatPath   = is_array($preset) ? ($preset['chat_path'] ?? '/chat/completions') : '/chat/completions';
        $sseDialect = is_array($preset) ? ($preset['sse_dialect'] ?? 'openai_completions') : 'openai_completions';
        $authType   = is_array($preset) ? ($preset['auth'] ?? 'bearer') : 'bearer';
        $authHeader = $authType === 'x-api-key' ? 'X-Api-Key' : 'Authorization';

        // Before the key goes anywhere. The SPA calls the provider itself, so
        // this is the last point at which PHP can stop a cleartext request to a
        // remote host — and the key is in the payload we would otherwise return.
        $endpointError = $this->endpointRejection($endpoint, $chatPath);

        if ($endpointError !== null) {
            return Json::error($endpointError, 400, ['code' => 'insecure_url']);
        }

        $apiKey = $this->aiServices->resolveKey($id);

        // Merge params: service params as base, tool-specific params as overlay.
        /** @var array<string, mixed> $params */
        $params = $service->params;

        if ($toolKey !== '') {
            $tool = $this->aiTools->findByKey($toolKey);

            if ($tool !== null && $tool->params !== []) {
                $params = array_merge($params, $tool->params);
            }
        }

        return Json::ok([
            'endpoint'   => $endpoint,
            'chatPath'   => $chatPath,
            'sseDialect' => $sseDialect,
            'model'      => $service->model,
            'authHeader' => $authHeader,
            'apiKey'     => $apiKey,
            'params'     => $params,
        ]);
    }

    /**
     * Returns why this endpoint may not be *stored*, or null if it may be.
     *
     * An empty endpoint is accepted here and nowhere else: a preset provider
     * leaves the field blank and inherits the bundled endpoint, which is checked
     * when the config is resolved. Only a URL the user actually typed is judged.
     */
    private function savedEndpointRejection(string $endpoint): ?string
    {
        if (trim($endpoint) === '') {
            return null;
        }

        return $this->endpointRejection($endpoint, '');
    }

    /**
     * Returns why this endpoint may not be called, or null if it may be.
     *
     * The chat path is appended before the check so the URL judged is the one
     * that will actually be requested — an endpoint and a path can disagree
     * about the scheme when the path is itself absolute.
     */
    private function endpointRejection(string $endpoint, string $chatPath): ?string
    {
        if (trim($endpoint) === '') {
            return 'The AI service has no endpoint URL configured.';
        }

        $url = rtrim(trim($endpoint), '/') . $chatPath;

        try {
            $this->urlGuard->assertAllowed($url, UrlPolicy::AiService);
        } catch (InsecureUrlException $e) {
            return $e->getMessage();
        }

        return null;
    }
}
