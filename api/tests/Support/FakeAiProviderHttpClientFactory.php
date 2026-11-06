<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ai\AiDpaGate;
use MyInvoice\Service\Ai\AiProviderHttpClient;
use MyInvoice\Service\Ai\OllamaEndpointGuard;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\AzureOpenAiClient;
use MyInvoice\Service\Import\GeminiClient;
use MyInvoice\Service\Import\LlmProviderRegistry;
use MyInvoice\Service\Import\OllamaClient;
use MyInvoice\Service\Import\OpenAiClient;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Import\PdfPageRasterizer;
use MyInvoice\Service\Import\PdfTotalExtractor;
use MyInvoice\Service\Import\ResidencyPolicy;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * AiProviderHttpClient nad mockovanou konfigurací poskytovatele a Guzzle MockHandlerem —
 * žádné skutečné volání LLM. Odeslané požadavky se zapisují do $history.
 */
final class FakeAiProviderHttpClientFactory
{
    /**
     * @param list<mixed>                     $queue   odpovědi MockHandleru
     * @param array<int, array<string,mixed>> $history
     */
    public static function create(
        TestCase $test,
        string $provider,
        array $queue,
        array &$history = [],
        string $model = 'claude-haiku-4-5',
        bool $dpaConfirmed = true,
        ?string $anthropicWorkspaceId = null,
    ): AiProviderHttpClient {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack, 'http_errors' => false]);

        $mock = (new \ReflectionClass($test))->getMethod('createStub');
        $pdo = $mock->invoke($test, PDO::class);
        $pdo->method('prepare')->willReturnCallback(static function (string $sql) use ($mock, $test, $provider, $model, $dpaConfirmed, $anthropicWorkspaceId) {
            $s = $mock->invoke($test, PDOStatement::class);
            $s->method('execute')->willReturn(true);
            if (str_contains($sql, 'ai_eu_residency_required')) {
                $s->method('fetch')->willReturn(['ai_provider' => $provider, 'ai_eu_residency_required' => 0]);
            } elseif (str_contains($sql, 'anthropic_api_key_enc')) {
                $s->method('fetch')->willReturn([
                    'anthropic_api_key_enc' => 'enc', 'anthropic_default_model' => $model, 'anthropic_workspace_id' => $anthropicWorkspaceId,
                ]);
            } elseif (str_contains($sql, 'openai_api_key_enc')) {
                $s->method('fetch')->willReturn(['openai_api_key_enc' => 'enc', 'openai_default_model' => $model, 'openai_base_url' => 'https://api.openai.test']);
            } elseif (str_contains($sql, 'gemini_api_key_enc')) {
                $s->method('fetch')->willReturn(['gemini_api_key_enc' => 'enc', 'gemini_default_model' => $model]);
            } elseif (str_contains($sql, 'ollama_base_url')) {
                $s->method('fetch')->willReturn(['ollama_base_url' => 'http://127.0.0.1:11434', 'ollama_default_model' => $model, 'ollama_api_key_enc' => null]);
            } elseif (str_contains($sql, 'ai_dpa_confirmations')) {
                $s->method('fetchColumn')->willReturn($dpaConfirmed ? json_encode([$provider => ['confirmed_at' => '2026-01-01 00:00:00']]) : '{}');
            } else {
                $s->method('fetch')->willReturn(false);
                $s->method('fetchColumn')->willReturn(false);
            }
            return $s;
        });
        $conn = $mock->invoke($test, Connection::class);
        $conn->method('pdo')->willReturn($pdo);
        $crypto = $mock->invoke($test, SecretEncryption::class);
        $crypto->method('decrypt')->willReturn('test-api-key');
        $logger = new NullLogger();
        $ollama = new OllamaClient($conn, $crypto, $logger, new OllamaEndpointGuard(null, ''),
            new PdfPageRasterizer($logger, false, ''), new PdfTotalExtractor(new PdfIsdocExtractor()), $http, true);
        $registry = new LlmProviderRegistry(
            new AnthropicClient($conn, $crypto, $logger), new AzureOpenAiClient($conn, $crypto, $logger),
            new OpenAiClient($conn, $crypto, $logger), new GeminiClient($conn, $crypto, $logger), $ollama,
        );
        return new AiProviderHttpClient($conn, $registry, new ResidencyPolicy(), new AiDpaGate($conn, $ollama), $logger, $http);
    }
}
