<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollAbsenceValidator;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Tests\Fixtures\Payroll\ShiftedYearPayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

final class PayrollAbsenceValidatorTest extends TestCase
{
    public function testSecondQuarterRequiresExactPreviousCalendarQuarter(): void
    {
        $data = $this->validator()->average([
            'employment_id' => 1,
            'applicable_year' => 2026,
            'applicable_quarter' => 2,
            'decisive_from' => '2026-01-01',
            'decisive_to' => '2026-03-31',
            'gross_earnings_minor' => 1,
            'longer_period_allocated_minor' => 0,
            'worked_minutes' => 1,
            'worked_days' => 21,
        ]);

        self::assertSame('2026-01-01', $data['decisive_from']);
        self::assertSame('2026-03-31', $data['decisive_to']);
    }

    public function testFirstQuarterUsesPreviousYearFourthQuarter(): void
    {
        $data = $this->validator()->average([
            'employment_id' => 1,
            'applicable_year' => 2026,
            'applicable_quarter' => 1,
            'decisive_from' => '2025-10-01',
            'decisive_to' => '2025-12-31',
            'gross_earnings_minor' => 1,
            'longer_period_allocated_minor' => 0,
            'worked_minutes' => 1,
            'worked_days' => 21,
        ]);

        self::assertSame(1, $data['applicable_quarter']);
    }

    public function testUnsupportedAbsenceYearFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('není účinný mzdový ruleset domény compensation_averages');
        $this->validator()->absence([
            'employment_id' => 1,
            'absence_type' => 'other',
            'date_from' => '2025-12-31',
            'date_to' => '2025-12-31',
        ]);
    }

    public function testDpnCompensationRateComesFromRulesetNotFromLiteral(): void
    {
        $data = $this->validator()->absence([
            'employment_id' => 1,
            'absence_type' => 'dpn',
            'date_from' => '2026-06-15',
            'date_to' => '2026-06-20',
            'average_snapshot_id' => 7,
        ]);

        self::assertSame('dpn', $data['compensation_policy']);
        self::assertSame(6_000, $data['compensation_rate_basis_points']);
    }

    public function testEntitlementBelowStatutoryMinimumWeeksIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('zákonné minimum 4 týdny');
        $this->validator()->entitlement([
            'employment_id' => 1,
            'leave_year' => 2026,
            'weekly_minutes' => 2_400,
            'entitlement_weeks' => 1,
            'continuous_calendar_days' => 365,
            'worked_equivalent_minutes' => 124_800,
            'rationale' => 'Pokus o podlimitní výměru.',
        ]);
    }

    public function testEntitlementYearWithoutRulesetFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('není účinný mzdový ruleset domény compensation_averages');
        $this->validator()->entitlement([
            'employment_id' => 1,
            'leave_year' => 2027,
            'weekly_minutes' => 2_400,
            'entitlement_weeks' => 4,
            'continuous_calendar_days' => 365,
            'worked_equivalent_minutes' => 124_800,
            'rationale' => 'Rok bez rulesetu.',
        ]);
    }

    public function testAverageYearWithoutRulesetFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('není účinný mzdový ruleset domény compensation_averages');
        $this->validator()->average([
            'employment_id' => 1,
            'applicable_year' => 2027,
            'applicable_quarter' => 2,
            'decisive_from' => '2027-01-01',
            'decisive_to' => '2027-03-31',
            'gross_earnings_minor' => 1,
            'longer_period_allocated_minor' => 0,
            'worked_minutes' => 1,
            'worked_days' => 21,
        ]);
    }

    public function testLeaveEntryPeriodMustMatchTheEntitlementYear(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('musí ležet v roce nároku');
        $this->validator()->assertLeaveEntryPeriod(2026, '2025-12-31');
    }

    public function testCalendarShiftUnlocksNextYearOnceItsRulesetExists(): void
    {
        $validator = new PayrollAbsenceValidator(
            ShiftedYearPayrollRulesetFixture::provider(2027),
        );

        $absence = $validator->absence([
            'employment_id' => 1,
            'absence_type' => 'dpn',
            'date_from' => '2027-06-15',
            'date_to' => '2027-06-20',
            'average_snapshot_id' => 7,
        ]);
        $average = $validator->average([
            'employment_id' => 1,
            'applicable_year' => 2027,
            'applicable_quarter' => 2,
            'decisive_from' => '2027-01-01',
            'decisive_to' => '2027-03-31',
            'gross_earnings_minor' => 1,
            'longer_period_allocated_minor' => 0,
            'worked_minutes' => 1,
            'worked_days' => 21,
        ]);
        $entitlement = $validator->entitlement([
            'employment_id' => 1,
            'leave_year' => 2027,
            'weekly_minutes' => 2_400,
            'entitlement_weeks' => 4,
            'continuous_calendar_days' => 365,
            'worked_equivalent_minutes' => 124_800,
            'rationale' => 'Rok s rulesetem.',
        ]);
        $validator->assertLeaveEntryPeriod(2027, '2027-03-01');

        self::assertSame(6_000, $absence['compensation_rate_basis_points']);
        self::assertSame(2027, $average['applicable_year']);
        self::assertSame(2027, $entitlement['leave_year']);
    }

    public function testMaternityRequiresTheExpectedChildbirthDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('očekávaný den porodu povinný');
        $this->validator()->absence($this->maternity(['expected_childbirth_date' => null]));
    }

    public function testMaternityKeepsBothDatesAndTheBirthIsOptional(): void
    {
        $withoutBirth = $this->validator()->absence($this->maternity());
        $withBirth = $this->validator()->absence($this->maternity(['childbirth_date' => '2026-06-18']));

        self::assertSame('2026-06-20', $withoutBirth['expected_childbirth_date']);
        self::assertNull($withoutBirth['childbirth_date']);
        self::assertSame('2026-06-18', $withBirth['childbirth_date']);
        self::assertSame('none', $withBirth['compensation_policy']);
    }

    /** § 32 odst. 1 písm. a) a § 34 odst. 1 písm. a) zákona č. 187/2006 Sb. */
    public function testMaternityCannotStartBeforeTheEighthWeekBeforeExpectedBirth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nejdříve 2026-04-25');
        $this->validator()->absence($this->maternity(['date_from' => '2026-04-24']));
    }

    public function testMaternityMayStartOnTheEighthWeekBeforeExpectedBirth(): void
    {
        $data = $this->validator()->absence($this->maternity(['date_from' => '2026-04-25']));

        self::assertSame('2026-04-25', $data['date_from']);
    }

    /** Porod před osmým týdnem: nástup dnem porodu (§ 34 odst. 1 písm. b)). */
    public function testMaternityStartingOnAPrematureBirthIsAllowed(): void
    {
        $data = $this->validator()->absence($this->maternity([
            'date_from' => '2026-03-02',
            'childbirth_date' => '2026-03-02',
        ]));

        self::assertSame('2026-03-02', $data['childbirth_date']);
    }

    /** Převzetí dítěte do péče po porodu (§ 34 odst. 1 písm. c)). */
    public function testChildbirthMayPrecedeTheAbsence(): void
    {
        $data = $this->validator()->absence($this->maternity([
            'date_from' => '2026-07-01',
            'childbirth_date' => '2026-06-18',
        ]));

        self::assertSame('2026-06-18', $data['childbirth_date']);
    }

    public function testChildbirthDatesAreRefusedOutsideMaternity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jen u peněžité pomoci v mateřství');
        $this->validator()->absence([
            'employment_id' => 1,
            'absence_type' => 'parental',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'expected_childbirth_date' => '2026-06-20',
        ]);
    }

    public function testRecordedChildbirthMustBeAValidDateOnMaternity(): void
    {
        $absence = ['absence_type' => 'ppm', 'expected_childbirth_date' => '2026-06-20'];

        self::assertSame('2026-06-18', $this->validator()->childbirthDate($absence, '2026-06-18'));
        try {
            $this->validator()->childbirthDate(['absence_type' => 'dpn'] + $absence, '2026-06-18');
            self::fail('Den porodu nepatří k nemoci.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('jen u peněžité pomoci', $exception->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Den porodu musí být platné datum');
        $this->validator()->childbirthDate($absence, '2026-02-30');
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function maternity(array $overrides = []): array
    {
        return [
            'employment_id' => 1,
            'absence_type' => 'ppm',
            'date_from' => '2026-05-01',
            'date_to' => '2026-11-30',
            'expected_childbirth_date' => '2026-06-20',
            'childbirth_date' => null,
            ...$overrides,
        ];
    }

    private function validator(): PayrollAbsenceValidator
    {
        return new PayrollAbsenceValidator(CzechPayrollRulesets2026::provider());
    }
}
