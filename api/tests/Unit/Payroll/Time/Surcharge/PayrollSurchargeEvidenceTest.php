<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time\Surcharge;

use DateTimeImmutable;
use DateTimeZone;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeCompensationMode;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeEvidence;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeEvidenceResult;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeException;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargePolicy;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeRuleset;
use PHPUnit\Framework\TestCase;

/**
 * Skutkový stav příplatků z evidence docházky.
 *
 * Data se zadávají v MÍSTNÍM čase a helper je převádí do UTC, protože přesně tak
 * je ukládá `payroll_time_entries` — a chyba v tomhle převodu je přesně to, co
 * u noční směny přes půlnoc posune den a rozbije odečet náhradního volna.
 *
 * 2026-07-04 je sobota, 2026-07-05 neděle a zároveň státní svátek
 * (Den slovanských věrozvěstů Cyrila a Metoděje).
 */
final class PayrollSurchargeEvidenceTest extends TestCase
{
    public function testNightShiftCrossingMidnightBelongsToTheDayItStarted(): void
    {
        $result = $this->collect([
            $this->entry('regular', '2026-06-15 22:00', '2026-06-16 06:00'),
            $this->entry('night', '2026-06-15 22:00', '2026-06-16 06:00'),
        ]);

        $segments = $result->segmentsFor(PayrollSurchargeKind::Night);
        self::assertCount(1, $segments);
        self::assertSame('2026-06-15', $segments[0]->localDate);
        self::assertSame(480, $segments[0]->minutes);
    }

    public function testBreakMinutesAreDeductedFromTheSurchargeBase(): void
    {
        $result = $this->collect([
            $this->entry('regular', '2026-06-15 22:00', '2026-06-16 06:00', 30),
            $this->entry('night', '2026-06-15 22:00', '2026-06-16 06:00', 30),
        ]);

        self::assertSame(450, $result->segmentsFor(PayrollSurchargeKind::Night)[0]->minutes);
    }

    /**
     * Zápis noční práce, který zasahuje do denní doby, se ODMÍTNE. Zkrátit ho
     * mlčky na průnik s noční dobou by znamenalo domýšlet si, kterou část
     * intervalu zaměstnanec odpracoval — a evidence tvrdí něco jiného.
     */
    public function testNightEntryReachingOutsideTheNightWindowFailsClosed(): void
    {
        $this->expectException(PayrollSurchargeException::class);
        $this->expectExceptionMessageMatches('/mimo noční dobu/');
        $this->collect([
            $this->entry('regular', '2026-06-15 21:00', '2026-06-15 23:00'),
            $this->entry('night', '2026-06-15 21:00', '2026-06-15 23:00'),
        ]);
    }

    public function testNightEntryEndingExactlyAtSixIsAccepted(): void
    {
        $result = $this->collect([
            $this->entry('regular', '2026-06-16 00:00', '2026-06-16 06:00'),
            $this->entry('night', '2026-06-16 00:00', '2026-06-16 06:00'),
        ]);

        self::assertSame(360, $result->segmentsFor(PayrollSurchargeKind::Night)[0]->minutes);
    }

    public function testWeekendEntryReachingIntoFridayFailsClosed(): void
    {
        $this->expectException(PayrollSurchargeException::class);
        $this->expectExceptionMessageMatches('/sobotou ani/');
        $this->collect([
            $this->entry('regular', '2026-07-03 22:00', '2026-07-04 06:00'),
            $this->entry('weekend', '2026-07-03 22:00', '2026-07-04 06:00'),
        ], period: '2026-07-01');
    }

    public function testHolidayEntryOnAnOrdinaryDayFailsClosed(): void
    {
        $this->expectException(PayrollSurchargeException::class);
        $this->expectExceptionMessageMatches('/svátkem není/');
        $this->collect([
            $this->entry('regular', '2026-07-07 08:00', '2026-07-07 16:00'),
            $this->entry('holiday', '2026-07-07 08:00', '2026-07-07 16:00'),
        ], period: '2026-07-01', holidayMode: PayrollSurchargeCompensationMode::Surcharge);
    }

    /** Svátek na neděli nese oba nároky současně — § 115 i § 118. */
    public function testHolidayOnASundayCarriesBothTheHolidayAndTheWeekendClaim(): void
    {
        $result = $this->collect([
            $this->entry('regular', '2026-07-05 08:00', '2026-07-05 16:00'),
            $this->entry('holiday', '2026-07-05 08:00', '2026-07-05 16:00'),
            $this->entry('weekend', '2026-07-05 08:00', '2026-07-05 16:00'),
        ], period: '2026-07-01', holidayMode: PayrollSurchargeCompensationMode::Surcharge);

        self::assertCount(1, $result->segmentsFor(PayrollSurchargeKind::Holiday));
        self::assertCount(1, $result->segmentsFor(PayrollSurchargeKind::Weekend));
        self::assertSame(480, $result->segmentsFor(PayrollSurchargeKind::Holiday)[0]->minutes);
    }

    public function testOvertimeAtNightOnASaturdayCarriesThreeClaims(): void
    {
        $result = $this->collect([
            $this->entry('overtime', '2026-07-04 22:00', '2026-07-05 00:00'),
            $this->entry('night', '2026-07-04 22:00', '2026-07-05 00:00'),
            $this->entry('weekend', '2026-07-04 22:00', '2026-07-05 00:00'),
        ], period: '2026-07-01');

        self::assertCount(3, $result->segments);
        foreach ($result->segments as $segment) {
            self::assertSame(120, $segment->minutes);
            self::assertSame('2026-07-04', $segment->localDate);
        }
    }

    /**
     * Kategorie jsou příznaky nad odpracovanou dobou. Víc nočních minut než
     * odpracovaných je vada evidence, ne bohatší podklad.
     */
    public function testFlaggedMinutesExceedingWorkedTimeFailClosed(): void
    {
        $this->expectException(PayrollSurchargeException::class);
        $this->expectExceptionMessageMatches('/příznaky nad odpracovanou/');
        $this->collect([
            $this->entry('regular', '2026-06-16 00:00', '2026-06-16 04:00'),
            $this->entry('night', '2026-06-16 00:00', '2026-06-16 06:00'),
        ]);
    }

    public function testSupersededEntriesAreIgnored(): void
    {
        $result = $this->collect([
            $this->entry('regular', '2026-06-16 00:00', '2026-06-16 06:00'),
            $this->entry('night', '2026-06-16 00:00', '2026-06-16 06:00', 0, null, 'superseded'),
        ]);

        self::assertSame([], $result->segmentsFor(PayrollSurchargeKind::Night));
    }

    // ── § 117 ────────────────────────────────────────────────────────────────

    public function testDifficultEnvironmentWithoutAFactorCountFailsClosed(): void
    {
        $this->expectException(PayrollSurchargeException::class);
        $this->expectExceptionMessageMatches('/ztěžujících vlivů/');
        $this->collect([
            $this->entry('regular', '2026-06-15 08:00', '2026-06-15 16:00'),
            $this->entry('difficult_environment', '2026-06-15 08:00', '2026-06-15 16:00'),
        ]);
    }

    public function testEntryFactorCountOverridesTheEmploymentDefault(): void
    {
        $result = $this->collect([
            $this->entry('regular', '2026-06-15 08:00', '2026-06-15 16:00'),
            $this->entry('difficult_environment', '2026-06-15 08:00', '2026-06-15 16:00', 0, 3),
        ], factors: 1);

        $segments = $result->segmentsFor(PayrollSurchargeKind::DifficultEnvironment);
        self::assertCount(1, $segments);
        self::assertSame(3, $segments[0]->factors);
    }

    /**
     * Dva zápisy téhož dne s různým počtem vlivů zůstávají oddělené. Sloučit je
     * do jednoho průměru by u § 117 vyrobilo jiné číslo, než jaké zákon přiznává.
     */
    public function testDifferentFactorCountsOnOneDayStaySeparateSegments(): void
    {
        $result = $this->collect([
            $this->entry('regular', '2026-06-15 06:00', '2026-06-15 14:00'),
            $this->entry('difficult_environment', '2026-06-15 06:00', '2026-06-15 10:00', 0, 2),
            $this->entry('difficult_environment', '2026-06-15 10:00', '2026-06-15 14:00', 0, 4),
        ]);

        $segments = $result->segmentsFor(PayrollSurchargeKind::DifficultEnvironment);
        self::assertCount(2, $segments);
        self::assertSame([2, 4], [$segments[0]->factors, $segments[1]->factors]);
        self::assertSame(240 * 2 + 240 * 4, array_sum(array_map(
            static fn ($segment): int => $segment->weightedMinutes(),
            $segments,
        )));
    }

    // ── § 114 náhradní volno ────────────────────────────────────────────────

    public function testGrantedCompensatoryTimeOffRemovesTheOvertimeSurcharge(): void
    {
        $result = $this->collect(
            [
                $this->entry('regular', '2026-06-15 08:00', '2026-06-15 18:00'),
                $this->entry('overtime', '2026-06-15 16:00', '2026-06-15 18:00'),
            ],
            compensations: [[
                'overtime_date' => '2026-06-15',
                'minutes' => 120,
                'granted_on' => '2026-06-22',
            ]],
        );

        self::assertSame([], $result->segmentsFor(PayrollSurchargeKind::Overtime));
        self::assertSame(
            'compensatory_time_off_granted',
            $result->waivedFor(PayrollSurchargeKind::Overtime)[0]['reason'],
        );
    }

    public function testPartialCompensatoryTimeOffLeavesTheRestSurcharged(): void
    {
        $result = $this->collect(
            [
                $this->entry('regular', '2026-06-15 08:00', '2026-06-15 18:00'),
                $this->entry('overtime', '2026-06-15 16:00', '2026-06-15 18:00'),
            ],
            compensations: [[
                'overtime_date' => '2026-06-15',
                'minutes' => 45,
                'granted_on' => '2026-06-22',
            ]],
        );

        self::assertSame(75, $result->segmentsFor(PayrollSurchargeKind::Overtime)[0]->minutes);
    }

    public function testMoreCompensatedMinutesThanOvertimeFailsClosed(): void
    {
        $this->expectException(PayrollSurchargeException::class);
        $this->expectExceptionMessageMatches('/ale přesčasu je jen/');
        $this->collect(
            [
                $this->entry('regular', '2026-06-15 08:00', '2026-06-15 18:00'),
                $this->entry('overtime', '2026-06-15 16:00', '2026-06-15 18:00'),
            ],
            compensations: [[
                'overtime_date' => '2026-06-15',
                'minutes' => 240,
                'granted_on' => '2026-06-22',
            ]],
        );
    }

    /**
     * § 114 odst. 1 — bez dohody náleží PŘÍPLATEK. Chybějící zásada tedy
     * u přesčasu výpočtu nebrání; opačné chování by vyrobilo nedoplatek.
     */
    public function testOvertimeWithoutAnAgreementIsSurchargedByDefault(): void
    {
        $result = $this->collect([
            $this->entry('regular', '2026-06-15 08:00', '2026-06-15 18:00'),
            $this->entry('overtime', '2026-06-15 16:00', '2026-06-15 18:00'),
        ]);

        self::assertCount(1, $result->segmentsFor(PayrollSurchargeKind::Overtime));
        self::assertFalse($result->requiresManualReview());
    }

    /** § 114 odst. 3 — mzda sjednaná s přihlédnutím k přesčasu příplatek vylučuje. */
    public function testWageAgreedWithOvertimeInMindYieldsNoSurcharge(): void
    {
        $result = $this->collect(
            [
                $this->entry('regular', '2026-06-15 08:00', '2026-06-15 18:00'),
                $this->entry('overtime', '2026-06-15 16:00', '2026-06-15 18:00'),
            ],
            overtimeMode: PayrollSurchargeCompensationMode::IncludedInWage,
        );

        self::assertSame([], $result->segmentsFor(PayrollSurchargeKind::Overtime));
        self::assertSame(
            'wage_includes_overtime',
            $result->waivedFor(PayrollSurchargeKind::Overtime)[0]['reason'],
        );
    }

    /** § 114 odst. 2 — dokud lhůta běží, příplatek se nevyplácí, ale hlásí se. */
    public function testAgreedTimeOffWithinTheDeadlineDefersTheSurcharge(): void
    {
        $result = $this->collect(
            [
                $this->entry('regular', '2026-06-15 08:00', '2026-06-15 18:00'),
                $this->entry('overtime', '2026-06-15 16:00', '2026-06-15 18:00'),
            ],
            overtimeMode: PayrollSurchargeCompensationMode::CompensatoryTimeOff,
            assessedOn: '2026-06-30',
        );

        self::assertSame([], $result->segmentsFor(PayrollSurchargeKind::Overtime));
        self::assertTrue($result->requiresManualReview());
        self::assertSame(
            PayrollSurchargeEvidence::OVERTIME_TIME_OFF_PENDING,
            $result->findings[0]['reason'],
        );
    }

    /** § 114 odst. 2 — po marném uplynutí tří kalendářních měsíců nárok vzniká. */
    public function testAgreedTimeOffNotGrantedInTimeRevivesTheSurcharge(): void
    {
        $result = $this->collect(
            [
                $this->entry('regular', '2026-06-15 08:00', '2026-06-15 18:00'),
                $this->entry('overtime', '2026-06-15 16:00', '2026-06-15 18:00'),
            ],
            overtimeMode: PayrollSurchargeCompensationMode::CompensatoryTimeOff,
            assessedOn: '2026-10-01',
        );

        self::assertCount(1, $result->segmentsFor(PayrollSurchargeKind::Overtime));
        self::assertSame(
            PayrollSurchargeEvidence::OVERTIME_TIME_OFF_LAPSED,
            $result->findings[0]['reason'],
        );
    }

    /** Poslední den lhůty ještě nárok nezakládá — „do konce třetího měsíce". */
    public function testTheLastDayOfTheDeadlineStillDefersTheSurcharge(): void
    {
        $result = $this->collect(
            [
                $this->entry('regular', '2026-06-15 08:00', '2026-06-15 18:00'),
                $this->entry('overtime', '2026-06-15 16:00', '2026-06-15 18:00'),
            ],
            overtimeMode: PayrollSurchargeCompensationMode::CompensatoryTimeOff,
            assessedOn: '2026-09-30',
        );

        self::assertSame([], $result->segmentsFor(PayrollSurchargeKind::Overtime));
    }

    // ── § 115 svátek ────────────────────────────────────────────────────────

    /**
     * § 115 odst. 1 dává jako VÝCHOZÍ náhradní volno, ne příplatek. Bez sjednané
     * zásady tedy nejde ani vyplatit, ani mlčky nevyplatit.
     */
    public function testHolidayWorkWithoutAnAgreementFailsClosed(): void
    {
        $this->expectException(PayrollSurchargeException::class);
        $this->expectExceptionMessageMatches('/není\s+sjednáno/u');
        $this->collect([
            $this->entry('regular', '2026-07-05 08:00', '2026-07-05 16:00'),
            $this->entry('holiday', '2026-07-05 08:00', '2026-07-05 16:00'),
        ], period: '2026-07-01');
    }

    /**
     * Sjednané náhradní volno za svátek příplatek ruší, ale modul nemá kam
     * zapsat, že za KONKRÉTNÍ svátek bylo poskytnuto — proto nález, ne ticho.
     */
    public function testAgreedHolidayTimeOffWaivesTheSurchargeButRaisesAFinding(): void
    {
        $result = $this->collect([
            $this->entry('regular', '2026-07-05 08:00', '2026-07-05 16:00'),
            $this->entry('holiday', '2026-07-05 08:00', '2026-07-05 16:00'),
        ], period: '2026-07-01', holidayMode: PayrollSurchargeCompensationMode::CompensatoryTimeOff);

        self::assertSame([], $result->segmentsFor(PayrollSurchargeKind::Holiday));
        self::assertSame(
            PayrollSurchargeEvidence::HOLIDAY_TIME_OFF_EVIDENCE_MISSING,
            $result->findings[0]['reason'],
        );
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $entries
     * @param list<array<string,mixed>> $compensations
     */
    private function collect(
        array $entries,
        array $compensations = [],
        string $period = '2026-06-01',
        ?PayrollSurchargeCompensationMode $overtimeMode = null,
        ?PayrollSurchargeCompensationMode $holidayMode = null,
        ?int $factors = null,
        ?string $assessedOn = null,
        bool $policyExists = false,
    ): PayrollSurchargeEvidenceResult {
        $ruleset = PayrollSurchargeRuleset::forDate(
            CzechPayrollRulesets2026::provider(),
            $period,
        );
        $policy = ($overtimeMode === null && $holidayMode === null
            && $factors === null && !$policyExists)
            ? PayrollSurchargePolicy::statutoryDefault()
            : PayrollSurchargePolicy::agreed(
                $overtimeMode ?? PayrollSurchargeCompensationMode::Surcharge,
                $holidayMode ?? PayrollSurchargeCompensationMode::CompensatoryTimeOff,
                $factors,
                [],
                $ruleset,
            );

        return (new PayrollSurchargeEvidence(new CzechHolidayCalendar()))->collect(
            $entries,
            $compensations,
            $policy,
            $ruleset,
            $period,
            $assessedOn ?? (new DateTimeImmutable($period))->modify('last day of this month')
                ->format('Y-m-d'),
        );
    }

    /** @return array<string,mixed> */
    private function entry(
        string $category,
        string $localStart,
        string $localEnd,
        int $breakMinutes = 0,
        ?int $factors = null,
        string $status = 'approved',
    ): array {
        $zone = new DateTimeZone('Europe/Prague');
        $utc = new DateTimeZone('UTC');

        return [
            'category' => $category,
            'starts_at_utc' => (new DateTimeImmutable($localStart, $zone))
                ->setTimezone($utc)->format('Y-m-d H:i:s'),
            'ends_at_utc' => (new DateTimeImmutable($localEnd, $zone))
                ->setTimezone($utc)->format('Y-m-d H:i:s'),
            'timezone_name' => 'Europe/Prague',
            'break_minutes' => $breakMinutes,
            'difficulty_factor_count' => $factors,
            'status' => $status,
        ];
    }
}
