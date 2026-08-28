<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Ruleset;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2025;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleParameterCatalog;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetCapability;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetOrigin;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use MyInvoice\Service\Payroll\Ruleset\VendorRulesetApprover;
use MyInvoice\Service\Payroll\Ruleset\VendorRulesetManifest;
use MyInvoice\Service\Payroll\SupportMatrix;
use PHPUnit\Framework\TestCase;

/**
 * Ročník 2025, doplněný zpětně kvůli opravným revizím a ročnímu zúčtování
 * daně za rok 2025.
 */
final class CzechPayrollRulesets2025Test extends TestCase
{
    protected function setUp(): void
    {
        VendorRulesetApprover::configure(VendorRulesetApprover::DEFAULT_NAME);
    }

    protected function tearDown(): void
    {
        VendorRulesetApprover::reset();
    }

    public function testCanonicalManifestIsByteStable(): void
    {
        self::assertSame(
            CzechPayrollRulesets2025::provider()->canonicalManifestJson(),
            CzechPayrollRulesets2025::provider()->canonicalManifestJson(),
        );
    }

    /**
     * Ročník 2026 se doplněním roku 2025 nesměl hnout ani o bajt — visí na něm
     * zmrazené snapshoty výplat i integritní piny. Skládání ročníků proto probíhá
     * mimo obě třídy a tenhle test je ta podmínka.
     */
    public function testAddingTheOlderYearLeavesTheYear2026Untouched(): void
    {
        $standalone = CzechPayrollRulesets2026::provider()->versions();
        $composed = array_values(array_filter(
            CzechPayrollRulesets::provider()->versions(),
            static fn (PayrollRulesetVersion $version): bool => str_contains($version->id, '2026'),
        ));

        self::assertSame(
            array_map(static fn (PayrollRulesetVersion $v): string => $v->id, $standalone),
            array_map(static fn (PayrollRulesetVersion $v): string => $v->id, $composed),
        );
        foreach ($standalone as $index => $version) {
            self::assertSame($version->contentHash, $composed[$index]->contentHash);
            self::assertSame($version->canonicalHash, $composed[$index]->canonicalHash);
        }

        // A hlavně: výběr podle data pro rok 2026 vrací pořád totéž.
        foreach (PayrollRulesetDomain::cases() as $domain) {
            $before = CzechPayrollRulesets2026::provider()->forDate($domain, '2026-08-03');
            $after = CzechPayrollRulesets::provider()->forDate($domain, '2026-08-03');
            self::assertSame($before->id, $after->id, $domain->value);
            self::assertSame($before->canonicalSnapshot, $after->canonicalSnapshot, $domain->value);
        }
    }

    public function testVendorManifestPinsEveryDeliveredVersionOf2025(): void
    {
        foreach (CzechPayrollRulesets2025::provider()->versions() as $version) {
            self::assertTrue(
                VendorRulesetManifest::contains($version->contentHash),
                sprintf(
                    "Otisk dodané verze %s není v manifestu. Doplňte:\n        // %s\n        '%s',",
                    $version->id,
                    $version->id,
                    $version->contentHash,
                ),
            );
            self::assertSame(PayrollRulesetOrigin::Vendor, $version->origin, $version->id);
            self::assertSame(PayrollRulesetLifecycle::Active, $version->lifecycle, $version->id);
        }
    }

    /**
     * Manifest drží PŘESNĚ dodané ročníky — ani otisk navíc, ani jeden chybějící.
     * Otisk navíc by znamenal, že se jako dodaná pozná i sada, kterou aplikace
     * nikdy nedodala, a ta by pak směla být účinná bez schválení.
     */
    public function testManifestHoldsExactlyTheDeliveredVersionsOfEveryYear(): void
    {
        $versions = CzechPayrollRulesets::provider()->versions();
        usort(
            $versions,
            static fn (PayrollRulesetVersion $left, PayrollRulesetVersion $right): int
                => $left->id <=> $right->id,
        );
        $hashes = array_map(
            static fn (PayrollRulesetVersion $version): string => $version->contentHash,
            $versions,
        );

        self::assertSame(
            VendorRulesetManifest::CONTENT_HASHES,
            $hashes,
            "Manifest dodaných sad neodpovídá kódu. Správné znění:\n" . implode("\n", array_map(
                static fn (PayrollRulesetVersion $version): string
                    => sprintf("        // %s\n        '%s',", $version->id, $version->contentHash),
                $versions,
            )),
        );
    }

    /**
     * Rok 2025 je celý pokrytý pro každou doménu, kterou mzdový výpočet
     * potřebuje — a JEN pro ně. Lhůty, číselníky a podání jsou navázané na JMHZ
     * účinné od 1. 1. 2026 a pro rok 2025 sadu záměrně nemají.
     */
    public function test2025IsSupportedAndTheJmhzDomainsStayMissing(): void
    {
        $provider = CzechPayrollRulesets::provider();

        self::assertSame([2025, 2026], (new SupportMatrix($provider))->supportedYears());

        foreach ([
            PayrollRulesetDomain::Deadlines,
            PayrollRulesetDomain::Codebooks,
            PayrollRulesetDomain::Submissions,
        ] as $domain) {
            $this->assertNoRulesetFor($provider, $domain);
        }
    }

    /** @return iterable<string, array{0:string, 1:string, 2:int}> */
    public static function moneyParameters(): iterable
    {
        yield 'měsíční hranice 23% sazby = 3 × 46 557' =>
            ['income_tax', 'advance.high_threshold.monthly', 13_967_100];
        yield 'sleva na poplatníka 2 570 Kč měsíčně' =>
            ['income_tax', 'credit.taxpayer.monthly', 257_000];
        yield 'rozhodná částka DPP 11 500 Kč' =>
            ['income_tax', 'dpp.withholding.threshold', 1_150_000];
        yield 'rozhodná částka ostatních příjmů 4 500 Kč' =>
            ['income_tax', 'other.withholding.threshold', 450_000];
        yield 'minimální měsíční příjem pro bonus = polovina minimální mzdy' =>
            ['income_tax', 'bonus.minimum_income.monthly', 1_040_000];
        yield 'roční limit zdravotních benefitů = průměrná mzda' =>
            ['income_tax', 'benefit_exemption.non_cash_health.yearly', 4_655_700];
        yield 'roční limit volnočasových benefitů = polovina průměrné mzdy' =>
            ['income_tax', 'benefit_exemption.non_cash_leisure.yearly', 2_327_850];
        yield 'maximální vyměřovací základ = 48 × 46 557' =>
            ['social_insurance', 'maximum_assessment_base.yearly', 223_473_600];
        yield 'minimální zdravotní pojistné = 13,5 % z minimální mzdy' =>
            ['health_insurance', 'minimum_contribution.monthly', 280_800];
        yield 'minimální mzda 20 800 Kč' =>
            ['employment_thresholds', 'minimum_wage.monthly_40h_week', 2_080_000];
        yield 'minimální hodinová mzda 124,40 Kč' =>
            ['employment_thresholds', 'minimum_wage.hourly_40h_week', 12_440];
        yield 'první hodinová redukční hranice 271,60 Kč' =>
            ['compensation_averages', 'wage_compensation.hourly_boundary_1_minor', 27_160];
        yield 'druhá hodinová redukční hranice 407,40 Kč' =>
            ['compensation_averages', 'wage_compensation.hourly_boundary_2_minor', 40_740];
        yield 'třetí hodinová redukční hranice 814,80 Kč' =>
            ['compensation_averages', 'wage_compensation.hourly_boundary_3_minor', 81_480];
        yield 'základní náhrada za 1 km osobním vozem 5,80 Kč' =>
            ['travel_allowances', 'vehicle.basic_compensation.car_per_km', 580];
        yield 'průměrná cena nafty 34,70 Kč' =>
            ['travel_allowances', 'fuel.average_price.diesel_per_litre', 3_470];
        yield 'životní minimum jednotlivce 4 860 Kč' =>
            ['enforcement_deductions', 'life_minimum.monthly', 486_000];
        yield 'částka na náklady na bydlení 14 680 Kč' =>
            ['enforcement_deductions', 'normative_rent.monthly', 1_468_000];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('moneyParameters')]
    public function testDeliveredAmountsMatchTheirLegalSource(
        string $domain,
        string $key,
        int $expectedMinorUnits,
    ): void {
        $version = CzechPayrollRulesets::provider()->forDate(
            PayrollRulesetDomain::from($domain),
            '2025-06-15',
        );

        self::assertSame($expectedMinorUnits, $version->parameter($key)->value, "{$domain}|{$key}");
    }

    /**
     * Stravenkový paušál je 70 % horní hranice stravného pásma 5 až 12 hodin.
     * Kdyby se sazba stravného změnila a limit osvobození ne, vznikla by tichá
     * chyba v každé výplatě se stravenkovým paušálem.
     */
    public function testMealBenefitLimitIsDerivedFromTheTravelAllowanceBand(): void
    {
        $provider = CzechPayrollRulesets::provider();
        $band = $provider->forDate(PayrollRulesetDomain::TravelAllowances, '2025-06-15')
            ->parameter('meal_allowance.band_1.tax_exempt_maximum')->value;
        $limit = $provider->forDate(PayrollRulesetDomain::IncomeTax, '2025-06-15')
            ->parameter('benefit_exemption.meal.per_shift')->value;

        self::assertIsInt($band);
        self::assertSame(17_700, $band);
        self::assertSame((int) floor($band * 70 / 100), $limit);
    }

    /**
     * Rok 2025 počítá nezabavitelnou částku jinými podíly než rok 2026
     * (2/3 a 1,5násobek proti 85/100 a 1,9násobku). Právě proto se podíly vezou
     * v datech — kdyby byly v kódu, oprava roku 2025 by se počítala pravidly 2026.
     */
    public function testEnforcementAmountsAreConsistentWithTheirShares(): void
    {
        $version = CzechPayrollRulesets::provider()->forDate(
            PayrollRulesetDomain::EnforcementDeductions,
            '2025-06-15',
        );
        $money = static fn (string $key): int => (int) $version->parameter($key)->value;
        $int = static fn (string $key): int => (int) $version->parameter($key)->value;

        $base = $money('protected_amount.calculation_base.monthly');
        self::assertSame(1_954_000, $base);
        self::assertSame(
            $base,
            $money('life_minimum.monthly')
                + $money('normative_rent.monthly')
                + $money('energy_flat.monthly'),
        );
        // Dvě třetiny nevycházejí na celý haléř; nařízení publikuje 13 026,67 Kč,
        // tedy zaokrouhleno NAHORU — proto ceil, ne intdiv jako u roku 2026.
        self::assertSame(
            (int) ceil($base * $int('debtor_share.numerator') / $int('debtor_share.denominator')),
            $money('protected_amount.debtor_base.monthly'),
        );
        self::assertSame(1_302_667, $money('protected_amount.debtor_base.monthly'));
        self::assertSame(
            intdiv(
                $base * $int('fully_attachable.factor_numerator'),
                $int('fully_attachable.factor_denominator'),
            ),
            $money('fully_attachable.threshold.monthly'),
        );
        self::assertSame(2_931_000, $money('fully_attachable.threshold.monthly'));
    }

    /**
     * Nedoložená hodnota se NEDOSAZUJE. Tři parametry sociálního pojištění se
     * pro rok 2025 nepodařilo doložit z primárního zdroje, a proto jsou vedené
     * jako ruční posouzení — výpočet, který na ně sáhne, fail-closed selže.
     */
    public function testUnverifiedRatesFailClosedInsteadOfGuessing(): void
    {
        $version = CzechPayrollRulesets::provider()->forDate(
            PayrollRulesetDomain::SocialInsurance,
            '2025-06-15',
        );

        foreach ([
            'employee.discount.working_pensioner',
            'employer.rate.rescue_and_company_fire_service',
            'employer.rate.risk_employment',
        ] as $key) {
            self::assertSame(
                PayrollRulesetCapability::ManualReview,
                $version->parameters[$key]->capability,
                $key,
            );
            self::assertStringContainsString('K OVĚŘENÍ', (string) $version->parameters[$key]->value);
            self::assertNotNull(
                PayrollRuleParameterCatalog::manualReview('social_insurance', $key),
                "Ruční posouzení {$key} nemá v katalogu vysvětlení.",
            );
        }

        // Běžná sazba zaměstnavatele naopak doložená je a počítá se normálně.
        self::assertSame('0.248', $version->parameter('employer.rate.ordinary')->value);
        self::assertSame('0.071', $version->parameter('employee.rate.ordinary')->value);
    }

    /**
     * Pojistné na rizikové zaměstnání a spoření na stáří z něj odvozené nabylo
     * účinnosti až 1. 1. 2026. Klíč, který v roce 2025 neexistoval, tu proto
     * nesmí být ani jako nula.
     */
    public function testRulesEffectiveFrom2026AreAbsentFrom2025(): void
    {
        $version = CzechPayrollRulesets::provider()->forDate(
            PayrollRulesetDomain::SocialInsurance,
            '2025-06-15',
        );

        foreach (array_keys($version->parameters) as $key) {
            self::assertStringStartsNotWith('risky_savings.', $key);
        }
    }

    /**
     * Nový parametr přidaný do ročníku 2026 se v ročníku 2025 nesmí tiše ztratit.
     * Chybějící klíč není bezpečná mezera — je to `PayrollRulesetException` uprostřed
     * zpětné opravy mzdy, tedy přesně v okamžiku, kdy s tím účetní nic nenadělá.
     *
     * Výjimky jsou JMENOVANÉ a je u nich napsáno proč. Cokoli dalšího musí test
     * shodit, aby se o tom rozhodovalo vědomě.
     */
    public function testEveryDomainOf2025CarriesTheSameParameterKeysAs2026(): void
    {
        /** Klíče, které v roce 2025 legitimně neexistovaly. */
        $absentIn2025 = [
            // Pojistné na rizikové zaměstnání a spoření na stáří z něj — účinné
            // až od 1. 1. 2026 (§ 7 odst. 1 písm. c) z. č. 589/1992 Sb.).
            'social_insurance' => [
                'risky_savings.effective_from',
                'risky_savings.minimum_shift_eighths',
                'risky_savings.payment_due.months_after_period',
                'risky_savings.payment_due.rule',
                'risky_savings.rate',
            ],
        ];

        foreach (CzechPayrollRulesets2025::provider()->versions() as $version2025) {
            $keys2026 = array_keys(
                CzechPayrollRulesets2026::provider()
                    ->forDate($version2025->domain, '2026-08-03')
                    ->parameters,
            );
            $expected = array_values(array_diff(
                $keys2026,
                $absentIn2025[$version2025->domain->value] ?? [],
            ));
            $missing = array_values(array_diff($expected, array_keys($version2025->parameters)));

            self::assertSame(
                [],
                $missing,
                sprintf(
                    'Ročník 2025 (%s) nemá klíče, které ročník 2026 má: %s. Doplňte je hodnotou '
                    . 'platnou pro rok 2025, nebo je zapište mezi jmenované výjimky s důvodem.',
                    $version2025->domain->value,
                    implode(', ', $missing),
                ),
            );
        }
    }

    public function testEveryParameterHasCzechLabelAndExplanation(): void
    {
        $provider = CzechPayrollRulesets::provider();

        self::assertSame([], PayrollRuleParameterCatalog::missingLabels($provider));
        self::assertSame([], PayrollRuleParameterCatalog::missingManualReviewExplanations($provider));
    }

    private function assertNoRulesetFor(
        \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider $provider,
        PayrollRulesetDomain $domain,
    ): void {
        try {
            $provider->forDate($domain, '2025-06-15');
        } catch (PayrollRulesetException) {
            return;
        }

        self::fail("Doména {$domain->value} nemá mít pro rok 2025 ruleset.");
    }
}
