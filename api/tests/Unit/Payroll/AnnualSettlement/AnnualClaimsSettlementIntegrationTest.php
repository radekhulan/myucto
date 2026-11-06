<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\AnnualSettlement\AnnualNonTaxableClaim;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualNonTaxableKind;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementCreditMonths;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementInput;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementResult;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSpouseCreditClaim;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxRates;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxSettlementCalculator;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use PHPUnit\Framework\TestCase;

/**
 * Integrace ročně uplatňovaných položek do ročního zúčtování. Částky v haléřích.
 *
 * Sazby 2026: 15 % do 1 762 812 Kč základu, sleva na poplatníka 30 840 Kč,
 * sleva na manžela 24 840 Kč (ZTP/P dvojnásobek).
 */
final class AnnualClaimsSettlementIntegrationTest extends TestCase
{
    private const YEAR = 2026;

    /** Bez ročních položek je výsledek stejný, jako by se nové parametry vůbec nepředaly. */
    public function testResultWithoutAnnualClaimsIsIdenticalToLegacyInput(): void
    {
        $legacy = new AnnualSettlementInput(
            self::YEAR, 12, 60_000_000, 5_000_000, 3_084_000, 0, 0, 60_000_000, 0, 0,
            [new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12)], [], [],
        );
        $explicit = new AnnualSettlementInput(
            self::YEAR, 12, 60_000_000, 5_000_000, 3_084_000, 0, 0, 60_000_000, 0, 0,
            [new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12)], [], [],
            nonTaxableClaims: [],
            spouseClaim: null,
        );

        $a = (new AnnualTaxSettlementCalculator())->calculate($legacy, $this->rates());
        $b = (new AnnualTaxSettlementCalculator())->calculate($explicit, $this->rates());

        self::assertSame(
            json_encode($a, JSON_THROW_ON_ERROR),
            json_encode($b, JSON_THROW_ON_ERROR),
        );
        $json = $a->jsonSerialize();
        foreach ([
            'tax_base_before_deductions_minor_units',
            'non_taxable_deductions_minor_units',
            'enforcement_credit_minor_units',
        ] as $key) {
            self::assertArrayNotHasKey($key, $json);
        }
        foreach (['annual_claims', 'non_taxable', 'enforcement_credit'] as $key) {
            self::assertArrayNotHasKey($key, $json['trace']);
        }
        self::assertSame(0, $a->nonTaxableDeductionsMinorUnits);
        self::assertSame(0, $a->enforcementCreditMinorUnits);
    }

    /**
     * Základ 12 345,67 Kč (1 234 567 h), spoření 1 000 Kč (100 000 h).
     * Po odpočtu 1 134 567 h, dolů na celá sta Kč = 1 130 000 h (11 300 Kč).
     * Bez odpočtu by základ byl 1 230 000 h.
     */
    public function testTaxBaseAfterDeductionIsRoundedDownToHundreds(): void
    {
        $with = $this->settle(1_234_567, [$this->claim(AnnualNonTaxableKind::PensionSavings, 100_000)]);
        $without = $this->settle(1_234_567, []);

        self::assertSame(1_130_000, $with->roundedTaxBaseMinorUnits);
        self::assertSame(1_234_567, $with->taxBaseBeforeDeductionsMinorUnits);
        self::assertSame(100_000, $with->nonTaxableDeductionsMinorUnits);
        self::assertSame(1_230_000, $without->roundedTaxBaseMinorUnits);
        // 15 % z 11 300 Kč = 1 695 Kč
        self::assertSame(169_500, $with->taxBeforeCreditsMinorUnits);
    }

    /** Odpočty větší než základ: základ 0, daň 0. Základ 5 000 Kč, spoření 40 000 Kč. */
    public function testDeductionsLargerThanBaseGiveZeroBase(): void
    {
        $result = $this->settle(500_000, [$this->claim(AnnualNonTaxableKind::PensionSavings, 4_000_000)]);

        self::assertTrue($result->performed);
        self::assertSame(0, $result->roundedTaxBaseMinorUnits);
        self::assertSame(0, $result->taxBeforeCreditsMinorUnits);
        self::assertSame(500_000, $result->nonTaxableDeductionsMinorUnits);
    }

    /**
     * Základ 200 000 Kč, daň 15 % = 30 000 Kč (3 000 000 h), sleva na poplatníka 30 840 Kč.
     * Exekuce 10 000 Kč jde první: daň 20 000 Kč, sleva na poplatníka se uplatní jen
     * z těchto 20 000 Kč (2 000 000 h). Daň po všem 0.
     */
    public function testEnforcementCreditIsAppliedBeforeTaxpayerCredit(): void
    {
        $result = $this->settle(
            20_000_000,
            [$this->claim(AnnualNonTaxableKind::EnforcementStop, 1_000_000)],
            [new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12)],
        );

        self::assertSame(3_000_000, $result->taxBeforeCreditsMinorUnits);
        self::assertSame(1_000_000, $result->enforcementCreditMinorUnits);
        self::assertSame(3_084_000, $result->annualCreditsMinorUnits);
        self::assertSame(2_000_000, $result->appliedCreditsMinorUnits);
        self::assertSame(0, $result->taxAfterAllCreditsMinorUnits);
        // Exekuce nesnižuje základ.
        self::assertSame(20_000_000, $result->roundedTaxBaseMinorUnits);
        self::assertSame(0, $result->nonTaxableDeductionsMinorUnits);
        self::assertSame(
            ['claimed_minor_units' => 1_000_000, 'applied_minor_units' => 1_000_000],
            $result->trace['enforcement_credit'],
        );
    }

    /** Exekuce 50 000 Kč při dani 30 000 Kč: uplatní se jen 30 000 Kč, na slevy nic nezbude. */
    public function testEnforcementCreditIsLimitedToTax(): void
    {
        $result = $this->settle(
            20_000_000,
            [$this->claim(AnnualNonTaxableKind::EnforcementStop, 5_000_000)],
            [new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12)],
        );

        self::assertSame(3_000_000, $result->enforcementCreditMinorUnits);
        self::assertSame(0, $result->appliedCreditsMinorUnits);
        self::assertSame(0, $result->taxAfterAllCreditsMinorUnits);
        self::assertSame(5_000_000, $result->trace['enforcement_credit']['claimed_minor_units']);
        self::assertSame(3_000_000, $result->trace['enforcement_credit']['applied_minor_units']);
    }

    /** 12 měsíců: 24 840 Kč. */
    public function testSpouseCreditForFullYear(): void
    {
        $result = $this->settleWithSpouse($this->spouse(0xFFF));

        self::assertTrue($result->performed);
        self::assertSame(2_484_000, $result->annualCreditsMinorUnits);
        self::assertSame(2_484_000, $result->trace['credits']['spouse']['amount_minor_units']);
    }

    /** 5 měsíců (leden až květen): 24 840 x 5 / 12 = 10 350 Kč. */
    public function testSpouseCreditForFiveMonths(): void
    {
        $result = $this->settleWithSpouse($this->spouse(0x01F));

        self::assertTrue($result->performed);
        self::assertSame(1_035_000, $result->annualCreditsMinorUnits);
    }

    /** ZTP/P ve 3 z 12 měsíců: 24 840 + 3 x 2 070 = 24 840 + 6 210 = 31 050 Kč. */
    public function testSpouseCreditDoubledForZtpPMonths(): void
    {
        $result = $this->settleWithSpouse($this->spouse(0xFFF, ztpMask: 0x007, ztpP: true));

        self::assertTrue($result->performed);
        self::assertSame(3_105_000, $result->annualCreditsMinorUnits);
        self::assertSame(3, $result->trace['credits']['spouse']['ztp_p_months']);
    }

    /** Dítě dovrší 3 roky 15. 5.: označený červen zastaví zúčtování překážkou. */
    public function testChildNotUnderThreeInMarkedMonthRefusesSettlement(): void
    {
        $result = $this->settleWithSpouse($this->spouse(0x03F, birth: '2023-05-15'));

        self::assertFalse($result->performed);
        self::assertContains('spouse_credit_conditions_not_met', $result->blockerCodes());
        self::assertSame(['child_not_under_three'], $result->trace['spouse_credit_failures']);
    }

    public function testSpouseIncomeOverLimitRefusesSettlement(): void
    {
        $result = $this->settleWithSpouse($this->spouse(0xFFF, income: 6_800_001));

        self::assertFalse($result->performed);
        self::assertContains('spouse_credit_conditions_not_met', $result->blockerCodes());
    }

    public function testSpouseIncomeAtLimitIsAccepted(): void
    {
        $result = $this->settleWithSpouse($this->spouse(0xFFF, income: 6_800_000));

        self::assertTrue($result->performed);
    }

    public function testUnknownSpouseIncomeRefusesSettlement(): void
    {
        $result = $this->settleWithSpouse($this->spouse(0xFFF, income: null));

        self::assertFalse($result->performed);
        self::assertContains('spouse_credit_conditions_not_met', $result->blockerCodes());
    }

    public function testGrandchildRefusesSettlement(): void
    {
        $result = $this->settleWithSpouse($this->spouse(0xFFF, childRelation: 'grandchild'));

        self::assertFalse($result->performed);
        self::assertContains('spouse_credit_conditions_not_met', $result->blockerCodes());
    }

    public function testZtpPMonthOutsideClaimMonthsRefusesSettlement(): void
    {
        $result = $this->settleWithSpouse($this->spouse(0x003, ztpMask: 0x008, ztpP: true));

        self::assertFalse($result->performed);
        self::assertSame(['ztp_p_month_outside_claim'], $result->trace['spouse_credit_failures']);
    }

    public function testUnverifiedClaimBlocksSettlement(): void
    {
        $result = $this->settle(20_000_000, [
            new AnnualNonTaxableClaim('dar', AnnualNonTaxableKind::Donation, 500_000),
        ]);

        self::assertFalse($result->performed);
        self::assertContains('annual_claim_evidence_unverified', $result->blockerCodes());
    }

    public function testUnverifiedSpouseClaimBlocksSettlement(): void
    {
        $result = $this->settleWithSpouse($this->spouse(0xFFF, status: TaxEvidenceStatus::Unverified));

        self::assertFalse($result->performed);
        self::assertContains('annual_claim_evidence_unverified', $result->blockerCodes());
    }

    public function testClaimsInUnsupportedYearAreRefused(): void
    {
        $input = new AnnualSettlementInput(
            2023, 12, 20_000_000, 0, 0, 0, 0, 20_000_000, 0, 0, [], [], [],
            nonTaxableClaims: [$this->claim(AnnualNonTaxableKind::Donation, 500_000)],
        );

        $result = (new AnnualTaxSettlementCalculator())->calculate($input, $this->rates());

        self::assertFalse($result->performed);
        self::assertContains('annual_only_claims_unsupported', $result->blockerCodes());
    }

    public function testInputRejectsForeignObjectInClaims(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AnnualSettlementInput(
            self::YEAR, 12, 1, 0, 0, 0, 0, 1, 0, 0, [], [], [],
            nonTaxableClaims: [new \stdClass()],
        );
    }

    // ---- pomocné ----

    /**
     * @param list<AnnualNonTaxableClaim> $claims
     * @param list<AnnualSettlementCreditMonths> $creditMonths
     */
    private function settle(int $base, array $claims, array $creditMonths = []): AnnualSettlementResult
    {
        $input = new AnnualSettlementInput(
            self::YEAR, 12, $base, 0, 0, 0, 0, $base, 0, 0, $creditMonths, [], [],
            nonTaxableClaims: $claims,
        );

        return (new AnnualTaxSettlementCalculator())->calculate($input, $this->rates());
    }

    private function settleWithSpouse(AnnualSpouseCreditClaim $spouse): AnnualSettlementResult
    {
        $input = new AnnualSettlementInput(
            self::YEAR, 12, 60_000_000, 0, 0, 0, 0, 60_000_000, 0, 0, [], [], [],
            spouseClaim: $spouse,
        );

        return (new AnnualTaxSettlementCalculator())->calculate($input, $this->rates());
    }

    private function claim(AnnualNonTaxableKind $kind, int $amount): AnnualNonTaxableClaim
    {
        return new AnnualNonTaxableClaim('c', $kind, $amount, evidenceStatus: TaxEvidenceStatus::Verified);
    }

    private function spouse(
        int $mask,
        int $ztpMask = 0,
        bool $ztpP = false,
        ?int $income = 0,
        string $birth = '2025-06-01',
        string $childRelation = 'child_own',
        TaxEvidenceStatus $status = TaxEvidenceStatus::Verified,
    ): AnnualSpouseCreditClaim {
        return new AnnualSpouseCreditClaim(
            's', 'spouse', '2020-01-01', null, $ztpP, 'd', $childRelation, $birth,
            $mask, $ztpMask, $income, $status,
        );
    }

    private function rates(): AnnualTaxRates
    {
        return AnnualTaxRates::forRuleset(
            CzechPayrollRulesets2026::provider()
                ->forDate(PayrollRulesetDomain::IncomeTax, sprintf('%04d-12-01', self::YEAR)),
        );
    }
}
