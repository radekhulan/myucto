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
 * Analytiky s tečkou (`221.100`, `518.990`) jsou v účtovém rozvrhu běžné a model je dostává
 * v allowed_accounts. Validace návrhu i JSON schéma odpovědi je dřív odmítaly regexem
 * `^[0-9]{3,6}$`, takže bankovní pohyb na účtu namapovaném na `221.100` končil vždy
 * `invalid_accounts` (navíc po eskalaci Haiku→Sonnet = dvě placená volání).
 */
#[AllowMockObjectsWithoutExpectations]
final class LlmClassifierRouterAnalyticAccountsTest extends TestCase
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
                    ['account_code' => '221', 'name' => 'Peněžní prostředky na účtech', 'account_type' => 'asset'],
                    ['account_code' => '221.100', 'name' => 'Běžný účet CZK', 'account_type' => 'asset'],
                    ['account_code' => '518', 'name' => 'Ostatní služby', 'account_type' => 'expense'],
                    ['account_code' => '518.990', 'name' => 'Ostatní služby — daňově neuznatelné', 'account_type' => 'expense'],
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
    private function anthropicResponse(array $input): Response
    {
        return new Response(200, [], (string) json_encode([
            'content' => [['type' => 'tool_use', 'input' => $input]],
            'model' => 'claude-haiku-4-5',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
        ]));
    }

    /** Všechny `pattern` u kódů účtů z JSON schématu, které šlo modelu v požadavku. */
    private function accountPatternsOf(mixed $request): array
    {
        /** @var \Psr\Http\Message\RequestInterface $req */
        $req = $request['request'];
        $patterns = [];
        $walk = static function (mixed $node, ?string $key = null) use (&$walk, &$patterns): void {
            if (!is_array($node)) {
                return;
            }
            if (in_array($key, ['debit_account', 'credit_account'], true) && isset($node['pattern'])) {
                $patterns[] = (string) $node['pattern'];
            }
            foreach ($node as $k => $v) {
                $walk($v, is_string($k) ? $k : null);
            }
        };
        $walk(json_decode((string) $req->getBody(), true));
        return $patterns;
    }

    public function testBankSuggestionAcceptsDottedAnalyticAccounts(): void
    {
        $mock = new MockHandler([
            $this->anthropicResponse([
                'debit_account' => '518.990', 'credit_account' => '221.100',
                'operation_type' => 'subscription', 'confidence' => 0.9, 'reasoning' => 'předplatné',
            ]),
        ]);
        $requests = [];
        $result = $this->router($mock, $requests)->classifyBankTransaction(1, ['direction' => 'out'], []);

        self::assertTrue($result['ok'] ?? false, 'analytiky z rozvrhu nesmí skončit invalid_accounts: ' . json_encode($result));
        self::assertSame('518.990', $result['debit']);
        self::assertSame('221.100', $result['credit']);
        self::assertCount(1, $requests, 'platný návrh se neeskaluje');
    }

    public function testIncomingBankSuggestionOnDottedBankAccount(): void
    {
        $mock = new MockHandler([
            $this->anthropicResponse([
                'debit_account' => '221.100', 'credit_account' => '518',
                'operation_type' => 'other', 'confidence' => 0.9, 'reasoning' => 'vratka',
            ]),
        ]);
        $requests = [];
        $result = $this->router($mock, $requests)->classifyBankTransaction(1, ['direction' => 'in'], []);

        self::assertTrue($result['ok'] ?? false, json_encode($result) ?: '');
        self::assertSame('221.100', $result['debit']);
    }

    public function testCodeOutsideChartIsStillRejected(): void
    {
        // 518.100 má platný tvar, ale v rozvrhu není → pořád invalid_accounts (i po eskalaci).
        $bad = [
            'debit_account' => '518.100', 'credit_account' => '221.100',
            'operation_type' => 'subscription', 'confidence' => 0.9, 'reasoning' => 'mimo rozvrh',
        ];
        $mock = new MockHandler([$this->anthropicResponse($bad), $this->anthropicResponse($bad)]);
        $requests = [];
        $result = $this->router($mock, $requests)->classifyBankTransaction(1, ['direction' => 'out'], []);

        self::assertFalse($result['ok'] ?? true);
        self::assertSame('invalid_accounts', $result['error'] ?? null);
    }

    public function testResponseSchemaAllowsDottedAnalytics(): void
    {
        $mock = new MockHandler([
            $this->anthropicResponse([
                'debit_account' => '518', 'credit_account' => '221',
                'operation_type' => 'fee', 'confidence' => 0.9, 'reasoning' => 'poplatek',
            ]),
            $this->anthropicResponse([
                'debit_account' => '518.990', 'expense_category_id' => 0, 'confidence' => 0.9, 'reasoning' => 'služba',
            ]),
        ]);
        $requests = [];
        $router = $this->router($mock, $requests);
        $router->classifyBankTransaction(1, ['direction' => 'out'], []);
        $purchase = $router->suggestPurchasePosting(1, [], [], []);

        self::assertTrue($purchase['ok'] ?? false, 'přijatá faktura na analytiku 518.990: ' . json_encode($purchase));
        self::assertSame('518.990', $purchase['debit']);

        $patterns = array_merge($this->accountPatternsOf($requests[0]), $this->accountPatternsOf($requests[1]));
        self::assertNotEmpty($patterns, 'schéma odpovědi musí kódy účtů omezovat patternem');
        foreach ($patterns as $pattern) {
            foreach (['221', '221.100', '518.990', '518000'] as $code) {
                self::assertSame(1, preg_match('/' . $pattern . '/D', $code), "pattern $pattern musí pustit $code");
            }
            foreach (['221.', '.100', '518.9a', '5181234'] as $code) {
                self::assertSame(0, preg_match('/' . $pattern . '/D', $code), "pattern $pattern nesmí pustit $code");
            }
        }
    }
}
