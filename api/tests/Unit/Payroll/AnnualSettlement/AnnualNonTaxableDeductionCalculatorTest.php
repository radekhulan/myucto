<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\AnnualSettlement\AnnualNonTaxableClaim;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualNonTaxableDeductionCalculator;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualNonTaxableDeductions;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualNonTaxableKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Limity § 15 ZDP na hranicích. Částky v haléřích (1 Kč = 100).
 */
final class AnnualNonTaxableDeductionCalculatorTest extends TestCase
{
    private const YEAR = 2026;
    private const ALL = 0xFFF;

    // ---- § 15 odst. 1: dary ----

    /** Základ 40 000 Kč: 2 % = 800 Kč (80 000 h), pod 1 000 Kč. */
    public function testDonationExactlyTwoPercentBelowThousandIsNotDeductible(): void
    {
        $result = $this->calc(4_000_000, [$this->donation(80_000)]);

        self::assertFalse($result->donationThresholdMet);
        self::assertSame(0, $result->donationMinorUnits);
        self::assertSame(80_000, $result->donationClaimedMinorUnits);
    }

    /** Totéž o haléř víc: 800,01 Kč přesáhne 2 % (ostrá nerovnost). */
    public function testDonationOneHalerOverTwoPercentIsDeductible(): void
    {
        $result = $this->calc(4_000_000, [$this->donation(80_001)]);

        self::assertTrue($result->donationThresholdMet);
        self::assertSame(80_001, $result->donationMinorUnits);
    }

    /** Základ 50 000 Kč: 2 % = 1 000 Kč; 999,99 Kč nepřesáhne 2 % a není ani 1 000 Kč. */
    public function testDonationNinetyNineNinetyNineIsNotDeductible(): void
    {
        $result = $this->calc(5_000_000, [$this->donation(99_999)]);

        self::assertFalse($result->donationThresholdMet);
        self::assertSame(0, $result->donationMinorUnits);
    }

    /** 1 000,00 Kč splní neostrou hranici, i když 2 % základu (1 000 Kč) nepřesáhne. */
    public function testDonationExactlyThousandCrownsIsDeductibleEvenAtTwoPercent(): void
    {
        $result = $this->calc(5_000_000, [$this->donation(100_000)]);

        self::assertTrue($result->donationThresholdMet);
        self::assertSame(100_000, $result->donationMinorUnits);
    }

    /** Hranice se posuzuje v úhrnu darů: 500 + 500 Kč = 1 000 Kč. */
    public function testDonationsAreSummedBeforeThreshold(): void
    {
        $result = $this->calc(5_000_000, [$this->donation(50_000, 'a'), $this->donation(50_000, 'b')]);

        self::assertTrue($result->donationThresholdMet);
        self::assertSame(100_000, $result->donationMinorUnits);
    }

    /** Základ 40 000 Kč: 15 % = 6 000 Kč (600 000 h); dar 10 000 Kč se zkrátí. */
    public function testDonationIsCappedAtFifteenPercent(): void
    {
        $result = $this->calc(4_000_000, [$this->donation(1_000_000)]);

        self::assertSame(600_000, $result->donationCapMinorUnits);
        self::assertSame(600_000, $result->donationMinorUnits);
    }

    /** Dar přesně na stropu se nekrátí. */
    public function testDonationExactlyAtCapIsNotShortened(): void
    {
        $result = $this->calc(4_000_000, [$this->donation(600_000)]);

        self::assertSame(600_000, $result->donationMinorUnits);
    }

    /**
     * Základ 33 333,33 Kč (3 333 333 h): 15 % = 4 999,9995 Kč, dolů na celé Kč = 4 999 Kč.
     * Dar 6 000 Kč se zkrátí na 499 900 h, ne na 500 000.
     */
    public function testDonationCapIsRoundedDownToWholeCrowns(): void
    {
        $result = $this->calc(3_333_333, [$this->donation(600_000)]);

        self::assertSame(499_900, $result->donationCapMinorUnits);
        self::assertSame(499_900, $result->donationMinorUnits);
    }

    /** Tři odběry krve = 3 x 3 000 = 9 000 Kč. */
    public function testThreeBloodDonationsAreWorthNineThousandCrowns(): void
    {
        $result = $this->calc(100_000_000, [$this->counted(AnnualNonTaxableKind::BloodDonation, 3)]);

        self::assertSame(900_000, $result->donationClaimedMinorUnits);
        self::assertSame(900_000, $result->donationMinorUnits);
    }

    public function testOrganAndStemCellDonationsAreWorthTwentyThousandCrownsEach(): void
    {
        $organ = $this->calc(100_000_000, [$this->counted(AnnualNonTaxableKind::OrganDonation, 1)]);
        $stem = $this->calc(100_000_000, [$this->counted(AnnualNonTaxableKind::StemCellDonation, 2)]);

        self::assertSame(2_000_000, $organ->donationMinorUnits);
        // 2 x 20 000 Kč
        self::assertSame(4_000_000, $stem->donationMinorUnits);
    }

    /** Odběry se počítají do darů: 1 odběr krve (3 000 Kč) + dar 2 000 Kč = 5 000 Kč. */
    public function testCountedDonationsShareTheDonationLimit(): void
    {
        $result = $this->calc(100_000_000, [
            $this->counted(AnnualNonTaxableKind::BloodDonation, 1),
            $this->donation(200_000),
        ]);

        self::assertSame(500_000, $result->donationMinorUnits);
    }

    // ---- § 15 odst. 4: úroky ----

    public function testInterestFullYearIsCappedAtOneHundredFiftyThousand(): void
    {
        $result = $this->calc(1_000_000_000, [$this->interest(20_000_000, self::ALL)]);

        self::assertSame(15_000_000, $result->interestMinorUnits);
        self::assertSame(12, $result->interestMonths);
    }

    public function testInterestBelowLimitIsNotShortened(): void
    {
        $result = $this->calc(1_000_000_000, [$this->interest(9_999_999, self::ALL)]);

        self::assertSame(9_999_999, $result->interestMinorUnits);
    }

    /** 7 měsíců (leden až červenec): 150 000 x 7 / 12 = 87 500 Kč. */
    public function testInterestForSevenMonthsIsProrated(): void
    {
        $result = $this->calc(1_000_000_000, [$this->interest(10_000_000, 0x7F)]);

        self::assertSame(8_750_000, $result->interestCapMinorUnits);
        self::assertSame(8_750_000, $result->interestMinorUnits);
        self::assertSame(7, $result->interestMonths);
    }

    /** Dva úvěry s disjunktními měsíci (1-6 a 7-12): sjednocení je 12, strop 150 000 Kč. */
    public function testTwoLoansWithDisjointMonthsUnionToTwelve(): void
    {
        $result = $this->calc(1_000_000_000, [
            $this->interest(10_000_000, 0x03F, 'a'),
            $this->interest(10_000_000, 0xFC0, 'b'),
        ]);

        self::assertSame(12, $result->interestMonths);
        self::assertSame(15_000_000, $result->interestCapMinorUnits);
        // 100 000 + 100 000 = 200 000 Kč, strop 150 000 Kč
        self::assertSame(15_000_000, $result->interestMinorUnits);
    }

    /** Překrývající se měsíce se nesčítají dvakrát: 1-8 a 5-12 je 12 měsíců, ne 16. */
    public function testOverlappingLoanMonthsAreCountedOnce(): void
    {
        $result = $this->calc(1_000_000_000, [
            $this->interest(20_000_000, 0x0FF, 'a'),
            $this->interest(20_000_000, 0xFF0, 'b'),
        ]);

        self::assertSame(12, $result->interestMonths);
        self::assertSame(15_000_000, $result->interestMinorUnits);
    }

    public function testLegacyHousingFullYearHasThreeHundredThousandLimit(): void
    {
        $result = $this->calc(1_000_000_000, [$this->interest(40_000_000, self::ALL, 'l', true)]);

        self::assertSame(30_000_000, $result->interestCapMinorUnits);
        self::assertSame(30_000_000, $result->interestMinorUnits);
    }

    /** Legacy do 300 000 Kč se nekrátí na 150 000 Kč. */
    public function testLegacyHousingBelowItsLimitIsNotShortenedToNewLimit(): void
    {
        $result = $this->calc(1_000_000_000, [$this->interest(25_000_000, self::ALL, 'l', true)]);

        self::assertSame(25_000_000, $result->interestMinorUnits);
    }

    /**
     * Legacy 200 000 Kč + nový 200 000 Kč, oba 12 měsíců: nový se zkrátí na 150 000 Kč,
     * dohromady 350 000 Kč, strop 300 000 Kč.
     */
    public function testLegacyPlusNewIsCappedAtThreeHundredThousand(): void
    {
        $result = $this->calc(1_000_000_000, [
            $this->interest(20_000_000, self::ALL, 'l', true),
            $this->interest(20_000_000, self::ALL, 'n'),
        ]);

        self::assertSame(30_000_000, $result->interestMinorUnits);
    }

    /** Legacy 100 000 + nový 200 000 Kč: nový na 150 000 Kč, celkem 250 000 Kč (pod 300 000). */
    public function testNewLoanIsShortenedToItsOwnLimitBeforeAddingLegacy(): void
    {
        $result = $this->calc(1_000_000_000, [
            $this->interest(10_000_000, self::ALL, 'l', true),
            $this->interest(20_000_000, self::ALL, 'n'),
        ]);

        self::assertSame(25_000_000, $result->interestMinorUnits);
    }

    /**
     * Legacy leden-červen (6 měsíců) 140 000 Kč, nový červenec-prosinec (6 měsíců) 200 000 Kč:
     * nový max 150 000 x 6 / 12 = 75 000 Kč; dohromady max 300 000 x 12 / 12 = 300 000 Kč.
     * 140 000 + 75 000 = 215 000 Kč.
     */
    public function testLegacyAndNewWithDisjointMonthsUseTheUnionForTheCombinedCap(): void
    {
        $result = $this->calc(1_000_000_000, [
            $this->interest(14_000_000, 0x03F, 'l', true),
            $this->interest(20_000_000, 0xFC0, 'n'),
        ]);

        self::assertSame(21_500_000, $result->interestMinorUnits);
        self::assertSame(30_000_000, $result->interestCapMinorUnits);
    }

    // ---- § 15 odst. 6: spoření ----

    /** 30 000 + 20 000 = 50 000 Kč, společný strop 48 000 Kč. */
    public function testPensionAndLifeInsuranceShareFortyEightThousand(): void
    {
        $result = $this->calc(1_000_000_000, [
            $this->claim(AnnualNonTaxableKind::PensionSavings, 3_000_000, 'p'),
            $this->claim(AnnualNonTaxableKind::LifeInsurance, 2_000_000, 'z'),
        ]);

        self::assertSame(5_000_000, $result->retirementClaimedMinorUnits);
        self::assertSame(4_800_000, $result->retirementMinorUnits);
    }

    /** Dlouhodobá péče i DIP ve stejném limitu: 30 000 + 15 000 + 10 000 = 55 000 Kč. */
    public function testCareAndInvestmentProductsAreInTheSameLimit(): void
    {
        $result = $this->calc(1_000_000_000, [
            $this->claim(AnnualNonTaxableKind::PensionSavings, 3_000_000, 'p'),
            $this->claim(AnnualNonTaxableKind::LongTermCareInsurance, 1_500_000, 'c'),
            $this->claim(AnnualNonTaxableKind::LongTermInvestment, 1_000_000, 'd'),
        ]);

        self::assertSame(4_800_000, $result->retirementMinorUnits);
    }

    public function testRetirementBelowLimitIsNotShortened(): void
    {
        $result = $this->calc(1_000_000_000, [
            $this->claim(AnnualNonTaxableKind::PensionSavings, 3_000_000, 'p'),
            $this->claim(AnnualNonTaxableKind::LongTermCareInsurance, 1_000_000, 'c'),
        ]);

        self::assertSame(4_000_000, $result->retirementMinorUnits);
    }

    // ---- úhrn do výše základu, pořadí ----

    /**
     * Základ 20 000 Kč (2 000 000 h). Dar 3 000 Kč (strop 15 % = 3 000 Kč, vejde se),
     * úroky 12 měsíců 50 000 Kč, spoření 40 000 Kč. Dary 300 000 h, úroky zbytek
     * 1 700 000 h, spoření 0. Úhrn = základ.
     */
    public function testDeductionsAreCappedAtTaxBaseInStatuteOrder(): void
    {
        $result = $this->calc(2_000_000, [
            $this->donation(300_000),
            $this->interest(5_000_000, self::ALL),
            $this->claim(AnnualNonTaxableKind::PensionSavings, 4_000_000, 'p'),
        ]);

        self::assertSame(300_000, $result->donationMinorUnits);
        self::assertSame(1_700_000, $result->interestMinorUnits);
        self::assertSame(0, $result->retirementMinorUnits);
        self::assertSame(2_000_000, $result->totalMinorUnits());
    }

    /** Spoření dostane, co zbylo po darech a úrocích: 20 000 - 3 000 - 5 000 = 12 000 Kč. */
    public function testRetirementGetsTheRemainderAfterDonationsAndInterest(): void
    {
        $result = $this->calc(2_000_000, [
            $this->donation(300_000),
            $this->interest(500_000, self::ALL),
            $this->claim(AnnualNonTaxableKind::PensionSavings, 4_000_000, 'p'),
        ]);

        self::assertSame(300_000, $result->donationMinorUnits);
        self::assertSame(500_000, $result->interestMinorUnits);
        self::assertSame(1_200_000, $result->retirementMinorUnits);
    }

    public function testZeroTaxBaseYieldsNoDeductions(): void
    {
        $result = $this->calc(0, [
            $this->donation(200_000),
            $this->interest(1_000_000, self::ALL),
            $this->claim(AnnualNonTaxableKind::PensionSavings, 1_000_000, 'p'),
        ]);

        self::assertSame(0, $result->totalMinorUnits());
    }

    public function testNoClaimsYieldsNothing(): void
    {
        $result = $this->calc(1_000_000, []);

        self::assertSame(0, $result->totalMinorUnits());
        self::assertSame([], $result->claimedByKind);
    }

    public function testEnforcementStopIsOnlyReportedNotDeducted(): void
    {
        $result = $this->calc(100_000_000, [
            $this->claim(AnnualNonTaxableKind::EnforcementStop, 500_000, 'e'),
        ]);

        self::assertSame(500_000, $result->enforcementCreditClaimedMinorUnits);
        self::assertSame(0, $result->totalMinorUnits());
    }

    public function testNegativeTaxBaseIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc(-1, [$this->donation(100_000)]);
    }

    public function testClaimsBeforeFirstSupportedYearAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AnnualNonTaxableDeductionCalculator())->calculate(2023, 1_000_000, [$this->donation(100_000)]);
    }

    // ---- konstruktor položky ----

    /** @param callable():AnnualNonTaxableClaim $factory */
    #[DataProvider('invalidClaimProvider')]
    public function testInvalidClaimConstructionIsRejected(callable $factory): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $factory();
    }

    /** @return iterable<string,array{callable}> */
    public static function invalidClaimProvider(): iterable
    {
        yield 'záporná částka' => [static fn () => new AnnualNonTaxableClaim('x', AnnualNonTaxableKind::Donation, -1)];
        yield 'nulová částka' => [static fn () => new AnnualNonTaxableClaim('x', AnnualNonTaxableKind::Donation, 0)];
        yield 'částka null' => [static fn () => new AnnualNonTaxableClaim('x', AnnualNonTaxableKind::Donation, null)];
        yield 'částka mimo rozsah' => [static fn () => new AnnualNonTaxableClaim('x', AnnualNonTaxableKind::Donation, 100_000_000_001)];
        yield 'prázdné označení' => [static fn () => new AnnualNonTaxableClaim(' ', AnnualNonTaxableKind::Donation, 100)];
        yield 'odběr bez počtu' => [static fn () => new AnnualNonTaxableClaim('x', AnnualNonTaxableKind::BloodDonation, null)];
        yield 'odběr s nulovým počtem' => [static fn () => new AnnualNonTaxableClaim('x', AnnualNonTaxableKind::BloodDonation, null, 0)];
        yield 'odběr s příliš velkým počtem' => [static fn () => new AnnualNonTaxableClaim('x', AnnualNonTaxableKind::BloodDonation, null, 101)];
        yield 'odběr s vlastní částkou' => [static fn () => new AnnualNonTaxableClaim('x', AnnualNonTaxableKind::BloodDonation, 100, 1)];
        yield 'počet u daru' => [static fn () => new AnnualNonTaxableClaim('x', AnnualNonTaxableKind::Donation, 100, 2)];
        yield 'úroky bez měsíců' => [static fn () => new AnnualNonTaxableClaim('x', AnnualNonTaxableKind::HousingInterest, 100)];
        yield 'úroky s nulovou maskou' => [static fn () => new AnnualNonTaxableClaim('x', AnnualNonTaxableKind::HousingInterest, 100, null, 0)];
        yield 'úroky s maskou mimo rok' => [static fn () => new AnnualNonTaxableClaim('x', AnnualNonTaxableKind::HousingInterest, 100, null, 0x1000)];
        yield 'měsíce u daru' => [static fn () => new AnnualNonTaxableClaim('x', AnnualNonTaxableKind::Donation, 100, null, 1)];
        yield 'legacy příznak u daru' => [static fn () => new AnnualNonTaxableClaim('x', AnnualNonTaxableKind::Donation, 100, null, null, true)];
    }

    // ---- pomocné ----

    /** @param list<AnnualNonTaxableClaim> $claims */
    private function calc(int $base, array $claims): AnnualNonTaxableDeductions
    {
        return (new AnnualNonTaxableDeductionCalculator())->calculate(self::YEAR, $base, $claims);
    }

    private function claim(AnnualNonTaxableKind $kind, int $amount, string $ref = 'c'): AnnualNonTaxableClaim
    {
        return new AnnualNonTaxableClaim($ref, $kind, $amount, evidenceStatus: TaxEvidenceStatus::Verified);
    }

    private function donation(int $amount, string $ref = 'd'): AnnualNonTaxableClaim
    {
        return $this->claim(AnnualNonTaxableKind::Donation, $amount, $ref);
    }

    private function counted(AnnualNonTaxableKind $kind, int $quantity): AnnualNonTaxableClaim
    {
        return new AnnualNonTaxableClaim('o', $kind, null, $quantity, evidenceStatus: TaxEvidenceStatus::Verified);
    }

    private function interest(int $amount, int $months, string $ref = 'u', bool $legacy = false): AnnualNonTaxableClaim
    {
        return new AnnualNonTaxableClaim(
            $ref,
            AnnualNonTaxableKind::HousingInterest,
            $amount,
            null,
            $months,
            $legacy,
            TaxEvidenceStatus::Verified,
        );
    }
}
