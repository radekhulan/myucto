<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Hourly;

use MyInvoice\Service\Payroll\Calculation\PayrollWageRounding;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageCalculator;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageContext;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class PayrollHourlyWageCalculatorTest extends TestCase
{
    public function testBaseRequiresNeitherAverageNorCalendarAndSeparatesOvertime(): void
    {
        $result = (new PayrollHourlyWageCalculator())->calculate($this->context([
            $this->segment(504000, 24000),
        ]));
        // Tarifní mzda zůstává připravená i bez PV pro samostatný přesčasový příplatek.
        self::assertFalse($result->complete);
        $base = array_slice($result->claims, 0, 2);
        self::assertSame([true, true], array_column($base, 'ready'));
        self::assertSame(['ordinary_base', 'achieved_overtime'], array_column($base, 'purpose'));
        self::assertSame([3200000, 160000], array_column($base, 'payable_minor'));
        self::assertSame([480000, 24000], array_column($base, 'duration_units'));
    }

    public function testExactDecimalAndMinuteUnionUsesNoProjection(): void
    {
        $result = (new PayrollHourlyWageCalculator())->calculate($this->context([$this->segment(53, 0)]));
        self::assertTrue($result->complete);
        self::assertSame(353, $result->claims[0]['analytical_minor']);
        self::assertSame(400, $result->claims[0]['payable_minor']);
    }

    public function testExplicitZeroDiffersFromUnknownAndMissingSource(): void
    {
        $calculator = new PayrollHourlyWageCalculator();
        self::assertTrue($calculator->calculate($this->context([$this->segment(0, 0)]))->complete);
        $unknown = $calculator->calculate($this->context([$this->segment(3000, null)]));
        self::assertFalse($unknown->complete);
        self::assertContains('hourly_overtime_not_provided', array_column($unknown->blockers, 'code'));
        $context = $this->context([$this->segment(3000, 0)]);
        $missing = $calculator->calculate(new PayrollHourlyWageContext(7, '2026-06-01', 'employment', $context->terms, $context->segments, null));
        self::assertContains('hourly_time_missing', array_column($missing->blockers, 'code'));
    }

    public function testRateChangeNeedsExplicitSourcePartitionAndRoundsOneBaseClaim(): void
    {
        $terms = [
            [...$this->term(), 'effective_to' => '2026-06-15', 'hourly_rate_minor' => 18000],
            [...$this->term(), 'id' => 2, 'effective_from' => '2026-06-16'],
        ];
        $segments = [
            [...$this->segment(240000, 0), 'date_to' => '2026-06-15'],
            [...$this->segment(264000, 0), 'date_from' => '2026-06-16'],
        ];
        $calculator = new PayrollHourlyWageCalculator();
        $good = $calculator->calculate($this->context($segments, $terms));
        self::assertTrue($good->complete);
        self::assertSame(3200000, $good->claims[0]['payable_minor']);
        self::assertCount(2, $good->claims[0]['segments']);
        $bad = $calculator->calculate($this->context([$this->segment(504000, 0)], $terms));
        self::assertContains('hourly_segment_crosses_terms', array_column($bad->blockers, 'code'));
    }

    public function testDppDoesNotInventOvertimeEntitlement(): void
    {
        $context = $this->context([$this->segment(24000, 3000)]);
        $result = (new PayrollHourlyWageCalculator())->calculate(new PayrollHourlyWageContext(7, '2026-06-01', 'dpp', $context->terms, $context->segments, $context->workedTimeEvidence));
        self::assertContains('hourly_agreement_overtime_invalid', array_column($result->blockers, 'code'));
        self::assertSame([], $result->claims);
    }

    public function testMixedMonthlyAndHourlyPartsUseOneOrdinaryClaimAndRequireFundEvidence(): void
    {
        $terms = [
            [...$this->term(), 'remuneration_type' => 'monthly', 'hourly_rate_minor' => null, 'monthly_gross_minor' => 3000000, 'effective_to' => '2026-06-15'],
            [...$this->term(), 'id' => 2, 'effective_from' => '2026-06-16'],
        ];
        $segments = [[...$this->segment(120000, 0), 'date_to' => '2026-06-15'], [...$this->segment(240000, 0), 'date_from' => '2026-06-16']];
        $input = $this->context($segments, $terms);
        $calculator = new PayrollHourlyWageCalculator();
        $missing = $calculator->calculate($input);
        self::assertContains('hourly_monthly_partition_missing', array_column($missing->blockers, 'code'));
        $result = $calculator->calculate(new PayrollHourlyWageContext(7, '2026-06-01', 'employment', $terms, $segments, $input->workedTimeEvidence,
            monthlyParts: [['term_id' => 1, 'date_from' => '2026-06-01', 'date_to' => '2026-06-15', 'fund_duration_units' => 480000, 'retained_duration_units' => 240000]], entitlementContext: $input->entitlementContext));
        self::assertTrue($result->complete);
        self::assertSame(3100000, $result->claims[0]['payable_minor']);
        self::assertSame(480000, $result->claims[0]['duration_units']);
        self::assertSame(360000, $result->claims[0]['worked_duration_units']);
        self::assertSame([1, 2], array_column($result->claims[0]['segments'], 'term_id'));
    }

    public function testManualAllocationCannotMoveHoursToAnotherRateWithSameTotal(): void
    {
        $terms = [[...$this->term(), 'effective_to' => '2026-06-15'], [...$this->term(), 'id' => 2, 'effective_from' => '2026-06-16', 'hourly_rate_minor' => 40000]];
        $segments = [[...$this->segment(3000, 0), 'date_to' => '2026-06-15'], [...$this->segment(3000, 0), 'date_from' => '2026-06-16']];
        $original = $this->context($segments, $terms);
        $segments[0]['worked_duration_units'] = 0;
        $segments[1]['worked_duration_units'] = 6000;
        $result = (new PayrollHourlyWageCalculator())->calculate(new PayrollHourlyWageContext(7, '2026-06-01', 'employment', $terms, $segments, $original->workedTimeEvidence));
        self::assertContains('hourly_source_partition_mismatch', array_column($result->blockers, 'code'));
        self::assertSame([], $result->claims);
    }

    public function testMissingApprovalCorruptHashAndImportedSourceFailClosed(): void
    {
        $original = $this->context([$this->segment(3000, 0)]);
        foreach ([['status', 'open', 'hourly_time_not_approved'], ['source_sha256', str_repeat('0', 64), 'hourly_time_evidence_invalid'], ['work_source', 'import_summary', 'hourly_import_source_unsupported']] as [$field, $value, $code]) {
            $source = [...$original->workedTimeEvidence, $field => $value];
            $result = (new PayrollHourlyWageCalculator())->calculate(new PayrollHourlyWageContext(7, '2026-06-01', 'employment', $original->terms, $original->segments, $source));
            self::assertContains($code, array_column($result->blockers, 'code'));
            self::assertSame([], $result->claims);
        }
    }

    public function testNativeEntriesConfirmExactTermAllocation(): void
    {
        $terms = [[...$this->term(), 'effective_to' => '2026-06-15'], [...$this->term(), 'id' => 2, 'effective_from' => '2026-06-16', 'hourly_rate_minor' => 40000]];
        $segments = [[...$this->segment(3000, 0), 'date_to' => '2026-06-15'], [...$this->segment(3000, 0), 'date_from' => '2026-06-16']];
        $original = $this->context($segments, $terms);
        $source = $original->workedTimeEvidence;
        unset($source['manual_summary'], $source['source_sha256']);
        $source['work_source'] = 'entries';
        $source['time_entries'] = array_map(static fn(string $date): array => ['starts_at_utc' => "$date 06:00:00", 'ends_at_utc' => "$date 07:00:00", 'timezone_name' => 'Europe/Prague', 'category' => 'regular', 'break_minutes' => 0, 'status' => 'approved'], ['2026-06-10', '2026-06-20']);
        $source['source_sha256'] = hash('sha256', CanonicalJson::encode($source));
        $calculator = new PayrollHourlyWageCalculator();
        $good = $calculator->calculate(new PayrollHourlyWageContext(7, '2026-06-01', 'employment', $terms, $segments, $source, entitlementContext: $original->entitlementContext));
        self::assertTrue($good->complete);
        self::assertSame(60000, $good->claims[0]['payable_minor']);
        $segments[0]['worked_duration_units'] = 0;
        $segments[1]['worked_duration_units'] = 6000;
        $bad = $calculator->calculate(new PayrollHourlyWageContext(7, '2026-06-01', 'employment', $terms, $segments, $source, entitlementContext: $original->entitlementContext));
        self::assertContains('hourly_source_partition_mismatch', array_column($bad->blockers, 'code'));
    }

    private function context(array $segments, ?array $terms = null): PayrollHourlyWageContext
    {
        $source = ['schema_version' => 'payroll-worked-time.v1', 'duration_units_per_hour' => 3000,
            'time_month_id' => 9, 'revision_no' => 1, 'row_version' => 3, 'status' => 'approved',
            'work_source' => 'manual_summary', 'period_start' => '2026-06-01',
            'manual_summary' => ['id' => 11, 'time_month_id' => 9, 'time_month_revision_no' => 1, 'segments' => $segments]];
        $source['source_sha256'] = hash('sha256', CanonicalJson::encode($source));
        return new PayrollHourlyWageContext(7, '2026-06-01', 'employment', $terms ?? [$this->term()], $segments, $source, PayrollWageRounding::Ceil, entitlementContext: $this->zeroPremiumEvidence($segments));
    }

    private function zeroPremiumEvidence(array $segments): array
    {
        return ['schema_version' => 'hourly-entitlements.v1', 'as_of' => '2026-06-30',
            'ruleset' => ['id' => 1, 'content_sha256' => str_repeat('a', 64)],
            'policy_versions' => [['id' => 1, 'revision_no' => 1, 'content_sha256' => str_repeat('b', 64),
                'date_from' => '2026-06-01', 'date_to' => '2026-06-30', 'overtime_mode' => 'surcharge',
                'rates' => ['overtime' => ['statutory_basis' => 'average', 'statutory_rate_bp' => 2500]]]],
            'premium_segments' => array_map(static fn(array $s): array => ['date_from' => $s['date_from'], 'date_to' => $s['date_to'],
                'night_duration_units' => 0, 'weekend_duration_units' => 0, 'holiday_work_duration_units' => 0, 'difficult_exposures' => []], $segments),
            'nonworked_holidays' => [], 'holiday_time_off_grants' => []];
    }

    private function segment(int $worked, ?int $overtime): array
    {
        return ['date_from' => '2026-06-01', 'date_to' => '2026-06-30', 'worked_duration_units' => $worked, 'overtime_duration_units' => $overtime, 'worked_days' => null];
    }

    private function term(): array
    {
        return ['id' => 1, 'row_version' => 1, 'effective_from' => '2026-01-01', 'effective_to' => null,
            'remuneration_type' => 'hourly', 'hourly_rate_minor' => 20000, 'monthly_gross_minor' => null];
    }
}
