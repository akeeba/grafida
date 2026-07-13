<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Feature;

use Boson\Component\Http\Request;
use Boson\Component\Http\Static\StaticProviderInterface;
use Boson\Contracts\Http\RequestInterface;
use Boson\Contracts\Http\ResponseInterface;
use Grafida\Application\Kernel;
use Grafida\Storage\Database;
use Grafida\Storage\Migrator;
use Grafida\Tests\Unit\Support\ArraySecretStore;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the AI tools/prompt/proxy/resolved-config routes end-to-end through the kernel.
 */
final class AiToolsRoutingTest extends TestCase
{
    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function kernel(): Kernel
    {
        $pdo = Database::connect(':memory:');
        (new Migrator($pdo))->migrate();

        return new Kernel($this->noopStatic(), $pdo, \dirname(__DIR__, 2), null, false);
    }

    /** A kernel with a real in-memory secret store (for resolved-config key test). */
    private function kernelWithSecrets(ArraySecretStore $store): Kernel
    {
        $pdo = Database::connect(':memory:');
        (new Migrator($pdo))->migrate();

        return new Kernel($this->noopStatic(), $pdo, \dirname(__DIR__, 2), null, $store);
    }

    /** @return array{0: int, 1: mixed} */
    private function call(Kernel $kernel, string $method, string $path, ?string $body = null): array
    {
        $request  = new Request($method, 'boson://app' . $path, [], $body ?? '');
        $response = $kernel->handle($request);

        return [(int) (string) $response->status, json_decode((string) $response->body, true)];
    }

    private function noopStatic(): StaticProviderInterface
    {
        return new class implements StaticProviderInterface {
            public function findFileByRequest(RequestInterface $request): ?ResponseInterface
            {
                return null;
            }
        };
    }

    /** Creates an AI service and returns its id. */
    private function createService(
        Kernel $kernel,
        string $provider = 'openai',
        string $endpoint = 'https://api.openai.com/v1',
        string $key = 'sk-test',
    ): int {
        [, $json] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name'          => 'Test Service',
            'provider'      => $provider,
            'endpoint'      => $endpoint,
            'model'         => 'gpt-4o',
            'key'           => $key,
            'allowInsecure' => true,
        ]));

        return $json['data']['id'];
    }

    // ------------------------------------------------------------------
    //  GET /api/ai/tools
    // ------------------------------------------------------------------

    public function testListAiToolsReturnsBundledToolsAndPromptAndTones(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/ai/tools');

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertArrayHasKey('tools', $json['data']);
        self::assertArrayHasKey('systemPrompt', $json['data']);
        self::assertArrayHasKey('tones', $json['data']);
        self::assertIsArray($json['data']['tools']);
        self::assertNotEmpty($json['data']['tools']);
        self::assertIsString($json['data']['systemPrompt']);
        self::assertNotEmpty($json['data']['systemPrompt']);
        self::assertIsArray($json['data']['tones']);
        self::assertNotEmpty($json['data']['tones']);
    }

    public function testListAiToolsReturnsDefaultSystemPrompt(): void
    {
        $kernel          = $this->kernel();
        [$status, $json] = $this->call($kernel, 'GET', '/api/ai/tools');

        self::assertSame(200, $status);
        // Default system prompt must be non-empty (from defaults.json).
        self::assertNotEmpty($json['data']['systemPrompt']);
    }

    // ------------------------------------------------------------------
    //  PUT /api/ai/system-prompt
    // ------------------------------------------------------------------

    public function testSetSystemPromptStoresOverride(): void
    {
        $kernel = $this->kernel();

        [$status, $json] = $this->call($kernel, 'PUT', '/api/ai/system-prompt', json_encode([
            'prompt' => 'My custom system prompt.',
        ]));

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertSame('My custom system prompt.', $json['data']['systemPrompt']);

        // Verify the override appears in GET /api/ai/tools.
        [, $list] = $this->call($kernel, 'GET', '/api/ai/tools');
        self::assertSame('My custom system prompt.', $list['data']['systemPrompt']);
    }

    public function testSetSystemPromptEmptyRestoresDefault(): void
    {
        $kernel = $this->kernel();

        // Set an override first.
        $this->call($kernel, 'PUT', '/api/ai/system-prompt', json_encode([
            'prompt' => 'Override.',
        ]));

        // Clear it with an empty prompt.
        [$status, $json] = $this->call($kernel, 'PUT', '/api/ai/system-prompt', json_encode([
            'prompt' => '',
        ]));

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);

        // GET should now return the bundled default, not 'Override.'.
        [, $list] = $this->call($kernel, 'GET', '/api/ai/tools');
        self::assertNotSame('Override.', $list['data']['systemPrompt']);
        self::assertNotEmpty($list['data']['systemPrompt']);
    }

    // ------------------------------------------------------------------
    //  PATCH /api/ai/tools/{key}
    // ------------------------------------------------------------------

    public function testPatchAiToolOverridesPromptAndTone(): void
    {
        $kernel = $this->kernel();

        [$status, $json] = $this->call($kernel, 'PATCH', '/api/ai/tools/proofread', json_encode([
            'prompt'    => 'My proofread prompt.',
            'tone'      => 'minimalist_crisp',
            'enabled'   => true,
            'sortOrder' => 25,
        ]));

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertSame('proofread', $json['data']['toolKey']);
        self::assertSame('My proofread prompt.', $json['data']['prompt']);
        self::assertSame('minimalist_crisp', $json['data']['tone']);
        self::assertSame(25, $json['data']['sortOrder']);
        self::assertFalse($json['data']['isCustom']);

        // Verify it appears in the list.
        [, $list] = $this->call($kernel, 'GET', '/api/ai/tools');
        $byKey = [];

        foreach ($list['data']['tools'] as $t) {
            $byKey[$t['toolKey']] = $t;
        }

        self::assertArrayHasKey('proofread', $byKey);
        self::assertSame('My proofread prompt.', $byKey['proofread']['prompt']);
    }

    public function testPatchAiToolCanDisableBuiltIn(): void
    {
        $kernel = $this->kernel();

        [$status, $json] = $this->call($kernel, 'PATCH', '/api/ai/tools/gen', json_encode([
            'enabled' => false,
        ]));

        self::assertSame(200, $status);
        self::assertFalse($json['data']['enabled']);

        // The disabled tool should still appear in GET /api/ai/tools (disabled tools are included
        // in the full list so admins can re-enable them).
        [, $list] = $this->call($kernel, 'GET', '/api/ai/tools');
        $byKey = [];

        foreach ($list['data']['tools'] as $t) {
            $byKey[$t['toolKey']] = $t;
        }

        self::assertArrayHasKey('gen', $byKey);
        self::assertFalse($byKey['gen']['enabled']);
    }

    public function testPatchAiToolInvalidKeyIs405ForWrongMethod(): void
    {
        // PUT /api/ai/tools/somekey should be 405 (only PATCH/DELETE).
        [$status, $json] = $this->call($this->kernel(), 'PUT', '/api/ai/tools/proofread', json_encode([
            'prompt' => 'X',
        ]));

        self::assertSame(405, $status);
        self::assertFalse($json['ok']);
    }

    // ------------------------------------------------------------------
    //  POST /api/ai/tools (custom tool create)
    // ------------------------------------------------------------------

    public function testCreateCustomTool(): void
    {
        $kernel = $this->kernel();

        [$status, $json] = $this->call($kernel, 'POST', '/api/ai/tools', json_encode([
            'toolKey'   => 'my_custom',
            'title'     => 'My Custom Tool',
            'icon'      => 'fa-star',
            'prompt'    => 'Do something.',
            'tone'      => 'none',
            'enabled'   => true,
            'sortOrder' => 99,
        ]));

        self::assertSame(201, $status);
        self::assertTrue($json['ok']);
        self::assertSame('my_custom', $json['data']['toolKey']);
        self::assertSame('My Custom Tool', $json['data']['title']);
        self::assertTrue($json['data']['isCustom']);
        self::assertIsInt($json['data']['id']);

        // Appears in the full list.
        [, $list] = $this->call($kernel, 'GET', '/api/ai/tools');
        $keys = array_column($list['data']['tools'], 'toolKey');
        self::assertContains('my_custom', $keys);
    }

    public function testCreateCustomToolRequiresUniqueKey(): void
    {
        $kernel = $this->kernel();

        $this->call($kernel, 'POST', '/api/ai/tools', json_encode([
            'toolKey' => 'dup_tool',
            'title'   => 'First',
            'prompt'  => 'p',
        ]));

        [$status, $json] = $this->call($kernel, 'POST', '/api/ai/tools', json_encode([
            'toolKey' => 'dup_tool',
            'title'   => 'Second',
            'prompt'  => 'p',
        ]));

        self::assertSame(409, $status);
        self::assertFalse($json['ok']);
    }

    public function testCreateCustomToolRequiresToolKey(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'POST', '/api/ai/tools', json_encode([
            'title'  => 'No key',
            'prompt' => 'p',
        ]));

        self::assertSame(400, $status);
        self::assertFalse($json['ok']);
    }

    // ------------------------------------------------------------------
    //  DELETE /api/ai/tools/{key}
    // ------------------------------------------------------------------

    public function testDeleteCustomTool(): void
    {
        $kernel = $this->kernel();

        $this->call($kernel, 'POST', '/api/ai/tools', json_encode([
            'toolKey' => 'to_delete',
            'title'   => 'To Delete',
            'prompt'  => 'bye',
        ]));

        [$status, $json] = $this->call($kernel, 'DELETE', '/api/ai/tools/to_delete');

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);

        // Gone from the list.
        [, $list] = $this->call($kernel, 'GET', '/api/ai/tools');
        $keys = array_column($list['data']['tools'], 'toolKey');
        self::assertNotContains('to_delete', $keys);
    }

    public function testDeleteUnknownToolIs404(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'DELETE', '/api/ai/tools/does_not_exist');

        self::assertSame(404, $status);
        self::assertFalse($json['ok']);
    }

    public function testToolOverrideRoundTrip(): void
    {
        $kernel = $this->kernel();

        // Override sortOrder of a built-in tool.
        $this->call($kernel, 'PATCH', '/api/ai/tools/concise', json_encode([
            'prompt'    => 'Override prompt.',
            'sortOrder' => 1,
        ]));

        // Now override another built-in.
        $this->call($kernel, 'PATCH', '/api/ai/tools/proofread', json_encode([
            'prompt'    => 'Proofread override.',
            'sortOrder' => 2,
        ]));

        [, $list] = $this->call($kernel, 'GET', '/api/ai/tools');

        $byKey = [];

        foreach ($list['data']['tools'] as $t) {
            $byKey[$t['toolKey']] = $t;
        }

        self::assertSame('Override prompt.',   $byKey['concise']['prompt']);
        self::assertSame('Proofread override.', $byKey['proofread']['prompt']);
    }

    // ------------------------------------------------------------------
    //  GET /api/ai/services/{id}/resolved
    // ------------------------------------------------------------------

    public function testResolvedConfigReturnsKeyAndDialect(): void
    {
        $store  = new ArraySecretStore();
        $kernel = $this->kernelWithSecrets($store);
        $id     = $this->createService($kernel, 'openai', 'https://api.openai.com/v1', 'sk-resolved-key');

        [$status, $json] = $this->call($kernel, 'GET', '/api/ai/services/' . $id . '/resolved');

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);

        $data = $json['data'];
        self::assertSame('https://api.openai.com/v1', $data['endpoint']);
        self::assertSame('/responses', $data['chatPath']);
        self::assertSame('openai_responses', $data['sseDialect']);
        self::assertSame('gpt-4o', $data['model']);
        self::assertSame('Authorization', $data['authHeader']);
        self::assertSame('sk-resolved-key', $data['apiKey']);
        self::assertIsArray($data['params']);
    }

    public function testResolvedConfigResolvesScalewayToChatCompletions(): void
    {
        $store  = new ArraySecretStore();
        $kernel = $this->kernelWithSecrets($store);
        $id     = $this->createService($kernel, 'scaleway', 'https://api.scaleway.ai/v1', 'sk-scaleway-key');

        [$status, $json] = $this->call($kernel, 'GET', '/api/ai/services/' . $id . '/resolved');

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertSame('/chat/completions', $json['data']['chatPath']);
        self::assertSame('openai_completions', $json['data']['sseDialect']);
    }

    public function testResolvedConfigUsesXApiKeyForAnthropic(): void
    {
        $store  = new ArraySecretStore();
        $kernel = $this->kernelWithSecrets($store);
        $id     = $this->createService($kernel, 'anthropic', 'https://api.anthropic.com/v1', 'sk-ant-key');

        [, $json] = $this->call($kernel, 'GET', '/api/ai/services/' . $id . '/resolved');

        self::assertSame('X-Api-Key', $json['data']['authHeader']);
        self::assertSame('anthropic', $json['data']['sseDialect']);
    }

    public function testResolvedConfigMergesToolParams(): void
    {
        $kernel = $this->kernel();

        // Create a service with base params.
        [, $created] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name'          => 'Parameterised',
            'provider'      => 'openai',
            'endpoint'      => 'https://api.openai.com/v1',
            'model'         => 'gpt-4o',
            'params'        => ['temperature' => 0.7, 'max_tokens' => 1000],
            'allowInsecure' => true,
        ]));

        $serviceId = $created['data']['id'];

        // Create a tool override with specific params.
        $this->call($kernel, 'PATCH', '/api/ai/tools/gen', json_encode([
            'prompt'    => 'Custom gen.',
            'params'    => ['temperature' => 0.3, 'top_p' => 0.9],
            'serviceId' => $serviceId,
        ]));

        // Resolved config with tool=gen should overlay tool params over service params.
        [, $resolved] = $this->call($kernel, 'GET', '/api/ai/services/' . $serviceId . '/resolved?tool=gen');

        $params = $resolved['data']['params'];
        // Tool param overrides service param.
        self::assertSame(0.3, $params['temperature']);
        // Tool param adds new key.
        self::assertSame(0.9, $params['top_p']);
        // Service-only param survives.
        self::assertSame(1000, $params['max_tokens']);
    }

    public function testResolvedConfigUnknownServiceIs404(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/ai/services/9999/resolved');

        self::assertSame(404, $status);
        self::assertFalse($json['ok']);
    }

    // ------------------------------------------------------------------
    //  POST /api/ai/proxy
    // ------------------------------------------------------------------

    public function testAiProxyRejectsMissingServiceId(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'POST', '/api/ai/proxy', json_encode([
            'url' => 'https://api.openai.com/v1/chat/completions',
        ]));

        self::assertSame(400, $status);
        self::assertFalse($json['ok']);
    }

    public function testAiProxyRejectsMissingUrl(): void
    {
        $kernel = $this->kernel();
        $id     = $this->createService($kernel);

        [$status, $json] = $this->call($kernel, 'POST', '/api/ai/proxy', json_encode([
            'serviceId' => $id,
        ]));

        self::assertSame(400, $status);
        self::assertFalse($json['ok']);
    }

    public function testAiProxyRejectsDisallowedHost(): void
    {
        $kernel = $this->kernel();
        $id     = $this->createService($kernel, 'openai', 'https://api.openai.com/v1');

        // Target is a different domain from the service endpoint.
        [$status, $json] = $this->call($kernel, 'POST', '/api/ai/proxy', json_encode([
            'serviceId' => $id,
            'url'       => 'https://evil.example.com/steal',
            'method'    => 'POST',
            'headers'   => [],
            'body'      => '',
        ]));

        self::assertSame(403, $status);
        self::assertFalse($json['ok']);
    }

    public function testAiProxyRejectsUnknownServiceId(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'POST', '/api/ai/proxy', json_encode([
            'serviceId' => 9999,
            'url'       => 'https://api.openai.com/v1/chat/completions',
            'method'    => 'POST',
            'headers'   => [],
            'body'      => '',
        ]));

        self::assertSame(404, $status);
        self::assertFalse($json['ok']);
    }

    // ------------------------------------------------------------------
    //  Bootstrap: aiTools payload
    // ------------------------------------------------------------------

    public function testBootstrapIncludesAiTools(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/bootstrap');

        self::assertSame(200, $status);
        self::assertArrayHasKey('aiTools', $json['data']);
        self::assertIsArray($json['data']['aiTools']);
        // Bootstrap returns only enabled tools.
        self::assertNotEmpty($json['data']['aiTools']);
    }

    public function testBootstrapAiToolsOnlyIncludesEnabledTools(): void
    {
        $kernel = $this->kernel();

        // Disable the 'gen' tool.
        $this->call($kernel, 'PATCH', '/api/ai/tools/gen', json_encode([
            'enabled' => false,
        ]));

        [, $boot] = $this->call($kernel, 'GET', '/api/bootstrap');

        $keys = array_column($boot['data']['aiTools'], 'toolKey');
        self::assertNotContains('gen', $keys, 'Disabled tool must not appear in bootstrap aiTools.');
    }

    public function testBootstrapAiToolsAreSorted(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/bootstrap');

        self::assertSame(200, $status);
        $tools  = $json['data']['aiTools'];
        $orders = array_column($tools, 'sortOrder');
        $sorted = $orders;
        sort($sorted);

        self::assertSame($sorted, $orders, 'Bootstrap aiTools must be sorted by sortOrder ASC.');
    }
}
