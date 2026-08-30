<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Payroll;

use MyInvoice\Service\Accounting\Payroll\PayrollCalculator;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CHARAKTERIZAČNÍ test převodu mzdové rekapitulace na mzdový ruleset (nález A-1).
 *
 * Starší modul četl vlastní kopii mzdových konstant z `TaxConstants::TABLE`;
 * pro roky s rulesetem je dnes zrcadlí {@see TaxConstants::withDerived()}.
 * Byla to konsolidace zdroje, ne změna výpočtu — a přesně to tady stojí
 * napsané v číslech: očekávané hodnoty jsou spočítané PŘED převodem a jsou
 * to celé rozpady, ne jen kontrolní součty. Kdyby zrcadlení mapovalo jiný
 * parametr nebo spletlo jednotky (ruleset vede peníze v setinách), rozejde
 * se tenhle test, ne až mzda účetní.
 *
 * Scénáře pokrývají právě ta místa, kde na zrcadlených hodnotách záleží:
 *   - rozhodný příjem (4 499,99 vs. 4 500 Kč) — účast na sociálním pojištění
 *   - minimální vyměřovací základ ZP a doplatek do něj (0 / 10 000 / 22 400 Kč)
 *   - zaokrouhlení základu na stokoruny (24 850 → 24 900)
 *   - progresivní sazba § 38h odst. 2 nad měsíční hranicí (200 000 Kč)
 *   - strop § 15a (týž hrubý příjem s vyčerpaným ročním základem)
 *   - měsíční slevy z ročních částek (§ 38h odst. 4)
 */
final class PayrollCalculatorRulesetConstantsTest extends TestCase
{
    /**
     * @param array{0:bool,1:int}|null $credits
     * @param array<string,int|bool> $expected
     */
    #[DataProvider('scenarios')]
    public function testTheLegacyBreakdownIsUnchangedAfterMovingToTheRuleset(
        float $gross,
        ?array $credits,
        ?float $ytdSocialBase,
        array $expected,
    ): void {
        $c = TaxConstants::forYear(2026);
        $monthly = $credits === null
            ? null
            : PayrollCalculator::monthlyCredits($c, $credits[0], $credits[1]);

        self::assertSame($expected, PayrollCalculator::compute($gross, $c, $monthly, $ytdSocialBase));
    }

    /** @return iterable<string, array{float, array{0:bool,1:int}|null, ?float, array<string,int|bool>}> */
    public static function scenarios(): iterable
    {
        yield 'pod rozhodným příjmem — sociální se neodvádí' => [4499.99, null, null, [
            'gross' => 4500, 'minimum_wage' => 22400, 'assessment_base' => 22400,
            'social_base' => 0, 'social_max_base' => 2350416, 'social_base_capped' => false,
            'below_participation' => true,
            'employee_social' => 0, 'employee_health' => 203, 'health_min_topup' => 2416,
            'employee_deductions' => 2619, 'tax_base' => 4500,
            'tax_high_threshold' => 146901, 'tax_high_base' => 0, 'advance_tax' => 675,
            'credit_taxpayer' => 0, 'credit_children' => 0, 'credit_total' => 0,
            'advance_tax_withheld' => 675, 'net' => 1206,
            'employer_social' => 0, 'employer_health' => 405, 'employer_total' => 405,
            'health_total' => 3024, 'social_total' => 0, 'remittance_total' => 3699,
            'super_gross' => 4905,
        ]];

        yield 'přesně na rozhodném příjmu — účast VZNIKÁ' => [4500.0, null, null, [
            'gross' => 4500, 'minimum_wage' => 22400, 'assessment_base' => 22400,
            'social_base' => 4500, 'social_max_base' => 2350416, 'social_base_capped' => false,
            'below_participation' => false,
            'employee_social' => 320, 'employee_health' => 203, 'health_min_topup' => 2416,
            'employee_deductions' => 2939, 'tax_base' => 4500,
            'tax_high_threshold' => 146901, 'tax_high_base' => 0, 'advance_tax' => 675,
            'credit_taxpayer' => 0, 'credit_children' => 0, 'credit_total' => 0,
            'advance_tax_withheld' => 675, 'net' => 886,
            'employer_social' => 1116, 'employer_health' => 405, 'employer_total' => 1521,
            'health_total' => 3024, 'social_total' => 1436, 'remittance_total' => 5135,
            'super_gross' => 6021,
        ]];

        yield 'doplatek do minimálního vyměřovacího základu ZP' => [10000.0, null, null, [
            'gross' => 10000, 'minimum_wage' => 22400, 'assessment_base' => 22400,
            'social_base' => 10000, 'social_max_base' => 2350416, 'social_base_capped' => false,
            'below_participation' => false,
            'employee_social' => 710, 'employee_health' => 450, 'health_min_topup' => 1674,
            'employee_deductions' => 2834, 'tax_base' => 10000,
            'tax_high_threshold' => 146901, 'tax_high_base' => 0, 'advance_tax' => 1500,
            'credit_taxpayer' => 0, 'credit_children' => 0, 'credit_total' => 0,
            'advance_tax_withheld' => 1500, 'net' => 5666,
            'employer_social' => 2480, 'employer_health' => 900, 'employer_total' => 3380,
            'health_total' => 3024, 'social_total' => 3190, 'remittance_total' => 7714,
            'super_gross' => 13380,
        ]];

        yield 'přesně na minimální mzdě — bez doplatku' => [22400.0, null, null, [
            'gross' => 22400, 'minimum_wage' => 22400, 'assessment_base' => 22400,
            'social_base' => 22400, 'social_max_base' => 2350416, 'social_base_capped' => false,
            'below_participation' => false,
            'employee_social' => 1591, 'employee_health' => 1008, 'health_min_topup' => 0,
            'employee_deductions' => 2599, 'tax_base' => 22400,
            'tax_high_threshold' => 146901, 'tax_high_base' => 0, 'advance_tax' => 3360,
            'credit_taxpayer' => 0, 'credit_children' => 0, 'credit_total' => 0,
            'advance_tax_withheld' => 3360, 'net' => 16441,
            'employer_social' => 5556, 'employer_health' => 2016, 'employer_total' => 7572,
            'health_total' => 3024, 'social_total' => 7147, 'remittance_total' => 13531,
            'super_gross' => 29972,
        ]];

        yield 'základ se zaokrouhlí na stokoruny nahoru (§38h odst. 1)' => [24850.0, null, null, [
            'gross' => 24850, 'minimum_wage' => 22400, 'assessment_base' => 24850,
            'social_base' => 24850, 'social_max_base' => 2350416, 'social_base_capped' => false,
            'below_participation' => false,
            'employee_social' => 1765, 'employee_health' => 1119, 'health_min_topup' => 0,
            'employee_deductions' => 2884, 'tax_base' => 24900,
            'tax_high_threshold' => 146901, 'tax_high_base' => 0, 'advance_tax' => 3735,
            'credit_taxpayer' => 0, 'credit_children' => 0, 'credit_total' => 0,
            'advance_tax_withheld' => 3735, 'net' => 18231,
            'employer_social' => 6163, 'employer_health' => 2236, 'employer_total' => 8399,
            'health_total' => 3355, 'social_total' => 7928, 'remittance_total' => 15018,
            'super_gross' => 33249,
        ]];

        yield 'sleva na poplatníka snižuje sraženou zálohu' => [50000.0, [true, 0], null, [
            'gross' => 50000, 'minimum_wage' => 22400, 'assessment_base' => 50000,
            'social_base' => 50000, 'social_max_base' => 2350416, 'social_base_capped' => false,
            'below_participation' => false,
            'employee_social' => 3550, 'employee_health' => 2250, 'health_min_topup' => 0,
            'employee_deductions' => 5800, 'tax_base' => 50000,
            'tax_high_threshold' => 146901, 'tax_high_base' => 0, 'advance_tax' => 7500,
            'credit_taxpayer' => 2570, 'credit_children' => 0, 'credit_total' => 2570,
            'advance_tax_withheld' => 4930, 'net' => 39270,
            'employer_social' => 12400, 'employer_health' => 4500, 'employer_total' => 16900,
            'health_total' => 6750, 'social_total' => 15950, 'remittance_total' => 27630,
            'super_gross' => 66900,
        ]];

        yield 'sleva na poplatníka a tři děti přebijí celou zálohu' => [50000.0, [true, 3], null, [
            'gross' => 50000, 'minimum_wage' => 22400, 'assessment_base' => 50000,
            'social_base' => 50000, 'social_max_base' => 2350416, 'social_base_capped' => false,
            'below_participation' => false,
            'employee_social' => 3550, 'employee_health' => 2250, 'health_min_topup' => 0,
            'employee_deductions' => 5800, 'tax_base' => 50000,
            'tax_high_threshold' => 146901, 'tax_high_base' => 0, 'advance_tax' => 7500,
            'credit_taxpayer' => 2570, 'credit_children' => 5447, 'credit_total' => 8017,
            'advance_tax_withheld' => 0, 'net' => 44200,
            'employer_social' => 12400, 'employer_health' => 4500, 'employer_total' => 16900,
            'health_total' => 6750, 'social_total' => 15950, 'remittance_total' => 22700,
            'super_gross' => 66900,
        ]];

        yield 'progresivní sazba nad měsíční hranicí (§38h odst. 2)' => [200000.0, null, null, [
            'gross' => 200000, 'minimum_wage' => 22400, 'assessment_base' => 200000,
            'social_base' => 200000, 'social_max_base' => 2350416, 'social_base_capped' => false,
            'below_participation' => false,
            'employee_social' => 14200, 'employee_health' => 9000, 'health_min_topup' => 0,
            'employee_deductions' => 23200, 'tax_base' => 200000,
            'tax_high_threshold' => 146901, 'tax_high_base' => 53099, 'advance_tax' => 34248,
            'credit_taxpayer' => 0, 'credit_children' => 0, 'credit_total' => 0,
            'advance_tax_withheld' => 34248, 'net' => 142552,
            'employer_social' => 49600, 'employer_health' => 18000, 'employer_total' => 67600,
            'health_total' => 27000, 'social_total' => 63800, 'remittance_total' => 125048,
            'super_gross' => 267600,
        ]];

        yield 'strop §15a krátí obě strany sociálního pojistného' => [200000.0, null, 2300000.0, [
            'gross' => 200000, 'minimum_wage' => 22400, 'assessment_base' => 200000,
            'social_base' => 50416, 'social_max_base' => 2350416, 'social_base_capped' => true,
            'below_participation' => false,
            'employee_social' => 3580, 'employee_health' => 9000, 'health_min_topup' => 0,
            'employee_deductions' => 12580, 'tax_base' => 200000,
            'tax_high_threshold' => 146901, 'tax_high_base' => 53099, 'advance_tax' => 34248,
            'credit_taxpayer' => 0, 'credit_children' => 0, 'credit_total' => 0,
            'advance_tax_withheld' => 34248, 'net' => 153172,
            'employer_social' => 12504, 'employer_health' => 18000, 'employer_total' => 30504,
            'health_total' => 27000, 'social_total' => 16084, 'remittance_total' => 77332,
            'super_gross' => 230504,
        ]];

        yield 'nulová hrubá mzda — zbyde jen doplatek do minimálního VZ' => [0.0, null, null, [
            'gross' => 0, 'minimum_wage' => 22400, 'assessment_base' => 22400,
            'social_base' => 0, 'social_max_base' => 2350416, 'social_base_capped' => false,
            'below_participation' => true,
            'employee_social' => 0, 'employee_health' => 0, 'health_min_topup' => 3024,
            'employee_deductions' => 3024, 'tax_base' => 0,
            'tax_high_threshold' => 146901, 'tax_high_base' => 0, 'advance_tax' => 0,
            'credit_taxpayer' => 0, 'credit_children' => 0, 'credit_total' => 0,
            'advance_tax_withheld' => 0, 'net' => -3024,
            'employer_social' => 0, 'employer_health' => 0, 'employer_total' => 0,
            'health_total' => 3024, 'social_total' => 0, 'remittance_total' => 3024,
            'super_gross' => 0,
        ]];
    }

    /**
     * Měsíční slevy se počítají z ROČNÍCH částek v `TaxConstants` (§ 38h odst. 4).
     * Ruleset vede jejich měsíční protějšky, takže tenhle test je zároveň důkaz,
     * že se obě reprezentace nerozešly: 30 840 / 12 = 2 570 = ruleset.
     */
    public function testMonthlyCreditsAreUnchanged(): void
    {
        $c = TaxConstants::forYear(2026);

        self::assertSame(
            ['taxpayer' => 0, 'children' => 0, 'total' => 0],
            PayrollCalculator::monthlyCredits($c, false, 0),
        );
        self::assertSame(
            ['taxpayer' => 2570, 'children' => 0, 'total' => 2570],
            PayrollCalculator::monthlyCredits($c, true, 0),
        );
        self::assertSame(
            ['taxpayer' => 2570, 'children' => 5447, 'total' => 8017],
            PayrollCalculator::monthlyCredits($c, true, 3),
        );
        self::assertSame(
            ['taxpayer' => 0, 'children' => 10087, 'total' => 10087],
            PayrollCalculator::monthlyCredits($c, false, 5),
        );
    }

    /**
     * Regrese na JEDNOTKY. Ruleset drží peníze v setinách; kdyby se převod
     * vynechal, minimální mzda by byla 2 240 000 Kč a rozpad by byl nesmysl,
     * který by ale prošel jako „nějaké číslo". Kontroluje se i typ — na `int`
     * visí `assertSame` napříč daňovými testy.
     */
    public function testMirroredAmountsAreWholeCrownIntegers(): void
    {
        foreach ([2025 => [20800, 139671, 4500], 2026 => [22400, 146901, 4500]] as $year => $expected) {
            $c = TaxConstants::forYear($year);
            self::assertSame($expected[0], $c['minimum_wage'], "minimální mzda {$year}");
            self::assertSame($expected[1], $c['advance_tax_high_threshold'], "hranice §38h/2 {$year}");
            self::assertSame($expected[2], $c['sickness_participation_threshold'], "rozhodný příjem {$year}");
        }
    }
}
