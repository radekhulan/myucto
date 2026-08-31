<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Payment;

use MyInvoice\Service\Payroll\Payment\PayrollAccidentInsuranceCalculator;
use PHPUnit\Framework\TestCase;

final class PayrollAccidentInsuranceCalculatorTest extends TestCase
{
    private PayrollAccidentInsuranceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PayrollAccidentInsuranceCalculator();
    }

    public function testAppliesRateAndRoundsUpToWholeCrowns(): void
    {
        // 333 333 Kč × 4,20 ‰ = 1 399,9986 Kč → zaokrouhleno nahoru na 1 400 Kč.
        self::assertSame(
            140_000,
            $this->calculator->premiumMinor(33_333_300, '4.20'),
        );
    }

    public function testExactMultipleIsNotRoundedUpFurther(): void
    {
        // 300 000 Kč × 4,20 ‰ = 1 260 Kč přesně.
        self::assertSame(
            126_000,
            $this->calculator->premiumMinor(30_000_000, '4.20'),
        );
    }

    public function testAppliesMinimumQuarterlyPremiumBelowThreshold(): void
    {
        // 1 000 Kč × 4,20 ‰ = 4,20 Kč, minimum je 100 Kč.
        self::assertSame(
            10_000,
            $this->calculator->premiumMinor(100_000, '4.20'),
        );
    }

    public function testMinimumAppliesExactlyAtTheBoundary(): void
    {
        // 100 000 Kč × 1,00 ‰ = 100 Kč přesně — minimum se uplatní, aniž by
        // ho bylo vidět (hranice, ne nad hranicí).
        self::assertSame(
            10_000,
            $this->calculator->premiumMinor(10_000_000, '1.00'),
        );
    }

    public function testZeroAssessmentBaseStillChargesTheMinimum(): void
    {
        self::assertSame(
            10_000,
            $this->calculator->premiumMinor(0, '4.20'),
        );
    }

    /**
     * Zaokrouhlení nahoru je VĚDOMÉ rozhodnutí, ne citace — vyhláška o něm mlčí
     * a nemají ho ani metodiky obou pojišťoven. Drží se proto, že § 12 odst. 9
     * zvyšuje nedoplatek o 10 % za každý započatý měsíc, kdežto přeplatek do
     * koruny stojí korunu. Tenhle test tu volbu fixuje: kdyby se přepnula na
     * matematické zaokrouhlení nebo dolů, padne.
     */
    public function testRoundsUpEvenOneHellerAboveWholeCrown(): void
    {
        // 250 000,01 Kč × 4,00 ‰ = 1 000,00004 Kč → 1 001 Kč.
        self::assertSame(
            100_100,
            $this->calculator->premiumMinor(25_000_001, '4.00'),
        );
        // Matematické zaokrouhlení by dalo 1 000 Kč, dolů také.
        self::assertNotSame(
            100_000,
            $this->calculator->premiumMinor(25_000_001, '4.00'),
        );
    }

    public function testHandlesTheHighestAnnexRate(): void
    {
        // 1 000 000 Kč × 50,40 ‰ = 50 400 Kč přesně (nejvyšší sazba přílohy).
        self::assertSame(
            5_040_000,
            $this->calculator->premiumMinor(100_000_000, '50.40'),
        );
    }

    public function testHandlesTheResidualAnnexRate(): void
    {
        // Zbytková skupina „Ostatní ekonomické činnosti": 5,6 ‰.
        // 1 234 567 Kč × 5,60 ‰ = 6 913,5752 Kč → 6 914 Kč.
        self::assertSame(
            691_400,
            $this->calculator->premiumMinor(123_456_700, '5.60'),
        );
    }

    public function testRejectsNegativeAssessmentBase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->premiumMinor(-1, '4.20');
    }

    public function testRejectsNonPositiveRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->premiumMinor(1_000_000, '0');
    }
}
