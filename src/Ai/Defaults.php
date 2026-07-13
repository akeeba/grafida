<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Ai;

use RuntimeException;

/**
 * Exposes the bundled AI defaults (system prompt, tones, provider presets, and built-in tools)
 * and merges them with any per-tool overrides or custom tools stored in the database.
 *
 * JSON shapes
 * -----------
 *
 * **defaults.json**
 * ```json
 * {
 *   "system": { "prompt": "<string>", "tone": "<string>" },
 *   "tools": {
 *     "<key>": {
 *       "title": "<string>",
 *       "icon": "<string>",
 *       "prompt": "<string>",
 *       "tone": "<string>",
 *       "override_system": <bool>,
 *       "sort_order": <int>
 *     }
 *   }
 * }
 * ```
 *
 * **voices.json**
 * ```json
 * { "<key>": { "label": "<string>", "prompt": "<string>" } }
 * ```
 *
 * **providers.json**
 * ```json
 * {
 *   "<key>": {
 *     "name": "<string>",
 *     "endpoint": "<string>",
 *     "auth": "bearer"|"x-api-key",
 *     "chat_path": "<string>",
 *     "models_path": "<string>|null",
 *     "sse_dialect": "openai_completions"|"openai_responses"|"anthropic"
 *   }
 * }
 * ```
 *
 * **effectiveTools() element**
 * ```php
 * [
 *   'id'             => int|null,
 *   'toolKey'        => string,
 *   'title'          => string,
 *   'icon'           => string,
 *   'prompt'         => string,
 *   'overrideSystem' => bool,
 *   'tone'           => string,
 *   'params'         => array<string, mixed>,
 *   'serviceId'      => int|null,
 *   'isCustom'       => bool,
 *   'enabled'        => bool,
 *   'sortOrder'      => int,
 * ]
 * ```
 */
final class Defaults
{
    /** @var array<string, mixed>|null */
    private ?array $defaultsCache = null;

    /** @var array<string, array{label: string, prompt: string}>|null */
    private ?array $tonesCache = null;

    /** @var array<string, array{name: string, endpoint: string, auth: string, chat_path: string, models_path: string|null, sse_dialect: string}>|null */
    private ?array $providersCache = null;

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Returns the bundled base system prompt.
     */
    public function systemPrompt(): string
    {
        $data   = $this->defaults();
        $system = $data['system'] ?? null;

        if (!is_array($system)) {
            return '';
        }

        $prompt = $system['prompt'] ?? null;

        return is_string($prompt) ? $prompt : '';
    }

    /**
     * Returns the default tone key for the system prompt.
     */
    public function systemTone(): string
    {
        $data   = $this->defaults();
        $system = $data['system'] ?? null;

        if (!is_array($system)) {
            return '';
        }

        $tone = $system['tone'] ?? null;

        return is_string($tone) ? $tone : '';
    }

    /**
     * Returns all available tones keyed by their identifier.
     *
     * @return array<string, array{label: string, prompt: string}>
     */
    public function tones(): array
    {
        if ($this->tonesCache !== null) {
            return $this->tonesCache;
        }

        $raw    = $this->readJson($this->resourcePath('voices.json'));
        $result = [];

        foreach ($raw as $key => $tone) {
            if (!is_array($tone)) {
                continue;
            }

            $label  = isset($tone['label'])  && is_string($tone['label'])  ? $tone['label']  : '';
            $prompt = isset($tone['prompt']) && is_string($tone['prompt']) ? $tone['prompt'] : '';

            $result[$key] = ['label' => $label, 'prompt' => $prompt];
        }

        return $this->tonesCache = $result;
    }

    /**
     * Returns all provider presets keyed by provider identifier.
     *
     * `sse_dialect` is one of `openai_completions`|`openai_responses`|`anthropic`.
     *
     * @return array<string, array{name: string, endpoint: string, auth: string, chat_path: string, models_path: string|null, sse_dialect: string}>
     */
    public function providers(): array
    {
        if ($this->providersCache !== null) {
            return $this->providersCache;
        }

        $raw    = $this->readJson($this->resourcePath('providers.json'));
        $result = [];

        foreach ($raw as $key => $provider) {
            if (!is_array($provider)) {
                continue;
            }

            $name        = isset($provider['name'])        && is_string($provider['name'])        ? $provider['name']        : '';
            $endpoint    = isset($provider['endpoint'])    && is_string($provider['endpoint'])    ? $provider['endpoint']    : '';
            $auth        = isset($provider['auth'])        && is_string($provider['auth'])        ? $provider['auth']        : 'bearer';
            $chatPath    = isset($provider['chat_path'])   && is_string($provider['chat_path'])   ? $provider['chat_path']   : '/chat/completions';
            $sseDial     = isset($provider['sse_dialect']) && is_string($provider['sse_dialect']) ? $provider['sse_dialect'] : 'openai_completions';
            $modelsRaw   = $provider['models_path'] ?? null;
            $modelsPath  = is_string($modelsRaw) ? $modelsRaw : null;

            $result[$key] = [
                'name'        => $name,
                'endpoint'    => $endpoint,
                'auth'        => $auth,
                'chat_path'   => $chatPath,
                'models_path' => $modelsPath,
                'sse_dialect' => $sseDial,
            ];
        }

        return $this->providersCache = $result;
    }

    /**
     * Returns the effective tool list: bundled defaults overlaid with any per-tool DB overrides
     * (prompt, params, tone, service_id, enabled, sort_order), plus any custom tools from the DB,
     * all sorted by sort_order ASC then tool key ASC.
     *
     * DB overrides for a built-in tool are identified by matching tool_key and is_custom = false.
     * Custom tools (is_custom = true) are appended as-is.
     *
     * @return list<array{id: int|null, toolKey: string, title: string, icon: string, prompt: string, overrideSystem: bool, tone: string, params: array<string, mixed>, serviceId: int|null, isCustom: bool, enabled: bool, sortOrder: int}>
     */
    public function effectiveTools(AiToolRepository $tools): array
    {
        $bundledData  = $this->defaults();
        $bundledTools = $bundledData['tools'] ?? null;

        if (!is_array($bundledTools)) {
            $bundledTools = [];
        }

        // Index non-custom DB records by tool_key for O(1) override lookups.
        $overrides = [];

        foreach ($tools->all() as $dbTool) {
            if (!$dbTool->isCustom) {
                $overrides[$dbTool->toolKey] = $dbTool;
            }
        }

        /** @var list<array{id: int|null, toolKey: string, title: string, icon: string, prompt: string, overrideSystem: bool, tone: string, params: array<string, mixed>, serviceId: int|null, isCustom: bool, enabled: bool, sortOrder: int}> $result */
        $result = [];

        // Built-in tools: use bundled defaults, applying any DB override where found.
        foreach ($bundledTools as $key => $def) {
            if (!is_array($def) || !is_string($key)) {
                continue;
            }

            $row = [
                'id'             => null,
                'toolKey'        => $key,
                'title'          => isset($def['title'])         && is_string($def['title'])         ? $def['title']         : $key,
                'icon'           => isset($def['icon'])          && is_string($def['icon'])          ? $def['icon']          : '',
                'prompt'         => isset($def['prompt'])        && is_string($def['prompt'])        ? $def['prompt']        : '',
                'overrideSystem' => isset($def['override_system']) && is_bool($def['override_system']) ? $def['override_system'] : false,
                'tone'           => isset($def['tone'])          && is_string($def['tone'])          ? $def['tone']          : '',
                'params'         => [],
                'serviceId'      => null,
                'isCustom'       => false,
                'enabled'        => true,
                'sortOrder'      => isset($def['sort_order']) && is_int($def['sort_order']) ? $def['sort_order'] : 0,
            ];

            if (isset($overrides[$key])) {
                $override           = $overrides[$key];
                $row['id']          = $override->id;
                $row['prompt']      = $override->prompt;
                $row['tone']        = $override->tone;
                $row['params']      = $override->params;
                $row['serviceId']   = $override->serviceId;
                $row['enabled']     = $override->enabled;
                $row['sortOrder']   = $override->sortOrder;
            }

            $result[] = $row;
        }

        // Custom tools: append verbatim from the DB.
        foreach ($tools->all() as $dbTool) {
            if (!$dbTool->isCustom) {
                continue;
            }

            $result[] = [
                'id'             => $dbTool->id,
                'toolKey'        => $dbTool->toolKey,
                'title'          => $dbTool->title,
                'icon'           => $dbTool->icon,
                'prompt'         => $dbTool->prompt,
                'overrideSystem' => $dbTool->overrideSystem,
                'tone'           => $dbTool->tone,
                'params'         => $dbTool->params,
                'serviceId'      => $dbTool->serviceId,
                'isCustom'       => true,
                'enabled'        => $dbTool->enabled,
                'sortOrder'      => $dbTool->sortOrder,
            ];
        }

        usort(
            $result,
            static function (array $a, array $b): int {
                $cmp = $a['sortOrder'] <=> $b['sortOrder'];
                return $cmp !== 0 ? $cmp : ($a['toolKey'] <=> $b['toolKey']);
            },
        );

        return $result;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Lazy-loads and caches the parsed defaults.json.
     *
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        if ($this->defaultsCache !== null) {
            return $this->defaultsCache;
        }

        return $this->defaultsCache = $this->readJson($this->resourcePath('defaults.json'));
    }

    /**
     * Returns the absolute path to a bundled resource file.
     *
     * JSON resources are read via file_get_contents which handles both real filesystem
     * paths (development) and phar:// URIs (compiled binary) — no extraction needed.
     */
    private function resourcePath(string $filename): string
    {
        return __DIR__ . \DIRECTORY_SEPARATOR . 'resources' . \DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Reads and JSON-decodes a file, returning an assoc array.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the file cannot be read or contains invalid JSON.
     */
    private function readJson(string $path): array
    {
        $content = @file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(sprintf('Cannot read bundled AI resource: %s', $path));
        }

        $decoded = json_decode($content, true);

        /** @var array<string, mixed> $data */
        $data = is_array($decoded) ? $decoded : [];

        return $data;
    }
}
