<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Mcp\HostedMcpEndpointAction;
use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\PermissionMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Mcp\HostedMcp;
use MyInvoice\Service\Mcp\NodeBridge;
use MyInvoice\Service\Mcp\StaffMcpToolPolicy;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use MyInvoice\Service\Tenant\TenantUrlResolver;
use MyInvoice\Service\Update\NativeUpdateService;
use MyInvoice\Service\Update\VersionService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Nabídka serverového MCP pro interní role nad skutečnou DB (role, členství,
 * per-firemní role) a skutečnou mapou rout. Data žijí jen v transakci testu.
 */
final class StaffMcpToolPolicyTest extends TestCase
{
    private const MANIFEST = __DIR__ . '/../../../MCP/src/tool-routes.generated.json';
    /** Incident: role pro fakturaci má přehled, ale ne přehled firem (dashboard.portfolio). */
    private const INVOICING = [
        'profile' => 2, 'profile.mcp' => 2, 'dashboard' => 1, 'clients' => 2,
        'invoices' => 2, 'invoices.create' => 2, 'purchase_invoices' => 2, 'documents' => 2,
    ];

    private Connection $db;
    private UserSupplierRepository $memberships;
    private PermissionCatalog $catalog;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->db = new Connection(Config::load(dirname(__DIR__, 3)));
        $this->db->pdo()->beginTransaction();
        $this->memberships = new UserSupplierRepository($this->db);
        $this->catalog = new PermissionCatalog();
        putenv('MYINVOICE_MCP_ENABLED=1');
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        foreach ($this->tempFiles as $path) @unlink($path);
        putenv('MYINVOICE_MCP_ENABLED');
    }

    public function testInvoicingRoleWithoutPortfolioDoesNotGetAgingTools(): void
    {
        $user = $this->user($this->role(self::INVOICING), [1 => null]);
        $allowed = $this->policy()->allowed($this->request($user));

        self::assertNotNull($allowed);
        foreach (['aging_receivables', 'aging_payables', 'portfolio_overview', 'trial_balance'] as $hidden) {
            self::assertNotContains($hidden, $allowed);
        }
        foreach (['whoami', 'list_suppliers', 'list_unpaid_invoices', 'list_purchase_invoices', 'list_invoices',
            'create_invoice', 'dashboard_summary'] as $visible) {
            self::assertContains($visible, $allowed);
        }
        self::assertLessThan(count($this->manifest()), count($allowed));
    }

    public function testPortfolioReadAddsAgingTools(): void
    {
        $user = $this->user($this->role(self::INVOICING + ['dashboard.portfolio' => 1]), [1 => null]);
        $allowed = $this->policy()->allowed($this->request($user));

        self::assertContains('aging_receivables', $allowed);
        self::assertContains('aging_payables', $allowed);
    }

    public function testCompanyAdminAndSuperadminKeepFullCatalog(): void
    {
        $policy = $this->policy();
        self::assertNull($policy->allowed($this->request($this->user(5, [1 => null]))));
        self::assertNull($policy->allowed($this->request($this->user(6, [1 => null, 2 => null]))));
        self::assertNull($policy->allowed($this->request($this->user(1, []))));
        // Správce v jedné z firem: nabídka se neomezí, práva v ostatních firmách ověří API.
        self::assertNull($policy->allowed($this->request($this->user($this->role(self::INVOICING), [1 => null, 2 => 5]))));
        self::assertTrue($policy->allows($this->request($this->user(5, [1 => null])), 'aging_receivables'));
    }

    public function testPerCompanyRolesAreUnitedAcrossAccessibleCompanies(): void
    {
        $portfolioOnly = $this->role(['profile' => 2, 'profile.mcp' => 2, 'dashboard' => 1, 'dashboard.portfolio' => 1]);
        $user = $this->user($this->role(self::INVOICING), [1 => null, 2 => $portfolioOnly]);
        $allowed = $this->policy()->allowed($this->request($user));
        self::assertContains('aging_receivables', $allowed);
        self::assertContains('list_unpaid_invoices', $allowed);

        // Token vázaný na firmu bez přehledu firem aging nástroje neukáže.
        $bound = $this->policy()->allowed($this->request($user, ['supplier_id' => 1]));
        self::assertNotContains('aging_receivables', $bound);
        self::assertContains('list_unpaid_invoices', $bound);
        $boundPortfolio = $this->policy()->allowed($this->request($user, ['supplier_id' => 2]));
        self::assertContains('aging_receivables', $boundPortfolio);
        self::assertNotContains('list_unpaid_invoices', $boundPortfolio);
    }

    public function testToolNeedsAllItsRoutesWithinOneCompany(): void
    {
        $manifest = $this->tempManifest(['synthetic' => ['write' => false, 'api' => [
            ['GET', '/api/invoices'], ['GET', '/api/crm/aging-receivables'],
        ]], 'local' => ['write' => false, 'api' => []]]);
        $invoicesOnly = $this->role(['profile.mcp' => 2, 'invoices' => 1]);
        $portfolioOnly = $this->role(['profile.mcp' => 2, 'dashboard' => 1, 'dashboard.portfolio' => 1]);
        $split = $this->user($invoicesOnly, [1 => null, 2 => $portfolioOnly]);
        self::assertSame(['local'], $this->policy($manifest)->allowed($this->request($split)));

        $both = $this->user($this->role(['profile.mcp' => 2, 'invoices' => 1, 'dashboard' => 1, 'dashboard.portfolio' => 1]), [1 => null]);
        self::assertSame(['synthetic', 'local'], $this->policy($manifest)->allowed($this->request($both)));
    }

    public function testUserWithoutCompanyGetsOnlyToolsWithoutCompanyRoutes(): void
    {
        $allowed = $this->policy()->allowed($this->request($this->user($this->role(self::INVOICING), [])));
        self::assertContains('whoami', $allowed);
        self::assertContains('list_suppliers', $allowed);
        self::assertNotContains('list_invoices', $allowed);
    }

    public function testBrokenManifestKeepsPreviousUnfilteredCatalog(): void
    {
        $request = $this->request($this->user($this->role(self::INVOICING), [1 => null]));
        foreach (['{', '[]', '{"x":{"write":false,"api":[["GET","/settings"]]}}'] as $json) {
            $path = $this->tempFile($json);
            self::assertNull($this->policy($path)->allowed($request), $json);
            self::assertTrue($this->policy($path)->allows($request, 'aging_receivables'), $json);
        }
        self::assertNull($this->policy('/nonexistent/tool-routes.json')->allowed($request));
    }

    public function testSingleToolDecisionMatchesTheList(): void
    {
        $request = $this->request($this->user($this->role(self::INVOICING), [1 => null]));
        $policy = $this->policy();
        $allowed = $policy->allowed($request);
        foreach (array_keys($this->manifest()) as $name) {
            self::assertSame(in_array($name, $allowed, true), $policy->allows($request, $name), $name);
        }
        self::assertFalse($policy->allows($request, 'neexistujici_nastroj'));
    }

    /**
     * Rozhodnutí o každé routě manifestu se musí shodovat s PermissionMiddleware,
     * které API při volání skutečně vynucuje. Jinak by nabídka skryla použitelný
     * nástroj, nebo nabízela nástroj, který skončí 403.
     */
    public function testRouteDecisionsMatchPermissionMiddleware(): void
    {
        $operations = [];
        foreach ($this->manifest() as $contract) {
            foreach ($contract['api'] as $operation) $operations[implode(' ', $operation)] = $operation;
        }
        ksort($operations);
        $perOperation = [];
        foreach (array_values($operations) as $index => $operation) {
            $perOperation['op_' . $index] = ['write' => $operation[0] !== 'GET', 'api' => [$operation]];
        }
        $manifest = $this->tempManifest($perOperation);
        $roles = [
            'fakturace' => $this->role(self::INVOICING),
            'readonly' => 3,
            'accountant' => 2,
            'portfolio' => $this->role(['profile.mcp' => 2, 'dashboard' => 1, 'dashboard.portfolio' => 1, 'reports' => 1]),
        ];
        foreach ($roles as $label => $roleId) {
            $user = $this->user($roleId, [1 => null]);
            $request = $this->request($user);
            $allowed = $this->policy($manifest)->allowed($request);
            self::assertNotNull($allowed, $label);
            $middleware = $this->middleware();
            foreach ($perOperation as $name => $contract) {
                [$method, $template] = $contract['api'][0];
                $path = (string) preg_replace('/\{[a-z_]+\}/', '1', $template);
                $status = $middleware->process(
                    $request->withMethod($method)->withUri($request->getUri()->withPath($path))
                        ->withHeader(SupplierScopeMiddleware::HEADER_NAME, '1'),
                    new class implements RequestHandlerInterface {
                        public function handle(ServerRequestInterface $request): ResponseInterface
                        {
                            return (new ResponseFactory())->createResponse(200);
                        }
                    },
                )->getStatusCode();
                self::assertSame($status === 200, in_array($name, $allowed, true), "$label: $method $template");
            }
        }
    }

    public function testHostedListHidesToolsAndHiddenCallFailsLikeUnknownTool(): void
    {
        $action = $this->endpoint();
        $invoicing = $this->user($this->role(self::INVOICING), [1 => null]);

        $list = $this->rpc($action, $invoicing, 'tools/list');
        $names = array_column($list['result']['tools'], 'name');
        self::assertNotContains('aging_receivables', $names);
        self::assertNotContains('aging_payables', $names);
        self::assertContains('list_unpaid_invoices', $names);
        self::assertContains('list_purchase_invoices', $names);
        self::assertNotContains('create_invoice', $names, 'Čtecí token dál skrývá zápisové nástroje.');
        self::assertContains('create_invoice', array_column(
            $this->rpc($action, $invoicing, 'tools/list', [], 'read_write')['result']['tools'], 'name'));

        foreach (['aging_receivables', 'neexistujici_nastroj'] as $name) {
            $call = $this->rpc($action, $invoicing, 'tools/call', ['name' => $name, 'arguments' => ['supplier_id' => 1]]);
            self::assertTrue($call['result']['isError'], $name);
            self::assertSame('Pro tento nástroj nemáte oprávnění.', $call['result']['content'][0]['text'], $name);
        }

        $admin = $this->rpc($action, $this->user(5, [1 => null]), 'tools/list');
        $unfiltered = $this->rpc($this->endpoint(false), $invoicing, 'tools/list');
        self::assertSame(array_column($unfiltered['result']['tools'], 'name'), array_column($admin['result']['tools'], 'name'));
        self::assertContains('aging_receivables', array_column($admin['result']['tools'], 'name'));
    }

    public function testClientSurfaceIgnoresStaffPolicy(): void
    {
        $user = $this->user($this->role(self::INVOICING), [1 => null]);
        // Bez klientské politiky zůstává klientský povrch zavřený jako dosud; staff filtr ho neotevře.
        $list = $this->rpc($this->endpoint(), $user, 'tools/list', [], 'read', ['mcp_client_surface' => true]);
        self::assertSame([], $list['result']['tools']);
    }

    /** @param array<string,int> $permissions */
    private function role(array $permissions): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO roles (name, role_type, is_active) VALUES (?, ?, 1)')
            ->execute(['__TEST MCP role ' . bin2hex(random_bytes(4)), 'staff']);
        $roleId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)');
        foreach ($permissions as $key => $level) $insert->execute([$roleId, $key, $level]);
        return $roleId;
    }

    /**
     * @param array<int, ?int> $companies supplier_id => per-firemní role (NULL = výchozí)
     * @return array<string, mixed> atribut uživatele jako z AuthMiddleware
     */
    private function user(int $roleId, array $companies): array
    {
        $pdo = $this->db->pdo();
        $role = $pdo->prepare('SELECT id, system_key, name, role_type FROM roles WHERE id = ?');
        $role->execute([$roleId]);
        $summary = $role->fetch(\PDO::FETCH_ASSOC);
        $pdo->prepare('INSERT INTO users (email, password_hash, name, role_id, locale, is_active) VALUES (?, ?, ?, ?, ?, 1)')
            ->execute(['__test_mcp_' . bin2hex(random_bytes(6)) . '@example.test',
                '$2y$12$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', '__TEST MCP', $roleId, 'cs']);
        $userId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)');
        foreach ($companies as $supplierId => $override) $insert->execute([$userId, $supplierId, $override]);
        return [
            'id' => $userId, 'role_id' => $roleId, 'is_superadmin' => $summary['role_type'] === 'superadmin',
            'role_summary' => ['id' => $roleId, 'type' => $summary['role_type'], 'system_key' => $summary['system_key'],
                'name' => $summary['name']],
        ];
    }

    private function request(array $user, array $token = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', 'https://example.test/mcp')
            ->withHeader('Authorization', 'Bearer mi_pat_synthetic')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_USER, $user)
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, $token + ['scope' => 'read']);
    }

    private function policy(string $manifest = self::MANIFEST): StaffMcpToolPolicy
    {
        return new StaffMcpToolPolicy($this->resolver(), new PermissionChecker($this->catalog),
            new RoutePermissionMap(), $this->memberships, $manifest);
    }

    private function resolver(): PermissionResolver
    {
        return new PermissionResolver($this->db, new SupplierAccessResolver($this->db, $this->memberships), $this->catalog);
    }

    private function middleware(): PermissionMiddleware
    {
        return new PermissionMiddleware(new ResponseFactory(), new RoutePermissionMap(), $this->resolver(),
            new PermissionChecker($this->catalog), new SupplierAccessResolver($this->db, $this->memberships));
    }

    private function endpoint(bool $withStaffPolicy = true): HostedMcpEndpointAction
    {
        $config = Config::load(dirname(__DIR__, 3));
        $hosted = new HostedMcp($this->db,
            new TenantUrlResolver($config, new SupplierDomainRepository($this->db, EntityCache::disabled())),
            new ManagedModeGuard($config));
        return new HostedMcpEndpointAction($hosted, new NodeBridge($hosted, $config),
            new VersionService($this->db, new NativeUpdateService()), new NullLogger(),
            $this->db, $this->memberships, null, $withStaffPolicy ? $this->policy() : null);
    }

    private function rpc(HostedMcpEndpointAction $action, array $user, string $method, array $params = [],
        string $scope = 'read', array $token = []): array
    {
        $request = $this->request($user, ['scope' => $scope] + $token);
        $request->getBody()->write(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method,
            'params' => $params === [] ? new \stdClass() : $params], JSON_THROW_ON_ERROR));
        $request->getBody()->rewind();
        $response = $action->handle($request, (new ResponseFactory())->createResponse());
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{write:bool, api:list<array{string,string}>}> */
    private function manifest(): array
    {
        return json_decode((string) file_get_contents(self::MANIFEST), true, 32, JSON_THROW_ON_ERROR);
    }

    private function tempManifest(array $contracts): string
    {
        return $this->tempFile(json_encode($contracts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mcp-routes-');
        file_put_contents($path, $content);
        return $this->tempFiles[] = $path;
    }
}
