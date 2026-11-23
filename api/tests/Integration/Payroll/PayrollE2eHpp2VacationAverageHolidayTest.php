<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Absence\AverageEarningDerivationService as Derivation;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\TestCase;

/**
 * Odpracovaná doba pro průměrný výdělek v měsíci se svátkem.
 *
 * „Za odpracovanou dobu se považuje doba, za kterou zaměstnanci přísluší mzda
 * nebo plat" (§ 353 odst. 2 ZP). Měsíční mzda se za svátek v obvyklý pracovní
 * den nekrátí (§ 115 odst. 3 ZP), takže mzda za tuto dobu přísluší a hodiny
 * svátku patří do jmenovatele průměru. U hodinové mzdy za svátek přísluší
 * náhrada mzdy, která do hrubé mzdy pro průměr nevstupuje, a hodiny svátku
 * se proto nepřičítají.
 *
 * Pracovní souhrn: dvě směny po 7,5 h (15 h odpracováno) a svátek 8 h.
 */
final class PayrollE2eHpp2VacationAverageHolidayTest extends TestCase
{
    public function testHolidayCoveredByMonthlyWageCountsAsWorkedTime(): void
    {
        $month = Derivation::monthFromRow(self::row([self::input('MZDA_MESICNI', 'base_wage', 4_400_000)], 8_000), '2026-01-01');

        self::assertSame([], $month['blockers']);
        self::assertSame(900 + 480, $month['worked_minutes']);
        self::assertSame((900 + 480) * 50, $month['worked_duration_units']);
        self::assertSame(480 * 50, $month['wage_covered_holiday_duration_units']);
        // Počet odpracovaných dnů (§ 355 odst. 1 ZP) svátek nemění.
        self::assertSame(2, $month['worked_days']);
    }

    public function testHourlyWageWithHolidayCompensationKeepsWorkedHoursOnly(): void
    {
        $month = Derivation::monthFromRow(self::row([
            self::input('MZDA_HODINOVA_ZAKLAD', 'hourly_wage', 300_000),
            self::input('NAHRADA_MZDY_SVATEK', 'compensation', 160_000, 'excluded'),
        ], 8_000), '2026-01-01');

        self::assertSame([], $month['blockers']);
        self::assertSame(900, $month['worked_minutes']);
        self::assertArrayNotHasKey('wage_covered_holiday_duration_units', $month);
    }

    public function testMonthlyWageNextToHolidayCompensationIsNotExtended(): void
    {
        // Smíšený měsíc (měsíční i hodinová část): svátek platila náhrada.
        $month = Derivation::monthFromRow(self::row([
            self::input('MZDA_MESICNI', 'base_wage', 2_200_000),
            self::input('NAHRADA_MZDY_SVATEK', 'compensation', 160_000, 'excluded'),
        ], 8_000), '2026-01-01');

        self::assertSame(900, $month['worked_minutes']);
    }

    public function testMonthWithoutMonthlyWageOrHolidayIsUnchanged(): void
    {
        $bonusOnly = Derivation::monthFromRow(self::row([self::input('ODMENA', 'bonus', 500_000)], 8_000), '2026-01-01');
        self::assertSame(900, $bonusOnly['worked_minutes']);

        $noHoliday = Derivation::monthFromRow(self::row([self::input('MZDA_MESICNI', 'base_wage', 4_400_000)], null), '2026-01-01');
        self::assertSame(900, $noHoliday['worked_minutes']);

        $excluded = Derivation::monthFromRow(self::row([self::input('MZDA_MESICNI', 'base_wage', 4_400_000, 'excluded')], 8_000), '2026-01-01');
        self::assertSame(900, $excluded['worked_minutes']);
    }

    public function testQuarterAverageDividesByHoursIncludingTheHoliday(): void
    {
        $months = [];
        foreach (['2026-01-01' => 8_000, '2026-02-01' => null, '2026-03-01' => null] as $period => $holiday) {
            $months[] = ['period_start' => $period]
                + Derivation::monthFromRow(self::row([self::input('MZDA_MESICNI', 'base_wage', 4_400_000)], $holiday, $period), $period);
        }
        $combined = Derivation::combine($months, 1, false);

        self::assertTrue($combined['ready'], CanonicalJson::encode($combined['blockers']));
        self::assertSame(3 * 900 + 480, $combined['worked_minutes']);
        self::assertSame(480 * 50, $combined['months'][0]['wage_covered_holiday_duration_units']);
        self::assertArrayNotHasKey('wage_covered_holiday_duration_units', $combined['months'][1]);
    }

    /**
     * @param list<array<string,mixed>> $inputs
     * @return array<string,mixed>
     */
    private static function row(array $inputs, ?int $holidayMillihours, string $periodStart = '2026-01-01'): array
    {
        $month = substr($periodStart, 0, 7);
        $source = [
            'schema_version' => 'jmhz-work-month.v2',
            'period_start' => $periodStart,
            'time_entries' => [
                self::entry("{$month}-05 06:00:00", "{$month}-05 14:00:00"),
                self::entry("{$month}-06 06:00:00", "{$month}-06 14:00:00"),
            ],
        ];
        $sourceJson = CanonicalJson::encode($source);

        return [
            'revision_id' => 7,
            'run_id' => 3,
            'revision_no' => 1,
            'revision_status' => 'approved',
            'run_status' => 'approved',
            'input_json' => (string) json_encode([
                'employment' => ['id' => 11],
                'inputs' => $inputs,
                'time_month' => [
                    'id' => 5,
                    'status' => 'approved',
                    'revision_no' => 1,
                    'row_version' => 2,
                    'jmhz_work_summary' => [
                        'id' => 42,
                        'derivation_version' => 'jmhz-work-month.v2',
                        'source_snapshot_json' => $sourceJson,
                        'source_snapshot_sha256' => hash('sha256', $sourceJson),
                        'summary_sha256' => str_repeat('b', 64),
                        'values' => [
                            'evidence_days' => 31,
                            'worked_millihours' => 15_000,
                            'holiday_millihours' => $holidayMillihours,
                        ],
                    ],
                ],
            ]),
            'result_json' => (string) json_encode([
                'employment_id' => 11,
                'totals' => ['average_earning_base_minor' => 4_400_000],
            ]),
            'result_hash' => str_repeat('a', 64),
            'result_status' => 'calculated',
        ];
    }

    /** @return array<string,mixed> */
    private static function entry(string $startsAtUtc, string $endsAtUtc): array
    {
        return [
            'id' => abs(crc32($startsAtUtc)),
            'category' => 'regular',
            'starts_at_utc' => $startsAtUtc,
            'ends_at_utc' => $endsAtUtc,
            'timezone_name' => 'Europe/Prague',
            'break_minutes' => 30,
        ];
    }

    /** @return array<string,mixed> */
    private static function input(string $code, string $kind, int $amountMinor, string $averageTreatment = 'included'): array
    {
        return [
            'amount_minor' => $amountMinor,
            'component' => [
                'code' => $code,
                'component_kind' => $kind,
                'average_earning_treatment' => $averageTreatment,
            ],
        ];
    }
}
