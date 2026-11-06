<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Absence\PayrollAbsenceApprovalWarnings;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Nemoc schválená přes dny s odpracovanou dobou a nepřítomnost v měsíci se
 * schváleným během musí po schválení říct, co je potřeba opravit (C-22).
 */
#[Group('integration')]
final class PayrollAbsenceApprovalWarningsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private int $supplierId;
    private int $employmentId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->db = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický nemocný", "employee", 1)',
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, "SYN-DPN-1", "employment", "active", "2026-01-01", 3000000, 0)',
        )->execute([$this->supplierId, $employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testSicknessOverWorkedDaysIsReportedWithDays(): void
    {
        foreach (['2026-09-08', '2026-09-09', '2026-09-30'] as $day) {
            $this->db->pdo()->prepare(
                "INSERT INTO payroll_time_entries
                    (supplier_id, employment_id, series_key, category, starts_at_utc, ends_at_utc,
                     timezone_name, source_kind, source_hash, status)
                 VALUES (?, ?, ?, 'regular', ?, ?, 'Europe/Prague', 'manual', ?, 'draft')",
            )->execute([
                $this->supplierId,
                $this->employmentId,
                bin2hex(random_bytes(16)),
                $day . ' 06:00:00',
                $day . ' 14:00:00',
                random_bytes(32),
            ]);
        }
        $service = new PayrollAbsenceApprovalWarnings($this->db);

        $warnings = $service->forApproved($this->supplierId, [
            'employment_id' => $this->employmentId,
            'date_from' => '2026-09-08',
            'date_to' => '2026-09-27',
        ]);

        self::assertSame(['absence_overlaps_worked_time'], array_column($warnings, 'code'));
        self::assertStringContainsString('8. 9., 9. 9.', $warnings[0]['message']);
        self::assertStringNotContainsString('30. 9.', $warnings[0]['message']);
        self::assertSame([], $service->forApproved($this->supplierId, [
            'employment_id' => $this->employmentId,
            'date_from' => '2026-09-10',
            'date_to' => '2026-09-20',
        ]));
    }
}
