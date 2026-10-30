<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Dashboard;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Service\License\LicenseConsistency;
use Psr\Log\NullLogger;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Dashboard\DashboardWidgetAccess;
use MyInvoice\Service\Dashboard\DashboardWidgetRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DashboardWidgetAccessTest extends TestCase
{
    private DashboardWidgetRegistry $registry;
    private DashboardWidgetAccess $access;
    private const SUPPLIER = ['id' => 987, 'is_vat_payer' => 1, 'taxpayer_type' => 'fo', 'accounting_mode' => 'double_entry'];

    protected function setUp(): void
    {
        $this->registry = new DashboardWidgetRegistry();
        $this->access = new DashboardWidgetAccess($this->createStub(Connection::class), $this->registry, $this->consistency());
    }

    public function testBaseAggregatesNeedNoInvoicePermissionButDetailsDo(): void
    {
        $request = $this->request([]);
        foreach (['business.kpi', 'business.monthly', 'receivables.aging', 'payables.aging', 'cashflow.weekly', 'vat.current', 'tax.deadlines'] as $type) {
            self::assertTrue($this->allowed($request, $type), $type);
        }
        foreach (['sales.summary', 'purchases.summary', 'clients.top', 'invoices.approvals', 'documents.requests', 'actions.quick', 'actions.todo', 'tax.net', 'accounting.status'] as $type) {
            self::assertFalse($this->allowed($request, $type), $type);
        }
        self::assertTrue($this->allowed($this->request(['invoices' => 1]), 'sales.summary'));
        self::assertFalse($this->allowed($this->request(['invoices' => 1]), 'clients.top'));
        self::assertTrue($this->allowed($this->request(['invoices' => 1, 'clients' => 1]), 'clients.top'));
    }

    public function testCompanyPredicatesAndTenantMismatchDeny(): void
    {
        $request = $this->request(['reports' => 1, 'accounting' => 1]);
        self::assertFalse($this->allowed($request, 'tax.net'), 'Bez ověřeného licenčního stavu zůstávají komerční widgety zavřené.');
        self::assertFalse($this->allowed($request, 'tax.net', array_replace(self::SUPPLIER, ['taxpayer_type' => 'po'])));
        self::assertFalse($this->allowed($request, 'vat.current', array_replace(self::SUPPLIER, ['is_vat_payer' => 0])));
        self::assertFalse($this->allowed($request, 'accounting.status', array_replace(self::SUPPLIER, ['accounting_mode' => 'tax_evidence'])));
        self::assertFalse($this->allowed($request, 'business.kpi', array_replace(self::SUPPLIER, ['id' => 988])));
        self::assertFalse($this->allowed($request, 'business.kpi', []));
    }

    public function testActionsNeedSpecificWriteAndReadDependencies(): void
    {
        self::assertFalse($this->allowed($this->request(['invoices.create' => 1]), 'actions.quick'));
        self::assertTrue($this->allowed($this->request(['invoices.create' => 2]), 'actions.quick'));
        self::assertFalse($this->allowed($this->request(['bank.match' => 2]), 'actions.todo'));
        self::assertTrue($this->allowed($this->request(['bank' => 1, 'bank.match' => 2]), 'actions.todo'));
        self::assertFalse($this->allowed($this->request(['recurring' => 1, 'recurring.run' => 1]), 'actions.todo'));
        self::assertTrue($this->allowed($this->request(['recurring' => 1, 'recurring.run' => 2]), 'actions.todo'));
        self::assertFalse($this->allowed($this->request(['dashboard' => 2]), 'actions.quick'));
    }

    public function testPurchaseApprovalsKeepReadOnlyAccessAndClientBankMatchingDoesNotGrantRematch(): void
    {
        $approver = $this->request(['purchase_invoices.approve' => 1]);
        self::assertTrue($this->access->capabilities($approver, 'actions.todo')['purchaseApprove']);
        $client = $this->request(['bank' => 1, 'bank.match' => 2], 'client');
        self::assertTrue($this->access->capabilities($client, 'actions.todo')['bankMatch']);
        self::assertFalse($this->access->capabilities($client, 'actions.todo')['bankRematch']);
        self::assertTrue($this->access->capabilities($this->request(['bank' => 1, 'bank.match' => 2]), 'actions.todo')['bankRematch']);
    }

    public function testDocumentViewsStaySeparatedByClientPolicy(): void
    {
        self::assertTrue($this->allowed($this->request(['documents.submit' => 1], 'client'), 'documents.requests'));
        self::assertFalse($this->allowed($this->request(['documents.requests' => 1], 'client'), 'documents.requests'));
        self::assertTrue($this->allowed($this->request(['documents.requests' => 1]), 'documents.requests'));
        self::assertFalse($this->allowed($this->request(['documents.submit' => 1]), 'documents.requests'));
    }

    public function testCatalogFiltersDefinitionsButPreservesUnavailableDefaultInstances(): void
    {
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetch')->willReturn(self::SUPPLIER);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $access = new DashboardWidgetAccess($db, $this->registry, $this->consistency());
        $catalog = $access->catalog($this->request([]), 'portal');
        $available = array_column($catalog['widgets'], 'type');
        self::assertContains('business.kpi', $available);
        self::assertNotContains('sales.summary', $available);
        self::assertNotContains('accounting.status', $available);
        self::assertContains('sales.summary', array_column($catalog['defaultLayout']['widgets'], 'type'));
        self::assertCount(32, $catalog['defaultLayout']['widgets']);
        self::assertNotNull($this->registry->get('accounting.status'));
        self::assertCount(24, $this->registry->all());
        self::assertSame('portal', $catalog['board']);
        self::assertIsObject($catalog['widgets'][0]['options']);
        self::assertArrayHasKey('heights', $catalog['widgets'][0]);
    }

    private function allowed(Request $request, string $type, array $supplier = self::SUPPLIER): bool
    {
        return $this->access->allows($request, $this->registry->get($type), $supplier);
    }

    private function consistency(): LicenseConsistency
    {
        return new LicenseConsistency($this->createStub(Connection::class), new Config([]), EntityCache::disabled(), new NullLogger());
    }

    private function request(array $permissions, string $type = 'staff'): Request
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/dashboard-widgets/catalog')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 987)
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Syntetická role', $type, true, $permissions));
    }
}
