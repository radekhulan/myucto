<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverReader;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Doklad, že převzatý vztah bez data skončení trvá po konci roku: převzatý
 * měsíc následujícího období s odpracovanou dobou nebo dobou pojištění, nebo
 * se skončením po 31. 12. Samotný příjem zúčtovaný po skončení trvání nedokládá.
 *
 * Data jsou syntetická (izolovaná firma, osoba „Převzatá osoba").
 */
#[Group('integration')]
final class PayrollTakeoverContinuationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollTakeoverReader $reader;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;

    protected function setUp(): void
    {
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->reader = $container->get(PayrollTakeoverReader::class);
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
        $pdo->prepare('INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, "active", "2026-04-01")')
            ->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Převzatá osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, end_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, "HPP-C", "employment", "active", "2024-01-01", "2024-01-01", NULL, 4000000, 0, 1)',
        )->execute([$this->supplierId, $this->employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
        $this->takeoverRow('2025-12-01', null, 31, 2000);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testNextYearMonthWithWorkProvesContinuation(): void
    {
        $this->takeoverRow('2026-02-01', null, 28, 2000);
        $this->takeoverRow('2026-01-01', null, 0, 0);

        $evidence = $this->reader->forEmployment($this->supplierId, $this->employmentId, 2025)
            ->continuationAfterYearEnd($this->employmentId);

        self::assertSame([
            'period' => '2026-02',
            'source' => 'takeover',
            'relationship_start_date' => '2024-01-01',
            'relationship_end_date' => null,
        ], $evidence);
    }

    public function testNextYearEndDateAfterYearEndProvesContinuation(): void
    {
        $this->takeoverRow('2026-01-01', '2026-01-15', 0, 0);

        $evidence = $this->reader->forEmployment($this->supplierId, $this->employmentId, 2025)
            ->continuationAfterYearEnd($this->employmentId);

        self::assertSame('2026-01', $evidence['period'] ?? null);
        self::assertSame('2026-01-15', $evidence['relationship_end_date'] ?? null);
    }

    /** Měsíc jen s dodatečně zúčtovaným příjmem, nebo se skončením do 31. 12., trvání nedokládá. */
    public function testIncomeOnlyOrEndedMonthDoesNotProveContinuation(): void
    {
        $this->takeoverRow('2026-01-01', null, 0, 0);
        $this->takeoverRow('2026-02-01', '2025-12-31', 28, 2000);

        self::assertNull(
            $this->reader->forEmployment($this->supplierId, $this->employmentId, 2025)
                ->continuationAfterYearEnd($this->employmentId),
        );
    }

    private function takeoverRow(string $period, ?string $end, int $insuranceDays, int $workedHundredths): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_migration_reference_totals
                (supplier_id, source, period_start, external_person_ref, external_relationship_ref,
                 employee_id, employment_id, relationship_start_date, relationship_end_date,
                 insurance_days, worked_days_hundredths, worked_minutes,
                 gross_minor, social_base_minor, advance_tax_minor)
             VALUES (?, "pamica", ?, "SYN-C", "SYN-C/1", ?, ?, "2024-01-01", ?, ?, ?, 0, 1000000, 1000000, 0)',
        )->execute([
            $this->supplierId,
            $period,
            $this->employeeId,
            $this->employmentId,
            $end,
            $insuranceDays,
            $workedHundredths,
        ]);
    }
}
