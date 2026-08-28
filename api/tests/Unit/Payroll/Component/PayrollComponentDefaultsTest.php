<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Component\PayrollBenefitBasketService;
use MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefaults;
use MyInvoice\Service\Payroll\Component\PayrollComponentKind;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MZ-08 — zařazení výchozích složek do zákonných košů osvobození.
 *
 * Původně tu stály částky: `annual_limit_minor` se u výchozích složek dosazoval
 * z rulesetu. Byl to ale strop JEDNÉ složky, kdežto § 6 odst. 9 ZDP limituje
 * ÚHRN za ustanovení — dvě složky téhož bodu limit obešly a překročení navíc
 * blokovalo schválení místo toho, aby se přebytek zdanil. Od migrace 1480 nese
 * složka jen zařazení do koše a částku drží ruleset.
 *
 * Test proto drží dvojí: u kterých složek koš JE (a který), a u kterých
 * VĚDOMĚ není.
 */
final class PayrollComponentDefaultsTest extends TestCase
{
    /** Kód složky => zákonný koš, do kterého její plnění spadá. */
    private const EXPECTED_BASKETS = [
        // § 6 odst. 9 písm. d) bod 1 — nepeněžní zdravotnická plnění, průměrná mzda.
        'ZDRAVOTNI_BENEFIT' => 'non_cash_health',
        // § 6 odst. 9 písm. d) bod 2 — volnočasová plnění, polovina průměrné mzdy.
        'REKREACE_VOLNY_CAS' => 'non_cash_leisure',
        // § 6 odst. 9 písm. m) — produkty spoření na stáří, 50 000 Kč ze zákona.
        'PRISPEVEK_PENZE_ZIVOTNI' => 'old_age_savings',
        // § 6 odst. 9 písm. b) — příspěvek na stravování, limit ZA JEDNU SMĚNU.
        'PRISPEVEK_STRAVOVANI' => 'meal_per_shift',
        // § 6 odst. 9 písm. i) — přechodné ubytování, 3 500 Kč MĚSÍČNĚ.
        'PRECHODNE_UBYTOVANI' => 'temporary_accommodation',
    ];

    /**
     * Částky košů, ověřené proti doslovnému znění ZDP účinnému pro rok 2026.
     * Průměrná mzda za zdaňovací období 2026 je 48 967 Kč (§ 21g odst. 2 ZDP
     * odkazuje na zákon o pojistném na sociální zabezpečení). U příspěvku na
     * stravování je to částka NA JEDNU SMĚNU, tedy 70 % ze 185,00 Kč.
     */
    private const EXPECTED_BASKET_LIMITS = [
        'non_cash_health' => 4_896_700,
        'non_cash_leisure' => 2_448_350,
        'old_age_savings' => 5_000_000,
        'meal_per_shift' => 12_950,
        'temporary_accommodation' => 350_000,
    ];

    /**
     * Benefitní složky, u kterých roční limit záměrně NENÍ, a proč. Kdyby sem
     * někdo limit doplnil naslepo, blokoval by schválení plnění, které zákon
     * v takové výši připouští.
     *
     * @var array<string,string>
     */
    private const DELIBERATELY_WITHOUT_BASKET = [
        'SOUKROME_VOZIDLO' => '§ 6 odst. 6 — ocenění příjmu, žádné osvobození s ročním stropem.',
        'VZDELAVANI' => '§ 6 odst. 9 písm. a) — odborný rozvoj je osvobozený bez limitu.',
        'PRISPEVEK_DLOUHODOBA_PECE' => 'Zařazení pod § 6 odst. 9 písm. m) určí až účetní.',
    ];

    public function testBenefitComponentsLandInTheRightStatutoryBasket(): void
    {
        $rows = $this->rowsByCode('2026-01-01');

        foreach (self::EXPECTED_BASKETS as $code => $expected) {
            self::assertArrayHasKey($code, $rows, "Výchozí číselník ztratil složku {$code}.");
            self::assertSame(
                $expected,
                $rows[$code]['exemption_basket'],
                "Složka {$code} je zařazená do jiného zákonného koše.",
            );
        }
    }

    /**
     * Zákonná částka NESMÍ skončit ve složkovém stropu: ten je tvrdá zábrana
     * schválení, kdežto zákon plnění nad limit nezakazuje, jen ho zdaňuje.
     */
    public function testDefaultComponentsCarryNoHardAnnualCap(): void
    {
        foreach ($this->rowsByCode('2026-01-01') as $code => $row) {
            self::assertArrayNotHasKey(
                'annual_limit_minor',
                $row,
                "Výchozí složka {$code} znovu dosazuje zákonnou částku do stropu složky.",
            );
        }
    }

    public function testBasketLimitsMatchTheStatute(): void
    {
        $baskets = new PayrollBenefitBasketService(CzechPayrollRulesets2026::provider());

        foreach (self::EXPECTED_BASKET_LIMITS as $basket => $expected) {
            self::assertSame(
                $expected,
                $baskets->limitMinor(
                    PayrollBenefitExemptionBasket::from($basket),
                    '2026-01-01',
                    1,
                ),
                "Limit koše {$basket} neodpovídá zákonné částce.",
            );
        }
    }

    #[DataProvider('inconsistentMealRules')]
    public function testMealLimitFailsClosedWhenTheSeventyPercentRuleDiverges(
        PayrollRulesetDomain $domain,
        string $key,
        PayrollRuleValue $value,
    ): void {
        $baskets = new PayrollBenefitBasketService(
            self::providerWithParameter($domain, $key, $value),
        );

        $this->expectException(PayrollRulesetException::class);
        $this->expectExceptionMessage('benefit_exemption.meal.per_shift');
        $baskets->limitMinor(
            PayrollBenefitExemptionBasket::MealPerShift,
            '2026-01-01',
            1,
        );
    }

    /** @return iterable<string,array{PayrollRulesetDomain,string,PayrollRuleValue}> */
    public static function inconsistentMealRules(): iterable
    {
        yield 'explicit per-shift limit' => [
            PayrollRulesetDomain::IncomeTax,
            'benefit_exemption.meal.per_shift',
            PayrollRuleValue::moneyMinor(12_949),
        ];
        yield '70 percent rate' => [
            PayrollRulesetDomain::IncomeTax,
            'benefit_exemption.meal.shift_rate',
            PayrollRuleValue::rate('0.69'),
        ];
        yield 'travel allowance ceiling' => [
            PayrollRulesetDomain::TravelAllowances,
            'meal_allowance.band_1.tax_exempt_maximum',
            PayrollRuleValue::moneyMinor(18_400),
        ];
    }

    public function testComponentsWithoutADocumentedBasketStayEmpty(): void
    {
        $rows = $this->rowsByCode('2026-01-01');

        foreach (self::DELIBERATELY_WITHOUT_BASKET as $code => $why) {
            self::assertArrayHasKey($code, $rows, "Výchozí číselník ztratil složku {$code}.");
            self::assertNull($rows[$code]['exemption_basket'], $why);
        }
    }

    public function testOnlyBenefitComponentsCarryAStatutoryBasket(): void
    {
        foreach ($this->rowsByCode('2026-01-01') as $code => $row) {
            if ($row['exemption_basket'] === null) {
                continue;
            }
            self::assertTrue(
                PayrollComponentKind::from($row['component_kind'])->isBenefit(),
                "Složka {$code} má zákonný koš, ale není benefitní — "
                . 'PayrollComponentDefinition takovou kombinaci odmítne.',
            );
            self::assertArrayHasKey(
                $row['exemption_basket'],
                self::EXPECTED_BASKET_LIMITS,
            );
        }
    }

    /**
     * Osvobozená složka bez uvedeného podkladu neprojde mzdovým během. Kdyby ji
     * číselník takhle založil, byl by měsíc s ní neuzavíratelný — přesně stav,
     * ve kterém CESTOVNI_NAHRADA_LIMIT byla.
     */
    public function testEveryExemptDefaultStatesItsExemptionBasis(): void
    {
        foreach ($this->rowsByCode('2026-01-01') as $code => $row) {
            if ($row['tax_treatment'] !== 'exempt') {
                self::assertNull(
                    $row['exemption_basis'],
                    "Složka {$code} tvrdí podklad osvobození, ale osvobozená není.",
                );
                continue;
            }
            self::assertNotNull(
                $row['exemption_basis'],
                "Osvobozená složka {$code} neuvádí, čím je osvobození podložené.",
            );
        }
    }

    public function testMandatoryRiskySavingsIsNotLeftForManualClassification(): void
    {
        $row = $this->rowsByCode('2026-01-01')['PRISPEVEK_RIZIKOVE_SPORENI'];

        self::assertSame('non_monetary', $row['value_kind']);
        self::assertSame('exempt', $row['tax_treatment']);
        self::assertSame('excluded', $row['social_treatment']);
        self::assertSame('excluded', $row['health_treatment']);
        self::assertSame('excluded', $row['average_earning_treatment']);
        self::assertSame('excluded', $row['enforcement_treatment']);
        self::assertSame('included', $row['jmhz_treatment']);
        self::assertSame('old_age_savings', $row['exemption_basket']);
        self::assertSame('benefit_basket', $row['exemption_basis']);
    }

    public function testEveryDefaultCodeIsKnownToTheDeletionGuard(): void
    {
        $codes = PayrollComponentDefaults::codes();

        self::assertSame($codes, array_values(array_unique($codes)));
        self::assertContains('MZDA_MESICNI', $codes);
        self::assertContains('ZDRAVOTNI_BENEFIT', $codes);
    }

    public function testClassificationVersionsAreOrderedByEffectiveDate(): void
    {
        $defaults = new PayrollComponentDefaults(
            CzechPayrollRulesets2026::provider(),
            [
                ['valid_from' => '2026-06-01', 'rows' => [self::syntheticRow('2026-06-01')]],
                ['valid_from' => '2026-01-01', 'rows' => [self::syntheticRow('2026-01-01')]],
            ],
        );

        self::assertSame(
            ['2026-01-01', '2026-06-01'],
            array_column($defaults->versions(), 'valid_from'),
        );
    }

    /**
     * Verze, ke které ruleset daně z příjmů nezná limit koše, se nezaloží vůbec.
     * Založit složku s košem bez částky by tiše vypnulo přesně to hlídání, kvůli
     * kterému tahle třída vznikla.
     */
    public function testVersionWithoutAnEffectiveIncomeTaxRulesetIsSkipped(): void
    {
        $defaults = new PayrollComponentDefaults(
            CzechPayrollRulesets2026::provider(),
            [
                ['valid_from' => '2026-01-01', 'rows' => [self::syntheticRow('2026-01-01')]],
                ['valid_from' => '2031-01-01', 'rows' => [self::syntheticRow('2031-01-01')]],
            ],
        );

        self::assertSame(['2026-01-01'], array_column($defaults->versions(), 'valid_from'));
    }

    /** @return array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string,8:string,9:string,10:string,11:string,12:?string} */
    private static function syntheticRow(string $label): array
    {
        return [
            'SYN_' . str_replace('-', '', $label),
            'Syntetická složka',
            'benefit_recreation',
            'non_monetary',
            'one_off',
            'manual_review',
            'manual_review',
            'manual_review',
            'excluded',
            'manual_review',
            'manual_review',
            'included',
            'non_cash_leisure',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function rowsByCode(string $validFrom): array
    {
        $defaults = new PayrollComponentDefaults(CzechPayrollRulesets2026::provider());
        foreach ($defaults->versions() as $version) {
            if ($version['valid_from'] !== $validFrom) {
                continue;
            }
            $rows = [];
            foreach ($version['rows'] as $row) {
                $rows[$row['code']] = $row;
            }

            return $rows;
        }

        self::fail("Výchozí klasifikace nemá verzi účinnou od {$validFrom}.");
    }

    private static function providerWithParameter(
        PayrollRulesetDomain $domain,
        string $key,
        PayrollRuleValue $value,
    ): PayrollRulesetProvider {
        $versions = CzechPayrollRulesets2026::provider()->versions();
        foreach ($versions as $index => $delivered) {
            if ($delivered->domain !== $domain || !$delivered->contains('2026-01-01')) {
                continue;
            }
            $parameters = $delivered->parameters;
            $parameters[$key] = $value;
            ksort($parameters, SORT_STRING);
            $versions[$index] = new PayrollRulesetVersion(
                $delivered->id,
                $delivered->version,
                $delivered->domain,
                $delivered->effectiveFrom,
                $delivered->effectiveTo,
                PayrollRulesetLifecycle::Active,
                $delivered->capability,
                $delivered->sources,
                $parameters,
                $delivered->approval,
                $delivered->technicalReview,
            );
        }

        return new PayrollRulesetProvider($versions);
    }
}
