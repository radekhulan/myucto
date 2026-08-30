<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
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

    /**
     * Ostrý provoz odemyká KONFIGURACE INSTALACE, ne přepsání konstanty.
     *
     * Kdyby se to dělalo editací `PRODUCT_RELEASED`, rozhodnutí „tahle
     * instalace jede mzdy ostře" by se ztratilo v diffu, nešlo by na dané
     * instalaci zjistit ani vrátit bez nasazení nové verze — a při upgradu by
     * se tiše přepsalo zpátky.
     */
    public function testInstallationConfigOpensAndClosesProductionRelease(): void
    {
        $states = Bootstrap::buildContainer()->get(PayrollModuleStateRepository::class);
        $gate = static fn (array $data): PayrollProductionGate => new PayrollProductionGate(
            $states,
            null,
            new Config($data),
        );

        self::assertTrue(
            $gate(['payroll' => ['production_released' => true]])->isReleased(),
            'Konfigurace instalace musí ostrý mzdový provoz odemknout.',
        );
        self::assertFalse($gate(['payroll' => ['production_released' => false]])->isReleased());
        // Chybějící klíč nic nepřepíná: instalace, která o mzdách nic neříká,
        // se chová stejně, jako by konfigurace nebyla — v ostrém provozu to
        // znamená `PRODUCT_RELEASED`, tedy zavřeno. Pod PHPUnit je brána
        // otevřená schválně (jinak by testy neprošly zamčenou větví), takže se
        // tady porovnává SHODA obou cest, ne konkrétní hodnota.
        self::assertSame(
            (new PayrollProductionGate($states, null, null))->isReleased(),
            $gate([])->isReleased(),
        );
        self::assertSame(
            ['released' => true],
            $gate(['payroll' => ['production_released' => true]])->status(),
            'Stav brány musí být vidět v API, ne jen v konfiguračním souboru.',
        );
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
