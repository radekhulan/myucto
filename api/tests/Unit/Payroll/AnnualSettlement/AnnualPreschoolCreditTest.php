<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\AnnualSettlement\AnnualPreschoolCreditClaim;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualPreschoolFacilityKind;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementChildMonths;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementCreditMonths;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementInput;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementResult;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementUnavailableException;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxRates;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxSettlementCalculator;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Tests\Fixtures\Payroll\Payroll2027IncomeTaxRulesetFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sleva za umístění dítěte (§ 35ba odst. 1 písm. g), § 35bd ZDP ve znění
 * zák. č. 180/2026 Sb.) v ročním zúčtování za rok 2027. Částky v haléřích.
 *
 * Minimální mzda 2027 je 24 900 Kč (zástupná sada, viz fixture), daň 15 %,
 * sleva na poplatníka 30 840 Kč, zvýhodnění na první dítě 15 204 Kč.
 */
final class AnnualPreschoolCreditTest extends TestCase
{
    private const YEAR = 2027;

    /** § 35bd odst. 1 věta druhá: nejvýše minimální mzda; hranice je neostrá. */
    #[DataProvider('capBoundaries')]
    public function testCreditIsCappedAtMinimumWage(int $expenses, int $expected): void
    {
        $result = $this->settle(60_000_000, [$this->claim(expenses: $expenses)]);

        self::assertTrue($result->performed, implode(',', $result->blockerCodes()));
        self::assertSame($expected, $result->trace['credits']['preschool']['amount_minor_units']);
        self::assertSame(3_084_000 + $expected, $result->annualCreditsMinorUnits);
    }

    /** @return iterable<string,array{int,int}> */
    public static function capBoundaries(): iterable
    {
        yield 'pod stropem' => [1_000_000, 1_000_000];
        yield 'přesně minimální mzda' => [2_490_000, 2_490_000];
        yield 'o haléř nad' => [2_490_001, 2_490_000];
        yield 'výrazně nad' => [3_600_000, 2_490_000];
        // Sleva snižuje daň zaokrouhlovanou na celé koruny nahoru — haléře ne.
        yield 'haléře dolů' => [2_489_950, 2_489_900];
    }

    /** § 35bd odst. 2 věta první a třetí: podíl minimální mzdy, celé koruny dolů. */
    #[DataProvider('sharedCare')]
    public function testSharedCareSplitsTheCap(int $households, int $expectedCap): void
    {
        $result = $this->settle(60_000_000, [$this->claim(expenses: 3_000_000, households: $households)]);

        self::assertTrue($result->performed);
        self::assertSame($expectedCap, $result->trace['credits']['preschool']['children'][0]['cap_minor_units']);
        self::assertSame($expectedCap, $result->trace['credits']['preschool']['amount_minor_units']);
    }

    /** @return iterable<string,array{int,int}> */
    public static function sharedCare(): iterable
    {
        yield 'střídavá péče' => [2, 1_245_000];
        yield 'tři domácnosti' => [3, 830_000];
        // 24 900 / 7 = 3 557,142… → 3 557 Kč
        yield 'sedm domácností' => [7, 355_700];
    }

    /** § 35bd odst. 2 věta druhá: písemná dohoda poplatníků určí jiný strop. */
    public function testWrittenAgreementOverridesTheShare(): void
    {
        $result = $this->settle(60_000_000, [
            $this->claim(expenses: 3_000_000, households: 2, agreedCap: 2_000_000),
        ]);

        self::assertTrue($result->performed);
        self::assertSame(2_000_000, $result->trace['credits']['preschool']['amount_minor_units']);
    }

    public function testEachChildHasItsOwnCap(): void
    {
        $result = $this->settle(60_000_000, [
            $this->claim(expenses: 3_000_000, reference: 'dependant-1'),
            $this->claim(expenses: 1_500_000, reference: 'dependant-2'),
        ]);

        self::assertTrue($result->performed);
        self::assertSame(3_990_000, $result->trace['credits']['preschool']['amount_minor_units']);
    }

    /**
     * § 35d odst. 7 a § 35c odst. 2 a 3: sleva za umístění dítěte je sleva podle
     * § 35ba, takže snižuje daň PŘED daňovým zvýhodněním. Základ 400 000 Kč,
     * daň 60 000 Kč; slevy 30 840 + 24 900 = 55 740 → zbývá 4 260 Kč. Na dítě
     * (15 204 Kč) se uplatní sleva 4 260 Kč a bonus 10 944 Kč. Kdyby šla až po
     * zvýhodnění, bonus by byl nula.
     */
    public function testCreditIsAppliedBeforeChildTaxBenefit(): void
    {
        $with = $this->settle(40_000_000, [$this->claim(expenses: 3_000_000)], withChild: true);
        $without = $this->settle(40_000_000, [], withChild: true);

        self::assertSame(6_000_000, $with->taxBeforeCreditsMinorUnits);
        self::assertSame(5_574_000, $with->appliedCreditsMinorUnits);
        self::assertSame(426_000, $with->childCreditMinorUnits);
        self::assertSame(1_094_400, $with->annualTaxBonusMinorUnits);
        self::assertSame(0, $with->taxAfterAllCreditsMinorUnits);

        self::assertSame(1_520_400, $without->childCreditMinorUnits);
        self::assertSame(0, $without->annualTaxBonusMinorUnits);
    }

    /** Sleva nikdy nevytvoří přeplatek nad daň: daň 30 000 Kč, slevy 55 740 Kč. */
    public function testCreditIsLimitedToTax(): void
    {
        $result = $this->settle(20_000_000, [$this->claim(expenses: 3_000_000)]);

        self::assertSame(3_000_000, $result->taxBeforeCreditsMinorUnits);
        self::assertSame(5_574_000, $result->annualCreditsMinorUnits);
        self::assertSame(3_000_000, $result->appliedCreditsMinorUnits);
        self::assertSame(0, $result->taxAfterAllCreditsMinorUnits);
    }

    /** § 31 bod 1 zák. č. 180/2026 Sb.: za 2026 platí dosavadní znění, kde sleva není. */
    public function testClaimForYear2026IsRefused(): void
    {
        $input = new AnnualSettlementInput(
            2026, 12, 60_000_000, 0, 0, 0, 0, 60_000_000, 0, 0,
            [new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12)], [], [],
            preschoolClaims: [$this->claim(expenses: 1_000_000)],
        );

        $result = (new AnnualTaxSettlementCalculator())->calculate($input, self::rates2026());

        self::assertFalse($result->performed);
        self::assertContains('preschool_credit_conditions_not_met', $result->blockerCodes());
        self::assertSame(
            ['statute_not_effective', 'minimum_wage_unavailable'],
            $result->trace['preschool_credit_failures']['dependant-1'],
        );
    }

    /** Regrese 2026: bez školkovného bajtově stejný výsledek i stopa sazeb. */
    public function testYear2026WithoutPreschoolIsUnchanged(): void
    {
        $legacy = new AnnualSettlementInput(
            2026, 12, 60_000_000, 5_000_000, 3_084_000, 0, 0, 60_000_000, 0, 0,
            [new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12)], [], [],
        );
        $explicit = new AnnualSettlementInput(
            2026, 12, 60_000_000, 5_000_000, 3_084_000, 0, 0, 60_000_000, 0, 0,
            [new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12)], [], [],
            preschoolClaims: [],
        );
        $calculator = new AnnualTaxSettlementCalculator();
        $a = $calculator->calculate($legacy, self::rates2026());
        $b = $calculator->calculate($explicit, self::rates2026());

        self::assertSame(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('preschool', $a->trace['credits']);
        self::assertArrayNotHasKey('minimum_wage_minor_units', $a->trace['rates']);
        self::assertNull(self::rates2026()->minimumWageMinorUnits);
    }

    public function testMissingMinimumWageInRulesetRefusesSettlement(): void
    {
        $rates = AnnualTaxRates::forRuleset(Payroll2027IncomeTaxRulesetFixture::incomeTax([
            'minimum_wage.monthly' => null,
        ]));

        $result = $this->settle(60_000_000, [$this->claim(expenses: 1_000_000)], rates: $rates);

        self::assertFalse($result->performed);
        self::assertSame(
            ['minimum_wage_unavailable'],
            $result->trace['preschool_credit_failures']['dependant-1'],
        );
    }

    /** Minimální mzda v rulesetu musí sedět s prahem bonusu (§ 35c odst. 4 — šestinásobek). */
    public function testMinimumWageDivergingFromBonusThresholdStopsTheSettlement(): void
    {
        $this->expectException(AnnualSettlementUnavailableException::class);
        $this->expectExceptionMessage('šestinásobek');

        AnnualTaxRates::forRuleset(Payroll2027IncomeTaxRulesetFixture::incomeTax([
            'minimum_wage.monthly' => PayrollRuleValue::moneyMinor(2_500_000),
        ]));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('failingConditions')]
    public function testConditionsRefuseSettlement(AnnualPreschoolCreditClaim $claim, array $expected): void
    {
        $result = $this->settle(60_000_000, [$claim]);

        self::assertFalse($result->performed);
        self::assertContains('preschool_credit_conditions_not_met', $result->blockerCodes());
        self::assertSame($expected, $result->trace['preschool_credit_failures'][$claim->childReference]);
    }

    /** @return iterable<string,array{AnnualPreschoolCreditClaim,list<string>}> */
    public static function failingConditions(): iterable
    {
        yield 'vnuk bez péče nahrazující péči rodičů (§ 35bd odst. 5)' => [
            self::makeClaim(relation: 'grandchild'),
            ['child_relation'],
        ];
        yield 'manžel místo dítěte' => [self::makeClaim(relation: 'spouse'), ['child_relation']];
        yield 'dítě s poplatníkem v roce nežilo (§ 35bd odst. 4 písm. a)' => [
            self::makeClaim(existenceFrom: '2028-01-01'),
            ['child_not_in_household'],
        ];
        yield 'dítě odešlo před rokem' => [
            self::makeClaim(existenceTo: '2026-12-31'),
            ['child_not_in_household'],
        ];
        yield 'jiný poplatník v domácnosti (§ 35bd odst. 3)' => [
            self::makeClaim(otherTaxpayer: true),
            ['claimed_by_other_taxpayer'],
        ];
        yield 'výdaje uplatněné jako výdaj (§ 35bd odst. 4 písm. b)' => [
            self::makeClaim(businessExpense: true),
            ['claimed_as_business_expense'],
        ];
        yield 'dohoda bez sdílené péče' => [
            self::makeClaim(agreedCap: 1_000_000),
            ['agreement_without_shared_care'],
        ];
        yield 'dohoda nad minimální mzdu' => [
            self::makeClaim(households: 2, agreedCap: 2_490_100),
            ['agreed_cap_over_minimum_wage'],
        ];
    }

    public function testGrandchildInCareReplacingParentsIsAccepted(): void
    {
        $result = $this->settle(60_000_000, [self::makeClaim(relation: 'grandchild', grandchildCare: true)]);

        self::assertTrue($result->performed, implode(',', $result->blockerCodes()));
    }

    /** Dítě, které žilo s poplatníkem jen část roku, nárok zakládá (§ 35bd odst. 4 písm. a). */
    public function testChildLivingWithTaxpayerPartOfTheYearIsAccepted(): void
    {
        $result = $this->settle(60_000_000, [self::makeClaim(existenceFrom: '2027-12-31')]);

        self::assertTrue($result->performed);
    }

    /** § 38l odst. 2 písm. g): bez potvrzení zařízení slevu plátce neuzná. */
    public function testUnverifiedClaimBlocksSettlement(): void
    {
        $result = $this->settle(60_000_000, [self::makeClaim(status: TaxEvidenceStatus::Unverified)]);

        self::assertFalse($result->performed);
        self::assertContains('annual_claim_evidence_unverified', $result->blockerCodes());
    }

    public function testSameChildTwiceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AnnualSettlementInput(
            self::YEAR, 12, 1, 0, 0, 0, 0, 1, 0, 0, [], [], [],
            preschoolClaims: [$this->claim(expenses: 100), $this->claim(expenses: 200)],
        );
    }

    public function testZeroExpensesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::makeClaim(expenses: 0);
    }

    public function testTraceCarriesClaimsAndMinimumWage(): void
    {
        $result = $this->settle(60_000_000, [$this->claim(expenses: 1_234_500)]);

        self::assertSame('kindergarten', $result->trace['annual_claims']['preschool'][0]['facility_kind']);
        self::assertSame(2_490_000, $result->trace['credits']['preschool']['minimum_wage_minor_units']);
        self::assertSame(2_490_000, $result->trace['rates']['minimum_wage_minor_units']);
    }

    /**
     * § 38l odst. 2 písm. g) bod 2: vyživuje-li totéž dítě další poplatník,
     * plátce slevu bez jeho potvrzení (jméno, dítě, výše uplatněné slevy)
     * nepřizná. Střídavá péče 24 900 / 2 = 12 450 Kč bez potvrzení neprojde,
     * s potvrzením o slevě 12 450 Kč ano.
     */
    public function testSharedCareWithoutOtherTaxpayerConfirmationBlocks(): void
    {
        $missing = self::makeClaim(expenses: 3_000_000, households: 2, documents: false);
        $result = $this->settle(60_000_000, [$missing]);

        self::assertFalse($result->performed);
        self::assertContains('preschool_credit_conditions_not_met', $result->blockerCodes());
        self::assertSame(
            ['other_taxpayer_confirmation_missing'],
            $result->trace['preschool_credit_failures']['dependant-1'],
        );

        $confirmed = self::makeClaim(expenses: 3_000_000, households: 2, otherClaimed: 1_245_000);
        $ok = $this->settle(60_000_000, [$confirmed]);
        self::assertTrue($ok->performed, implode(',', $ok->blockerCodes()));
        self::assertSame(1_245_000, $ok->trace['credits']['preschool']['amount_minor_units']);
    }

    /** Další vyživující poplatník v téže domácnosti (manžel) potvrzuje i nulovou slevu. */
    public function testOtherSupportingTaxpayerInTheSameHouseholdNeedsConfirmation(): void
    {
        $missing = self::makeClaim(otherSupporting: true, documents: false);
        self::assertSame(
            ['other_taxpayer_confirmation_missing'],
            $missing->conditionFailures(self::YEAR, 2_490_000),
        );
        self::assertSame([], self::makeClaim(otherSupporting: true)->conditionFailures(self::YEAR, 2_490_000));
    }

    /** § 38l odst. 2 písm. g) bod 3: dohodnutý strop jen s doloženou písemnou dohodou. */
    public function testAgreedCapWithoutAgreementDocumentBlocks(): void
    {
        $claim = new AnnualPreschoolCreditClaim(
            'dependant-1', 'child_own', '2023-03-01', '2023-03-01', null,
            AnnualPreschoolFacilityKind::Kindergarten, 'Mateřská škola',
            3_000_000, 2, 2_000_000, false, false, false,
            TaxEvidenceStatus::Verified, 'Potvrzení MŠ 2027',
            false, 'Druhý Rodič', 490_000, 'Potvrzení druhého rodiče 2027', null,
        );

        self::assertSame(['agreement_document_missing'], $claim->conditionFailures(self::YEAR, 2_490_000));
    }

    /**
     * Úhrn vlastní slevy a slevy potvrzené jiným poplatníkem nesmí překročit
     * minimální mzdu: 12 450 + 12 451 > 24 900 je překážka.
     */
    public function testCombinedCreditOverMinimumWageBlocks(): void
    {
        $over = self::makeClaim(expenses: 3_000_000, households: 2, otherClaimed: 1_245_100);
        self::assertSame(['combined_credit_over_minimum_wage'], $over->conditionFailures(self::YEAR, 2_490_000));

        $exact = self::makeClaim(expenses: 3_000_000, households: 2, otherClaimed: 1_245_000);
        self::assertSame([], $exact->conditionFailures(self::YEAR, 2_490_000));
    }

    // ---- pomocné ----

    /** @param list<AnnualPreschoolCreditClaim> $claims */
    private function settle(
        int $base,
        array $claims,
        bool $withChild = false,
        ?AnnualTaxRates $rates = null,
    ): AnnualSettlementResult {
        $input = new AnnualSettlementInput(
            self::YEAR, 12, $base, 0, 0, 0, 0, $base, 0, 0,
            [new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12)],
            $withChild ? [new AnnualSettlementChildMonths('child-1', 1, 12, 0)] : [],
            [],
            preschoolClaims: $claims,
        );

        return (new AnnualTaxSettlementCalculator())->calculate(
            $input,
            $rates ?? AnnualTaxRates::forRuleset(Payroll2027IncomeTaxRulesetFixture::incomeTax()),
        );
    }

    private function claim(
        int $expenses,
        int $households = 1,
        ?int $agreedCap = null,
        string $reference = 'dependant-1',
    ): AnnualPreschoolCreditClaim {
        return self::makeClaim(
            expenses: $expenses,
            households: $households,
            agreedCap: $agreedCap,
            reference: $reference,
        );
    }

    private static function makeClaim(
        int $expenses = 1_000_000,
        int $households = 1,
        ?int $agreedCap = null,
        string $reference = 'dependant-1',
        string $relation = 'child_own',
        string $existenceFrom = '2023-03-01',
        ?string $existenceTo = null,
        bool $otherTaxpayer = false,
        bool $businessExpense = false,
        bool $grandchildCare = false,
        TaxEvidenceStatus $status = TaxEvidenceStatus::Verified,
        bool $documents = true,
        bool $otherSupporting = false,
        ?int $otherClaimed = 0,
    ): AnnualPreschoolCreditClaim {
        // Doklady § 38l odst. 2 písm. g) body 2 a 3 dodá pomocník tam, kde je
        // zákon vyžaduje; testy dokladů je vypínají přes $documents.
        $confirmation = $documents && ($households > 1 || $otherSupporting);

        return new AnnualPreschoolCreditClaim(
            $reference,
            $relation,
            '2023-03-01',
            $existenceFrom,
            $existenceTo,
            AnnualPreschoolFacilityKind::Kindergarten,
            'Mateřská škola',
            $expenses,
            $households,
            $agreedCap,
            $otherTaxpayer,
            $businessExpense,
            $grandchildCare,
            $status,
            $status === TaxEvidenceStatus::Verified ? 'Potvrzení MŠ 2027' : null,
            $otherSupporting,
            $confirmation ? 'Druhý Rodič' : null,
            $confirmation ? $otherClaimed : null,
            $confirmation ? 'Potvrzení druhého rodiče 2027' : null,
            $documents && $agreedCap !== null ? 'Dohoda rodičů 2027' : null,
        );
    }

    private static function rates2026(): AnnualTaxRates
    {
        return AnnualTaxRates::forRuleset(
            CzechPayrollRulesets2026::provider()
                ->forDate(PayrollRulesetDomain::IncomeTax, '2026-12-01'),
        );
    }
}
