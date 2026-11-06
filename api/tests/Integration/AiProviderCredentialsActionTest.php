<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Admin\Import\AiProviderCredentialsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Import\LlmProviderCapabilities;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Epic F7 §3.8/§13 — AI provider credentials (AiProviderCredentialsAction). Ověřuje
 * rozšířený FE kontrakt status() (models, eu_capable, default_model, residency_label)
 * a dedikovaný TestConnection endpoint (admin-only; non-admin 403; bez změny creds).
 *
 * Gemini klíč se pro tenanta v setUp vynuluje (a v tearDown obnoví), aby testConnection
 * nešel na síť (creds null → ok:false, error, žádný HTTP call). Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class AiProviderCredentialsActionTest extends TestCase
{
    private Connection $db;
    private AiProviderCredentialsAction $action;
    private int $supplierId = 0;
    private int $userId = 0;
    private ?string $savedGeminiKey = null;
    /** @var array<string,mixed> */
    private array $savedOllama = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db     = $c->get(Connection::class);
            $this->action = $c->get(AiProviderCredentialsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user.');
        }
        // Vynuluj gemini klíč (testConnection pak nejde na síť); ulož pro obnovu.
        $stmt = $pdo->prepare('SELECT gemini_api_key_enc FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);
        $val = $stmt->fetchColumn();
        $this->savedGeminiKey = $val === false || $val === null ? null : (string) $val;
        $pdo->prepare('UPDATE supplier SET gemini_api_key_enc = NULL WHERE id = ?')->execute([$this->supplierId]);
        $this->savedOllama = $this->ollamaRow();
        $pdo->prepare('UPDATE supplier SET ollama_base_url = NULL, ollama_default_model = NULL, ollama_api_key_enc = NULL WHERE id = ?')
            ->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->supplierId > 0) {
            $this->db->pdo()->prepare('UPDATE supplier SET gemini_api_key_enc = ? WHERE id = ?')
                ->execute([$this->savedGeminiKey, $this->supplierId]);
            $this->db->pdo()->prepare('UPDATE supplier SET ollama_base_url = ?, ollama_default_model = ?, ollama_api_key_enc = ? WHERE id = ?')
                ->execute([
                    $this->savedOllama['ollama_base_url'] ?? null,
                    $this->savedOllama['ollama_default_model'] ?? null,
                    $this->savedOllama['ollama_api_key_enc'] ?? null,
                    $this->supplierId,
                ]);
            $this->db->close();
        }
    }

    public function testStatusHasFeContractFields(): void
    {
        $res = $this->call('status', 'GET', 'admin');
        self::assertSame(200, $res['status']);
        $providers = $res['body']['providers'] ?? [];
        foreach (['anthropic', 'azure_openai', 'openai', 'gemini', 'ollama'] as $p) {
            self::assertArrayHasKey($p, $providers, "provider $p chybí");
            $info = $providers[$p];
            self::assertArrayHasKey('models', $info, "$p.models");
            self::assertIsArray($info['models']);
            self::assertArrayHasKey('default_model', $info);
            self::assertArrayHasKey('data_region', $info);
            self::assertArrayHasKey('eu_capable', $info);
            self::assertIsBool($info['eu_capable']);
            self::assertArrayHasKey('residency_label', $info);
            self::assertArrayHasKey('configured', $info);
            self::assertArrayHasKey('extractions_count', $info);
        }
        // eu_capable matrix: anthropic/gemini = false, openai/azure = true.
        self::assertFalse($providers['anthropic']['eu_capable']);
        self::assertFalse($providers['gemini']['eu_capable']);
        self::assertTrue($providers['openai']['eu_capable']);
        self::assertTrue($providers['azure_openai']['eu_capable']);
    }

    /** AI nastavuje firma; vytěžovat přes něj smí i účetní, ne jen superadmin. */
    public function testExtractionStatusIsAvailableToAccountantWithoutConfig(): void
    {
        $res = $this->call('extractionStatus', 'GET', 'accountant');
        self::assertSame(200, $res['status']);
        $provider = $res['body']['ai_provider'] ?? null;
        self::assertIsString($provider);
        self::assertSame([$provider], array_keys($res['body']['providers'] ?? []));
        self::assertSame(['configured', 'default_model', 'models', 'allowed_models'], array_keys($res['body']['providers'][$provider]));
        self::assertSame(['client_ai_extraction_enabled', 'client_ai_extraction_usage', 'ai_provider', 'providers'], array_keys($res['body']));
        self::assertIsBool($res['body']['client_ai_extraction_enabled']);

        self::assertSame(403, $this->call('extractionStatus', 'GET', 'readonly')['status']);
    }

    public function testTestRouteAdminOnly(): void
    {
        // Non-admin → 403.
        foreach (['accountant', 'readonly'] as $role) {
            $denied = $this->call('test', 'POST', $role, ['provider' => 'gemini']);
            self::assertSame(403, $denied['status'], "$role musí dostat 403");
        }

        // Admin → 200 + kontrakt {test_ok, test_error, model}; gemini bez creds → ok:false (bez sítě).
        $ok = $this->call('test', 'POST', 'admin', ['provider' => 'gemini']);
        self::assertSame(200, $ok['status']);
        self::assertArrayHasKey('test_ok', $ok['body']);
        self::assertArrayHasKey('test_error', $ok['body']);
        self::assertArrayHasKey('model', $ok['body']);
        self::assertFalse($ok['body']['test_ok'], 'gemini bez klíče → test_ok false');
    }

    public function testTestRouteRejectsBadProvider(): void
    {
        $res = $this->call('test', 'POST', 'admin', ['provider' => 'bogus']);
        self::assertSame(400, $res['status']);
    }

    /** @param array<string,string> $body */
    private function callModels(string $role, array $body): array
    {
        $req = $this->req('POST', $role)->withParsedBody($body);
        $res = $this->action->ollamaModels($req, new Psr7Response());
        return ['status' => $res->getStatusCode(), 'body' => json_decode((string) $res->getBody(), true)];
    }

    private function ollamaRow(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ollama_base_url, ollama_default_model, ollama_api_key_enc FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);
        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function testOllamaRejectsBlockedEndpointAndSavesNothing(): void
    {
        $res = $this->call('update', 'PUT', 'admin', ['provider' => 'ollama', 'base_url' => 'http://169.254.169.254', 'default_model' => 'vision-model:7b']);
        self::assertSame(400, $res['status']);
        self::assertStringContainsString('ollama_endpoint_blocked', (string) ($res['body']['error']['message'] ?? ''));
        self::assertNull($this->ollamaRow()['ollama_base_url']);
    }

    public function testOllamaRequiresUrlAndModel(): void
    {
        $res = $this->call('update', 'PUT', 'admin', ['provider' => 'ollama', 'base_url' => 'http://127.0.0.1:9']);
        self::assertSame(400, $res['status']);
        self::assertStringContainsString('Adresa Ollamy i model jsou povinné', (string) ($res['body']['error']['message'] ?? ''));
    }

    public function testOllamaUnreachableIsReportedNotSaved(): void
    {
        $res = $this->call('update', 'PUT', 'admin', ['provider' => 'ollama', 'base_url' => 'http://127.0.0.1:9', 'default_model' => 'vision-model:7b']);
        self::assertSame(400, $res['status']);
        self::assertStringContainsString('ollama_unreachable', (string) ($res['body']['error']['message'] ?? ''));
        self::assertNull($this->ollamaRow()['ollama_base_url']);
    }

    public function testModelsEndpointValidatesUrl(): void
    {
        $blocked = $this->callModels('admin', ['base_url' => 'http://169.254.169.254']);
        self::assertSame(422, $blocked['status']);
        self::assertSame('ollama_endpoint_blocked', $blocked['body']['error']['code'] ?? null);

        $invalid = $this->callModels('admin', ['base_url' => 'http://127.0.0.1:11434/v1']);
        self::assertSame(422, $invalid['status']);
        self::assertSame('ollama_endpoint_invalid', $invalid['body']['error']['code'] ?? null);
    }

    public function testModelsEndpointAdminOnly(): void
    {
        foreach (['accountant', 'readonly'] as $role) {
            $res = $this->callModels($role, ['base_url' => 'http://127.0.0.1:9']);
            self::assertSame(403, $res['status'], "$role musí dostat 403");
        }
    }

    public function testChangingBaseUrlWithoutKeyClearsStoredKey(): void
    {
        // Simuluj uloženou konfiguraci s klíčem přímo v DB (Ollama v testu neběží).
        $crypto = Bootstrap::buildContainer()->get(\MyInvoice\Service\Auth\SecretEncryption::class);
        $this->db->pdo()->prepare('UPDATE supplier SET ollama_base_url = ?, ollama_default_model = ?, ollama_api_key_enc = ? WHERE id = ?')
            ->execute(['http://127.0.0.1:9', 'vision-model:7b', $crypto->encrypt('tok-secret'), $this->supplierId]);

        $action = Bootstrap::buildContainer()->get(AiProviderCredentialsAction::class);
        $ref = new \ReflectionMethod($action, 'resolveOllamaKey');
        self::assertSame('', $ref->invoke($action, $this->supplierId, 'http://127.0.0.2:9', null, false), 'jiná adresa bez nového klíče = klíč smazat');
        self::assertNull($ref->invoke($action, $this->supplierId, 'http://127.0.0.1:9/', null, false), 'stejná adresa = ponechat');
        self::assertSame('tok-new', $ref->invoke($action, $this->supplierId, 'http://127.0.0.2:9', 'tok-new', false));
        self::assertSame('', $ref->invoke($action, $this->supplierId, 'http://127.0.0.1:9', null, true), 'clear_api_key');
    }

    public function testModelsEndpointDoesNotSendStoredKeyToOtherHost(): void
    {
        $crypto = Bootstrap::buildContainer()->get(\MyInvoice\Service\Auth\SecretEncryption::class);
        $this->db->pdo()->prepare('UPDATE supplier SET ollama_base_url = ?, ollama_default_model = ?, ollama_api_key_enc = ? WHERE id = ?')
            ->execute(['http://127.0.0.1:9', 'vision-model:7b', $crypto->encrypt('tok-secret'), $this->supplierId]);
        $action = Bootstrap::buildContainer()->get(AiProviderCredentialsAction::class);
        $ref = new \ReflectionMethod($action, 'storedOllamaKeyFor');
        self::assertSame('tok-secret', $ref->invoke($action, $this->supplierId, 'http://127.0.0.1:9'));
        self::assertSame('', $ref->invoke($action, $this->supplierId, 'http://127.0.0.2:9'));
    }

    public function testDeleteOllamaClearsAll(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET ollama_base_url = ?, ollama_default_model = ? WHERE id = ?')
            ->execute(['http://127.0.0.1:9', 'vision-model:7b', $this->supplierId]);
        $req = $this->req('DELETE', 'admin')->withQueryParams(['provider' => 'ollama']);
        $res = $this->action->delete($req, new Psr7Response());
        self::assertSame(200, $res->getStatusCode());
        $row = $this->ollamaRow();
        self::assertNull($row['ollama_base_url']);
        self::assertNull($row['ollama_default_model']);
    }

    /**
     * Skutečná akce s OllamaClientem nad Guzzle MockHandlerem; zaznamenává odeslané requesty.
     *
     * @param array<int,array{request:\Psr\Http\Message\RequestInterface}> $history
     */
    private function mockedAction(array &$history): AiProviderCredentialsAction
    {
        $c = Bootstrap::buildContainer();
        $queue = [];
        for ($i = 0; $i < 6; $i++) {
            $queue[] = new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"models":[{"name":"vision-model:7b","size":1}]}');
            $queue[] = new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"capabilities":["completion"]}');
        }
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($queue));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $ollama = new \MyInvoice\Service\Import\OllamaClient(
            $c->get(Connection::class),
            $c->get(\MyInvoice\Service\Auth\SecretEncryption::class),
            $c->get(\Psr\Log\LoggerInterface::class),
            new \MyInvoice\Service\Ai\OllamaEndpointGuard(),
            $c->get(\MyInvoice\Service\Import\PdfPageRasterizerInterface::class),
            $c->get(\MyInvoice\Service\Import\PdfTotalExtractor::class),
            new \GuzzleHttp\Client(['handler' => $stack, 'http_errors' => false]),
            true,
        );
        return new AiProviderCredentialsAction(
            $c->get(\MyInvoice\Service\Import\AnthropicClient::class),
            $c->get(\MyInvoice\Service\Import\AzureOpenAiClient::class),
            $c->get(\MyInvoice\Service\Import\OpenAiClient::class),
            $c->get(\MyInvoice\Service\Import\GeminiClient::class),
            $ollama,
            $c->get(Connection::class),
            $c->get(\MyInvoice\Service\ActivityLogger::class),
            $c->get(\MyInvoice\Service\IpMatcher::class),
            $c->get(\MyInvoice\Service\Import\AiCredentialsBulkApplier::class),
            $c->get(\MyInvoice\Service\Import\ClientAiExtractionQuota::class),
        );
    }

    private function storeOllamaKey(string $baseUrl, string $key): string
    {
        $enc = Bootstrap::buildContainer()->get(\MyInvoice\Service\Auth\SecretEncryption::class)->encrypt($key);
        $this->db->pdo()->prepare('UPDATE supplier SET ollama_base_url = ?, ollama_default_model = ?, ollama_api_key_enc = ? WHERE id = ?')
            ->execute([$baseUrl, 'vision-model:7b', $enc, $this->supplierId]);
        return $enc;
    }

    /** @param array<int,array{request:\Psr\Http\Message\RequestInterface}> $history */
    private function assertNoAuthAndHost(array $history, string $host): void
    {
        self::assertNotEmpty($history, 'musel odejít aspoň jeden request');
        foreach ($history as $h) {
            self::assertFalse($h['request']->hasHeader('Authorization'), 'klíč nesmí odejít na jiný host');
            self::assertSame($host, $h['request']->getUri()->getHost());
        }
    }

    public function testPutWithChangedBaseUrlNeverSendsNorKeepsStoredKey(): void
    {
        $this->storeOllamaKey('http://127.0.0.1:9', 'tok-secret');
        $history = [];
        $action = $this->mockedAction($history);
        $req = $this->req('PUT', 'admin')->withParsedBody(['provider' => 'ollama', 'base_url' => 'http://127.0.0.2:9', 'default_model' => 'vision-model:7b']);
        $res = $action->update($req, new Psr7Response());
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertNoAuthAndHost($history, '127.0.0.2');
        $row = $this->ollamaRow();
        self::assertSame('http://127.0.0.2:9', $row['ollama_base_url']);
        self::assertNull($row['ollama_api_key_enc'], 'klíč se při změně adresy musí smazat');
    }

    public function testModelsEndpointForOtherHostSendsNoAuthorization(): void
    {
        $this->storeOllamaKey('http://127.0.0.1:9', 'tok-secret');
        $history = [];
        $res = $this->mockedAction($history)->ollamaModels(
            $this->req('POST', 'admin')->withParsedBody(['base_url' => 'http://127.0.0.2:9']),
            new Psr7Response()
        );
        self::assertSame(200, $res->getStatusCode());
        $this->assertNoAuthAndHost($history, '127.0.0.2');
    }

    public function testModelsEndpointForSameHostSendsStoredKey(): void
    {
        $this->storeOllamaKey('http://127.0.0.1:9', 'tok-secret');
        $history = [];
        $res = $this->mockedAction($history)->ollamaModels(
            $this->req('POST', 'admin')->withParsedBody(['base_url' => 'http://127.0.0.1:9']),
            new Psr7Response()
        );
        self::assertSame(200, $res->getStatusCode());
        self::assertNotEmpty($history);
        foreach ($history as $h) {
            self::assertSame('Bearer tok-secret', $h['request']->getHeaderLine('Authorization'));
            self::assertSame('127.0.0.1', $h['request']->getUri()->getHost());
        }
    }

    /** Uložení ověří Ollamu jedním seznamem modelů; druhý průchod /api/tags + /api/show jen zdvojí čekání. */
    public function testPutListsOllamaModelsOnlyOnce(): void
    {
        $history = [];
        $req = $this->req('PUT', 'admin')->withParsedBody(['provider' => 'ollama', 'base_url' => 'http://127.0.0.1:9', 'default_model' => 'vision-model:7b']);
        $res = $this->mockedAction($history)->update($req, new Psr7Response());
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $body = json_decode((string) $res->getBody(), true);
        self::assertTrue($body['test_ok']);
        self::assertSame('vision-model:7b', $body['model']);
        $tags = array_filter($history, static fn (array $h): bool => $h['request']->getUri()->getPath() === '/api/tags');
        self::assertCount(1, $tags);
    }

    public function testPutWithSameBaseUrlKeepsStoredKey(): void
    {
        $enc = $this->storeOllamaKey('http://127.0.0.1:9', 'tok-secret');
        $history = [];
        $req = $this->req('PUT', 'admin')->withParsedBody(['provider' => 'ollama', 'base_url' => 'http://127.0.0.1:9', 'default_model' => 'vision-model:7b']);
        $res = $this->mockedAction($history)->update($req, new Psr7Response());
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame($enc, $this->ollamaRow()['ollama_api_key_enc']);
    }

    public function testPutWithNewUrlDropsUndecryptableOrphanKey(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET ollama_base_url = ?, ollama_default_model = ?, ollama_api_key_enc = ? WHERE id = ?')
            ->execute(['http://127.0.0.1:9', 'vision-model:7b', 'enc:v1:' . base64_encode(str_repeat('x', 64)), $this->supplierId]);
        $history = [];
        $req = $this->req('PUT', 'admin')->withParsedBody(['provider' => 'ollama', 'base_url' => 'http://127.0.0.2:9', 'default_model' => 'vision-model:7b']);
        $res = $this->mockedAction($history)->update($req, new Psr7Response());
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertNull($this->ollamaRow()['ollama_api_key_enc']);
    }

    /**
     * ID workspace u klíče Anthropic bez přiřazeného workspace: uloží se oříznuté, status
     * ho vrací pro formulář, neplatná hodnota je 400 bez zápisu, prázdná ho smaže a smazání
     * klíče ho zahodí taky. Config-only uložení nevolá poskytovatele.
     */
    public function testAnthropicWorkspaceIdSaveValidateAndClear(): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT anthropic_api_key_enc, anthropic_default_model, anthropic_workspace_id FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);
        $saved = (array) $stmt->fetch(\PDO::FETCH_ASSOC);
        $workspace = function () use ($pdo): mixed {
            $s = $pdo->prepare('SELECT anthropic_workspace_id FROM supplier WHERE id = ?');
            $s->execute([$this->supplierId]);
            return $s->fetchColumn();
        };
        try {
            $crypto = Bootstrap::buildContainer()->get(\MyInvoice\Service\Auth\SecretEncryption::class);
            $pdo->prepare('UPDATE supplier SET anthropic_api_key_enc = ?, anthropic_workspace_id = NULL WHERE id = ?')
                ->execute([$crypto->encrypt('sk-ant-' . str_repeat('a', 40)), $this->supplierId]);

            $ok = $this->call('update', 'PUT', 'admin', ['provider' => 'anthropic', 'workspace_id' => '  wrkspc_01AbC-9  ']);
            self::assertSame(200, $ok['status'], json_encode($ok['body']) ?: '');
            self::assertFalse($ok['body']['tested']);
            self::assertSame('wrkspc_01AbC-9', $workspace());
            self::assertSame('wrkspc_01AbC-9', $this->call('status', 'GET', 'admin')['body']['providers']['anthropic']['workspace_id'] ?? null);

            foreach (["wrkspc_01\r\nX-Evil: 1", 'wrkspc 01', str_repeat('a', 129), ['pole']] as $bad) {
                $res = $this->call('update', 'PUT', 'admin', ['provider' => 'anthropic', 'workspace_id' => $bad]);
                self::assertSame(400, $res['status']);
                self::assertSame('validation_failed', $res['body']['error']['code'] ?? null);
                self::assertSame('wrkspc_01AbC-9', $workspace(), 'neplatná hodnota nesmí nic zapsat');
            }

            $this->call('update', 'PUT', 'admin', ['provider' => 'anthropic', 'default_model' => LlmProviderCapabilities::ANTHROPIC_DEFAULT_MODEL]);
            self::assertSame('wrkspc_01AbC-9', $workspace(), 'vynechané pole ID workspace nemaže');

            $cleared = $this->call('update', 'PUT', 'admin', ['provider' => 'anthropic', 'workspace_id' => '   ']);
            self::assertSame(200, $cleared['status']);
            self::assertNull($workspace());

            $pdo->prepare('UPDATE supplier SET anthropic_workspace_id = ? WHERE id = ?')->execute(['wrkspc_01Del', $this->supplierId]);
            $res = $this->action->delete($this->req('DELETE', 'admin')->withQueryParams(['provider' => 'anthropic']), new Psr7Response());
            self::assertSame(200, $res->getStatusCode());
            self::assertNull($workspace());
        } finally {
            $pdo->prepare('UPDATE supplier SET anthropic_api_key_enc = ?, anthropic_default_model = ?, anthropic_workspace_id = ? WHERE id = ?')
                ->execute([
                    $saved['anthropic_api_key_enc'] ?? null,
                    $saved['anthropic_default_model'] ?? LlmProviderCapabilities::ANTHROPIC_DEFAULT_MODEL,
                    $saved['anthropic_workspace_id'] ?? null,
                    $this->supplierId,
                ]);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, string $http, string $role, array $body = []): array
    {
        $req = $this->req($http, $role);
        if ($body !== []) $req = $req->withParsedBody($body);
        $resp = $this->action->{$method}($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function req(string $http, string $role): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($http, '/api/admin/imports/ai/credentials')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
    }
}
