<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Portfolio;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Portfolio\GroupDashboardAccess;
use MyInvoice\Service\Portfolio\GroupDashboardCompanyReader;
use MyInvoice\Service\Portfolio\GroupDashboardService;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use MyInvoice\Service\Tenant\TenantDomainContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;

#[Group('integration')]
final class GroupDashboardAccessTest extends TestCase
{
    private Connection $db;
    private int $userId;
    private int $fullRole;
    private int $limitedRole;
    private int $hiddenRole;
    private array $suppliers = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) $this->markTestSkipped('Requires local test DB configuration.');
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->fullRole = $this->role(['dashboard.portfolio', 'invoices', 'purchase_invoices', 'accounting', 'bank', 'cash', 'other_items']);
        $this->limitedRole = $this->role(['dashboard.portfolio', 'invoices']);
        $this->hiddenRole = $this->role(['invoices']);
        $pdo->prepare("INSERT INTO users (email, password_hash, name, role_id, locale, is_active) VALUES (?, 'disabled-test-password', 'Synthetic dashboard user', ?, 'cs', 1)")
            ->execute(['group-dashboard-' . bin2hex(random_bytes(6)) . '@example.invalid', $this->fullRole]);
        $this->userId = (int) $pdo->lastInsertId();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source, 'Test database must have a synthetic supplier baseline.');
        $insert = $pdo->prepare("INSERT INTO supplier
            (company_name, display_name, street, city, zip, country_id, is_vat_payer, email,
             default_currency_id, default_vat_rate_id, accounting_mode)
            SELECT ?, ?, 'Synthetic street', 'Synthetic city', '00000', country_id, 0, 'synthetic@example.invalid',
                   default_currency_id, default_vat_rate_id, 'double_entry' FROM supplier WHERE id = ?");
        for ($i = 1; $i <= 50; $i++) {
            $insert->execute(['Synthetic group ' . $i, 'Synthetic group ' . $i, $source]);
            $this->suppliers[] = (int) $pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testReadOnlyMembershipAtTenAndFiftyCompaniesDoesNotIncludeOtherCompanies(): void
    {
        $this->assign(array_slice($this->suppliers, 0, 10));
        self::assertSame(array_slice($this->suppliers, 0, 10), $this->ids($this->access()->companies($this->request())));
        $this->assign(array_slice($this->suppliers, 10));
        self::assertSame($this->suppliers, $this->ids($this->access()->companies($this->request())));
        $this->db->pdo()->prepare('DELETE FROM user_suppliers WHERE user_id = ? AND supplier_id = ?')
            ->execute([$this->userId, $this->suppliers[1]]);
        $remaining = $this->ids($this->access()->companies($this->request()));
        self::assertCount(49, $remaining);
        self::assertNotContains($this->suppliers[1], $remaining);
        $this->db->pdo()->prepare('DELETE FROM user_suppliers WHERE user_id = ?')->execute([$this->userId]);
        self::assertSame([], $this->access()->companies($this->request()));
    }

    public function testOverrideRecomputesRightsDespiteOriginalStrongRoleAndResolvedSupplier(): void
    {
        $this->assign([$this->suppliers[0]]);
        $this->assign([$this->suppliers[1]], $this->limitedRole);
        $this->assign([$this->suppliers[2]], $this->hiddenRole);
        $companies = $this->access()->companies($this->request()
            ->withAttribute('auth.effective_role', new EffectiveRole($this->fullRole, 'Strong original', 'staff', true,
                ['dashboard.portfolio' => 2, 'bank' => 2, 'accounting' => 2]))
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->suppliers[0])
            ->withHeader(SupplierScopeMiddleware::HEADER_NAME, (string) $this->suppliers[49])
            ->withQueryParams(['supplier_id' => $this->suppliers[49]]));
        self::assertSame([$this->suppliers[0], $this->suppliers[1]], $this->ids($companies));
        self::assertTrue(RequestAuthorization::allows($companies[0]['request'], 'bank'));
        self::assertTrue(RequestAuthorization::allows($companies[0]['request'], 'accounting'));
        self::assertFalse(RequestAuthorization::allows($companies[1]['request'], 'bank'));
        self::assertFalse(RequestAuthorization::allows($companies[1]['request'], 'accounting'));
        self::assertSame($this->suppliers[1], $companies[1]['request']->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID));
        $reader = Bootstrap::buildContainer()->get(GroupDashboardCompanyReader::class);
        $limited = $reader->read($companies[1]['request'], $companies[1]['supplier'], 'balances', 12, 8);
        self::assertArrayNotHasKey('bank', $limited);
        self::assertArrayNotHasKey('cash', $limited);
        $full = $reader->read($companies[0]['request'], $companies[0]['supplier'], 'balances', 12, 8);
        self::assertSame([], $full['bank']);
        self::assertSame([], $full['cash']);
        self::assertSame([], $full['issues']);
    }

    public function testTokenBindingAndCustomDomainCannotExpandScopeIncludingSuperadmin(): void
    {
        $this->assign($this->suppliers);
        $bound = $this->request()->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['supplier_id' => $this->suppliers[1]]);
        self::assertSame([$this->suppliers[1]], $this->ids($this->access()->companies($bound)));
        $domain = new TenantDomainContext(TenantDomainContext::CUSTOM, 'synthetic.example.invalid', 'https://synthetic.example.invalid', 1, $this->suppliers[0], 'all', 'active');
        self::assertSame([$this->suppliers[0]], $this->ids($this->access()->companies($this->request()->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, $domain))));
        self::assertSame([], $this->access()->companies($bound->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, $domain)));
        self::assertSame([$this->suppliers[0]], $this->ids($this->access()->companies($this->request(true)->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, $domain))));
        $this->db->pdo()->prepare('DELETE FROM user_suppliers WHERE user_id = ? AND supplier_id = ?')->execute([$this->userId, $this->suppliers[1]]);
        self::assertSame([], $this->access()->companies($bound));
    }

    public function testEverySectionUsesExistingSourcesWithCompanyScopedRights(): void
    {
        $this->assign([$this->suppliers[0]]);
        $this->assign([$this->suppliers[1]], $this->limitedRole);
        $this->db->pdo()->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
            VALUES (?, 'EUR', 'Synthetic EUR', 'EUR', 'Synthetic EUR', 'Synthetic EUR', 2, 1, 1)")->execute([$this->suppliers[0]]);
        $service = Bootstrap::buildContainer()->get(GroupDashboardService::class);
        self::assertSame(realpath(dirname(__DIR__, 4) . '/api/src/Service/Portfolio/GroupDashboardService.php'),
            realpath((new \ReflectionClass($service))->getFileName()));
        foreach (GroupDashboardService::SECTIONS as $section) {
            $result = $service->dashboard($this->request(), $section, 12, 8);
            self::assertSame(2, $result['company_count'], $section);
            self::assertEqualsCanonicalizing([$this->suppliers[0], $this->suppliers[1]], array_column($result['companies'], 'id'), $section);
            foreach ($result['companies'] as $company) self::assertSame([], $company['issues'], $section);
            $full = array_values(array_filter($result['companies'], fn (array $company): bool => $company['id'] === $this->suppliers[0]))[0];
            $limited = array_values(array_filter($result['companies'], fn (array $company): bool => $company['id'] === $this->suppliers[1]))[0];
            if ($section === 'cashflow') {
                self::assertSame('EUR', $full['cashflow'][0]['currency']);
                self::assertCount(8, $full['cashflow'][0]['weeks']);
                self::assertArrayNotHasKey('cashflow', $limited);
            }
            if ($section === 'overview') {
                self::assertSame([], $full['financial']);
                self::assertNull($full['accounting']);
                self::assertArrayNotHasKey('financial', $limited);
                self::assertArrayNotHasKey('accounting', $limited);
            }
        }
    }

    public function testExactDocumentPeriodAndPreviousLeapDatesAreFinanciallyBounded(): void
    {
        $this->assign([$this->suppliers[0]]);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
            VALUES (?, 'EUR', 'Synthetic EUR', 'EUR', 'Synthetic EUR', 'Synthetic EUR', 2, 1, 1)")->execute([$this->suppliers[0]]);
        $currency = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
            SELECT ?, 'Synthetic period client', 'Synthetic street', 'Synthetic city', '00000', country_id, 'synthetic@example.invalid', ?
            FROM supplier WHERE id = ?")->execute([$this->suppliers[0], $currency, $this->suppliers[0]]);
        $client = (int) $pdo->lastInsertId();
        $invoice = $pdo->prepare("INSERT INTO invoices
            (supplier_id, client_id, issue_date, tax_date, due_date, currency_id, status, total_without_vat, total_with_vat, created_by, exchange_rate)
            VALUES (?, ?, ?, ?, ?, ?, 'issued', ?, ?, ?, 25)");
        foreach ([['2024-02-28', 1000], ['2024-02-29', 100], ['2024-03-02', 20], ['2024-03-03', 2000],
            ['2023-02-28', 50], ['2023-03-02', 10], ['2023-03-03', 3000]] as [$date, $amount]) {
            $invoice->execute([$this->suppliers[0], $client, $date, $date, $date, $currency, $amount, $amount, $this->userId]);
        }
        $invoice->execute([$this->suppliers[1], $client, '2024-03-01', '2024-03-01', '2024-03-01', $currency, 99999, 99999, $this->userId]);
        $pdo->prepare("INSERT INTO purchase_invoices
            (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date, received_at,
             currency_id, vendor_snapshot, status, total_without_vat, total_with_vat, created_by, exchange_rate)
            VALUES (?, ?, 'SYNTHETIC-PERIOD', 'invoice', '2024-03-01', '2024-02-29', '2024-03-01', '2024-03-01', ?, '{}', 'received', 30, 30, ?, 25)")
            ->execute([$this->suppliers[0], $client, $currency, $this->userId]);
        $service = Bootstrap::buildContainer()->get(GroupDashboardService::class);
        $result = $service->dashboard($this->request(), 'overview', 12, 8, '2024-02-29', '2024-03-02');
        self::assertSame(['from' => '2024-02-29', 'to' => '2024-03-02', 'previous_from' => '2023-02-28', 'previous_to' => '2023-03-02', 'mode' => 'custom'], $result['period']);
        self::assertSame([], $result['companies'][0]['issues']);
        $financial = $result['companies'][0]['financial'][0];
        self::assertSame('EUR', $financial['currency']);
        self::assertEquals(120, $financial['revenue']);
        self::assertEquals(30, $financial['costs']);
        self::assertEquals(90, $financial['profit']);
        self::assertEquals(60, $financial['previous_revenue']);
        self::assertEquals(3000, $result['converted_czk']['companies'][0]['financial'][0]['revenue']);
        self::assertEquals(1500, $result['converted_czk']['companies'][0]['financial'][0]['previous_revenue']);
        $pdo->prepare('UPDATE invoices SET exchange_rate = NULL WHERE supplier_id = ? AND tax_date = ?')
            ->execute([$this->suppliers[0], '2024-02-29']);
        $partial = $service->dashboard($this->request(), 'overview', 12, 8, '2024-02-29', '2024-03-02');
        self::assertEquals(120, $partial['companies'][0]['financial'][0]['revenue']);
        self::assertEquals(500, $partial['converted_czk']['companies'][0]['financial'][0]['revenue']);
        self::assertContains('conversion.financial', $partial['converted_czk']['companies'][0]['issues']);
        self::assertContains('EUR', $partial['converted_czk']['missing_currencies']);
        $pdo->prepare('UPDATE invoices SET exchange_rate = NULL WHERE supplier_id = ? AND tax_date = ?')
            ->execute([$this->suppliers[0], '2024-03-02']);
        $unknown = $service->dashboard($this->request(), 'overview', 12, 8, '2024-02-29', '2024-03-02');
        self::assertNull($unknown['converted_czk']['companies'][0]['financial'][0]['revenue']);
        $monthly = $service->dashboard($this->request(), 'trends', 12, 8, '2024-02-29', '2024-03-02')['companies'][0]['monthly'];
        self::assertSame(['2024-02', '2024-03'], array_column($monthly, 'period'));
        self::assertEquals([100, 20], array_column($monthly, 'revenue'));
        self::assertEquals([0, 30], array_column($monthly, 'costs'));
        self::assertEquals($financial['revenue'], array_sum(array_column($monthly, 'revenue')));
    }

    public function testInvalidOverrideFailsClosedAndSuperadminOnlyEnumeratesRealCompanies(): void
    {
        $inactive = $this->role(['dashboard.portfolio'], 'staff', false);
        $client = $this->role(['invoices'], 'client');
        $this->assign([$this->suppliers[0]], $inactive);
        $this->assign([$this->suppliers[1]], $client);
        self::assertSame([], $this->access()->companies($this->request()));
        $actual = array_map('intval', $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame($actual, $this->ids($this->access()->companies($this->request(true))));
    }

    private function role(array $keys, string $type = 'staff', bool $active = true): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO roles (name, role_type, is_active) VALUES (?, ?, ?)')->execute(['Synthetic group role', $type, (int) $active]);
        $id = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)');
        foreach ($keys as $key) $insert->execute([$id, $key, AccessLevel::READ->value]);
        return $id;
    }

    private function assign(array $ids, ?int $role = null): void
    {
        $insert = $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)');
        foreach ($ids as $id) $insert->execute([$this->userId, $id, $role]);
    }

    private function access(): GroupDashboardAccess
    {
        $memberships = new UserSupplierRepository($this->db);
        $suppliers = new SupplierAccessResolver($this->db, $memberships);
        return new GroupDashboardAccess($this->db, $memberships, $suppliers, new PermissionResolver($this->db, $suppliers, new PermissionCatalog()));
    }

    private function request(bool $superadmin = false): Request
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/portfolio/group-dashboard')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role_id' => $this->fullRole,
                'is_superadmin' => $superadmin, 'role_summary' => ['type' => $superadmin ? 'superadmin' : 'staff']])
            ->withAttribute('auth.effective_role', new EffectiveRole($this->fullRole, 'Synthetic request role',
                $superadmin ? 'superadmin' : 'staff', true, ['dashboard.portfolio' => 1], $superadmin ? 'superadmin' : null));
    }

    private function ids(array $companies): array
    {
        return array_map(static fn (array $company): int => (int) $company['supplier']['id'], $companies);
    }
}
