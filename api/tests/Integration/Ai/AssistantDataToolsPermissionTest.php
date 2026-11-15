<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Ai;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Ai\Assistant\AssistantDataTools;
use MyInvoice\Service\Ai\Assistant\McpAssistantDataSource;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Mcp\HostedMcp;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Datové nástroje AI asistenta přes skutečný Node most a interní API: data se čtou
 * s právy přihlášeného uživatele v aktuální firmě. Uživatel bez práva na faktury je
 * přes asistenta nedostane, ani kdyby mu nástroj nabídl model nebo chybná nabídka.
 */
#[Group('integration')]
final class AssistantDataToolsPermissionTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private SessionManager $sessions;
    private int $supplierId = 0;
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $roleIds = [];
    /** @var list<string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 4);
        if (!is_file($root . '/cfg.php')) $this->markTestSkipped('cfg.php missing');
        try {
            $this->config = Config::load($root);
            $this->db = new Connection($this->config);
            $this->sessions = new SessionManager($this->db, $this->config, new DatabaseSecurityClock());
            $this->supplierId = (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        if ($this->supplierId <= 0) $this->markTestSkipped('No supplier');
        if (!Bootstrap::buildApp()->getContainer()->get(HostedMcp::class)->nodeAvailable()) {
            $this->markTestSkipped('Node.js není pro testy dostupný.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        foreach ($this->tokens as $token) {
            try { $this->sessions->destroy($token); } catch (\Throwable) {}
        }
        $pdo = $this->db->pdo();
        if ($this->userIds !== []) {
            $in = implode(',', array_fill(0, count($this->userIds), '?'));
            $pdo->prepare("DELETE FROM activity_log WHERE user_id IN ($in)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM user_suppliers WHERE user_id IN ($in)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM users WHERE id IN ($in)")->execute($this->userIds);
        }
        if ($this->roleIds !== []) {
            $in = implode(',', array_fill(0, count($this->roleIds), '?'));
            $pdo->prepare("DELETE FROM role_permissions WHERE role_id IN ($in)")->execute($this->roleIds);
            $pdo->prepare("DELETE FROM roles WHERE id IN ($in)")->execute($this->roleIds);
        }
        $this->db->close();
    }

    public function testUserWithoutInvoicePermissionGetsNoInvoiceDataThroughAssistant(): void
    {
        $tools = Bootstrap::buildApp()->getContainer()->get(AssistantDataTools::class);
        $accountant = $this->request(['accounting' => 1]);
        $invoicing = $this->request(['accounting' => 1, 'invoices' => 1, 'clients' => 1]);

        $restricted = $tools->forRequest($accountant);
        self::assertNotNull($restricted);
        $names = array_column($restricted->catalog(), 'name');
        self::assertNotContains('list_invoices', $names, 'nástroj bez práva se nenabídne');
        self::assertNotContains('create_invoice', $names, 'zápisové nástroje nikdy');
        $denied = $restricted->call('list_invoices', []);
        self::assertFalse($denied['ok']);

        // I kdyby nabídka selhala (bez omezení), API data bez práva nevydá.
        $unfiltered = $this->unfilteredSource($accountant);
        $direct = $unfiltered->call('list_invoices', ['per_page' => 5]);
        self::assertFalse($direct['ok']);
        self::assertStringContainsString('403', $direct['text']);

        $allowed = $tools->forRequest($invoicing);
        self::assertNotNull($allowed);
        self::assertContains('list_invoices', array_column($allowed->catalog(), 'name'));
        $ok = $allowed->call('list_invoices', ['per_page' => 5]);
        self::assertTrue($ok['ok'], $ok['text']);
    }

    public function testBridgeSendsOnlyReadRequestsToTheApplication(): void
    {
        $source = $this->unfilteredSource($this->request(['accounting' => 1]));
        $base = rtrim((string) $this->config->get('app.url', ''), '/') . '/api/v1';
        self::assertNull($source->apiRequest(['url' => $base . '/clients', 'method' => 'POST', 'body' => '{}']));
        self::assertNull($source->apiRequest(['url' => $base . '/clients', 'method' => 'GET', 'body' => 'x']));
        self::assertNull($source->apiRequest(['url' => 'https://evil.test/api/v1/clients', 'method' => 'GET']));
        $request = $source->apiRequest(['url' => $base . '/clients', 'method' => 'GET',
            'headers' => ['Authorization' => 'Bearer mi_pat_x', 'X-Supplier-Id' => '999', 'X-MyUcto-Tool' => 'search_clients']]);
        self::assertNotNull($request);
        self::assertArrayNotHasKey('Authorization', $request['headers']);
        self::assertSame((string) $this->supplierId, $request['headers']['X-Supplier-Id'], 'firmu určuje server, ne Node');
        self::assertSame('search_clients', $request['headers']['X-MyUcto-Tool']);
    }

    private function unfilteredSource(ServerRequestInterface $request): McpAssistantDataSource
    {
        $root = dirname(__DIR__, 4);
        $hosted = Bootstrap::buildApp()->getContainer()->get(HostedMcp::class);
        return new McpAssistantDataSource(
            $hosted->nodeBinary(), $hosted->phpBinary(), $root . '/MCP/src/hosted-relay.mjs',
            rtrim((string) $this->config->get('app.url', ''), '/') . '/api/v1', '0.0.0', $this->supplierId,
            (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN), ['REMOTE_ADDR' => '127.0.0.1'], null,
            json_decode((string) file_get_contents($root . '/MCP/src/tool-routes.generated.json'), true), new NullLogger(),
        );
    }

    /** @param array<string,int> $permissions */
    private function request(array $permissions): ServerRequestInterface
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO roles (name, role_type, is_active) VALUES (?, ?, 1)')
            ->execute(['__TEST AI asistent ' . bin2hex(random_bytes(4)), 'staff']);
        $roleId = (int) $pdo->lastInsertId();
        $this->roleIds[] = $roleId;
        $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)');
        foreach (['profile' => 2] + $permissions as $key => $level) $insert->execute([$roleId, $key, $level]);
        $pdo->prepare('INSERT INTO users (email, password_hash, name, role, role_id, locale, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
            ->execute(['__test_ai_assist_' . bin2hex(random_bytes(6)) . '@example.test',
                '$2y$12$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', '__TEST AI asistent', 'readonly', $roleId, 'cs']);
        $userId = (int) $pdo->lastInsertId();
        $this->userIds[] = $userId;
        $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id) VALUES (?, ?)')->execute([$userId, $this->supplierId]);
        $session = $this->sessions->create($userId, '127.0.0.1', '__test_ai_assist');
        $this->tokens[] = (string) $session['token'];

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/accounting/ai-assistant/ask', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader(SupplierScopeMiddleware::HEADER_NAME, (string) $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, (string) $session['token'])
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $userId, 'role_id' => $roleId, 'is_superadmin' => false,
                'role_summary' => ['id' => $roleId, 'type' => 'staff', 'system_key' => null, 'name' => 'test'],
            ])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
    }
}
