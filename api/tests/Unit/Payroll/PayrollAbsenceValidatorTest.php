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

    /**
     * Převzatá nepřítomnost z roku před prvním rulesetem je historická evidence: zapíše
     * se bez sazby náhrady (náhradu nese převzatá mzda), ručně zadaná dál selže.
     */
    public function testTakenOverAbsenceBeforeRulesetsIsHistoricalEvidenceWithoutRate(): void
    {
        $dpn = $this->validator()->absence([
            'employment_id' => 1,
            'absence_type' => 'dpn',
            'date_from' => '2023-12-20',
            'date_to' => '2024-01-10',
        ], takeover: true);
        $vacation = $this->validator()->absence([
            'employment_id' => 1,
            'absence_type' => 'vacation',
            'date_from' => '2024-03-04',
            'date_to' => '2024-03-08',
        ], takeover: true);

        self::assertSame('dpn', $dpn['compensation_policy']);
        self::assertNull($dpn['compensation_rate_basis_points']);
        self::assertSame('2023-12-20', $dpn['date_from']);
        self::assertSame('average_100', $vacation['compensation_policy']);
        self::assertTrue($this->validator()->predatesRulesets('2025-12-31'));
        self::assertFalse($this->validator()->predatesRulesets('2026-01-01'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('není účinný mzdový ruleset domény compensation_averages');
        $this->validator()->absence([
            'employment_id' => 1,
            'absence_type' => 'dpn',
            'date_from' => '2023-12-20',
            'date_to' => '2024-01-10',
        ]);
    }

    /** Převzatá nepřítomnost přes začátek rulesetů nebo v roce po nich zůstává fail-closed. */
    public function testTakenOverAbsenceReachingCoveredOrLaterYearStillFailsClosed(): void
    {
        foreach ([['2025-12-20', '2026-01-10'], ['2027-02-01', '2027-02-05']] as [$from, $to]) {
            try {
                $this->validator()->absence([
                    'employment_id' => 1,
                    'absence_type' => 'dpn',
                    'date_from' => $from,
                    'date_to' => $to,
                ], takeover: true);
                self::fail("Převzatá nepřítomnost {$from} - {$to} neměla projít bez rulesetu.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('není účinný mzdový ruleset', $e->getMessage());
            }
        }
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

    /** § 40 odst. 1 písm. b) zákona č. 187/2006 Sb.: jen u ošetřování člena rodiny. */
    public function testLoneCarerIsKeptOnlyOnCare(): void
    {
        $care = [
            'employment_id' => 1,
            'absence_type' => 'ocr',
            'date_from' => '2026-07-06',
            'date_to' => '2026-07-20',
        ];
        self::assertTrue($this->validator()->absence($care + ['lone_carer' => true])['lone_carer']);
        self::assertFalse($this->validator()->absence($care)['lone_carer']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jen u ošetřování člena rodiny');
        $this->validator()->absence(['absence_type' => 'unpaid_leave', 'lone_carer' => true] + $care);
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

    /** Druh překážky určuje sazbu: strana zaměstnance 100 %, prostoj 80 %. */
    public function testObstacleRateComesFromTheKindTable(): void
    {
        $doctor = $this->validator()->absence($this->obstacle('employee_obstacle', 'medical_examination'));
        $downtime = $this->validator()->absence($this->obstacle('employer_obstacle', 'downtime'));
        $other = $this->validator()->absence($this->obstacle('employer_obstacle', 'other_employer_obstacle'));

        self::assertSame(['medical_examination', 10_000, 'average_100'], [
            $doctor['obstacle_kind'], $doctor['compensation_rate_basis_points'], $doctor['compensation_policy'],
        ]);
        self::assertSame(['downtime', 8_000, 'average_custom'], [
            $downtime['obstacle_kind'], $downtime['compensation_rate_basis_points'], $downtime['compensation_policy'],
        ]);
        self::assertSame(10_000, $other['compensation_rate_basis_points']);
    }

    public function testPaidObstacleWithoutKindIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Vyberte druh překážky');
        $this->validator()->absence($this->obstacle('employer_obstacle', null));
    }

    public function testObstacleKindMustMatchTheSide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('straně zaměstnavatele, ne zaměstnance');
        $this->validator()->absence($this->obstacle('employee_obstacle', 'downtime'));
    }

    /** § 207 písm. a) ZP: nejméně 80 %, zvýšení jen s důvodem, nad průměr nikdy. */
    public function testObstacleRateOverrideNeedsReasonAndStaysWithinStatutoryBounds(): void
    {
        $raised = $this->validator()->absence($this->obstacle('employer_obstacle', 'downtime', [
            'compensation_rate_basis_points' => 9_000,
            'compensation_rate_reason' => 'Vnitřní předpis č. 3/2026',
        ]));
        self::assertSame(9_000, $raised['compensation_rate_basis_points']);
        self::assertSame('Vnitřní předpis č. 3/2026', $raised['compensation_rate_reason']);

        foreach ([
            [['compensation_rate_basis_points' => 9_000], 'uveďte důvod'],
            [['compensation_rate_basis_points' => 7_000, 'compensation_rate_reason' => 'x'], '80 až 100 %'],
            [['compensation_rate_basis_points' => 10_001, 'compensation_rate_reason' => 'x'], '80 až 100 %'],
        ] as [$override, $message]) {
            try {
                $this->validator()->absence($this->obstacle('employer_obstacle', 'downtime', $override));
                self::fail('Sazba mimo pravidla prošla: ' . json_encode($override));
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    /** Strana zaměstnance má náhradu ze zákona 100 %, sazbu nejde snížit. */
    public function testEmployeeObstacleRateIsFixed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ze zákona 100 %');
        $this->validator()->absence($this->obstacle('employee_obstacle', 'own_wedding', [
            'compensation_rate_basis_points' => 8_000,
            'compensation_rate_reason' => 'Pokus',
        ]));
    }

    /** § 209 odst. 2 ZP: bez dohody nebo vnitřního předpisu částečná nezaměstnanost neexistuje. */
    public function testPartialUnemploymentRequiresTheInternalRegulation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('§ 209 odst. 2 ZP');
        $this->validator()->absence($this->obstacle('employer_obstacle', 'partial_unemployment'));
    }

    /**
     * Částečná práce s příspěvkem (§ 120a a násl. zákona o zaměstnanosti) je
     * překážka na straně zaměstnavatele s náhradou nejméně 80 %; méně neprojde.
     */
    public function testPartialWorkIsAnEmployerObstacleWithAtLeastEightyPercent(): void
    {
        $data = $this->validator()->absence($this->obstacle('employer_obstacle', 'partial_work'));
        self::assertSame('partial_work', $data['obstacle_kind']);
        self::assertSame(8_000, $data['compensation_rate_basis_points']);

        $this->expectException(\InvalidArgumentException::class);
        $this->validator()->absence($this->obstacle('employer_obstacle', 'partial_work', [
            'compensation_rate_basis_points' => 6_000,
            'compensation_rate_reason' => 'Pokus',
        ]));
    }

    public function testObstacleFieldsAreRefusedOnOtherTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jen u placené překážky');
        $this->validator()->absence($this->obstacle('vacation', 'medical_examination'));
    }

    /** Převzatá nepřítomnost druh nenese a zapisuje se jako dřív. */
    public function testTakeoverObstacleWithoutKindKeepsTheLegacyPolicy(): void
    {
        $data = $this->validator()->absence($this->obstacle('employer_obstacle', null), takeover: true);

        self::assertNull($data['obstacle_kind']);
        self::assertSame('statutory_manual_review', $data['compensation_policy']);
        self::assertSame(10_000, $data['compensation_rate_basis_points']);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function obstacle(string $type, ?string $kind, array $overrides = []): array
    {
        return [
            'employment_id' => 1,
            'absence_type' => $type,
            'date_from' => '2026-07-15',
            'date_to' => '2026-07-16',
            'obstacle_kind' => $kind,
            'average_snapshot_id' => 7,
            ...$overrides,
        ];
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
