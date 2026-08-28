<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\PayrollProductionGateException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollProductionGateTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollProductionGate $gate;
    private int $supplierId;

    public function testDistributedBuildKeepsProductionReleaseClosed(): void
    {
        self::assertFalse(PayrollProductionGate::PRODUCT_RELEASED);
    }

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->gate = new PayrollProductionGate(
            $container->get(PayrollModuleStateRepository::class),
            false,
        );
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT MIN(id) FROM supplier',
        )->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period)
             VALUES (?, "setup", "2026-01-01")',
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        $this->db->close();
    }

    public function testTestTransportRemainsAvailableDuringInternalVerification(): void
    {
        $this->gate->assertEnvironmentActive($this->supplierId, 'test');

        self::addToAssertionCount(1);
    }

    public function testProductionTransportIsRejectedDuringInternalVerification(): void
    {
        $this->expectException(PayrollProductionGateException::class);

        $this->gate->assertEnvironmentActive(
            $this->supplierId,
            'production',
        );
    }

    public function testActiveCustomerCannotBypassPendingInternalProductRelease(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_module_state SET status = "active"
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);

        $this->expectException(PayrollProductionGateException::class);
        $this->gate->assertEnvironmentActive(
            $this->supplierId,
            'production',
        );
    }

    public function testReleasedProductStillRequiresCompletedCustomerSetup(): void
    {
        $released = new PayrollProductionGate(
            new PayrollModuleStateRepository($this->db),
            true,
        );

        $this->expectException(PayrollProductionGateException::class);
        $released->assertEnvironmentActive($this->supplierId, 'production');
    }

    public function testReleasedProductAllowsProductionAfterOrdinarySetup(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_module_state SET status = "active"
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $released = new PayrollProductionGate(
            new PayrollModuleStateRepository($this->db),
            true,
        );

        $released->assertEnvironmentActive($this->supplierId, 'production');
        self::addToAssertionCount(1);
    }
}
