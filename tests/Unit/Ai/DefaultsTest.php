<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Ai;

use Grafida\Ai\AiTool;
use Grafida\Ai\AiToolRepository;
use Grafida\Ai\Defaults;
use Grafida\Storage\Database;
use Grafida\Storage\Migrator;
use Grafida\Tests\Unit\TestCase;
use PDO;

/**
 * Verifies that Defaults loads bundled JSON correctly and that effectiveTools()
 * properly merges DB overrides and custom tools.
 */
final class DefaultsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connect(':memory:');
        (new Migrator($this->pdo))->migrate();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function defaults(): Defaults
    {
        return new Defaults();
    }

    private function toolRepo(): AiToolRepository
    {
        return new AiToolRepository($this->pdo);
    }

    // -----------------------------------------------------------------------
    // systemPrompt / tones
    // -----------------------------------------------------------------------

    public function testSystemPromptIsNonEmpty(): void
    {
        $prompt = $this->defaults()->systemPrompt();

        self::assertNotEmpty($prompt, 'systemPrompt() must return the bundled system prompt string');
        self::assertStringContainsString('copy editor', $prompt, 'system prompt must mention "copy editor"');
    }

    public function testTonesContainsExpectedKeys(): void
    {
        $tones = $this->defaults()->tones();

        self::assertIsArray($tones);
        self::assertArrayHasKey('none', $tones);
        self::assertArrayHasKey('unchanged', $tones);
        self::assertArrayHasKey('straightforward_pragmatic', $tones);
        self::assertArrayHasKey('friendly_inclusive', $tones);
        self::assertArrayHasKey('sophisticated_refined', $tones);
        self::assertArrayHasKey('minimalist_crisp', $tones);
    }

    public function testEachToneHasLabelAndPrompt(): void
    {
        foreach ($this->defaults()->tones() as $key => $tone) {
            self::assertArrayHasKey('label', $tone, "Tone '$key' must have a 'label' key");
            self::assertArrayHasKey('prompt', $tone, "Tone '$key' must have a 'prompt' key");
            self::assertIsString($tone['label'],  "Tone '$key' label must be a string");
            self::assertIsString($tone['prompt'], "Tone '$key' prompt must be a string");
        }
    }

    // -----------------------------------------------------------------------
    // providers()
    // -----------------------------------------------------------------------

    public function testProvidersContainsAllExpectedKeys(): void
    {
        $providers = $this->defaults()->providers();

        foreach (['openai', 'anthropic', 'cohere', 'deepseek', 'google', 'groq', 'mistral', 'openrouter', 'perplexity', 'scaleway', 'github', 'custom', 'custom_responses'] as $key) {
            self::assertArrayHasKey($key, $providers, "providers() must contain provider key '$key'");
        }

        self::assertCount(13, $providers);
    }

    public function testOpenAIProviderUsesResponsesApi(): void
    {
        $p = $this->defaults()->providers()['openai'];

        self::assertSame('https://api.openai.com/v1', $p['endpoint']);
        self::assertSame('bearer',            $p['auth']);
        self::assertSame('/responses',        $p['chat_path']);
        self::assertSame('/models',           $p['models_path']);
        self::assertSame('openai_responses',  $p['sse_dialect']);
    }

    public function testNoProviderUsesTheBareOpenaiDialect(): void
    {
        foreach ($this->defaults()->providers() as $key => $p) {
            self::assertContains(
                $p['sse_dialect'],
                ['openai_completions', 'openai_responses', 'anthropic'],
                "provider '$key' must use one of the three known sse_dialect values"
            );
        }
    }

    public function testScalewayIsUnaffectedByTheDialectRename(): void
    {
        $p = $this->defaults()->providers()['scaleway'];

        self::assertSame('openai_completions',  $p['sse_dialect']);
        self::assertSame('/chat/completions',   $p['chat_path']);
    }

    public function testCustomResponsesProviderUsesResponsesApiWithEmptyEndpoint(): void
    {
        $p = $this->defaults()->providers()['custom_responses'];

        self::assertSame('',                  $p['endpoint']);
        self::assertSame('/responses',        $p['chat_path']);
        self::assertSame('openai_responses',  $p['sse_dialect']);
    }

    public function testAnthropicProviderUsesXApiKeyAndAnthropicDialect(): void
    {
        $p = $this->defaults()->providers()['anthropic'];

        self::assertSame('https://api.anthropic.com/v1', $p['endpoint']);
        self::assertSame('x-api-key',  $p['auth']);
        self::assertSame('/messages',  $p['chat_path']);
        self::assertSame('/models',    $p['models_path']);
        self::assertSame('anthropic',  $p['sse_dialect']);
    }

    public function testGitHubProviderHasNonStandardChatAndModelsPath(): void
    {
        $p = $this->defaults()->providers()['github'];

        self::assertSame('https://models.github.ai',       $p['endpoint']);
        self::assertSame('/inference/chat/completions',    $p['chat_path']);
        self::assertSame('/catalog/models',                $p['models_path']);
        self::assertSame('openai_completions',              $p['sse_dialect']);
    }

    public function testCohereHasNullModelsPath(): void
    {
        self::assertNull($this->defaults()->providers()['cohere']['models_path']);
    }

    public function testPerplexityHasNullModelsPath(): void
    {
        self::assertNull($this->defaults()->providers()['perplexity']['models_path']);
    }

    // -----------------------------------------------------------------------
    // effectiveTools() — no DB records
    // -----------------------------------------------------------------------

    public function testEffectiveToolsReturnsBundledDefaults(): void
    {
        $tools = $this->defaults()->effectiveTools($this->toolRepo());

        self::assertNotEmpty($tools, 'effectiveTools() must return at least the bundled tools');

        $keys = array_column($tools, 'toolKey');
        self::assertContains('gen',          $keys);
        self::assertContains('proofread',    $keys);
        self::assertContains('friendly',     $keys);
        self::assertContains('professional', $keys);
        self::assertContains('concise',      $keys);
    }

    public function testBundledToolsHaveExpectedIcons(): void
    {
        $byKey = [];

        foreach ($this->defaults()->effectiveTools($this->toolRepo()) as $t) {
            $byKey[$t['toolKey']] = $t;
        }

        self::assertSame('fa-laptop-code',                         $byKey['gen']['icon']);
        self::assertSame('fa-check',                               $byKey['proofread']['icon']);
        self::assertSame('fa-face-grin',                           $byKey['friendly']['icon']);
        self::assertSame('fa-user-tie',                            $byKey['professional']['icon']);
        self::assertSame('fa-down-left-and-up-right-to-center',    $byKey['concise']['icon']);
    }

    public function testBundledToolsAreSortedBySortOrder(): void
    {
        $tools  = $this->defaults()->effectiveTools($this->toolRepo());
        $orders = array_column($tools, 'sortOrder');
        $sorted = $orders;
        sort($sorted);

        self::assertSame($sorted, $orders, 'effectiveTools() must return tools sorted by sort_order ASC');
    }

    public function testBundledToolsHaveNullIdAndNullServiceId(): void
    {
        foreach ($this->defaults()->effectiveTools($this->toolRepo()) as $tool) {
            if ($tool['isCustom']) {
                continue;
            }

            self::assertNull($tool['id'],        "Bundled tool '{$tool['toolKey']}' must have null id");
            self::assertNull($tool['serviceId'], "Bundled tool '{$tool['toolKey']}' must have null serviceId");
        }
    }

    // -----------------------------------------------------------------------
    // effectiveTools() — DB override beats bundled prompt
    // -----------------------------------------------------------------------

    public function testDbOverrideReplacesPromptForBuiltInTool(): void
    {
        $repo = $this->toolRepo();
        $repo->upsert(new AiTool(
            id: null,
            toolKey: 'proofread',
            title: 'Proofread Override',
            icon: 'fa-check',
            prompt: 'My custom proofread prompt.',
            overrideSystem: false,
            tone: 'concise',
            params: ['temperature' => 0.5],
            serviceId: null,
            isCustom: false,
            enabled: true,
            sortOrder: 20,
        ));

        $tools = $this->defaults()->effectiveTools($repo);
        $byKey = [];

        foreach ($tools as $t) {
            $byKey[$t['toolKey']] = $t;
        }

        self::assertArrayHasKey('proofread', $byKey);
        self::assertSame('My custom proofread prompt.', $byKey['proofread']['prompt'],
            'DB override must replace the bundled prompt');
        self::assertSame('concise', $byKey['proofread']['tone'],
            'DB override must replace the bundled tone');
        self::assertSame(['temperature' => 0.5], $byKey['proofread']['params'],
            'DB override must replace the bundled params');
        // Title and icon stay as bundled values (not in the override set)
        self::assertSame('fa-check',  $byKey['proofread']['icon']);
        // The DB record id should be present
        self::assertNotNull($byKey['proofread']['id'], 'effectiveTools must carry the DB id for an overridden tool');
    }

    public function testDbOverrideCanDisableBuiltInTool(): void
    {
        $repo = $this->toolRepo();
        $repo->upsert(new AiTool(
            id: null,
            toolKey: 'gen',
            title: 'Generate',
            icon: 'fa-laptop-code',
            prompt: 'Original prompt.',
            overrideSystem: false,
            tone: '',
            params: [],
            serviceId: null,
            isCustom: false,
            enabled: false,
            sortOrder: 10,
        ));

        $byKey = [];

        foreach ($this->defaults()->effectiveTools($repo) as $t) {
            $byKey[$t['toolKey']] = $t;
        }

        self::assertFalse($byKey['gen']['enabled'], 'DB override must be able to disable a built-in tool');
    }

    // -----------------------------------------------------------------------
    // effectiveTools() — custom tool appears
    // -----------------------------------------------------------------------

    public function testCustomToolAppearsInEffectiveTools(): void
    {
        $repo = $this->toolRepo();
        $repo->upsert(new AiTool(
            id: null,
            toolKey: 'my_custom_tool',
            title: 'My Custom Tool',
            icon: 'fa-star',
            prompt: 'Do something custom.',
            overrideSystem: true,
            tone: 'informal_chatty',
            params: [],
            serviceId: null,
            isCustom: true,
            enabled: true,
            sortOrder: 100,
        ));

        $tools = $this->defaults()->effectiveTools($repo);
        $keys  = array_column($tools, 'toolKey');

        self::assertContains('my_custom_tool', $keys, 'custom tool must appear in effectiveTools()');

        $byKey = [];

        foreach ($tools as $t) {
            $byKey[$t['toolKey']] = $t;
        }

        self::assertTrue($byKey['my_custom_tool']['isCustom']);
        self::assertSame('My Custom Tool',   $byKey['my_custom_tool']['title']);
        self::assertSame('Do something custom.', $byKey['my_custom_tool']['prompt']);
        self::assertSame(100,                $byKey['my_custom_tool']['sortOrder']);
    }

    public function testCustomToolSortOrderIsRespected(): void
    {
        $repo = $this->toolRepo();
        $repo->upsert(new AiTool(
            id: null,
            toolKey: 'zzz_custom',
            title: 'ZZZ Custom',
            icon: 'fa-star',
            prompt: 'Custom.',
            overrideSystem: false,
            tone: '',
            params: [],
            serviceId: null,
            isCustom: true,
            enabled: true,
            sortOrder: 5,  // Lower than any bundled tool (10+), so this must sort first.
        ));

        $tools    = $this->defaults()->effectiveTools($repo);
        $firstKey = $tools[0]['toolKey'];

        self::assertSame('zzz_custom', $firstKey,
            'A custom tool with sort_order=5 must appear before bundled tools with sort_order >= 10');
    }
}
