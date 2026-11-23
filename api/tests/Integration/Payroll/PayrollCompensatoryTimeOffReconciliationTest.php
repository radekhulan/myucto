<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Time\Overtime\CompensatoryTimeOffReconciliation;
use MyInvoice\Service\Payroll\Time\PayrollTimeService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Náhradní volno se eviduje na dvou místech a rozpor mezi nimi nesmí mlčet.
 *
 * Dokud absence neměla vlastní druh, bylo čerpání schované mezi „jinými
 * absencemi" (`other`) a proti `payroll_overtime_compensations` ho nešlo
 * postavit vůbec. Jednostranný zápis je přitom tichá vada:
 *
 *  - jen absence  → přesčas se z vyrovnávacího období podle § 93 odst. 4
 *    neodečte a limit se ohlásí jako překročený, i když překročený není;
 *  - jen kompenzace → mzda o volnu neví a den zůstane v docházce jako
 *    neodpracovaný bez důvodu.
 *
 * Test proto ověřuje obě strany i fail-closed větev u zápisu bez data
 * poskytnutí.
 */
#[Group('integration')]
final class PayrollCompensatoryTimeOffReconciliationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';

    private Connection $db;
    private PayrollTimeService $time;
    private int $supplierId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->time = $container->get(PayrollTimeService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach (
            ['payroll_absences', 'payroll_overtime_compensations', 'payroll_employments']
            as $table
        ) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
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

    /** Bez druhu absence pro náhradní volno by tenhle zápis vůbec neprošel. */
    public function testCompensatoryTimeOffIsItsOwnAbsenceKind(): void
    {
        $employmentId = $this->seedEmployment();
        $this->seedAbsence($employmentId, '2026-06-10', '2026-06-10');

        $stored = $this->db->pdo()->prepare(
            'SELECT absence_type, compensation_policy
               FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ?'
        );
        $stored->execute([$this->supplierId, $employmentId]);
        $row = $stored->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('compensatory_time_off', $row['absence_type']);
        self::assertSame(
            'none',
            $row['compensation_policy'],
            '§ 114 odst. 3 — za dobu čerpání náhradního volna mzda nepřísluší.',
        );
    }

    public function testAbsenceWithoutCompensationIsReported(): void
    {
        $employmentId = $this->seedEmployment();
        $this->seedAbsence($employmentId, '2026-06-10', '2026-06-10');

        $check = $this->check($employmentId);

        self::assertSame(
            [CompensatoryTimeOffReconciliation::ABSENCE_WITHOUT_COMPENSATION],
            $check['findings'],
        );
        self::assertSame(1, $check['absence_rows']);
        self::assertSame(0, $check['granted_rows']);
    }

    public function testCompensationWithoutAbsenceIsReported(): void
    {
        $employmentId = $this->seedEmployment();
        $this->seedCompensation($employmentId, '2026-05-04', 120, '2026-06-10');

        $check = $this->check($employmentId);

        self::assertSame(
            [CompensatoryTimeOffReconciliation::COMPENSATION_WITHOUT_ABSENCE],
            $check['findings'],
        );
        self::assertSame(120, $check['granted_minutes']);
    }

    /**
     * Obě strany zapsané → žádný nález. Bez tohohle případu by test procházel
     * i s kontrolou, která hlásí rozpor pořád.
     */
    public function testBothSidesRecordedProduceNoFinding(): void
    {
        $employmentId = $this->seedEmployment();
        $this->seedAbsence($employmentId, '2026-06-10', '2026-06-10');
        $this->seedCompensation($employmentId, '2026-05-04', 120, '2026-06-10');

        $check = $this->check($employmentId);

        self::assertSame([], $check['findings']);
        self::assertSame(CompensatoryTimeOffReconciliation::OK, $check['status']);
    }

    /**
     * Vazba je klíčovaná dnem PŘESČASU, ne dnem čerpání — kompenzace za přesčas
     * z minulého měsíce se do porovnání dostane podle `granted_on`, protože
     * v tomhle měsíci se vybírala.
     */
    public function testGrantDateWithoutValueIsFailClosed(): void
    {
        $employmentId = $this->seedEmployment();
        $this->seedAbsence($employmentId, '2026-06-10', '2026-06-10');
        $this->seedCompensation($employmentId, '2026-05-04', 120, '2026-06-10');
        $this->seedCompensation($employmentId, '2026-05-05', 60, null);

        $check = $this->check($employmentId);

        self::assertSame(
            [CompensatoryTimeOffReconciliation::GRANT_DATE_UNKNOWN],
            $check['findings'],
            'Zápis bez data poskytnutí se nesmí tiše počítat do měsíce.',
        );
        self::assertSame(1, $check['ungranted_rows']);
    }

    /** @return array<string,mixed> */
    private function check(int $employmentId): array
    {
        $page = $this->time->overview(
            $this->supplierId,
            self::PERIOD,
            false,
            25,
            0,
            $employmentId,
        );
        self::assertCount(1, $page['items']);
        $item = $page['items'][0];
        self::assertIsArray($item);
        $check = $item['compensatory_time_off_check'];
        self::assertIsArray($check);

        return $check;
    }

    private function seedEmployment(): int
    {
        $pdo = $this->db->pdo();
        $ordinal = ++$this->sequence;
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 30000, 0, 1)'
        )->execute([$this->supplierId, sprintf('Synteticka Osoba %02d', $ordinal)]);
        $employeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", "2026-01-01")'
        )->execute([$this->supplierId, $employeeId, sprintf('ZAM-%02d', $ordinal)]);

        return (int) $pdo->lastInsertId();
    }

    private function seedAbsence(int $employmentId, string $from, string $to): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_absences
                (supplier_id, employment_id, absence_type, date_from, date_to,
                 compensation_policy, support_status, status)
             VALUES (?, ?, "compensatory_time_off", ?, ?, "none", "supported", "approved")'
        )->execute([$this->supplierId, $employmentId, $from, $to]);
    }

    private function seedCompensation(
        int $employmentId,
        string $overtimeDate,
        int $minutes,
        ?string $grantedOn,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_overtime_compensations
                (supplier_id, employment_id, overtime_date, minutes, granted_on)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $employmentId, $overtimeDate, $minutes, $grantedOn]);
    }
}
