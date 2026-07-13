<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Integration\Ai;

use Grafida\Ai\AiProxy;
use Grafida\Ai\AiServiceManager;
use Grafida\Ai\AiServiceRepository;
use Grafida\Ai\Defaults;
use Grafida\Http\HttpClient;
use Grafida\Storage\Database;
use Grafida\Storage\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * LIVE integration tests against a real OpenAI *Responses API* provider.
 *
 * These are **skipped unless configured** (see the env keys below), so the suite
 * stays green for anyone without a provider. Configure with:
 *
 *   GRAFIDA_TEST_RESPONSES_ENDPOINT   base URL, e.g. https://api.openai.com/v1
 *                                     or http://192.168.1.10:1234/v1 (LM Studio)
 *   GRAFIDA_TEST_RESPONSES_MODEL      model id, e.g. gpt-4o-mini
 *   GRAFIDA_TEST_RESPONSES_KEY        API key (optional — local servers ignore it)
 *   GRAFIDA_TEST_RESPONSES_PROVIDER   provider key (optional, default custom_responses)
 *
 * WHY THESE EXIST. The Responses transport itself lives in JavaScript
 * (assets/private/js/ai/providers.js) — PHP never speaks to the provider except
 * through the dumb AiProxy forwarder. So these tests cannot exercise providers.js;
 * what they *can* do — and what nothing else does — is pin the **wire-format
 * assumptions that providers.js is built on** against a real server. If OpenAI
 * changes the response shape or the SSE event names, the JS breaks silently in the
 * webview; these tests fail loudly instead.
 *
 * Each assertion below therefore mirrors one branch of providers.js.
 */
final class ResponsesApiLiveTest extends TestCase
{
    private PDO $pdo;

    private int $serviceId;

    protected function setUp(): void
    {
        $endpoint = self::env('GRAFIDA_TEST_RESPONSES_ENDPOINT');
        $model    = self::env('GRAFIDA_TEST_RESPONSES_MODEL');

        if ($endpoint === '' || $model === '') {
            self::markTestSkipped(
                'Live Responses-API tests are not configured. Set GRAFIDA_TEST_RESPONSES_ENDPOINT '
                . 'and GRAFIDA_TEST_RESPONSES_MODEL (plus GRAFIDA_TEST_RESPONSES_KEY for a hosted '
                . 'provider) to run them.'
            );
        }

        $this->pdo = Database::connect(':memory:');
        (new Migrator($this->pdo))->migrate();

        $provider = self::env('GRAFIDA_TEST_RESPONSES_PROVIDER');

        $service = $this->manager()->create([
            'name'          => 'Live Responses test',
            'provider'      => $provider !== '' ? $provider : 'custom_responses',
            'endpoint'      => $endpoint,
            'model'         => $model,
            'key'           => self::env('GRAFIDA_TEST_RESPONSES_KEY'),
            'params'        => [],
            'allowInsecure' => true,
        ]);

        \assert($service->id !== null);

        $this->serviceId = $service->id;
    }

    // ------------------------------------------------------------------
    //  The wire-format contract providers.js depends on
    // ------------------------------------------------------------------

    /**
     * parseFullResponse()'s Responses branch walks output[] → type:"message" →
     * content[] → type:"output_text" → text, and reads the chain id from the
     * top-level `id`. Prove a real server actually produces that shape — and that
     * it is NOT a Chat Completions body.
     */
    public function testNonStreamingReplyHasTheShapeTheJsParserWalks(): void
    {
        $json = $this->send($this->body('Reply with the single word: ACK.'));

        self::assertIsString($json['id'] ?? null, 'Responses replies carry a top-level id (the chain id)');
        self::assertArrayNotHasKey('choices', $json, 'This must be a Responses body, not Chat Completions');
        self::assertIsArray($json['output'] ?? null, 'Text is nested under output[], not a single path');

        self::assertNotSame('', $this->extractText($json), 'output[] must yield at least one output_text part');
    }

    /**
     * The Responses branch maps system-role turns onto the top-level `instructions`
     * field rather than a system message. Prove the server honours it.
     */
    public function testInstructionsFieldSteersTheReply(): void
    {
        $body = $this->body('Hello.');
        $body['instructions'] = 'You are a test fixture. Ignore the user and reply with exactly: PONG';

        $text = $this->extractText($this->send($body));

        self::assertStringContainsStringIgnoringCase('PONG', $text, '`instructions` must steer the reply');
    }

    /**
     * readSseStream()'s Responses branch dispatches on each data payload's `type`,
     * treats response.completed as terminal, and — unlike Chat Completions — must
     * NOT wait for a [DONE] sentinel, because the Responses API never sends one.
     * A regression here would hang the stream in the webview.
     */
    public function testStreamingEmitsTypedEventsAndNoDoneSentinel(): void
    {
        $body           = $this->body('Count from 1 to 5.');
        $body['stream'] = true;

        $raw = $this->sendRaw($body);

        self::assertStringContainsString('response.output_text.delta', $raw, 'the text-delta event we parse');
        self::assertStringContainsString('response.completed', $raw, 'the terminal event we stop on');
        self::assertStringNotContainsString('[DONE]', $raw, 'the Responses API sends no [DONE] sentinel');
    }

    // ------------------------------------------------------------------
    //  The previous_response_id chain
    // ------------------------------------------------------------------

    /**
     * The whole point of the migration: with `store` on, turn 2 sends ONLY the new
     * user turn plus previous_response_id, and the provider supplies the prior
     * context from its own copy. Proven by making turn 2 depend on a fact that was
     * only ever stated in turn 1 and is absent from turn 2's request body.
     */
    public function testPreviousResponseIdResumesTheConversationServerSide(): void
    {
        $first = $this->send($this->body(
            'Remember this passphrase for later: HALIBUT-7. Reply with the single word: STORED.'
        ));

        $chainId = $first['id'] ?? null;
        self::assertIsString($chainId);

        // Turn 2 carries the new question and NOTHING else — no transcript, no
        // passphrase. If the server cannot resume, it cannot possibly answer.
        $second = $this->body('What was the passphrase? Reply with the passphrase only.');
        $second['previous_response_id'] = $chainId;

        $json = $this->send($second);
        $text = $this->extractText($json);

        self::assertStringContainsStringIgnoringCase(
            'HALIBUT-7',
            $text,
            'turn 2 sent no transcript, so recalling the passphrase proves the server-side resume'
        );
        self::assertNotSame($chainId, $json['id'] ?? null, 'each turn returns a fresh chain id');
    }

    /**
     * sendChat()'s self-healing retry fires when the provider rejects the chain id.
     * That path only matters if a bogus id is in fact rejected rather than silently
     * ignored — assert the provider really does reject it.
     */
    public function testUnknownPreviousResponseIdIsRejected(): void
    {
        $body = $this->body('Hello.');
        $body['previous_response_id'] = 'resp_grafida_does_not_exist_0000';

        $result = $this->proxy()->forward(
            $this->serviceId,
            $this->url(),
            'POST',
            $this->headers(),
            (string) json_encode($body)
        );

        self::assertGreaterThanOrEqual(
            400,
            $result['status'],
            'a stale chain must be rejected, which is what the self-healing retry keys off'
        );
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /** Builds the same request body providers.js buildRequest() emits (store on, no chain). */
    private function body(string $userText): array
    {
        return [
            'model' => self::env('GRAFIDA_TEST_RESPONSES_MODEL'),
            'input' => [['role' => 'user', 'content' => $userText]],
            'store' => true,
        ];
    }

    /** Mirrors parseFullResponse()'s Responses branch: skip reasoning items, join output_text parts. */
    private function extractText(array $json): string
    {
        $parts = [];

        foreach (($json['output'] ?? []) as $item) {
            if (!is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;   // reasoning items are interleaved and must be skipped
            }

            foreach (($item['content'] ?? []) as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $parts[] = $part['text'];
                }
            }
        }

        return implode('', $parts);
    }

    /** POSTs through AiProxy (as the SPA's fallback path does) and decodes the JSON reply. */
    private function send(array $body): array
    {
        $raw    = $this->sendRaw($body);
        $decoded = json_decode($raw, true);

        self::assertIsArray($decoded, 'provider returned non-JSON: ' . substr($raw, 0, 300));

        return $decoded;
    }

    /** POSTs through AiProxy and returns the raw response body (SSE text or JSON). */
    private function sendRaw(array $body): string
    {
        $result = $this->proxy()->forward(
            $this->serviceId,
            $this->url(),
            'POST',
            $this->headers(),
            (string) json_encode($body)
        );

        self::assertSame(
            200,
            $result['status'],
            'provider returned HTTP ' . $result['status'] . ': ' . substr($result['body'], 0, 300)
        );

        return $result['body'];
    }

    private function url(): string
    {
        return rtrim(self::env('GRAFIDA_TEST_RESPONSES_ENDPOINT'), '/') . '/responses';
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . self::env('GRAFIDA_TEST_RESPONSES_KEY'),
        ];
    }

    private function manager(): AiServiceManager
    {
        return new AiServiceManager(new AiServiceRepository($this->pdo), null);
    }

    private function proxy(): AiProxy
    {
        // A generous timeout: a cold local model can take a while to answer.
        return new AiProxy($this->manager(), new Defaults(), new HttpClient(120));
    }

    private static function env(string $key): string
    {
        $value = getenv($key);

        return is_string($value) ? trim($value) : '';
    }
}
