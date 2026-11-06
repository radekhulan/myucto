<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Hourly;

use MyInvoice\Service\Payroll\Calculation\PayrollWageRounding;
use MyInvoice\Service\Payroll\Hourly\MinimumHourlyRate;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyMinimumWageCalculator;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyMinimumWageContext;
use PHPUnit\Framework\TestCase;

final class PayrollHourlyMinimumWageTest extends TestCase
{
    public function testShorterStatutoryWeekRoundsUpToTenHellers(): void
    {
        self::assertSame(13440, MinimumHourlyRate::calculate(13440, 2400));
        self::assertSame(14340, MinimumHourlyRate::calculate(13440, 2250));
        self::assertSame(13880, MinimumHourlyRate::calculate(13440, 2325));
        $this->expectException(\InvalidArgumentException::class);
        MinimumHourlyRate::calculate(13440, 0);
    }

    public function testCompanyHalfUpCannotLeavePayBelowTheExactMinimum(): void
    {
        $part = [...$this->part(), 'minimum_base40_minor' => 10770, 'regular_worked_duration_units' => 6001];
        $result = $this->calculate(parts: [$part], ordinary: 20000, rounding: PayrollWageRounding::HalfUp);
        self::assertTrue($result->complete);
        self::assertSame(21544, $result->assessmentEvidence['threshold']['analytical_minor']);
        self::assertSame(1500, $result->assessmentEvidence['policy_payable_minor']);
        self::assertSame(1600, $result->claim['payable_minor']);
        self::assertSame('ceil', $result->assessmentEvidence['statutory_floor_rounding']);
    }

    public function testActualPayableBaseAndOrdinaryBonusCountButStatutoryPremiumDoesNot(): void
    {
        $result = $this->calculate(ordinary: 13400, other: [
            ['amount_minor' => 40, 'minimum_wage_treatment' => 'included'],
            ['amount_minor' => 9000, 'minimum_wage_treatment' => 'excluded'],
        ]);
        self::assertTrue($result->complete);
        self::assertSame(0, $result->claim['payable_minor']);
        self::assertSame(13440, $result->assessmentEvidence['eligible']['analytical_minor']);
        self::assertSame(0, $this->calculate(ordinary: 13500)->claim['payable_minor']);
    }

    public function testEmbeddedPremiumIsExcludedExactlyButIncludedOvertimeIsNotSubtractedTwice(): void
    {
        $allocations = [
            ['kind' => 'included_overtime', 'raw_numerator_minor' => 5000, 'raw_denominator' => 1],
            ['kind' => 'included_agreement_premium', 'exclude_from_minimum_wage' => true, 'raw_numerator_minor' => 301, 'raw_denominator' => 3],
        ];
        $result = $this->calculate(ordinary: 13500, included: $allocations);
        self::assertTrue($result->complete);
        self::assertSame(100, $result->claim['payable_minor']);
        self::assertSame(40199, $result->assessmentEvidence['eligible']['raw_numerator_minor']);
        self::assertSame(3, $result->assessmentEvidence['eligible']['raw_denominator']);
    }

    public function testPartsUseTheirOwnStandardWeekAndRoundOnlyTheCombinedClaim(): void
    {
        $parts = [
            [...$this->part(), 'date_to' => '2026-07-15', 'regular_worked_duration_units' => 1],
            [...$this->part(), 'term_id' => 2, 'date_from' => '2026-07-16', 'stated_weekly_minutes' => 2250, 'regular_worked_duration_units' => 1],
        ];
        $result = $this->calculate(parts: $parts);
        self::assertTrue($result->complete);
        self::assertSame(100, $result->claim['payable_minor']);
        self::assertSame(2, $result->claim['duration_units']);
        self::assertSame(0, $result->claim['worked_duration_units']);
        self::assertSame([13440, 14340], array_column($result->assessmentEvidence['parts'], 'minimum_hourly_minor'));
    }

    public function testUnknownIsBlockedAndExplicitZeroIsACompleteZero(): void
    {
        self::assertFalse($this->calculate(parts: [])->complete);
        $unknown = $this->calculate(parts: [[...$this->part(), 'regular_worked_duration_units' => null]]);
        self::assertFalse($unknown->complete);
        self::assertNull($unknown->claim['payable_minor']);
        self::assertFalse($this->calculate(other: [['amount_minor' => 100, 'minimum_wage_treatment' => null]])->complete);
        $zero = $this->calculate(parts: [[...$this->part(), 'regular_worked_duration_units' => 0]]);
        self::assertTrue($zero->complete);
        self::assertSame(0, $zero->claim['payable_minor']);
    }

    public function testMonthlyRequiresAdvanceProofAndRealFundAndIsNeverUsedForAgreements(): void
    {
        $part = [...$this->part(), 'regular_worked_duration_units' => 240000, 'minimum_monthly_minor' => 2240000,
            'full_month_fund_duration_units' => 240000, 'contractual_weekly_duration_units' => 60000];
        $proof = ['reference' => 'Syntetické předchozí určení', 'decided_on' => '2026-06-30', 'valid_from' => '2026-07-01', 'valid_to' => null];
        self::assertFalse($this->calculate(parts: [$part], assessment: 'monthly')->complete);
        $result = $this->calculate(parts: [$part], assessment: 'monthly', proof: $proof);
        self::assertTrue($result->complete);
        self::assertSame(1120000, $result->claim['payable_minor']);
        self::assertFalse($this->calculate(parts: [$part], assessment: 'monthly', proof: [...$proof, 'decided_on' => '2026-07-02'])->complete);
        self::assertFalse($this->calculate(parts: [$part], assessment: 'monthly', proof: $proof, type: 'dpp')->complete);
        unset($part['full_month_fund_duration_units']);
        self::assertFalse($this->calculate(parts: [$part], assessment: 'monthly', proof: $proof)->complete);
        self::assertTrue($this->calculate(type: 'dpc')->complete);
    }

    public function testOverlappingPartsAndNegativeNetEarningsNeverProduceAPayableClaim(): void
    {
        self::assertFalse($this->calculate(parts: [$this->part(), $this->part()])->complete);
        self::assertFalse($this->calculate(other: [['amount_minor' => -1, 'minimum_wage_treatment' => 'included']])->complete);
        self::assertFalse($this->calculate(included: [['exclude_from_minimum_wage' => true, 'raw_numerator_minor' => 1, 'raw_denominator' => 1]])->complete);
    }

    public function testNegativeExcludedCorrectionIsTraceOnlyAndDoesNotBlockMinimum(): void
    {
        $base = $this->calculate(ordinary: 12000);
        $result = $this->calculate(ordinary: 12000, other: [['amount_minor' => -500, 'minimum_wage_treatment' => 'excluded', 'component_code' => 'NAHRADA_MZDY']]);
        self::assertTrue($result->complete, json_encode($result->blockers));
        self::assertSame($base->claim['payable_minor'], $result->claim['payable_minor']);
        self::assertSame(-500, $result->assessmentEvidence['excluded'][0]['raw_numerator_minor']);
        self::assertSame(-500, $result->assessmentEvidence['excluded'][0]['analytical_minor']);
    }

    public function testMissingMonthlyFundHasItsOwnBlocker(): void
    {
        $proof = ['reference' => 'Syntetické předchozí určení', 'decided_on' => '2026-06-30', 'valid_from' => '2026-07-01', 'valid_to' => null];
        $result = $this->calculate(parts: [[...$this->part(), 'regular_worked_duration_units' => 240000, 'contractual_weekly_duration_units' => 60000, 'minimum_monthly_minor' => 2240000]], assessment: 'monthly', proof: $proof);
        self::assertFalse($result->complete);
        self::assertSame('hourly_minimum_monthly_fund_missing', $result->blockers[0]['code'], json_encode($result->blockers));
    }

    public function testEarningForAnotherPeriodIsExcludedWithItsReason(): void
    {
        $base = $this->calculate(ordinary: 12000);
        $result = $this->calculate(ordinary: 12000, other: [['amount_minor' => 50000, 'minimum_wage_treatment' => 'excluded',
            'exclusion_reason' => 'source_period_other', 'component_code' => 'ODMENA', 'source_period_start' => '2026-06-01']]);
        self::assertTrue($result->complete, json_encode($result->blockers));
        self::assertSame($base->claim['payable_minor'], $result->claim['payable_minor']);
        self::assertSame('source_period_other', $result->assessmentEvidence['excluded'][0]['reason']);
    }

    public function testExplicitHourlyZeroNeedsNeitherFloorNorStandardButPositiveAndUnknownTimeDo(): void
    {
        $part = [...$this->part(), 'regular_worked_duration_units' => 0, 'minimum_base40_minor' => null, 'stated_weekly_minutes' => null];
        $zero = $this->calculate(parts: [$part]);
        self::assertTrue($zero->complete, json_encode($zero->blockers));
        self::assertSame(0, $zero->claim['payable_minor']);
        self::assertNull($zero->assessmentEvidence['parts'][0]['minimum_hourly_minor']);
        self::assertFalse($this->calculate(parts: [[...$part, 'regular_worked_duration_units' => 1]])->complete);
        self::assertFalse($this->calculate(parts: [[...$part, 'regular_worked_duration_units' => null]])->complete);
        self::assertFalse($this->calculate(parts: [$part], assessment: 'monthly')->complete);
    }

    private function part(): array
    {
        return ['term_id' => 1, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31',
            'regular_worked_duration_units' => 3000, 'stated_weekly_minutes' => 2400, 'minimum_base40_minor' => 13440];
    }

    private function calculate(?array $parts = null, int $ordinary = 0, array $other = [], array $included = [],
        PayrollWageRounding $rounding = PayrollWageRounding::Ceil, string $assessment = 'hourly', ?array $proof = null, string $type = 'employment'): \MyInvoice\Service\Payroll\Hourly\PayrollHourlyMinimumWageResult
    {
        return (new PayrollHourlyMinimumWageCalculator())->calculate(new PayrollHourlyMinimumWageContext(
            1, '2026-07-01', $type, $assessment, $parts ?? [$this->part()], $ordinary, $other, $included, $rounding, $proof,
        ));
    }
}
