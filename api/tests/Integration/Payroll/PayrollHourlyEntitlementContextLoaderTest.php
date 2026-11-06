<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollHourlyEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageCalculator;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageContext;
use MyInvoice\Service\Payroll\PayrollHourlyEntitlementContextLoader;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBatchLoader;
use MyInvoice\Service\Payroll\Time\PayrollWorkedTimeEvidence;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollHourlyEntitlementContextLoaderTest extends TestCase
{
    use IsolatedSupplierTrait;
    private Connection $db;
    private PayrollTimeRepository $time;
    private int $supplier;
    private int $user;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->time = $container->get(PayrollTimeRepository::class);
        $pdo = $this->db->pdo();
        $original = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->user = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $original);
        self::assertGreaterThan(0, $this->user);
        $pdo->beginTransaction();
        $this->supplier = $this->createIsolatedSupplier($pdo, $original);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        $this->db->close();
    }

    public function testZeroManualMonthIsCompleteWithoutAverageOrScheduleAndFreezesProofs(): void
    {
        $id = $this->employment();
        [$context, $terms, $source] = $this->context($id);
        self::assertNull($context['average_earning']);
        self::assertSame([], $context['schedule_source']);
        self::assertSame([], $context['ledger_source']['entitlements']);
        self::assertSame('statutory', $context['policy_versions'][0]['source']);
        $result = (new PayrollHourlyWageCalculator())->calculate(new PayrollHourlyWageContext($id, '2026-06-01', 'employment', $terms, $context['work_segments'], $source, entitlementContext: $context));
        self::assertTrue($result->complete, json_encode($result->blockers));
        self::assertSame(0, array_sum(array_column($result->claims, 'payable_minor')));
        self::assertSame($context, $this->context($id)[0]);
        $this->db->pdo()->prepare('INSERT INTO payroll_employment_surcharge_policies (supplier_id, employment_id, valid_from, agreement_reference, night_rate_bp) VALUES (?, ?, "2026-06-01", "Syntetické sjednání", 2000)')->execute([$this->supplier, $id]);
        $changed = $this->context($id)[0];
        self::assertNotSame($context, $changed);
        self::assertSame(2000, $changed['policy_versions'][0]['rates']['night']['agreed_rate_bp']);
    }

    public function testChangedSelectedTimeSourceDoesNotReuseEarlierPremiumConfirmation(): void
    {
        $id = $this->employment();
        [$context] = $this->context($id);
        self::assertIsArray($context['premium_segments']);
        $this->db->pdo()->prepare('UPDATE payroll_time_months SET revision_no = revision_no + 1, row_version = row_version + 1 WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplier, $id]);
        $changed = $this->context($id)[0];
        self::assertNull($changed['premium_segments']);
        self::assertNotNull($changed['evidence_source']);
        // Červen nemá svátek, nulu proto posoudí server sám; ruční příplatky ne.
        self::assertSame([], $changed['nonworked_holidays']);
        self::assertSame('no_holiday_on_hourly_day', $changed['automatic_assessment']['rules']['nonworked_holidays']);
    }

    public function testOrdinaryAttendanceMonthIsCompleteWithoutSavedEvidence(): void
    {
        $id = $this->attendanceEmployment('2026-06-01', 'employment');
        [$context, $terms, $source] = $this->context($id);
        self::assertNull($context['evidence_source']);
        self::assertSame([], $context['nonworked_holidays']);
        self::assertSame([], $context['holiday_time_off_grants']);
        self::assertSame([], $context['overtime_time_off_allocations']);
        self::assertSame(['rule_version' => PayrollHourlyEntitlementContextLoader::AUTOMATIC_RULES, 'rules' => [
            'nonworked_holidays' => 'no_holiday_on_hourly_day', 'holiday_time_off_grants' => 'no_holiday_time_off_entitlement',
            'overtime_time_off_allocations' => 'no_overtime_time_off_entitlement']], $context['automatic_assessment']);
        $result = (new PayrollHourlyWageCalculator())->calculate(new PayrollHourlyWageContext($id, '2026-06-01', 'employment', $terms, $context['work_segments'], $source, entitlementContext: $context));
        self::assertTrue($result->complete, json_encode($result->blockers));
        self::assertSame(160000, array_sum(array_column($result->claims, 'payable_minor')));
    }

    public function testHolidayOnScheduledDayStillNeedsExplicitAssessment(): void
    {
        $id = $this->attendanceEmployment('2026-07-01', 'employment', shiftOn: '2026-07-06');
        [$context, $terms, $source] = $this->context($id, '2026-07-01');
        self::assertNull($context['nonworked_holidays']);
        self::assertSame([], $context['holiday_time_off_grants']);
        self::assertArrayNotHasKey('nonworked_holidays', $context['automatic_assessment']['rules']);
        $result = (new PayrollHourlyWageCalculator())->calculate(new PayrollHourlyWageContext($id, '2026-07-01', 'employment', $terms, $context['work_segments'], $source, entitlementContext: $context));
        self::assertFalse($result->complete);
        self::assertContains('hourly_holiday_evidence_missing', array_column($result->blockers, 'code'));
    }

    public function testAgreementNeverReceivesNonworkedHolidayCompensation(): void
    {
        $id = $this->attendanceEmployment('2026-07-01', 'dpp', shiftOn: '2026-07-06');
        [$context, $terms, $source] = $this->context($id, '2026-07-01');
        self::assertSame([], $context['nonworked_holidays']);
        self::assertSame('agreement_not_entitled', $context['automatic_assessment']['rules']['nonworked_holidays']);
        $result = (new PayrollHourlyWageCalculator())->calculate(new PayrollHourlyWageContext($id, '2026-07-01', 'dpp', $terms, $context['work_segments'], $source, entitlementContext: $context));
        self::assertTrue($result->complete, json_encode($result->blockers));
        self::assertNotContains('NAHRADA_MZDY_SVATEK', array_column($result->claims, 'component_code'));
        $options = (new PayrollHourlyEntitlementContextLoader($this->db))->evidenceOptions($this->supplier, $id, '2026-07-01');
        self::assertSame([], $options['nonworked_holidays']);
        $this->expectException(\InvalidArgumentException::class);
        (new PayrollHourlyEntitlementContextLoader($this->db))->validateSelections($this->supplier, $id, '2026-07-01',
            ['nonworked_holidays' => [['date' => '2026-07-06', 'duration_units' => 24000]], 'holiday_time_off_grants' => [], 'overtime_time_off_allocations' => []], $source);
    }

    public function testApprovingAttendanceKeepsSavedEvidenceCurrent(): void
    {
        $id = $this->attendanceEmployment('2026-07-01', 'employment', shiftOn: '2026-07-06', approved: false);
        [, , $source] = $this->context($id, '2026-07-01');
        (new PayrollHourlyEvidenceRepository($this->db))->save($this->supplier, $id, '2026-07-01', ['row_version' => 0, 'work_source_hash' => PayrollHourlyEvidenceRepository::bindingHash($source),
            'premium_segments' => [], 'nonworked_holidays' => [], 'holiday_time_off_grants' => [], 'overtime_time_off_allocations' => []], $this->user, static fn() => null);
        $this->db->pdo()->prepare('UPDATE payroll_time_entries SET status = "approved", approved_at = NOW() WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplier, $id]);
        $this->db->pdo()->prepare('UPDATE payroll_time_months SET status = "approved", approved_at = NOW(), row_version = row_version + 1 WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplier, $id]);
        [$context] = $this->context($id, '2026-07-01');
        self::assertNotNull($context['evidence_source']);
        self::assertSame([], $context['nonworked_holidays']);
        self::assertArrayNotHasKey('automatic_assessment', $context);
        $history = (new PayrollHourlyEvidenceRepository($this->db))->history($this->supplier, $id, '2026-07-01', 0)['history'];
        self::assertNull($history[0]['reason']);
    }

    public function testBatchQueryCountDoesNotGrowPerEmploymentAndTenantScopeIsRespected(): void
    {
        $ids = [$this->employment(), $this->employment(), $this->employment(), $this->employment()];
        $loader = new PayrollHourlyEntitlementContextLoader($this->db);
        $terms = (new PayrollRunSnapshotBatchLoader($this->db))->hourlyRemunerationTerms($this->supplier, $ids, '2026-06-01', '2026-06-30');
        $sources = (new PayrollWorkedTimeEvidence($this->db))->loadMany($this->supplier, $ids, '2026-06-01');
        $loader->forMany($this->supplier, '2026-06-01', [], [$ids[0] => $terms[$ids[0]]], $sources);
        $before = $this->questions();
        $single = $loader->forMany($this->supplier, '2026-06-01', [], [$ids[0] => $terms[$ids[0]]], $sources);
        $singleCount = $this->questions() - $before;
        $before = $this->questions();
        $batch = $loader->forMany($this->supplier, '2026-06-01', [], $terms, $sources);
        $batchCount = $this->questions() - $before;
        self::assertCount(4, $batch);
        self::assertSame($singleCount, $batchCount);
        self::assertLessThanOrEqual(15, $batchCount);
        self::assertSame($single[$ids[0]], $batch[$ids[0]]);
        $other = $this->createIsolatedSupplier($this->db->pdo(), $this->supplier);
        $foreign = $loader->forMany($other, '2026-06-01', [], $terms, $sources);
        self::assertNull($foreign[$ids[0]]['evidence_source']);
        self::assertSame([], $foreign[$ids[0]]['policy_source']);
    }

    public function testAgreementHoursDoNotDefineStatutoryWeekAndConflictingEmploymentOverrideFailsClosed(): void
    {
        $dpp = $this->employment();
        $dpc = $this->employment();
        $hpp = $this->employment();
        $pdo = $this->db->pdo();
        $update = $pdo->prepare('UPDATE payroll_employment_terms SET weekly_hours = ?, workload_basis_points = ? WHERE supplier_id = ? AND employment_id = ?');
        $update->execute([10, 10000, $this->supplier, $dpp]);
        $update->execute([20, 5000, $this->supplier, $dpc]);
        $update->execute([37.5, 10000, $this->supplier, $hpp]);
        $policy = json_encode(['minimum_wage' => ['assessment' => 'hourly', 'statutory_weekly_minutes' => 2400,
            'statutory_weekly_reference' => 'Syntetický rozvrh']], JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT INTO payroll_employment_surcharge_policies (supplier_id, employment_id, valid_from, agreement_reference, hourly_terms_json) VALUES (?, ?, "2026-06-01", "Syntetické podmínky", ?)')->execute([$this->supplier, $hpp, $policy]);
        $ids = [$dpp, $dpc, $hpp];
        $terms = (new PayrollRunSnapshotBatchLoader($this->db))->hourlyRemunerationTerms($this->supplier, $ids, '2026-06-01', '2026-06-30');
        $sources = (new PayrollWorkedTimeEvidence($this->db))->loadMany($this->supplier, $ids, '2026-06-01');
        $contexts = (new PayrollHourlyEntitlementContextLoader($this->db))->forMany($this->supplier, '2026-06-01', [
            ['employment_id' => $dpp, 'relation_type' => 'dpp'], ['employment_id' => $dpc, 'relation_type' => 'dpc'],
            ['employment_id' => $hpp, 'relation_type' => 'employment']], $terms, $sources);
        foreach ([$dpp, $dpc] as $id) {
            self::assertSame(2400, $contexts[$id]['minimum_floor_segments'][0]['stated_weekly_minutes']);
            self::assertSame(13440, $contexts[$id]['minimum_floor_segments'][0]['minimum_hourly_minor']);
        }
        self::assertTrue($contexts[$hpp]['minimum_floor_segments'][0]['weekly_hours_conflict']);
        self::assertNull($contexts[$hpp]['minimum_floor_segments'][0]['minimum_hourly_minor']);
    }

    public function testPreviewCalculatesMinimumTopUpFromConfirmed160Hours(): void
    {
        $id = $this->employment();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employment_terms SET hourly_rate_minor = 13000, weekly_hours = 40, workload_basis_points = 10000 WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplier, $id]);
        $month = $this->time->monthState($this->supplier, $id, '2026-06-01');
        $this->time->reopenMonth($this->supplier, $id, '2026-06-01', (int) $month['row_version'], 'Oprava syntetických hodin', $this->user);
        $month = $this->time->monthState($this->supplier, $id, '2026-06-01');
        $this->time->saveManualSummary($this->supplier, $id, '2026-06-01', (int) $month['row_version'], 'Doložených 160 hodin', false,
            [['date_from' => '2026-06-01', 'date_to' => '2026-06-30', 'worked_duration_units' => 480000, 'overtime_duration_units' => 0, 'worked_days' => 20]], $this->user);
        $month = $this->time->monthState($this->supplier, $id, '2026-06-01');
        $this->time->approveMonth($this->supplier, $id, '2026-06-01', (int) $month['row_version'], null, $this->user);
        $source = (new PayrollWorkedTimeEvidence($this->db))->loadMany($this->supplier, [$id], '2026-06-01')[$id];
        $body = ['row_version' => 1, 'reason' => 'Výslovně bez dalších nároků', 'work_source_hash' => PayrollHourlyEvidenceRepository::bindingHash($source),
            'premium_segments' => [['date_from' => '2026-06-01', 'date_to' => '2026-06-30', 'night_duration_units' => 0, 'weekend_duration_units' => 0, 'holiday_work_duration_units' => 0, 'difficult_exposures' => [], 'holiday_work_days' => [], 'overtime_work_days' => []]],
            'nonworked_holidays' => [], 'holiday_time_off_grants' => [], 'overtime_time_off_allocations' => []];
        (new PayrollHourlyEvidenceRepository($this->db))->save($this->supplier, $id, '2026-06-01', $body, $this->user, static fn() => null);
        $rows = [['employment_id' => $id, 'relation_type' => 'employment', 'start_date' => '2026-01-01', 'end_date' => null]];
        $preview = (new \MyInvoice\Service\Payroll\PayrollHourlyWagePreview($this->db))->forMany($this->supplier, '2026-06-01', $rows)[$id];
        self::assertTrue($preview['minimum_wage']['complete'], json_encode($preview['minimum_wage']['blockers']));
        self::assertSame(70400, $preview['minimum_wage']['claim']['payable_minor']);
        self::assertSame(70400, array_values(array_filter($preview['claims'], static fn(array $claim): bool => $claim['purpose'] === 'minimum_wage_top_up'))[0]['payable_minor']);
        self::assertSame(2150400, $preview['payable_total_minor']);
    }

    private function context(int $id, string $period = '2026-06-01'): array
    {
        $end = (new \DateTimeImmutable($period))->modify('last day of this month')->format('Y-m-d');
        $terms = (new PayrollRunSnapshotBatchLoader($this->db))->hourlyRemunerationTerms($this->supplier, [$id], $period, $end);
        $sources = (new PayrollWorkedTimeEvidence($this->db))->loadMany($this->supplier, [$id], $period);
        $type = (string) $this->db->pdo()->query('SELECT relation_type FROM payroll_employments WHERE id = ' . $id)->fetchColumn();
        return [(new PayrollHourlyEntitlementContextLoader($this->db))->forMany($this->supplier, $period, [['employment_id' => $id, 'relation_type' => $type]], $terms, $sources)[$id], $terms[$id], $sources[$id]];
    }

    /** Hodinový vztah s docházkou: jeden osmihodinový den, volitelně směna ve svátek. */
    private function attendanceEmployment(string $period, string $type, ?string $shiftOn = null, bool $approved = true): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, "Syntetická docházka", "employee", 1)')->execute([$this->supplier]);
        $person = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, start_date) VALUES (?, ?, ?, ?, "active", "2026-01-01")')->execute([$this->supplier, $person, 'SYN-ATT-' . $person, $type]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, effective_from, planned_start_on, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis) VALUES (?, ?, "2026-01-01", "2026-01-01", "hourly", 20000, "contract", "Syntetické podmínky")')->execute([$this->supplier, $id]);
        $status = $approved ? 'approved' : 'open';
        $pdo->prepare('INSERT INTO payroll_time_months (supplier_id, employment_id, period_start, work_source, status, approved_at) VALUES (?, ?, ?, "entries", ?, ?)')->execute([$this->supplier, $id, $period, $status, $approved ? date('Y-m-d H:i:s') : null]);
        $day = substr($period, 0, 8) . '02';
        $pdo->prepare('INSERT INTO payroll_time_entries (supplier_id, employment_id, series_key, revision_no, category, starts_at_utc, ends_at_utc, timezone_name, break_minutes, source_kind, source_hash, status, approved_at) VALUES (?, ?, ?, 1, "regular", ?, ?, "Europe/Prague", 0, "manual", ?, ?, ?)')
            ->execute([$this->supplier, $id, bin2hex(random_bytes(16)), $day . ' 06:00:00', $day . ' 14:00:00', hash('sha256', 'entry-' . $id, true), $approved ? 'approved' : 'draft', $approved ? date('Y-m-d H:i:s') : null]);
        if ($shiftOn !== null) {
            $pdo->prepare('INSERT INTO payroll_shifts (supplier_id, employment_id, series_key, starts_at_utc, ends_at_utc, timezone_name, status, published_at) VALUES (?, ?, ?, ?, ?, "Europe/Prague", "published", NOW())')
                ->execute([$this->supplier, $id, bin2hex(random_bytes(16)), $shiftOn . ' 06:00:00', $shiftOn . ' 14:00:00']);
        }
        return $id;
    }

    private function questions(): int { return (int) $this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value']; }

    private function employment(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, "Syntetický nulový měsíc", "employee", 1)')->execute([$this->supplier]);
        $person = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, start_date) VALUES (?, ?, ?, "employment", "active", "2026-01-01")')->execute([$this->supplier, $person, 'SYN-HOUR-' . $person]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, effective_from, planned_start_on, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis) VALUES (?, ?, "2026-01-01", "2026-01-01", "hourly", 20000, "contract", "Syntetické podmínky")')->execute([$this->supplier, $id]);
        $this->time->saveManualSummary($this->supplier, $id, '2026-06-01', 0, 'Výslovná nula', true, [['date_from' => '2026-06-01', 'date_to' => '2026-06-30', 'worked_duration_units' => 0, 'overtime_duration_units' => 0, 'worked_days' => 0]], $this->user);
        $pdo->prepare('UPDATE payroll_time_months SET status = "approved", approved_at = NOW() WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplier, $id]);
        $source = (new PayrollWorkedTimeEvidence($this->db))->loadMany($this->supplier, [$id], '2026-06-01')[$id];
        $body = ['row_version' => 0, 'reason' => 'Výslovně bez dalších nároků', 'work_source_hash' => PayrollHourlyEvidenceRepository::bindingHash($source),
            'premium_segments' => [['date_from' => '2026-06-01', 'date_to' => '2026-06-30', 'night_duration_units' => 0, 'weekend_duration_units' => 0, 'holiday_work_duration_units' => 0, 'difficult_exposures' => [], 'holiday_work_days' => [], 'overtime_work_days' => []]],
            'nonworked_holidays' => [], 'holiday_time_off_grants' => [], 'overtime_time_off_allocations' => []];
        (new PayrollHourlyEvidenceRepository($this->db))->save($this->supplier, $id, '2026-06-01', $body, $this->user, static fn() => null);
        return $id;
    }
}
