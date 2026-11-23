<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mcp\McpInternalRequest;
use MyInvoice\Service\Mcp\McpOAuth;
use MyInvoice\Service\Tenant\ClientRoutePolicy;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Klientská role a MCP na kanonické adresě instalace (bez vlastní domény firmy).
 * Připojení je vázané na jednu firmu klienta a má stejný omezený povrch jako
 * připojení přes klientskou doménu.
 */
final class ClientCanonicalMcpTest extends TestCase
{
    private \PDO $pdo;
    private int $user;
    private int $role;
    private int $supplier;
    private int $otherSupplier;
    private string $client;
    private string $canonical;
    private array $session;
    private array $prior = [];
    private string $cookie;
    private array $extraUsers = [];
    private array $extraRoles = [];
    private array $hostnames = [];

    protected function setUp(): void
    {
        foreach (['MYINVOICE_DOMAINS_ENABLED', 'MYINVOICE_MCP_ENABLED'] as $key) {
            $this->prior[$key] = getenv($key);
            putenv($key . '=1');
        }
        $container = Bootstrap::buildContainer();
        $this->pdo = $container->get(Connection::class)->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");
        $suppliers = $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(2, $suppliers);
        [$this->supplier, $this->otherSupplier] = array_map('intval', $suppliers);
        $suffix = bin2hex(random_bytes(6));
        $this->pdo->prepare("INSERT INTO roles (name, role_type, is_active) VALUES (?, 'client', 1)")->execute(['MCP kanonický ' . $suffix]);
        $this->role = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO role_permissions VALUES (?, 'profile', 1), (?, 'profile.mcp', 1), (?, 'invoices', 1)")
            ->execute([$this->role, $this->role, $this->role]);
        $this->user = $this->createUser('mcp-kan-' . $suffix . '@example.test', 'MCP kanonický klient', $this->role);
        $this->pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id) VALUES (?, ?)')->execute([$this->user, $this->supplier]);
        $this->client = $container->get(McpOAuth::class)->register('Syntetický asistent', ['https://assistant.example.test/callback']);
        $config = $container->get(Config::class);
        $this->canonical = rtrim((string) $config->get('app.url'), '/');
        $this->cookie = (string) $config->get('session.cookie_name', '__Host-myinvoice_session');
        $this->session = $container->get(SessionManager::class)->create($this->user, '127.0.0.1', 'synthetic-mcp');
    }

    protected function tearDown(): void
    {
        if (isset($this->user)) {
            $this->pdo->prepare('DELETE FROM mcp_oauth_clients WHERE client_id=?')->execute([$this->client]);
            foreach ($this->hostnames as $host) $this->pdo->prepare('DELETE FROM supplier_domains WHERE hostname=?')->execute([$host]);
            foreach ([...$this->extraUsers, $this->user] as $id) $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
            foreach ([...$this->extraRoles, $this->role] as $id) $this->pdo->prepare('DELETE FROM roles WHERE id=?')->execute([$id]);
        }
        foreach ($this->prior as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
    }

    public function testClientConsentOnCanonicalAddressBindsGrantToOwnCompanyAndClientSurface(): void
    {
        $params = $this->params();
        $consent = $this->request('GET', $this->canonical . '/oauth/authorize?' . http_build_query($params), session: true);
        self::assertSame(200, $consent->getStatusCode(), (string) $consent->getBody());
        self::assertStringNotContainsString('včetně těch přidaných později', (string) $consent->getBody());
        self::assertStringContainsString('name="grant_supplier_id" value="' . $this->supplier . '"', (string) $consent->getBody());

        $token = $this->approve($params + ['grant_supplier_id' => (string) $this->supplier]);
        $row = Bootstrap::buildContainer()->get(ApiTokenService::class)->validate($token['access_token']);
        self::assertSame($this->supplier, $row['supplier_id']);
        self::assertSame($this->supplier, (int) $this->pdo->query("SELECT supplier_id FROM mcp_oauth_grants WHERE user_id={$this->user}")->fetchColumn());

        $names = array_column(self::json($this->rpc($token['access_token'], 'tools/list'))['result']['tools'], 'name');
        self::assertContains('list_invoices', $names);
        self::assertNotContains('trial_balance', $names);
        self::assertNotContains('create_invoice', $names);
        self::assertNotContains('list_payroll_people', $names);
        $accounting = self::json($this->rpc($token['access_token'], 'tools/call', ['name' => 'trial_balance', 'arguments' => []]));
        self::assertTrue($accounting['result']['isError']);
        $foreign = self::json($this->rpc($token['access_token'], 'tools/call', ['name' => 'list_invoices', 'arguments' => ['supplier_id' => $this->otherSupplier]]));
        self::assertTrue($foreign['result']['isError']);
        $this->pdo->prepare('INSERT INTO user_suppliers (user_id,supplier_id) VALUES (?,?)')->execute([$this->user, $this->otherSupplier]);
        $companies = self::json($this->rpc($token['access_token'], 'tools/call', ['name' => 'list_suppliers']))['result']['structuredContent']['result'];
        self::assertSame([$this->supplier], array_column($companies, 'id'));
    }

    public function testRestOutsideClientSurfaceIsForbiddenForCanonicalClientToken(): void
    {
        // Self-service cestu PermissionMiddleware pustí; zastavit ji musí až klientský povrch tokenu.
        $path = '/api/v1/auth/domain-login/authorize';
        self::assertFalse((new ClientRoutePolicy())->allowsApiRequest('POST', '/api/auth/domain-login/authorize'));
        $grant = $this->grantFor($this->supplier);
        $builder = new McpInternalRequest($this->canonical, sys_get_temp_dir());
        $request = $builder->build(['url' => $this->canonical . $path, 'method' => 'POST',
            'serverParams' => ['REMOTE_ADDR' => '127.0.0.1'],
            'headers' => ['Authorization' => 'Bearer ' . $grant['access_token'], 'Content-Type' => 'application/json'],
            'body' => '{}', 'mcpResource' => $this->canonical . '/mcp']);
        $response = $this->handle($request);
        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('klientského MCP', (string) $response->getBody());
    }

    public function testClientWithoutMcpPermissionCannotConsentAndCannotUseTokensPermission(): void
    {
        $this->pdo->exec("UPDATE role_permissions SET access_level=0 WHERE role_id={$this->role} AND permission_key='profile.mcp'");
        // profile.tokens je jen pro interní role; klientovi MCP neotevře.
        $this->pdo->prepare("INSERT INTO role_permissions VALUES (?, 'profile.tokens', 2)")->execute([$this->role]);
        $consent = $this->request('GET', $this->canonical . '/oauth/authorize?' . http_build_query($this->params()), session: true);
        self::assertSame(403, $consent->getStatusCode(), (string) $consent->getBody());
        self::assertNull($this->exchange($this->supplier));
    }

    public function testClientGrantWithoutCompanyOrForForeignCompanyIsNotIssued(): void
    {
        self::assertNull($this->exchange(null));
        self::assertNull($this->exchange($this->otherSupplier));
        $params = $this->params() + ['grant_supplier_id' => (string) $this->otherSupplier];
        $denied = $this->request('POST', $this->canonical . '/oauth/authorize', $params + [
            'csrf_token' => $this->session['csrf_token'], 'decision' => 'approve', 'grant_scope' => 'read',
        ], session: true, form: true);
        self::assertSame(400, $denied->getStatusCode(), (string) $denied->getBody());
    }

    public function testClientWithMoreCompaniesChoosesOneCompanyPerConnection(): void
    {
        $this->pdo->prepare('INSERT INTO user_suppliers (user_id,supplier_id) VALUES (?,?)')->execute([$this->user, $this->otherSupplier]);
        $params = $this->params();
        $consent = $this->request('GET', $this->canonical . '/oauth/authorize?' . http_build_query($params), session: true);
        self::assertSame(200, $consent->getStatusCode(), (string) $consent->getBody());
        self::assertStringContainsString('<select name="grant_supplier_id"', (string) $consent->getBody());
        $missing = $this->request('POST', $this->canonical . '/oauth/authorize', $params + [
            'csrf_token' => $this->session['csrf_token'], 'decision' => 'approve', 'grant_scope' => 'read',
        ], session: true, form: true);
        self::assertSame(400, $missing->getStatusCode());
        $token = $this->approve($params + ['grant_supplier_id' => (string) $this->otherSupplier]);
        $row = Bootstrap::buildContainer()->get(ApiTokenService::class)->validate($token['access_token']);
        self::assertSame($this->otherSupplier, $row['supplier_id']);
    }

    #[TestWith(['permission'])]
    #[TestWith(['membership'])]
    #[TestWith(['user'])]
    #[TestWith(['role'])]
    public function testRevokedAccessBlocksUseAndRefresh(string $change): void
    {
        $grant = $this->grantFor($this->supplier);
        self::assertSame(200, $this->rpc($grant['access_token'], 'initialize')->getStatusCode());
        match ($change) {
            'permission' => $this->pdo->exec("UPDATE role_permissions SET access_level=0 WHERE role_id={$this->role} AND permission_key='profile.mcp'"),
            'membership' => $this->pdo->exec("DELETE FROM user_suppliers WHERE user_id={$this->user}"),
            'user' => $this->pdo->exec("UPDATE users SET is_active=0 WHERE id={$this->user}"),
            'role' => $this->pdo->exec("UPDATE roles SET is_active=0 WHERE id={$this->role}"),
        };
        self::assertSame(401, $this->rpc($grant['access_token'], 'initialize')->getStatusCode());
        self::assertNull(Bootstrap::buildContainer()->get(McpOAuth::class)->refresh($grant['refresh_token'], $this->client));
    }

    public function testMembershipInAnotherCompanyDoesNotKeepGrantAlive(): void
    {
        $grant = $this->grantFor($this->supplier);
        $this->pdo->prepare('INSERT INTO user_suppliers (user_id,supplier_id) VALUES (?,?)')->execute([$this->user, $this->otherSupplier]);
        $this->pdo->prepare('DELETE FROM user_suppliers WHERE user_id=? AND supplier_id=?')->execute([$this->user, $this->supplier]);
        self::assertSame(401, $this->rpc($grant['access_token'], 'initialize')->getStatusCode());
        self::assertNull(Bootstrap::buildContainer()->get(McpOAuth::class)->refresh($grant['refresh_token'], $this->client));
    }

    public function testStaffOnCanonicalAddressKeepsCrossCompanyGrantViaTokensPermission(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->pdo->prepare("INSERT INTO roles (name, role_type, is_active) VALUES (?, 'staff', 1)")->execute(['MCP interní ' . $suffix]);
        $staffRole = (int) $this->pdo->lastInsertId();
        $this->extraRoles[] = $staffRole;
        // Předvaha (`accounting`) není v klientském katalogu: u zaměstnance prokazuje
        // zaměstnanecký povrch. Bez práva by ji filtr podle role správně skryl.
        $this->pdo->prepare("INSERT INTO role_permissions VALUES (?, 'profile', 1), (?, 'profile.tokens', 1), (?, 'invoices', 1), (?, 'accounting', 1)")
            ->execute([$staffRole, $staffRole, $staffRole, $staffRole]);
        $staff = $this->createUser('mcp-staff-' . $suffix . '@example.test', 'MCP interní', $staffRole);
        $this->extraUsers[] = $staff;
        $this->pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id) VALUES (?, ?)')->execute([$staff, $this->supplier]);
        $this->session = Bootstrap::buildContainer()->get(SessionManager::class)->create($staff, '127.0.0.1', 'synthetic-mcp');

        $params = $this->params();
        $consent = $this->request('GET', $this->canonical . '/oauth/authorize?' . http_build_query($params), session: true);
        self::assertSame(200, $consent->getStatusCode(), (string) $consent->getBody());
        self::assertStringContainsString('včetně těch přidaných později', (string) $consent->getBody());
        self::assertStringNotContainsString('grant_supplier_id', (string) $consent->getBody());
        $token = $this->approve($params);
        $row = Bootstrap::buildContainer()->get(ApiTokenService::class)->validate($token['access_token']);
        self::assertNull($row['supplier_id']);
        $names = array_column(self::json($this->rpc($token['access_token'], 'tools/list'))['result']['tools'], 'name');
        self::assertContains('trial_balance', $names);
        self::assertNotContains('aging_receivables', $names);
        self::assertIsArray(Bootstrap::buildContainer()->get(McpOAuth::class)->refresh($token['refresh_token'], $this->client));
    }

    public function testClientPageShowsCanonicalOrDomainAddressAndRevokesInBothPlaces(): void
    {
        $settings = $this->request('GET', $this->canonical . '/api/mcp/settings', session: true);
        self::assertSame(200, $settings->getStatusCode(), (string) $settings->getBody());
        self::assertSame($this->canonical . '/mcp', self::json($settings)['endpoint']);

        $origin = 'https://mcp-kan-' . bin2hex(random_bytes(5)) . '.example.test';
        $this->hostnames[] = substr($origin, 8);
        $this->pdo->prepare("INSERT INTO supplier_domains (supplier_id, hostname, purpose, status, verification_token, verified_at) VALUES (?, ?, 'portal', 'active', ?, NOW())")
            ->execute([$this->supplier, substr($origin, 8), bin2hex(random_bytes(32))]);
        $settings = $this->request('GET', $this->canonical . '/api/mcp/settings', session: true);
        self::assertSame($origin . '/mcp', self::json($settings)['endpoint']);

        $first = $this->grantFor($this->supplier);
        $second = $this->grantFor($this->supplier);
        $ids = array_map('intval', $this->pdo->query("SELECT id FROM mcp_oauth_grants WHERE user_id={$this->user} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertCount(2, $ids);
        self::assertSame(200, $this->request('DELETE', $this->canonical . '/api/mcp/grants/' . $ids[0], session: true)->getStatusCode());
        self::assertNull(Bootstrap::buildContainer()->get(ApiTokenService::class)->validate($first['access_token']));

        $this->pdo->prepare('UPDATE sessions SET supplier_domain_id=(SELECT id FROM supplier_domains WHERE hostname=?) WHERE id=?')
            ->execute([substr($origin, 8), $this->session['token']]);
        $list = $this->request('GET', $origin . '/api/mcp/grants', session: true);
        self::assertSame(200, $list->getStatusCode(), (string) $list->getBody());
        self::assertContains($ids[1], array_column(self::json($list)['grants'], 'id'));
        self::assertSame(200, $this->request('DELETE', $origin . '/api/mcp/grants/' . $ids[1], session: true)->getStatusCode());
        self::assertNull(Bootstrap::buildContainer()->get(ApiTokenService::class)->validate($second['access_token']));
    }

    private function createUser(string $email, string $name, int $role): int
    {
        $this->pdo->prepare('INSERT INTO users (email, password_hash, name, role_id, is_active) VALUES (?, ?, ?, ?, 1)')
            ->execute([$email, password_hash('synthetic-mcp-password', PASSWORD_DEFAULT), $name, $role]);
        return (int) $this->pdo->lastInsertId();
    }

    private function params(): array
    {
        return ['client_id' => $this->client, 'redirect_uri' => 'https://assistant.example.test/callback', 'response_type' => 'code',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'resource' => $this->canonical . '/mcp', 'scope' => 'read'];
    }

    private function approve(array $params): array
    {
        $approved = $this->request('POST', $this->canonical . '/oauth/authorize', $params + [
            'csrf_token' => $this->session['csrf_token'], 'decision' => 'approve', 'grant_scope' => 'read',
        ], session: true, form: true);
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        self::assertSame(1, preg_match('/id="mcp-oauth-continue"[^>]+href="([^"]+)"/', (string) $approved->getBody(), $match));
        parse_str((string) parse_url(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), PHP_URL_QUERY), $query);
        $exchange = $this->request('POST', $this->canonical . '/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $this->client, 'code' => $query['code'],
            'code_verifier' => str_repeat('a', 64), 'redirect_uri' => $params['redirect_uri'], 'resource' => $params['resource'],
        ], form: true);
        self::assertSame(200, $exchange->getStatusCode(), (string) $exchange->getBody());
        return self::json($exchange);
    }

    private function exchange(?int $supplier): ?array
    {
        $oauth = Bootstrap::buildContainer()->get(McpOAuth::class);
        $p = $this->params();
        $code = $oauth->createCode($this->client, $this->user, $supplier, $p['redirect_uri'], $p['code_challenge'], 'read', $p['resource']);
        return $oauth->exchange($code, $this->client, $p['redirect_uri'], str_repeat('a', 64), $p['resource']);
    }

    private function grantFor(?int $supplier): array
    {
        $grant = $this->exchange($supplier);
        self::assertIsArray($grant);
        return $grant;
    }

    private function rpc(string $token, string $method, array $params = []): ResponseInterface
    {
        return $this->request('POST', $this->canonical . '/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ?: new \stdClass()], $token);
    }

    private function request(string $method, string $url, array $body = [], ?string $token = null, bool $session = false, bool $form = false): ResponseInterface
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, $url, ['REMOTE_ADDR' => '127.0.0.1']);
        if ($token !== null) $r = $r->withHeader('Authorization', 'Bearer ' . $token);
        if ($session) $r = $r->withCookieParams([$this->cookie => $this->session['token']])->withHeader('Origin', $r->getUri()->getScheme() . '://' . $r->getUri()->getAuthority())
            ->withHeader('X-CSRF-Token', $this->session['csrf_token']);
        if ($method === 'POST') $r = $r->withHeader('Content-Type', $form ? 'application/x-www-form-urlencoded' : 'application/json')
            ->withParsedBody($body)->withBody((new StreamFactory())->createStream($form ? http_build_query($body) : json_encode($body, JSON_THROW_ON_ERROR)));
        return $this->handle($r);
    }

    /** Každý požadavek má čerstvý kontejner jako v provozu; pošta jde vždy do mocku. */
    private function handle(ServerRequestInterface $request): ResponseInterface
    {
        $app = Bootstrap::buildApp();
        $app->getContainer()->set(Mailer::class, $this->createStub(Mailer::class));
        return $app->handle($request);
    }

    private static function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
