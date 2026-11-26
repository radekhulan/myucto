<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ai;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ai\AiDpaGate;
use MyInvoice\Service\Ai\AiProviderHttpClient;
use MyInvoice\Service\Ai\LlmClassifierRouter;
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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * §12b — auto-escalace bank_tx kontace Haiku→Sonnet. Ověřuje, že při nepoužitelné
 * (nízkojisté) odpovědi Haiku se právě JEDNOU zopakuje dotaz na claude-sonnet-5-5 a
 * použije se jeho odpověď; a naopak že při použitelné odpovědi Haiku se neescaluje.
 */
#[AllowMockObjectsWithoutExpectations]
final class LlmClassifierRouterEscalationTest extends TestCase
{
    /** @param list<array<string,mixed>> $requests */
    private function router(MockHandler $mock, array &$requests): LlmClassifierRouter
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($requests));
        $http = new Client(['handler' => $stack, 'http_errors' => false]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $s = $this->createMock(PDOStatement::class);
            $s->method('execute')->willReturn(true);
            if (str_contains($sql, 'chart_of_accounts')) {
                $s->method('fetchAll')->willReturn([
                    ['account_code' => '221000', 'name' => 'Běžný účet', 'account_type' => 'asset'],
                    ['account_code' => '518000', 'name' => 'Ostatní služby', 'account_type' => 'expense'],
                ]);
            } elseif (str_contains($sql, 'ai_dpa_confirmations')) {
                $s->method('fetchColumn')->willReturn('{"anthropic":{"confirmed_at":"2026-01-01T00:00:00Z"}}');
            } elseif (str_contains($sql, 'anthropic_api_key_enc')) {
                $s->method('fetch')->willReturn(['anthropic_api_key_enc' => 'ENC', 'anthropic_default_model' => 'claude-haiku-4-5']);
            } elseif (str_contains($sql, 'ai_provider')) {
                $s->method('fetch')->willReturn(['ai_provider' => 'anthropic', 'ai_eu_residency_required' => 0]);
            } else {
                $s->method('fetch')->willReturn([]);
                $s->method('fetchAll')->willReturn([]);
            }
            return $s;
        });
        $conn = $this->createMock(Connection::class);
        $conn->method('pdo')->willReturn($pdo);

        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->method('decrypt')->willReturn('sk-ant-' . str_repeat('a', 40));
        $logger = new NullLogger();

        $ollama = new OllamaClient($conn, $crypto, $logger, new OllamaEndpointGuard(static fn (): array => [], ''),
            new PdfPageRasterizer($logger, false, ''), new PdfTotalExtractor(new PdfIsdocExtractor()));
        $registry = new LlmProviderRegistry(
            new AnthropicClient($conn, $crypto, $logger),
            new AzureOpenAiClient($conn, $crypto, $logger),
            new OpenAiClient($conn, $crypto, $logger),
            new GeminiClient($conn, $crypto, $logger),
            $ollama,
        );
        $client = new AiProviderHttpClient($conn, $registry, new ResidencyPolicy(), new AiDpaGate($conn, $ollama), $logger, $http);

        return new LlmClassifierRouter($conn, $client, $logger);
    }

    /** @param array<string,mixed> $input */
    private function anthropicResponse(string $model, array $input, bool $structured = false): Response
    {
        return new Response(200, [], (string) json_encode([
            'content' => [$structured ? ['type' => 'text', 'text' => json_encode($input)] : ['type' => 'tool_use', 'input' => $input]],
            'model' => $model,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
        ]));
    }

    private function modelOf(mixed $request): string
    {
        /** @var \Psr\Http\Message\RequestInterface $req */
        $req = $request['request'];
        $body = json_decode((string) $req->getBody(), true);
        return (string) ($body['model'] ?? '');
    }

    public function testEscalatesToSonnetWhenHaikuLowConfidence(): void
    {
        $mock = new MockHandler([
            $this->anthropicResponse('claude-haiku-4-5', [
                'debit_account' => '518000', 'credit_account' => '221000',
                'operation_type' => 'fee', 'confidence' => 0.1, 'reasoning' => 'haiku guess',
            ]),
            $this->anthropicResponse('claude-sonnet-5-5', [
                'debit_account' => '518000', 'credit_account' => '221000',
                'operation_type' => 'fee', 'confidence' => 0.9, 'reasoning' => 'sonnet confident',
            ], true),
        ]);
        $requests = [];
        $router = $this->router($mock, $requests);

        $result = $router->classifyBankTransaction(1, ['direction' => 'out'], []);

        self::assertCount(2, $requests, 'právě jedno zopakování (žádná smyčka)');
        self::assertSame('claude-haiku-4-5', $this->modelOf($requests[0]), 'první pokus na Haiku');
        self::assertSame('claude-sonnet-5-5', $this->modelOf($requests[1]), 'escalace na Sonnet');
        self::assertTrue($result['ok']);
        self::assertTrue($result['escalated'] ?? false);
        self::assertSame('claude-sonnet-5-5', $result['model']);
        self::assertSame(0.36, $result['confidence']);
    }

    public function testDoesNotEscalateWhenHaikuUsable(): void
    {
        $mock = new MockHandler([
            $this->anthropicResponse('claude-haiku-4-5', [
                'debit_account' => '518000', 'credit_account' => '221000',
                'operation_type' => 'fee', 'confidence' => 0.9, 'reasoning' => 'haiku confident',
            ]),
        ]);
        $requests = [];
        $router = $this->router($mock, $requests);

        $result = $router->classifyBankTransaction(1, ['direction' => 'out'], []);

        self::assertCount(1, $requests, 'žádná escalace při použitelné odpovědi Haiku');
        self::assertSame('claude-haiku-4-5', $this->modelOf($requests[0]));
        self::assertTrue($result['ok']);
        self::assertArrayNotHasKey('escalated', $result);
        self::assertSame('claude-haiku-4-5', $result['model']);
    }
}
