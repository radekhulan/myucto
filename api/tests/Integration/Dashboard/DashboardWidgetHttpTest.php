<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Dashboard;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SessionManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/** Celý HTTP řetězec: relace, firma, efektivní role, CSRF, tokenová hranice a DI. */
#[Group('integration')]
final class DashboardWidgetHttpTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private SessionManager $sessions;
    private ?App $app = null;
    private array $suppliers = [];
    private array $users = [];
    private array $roles = [];
    private array $sessionTokens = [];
    private int $profileRole;
    private int $invoiceRole;
    private int $readonlyRole;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 4);
        if (!is_file($root . '/cfg.php')) $this->markTestSkipped('HTTP test vyžaduje izolovanou testovací DB.');
        $this->config = Config::load($root);
        $this->db = new Connection($this->config);
        self::assertStringEndsWith('_test', (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->sessions = new SessionManager($this->db, $this->config, new DatabaseSecurityClock());
        $this->suppliers[] = $this->supplier('A');
        $this->suppliers[] = $this->supplier('B');
        $this->profileRole = $this->role('client', ['profile' => 1]);
        $this->invoiceRole = $this->role('client', ['profile' => 1, 'invoices' => 1]);
        $this->readonlyRole = $this->role('staff', ['profile' => 1, 'dashboard' => 1]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($this->sessionTokens as $token) $this->sessions->destroy($token);
        foreach ($this->users as $user) {
            $pdo->prepare('DELETE FROM api_request_log WHERE user_id = ?')->execute([$user]);
            $pdo->prepare('DELETE FROM activity_log WHERE user_id = ?')->execute([$user]);
            $pdo->prepare('DELETE FROM user_suppliers WHERE user_id = ?')->execute([$user]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user]);
        }
        foreach ($this->roles as $role) $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$role]);
        // Vlastní měna a výchozí měna firmy tvoří cyklický FK stejně jako při setupu.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($this->suppliers as $supplier) {
                $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$supplier]);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        // Kaskády při odstranění firmy musí běžet se zapnutými cizími klíči.
        foreach ($this->suppliers as $supplier) {
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$supplier]);
        }
        $this->app = null;
        $this->db->close();
    }

    public function testClientProfileReadCatalogAndQueryUseOnlyAllowedBusinessData(): void
    {
        $user = $this->user($this->profileRole, 'client');
        $this->assign($user, $this->suppliers[0]);
        $session = $this->session($user);
        $catalog = $this->request('GET', '/api/dashboard-widgets/catalog?board=portal', $session);
        self::assertSame(200, $catalog->getStatusCode(), (string) $catalog->getBody());
        $body = $this->json($catalog);
        $available = array_column($body['widgets'], 'type');
        self::assertContains('business.kpi', $available);
        self::assertNotContains('sales.summary', $available);
        self::assertNotContains('clients.top', $available);
        self::assertNotContains('actions.quick', $available);

        $query = $this->request('POST', '/api/dashboard-widgets/query', $session, ['board' => 'portal', 'widgets' => [
            $this->widget('base', 'business.kpi'), $this->widget('sales', 'sales.summary'), $this->widget('contacts', 'clients.top'),
        ]]);
        self::assertSame(200, $query->getStatusCode(), (string) $query->getBody());
        $data = $this->json($query)['widgets'];
        self::assertContains($data['base']['status'], ['ok', 'empty']);
        self::assertSame(['status' => 'unavailable', 'data' => null], $data['sales']);
        self::assertSame(['status' => 'unavailable', 'data' => null], $data['contacts']);
        self::assertSame(403, $this->request('GET', '/api/dashboard-widgets/catalog?board=home', $session)->getStatusCode());
        $noCsrf = $this->request('POST', '/api/dashboard-widgets/query', $session, ['board' => 'portal', 'widgets' => []], csrf: false);
        self::assertSame(403, $noCsrf->getStatusCode());
        self::assertSame('csrf_failed', $this->json($noCsrf)['error']['code']);
    }

    public function testCompanyOverrideRoleIsResolvedForEveryCatalogAndQuery(): void
    {
        $global = $this->role('client', ['profile' => 2, 'invoices' => 2, 'clients' => 2]);
        $user = $this->user($global, 'client');
        $this->assign($user, $this->suppliers[0], $this->profileRole);
        $this->assign($user, $this->suppliers[1], $this->invoiceRole);
        $session = $this->session($user);
        foreach ([[0, false], [1, true], [0, false]] as [$index, $allowed]) {
            $catalog = $this->request('GET', '/api/dashboard-widgets/catalog?board=portal', $session, supplier: $this->suppliers[$index]);
            self::assertSame(200, $catalog->getStatusCode(), (string) $catalog->getBody());
            self::assertSame($allowed, in_array('sales.summary', array_column($this->json($catalog)['widgets'], 'type'), true));
            self::assertNotContains('clients.top', array_column($this->json($catalog)['widgets'], 'type'));
            $query = $this->request('POST', '/api/dashboard-widgets/query', $session,
                ['board' => 'portal', 'widgets' => [$this->widget('sales', 'sales.summary')]], $this->suppliers[$index]);
            self::assertSame(200, $query->getStatusCode(), (string) $query->getBody());
            self::assertContains($this->json($query)['widgets']['sales']['status'], $allowed ? ['ok', 'empty'] : ['unavailable']);
        }
    }

    public function testAnonymousForeignCompanyInvalidBoardAndBearerAliasesStayClosed(): void
    {
        $user = $this->user($this->readonlyRole, 'readonly');
        $this->assign($user, $this->suppliers[0]);
        $session = $this->session($user);
        self::assertSame(401, $this->request('GET', '/api/dashboard-widgets/catalog?board=portal')->getStatusCode());
        self::assertSame(403, $this->request('GET', '/api/dashboard-widgets/catalog?board=portal', $session, supplier: $this->suppliers[1])->getStatusCode());
        self::assertSame(422, $this->request('GET', '/api/dashboard-widgets/catalog?board=invalid', $session)->getStatusCode());
        $alias = $this->request('GET', '/api/v1/dashboard-widgets/catalog?board=portal', $session);
        self::assertSame(200, $alias->getStatusCode(), (string) $alias->getBody());
        self::assertSame('1', $alias->getHeaderLine('X-API-Version'));
        self::assertSame('portal', $this->json($alias)['board']);
        $tokens = new ApiTokenService($this->db, new RedisFactory($this->config));
        $token = $tokens->generate($user, $this->suppliers[0], 'Syntetický HTTP test', 'read_write');
        foreach (['/api', '/api/v1'] as $prefix) {
            foreach ([['GET', '/dashboard-widgets/catalog?board=portal', null],
                ['POST', '/dashboard-widgets/query', ['board' => 'portal', 'widgets' => []]]] as [$method, $path, $body]) {
                $response = $this->request($method, $prefix . $path, body: $body, token: $token['plaintext']);
                self::assertSame(403, $response->getStatusCode(), $method . ' ' . $prefix . $path . ': ' . (string) $response->getBody());
                self::assertSame('token_endpoint_forbidden', $this->json($response)['error']['code']);
            }
        }
    }

    public function testOriginalPortalSummaryKeepsAggregatedContract(): void
    {
        $user = $this->user($this->profileRole, 'client');
        $this->assign($user, $this->suppliers[0]);
        $response = $this->request('GET', '/api/portal/summary', $this->session($user));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        foreach (['company', 'kpi', 'monthly', 'cashflow', 'vat'] as $key) self::assertArrayHasKey($key, $body);
        $walk = function (array $node) use (&$walk): void {
            foreach ($node as $key => $value) {
                self::assertNotContains($key, ['client_name', 'vendor_name', 'client_id', 'vendor_id', 'invoice_number', 'document_number', 'number', 'variable_symbol', 'company_name']);
                if (is_array($value)) $walk($value);
            }
        };
        $walk($body);
    }

    public function testPersonalLayoutRoundTripRequiresCsrfAndIsolatesOwnersAndBoards(): void
    {
        $user = $this->user($this->readonlyRole, 'readonly');
        $other = $this->user($this->readonlyRole, 'readonly');
        foreach ([$user, $other] as $id) $this->assign($id, $this->suppliers[0]);
        $session = $this->session($user);
        $initial = $this->request('GET', '/api/user/dashboard-layouts/home', $session);
        self::assertSame(200, $initial->getStatusCode(), (string) $initial->getBody());
        self::assertSame('0', $this->json($initial)['revision']);
        $layout = ['schemaVersion' => 2, 'items' => []];
        $body = ['layout' => $layout, 'revision' => '0'];
        $noCsrf = $this->request('PUT', '/api/user/dashboard-layouts/home', $session, $body, csrf: false);
        self::assertSame(403, $noCsrf->getStatusCode());
        self::assertSame('csrf_failed', $this->json($noCsrf)['error']['code']);
        $saved = $this->request('PUT', '/api/user/dashboard-layouts/home', $session, $body);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $revision = $this->json($saved)['revision'];
        self::assertNotSame('0', $revision);
        self::assertSame($layout, $this->json($this->request('GET', '/api/user/dashboard-layouts/home', $session))['layout']);
        self::assertSame('0', $this->json($this->request('GET', '/api/user/dashboard-layouts/portal', $session))['revision']);
        self::assertSame('0', $this->json($this->request('GET', '/api/user/dashboard-layouts/home', $this->session($other)))['revision']);
        self::assertSame(409, $this->request('PUT', '/api/user/dashboard-layouts/home', $session, $body)->getStatusCode());
        self::assertSame(422, $this->request('PUT', '/api/user/dashboard-layouts/home', $session, $body + ['user_id' => $other])->getStatusCode());
        $reset = $this->request('DELETE', '/api/user/dashboard-layouts/home', $session, ['revision' => $revision]);
        self::assertSame(200, $reset->getStatusCode(), (string) $reset->getBody());
        self::assertNull($this->json($reset)['layout']);
        self::assertNotSame('0', $this->json($reset)['revision']);
        $tokens = new ApiTokenService($this->db, new RedisFactory($this->config));
        $token = $tokens->generate($user, $this->suppliers[0], 'Syntetický editor rozložení', 'read_write');
        foreach (['/api', '/api/v1'] as $prefix) {
            foreach (['GET', 'PUT', 'DELETE'] as $method) {
                $response = $this->request($method, $prefix . '/user/dashboard-layouts/home', body: $body, token: $token['plaintext']);
                self::assertSame(403, $response->getStatusCode());
                self::assertSame('token_endpoint_forbidden', $this->json($response)['error']['code']);
            }
        }
    }

    private function supplier(string $suffix): int
    {
        $pdo = $this->db->pdo();
        // První firma čisté DB nemá odkud převzít výchozí měnu; stejný krátký
        // bootstrap cyklického FK používají SetupAction a SupplierCreator.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $stmt = $pdo->prepare("INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, is_vat_payer)
                VALUES (?, 'Syntetická 1', 'Testovací město', '10000', (SELECT MIN(id) FROM countries), 'synthetic@example.test',
                    0, (SELECT MIN(id) FROM vat_rates), 0)");
            $stmt->execute(['Syntetická HTTP firma ' . $suffix . ' ' . bin2hex(random_bytes(4))]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                VALUES (?, 'CZK', 'Syntetická testovací měna', 'Kč', 'Česká koruna', 'Czech koruna', 2, 1, 1)")->execute([$id]);
            $currency = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currency, $id]);
            return $id;
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function role(string $type, array $permissions): int
    {
        $this->db->pdo()->prepare('INSERT INTO roles (name, role_type, is_active) VALUES (?, ?, 1)')
            ->execute(['Syntetická HTTP role ' . bin2hex(random_bytes(4)), $type]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->roles[] = $id;
        $stmt = $this->db->pdo()->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)');
        foreach ($permissions as $key => $level) $stmt->execute([$id, $key, $level]);
        return $id;
    }

    private function user(int $role, string $legacy): int
    {
        $this->db->pdo()->prepare("INSERT INTO users (email, password_hash, name, role, role_id, locale, is_active) VALUES (?, ?, ?, ?, ?, 'cs', 1)")
            ->execute(['__dashboard_http_' . bin2hex(random_bytes(8)) . '@example.test', password_hash('synthetic-only-password', PASSWORD_BCRYPT, ['cost' => 4]),
                'Syntetický HTTP uživatel', $legacy, $role]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->users[] = $id;
        return $id;
    }

    private function assign(int $user, int $supplier, ?int $role = null): void
    {
        $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)')->execute([$user, $supplier, $role]);
    }

    private function session(int $user): array
    {
        $session = $this->sessions->create($user, '127.0.0.1', 'Synthetic dashboard HTTP test');
        $this->sessionTokens[] = $session['token'];
        return $session;
    }

    private function request(string $method, string $path, ?array $session = null, ?array $body = null, ?int $supplier = null, bool $csrf = true, ?string $token = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Accept', 'application/json')->withHeader('Origin', rtrim((string) $this->config->get('app.url', ''), '/'))
            ->withHeader('X-Supplier-Id', (string) ($supplier ?? $this->suppliers[0]));
        if ($session !== null) {
            $request = $request->withCookieParams([(string) $this->config->get('session.cookie_name', '__Host-myinvoice_session') => $session['token']]);
            if ($csrf) $request = $request->withHeader('X-CSRF-Token', $session['csrf_token']);
        }
        if ($token !== null) $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        if ($body !== null) $request = $request->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        return ($this->app ??= Bootstrap::buildApp())->handle($request);
    }

    private function widget(string $id, string $type): array
    {
        return ['id' => $id, 'type' => $type, 'options' => (object) []];
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
    }
}
