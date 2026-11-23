<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Absence\AutomaticLeaveEntitlementService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hromadný výpočet dovolené u převzaté firmy: absence z měsíců před prvním
 * mzdovým obdobím v MyÚčtu posoudil předchozí program a zůstatek dovolené
 * přišel převodem. Dřív u většiny lidí stálo „Doplnit - jiná schválená
 * absence vyžaduje ruční posouzení" a nabízel se druhý výpočet nároku.
 */
#[Group('integration')]
final class AutomaticLeaveEntitlementPredecessorTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private AutomaticLeaveEntitlementService $service;
    private int $supplierId;
    private int $employmentId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        foreach (['payroll_absences', 'payroll_leave_ledger', 'payroll_module_state', 'payroll_time_months'] as $table) {
            if (!$this->db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $this->service = $container->get(AutomaticLeaveEntitlementService::class);
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($source === 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare(
            "INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, 'setup', '2026-06-01')
             ON DUPLICATE KEY UPDATE start_period = VALUES(start_period)"
        )->execute([$this->supplierId]);
        $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, ?, "employee", 1)')
            ->execute([$this->supplierId, 'Syntetická převzatá osoba']);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, is_primary, start_date, actual_start_date)
             VALUES (?, ?, "SYN-DOV", "employment", "active", 0, "2025-03-01", "2025-03-01")'
        )->execute([$this->supplierId, $employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testAbsenceBeforeStartPeriodNeedsNoAssessment(): void
    {
        $this->absence('dpn', '2026-03-02', '2026-03-06');

        $item = $this->item();

        self::assertNotContains('absence_legal_assessment_required', $item['blockers'], 'Absenci před MyÚčtem posoudil předchozí program.');
        self::assertSame(1, $item['predecessor_absences']);
        self::assertSame([], $item['assessment_absences']);
    }

    public function testManualSourceBlocksLeaveCalculationAndDoesNotClaimZero(): void
    {
        $this->db->pdo()->prepare("INSERT INTO payroll_time_months (supplier_id, employment_id, period_start, work_source, status, approved_at) VALUES (?, ?, '2026-09-01', 'manual_summary', 'approved', NOW())")
            ->execute([$this->supplierId, $this->employmentId]);
        $item = $this->item();
        self::assertFalse($item['ready']);
        self::assertContains('manual_work_source_requires_review', $item['blockers']);
        self::assertNull($item['worked_equivalent_minutes']);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->calculateBatch($this->supplierId, 2026, '2026-09-26', [
            ['employment_id' => $this->employmentId, 'input_version' => $item['input_version']],
        ], null);
    }

    public function testAbsenceAfterStartNamesWhatToAssessAndDecisionClearsTheBlocker(): void
    {
        $id = $this->absence('ocr', '2026-07-06', '2026-07-08');

        $item = $this->item();
        self::assertContains('absence_legal_assessment_required', $item['blockers']);
        self::assertSame([[
            'id' => $id, 'row_version' => 1, 'absence_type' => 'ocr', 'date_from' => '2026-07-06', 'date_to' => '2026-07-08',
        ]], $item['assessment_absences'], 'Řádek musí říct, KTERÁ absence čeká na posouzení.');

        $employment = $this->db->pdo()->query(
            "SELECT employment.*, employee.full_name FROM payroll_employments employment
               JOIN payroll_employees employee ON employee.id = employment.employee_id WHERE employment.id = {$this->employmentId}"
        )->fetch(\PDO::FETCH_ASSOC);
        $decided = (new \ReflectionMethod($this->service, 'candidate'))
            ->invoke($this->service, $this->supplierId, 2026, '2026-09-26', $employment, ['ocr' => AutomaticLeaveEntitlementService::DECISION_EXCLUDE]);
        self::assertNotContains('absence_legal_assessment_required', $decided['blockers']);
        self::assertSame('exclude', $decided['absence_assessments'][0]['decision']);
        self::assertSame(0, $decided['absence_assessments'][0]['credited_minutes']);
        self::assertSame($item['input_version'], $decided['input_version'], 'Rozhodnutí nemění verzi vstupů z náhledu.');

        $included = (new \ReflectionMethod($this->service, 'candidate'))
            ->invoke($this->service, $this->supplierId, 2026, '2026-09-26', $employment, ['ocr' => AutomaticLeaveEntitlementService::DECISION_INCLUDE]);
        self::assertNotContains('absence_legal_assessment_required', $included['blockers']);
        self::assertContains('absence_time_month_missing', $included['blockers'], 'Započíst jde jen absenci ve schváleném měsíci docházky.');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->calculateBatch($this->supplierId, 2026, '2026-09-26',
            [['employment_id' => $this->employmentId, 'input_version' => $item['input_version']]], null, ['ocr' => 'maybe']);
    }

    public function testTakenOverLeaveBalanceIsNotCalculatedAgain(): void
    {
        $insert = $this->db->pdo()->prepare(
            "INSERT INTO payroll_leave_ledger
                (supplier_id, employment_id, leave_year, effective_date, entry_type, minutes_delta, reason, support_status, source_hash)
             VALUES (?, ?, 2026, '2026-01-01', 'carryover', ?, ?, 'supported', UNHEX(SHA2(?, 256)))"
        );
        $insert->execute([$this->supplierId, $this->employmentId, 600, 'Zůstatek z roku 2025 zadaný ručně.', 'syn-1']);
        self::assertNull($this->item()['takeover'], 'Ruční převod z minulého roku nárok roku neurčuje.');

        $insert->execute([$this->supplierId, $this->employmentId, 6000, 'Převzato z PAMICA: zůstatek dovolené ke dni převodu, 100 h.', 'syn-2']);
        $this->absence('dpn', '2026-03-02', '2026-03-06');

        $item = $this->item();

        self::assertFalse($item['ready']);
        self::assertSame([], $item['blockers']);
        self::assertSame(6000, $item['takeover']['minutes']);
        self::assertSame('2026-01-01', $item['takeover']['effective_date']);
    }

    private function absence(string $type, string $from, string $to): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_absences (supplier_id, employment_id, absence_type, date_from, date_to, status, row_version)
             VALUES (?, ?, ?, ?, ?, 'approved', 1)"
        )->execute([$this->supplierId, $this->employmentId, $type, $from, $to]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function item(): array
    {
        $page = $this->service->page($this->supplierId, 2026, '2026-09-26');
        self::assertCount(1, $page['items']);

        return $page['items'][0];
    }
}
