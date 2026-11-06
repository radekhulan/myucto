<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzPayrollTakeover;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmployment;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmploymentWriter;
use MyInvoice\Service\Payroll\PayrollHealthInsurerBulkAssignment;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Co po převzetí z hlášení JMHZ zbývá doplnit: zdravotní pojišťovna (hlášení ji
 * nenese) a srážky ze mzdy (hlášení nese jen příznak).
 */
#[Group('integration')]
final class PayrollTakeoverFollowUpTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollHealthInsurerBulkAssignment $health;
    private PayrollTakeoverEmploymentWriter $writer;
    private PayrollEmploymentRepository $employments;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        $health = $container->get(PayrollHealthInsurerBulkAssignment::class);
        $writer = $container->get(PayrollTakeoverEmploymentWriter::class);
        $employments = $container->get(PayrollEmploymentRepository::class);
        if (!$db instanceof Connection
            || !$health instanceof PayrollHealthInsurerBulkAssignment
            || !$writer instanceof PayrollTakeoverEmploymentWriter
            || !$employments instanceof PayrollEmploymentRepository
        ) {
            throw new \RuntimeException('Služby převzetí nejsou dostupné.');
        }
        $this->db = $db;
        $this->health = $health;
        $this->writer = $writer;
        $this->employments = $employments;
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            "INSERT INTO payroll_module_state (supplier_id, status, start_period)
             VALUES (?, 'setup', '2026-09-01')
             ON DUPLICATE KEY UPDATE status = 'setup', start_period = '2026-09-01'"
        )->execute([$this->supplierId]);
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

    public function testPeopleWithoutInsurerAreListedAndAssignedInBulk(): void
    {
        [$first] = $this->employment('Syntetická osoba bez ZP', '2026-03-01');
        [$second] = $this->employment('Syntetická osoba druhá', '2026-05-04');
        [$ended] = $this->employment('Syntetická osoba odešlá', '2026-01-01', '2026-06-30');

        $preview = $this->health->preview($this->supplierId, '2026-09-01');
        self::assertSame(
            [[$first, '2026-03-01'], [$second, '2026-05-01']],
            array_map(static fn (array $person): array => [$person['employee_id'], $person['suggested_from']], $preview['people']),
        );
        self::assertNotContains($ended, array_column($preview['people'], 'employee_id'), 'Vztah v období netrvá.');

        $result = $this->health->apply($this->supplierId, [
            ['employee_id' => $first, 'insurer_code' => '111', 'effective_from' => '2026-03-01'],
            ['employee_id' => $second, 'insurer_code' => '207', 'effective_from' => '2026-05-01'],
        ], $this->userId, null, null);

        self::assertSame(['applied' => 2, 'failed' => 0], $result['counts'], json_encode($result['failed'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame([], $this->health->preview($this->supplierId, '2026-09-01')['people']);
        self::assertSame([['111', '2026-03-01']], $this->coverage($first));
        self::assertSame([['207', '2026-05-01']], $this->coverage($second));
    }

    public function testInvalidInsurerIsRejectedBeforeAnyWrite(): void
    {
        [$employee] = $this->employment('Syntetická osoba', '2026-03-01');

        $this->expectException(\InvalidArgumentException::class);
        $this->health->apply($this->supplierId, [
            ['employee_id' => $employee, 'insurer_code' => '999', 'effective_from' => '2026-03-01'],
        ], $this->userId, null, null);
    }

    public function testDeductionsFollowUpIsCreatedOnceAndCompletedByARecordedDeduction(): void
    {
        [$employeeId, $employmentId] = $this->employment('Syntetická osoba se srážkou', '2026-03-01');
        $taken = new PayrollTakeoverEmployment(
            personalNumber: 'P1',
            relationKey: 'employment:' . $employmentId,
            followUps: [JmhzPayrollTakeover::DEDUCTIONS_FOLLOW_UP],
        );

        self::assertSame(['follow_ups' => 1], $this->writer->followUps($this->supplierId, $employmentId, $taken, '2026-09', JmhzPayrollTakeover::policy()));
        self::assertSame([], $this->writer->followUps($this->supplierId, $employmentId, $taken, '2026-09', JmhzPayrollTakeover::policy()), 'Opakovaný import úkol nezdvojí.');

        $item = $this->item($employeeId);
        self::assertSame('pending', $item['effective_status'], 'Úkol pro první mzdu v MyÚčtu předchozí program nevyřídil.');
        self::assertSame('2026-09-01', $item['due_date']);

        $this->db->pdo()->prepare(
            "INSERT INTO payroll_deduction_agreements
                (supplier_id, employee_id, agreement_reference, title, deduction_kind, status, requested_minor, valid_from)
             VALUES (?, ?, 'SYN-1', 'Syntetická dohoda o srážce', 'other', 'active', 100000, '2026-09-01')"
        )->execute([$this->supplierId, $employeeId]);

        $item = $this->item($employeeId);
        self::assertSame('completed', $item['effective_status']);
        self::assertSame('evidence', $item['effective_reason']);
    }

    /**
     * PRE-04: rozběhnutá nemoc z převzatého hlášení. Pokračující neschopnost
     * zadaná od prvního dne v MyÚčtu bez započtených dnů by otevřela druhé
     * okno náhrady mzdy, proto úkol nesplní. Splní ho až nepřítomnost se
     * skutečným dnem vzniku před prvním měsícem v MyÚčtu.
     */
    public function testSicknessFollowUpIsCompletedOnlyByAbsenceWithItsRealStart(): void
    {
        [$employeeId, $employmentId] = $this->employment('Syntetická osoba v neschopnosti', '2026-03-01');
        $taken = new PayrollTakeoverEmployment(
            personalNumber: 'P1',
            relationKey: 'employment:' . $employmentId,
            followUps: [JmhzPayrollTakeover::SICKNESS_FOLLOW_UP],
        );

        self::assertSame(['follow_ups' => 1], $this->writer->followUps($this->supplierId, $employmentId, $taken, '2026-09', JmhzPayrollTakeover::policy()));
        $item = $this->item($employeeId, JmhzPayrollTakeover::SICKNESS_FOLLOW_UP);
        self::assertSame('pending', $item['effective_status'], 'Úkol pro první mzdu v MyÚčtu předchozí program nevyřídil.');

        $absence = $this->db->pdo()->prepare(
            "INSERT INTO payroll_absences
                (supplier_id, employment_id, absence_type, date_from, date_to, status, sickness_window_carried_days)
             VALUES (?, ?, 'dpn', ?, ?, 'approved', 0)"
        );
        $absence->execute([$this->supplierId, $employmentId, '2026-09-01', '2026-09-20']);
        self::assertSame('pending', $this->item($employeeId, JmhzPayrollTakeover::SICKNESS_FOLLOW_UP)['effective_status']);

        $absence->execute([$this->supplierId, $employmentId, '2026-08-25', '2026-08-31']);
        $item = $this->item($employeeId, JmhzPayrollTakeover::SICKNESS_FOLLOW_UP);
        self::assertSame('completed', $item['effective_status']);
        self::assertSame('evidence', $item['effective_reason']);
    }

    /** @return array{0:int,1:int} */
    private function employment(string $name, string $start, ?string $end = null): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, ?, "employee", 1)'
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, is_primary, start_date, actual_start_date, end_date)
             VALUES (?, ?, ?, "employment", ?, 1, ?, ?, ?)'
        )->execute([$this->supplierId, $employeeId, 'Z' . $employeeId, $end === null ? 'active' : 'ended', $start, $start, $end]);

        return [$employeeId, (int) $pdo->lastInsertId()];
    }

    /** @return list<array{0:string,1:string}> */
    private function coverage(int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT insurer_code, effective_from FROM payroll_person_health_coverage_history
              WHERE supplier_id = ? AND employee_id = ? ORDER BY effective_from'
        );
        $stmt->execute([$this->supplierId, $employeeId]);

        return array_map(
            static fn (array $row): array => [(string) $row['insurer_code'], (string) $row['effective_from']],
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    /** @return array<string,mixed> */
    private function item(int $employeeId, string $itemKey = JmhzPayrollTakeover::DEDUCTIONS_FOLLOW_UP): array
    {
        foreach ($this->employments->listForEmployee($this->supplierId, $employeeId) as $employment) {
            foreach ($employment['checklist'] as $item) {
                if ($item['item_key'] === $itemKey) {
                    return $item;
                }
            }
        }
        self::fail("Úkol {$itemKey} chybí.");
    }
}
