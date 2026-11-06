<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollHourlyEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRounding;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageCalculator;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageContext;
use MyInvoice\Service\Payroll\PayrollHourlyWagePreview;
use MyInvoice\Service\Payroll\Time\PayrollWorkedTimeEvidence;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollHourlySpecifiedScenariosTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollTimeRepository $time;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->time = $container->get(PayrollTimeRepository::class);
        $pdo = $this->db->pdo();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $source);
        self::assertGreaterThan(0, $this->userId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        $this->db->close();
    }

    public function testC1AchievedOvertimeAndItsPremiumProduce34020Czk(): void
    {
        $preview = $this->preview(20000, 21000, [$this->segment(168, 8)]);
        $claims = array_column($preview['claims'], null, 'purpose');
        self::assertSame(3200000, $claims['ordinary_base']['payable_minor']);
        self::assertSame(160000, $claims['achieved_overtime']['payable_minor']);
        self::assertSame(42000, $claims['premium_overtime']['payable_minor']);
        self::assertSame(160 * 3000, $claims['ordinary_base']['worked_duration_units']);
        self::assertSame(8 * 3000, $claims['achieved_overtime']['worked_duration_units']);
        self::assertSame(0, $claims['premium_overtime']['worked_duration_units']);
        self::assertSame(0, $claims['minimum_wage_top_up']['payable_minor']);
        self::assertSame(3402000, $preview['payable_total_minor']);
    }

    public function testC2SameSixteenWeekendNightHoursReceiveBothPremiumsAndTotal29560Czk(): void
    {
        $preview = $this->preview(18000, 19000, [
            $this->segment(40, night: 8, from: '2026-06-01', to: '2026-06-05'),
            $this->segment(16, night: 16, weekend: 16, from: '2026-06-06', to: '2026-06-07'),
            $this->segment(104, from: '2026-06-08', to: '2026-06-30'),
        ]);
        $claims = array_column($preview['claims'], null, 'purpose');
        self::assertSame(2880000, $claims['ordinary_base']['payable_minor']);
        self::assertSame(45600, $claims['premium_night']['payable_minor']);
        self::assertSame(30400, $claims['premium_weekend']['payable_minor']);
        self::assertSame(24 * 3000, $claims['premium_night']['duration_units']);
        self::assertSame(16 * 3000, $claims['premium_weekend']['duration_units']);
        self::assertSame(0, $claims['premium_night']['worked_duration_units']);
        self::assertSame(0, $claims['premium_weekend']['worked_duration_units']);
        self::assertSame(2956000, $preview['payable_total_minor']);
    }

    public function testD2MinimumIgnoresAchievedOvertimeAndPremiumsAndTotalIs23030Czk(): void
    {
        $preview = $this->preview(13000, 13500, [$this->segment(168, overtime: 8, night: 16)]);
        $claims = array_column($preview['claims'], null, 'purpose');
        self::assertSame(2080000, $claims['ordinary_base']['payable_minor']);
        self::assertSame(104000, $claims['achieved_overtime']['payable_minor']);
        self::assertSame(27000, $claims['premium_overtime']['payable_minor']);
        self::assertSame(21600, $claims['premium_night']['payable_minor']);
        self::assertSame(70400, $claims['minimum_wage_top_up']['payable_minor']);
        $assessment = $preview['minimum_wage']['assessment_evidence'];
        self::assertSame(2080000, $assessment['eligible']['analytical_minor']);
        self::assertSame(2150400, $assessment['threshold']['analytical_minor']);
        self::assertSame(160 * 3000, $assessment['parts'][0]['regular_worked_duration_units']);
        self::assertSame(2303000, $preview['payable_total_minor']);
    }

    public function testC5DifficultEnvironmentUsesMinimumAndKeeps53760RawBefore538CzkPayment(): void
    {
        // PV úmyslně jiný než minimální mzda: § 117 musí použít druhý základ.
        $preview = $this->preview(20000, 99000, [$this->segment(160, difficult: 40)]);
        $claims = array_column($preview['claims'], null, 'purpose');
        $claim = $claims['premium_difficult_environment'];
        self::assertSame(53760, $claim['raw_numerator_minor']);
        self::assertSame(1, $claim['raw_denominator']);
        self::assertSame(53760, $claim['analytical_minor']);
        self::assertSame(53800, $claim['payable_minor']);
        self::assertSame(40 * 3000, $claim['duration_units']);
        self::assertSame(0, $claim['worked_duration_units']);
        self::assertSame(3253800, $preview['payable_total_minor']);
        // Stejné zmrazené podklady: historická firemní varianta HalfUp také 538 Kč.
        $source = $preview['_source_snapshot'];
        $halfUp = (new PayrollHourlyWageCalculator())->calculate(new PayrollHourlyWageContext(
            1, '2026-06-01', 'employment', $source['terms'], $source['segments'],
            $source['worked_time_evidence'], PayrollWageRounding::HalfUp,
            $source['monthly_parts'], $source['entitlement_context'],
        ));
        self::assertTrue($halfUp->complete, json_encode($halfUp->blockers));
        $halfUpClaim = array_column($halfUp->claims, null, 'purpose')['premium_difficult_environment'];
        self::assertSame(53760, $halfUpClaim['analytical_minor']);
        self::assertSame(53800, $halfUpClaim['payable_minor']);
    }

    public function testDifficultEnvironmentForShorterStatedWeekUsesFortyHourMinimumBase(): void
    {
        // § 118 odst. 1 ZP: 10 % základní sazby minimální mzdy, ne sazby přepočtené na 37,5 h.
        $preview = $this->preview(20000, 99000, [$this->segment(150, difficult: 40)], '37.5');
        $claim = array_column($preview['claims'], null, 'purpose')['premium_difficult_environment'];
        self::assertSame(53760, $claim['analytical_minor']);
        self::assertSame(53800, $claim['payable_minor']);
    }

    /** @return array<string,mixed> */
    private function segment(int $worked, int $overtime = 0, int $night = 0, int $weekend = 0, int $difficult = 0,
        string $from = '2026-06-01', string $to = '2026-06-30'): array
    {
        return ['date_from' => $from, 'date_to' => $to, 'worked_duration_units' => $worked * 3000,
            'overtime_duration_units' => $overtime * 3000, 'worked_days' => null,
            'night_duration_units' => $night * 3000, 'weekend_duration_units' => $weekend * 3000,
            'holiday_work_duration_units' => 0, 'holiday_work_days' => [], 'overtime_work_days' => [],
            'difficult_exposures' => $difficult === 0 ? [] : [['duration_units' => $difficult * 3000, 'factors' => 1]]];
    }

    /** @param list<array<string,mixed>> $segments @return array<string,mixed> */
    private function preview(int $rate, int $average, array $segments, string $weeklyHours = '40'): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, "Syntetický hodinový scénář", "employee", 1)')->execute([$this->supplierId]);
        $employee = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, start_date) VALUES (?, ?, "SYN-HOURLY-SCENARIO", "employment", "active", "2026-01-01")')->execute([$this->supplierId, $employee]);
        $employment = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, effective_from, planned_start_on, actual_start_on,
            weekly_hours, workload_basis_points, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis)
            VALUES (?, ?, "2026-01-01", "2026-01-01", "2026-01-01", ?, 10000, "hourly", ?, "contract", "Syntetická smlouva")')->execute([$this->supplierId, $employment, $weeklyHours, $rate]);
        $pdo->prepare('INSERT INTO payroll_average_earning_snapshots (supplier_id, employment_id, applicable_year, applicable_quarter,
            revision_no, source_kind, decisive_from, decisive_to, gross_earnings_minor, longer_period_allocated_minor, worked_minutes, worked_days,
            average_hourly_minor, rationale, support_status, status, ruleset_id, ruleset_hash, input_hash, input_trace, created_by, approved_by, approved_at)
            VALUES (?, ?, 2026, 2, 1, "probable", "2026-01-01", "2026-03-31", 0, 0, 0, 0, ?, "Syntetický schválený PV", "supported", "approved",
            "synthetic-hourly-scenarios", ?, UNHEX(?), "{}", ?, ?, NOW())')->execute([$this->supplierId, $employment, $average,
                str_repeat('a', 64), hash('sha256', '{}'), $this->userId, $this->userId]);
        $work = array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['date_from', 'date_to', 'worked_duration_units', 'overtime_duration_units', 'worked_days'])), $segments);
        $this->time->saveManualSummary($this->supplierId, $employment, '2026-06-01', 0, 'Syntetické doložené hodiny', true, $work, $this->userId);
        $month = $this->time->monthState($this->supplierId, $employment, '2026-06-01');
        $this->time->approveMonth($this->supplierId, $employment, '2026-06-01', (int) $month['row_version'], null, $this->userId);
        $source = (new PayrollWorkedTimeEvidence($this->db))->loadMany($this->supplierId, [$employment], '2026-06-01')[$employment];
        $premium = array_map(static fn(array $row): array => array_diff_key($row, array_flip(['worked_duration_units', 'overtime_duration_units', 'worked_days'])), $segments);
        $body = ['row_version' => 0, 'reason' => 'Syntetické doložení příplatků', 'work_source_hash' => PayrollHourlyEvidenceRepository::bindingHash($source),
            'premium_segments' => $premium, 'nonworked_holidays' => [], 'holiday_time_off_grants' => [], 'overtime_time_off_allocations' => []];
        (new PayrollHourlyEvidenceRepository($this->db))->save($this->supplierId, $employment, '2026-06-01', $body, $this->userId, static fn() => null);
        $preview = (new PayrollHourlyWagePreview($this->db))->forMany($this->supplierId, '2026-06-01', [
            ['employment_id' => $employment, 'relation_type' => 'employment', 'start_date' => '2026-01-01', 'end_date' => null],
        ])[$employment];
        self::assertTrue($preview['complete'], json_encode($preview['blockers']));
        self::assertSame('ceil', $preview['rounding']);
        return $preview;
    }
}
