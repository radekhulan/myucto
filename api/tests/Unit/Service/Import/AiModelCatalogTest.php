<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\AiModelCatalog;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\GeminiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AiModelCatalogTest extends TestCase
{
    private array $history = [];

    public function testClaudeFollowsLastIdAndFiltersPdfModels(): void
    {
        $client = $this->anthropic([
            self::response(['data' => [
                ['id' => 'claude-fable-5-2', 'display_name' => 'Claude Fable 5.2', 'created_at' => '2026-11-12T00:00:00Z'],
                ['id' => 'claude-opus-4-20250514'],
                ['id' => 'claude-3-5-haiku-20241022'],
            ], 'has_more' => true, 'last_id' => 'claude-3-5-haiku-20241022']),
            self::response(['data' => [
                ['id' => 'claude-haiku-5-5'],
                ['id' => 'claude-fable-5-2'],
                ['id' => 'claude-sonnet-5-5-20261111'],
                ['id' => 'claude-sonnet-5-5-audio'],
                ['id' => 123],
            ], 'has_more' => false]),
        ]);

        self::assertSame([
            'models' => ['claude-haiku-5-5', 'claude-fable-5-2', 'claude-sonnet-5-5-20261111'], 'error' => null,
        ], $client->listModels('sk-ant-test-fixture', 'wrkspc_test'));
        self::assertCount(2, $this->history);
        foreach ($this->history as $exchange) {
            $request = $exchange['request'];
            self::assertSame('GET', $request->getMethod());
            self::assertSame('api.anthropic.com', $request->getUri()->getHost());
            self::assertSame('/v1/models', $request->getUri()->getPath());
            self::assertSame('sk-ant-test-fixture', $request->getHeaderLine('x-api-key'));
            self::assertSame('2023-06-01', $request->getHeaderLine('anthropic-version'));
            self::assertSame('wrkspc_test', $request->getHeaderLine('anthropic-workspace-id'));
        }
        self::assertSame('limit=1000', $this->history[0]['request']->getUri()->getQuery());
        self::assertSame('limit=1000&after_id=claude-3-5-haiku-20241022', $this->history[1]['request']->getUri()->getQuery());
    }

    public function testGeminiUsesAHeaderAndFollowsPageTokenWithMethodAndModalityFilters(): void
    {
        $client = $this->gemini([
            self::response(['models' => [
                self::geminiModel('gemini-4-hydrogen'),
                self::geminiModel('gemini-3.8-flash', ['countTokens']),
                self::geminiModel('gemini-3.5-flash'),
                self::geminiModel('gemini-3.7-flash'),
                self::geminiModel('gemini-4-neon-image'),
                self::geminiModel('text-embedding-004'),
            ], 'nextPageToken' => 'cursor+/=']),
            self::response(['models' => [
                self::geminiModel('gemini-3.8-flash'),
                self::geminiModel('gemini-4-hydrogen', ['countTokens', 'generateContent']),
                self::geminiModel('gemini-2.5-pro'),
                self::geminiModel('gemini-2.5-flash-lite'),
                self::geminiModel('gemini-3.5-flash-lite'),
                ['name' => 'gemini-4-carbon', 'supportedGenerationMethods' => ['generateContent']],
            ]]),
        ]);

        self::assertSame([
            'models' => ['gemini-3.5-flash-lite', 'gemini-3.8-flash', 'gemini-4-hydrogen', 'gemini-2.5-flash-lite'], 'error' => null,
        ], $client->listModels('gemini-fixture-key-only-in-header'));
        self::assertCount(2, $this->history);
        foreach ($this->history as $exchange) {
            $request = $exchange['request'];
            self::assertSame('GET', $request->getMethod());
            self::assertSame('generativelanguage.googleapis.com', $request->getUri()->getHost());
            self::assertSame('/v1beta/models', $request->getUri()->getPath());
            self::assertSame('gemini-fixture-key-only-in-header', $request->getHeaderLine('x-goog-api-key'));
        }
        self::assertSame('pageSize=1000', $this->history[0]['request']->getUri()->getQuery());
        self::assertSame('pageSize=1000&pageToken=cursor%2B%2F%3D', $this->history[1]['request']->getUri()->getQuery());
    }

    public function testClaudeWithoutNextCursorReturnsPartialCatalog(): void
    {
        $client = $this->anthropic([self::response(['data' => [['id' => 'claude-haiku-5-5']], 'has_more' => true])]);
        self::assertSame(['models' => ['claude-haiku-5-5'], 'error' => AiModelCatalog::INCOMPLETE], $client->listModels('sk-ant-test-fixture'));
        self::assertCount(1, $this->history);
    }

    public function testGeminiWithRepeatedCursorReturnsPartialCatalog(): void
    {
        $page = self::response(['models' => [self::geminiModel('gemini-4-carbon')], 'nextPageToken' => 'repeated']);
        $client = $this->gemini([$page, clone $page]);
        self::assertSame(['models' => ['gemini-4-carbon'], 'error' => AiModelCatalog::INCOMPLETE], $client->listModels('fixture-key'));
        self::assertCount(2, $this->history);
    }

    public function testClaudeTreatsFalsyHasMoreAsTheLastPage(): void
    {
        $client = $this->anthropic([self::response(['data' => [['id' => 'claude-haiku-5-5']], 'has_more' => 0])]);
        self::assertSame(['models' => ['claude-haiku-5-5'], 'error' => null], $client->listModels('sk-ant-test-fixture'));
        self::assertCount(1, $this->history);
    }

    public function testBothProvidersKeepCollectedModelsAtThePageLimit(): void
    {
        $claudePages = [];
        $geminiPages = [];
        for ($i = 1; $i <= AiModelCatalog::MAX_PAGES; $i++) {
            $claudePages[] = self::response(['data' => [['id' => 'claude-haiku-5-5']], 'has_more' => 1, 'last_id' => 'cursor-' . $i]);
            $geminiPages[] = self::response(['models' => [self::geminiModel('gemini-4-carbon')], 'nextPageToken' => 'cursor-' . $i]);
        }
        self::assertSame(['models' => ['claude-haiku-5-5'], 'error' => AiModelCatalog::INCOMPLETE], $this->anthropic($claudePages)->listModels('sk-ant-test-fixture'));
        self::assertSame(['models' => ['gemini-4-carbon'], 'error' => AiModelCatalog::INCOMPLETE], $this->gemini($geminiPages)->listModels('fixture-key'));
        self::assertCount(2 * AiModelCatalog::MAX_PAGES, $this->history);
    }

    public function testNextPageUsesOnlyTheTimeRemainingBeforeTheDeadline(): void
    {
        $http = $this->http([self::response(['data' => []])]);
        $url = 'https://api.anthropic.com/v1/models';
        $deadline = hrtime(true) / 1e9;
        self::assertSame(['body' => null, 'error' => AiModelCatalog::UNAVAILABLE], AiModelCatalog::fetchPage($http, $url, [], deadline: $deadline));
        self::assertCount(0, $this->history);

        self::assertNull(AiModelCatalog::fetchPage($http, $url, [], deadline: $deadline + 4)['error']);
        $options = $this->history[0]['options'];
        self::assertGreaterThan(0, $options['timeout']);
        self::assertLessThanOrEqual(4, $options['timeout']);
        self::assertLessThanOrEqual($options['timeout'], $options['connect_timeout']);
    }

    public function testGeminiPreservesEarlierPagesWhenAFollowingPageFails(): void
    {
        $page = self::response(['models' => [self::geminiModel('gemini-4-carbon')], 'nextPageToken' => 'continue']);
        $client = $this->gemini([$page, new Response(503)]);
        self::assertSame(['models' => ['gemini-4-carbon'], 'error' => AiModelCatalog::INCOMPLETE], $client->listModels('fixture-key'));
        self::assertCount(2, $this->history);
    }

    public function testCatalogDistinguishesInvalidKeysEmptyResultsAndNetworkFailures(): void
    {
        $gemini = $this->gemini([
            self::response(['error' => ['details' => [['reason' => 'API_KEY_INVALID']], 'message' => 'fixture-key']], 400),
            new Response(200, [], '{}'),
        ]);
        self::assertSame(['models' => [], 'error' => AiModelCatalog::INVALID_KEY], $gemini->listModels('fixture-key'));
        self::assertSame(['models' => [], 'error' => AiModelCatalog::EMPTY], $gemini->listModels('fixture-key'));

        $claude = $this->anthropic([
            new Response(401, [], 'sk-ant-test-fixture'),
            new ConnectException('Network failure', new Request('GET', 'https://api.anthropic.com/v1/models')),
        ]);
        self::assertSame(['models' => [], 'error' => AiModelCatalog::INVALID_KEY], $claude->listModels('sk-ant-test-fixture'));
        self::assertSame(['models' => [], 'error' => AiModelCatalog::UNAVAILABLE], $claude->listModels('sk-ant-test-fixture'));
    }

    private function anthropic(array $responses): AnthropicClient
    {
        return new AnthropicClient($this->createStub(Connection::class), $this->createStub(SecretEncryption::class), new NullLogger(), $this->http($responses));
    }

    private function gemini(array $responses): GeminiClient
    {
        return new GeminiClient($this->createStub(Connection::class), $this->createStub(SecretEncryption::class), new NullLogger(), $this->http($responses));
    }

    private function http(array $responses): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new Client(['handler' => $stack]);
    }

    private static function response(array $body, int $status = 200): Response
    {
        return new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private static function geminiModel(string $id, array $methods = ['generateContent']): array
    {
        return ['name' => 'models/' . $id, 'supportedGenerationMethods' => $methods, 'displayName' => $id];
    }
}
