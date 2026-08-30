<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax;

use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Zafixuje ověřené hodnoty (Finanční správa / ČSSZ / VZP, k 2026-05). Změna těchto
 * konstant musí být vědomá — proto je hlídá test.
 */
final class TaxConstantsTest extends TestCase
{
    public function testVerified2024Values(): void
    {
        $c = TaxConstants::forYear(2024);
        self::assertSame(array_keys(TaxConstants::forYear(2025)), array_keys($c));
        self::assertSame(89976, $c['pausal_annual']['band1']);
        self::assertSame(1582812, $c['tax_high_threshold']);
        self::assertSame(158292, $c['social_min_base_main']);
        self::assertSame(58044, $c['social_min_base_secondary']);
        self::assertSame(2110416, $c['social_max_base']);
        self::assertSame(105520, $c['social_secondary_participation_threshold']);
        self::assertSame(263802, $c['health_min_base']);
        self::assertSame(18900, $c['minimum_wage']);
        self::assertSame(113400, $c['child_bonus_min_income']);
        self::assertSame(2000000, $c['vat_limit_low']);
        self::assertSame(2000000, $c['vat_limit_high']);
        self::assertSame(12.0, $c['vat_rate_reduced']);
        self::assertSame(8000, $c['sickness_min_monthly_base']);
    }

    public function testVerified2025Values(): void
    {
        $c = TaxConstants::forYear(2025);
        self::assertSame(104592, $c['pausal_annual']['band1']);  // 12× 8 716
        self::assertSame(1676052, $c['tax_high_threshold']);     // 36× prům. mzda 46 557
        self::assertSame(0.55, $c['social_assessment_pct']);
        self::assertSame(0.50, $c['health_assessment_pct']);     // ← zdravotní 50 %, ne 55 %
        self::assertSame(195540, $c['social_min_base_main']);    // 35 % × 46 557 × 12
        self::assertSame(61476, $c['social_min_base_secondary']);
        self::assertSame(111736, $c['social_secondary_participation_threshold']); // rozhodná částka vedlejší SVČ (ČSSZ)
        self::assertSame(279342, $c['health_min_base']);         // 50 % × 46 557 × 12
        self::assertSame([15204, 22320, 27840], $c['child_credits']);
    }

    public function testVerified2026Values(): void
    {
        $c = TaxConstants::forYear(2026);
        // 6× 9 984 (led–čvn) + 6× 9 162 (čvc–pro, novela od 1. 7. 2026), NE 12× 9 984
        self::assertSame(114876, $c['pausal_annual']['band1']);
        self::assertSame(200940, $c['pausal_annual']['band2']);  // 12× 16 745 — beze změny
        self::assertSame(325668, $c['pausal_annual']['band3']);  // 12× 27 139 — beze změny
        self::assertSame(1762812, $c['tax_high_threshold']);     // 36× prům. mzda 48 967
        self::assertSame(146901, $c['advance_tax_high_threshold']); // 3× prům. mzda 48 967
        self::assertSame(0.50, $c['health_assessment_pct']);
        self::assertSame(235044, $c['social_min_base_main']);    // 40 % × 48 967 × 12
        self::assertSame(64644, $c['social_min_base_secondary']);
        self::assertSame(117521, $c['social_secondary_participation_threshold']); // rozhodná částka vedlejší SVČ 2026 (ČSSZ)
        self::assertSame(293802, $c['health_min_base']);         // 50 % × 48 967 × 12
    }

    public function testIncomeTaxReturnKeys(): void
    {
        // Epic DP (issue #18) — klíče pro DPPO + odvody OSVČ musí být pro všechny roky.
        foreach ([2024, 2025, 2026] as $year) {
            $c = TaxConstants::forYear($year);
            self::assertSame(0.21, $c['corporate_tax_rate'], "corporate_tax_rate $year");
            self::assertSame(0.027, $c['sickness_rate'], "sickness_rate $year");
            self::assertSame($year === 2024 ? 8000 : 9000, $c['sickness_min_monthly_base'], "sickness_min_monthly_base $year");
            self::assertSame(0.30, $c['donation_cap_po_pct'], "donation_cap_po_pct $year");
            self::assertSame(0.30, $c['donation_cap_fo_pct'], "donation_cap_fo_pct $year");
            self::assertSame(1000, $c['donation_min_fo'], "donation_min_fo $year");
            self::assertSame(18000, $c['disabled_employee_credit'], "disabled_employee_credit $year");
            self::assertSame(60000, $c['disabled_employee_credit_severe'], "disabled_employee_credit_severe $year");
            self::assertSame(30000, $c['advance_threshold_low'], "advance_threshold_low $year");
            self::assertSame(150000, $c['advance_threshold_high'], "advance_threshold_high $year");
            self::assertSame(1000, $c['rounding_base_po'], "rounding_base_po $year");
            self::assertSame(100, $c['rounding_base_fo'], "rounding_base_fo $year");
        }
    }

    /**
     * Audit 2026-08 kategorie B ("patří do roční sady") — hodnoty přesunuté z PHP
     * literálů (§8a/§8c ZoR, §46, §23/3/a/12, §38a, §74b, §79, §78/§78a, §148 DŘ,
     * §30, §110f, §94) musí být pro všechny existující ročníky přítomné a mít
     * dnes platnou hodnotu (historicky se neměnily).
     */
    public function testAuditCategoryBKeysArePresentForAllYears(): void
    {
        foreach ([2024, 2025, 2026] as $year) {
            $c = TaxConstants::forYear($year);
            self::assertSame(18, $c['bad_debt_provision_8a_50pct_months'], "8a 50% $year");
            self::assertSame(30, $c['bad_debt_provision_8a_100pct_months'], "8a 100% $year");
            self::assertSame(12, $c['bad_debt_provision_8c_months'], "8c months $year");
            self::assertSame(30000, $c['bad_debt_provision_8c_limit'], "8c limit $year");
            self::assertSame(36, $c['receivable_limitation_warning_months'], "limitation $year");
            self::assertSame(10000, $c['bad_debt_small_receivable_limit'], "s46 limit $year");
            self::assertSame(20000, $c['bad_debt_small_receivable_debtor_year_limit'], "s46 debtor limit $year");
            self::assertSame(6, $c['bad_debt_small_receivable_months'], "s46 months $year");
            self::assertSame(30, $c['unpaid_liability_aging_months'], "unpaid liability $year");
            self::assertSame(0.50, $c['advance_employment_exempt_share'], "38a exempt $year");
            self::assertSame(0.15, $c['advance_employment_half_share'], "38a half $year");
            self::assertSame(6, $c['s74b_aging_months'], "s74b $year");
            self::assertSame(12, $c['s79_claim_window_months'], "s79 $year");
            self::assertSame([5, 10], $c['vat_adjustment_period_years'], "s78 period years $year");
            self::assertSame(10, $c['vat_adjustment_tolerance_points'], "s78a tolerance $year");
            self::assertSame(3, $c['assessment_period_years'], "s43/148 $year");
            self::assertSame(10000, $c['simplified_document_limit_with_vat'], "s30 $year");
            self::assertSame(10, $c['oss_evidence_retention_years'], "oss retention $year");
            self::assertSame(10, $c['vat_registration_application_deadline_working_days'], "s94 $year");
        }
        // Stejné pořadí klíčů 2024 vs 2025 (viz testVerified2024Values — 2026 se liší
        // už dnes kvůli mzdovému rulesetu/zrcadlení `dpp_withholding_limit`, nesouvisí
        // s touhle změnou).
        self::assertSame(array_keys(TaxConstants::forYear(2025)), array_keys(TaxConstants::forYear(2024)));
    }

    public function testAvailableYearsAndUnknownYearRejection(): void
    {
        self::assertContains(2024, TaxConstants::availableYears());
        self::assertContains(2025, TaxConstants::availableYears());
        self::assertContains(2026, TaxConstants::availableYears());
        foreach ([2023, 2027, 9999] as $year) {
            try {
                TaxConstants::forYear($year);
                self::fail('Neznámý rok ' . $year . ' musí být odmítnut.');
            } catch (\OutOfRangeException $e) {
                self::assertStringContainsString((string) $year, $e->getMessage());
            }
        }
    }

    /**
     * Měsíční záloha paušální daně se může změnit uprostřed roku, roční částka je
     * proto vždy dopočítaná z rozvrhu — nikdy uložený skalár.
     */
    public function testPausalScheduleDrivesAnnualAmount(): void
    {
        $c = TaxConstants::forYear(2026);
        self::assertSame(
            [
                ['from' => '2026-01-01', 'band1' => 9984, 'band2' => 16745, 'band3' => 27139],
                ['from' => '2026-07-01', 'band1' => 9162, 'band2' => 16745, 'band3' => 27139],
            ],
            $c['pausal_monthly']
        );

        // 2025: jediná sazba celý rok.
        self::assertSame(
            [['from' => '2025-01-01', 'band1' => 8716, 'band2' => 16745, 'band3' => 27139]],
            TaxConstants::forYear(2025)['pausal_monthly']
        );
    }

    /**
     * MyÚčto se od upstreamu vědomě liší: neznámý rok se NEsmí tiše počítat
     * sazbami jiného období. Chybějící sadu musí doplnit release nebo DB override
     * (TaxConstantsRepository), jinak by účetní výstup mlčky mísil dvě legislativy.
     */
    public function testUnknownYearThrowsInsteadOfSilentlyFallingBack(): void
    {
        $this->expectException(\OutOfRangeException::class);
        TaxConstants::forYear(2027);
    }

    /**
     * Když se sada pro neznámý rok převezme (repository fallback), schod uprostřed
     * roku se nesmí zopakovat — segmenty se ukotví k 1. 1. požadovaného roku.
     */
    public function testCarriedOverScheduleIsAnchoredToRequestedYear(): void
    {
        $c = TaxConstants::withDerived(TaxConstants::forYear(2026), 2027);
        self::assertSame(
            [['from' => '2027-01-01', 'band1' => 9162, 'band2' => 16745, 'band3' => 27139]],
            $c['pausal_monthly']
        );
        self::assertSame(109944, $c['pausal_annual']['band1']); // 12× 9 162
    }

    /**
     * Legacy override (starší verze aplikace ukládala jen roční částky) si roční
     * hodnotu ponechá doslova — dělení dvanácti nemusí vyjít na koruny.
     */
    public function testLegacyAnnualOnlyOverrideKeepsItsAmount(): void
    {
        $c = TaxConstants::withDerived(
            ['year' => 2026, 'pausal_annual' => ['band1' => 100000, 'band2' => 200940, 'band3' => 325668]],
            2026
        );
        self::assertSame(100000, $c['pausal_annual']['band1']);
        self::assertSame(8333.33, $c['pausal_monthly'][0]['band1']);
        self::assertSame('2026-01-01', $c['pausal_monthly'][0]['from']);
    }
}
