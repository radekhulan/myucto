<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Mcp\ClientMcpToolPolicy;
use MyInvoice\Service\Tenant\ClientRoutePolicy;
use MyInvoice\Service\Tenant\SupplierAccess;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PDO;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Skutečná resoluce rolí nad izolovanou SQLite; doménová politika i RBAC jsou produkční. */
final class ClientMcpToolPolicyTest extends TestCase
{
    private const DOCUMENT_READ_TOOLS = ['list_currencies', 'resolve_price_list_item'];
    private const OPERATIONAL_READ_TOOLS = [
        'bank_account_balances', 'list_bank_match_suggestions', 'recurring_invoice_history',
        'search_products', 'stock_availability', 'list_warehouses', 'get_warehouse',
    ];
    private const DETAIL_READ_TOOLS = [
        'stock_quantities', 'list_stock_locations', 'get_product_packaging', 'get_product_tracking',
        'get_purchase_invoice_payment', 'list_expense_categories',
    ];
    private const ADVANCE_READ_TOOLS = [
        'list_invoice_advance_candidates', 'list_proforma_final_candidates', 'list_invoice_advance_drawdown_candidates',
        'list_purchase_advance_candidates', 'list_purchase_settlement_candidates', 'preview_invoice_penalty',
    ];
    private const DOCUMENT_WRITE_TOOLS = [
        'update_purchase_invoice', 'add_purchase_invoice_item', 'update_purchase_invoice_item', 'remove_purchase_invoice_item',
        'upload_invoice_attachment', 'upload_purchase_invoice_pdf', 'import_purchase_invoice_file',
    ];
    private const ADVANCE_WRITE_TOOLS = [
        'link_invoice_advance', 'unlink_invoice_advance', 'apply_invoice_advance_drawdown', 'remove_invoice_advance_drawdown',
        'create_final_invoice_from_proforma', 'create_payment_tax_document', 'link_purchase_advance', 'unlink_purchase_advance',
    ];

    #[TestWith(['client_read'])]
    #[TestWith(['client_write'])]
    #[TestWith(['accountant'])]
    #[TestWith(['admin'])]
    public function testCatalogAddsOnlyDeclaredClientTools(string $preset): void
    {
        // Zmrazená nabídka původního client-tools.json nad b939f31a7; není zdrojem produkčních práv.
        $baseline = json_decode(file_get_contents(__DIR__ . '/../Fixtures/Mcp/client-catalog-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest = json_decode(file_get_contents(__DIR__ . '/../../../MCP/src/tool-api.generated.json'), true, flags: JSON_THROW_ON_ERROR);
        $allowed = $this->catalog($preset);
        foreach (['read', 'read_write'] as $scope) {
            $actual = array_values(array_filter($allowed, fn ($name) => $scope === 'read_write' || !$manifest[$name]['write']));
            sort($actual);
            $additions = ['get_work_report', 'list_vat_rates', ...self::DOCUMENT_READ_TOOLS,
                ...self::OPERATIONAL_READ_TOOLS, ...self::DETAIL_READ_TOOLS, ...self::ADVANCE_READ_TOOLS];
            if ($scope === 'read_write' && $preset !== 'client_read') {
                array_push($additions, 'add_work_report_entry', 'add_work_report_material', 'remove_work_report_entry',
                    ...self::DOCUMENT_WRITE_TOOLS, ...self::ADVANCE_WRITE_TOOLS);
            }
            $expected = [...$baseline[$preset][$scope], ...$additions];
            sort($expected);
            self::assertSame($expected, $actual, $preset . ' / ' . $scope);
        }
    }

    #[TestWith(['bank', ['bank_account_balances', 'list_bank_match_suggestions']])]
    #[TestWith(['recurring', ['recurring_invoice_history']])]
    #[TestWith(['stock', ['search_products', 'stock_availability', 'list_warehouses', 'get_warehouse']])]
    public function testOperationalReadToolsRequireTheirOwnAgenda(string $key, array $expected): void
    {
        sort($expected);
        foreach ([0, 1, 2] as $level) {
            $allowed = $this->catalog('client_none', overrides: [$key => $level]);
            $actual = array_values(array_intersect(self::OPERATIONAL_READ_TOOLS, $allowed));
            sort($actual);
            self::assertSame($level === 0 ? [] : $expected, $actual, $key . ': ' . $level);
        }
    }

    #[TestWith(['stock', ['stock_quantities', 'list_stock_locations', 'get_product_packaging', 'get_product_tracking']])]
    #[TestWith(['purchase_invoices', ['get_purchase_invoice_payment']])]
    #[TestWith(['settings.company', ['list_expense_categories']])]
    public function testReadDetailsRequireTheirOwnAgenda(string $key, array $expected): void
    {
        sort($expected);
        foreach ([0, 1, 2] as $level) {
            $allowed = $this->catalog('client_none', overrides: [$key => $level]);
            $actual = array_values(array_intersect(self::DETAIL_READ_TOOLS, $allowed));
            sort($actual);
            self::assertSame($level === 0 ? [] : $expected, $actual, $key . ': ' . $level);
        }
    }

    #[TestWith(['invoices', ['list_invoice_advance_candidates', 'list_proforma_final_candidates',
        'list_invoice_advance_drawdown_candidates', 'preview_invoice_penalty']])]
    #[TestWith(['purchase_invoices', ['list_purchase_advance_candidates', 'list_purchase_settlement_candidates']])]
    public function testAdvanceAndPenaltyReadsRequireTheirOwnAgenda(string $key, array $expected): void
    {
        sort($expected);
        foreach ([0, 1, 2] as $level) {
            $allowed = $this->catalog('client_none', overrides: [$key => $level]);
            $actual = array_values(array_intersect(self::ADVANCE_READ_TOOLS, $allowed));
            sort($actual);
            self::assertSame($level === 0 ? [] : $expected, $actual, $key . ': ' . $level);
        }
    }

    public function testReadToolsDoNotExposeEshopOrInternalOperations(): void
    {
        $allowed = $this->catalog('client_write');
        foreach (['get_product', 'get_product_prices', 'create_warehouse', 'create_product', 'create_stock_document',
            'post_stock_document', 'trial_balance', 'list_payroll_people', 'get_tax_return',
            'get_product_customer_prices', 'quote_product_prices', 'lookup_company_in_ares', 'stock_in_transit',
            'list_purchase_payment_orders', 'create_invoice_penalty'] as $name) {
            self::assertNotContains($name, $allowed);
        }
    }

    #[TestWith(['invoices', ['link_invoice_advance', 'unlink_invoice_advance', 'apply_invoice_advance_drawdown', 'remove_invoice_advance_drawdown']])]
    #[TestWith(['invoices.issue', ['create_final_invoice_from_proforma']])]
    #[TestWith(['invoices.mark_paid', ['create_payment_tax_document']])]
    #[TestWith(['purchase_invoices', ['link_purchase_advance', 'unlink_purchase_advance']])]
    public function testAdvanceWritesRequireTheirOwnWritePermission(string $key, array $expected): void
    {
        sort($expected);
        foreach ([0, 1, 2] as $level) {
            $actual = array_values(array_intersect(self::ADVANCE_WRITE_TOOLS,
                $this->catalog('client_none', overrides: [$key => $level])));
            sort($actual);
            self::assertSame($level === 2 ? $expected : [], $actual, $key . ': ' . $level);
        }
    }

    public function testPurchaseWorkflowRequiresAllAgendaPermissions(): void
    {
        $minimal = ['purchase_invoices' => 2, 'clients' => 1, 'settings.company' => 1];
        $allowed = $this->catalog('client_none', overrides: $minimal);
        self::assertContains('update_purchase_invoice', $allowed);
        foreach (['add_purchase_invoice_item', 'update_purchase_invoice_item', 'remove_purchase_invoice_item', 'upload_purchase_invoice_pdf'] as $name) {
            self::assertContains($name, $allowed);
        }
        self::assertNotContains('upload_invoice_attachment', $allowed);
        self::assertNotContains('import_purchase_invoice_file', $allowed);
        foreach (['clients', 'settings.company'] as $missing) {
            $permissions = $minimal;
            unset($permissions[$missing]);
            $allowed = $this->catalog('client_none', overrides: $permissions);
            self::assertNotContains('update_purchase_invoice', $allowed, $missing);
            self::assertContains('add_purchase_invoice_item', $allowed);
        }
        foreach ([0, 1] as $level) {
            $allowed = $this->catalog('client_none', overrides: [...$minimal, 'purchase_invoices' => $level]);
            self::assertSame([], array_values(array_intersect(self::DOCUMENT_WRITE_TOOLS, $allowed)));
        }
    }

    public function testFileOperationsUseTheirOwnAgendaPermissions(): void
    {
        $issued = $this->catalog('client_none', overrides: ['invoices' => 2]);
        self::assertSame(['upload_invoice_attachment'], array_values(array_intersect(self::DOCUMENT_WRITE_TOOLS, $issued)));
        $import = $this->catalog('client_none', overrides: ['purchase_invoices.create' => 2]);
        self::assertSame(['import_purchase_invoice_file'], array_values(array_intersect(self::DOCUMENT_WRITE_TOOLS, $import)));
    }

    public function testCurrenciesAndPriceResolutionRespectReadAlternatives(): void
    {
        $none = $this->catalog('client_none');
        self::assertNotContains('list_currencies', $none);
        self::assertNotContains('resolve_price_list_item', $none);
        foreach (['settings.company', 'price_list', 'stock', 'projects'] as $key) {
            self::assertContains('list_currencies', $this->catalog('client_none', overrides: [$key => 1]), $key);
        }
        foreach (['price_list', 'invoices', 'recurring'] as $key) {
            self::assertContains('resolve_price_list_item', $this->catalog('client_none', overrides: [$key => 1]), $key);
        }
    }

    public function testWorkReportRequiresAllLookupPermissions(): void
    {
        self::assertContains('add_work_report_entry', $this->catalog('client_write'));
        foreach (['projects', 'clients', 'settings.company'] as $missing) {
            $allowed = $this->catalog('client_write', $missing);
            self::assertNotContains('add_work_report_entry', $allowed, $missing);
            self::assertContains('get_work_report', $allowed);
            self::assertContains('remove_work_report_entry', $allowed);
        }
    }

    public function testOnlyIdentityToolsAreAvailableWithoutAgendaPermissions(): void
    {
        $actual = $this->catalog('client_none');
        sort($actual);
        self::assertSame(['whoami'], $actual);
        $manifest = json_decode(file_get_contents(__DIR__ . '/../../../MCP/src/tool-api.generated.json'), true, flags: JSON_THROW_ON_ERROR);
        $routes = new RoutePermissionMap();
        $selfService = [];
        foreach ($manifest as $name => $contract) {
            if (array_all($contract['api'], fn ($operation) => !str_contains($operation[1], '{')
                && $routes->match(...$operation)?->kind === RoutePermissionMap::SELF_SERVICE)) $selfService[] = $name;
        }
        sort($selfService);
        self::assertSame(['whoami'], $selfService);
    }

    #[TestWith([''])]
    #[TestWith(['{'])]
    #[TestWith(['null'])]
    #[TestWith(['{"synthetic":{"write":false,"api":[]}}'])]
    #[TestWith(['{"synthetic":{"write":false,"api":[["PUT","/api/invoices/{id}"]]}}'])]
    #[TestWith(['{"synthetic":{"write":false,"api":[["GET","/api/invoices/1"]]}}'])]
    #[TestWith(['{"synthetic":{"api":[["GET","/api/invoices"]]}}'])]
    public function testInvalidManifestFailsClosed(string $content): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mcp-manifest-');
        try {
            file_put_contents($path, $content);
            self::assertSame([], $this->catalog('admin', manifestPath: $path));
        } finally {
            unlink($path);
        }
    }

    public function testMissingOrUnreadableManifestFailsClosed(): void
    {
        self::assertSame([], $this->catalog('admin', manifestPath: __DIR__ . '/missing-manifest.json'));
        self::assertSame([], $this->catalog('admin', manifestPath: __DIR__));
        $path = tempnam(sys_get_temp_dir(), 'mcp-manifest-');
        try {
            file_put_contents($path, '{}');
            chmod($path, 0000);
            if (!is_readable($path)) self::assertSame([], $this->catalog('admin', manifestPath: $path));
        } finally {
            chmod($path, 0600);
            unlink($path);
        }
    }

    /** @return list<string> */
    public function catalog(string $preset, ?string $missing = null, ?string $manifestPath = null, array $overrides = []): array
    {
        $catalog = new PermissionCatalog();
        $type = str_starts_with($preset, 'client_') ? 'client' : 'staff';
        $permissions = $preset === 'accountant' ? $catalog->legacyPreset('accountant') : [];
        if ($preset !== 'accountant' && $preset !== 'client_none') {
            foreach ($catalog->all() as $key => $definition) {
                if (in_array($type, $definition['role_types'], true)) $permissions[$key] = $preset === 'client_read' ? 1 : 2;
            }
        }
        // Testujeme čtecí i zápisový token nad stejnou maticí agend.
        $permissions['profile.mcp'] = 2;
        $permissions = [...$permissions, ...$overrides];
        unset($permissions[$missing ?? '']);
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, system_key TEXT, name TEXT, role_type TEXT, is_active INTEGER);
            CREATE TABLE role_permissions (role_id INTEGER, permission_key TEXT, access_level INTEGER)');
        $pdo->prepare('INSERT INTO roles VALUES (1, ?, ?, ?, 1)')
            ->execute([$type === 'staff' ? $preset : null, 'Syntetická MCP role', $type]);
        $insert = $pdo->prepare('INSERT INTO role_permissions VALUES (1, ?, ?)');
        foreach ($permissions as $key => $level) $insert->execute([$key, $level]);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $suppliers = $this->createStub(SupplierAccessResolver::class);
        $suppliers->method('resolve')->willReturn(new SupplierAccess(1, false, null));
        $roles = new PermissionResolver($db, $suppliers, $catalog);
        $routes = new RoutePermissionMap();
        $arguments = [$roles, new PermissionChecker($catalog), $routes, new ClientRoutePolicy($routes, $catalog)];
        if ($manifestPath !== null) $arguments[] = $manifestPath;
        $policy = new ClientMcpToolPolicy(...$arguments);
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://portal.example.test/mcp')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role_id' => 1, 'role_summary' => ['type' => $type]]);
        return $policy->allowed($request);
    }
}
