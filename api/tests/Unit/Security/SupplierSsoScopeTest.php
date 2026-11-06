<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\CrossSupplierAuthorization;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\UserRoleProfile;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;
use MyInvoice\Service\Portfolio\GroupDashboardAccess;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SupplierSsoScopeTest extends TestCase
{
    private Connection $db;
    private LoginPolicyService $policy;
    private UserSupplierRepository $memberships;
    private SupplierAccessResolver $suppliers;
    private CrossSupplierAuthorization $cross;
    private GroupDashboardAccess $group;
    private Config $config;
    private PermissionResolver $permissions;

    protected function setUp(): void
    {
        $config = $this->config = new Config(['app' => ['url' => 'https://synthetic.example.invalid'], 'domains' => ['enabled' => true], 'auth' => ['oidc' => [
            'enabled' => true, 'break_glass_roles' => ['custom_emergency'],
            'providers' => ['entra' => ['enabled' => true,
                'tenant_id' => '11111111-1111-1111-1111-111111111111',
                'client_id' => '22222222-2222-2222-2222-222222222222', 'client_secret' => 'synthetic-unused',
            ]],
        ]]]);
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE supplier (id INTEGER PRIMARY KEY, company_name TEXT, display_name TEXT, accounting_mode TEXT, is_vat_payer INTEGER, login_policy TEXT, login_sso_provider TEXT);
            CREATE TABLE user_suppliers (user_id INTEGER, supplier_id INTEGER, role_id INTEGER);
            CREATE TABLE roles (id INTEGER PRIMARY KEY, system_key TEXT, name TEXT, role_type TEXT, is_active INTEGER);
            CREATE TABLE role_permissions (role_id INTEGER, permission_key TEXT, access_level INTEGER);
            CREATE TABLE users (id INTEGER PRIMARY KEY, role_id INTEGER);
            INSERT INTO users VALUES (10,2);
            INSERT INTO supplier VALUES (11,'Synthetic A',NULL,'double_entry',0,'any',NULL),
                (12,'Synthetic B',NULL,'double_entry',0,'sso_required','entra'),
                (13,'Synthetic C',NULL,'double_entry',0,'sso_required','entra');
            INSERT INTO user_suppliers VALUES (10,11,NULL),(10,12,NULL);
            INSERT INTO roles VALUES (2,'accountant','Synthetic staff','staff',1);
            INSERT INTO role_permissions VALUES (2,'documents.inbox',2),(2,'dashboard.portfolio',1),(2,'accounting',1);");
        $this->db = new Connection($config);
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($this->db, $pdo);
        $this->policy = new LoginPolicyService($this->db, $config, new OidcProviderRegistry($config));
        $this->memberships = new UserSupplierRepository($this->db);
        $this->suppliers = new SupplierAccessResolver($this->db, $this->memberships, $this->policy);
        $permissions = $this->permissions = new PermissionResolver($this->db, $this->suppliers, new PermissionCatalog());
        $this->cross = new CrossSupplierAuthorization($this->db, $this->memberships, $this->suppliers, $permissions, new UserRoleProfile($this->db));
        $this->group = new GroupDashboardAccess($this->db, $this->memberships, $this->suppliers, $permissions);
    }

    public static function authenticationMethods(): array
    {
        return [
            'password_session' => ['session', 'password', false],
            'entra_session' => ['session', 'oidc:entra', true],
            'password_token' => ['bearer', 'password', false],
            'entra_token' => ['bearer', 'oidc:entra', true],
            'missing_context' => [null, null, false],
        ];
    }

    #[DataProvider('authenticationMethods')]
    public function testCrossCompanyWriteRequiresTheTargetLoginPolicy(?string $method, ?string $origin, bool $allowed): void
    {
        $request = $this->request($method, $origin);
        self::assertSame($allowed, $this->cross->allowsIn($request, 12, ['documents.inbox']));
        self::assertTrue($this->cross->allowsIn($request, 11, ['documents.inbox']));
        self::assertFalse($this->cross->allowsIn($request, 13, ['documents.inbox']));
    }

    #[DataProvider('authenticationMethods')]
    public function testGroupDashboardIncludesOnlyCompaniesAllowedForThisAuthentication(?string $method, ?string $origin, bool $allowed): void
    {
        $companies = $this->group->companies($this->request($method, $origin));
        self::assertSame($allowed ? [11, 12] : [11], array_map(static fn (array $c): int => (int) $c['supplier']['id'], $companies));
    }

    public function testMembershipMemoCannotReuseAnEntraDecisionForPassword(): void
    {
        $password = $this->request('session', 'password')->withHeader('X-Supplier-Id', '12');
        $entra = $this->request('session', 'oidc:entra')->withHeader('X-Supplier-Id', '12');
        self::assertTrue($this->suppliers->resolve($password)->denied);
        self::assertFalse($this->suppliers->resolve($entra)->denied);
        self::assertTrue($this->suppliers->resolve($password)->denied);
    }

    public function testExplicitBackgroundAndConfiguredEmergencyRoleRemainAllowed(): void
    {
        self::assertTrue($this->cross->allowsIn($this->request('background', null), 12, ['documents.inbox']));
        $emergency = $this->request('session', 'password')->withAttribute(AuthMiddleware::ATTR_USER, [
            'id' => 10, 'role_id' => 2, 'role_summary' => ['type' => 'staff', 'system_key' => 'custom_emergency'],
        ]);
        self::assertTrue($this->cross->allowsIn($emergency, 12, ['documents.inbox']));
        self::assertFalse($this->cross->allowsIn($emergency, 13, ['documents.inbox']));
    }

    public function testBoundEntraTokenCannotExpandToAnotherCompany(): void
    {
        $request = $this->request('bearer', 'oidc:entra')->withAttribute(AuthMiddleware::ATTR_API_TOKEN, [
            'supplier_id' => 11, 'issued_auth_method' => 'oidc:entra',
        ]);
        self::assertFalse($this->cross->allowsIn($request, 12, ['documents.inbox']));
        self::assertSame([11], array_map(static fn (array $c): int => (int) $c['supplier']['id'], $this->group->companies($request)));
    }

    public function testPortfolioChecksTheRequestedCompanyLoginPolicy(): void
    {
        $portfolio = new \MyInvoice\Service\Portfolio\PortfolioAggregationService(
            $this->db, $this->memberships,
            (new \ReflectionClass(\MyInvoice\Service\Crm\CrmAggregationService::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(\MyInvoice\Service\Supplier\SupplierDirectory::class))->newInstanceWithoutConstructor(),
            $this->suppliers,
        );
        self::assertFalse($portfolio->userCanAccess(10, false, 12, null, $this->request('session', 'password')));
        self::assertTrue($portfolio->userCanAccess(10, false, 12, null, $this->request('session', 'oidc:entra')));
    }

    public function testAutomationFiltersTheTargetCompanyLoginPolicy(): void
    {
        $feed = new \MyInvoice\Service\Automation\AutomationFeedService(
            $this->db, $this->memberships,
            (new \ReflectionClass(\MyInvoice\Service\Accounting\Bank\BankRuleMatcher::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(\MyInvoice\Repository\BankPostingRuleRepository::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(\MyInvoice\Service\Accounting\Learning\LearningStatsProvider::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(\MyInvoice\Service\Accounting\Bank\BankPostingPreview::class))->newInstanceWithoutConstructor(),
            $this->suppliers,
        );
        self::assertSame([11], $feed->allowedSupplierIds(10, false, null, $this->request('session', 'password')));
        self::assertSame([11, 12], $feed->allowedSupplierIds(10, false, null, $this->request('session', 'oidc:entra')));
    }

    public function testSuperadminCannotJoinAGroupOutsideTheLoginPolicyScope(): void
    {
        $this->db->pdo()->exec("ALTER TABLE supplier ADD COLUMN supplier_group_id INTEGER;
            ALTER TABLE supplier ADD COLUMN ic TEXT;
            CREATE TABLE supplier_groups (id INTEGER PRIMARY KEY, name TEXT, created_at TEXT);
            INSERT INTO supplier_groups VALUES (100, 'Synthetic protected group', '2026-11-06');
            UPDATE supplier SET supplier_group_id = 100 WHERE id = 12;");
        $repo = new \MyInvoice\Repository\DimensionRepository($this->db);
        $serviceClass = \MyInvoice\Service\Accounting\Dimension\DimensionService::class;
        $dimensions = (new \ReflectionClass($serviceClass))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($serviceClass, 'repo'))->setValue($dimensions, $repo);
        $class = \MyInvoice\Action\Accounting\DimensionAction::class;
        $action = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach (['dimensions' => $dimensions, 'repo' => $repo, 'supplierAccess' => $this->suppliers,
            'logger' => $this->createStub(\MyInvoice\Service\ActivityLogger::class),
            'ipMatcher' => $this->createStub(\MyInvoice\Service\IpMatcher::class),
        ] as $name => $dependency) {
            (new \ReflectionProperty($class, $name))->setValue($action, $dependency);
        }
        $request = $this->request('session', 'password')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 10, 'is_superadmin' => true, 'role' => 'admin'])
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, 11)
            ->withParsedBody(['group_id' => 100]);
        $response = $action->updateGroup($request, (new \Slim\Psr7\Factory\ResponseFactory())->createResponse());
        self::assertSame(404, $response->getStatusCode());
        self::assertNull($repo->groupIdOf(11));
    }

    /**
     * Filtr firem v souhrnech jen zužuje to, co volající povolil podle členství:
     * povinné SSO cílové firmy a vazba požadavku platí, členství se znovu nevykládá.
     * Druhý výklad členství dřív tiše odřízl globálního správce i firmy bez SSO.
     */
    public function testSupplierFilterEnforcesLoginPolicyAndBindingWithoutReinterpretingMembership(): void
    {
        $this->db->pdo()->exec("INSERT INTO supplier VALUES (14,'Synthetic D',NULL,'double_entry',0,'any',NULL)");
        $admin = ['id' => 10, 'role' => 'admin'];
        $password = $this->request('session', 'password')->withAttribute(AuthMiddleware::ATTR_USER, $admin);
        $entra = $this->request('session', 'oidc:entra')->withAttribute(AuthMiddleware::ATTR_USER, $admin);

        self::assertSame([11, 14], $this->suppliers->filterAllowedSupplierIds($password, [11, 12, 13, 14]));
        self::assertSame([11, 12, 13, 14], $this->suppliers->filterAllowedSupplierIds($entra, [11, 12, 13, 14]));
        self::assertSame([11, 14], $this->suppliers->filterAllowedSupplierIds(
            $this->request('bearer', 'password')->withAttribute(AuthMiddleware::ATTR_USER, $admin), [11, 12, 13, 14],
        ));

        $bound = $entra->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['supplier_id' => 12, 'issued_auth_method' => 'oidc:entra'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer');
        self::assertSame([12], $this->suppliers->filterAllowedSupplierIds($bound, [11, 12, 13, 14]));

        $domain = $entra->withAttribute(\MyInvoice\Middleware\TenantDomainMiddleware::ATTR_CONTEXT, new \MyInvoice\Service\Tenant\TenantDomainContext(
            \MyInvoice\Service\Tenant\TenantDomainContext::CUSTOM, 'firma.synthetic.invalid', 'https://firma.synthetic.invalid', 1, 14,
        ));
        self::assertSame([14], $this->suppliers->filterAllowedSupplierIds($domain, [11, 12, 13, 14]));
        self::assertSame([], $this->suppliers->filterAllowedSupplierIds($password, [0, -1]));
    }

    #[DataProvider('authenticationMethods')]
    public function testCustomDomainMcpGrantUsesTheOriginalAuthentication(?string $method, ?string $origin, bool $allowed): void
    {
        $this->db->pdo()->exec("ALTER TABLE users ADD COLUMN is_active INTEGER DEFAULT 1;
            CREATE TABLE supplier_domains (hostname TEXT, supplier_id INTEGER, status TEXT, purpose TEXT);
            INSERT INTO supplier_domains VALUES ('mcp.synthetic.invalid', 12, 'active', 'all');
            INSERT INTO role_permissions VALUES (2, 'profile.tokens', 2);");
        $resources = new \MyInvoice\Service\Mcp\McpResourcePolicy($this->db, $this->config, $this->permissions);
        self::assertSame($allowed, $resources->allowsGrant('https://mcp.synthetic.invalid/mcp', 10, 12, 'read', $origin));
        self::assertSame($allowed, $resources->allowsGrant('https://mcp.synthetic.invalid/mcp', 10, 12, 'read_write', $origin));
    }

    private function request(?string $method, ?string $origin): Request
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/portfolio/group-dashboard')
            ->withHeader('X-Supplier-Id', '11')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 10, 'role_id' => 2, 'role_summary' => ['type' => 'staff', 'system_key' => 'accountant'],
            ])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method)
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['auth_method' => $origin])
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, $method === 'bearer' ? ['supplier_id' => null, 'issued_auth_method' => $origin] : null);
    }
}
