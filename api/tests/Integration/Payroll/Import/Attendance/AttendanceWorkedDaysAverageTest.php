<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Attendance;

use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Service\Payroll\Absence\AverageEarningBatchService;
use MyInvoice\Service\Payroll\Absence\AverageEarningDerivationService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoSampleProfile;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Nález N4 (fix7): agentura importuje měsíční součty (hodiny, částky) bez
 * odpracovaných dnů a skutečný průměrný výdělek od 2. čtvrtletí nevznikne
 * sám (`worked_days_not_provided`). Se sloupcem „Odpracované dny" jde počet
 * dnů z importu přes souhrn pracovního měsíce a schválený běh až do návrhu
 * průměru: § 355 odst. 1 ZP (aspoň 21 dnů) se posoudí bez ručního zadání.
 * Data jsou syntetická.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class AttendanceWorkedDaysAverageTest extends TestCase
{
    use PayrollFullFlowTrait;

    /** Měsíc → [hodiny, odpracované dny, hodinová mzda celkem v Kč]. */
    private const MONTHS = [
        '2026-01' => [50, 8, 9000],
        '2026-02' => [40, 7, 7200],
        '2026-03' => [55, 8, 9900],
    ];

    private int $officeId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('AWD', 'Syntetická účtárna agentury', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testSecondQuarterActualAverageArisesFromImportedWorkedDays(): void
    {
        $person = $this->createEmployment($this->officeId, 'Průměrná Dana', 1, 'dpp', 'dpp', 10, 2_500, true, '2026-01-01');
        foreach (['2026-02-01', '2026-03-01'] as $periodStart) {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_enforcement_person_month_evidence
                    (supplier_id, employee_id, period_start,
                     claim_register_evidence_complete, dependants_evidence_complete,
                     spouse_evidence_complete, pension_evidence, updated_by)
                 VALUES (?, ?, ?, 1, 1, 1, "none", ?)',
            )->execute([$this->supplierId, $person['employee_id'], $periodStart, $this->actors[0]]);
        }
        $import = $this->container->get(AttendanceImportService::class);
        self::assertInstanceOf(AttendanceImportService::class, $import);
        $profileId = (int) $import->restoreSampleProfile($this->supplierId, $this->actors[0], 'myucto')['profile']['id'];

        foreach (self::MONTHS as $period => [$hours, $days, $amount]) {
            $file = AttendanceFixture::file("podklady-{$period}.xlsx", AttendanceFixture::xlsx([
                AttendanceMyUctoSampleProfile::SHEET => ['rows' => [
                    1 => ['A' => 'Osobní číslo', 'B' => 'Příjmení a jméno', 'C' => 'Odpracováno (hod)', 'D' => 'Odpracované dny', 'E' => 'Hodinová mzda celkem (Kč)'],
                    2 => ['A' => 'FLOW-1', 'B' => 'Průměrná Dana', 'C' => $hours, 'D' => $days, 'E' => $amount],
                ]],
            ]));
            $applied = $import->apply(
                $this->supplierId,
                $period,
                [$file],
                null,
                [],
                false,
                true,
                $this->actors[0],
                createComponents: true,
                profileId: $profileId,
                writeTimeSummary: true,
                approveCleanTimeMonths: true,
            );
            self::assertSame([], $applied['skipped_persons'], $period);
            self::assertSame(1, $applied['time_summary']['written'], $period);
            self::assertSame(1, $applied['time_approval']['approved'] ?? null, json_encode($applied['time_approval']));
            self::assertNotContains('worked_days_not_provided', array_column($applied['time_approval']['warnings'], 'code'));

            $drafts = $this->db->pdo()->prepare('SELECT id FROM payroll_inputs WHERE supplier_id = ? AND status = "draft"');
            $drafts->execute([$this->supplierId]);
            $inputs = $this->container->get(PayrollInputRepository::class);
            self::assertInstanceOf(PayrollInputRepository::class, $inputs);
            $approvedInputs = $inputs->approveBatch($this->supplierId, array_map('intval', $drafts->fetchAll(\PDO::FETCH_COLUMN)), $this->actors[0]);
            self::assertSame([], $approvedInputs['failed'], json_encode($approvedInputs));

            $run = $this->runPayrollMonth("{$period}-01", date('Y-m-d', strtotime("{$period}-01 +1 month +12 days")), $this->officeId, "awd-{$period}");
            self::assertSame([], $run['blockers'], "{$period}: " . json_encode($run['blockers']));
            self::assertNotNull($run['approved'], $period);
        }

        $derivation = $this->container->get(AverageEarningDerivationService::class);
        self::assertInstanceOf(AverageEarningDerivationService::class, $derivation);
        $suggestion = $derivation->suggest($this->supplierId, $person['employment_id'], 2026, 2);

        self::assertSame([], $suggestion['blockers'], json_encode($suggestion['actual_blockers']));
        self::assertTrue($suggestion['ready']);
        self::assertSame('actual', $suggestion['source_kind']);
        self::assertSame([8, 7, 8], array_column($suggestion['months'], 'worked_days'));
        self::assertSame(23, $suggestion['worked_days']);
        self::assertSame(2_610_000, $suggestion['gross_earnings_minor']);
        self::assertSame(145 * 60, $suggestion['worked_minutes']);

        // Průměr vznikne hromadným schválením bez ručního zadání dnů: 26 100 Kč / 145 h = 180,00 Kč/h.
        $batch = $this->container->get(AverageEarningBatchService::class);
        self::assertInstanceOf(AverageEarningBatchService::class, $batch);
        $created = $batch->createBatch($this->supplierId, 2026, 2, [[
            'employment_id' => $person['employment_id'],
            'input_version' => $suggestion['input_version'],
        ]], $this->actors[0]);
        self::assertSame([], $created['skipped'], json_encode($created['skipped']));
        self::assertCount(1, $created['approved']);
        $stmt = $this->db->pdo()->prepare(
            'SELECT source_kind, worked_days, average_hourly_minor
               FROM payroll_average_earning_snapshots
              WHERE supplier_id = ? AND employment_id = ? AND applicable_year = 2026 AND applicable_quarter = 2',
        );
        $stmt->execute([$this->supplierId, $person['employment_id']]);
        $snapshot = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($snapshot);
        self::assertSame('actual', $snapshot['source_kind']);
        self::assertSame(23, (int) $snapshot['worked_days']);
        self::assertSame(18_000, (int) $snapshot['average_hourly_minor']);
    }
}
