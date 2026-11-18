<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationModuleSetup;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Změna prvního mzdového období během nastavení mezd.
 *
 * Firma po převodu z předchozího programu zjistí, že rozpracovaný měsíc má
 * spočítat MyÚčto, a první období posune zpět. Během nastavení to jde, ale
 * změna nesmí schovat existující běh: převzatý běh by se ocitl mezi měsíci,
 * které počítá MyÚčto, spočítaný běh v převzaté části roku.
 */
#[Group('integration')]
final class PayrollStartPeriodChangeTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollMigrationModuleSetup $setup;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        $setup = $container->get(PayrollMigrationModuleSetup::class);
        if (!$db instanceof Connection || !$setup instanceof PayrollMigrationModuleSetup) {
            throw new \RuntimeException('Služba nastavení mezd není dostupná.');
        }
        $this->db = $db;
        $this->setup = $setup;
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('DELETE FROM payroll_module_state WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, row_version)
             VALUES (?, "setup", "2026-11-01", 1)'
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testSetupStartCanMoveBackWithoutRuns(): void
    {
        self::assertNull($this->setup->startChangeProblem($this->supplierId, '2026-10'));
        self::assertNull($this->setup->startChangeProblem($this->supplierId, '2026-12'));
    }

    public function testTakeoverRunBlocksMovingStartBackOverIt(): void
    {
        $this->insertRun('2026-10-01', 'takeover', 'approved');

        self::assertStringContainsString(
            'převzatý mzdový běh',
            (string) $this->setup->startChangeProblem($this->supplierId, '2026-10'),
        );
        self::assertNull($this->setup->startChangeProblem($this->supplierId, '2026-12'));
    }

    public function testCalculatedRunBlocksMovingStartForwardOverIt(): void
    {
        $this->insertRun('2026-11-01', 'calculated', 'draft');

        self::assertStringContainsString(
            'vlastní mzdové běhy',
            (string) $this->setup->startChangeProblem($this->supplierId, '2026-12'),
        );
        self::assertNull($this->setup->startChangeProblem($this->supplierId, '2026-10'));
    }

    public function testCancelledRunsDoNotBlock(): void
    {
        $this->insertRun('2026-10-01', 'takeover', 'cancelled');

        self::assertNull($this->setup->startChangeProblem($this->supplierId, '2026-10'));
    }

    public function testActiveModuleKeepsItsStart(): void
    {
        $this->db->pdo()->prepare('UPDATE payroll_module_state SET status = "active" WHERE supplier_id = ?')
            ->execute([$this->supplierId]);

        self::assertNull($this->setup->startChangeProblem($this->supplierId, '2026-11'));
        self::assertStringContainsString(
            'jen během nastavení',
            (string) $this->setup->startChangeProblem($this->supplierId, '2026-10'),
        );
    }

    private function insertRun(string $periodStart, string $kind, string $status): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, run_kind)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $periodStart, $periodStart, $status, $kind]);
    }
}
