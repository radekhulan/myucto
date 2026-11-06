<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Absence\AverageEarningBatchService;
use MyInvoice\Service\Payroll\Absence\AverageEarningDerivationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Průměrný výdělek pro první čtvrtletí po přechodu: rozhodné období (červenec
 * až září) vedl předchozí program, takže v MyÚčtu za něj není žádný běh a
 * návrh dřív skončil na „běh chybí" — účetní opisovala průměr ručně.
 */
#[Group('integration')]
final class AverageEarningTakeoverSuggestionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private AverageEarningDerivationService $derivation;
    private AverageEarningBatchService $batch;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;

    protected function setUp(): void
    {
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->derivation = $container->get(AverageEarningDerivationService::class);
            $this->batch = $container->get(AverageEarningBatchService::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare('INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, "active", "2026-10-01")')
            ->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Převzatá osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, "HPP-AVG", "employment", "active", "2024-01-01", "2024-01-01", 4000000, 0, 1)',
        )->execute([$this->supplierId, $this->employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testQuarterAfterTransitionIsSuggestedFromTakenOverWages(): void
    {
        foreach (['2026-07', '2026-08', '2026-09'] as $period) {
            $this->takeover($period, 40_000_00, 168 * 60, 2100);
        }

        $suggestion = $this->derivation->suggest($this->supplierId, $this->employmentId, 2026, 4);

        self::assertTrue($suggestion['ready'], implode(', ', $suggestion['blockers']));
        self::assertSame('actual', $suggestion['source_kind']);
        self::assertSame(120_000_00, $suggestion['gross_earnings_minor']);
        self::assertSame(3 * 168 * 60, $suggestion['worked_minutes']);
        self::assertSame(63, $suggestion['worked_days']);
        self::assertSame(['2026-07', '2026-08', '2026-09'], $suggestion['takeover_periods']);

        // Hromadné schválení převzatý průměr nepustí — musí ho posoudit účetní.
        $candidate = $this->batch->page($this->supplierId, 2026, 4, 50, 0)['items'][0];
        self::assertFalse($candidate['ready']);
        self::assertContains('takeover_needs_review', $candidate['blockers']);
    }

    public function testTakenOverMonthWithoutHoursBlocksTheSuggestion(): void
    {
        $this->takeover('2026-07', 40_000_00, 0, 2100);
        $this->takeover('2026-08', 40_000_00, 168 * 60, 2100);
        $this->takeover('2026-09', 40_000_00, 168 * 60, 2100);

        $suggestion = $this->derivation->suggest($this->supplierId, $this->employmentId, 2026, 4);

        self::assertFalse($suggestion['ready']);
        self::assertContains('takeover_worked_time_missing', $suggestion['blockers']);
    }

    private function takeover(string $period, int $gross, int $minutes, int $daysHundredths): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_migration_reference_totals
                (supplier_id, source, period_start, external_person_ref, external_relationship_ref,
                 employee_id, employment_id, gross_minor, worked_minutes, worked_days_hundredths)
             VALUES (?, "other", ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $period . '-01',
            'employee:' . $this->employeeId,
            'employment:' . $this->employmentId,
            $this->employeeId,
            $this->employmentId,
            $gross,
            $minutes,
            $daysHundredths,
        ]);
    }
}
