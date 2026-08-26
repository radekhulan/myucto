<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementBlocker;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementChildMonths;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementCreditMonths;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementInput;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementOutcome;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementStatute;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxRates;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxSettlementCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\ExternalEmployerTaxCertificate;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Roční zúčtování — § 38ch ZDP.
 *
 * Klíčový test je `testOverpaymentMatchesSumOfMonthlyAdvancesToTheHaler`: nepočítá
 * očekávanou částku ručně z literálu, ale prožene stejná měsíční data SKUTEČNÝM
 * měsíčním kalkulátorem a porovná. Kdyby se roční a měsíční větev rozešly
 * v zaokrouhlení, sazbě nebo pásmu, sedne to na haléř jen náhodou.
 */
final class AnnualTaxSettlementCalculatorTest extends TestCase
{
    private const YEAR = 2026;

    /**
     * Zaměstnanec s jedním zaměstnavatelem a podepsaným prohlášením.
     *
     * Přeplatek vzniká z toho, že měsíční základ se podle § 38h odst. 1
     * zaokrouhluje na celé stokoruny NAHORU, kdežto roční základ podle
     * § 16 odst. 2 na celá sta DOLŮ. Rozdíl je reálný a musí sedět na haléř.
     */
    public function testOverpaymentMatchesSumOfMonthlyAdvancesToTheHaler(): void
    {
        $rates = $this->rates();
        $monthly = new MonthlyAdvanceTaxCalculator($this->rulesets());
        $taxpayerCredit = intdiv(
            $rates->annualCreditMinorUnits[TaxCreditKind::Taxpayer->value],
            AnnualTaxRates::MONTHS_IN_YEAR,
        );

        // Záměrně nekulaté měsíční mzdy — na kulatých by se zaokrouhlovací
        // rozdíl neprojevil a test by neověřoval nic.
        $grossByMonth = [
            4_213_700, 4_213_700, 4_213_700, 4_589_150, 4_213_700, 4_213_700,
            4_213_700, 4_213_700, 4_777_733, 4_213_700, 4_213_700, 4_213_700,
        ];

        $advanceBase = 0;
        $advanceTax = 0;
        $appliedCredits = 0;
        foreach ($grossByMonth as $gross) {
            $result = $monthly->calculate(
                sprintf('%04d-06-01', self::YEAR),
                new MonthlyAdvanceTaxInput(
                    taxableIncomeMinorUnits: $gross,
                    signedDeclaration: true,
                    claimTaxpayerCredit: true,
                    otherNonRefundableCreditsMinorUnits: 0,
                    childCreditMinorUnits: 0,
                ),
            );
            $advanceBase += $gross;
            $advanceTax += $result->taxAfterCreditsMinorUnits;
            $appliedCredits += min(
                $result->taxBeforeCreditsMinorUnits,
                $taxpayerCredit,
            );
        }

        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: $advanceBase,
                advanceTax: $advanceTax,
                appliedCredits: $appliedCredits,
                bonusQualifyingIncome: $advanceBase,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
            ),
            $rates,
        );

        self::assertTrue($result->performed);
        self::assertSame([], $result->blockerCodes());

        // Roční daň spočítaná nezávisle na kalkulátoru: § 16 odst. 2 zaokrouhlí
        // základ na celá sta dolů, § 16 odst. 1 aplikuje 15 % (celý základ je
        // pod 36násobkem průměrné mzdy), daň se zaokrouhlí na koruny nahoru.
        $expectedBase = intdiv($advanceBase, 10_000) * 10_000;
        self::assertSame($expectedBase, $result->roundedTaxBaseMinorUnits);
        $expectedTax = (int) (ceil($expectedBase * 15 / 100 / 100) * 100);
        self::assertSame($expectedTax, $result->taxBeforeCreditsMinorUnits);

        $annualCredit = $rates->annualCreditMinorUnits[TaxCreditKind::Taxpayer->value];
        self::assertSame($annualCredit, $result->annualCreditsMinorUnits);
        self::assertSame(
            $expectedTax - $annualCredit,
            $result->taxAfterAllCreditsMinorUnits,
        );

        // Tohle je ta věta ze zadání: přeplatek = úhrn měsíčních záloh minus
        // roční daň po slevách, na haléř.
        self::assertSame(
            $advanceTax - $result->taxAfterAllCreditsMinorUnits,
            $result->taxDifferenceMinorUnits,
        );
        self::assertSame(0, $result->bonusDifferenceMinorUnits);
        self::assertSame(
            $result->taxDifferenceMinorUnits,
            $result->settlementDifferenceMinorUnits,
        );
        self::assertGreaterThan(
            AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
            $result->settlementDifferenceMinorUnits,
        );
        self::assertSame(AnnualSettlementOutcome::Overpayment, $result->outcome);
        self::assertSame(
            $result->settlementDifferenceMinorUnits,
            $result->payableMinorUnits,
        );
    }

    /** Roční slevy a odpočty se uplatní právě jednou, ne dvanáctkrát. */
    public function testAnnualCreditsAreAppliedOnceNotPerMonth(): void
    {
        $rates = $this->rates();
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 60_000_000,
                advanceTax: 5_000_000,
                appliedCredits: 3_084_000,
                bonusQualifyingIncome: 60_000_000,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
            ),
            $rates,
        );

        self::assertTrue($result->performed);
        self::assertSame(
            $rates->annualCreditMinorUnits[TaxCreditKind::Taxpayer->value],
            $result->annualCreditsMinorUnits,
        );
        // 12× měsíční částka je právě roční částka — ne 12× roční.
        self::assertSame(
            intdiv(
                $rates->annualCreditMinorUnits[TaxCreditKind::Taxpayer->value],
                AnnualTaxRates::MONTHS_IN_YEAR,
            ) * 12,
            $result->annualCreditsMinorUnits,
        );
    }

    /**
     * § 35ba odst. 3 dvanáctinovou úpravu vztahuje jen na písm. b) až e).
     * Základní sleva na poplatníka náleží celá i tomu, kdo pracoval tři měsíce.
     */
    public function testTaxpayerCreditIsNotProratedButDisabilityCreditIs(): void
    {
        $rates = $this->rates();
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 30_000_000,
                advanceTax: 1_000_000,
                appliedCredits: 771_000,
                bonusQualifyingIncome: 30_000_000,
                completedMonths: 3,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 3),
                    new AnnualSettlementCreditMonths(TaxCreditKind::DisabilityBasic, 3),
                ],
            ),
            $rates,
        );

        $taxpayer = $rates->annualCreditMinorUnits[TaxCreditKind::Taxpayer->value];
        $disabilityMonthly = intdiv(
            $rates->annualCreditMinorUnits[TaxCreditKind::DisabilityBasic->value],
            AnnualTaxRates::MONTHS_IN_YEAR,
        );

        self::assertTrue($result->performed);
        self::assertSame(
            $taxpayer + $disabilityMonthly * 3,
            $result->annualCreditsMinorUnits,
        );
        self::assertSame(
            $taxpayer,
            $result->trace['credits'][TaxCreditKind::Taxpayer->value]['amount_minor_units'],
        );
        self::assertFalse(
            $result->trace['credits'][TaxCreditKind::Taxpayer->value]['prorated'],
        );
        self::assertTrue(
            $result->trace['credits'][TaxCreditKind::DisabilityBasic->value]['prorated'],
        );
    }

    /** § 35ba odst. 1: slevy nesmí vytvořit přeplatek samy o sobě. */
    public function testCreditsNeverExceedTheTax(): void
    {
        $rates = $this->rates();
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 5_000_000,
                advanceTax: 0,
                appliedCredits: 0,
                bonusQualifyingIncome: 5_000_000,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
            ),
            $rates,
        );

        self::assertTrue($result->performed);
        self::assertSame(
            $result->taxBeforeCreditsMinorUnits,
            $result->appliedCreditsMinorUnits,
        );
        self::assertSame(0, $result->taxAfterAllCreditsMinorUnits);
        self::assertSame(0, $result->settlementDifferenceMinorUnits);
        self::assertSame(AnnualSettlementOutcome::NoDifference, $result->outcome);
    }

    /** § 38ch odst. 5 věta poslední: nedoplatek se poplatníkovi NESRÁŽÍ. */
    public function testUnderpaymentIsNeverWithheld(): void
    {
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 60_000_000,
                advanceTax: 100_000,
                appliedCredits: 0,
                bonusQualifyingIncome: 60_000_000,
                creditMonths: [],
            ),
            $this->rates(),
        );

        self::assertTrue($result->performed);
        self::assertLessThan(0, $result->settlementDifferenceMinorUnits);
        self::assertSame(0, $result->payableMinorUnits);
        self::assertSame(
            AnnualSettlementOutcome::UnderpaymentNotWithheld,
            $result->outcome,
        );
    }

    /**
     * § 38ch odst. 5: vrací se přeplatek VÍCE než 50 Kč. Přesně 50 Kč se
     * nevyplácí, ale zúčtování proběhlo — to jsou dva různé stavy.
     */
    public function testOverpaymentOfExactlyFiftyCrownsIsNotPaidOut(): void
    {
        $rates = $this->rates();
        $base = 60_000_000;
        $roundedBase = intdiv($base, 10_000) * 10_000;
        $tax = (int) (ceil($roundedBase * 15 / 100 / 100) * 100);
        $annualCredit = $rates->annualCreditMinorUnits[TaxCreditKind::Taxpayer->value];
        $taxAfterCredits = $tax - $annualCredit;

        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: $base,
                advanceTax: $taxAfterCredits
                    + AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
                appliedCredits: $annualCredit,
                bonusQualifyingIncome: $base,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
            ),
            $rates,
        );

        self::assertSame(
            AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
            $result->settlementDifferenceMinorUnits,
        );
        self::assertSame(0, $result->payableMinorUnits);
        self::assertSame(
            AnnualSettlementOutcome::OverpaymentBelowThreshold,
            $result->outcome,
        );
        self::assertTrue($result->performed);
    }

    /**
     * § 35c odst. 10 a odst. 7: zvýhodnění po měsících, u dítěte s průkazem
     * ZTP/P dvojnásobek jen za měsíce, kdy průkaz měl.
     */
    public function testChildBenefitIsProratedAndDoubledForZtpPMonths(): void
    {
        $rates = $this->rates();
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 60_000_000,
                advanceTax: 500_000,
                appliedCredits: 3_084_000,
                bonusQualifyingIncome: 60_000_000,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
                childMonths: [
                    new AnnualSettlementChildMonths('child-a', 1, 12, 6),
                ],
            ),
            $rates,
        );

        $monthly = intdiv(
            $rates->annualChildCreditMinorUnits[1],
            AnnualTaxRates::MONTHS_IN_YEAR,
        );
        self::assertSame(
            $monthly * 6 + $monthly * 6 * 2,
            $result->childEntitlementMinorUnits,
        );
    }

    /**
     * § 35d odst. 6 věta třetí: nedosáhl-li roční příjem šestinásobku minimální
     * mzdy, poplatník už NEZTRÁCÍ nárok na vyplacené měsíční bonusy. Rozdíl na
     * bonusu je proto nula, ne záporná částka.
     */
    public function testMonthlyBonusesAreNotClawedBackBelowTheAnnualThreshold(): void
    {
        $rates = $this->rates();
        $paidBonuses = 400_000;
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 4_000_000,
                advanceTax: 0,
                appliedCredits: 0,
                monthlyTaxBonus: $paidBonuses,
                bonusQualifyingIncome: 4_000_000,
                completedMonths: 3,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 3),
                ],
                childMonths: [
                    new AnnualSettlementChildMonths('child-a', 1, 3, 0),
                ],
            ),
            $rates,
        );

        self::assertLessThan(
            $rates->bonusMinimumIncomeMinorUnits,
            4_000_000,
        );
        self::assertFalse($result->annualBonusThresholdMet);
        self::assertSame(0, $result->annualTaxBonusMinorUnits);
        self::assertSame(0, $result->bonusDifferenceMinorUnits);
        self::assertSame(0, $result->payableMinorUnits);
    }

    /**
     * Dva měsíce nedosáhnou měsíční hranice, ale úhrn roku dosáhne roční.
     * Roční zúčtování proto doplatí právě bonus za oba vynechané měsíce.
     */
    public function testAnnualSettlementCatchesUpMonthsBelowTheMonthlyBonusThreshold(): void
    {
        $monthly = new MonthlyAdvanceTaxCalculator($this->rulesets());
        $monthlyPaid = 0;
        $annualIncome = 0;
        foreach ([1_000_000, 1_000_000, ...array_fill(0, 10, 1_200_000)] as $month => $income) {
            $result = $monthly->calculate(
                sprintf('%04d-%02d-01', self::YEAR, $month + 1),
                new MonthlyAdvanceTaxInput(
                    taxableIncomeMinorUnits: $income,
                    signedDeclaration: true,
                    claimTaxpayerCredit: true,
                    childCreditMinorUnits: 126_700,
                ),
            );
            $annualIncome += $income;
            $monthlyPaid += $result->taxBonusMinorUnits;
        }

        self::assertSame(14_000_000, $annualIncome);
        self::assertSame(1_267_000, $monthlyPaid);

        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: $annualIncome,
                advanceTax: 0,
                appliedCredits: 0,
                bonusQualifyingIncome: $annualIncome,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
                monthlyTaxBonus: $monthlyPaid,
                childMonths: [
                    new AnnualSettlementChildMonths('child-a', 1, 12, 0),
                ],
            ),
            $this->rates(),
        );

        self::assertTrue($result->annualBonusThresholdMet);
        self::assertSame(1_520_400, $result->annualTaxBonusMinorUnits);
        self::assertSame(253_400, $result->bonusDifferenceMinorUnits);
        self::assertSame(253_400, $result->payableMinorUnits);
        self::assertSame(13_440_000, $result->jsonSerialize()['bonus_minimum_income_minor_units']);
        self::assertSame(14_000_000, $result->jsonSerialize()['bonus_qualifying_income_minor_units']);
        self::assertSame(1_267_000, $result->jsonSerialize()['monthly_tax_bonus_minor_units']);
    }

    public function testAnnualBonusIncomeThresholdIsInclusive(): void
    {
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 13_440_000,
                advanceTax: 0,
                appliedCredits: 0,
                bonusQualifyingIncome: 13_440_000,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
                childMonths: [
                    new AnnualSettlementChildMonths('child-a', 1, 12, 0),
                ],
            ),
            $this->rates(),
        );

        self::assertTrue($result->annualBonusThresholdMet);
        self::assertSame(1_520_400, $result->annualTaxBonusMinorUnits);
        self::assertSame(13_440_000, $result->jsonSerialize()['bonus_minimum_income_minor_units']);
    }

    /** Rok bez jediného uzavřeného měsíce se nedopočítává „aspoň částečně". */
    public function testYearWithoutApprovedMonthsIsRefused(): void
    {
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 0,
                advanceTax: 0,
                appliedCredits: 0,
                bonusQualifyingIncome: 0,
                completedMonths: 0,
                creditMonths: [],
            ),
            $this->rates(),
        );

        self::assertFalse($result->performed);
        self::assertContains(
            AnnualSettlementBlocker::NoApprovedMonths->value,
            $result->blockerCodes(),
        );
        self::assertSame(0, $result->settlementDifferenceMinorUnits);
        self::assertNull($result->outcome);
    }

    /**
     * § 38ch odst. 3 chce od předchozího plátce i poskytnuté měsíční slevy
     * a vyplacené bonusy. Nese-li potvrzení jen základ a zálohu, nepočítá se
     * s ním vůbec — ne špatně.
     */
    public function testExternalCertificateStopsTheSettlement(): void
    {
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 60_000_000,
                advanceTax: 5_000_000,
                appliedCredits: 3_084_000,
                bonusQualifyingIncome: 60_000_000,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
                externalCertificates: [
                    new ExternalEmployerTaxCertificate(
                        'synthetic-certificate',
                        10_000_000,
                        1_500_000,
                        TaxEvidenceStatus::Verified,
                        'synthetic-evidence',
                    ),
                ],
            ),
            $this->rates(),
        );

        self::assertFalse($result->performed);
        self::assertContains(
            AnnualSettlementBlocker::ExternalCertificateIncomplete->value,
            $result->blockerCodes(),
        );
    }

    /**
     * Chybí-li BYŤ JEDNA ze čtyř složek § 38ch odst. 3, zúčtování se neprovede.
     * Testuje se každá zvlášť, protože nejnebezpečnější je právě ta jediná
     * chybějící — u úplně prázdného potvrzení si toho všimne kdokoli.
     */
    #[DataProvider('incompleteCertificateFieldProvider')]
    public function testEverySingleMissingStatutoryFieldStopsTheSettlement(
        string $missingField,
    ): void {
        $values = [
            'gross_income' => 20_000_000,
            'advance_base' => 10_000_000,
            'advance_tax' => 1_500_000,
            'credit_35ba' => 257_000,
            'credit_35c' => 0,
            'tax_bonus' => 120_000,
        ];
        $values[$missingField] = null;

        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 60_000_000,
                advanceTax: 5_000_000,
                appliedCredits: 3_084_000,
                bonusQualifyingIncome: 60_000_000,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
                externalCertificates: [
                    new ExternalEmployerTaxCertificate(
                        'synthetic-certificate',
                        $values['advance_base'],
                        $values['advance_tax'],
                        TaxEvidenceStatus::Verified,
                        'synthetic-evidence',
                        $values['gross_income'],
                        $values['credit_35ba'],
                        $values['credit_35c'],
                        $values['tax_bonus'],
                    ),
                ],
            ),
            $this->rates(),
        );

        self::assertFalse($result->performed);
        self::assertContains(
            AnnualSettlementBlocker::ExternalCertificateIncomplete->value,
            $result->blockerCodes(),
        );
    }

    /** @return iterable<string,array{string}> */
    public static function incompleteCertificateFieldProvider(): iterable
    {
        foreach (ExternalEmployerTaxCertificate::REQUIRED_STATUTORY_FIELDS as $code) {
            yield $code => [$code];
        }
    }

    /**
     * Nula na potvrzení NENÍ totéž co chybějící údaj. „Bonus nevyplácel"
     * musí jít doložit, jinak by úplné potvrzení bez bonusů nešlo použít.
     */
    public function testExplicitZeroIsNotAMissingField(): void
    {
        $certificate = new ExternalEmployerTaxCertificate(
            'synthetic-certificate',
            0,
            0,
            TaxEvidenceStatus::Verified,
            'synthetic-evidence',
            0,
            0,
            0,
            0,
        );

        self::assertSame([], $certificate->missingStatutoryFields());
        self::assertTrue($certificate->isComplete());
    }

    public function testVerifiedCertificateDoesNotRequireEvidenceReference(): void
    {
        $certificate = new ExternalEmployerTaxCertificate(
            'synthetic-certificate-without-source',
            0,
            0,
            TaxEvidenceStatus::Verified,
            null,
            0,
            0,
            0,
            0,
        );

        self::assertTrue($certificate->isVerified());
        self::assertNull($certificate->evidenceReference);
    }

    /**
     * § 38ch odst. 4 mluví o úhrnu mezd od všech plátců — nedoložený údaj do
     * něj nevstupuje, i když je vyplněný celý.
     */
    public function testUnverifiedCertificateStopsTheSettlement(): void
    {
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 60_000_000,
                advanceTax: 5_000_000,
                appliedCredits: 3_084_000,
                bonusQualifyingIncome: 60_000_000,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
                externalCertificates: [
                    $this->certificate(),
                ],
            ),
            $this->rates(),
            [],
        );

        self::assertFalse($result->performed);
        self::assertContains(
            AnnualSettlementBlocker::ExternalCertificateUnverified->value,
            $result->blockerCodes(),
        );
    }

    /**
     * Úplné a doložené potvrzení se přičte do úhrnu — základ, zálohy, už
     * vyplacené bonusy i příjem rozhodný pro § 35c odst. 4.
     */
    public function testCompleteCertificateEntersTheTotals(): void
    {
        $calculator = new AnnualTaxSettlementCalculator();
        $rates = $this->rates();
        $creditMonths = [new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12)];

        $withCertificate = $calculator->calculate(
            $this->input(
                advanceBase: 40_000_000,
                advanceTax: 3_000_000,
                appliedCredits: 3_084_000,
                bonusQualifyingIncome: 40_000_000,
                creditMonths: $creditMonths,
                externalCertificates: [
                    $this->certificate(verified: true),
                ],
            ),
            $rates,
        );
        // Táž situace, jen s údaji potvrzení už započtenými do vlastních
        // kumulací. Musí vyjít na korunu totéž — jinak by potvrzení znamenalo
        // jiný výsledek než tentýž příjem u jednoho plátce.
        $merged = $calculator->calculate(
            $this->input(
                advanceBase: 40_000_000 + 10_000_000,
                advanceTax: 3_000_000 + 1_500_000,
                appliedCredits: 3_084_000,
                bonusQualifyingIncome: 40_000_000 + 20_000_000,
                creditMonths: $creditMonths,
            ),
            $rates,
        );

        self::assertTrue($withCertificate->performed);
        self::assertSame(
            $merged->roundedTaxBaseMinorUnits,
            $withCertificate->roundedTaxBaseMinorUnits,
        );
        self::assertSame(
            $merged->taxDifferenceMinorUnits,
            $withCertificate->taxDifferenceMinorUnits,
        );
        self::assertSame(
            $merged->settlementDifferenceMinorUnits,
            $withCertificate->settlementDifferenceMinorUnits,
        );
        self::assertSame(
            10_000_000,
            $withCertificate->trace['external_certificates']['advance_base_minor_units'],
        );
        self::assertSame(
            1,
            $withCertificate->trace['external_certificates']['count'],
        );
    }

    /**
     * Nejdražší chyba: bonusy vyplacené předchozím plátcem musí snížit rozdíl
     * podle § 35d odst. 7. Kdyby se nezapočítaly, poplatník by dostal podruhé
     * to, co už jednou dostal.
     */
    public function testPaidBonusFromCertificateReducesTheBonusDifference(): void
    {
        $calculator = new AnnualTaxSettlementCalculator();
        $rates = $this->rates();
        $shared = [
            'advanceBase' => 20_000_000,
            'advanceTax' => 0,
            'appliedCredits' => 3_084_000,
            'bonusQualifyingIncome' => 20_000_000,
            'creditMonths' => [
                new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
            ],
            'childMonths' => [
                new AnnualSettlementChildMonths('child-1', 1, 12, 0),
            ],
        ];

        $withoutCertificate = $calculator->calculate(
            $this->input(...$shared),
            $rates,
        );
        $withPaidBonus = $calculator->calculate(
            $this->input(
                ...$shared,
                externalCertificates: [
                    new ExternalEmployerTaxCertificate(
                        'synthetic-certificate',
                        0,
                        0,
                        TaxEvidenceStatus::Verified,
                        'synthetic-evidence',
                        0,
                        0,
                        0,
                        500_000,
                    ),
                ],
            ),
            $rates,
        );

        self::assertTrue($withoutCertificate->performed);
        self::assertTrue($withPaidBonus->performed);
        self::assertSame(
            $withoutCertificate->bonusDifferenceMinorUnits - 500_000,
            $withPaidBonus->bonusDifferenceMinorUnits,
        );
    }

    private function certificate(bool $verified = false): ExternalEmployerTaxCertificate
    {
        return new ExternalEmployerTaxCertificate(
            'synthetic-certificate',
            10_000_000,
            1_500_000,
            $verified ? TaxEvidenceStatus::Verified : TaxEvidenceStatus::Unverified,
            'synthetic-evidence',
            20_000_000,
            257_000,
            0,
            0,
        );
    }

    /** Překážka zvenčí se přenese a nic se nedopočítá. */
    public function testExternalBlockerRefusesWithoutComputing(): void
    {
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 60_000_000,
                advanceTax: 5_000_000,
                appliedCredits: 3_084_000,
                bonusQualifyingIncome: 60_000_000,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
            ),
            $this->rates(),
            [AnnualSettlementBlocker::DeclarationNotSigned],
        );

        self::assertFalse($result->performed);
        self::assertSame(
            [AnnualSettlementBlocker::DeclarationNotSigned->value],
            $result->blockerCodes(),
        );
        self::assertSame(0, $result->taxBeforeCreditsMinorUnits);
        self::assertSame(0, $result->payableMinorUnits);
    }

    /**
     * § 16 odst. 1 písm. b): 23 % nad 36násobkem průměrné mzdy. Roční hranice
     * musí být dvanáctinásobkem měsíční — jinak by se roční a měsíční větev
     * rozešly u vysokých příjmů.
     */
    public function testHighRateBandStartsAtTwelveTimesTheMonthlyThreshold(): void
    {
        $rates = $this->rates();
        $monthly = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::IncomeTax, sprintf('%04d-06-01', self::YEAR))
            ->parameter('advance.high_threshold.monthly');

        self::assertSame(
            ((int) $monthly->value) * AnnualTaxRates::MONTHS_IN_YEAR,
            $rates->highRateThresholdMinorUnits,
        );

        $base = $rates->highRateThresholdMinorUnits + 100_000_00;
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: $base,
                advanceTax: 0,
                appliedCredits: 0,
                bonusQualifyingIncome: $base,
                creditMonths: [],
            ),
            $rates,
        );

        // Základ se nejdřív zaokrouhlí podle § 16 odst. 2 dolů na celá sta Kč
        // a teprve pak se dělí do pásem — v opačném pořadí by vyšlo o něco víc.
        $roundedBase = intdiv($base, 10_000) * 10_000;
        self::assertSame($roundedBase, $result->roundedTaxBaseMinorUnits);
        self::assertGreaterThan(
            $rates->highRateThresholdMinorUnits,
            $roundedBase,
        );
        $low = $rates->highRateThresholdMinorUnits * 15;
        $high = ($roundedBase - $rates->highRateThresholdMinorUnits) * 23;
        self::assertSame(
            (int) (ceil(($low + $high) / 100 / 100) * 100),
            $result->taxBeforeCreditsMinorUnits,
        );
    }

    /**
     * @param list<AnnualSettlementCreditMonths> $creditMonths
     * @param list<AnnualSettlementChildMonths> $childMonths
     * @param list<ExternalEmployerTaxCertificate> $externalCertificates
     */
    private function input(
        int $advanceBase,
        int $advanceTax,
        int $appliedCredits,
        int $bonusQualifyingIncome,
        array $creditMonths,
        int $monthlyTaxBonus = 0,
        int $completedMonths = 12,
        array $childMonths = [],
        array $externalCertificates = [],
    ): AnnualSettlementInput {
        return new AnnualSettlementInput(
            self::YEAR,
            $completedMonths,
            $advanceBase,
            $advanceTax,
            $appliedCredits,
            0,
            $monthlyTaxBonus,
            $bonusQualifyingIncome,
            0,
            0,
            $creditMonths,
            $childMonths,
            $externalCertificates,
        );
    }

    private function rates(): AnnualTaxRates
    {
        return AnnualTaxRates::forRuleset(
            CzechPayrollRulesets2026::provider()
                ->forDate(PayrollRulesetDomain::IncomeTax, sprintf('%04d-12-01', self::YEAR)),
        );
    }

    private function rulesets(): PayrollRulesetProvider
    {
        return new PayrollRulesetProvider([
            CzechPayrollRulesets2026::provider()
                ->forDate(PayrollRulesetDomain::IncomeTax, sprintf('%04d-06-01', self::YEAR)),
        ]);
    }
}
