<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as HttpResponse;
use MyInvoice\Action\Admin\Import\AiProviderCredentialsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Ai\OllamaEndpointGuard;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\AiCredentialsBulkApplier;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\AzureOpenAiClient;
use MyInvoice\Service\Import\ClientAiExtractionQuota;
use MyInvoice\Service\Import\GeminiClient;
use MyInvoice\Service\Import\OllamaClient;
use MyInvoice\Service\Import\OpenAiClient;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Import\PdfPageRasterizerInterface;
use MyInvoice\Service\Import\PdfTotalExtractor;
use MyInvoice\Service\IpMatcher;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class AiProviderCredentialsConfigTest extends TestCase
{
    private PDO $pdo;
    private AiProviderCredentialsAction $action;
    private array $history = [];
    private array $resolvedHosts = [];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE supplier (
            id INTEGER PRIMARY KEY,
            anthropic_api_key_enc TEXT, anthropic_default_model TEXT, anthropic_workspace_id TEXT,
            openai_api_key_enc TEXT, openai_default_model TEXT, openai_base_url TEXT,
            gemini_api_key_enc TEXT, gemini_default_model TEXT,
            ollama_api_key_enc TEXT, ollama_default_model TEXT, ollama_base_url TEXT,
            ai_provider TEXT, ai_data_region TEXT, ai_eu_residency_required INTEGER
        )');
        $this->pdo->exec("INSERT INTO supplier VALUES (
            1, 'stored-anthropic', 'claude-sonnet-5-5-20261111', NULL,
            'stored-openai', 'gpt-6.2-sol', NULL,
            'stored-gemini', 'gemini-3.8-flash',
            'stored-ollama', 'vision:7b', 'http://ollama.test:11434',
            'anthropic', 'us', 0
        )");
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($this->pdo);
        $crypto = $this->createStub(SecretEncryption::class);
        $crypto->method('decrypt')->willReturn('synthetic-provider-key');
        $stack = HandlerStack::create(new MockHandler([new HttpResponse(503)]));
        $stack->push(Middleware::history($this->history));
        $http = new Client(['handler' => $stack]);
        $log = new NullLogger();
        $guard = new OllamaEndpointGuard(function (string $host): array {
            $this->resolvedHosts[] = $host;
            return ['127.0.0.1'];
        }, '');
        $this->action = new AiProviderCredentialsAction(
            new AnthropicClient($db, $crypto, $log, $http),
            new AzureOpenAiClient($db, $crypto, $log, $http),
            new OpenAiClient($db, $crypto, $log, $http),
            new GeminiClient($db, $crypto, $log, $http),
            new OllamaClient($db, $crypto, $log, $guard, $this->createStub(PdfPageRasterizerInterface::class), new PdfTotalExtractor(new PdfIsdocExtractor()), $http, true),
            $db,
            $this->createStub(ActivityLogger::class),
            new IpMatcher(),
            (new \ReflectionClass(AiCredentialsBulkApplier::class))->newInstanceWithoutConstructor(),
            new ClientAiExtractionQuota($db),
        );
    }

    public static function configUpdates(): array
    {
        return [
            'Anthropic workspace' => ['anthropic', ['workspace_id' => ' wrkspc_fixture '], 'workspace_id', 'wrkspc_fixture'],
            'Anthropic model' => ['anthropic', ['default_model' => 'claude-sonnet-5-5-20261112'], 'default_model', 'claude-sonnet-5-5-20261112'],
            'OpenAI model' => ['openai', ['default_model' => 'gpt-6.2-sol-2026-11-12'], 'default_model', 'gpt-6.2-sol-2026-11-12'],
            'Gemini model' => ['gemini', ['default_model' => 'gemini-3.9-flash'], 'default_model', 'gemini-3.9-flash'],
            'Ollama model' => ['ollama', ['default_model' => 'team/custom_vision:7b'], 'default_model', 'team/custom_vision:7b'],
        ];
    }

    #[DataProvider('configUpdates')]
    public function testConfigOnlySaveSucceedsWithUnavailableCatalog(string $provider, array $body, string $field, string $expected): void
    {
        $response = $this->update($provider, $body);

        self::assertSame([], $this->history);
        self::assertSame([], $this->resolvedHosts);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['saved' => true, 'tested' => false, 'test_ok' => null, 'test_error' => null], json_decode((string) $response->getBody(), true));
        $row = $this->supplier();
        self::assertSame($expected, $row[$provider . '_' . $field]);
        self::assertSame('stored-' . $provider, $row[$provider . '_api_key_enc']);
    }

    public static function invalidModels(): array
    {
        return [
            ['anthropic', 'claude-sonnet-5-5-audio'],
            ['openai', 'gpt-4o-realtime-preview'],
            ['gemini', 'gemini-4-neon-image'],
            ['ollama', 'custom vision:7b'],
        ];
    }

    #[DataProvider('invalidModels')]
    public function testInvalidModelIdIsRejectedOffline(string $provider, string $model): void
    {
        $before = $this->supplier();
        $response = $this->update($provider, ['default_model' => $model]);

        self::assertSame([], $this->history);
        self::assertSame([], $this->resolvedHosts);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame($before, $this->supplier());
    }

    public function testConfigOnlySaveDoesNotTestOrCopyStoredKeyForBulkRequest(): void
    {
        $response = $this->update('openai', ['base_url' => 'https://eu.api.openai.com', 'apply_to_all_companies' => true]);

        self::assertSame([], $this->history);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['tested']);
        self::assertSame(['applied' => false, 'reason' => 'key_required'], $body['bulk']);
        self::assertSame('https://eu.api.openai.com', $this->supplier()['openai_base_url']);
    }

    private function update(string $provider, array $body): Response
    {
        $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/admin/imports/ai/credentials')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 1)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Testovací role', 'staff', true, ['settings.ai_provider' => AccessLevel::WRITE->value]))
            ->withParsedBody(['provider' => $provider] + $body);

        return $this->action->update($request, new Response());
    }

    private function supplier(): array
    {
        return $this->pdo->query('SELECT * FROM supplier WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    }
}
