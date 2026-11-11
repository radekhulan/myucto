<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Component\PayrollWholeCrowns;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Haléře osvobozeného benefitu: které vstupy je nést smí a jak se úhrny
 * vykážou v měsíčním hlášení v celých korunách.
 */
final class PayrollWholeCrownsReportingTest extends TestCase
{
    private const MEAL = ['jmhz_treatment' => 'included', 'tax_treatment' => 'exempt', 'exemption_basket' => 'meal_per_shift'];
    private const WAGE = ['jmhz_treatment' => 'included', 'tax_treatment' => 'included', 'exemption_basket' => null];

    public function testExemptBasketBenefitMayCarryHalere(): void
    {
        self::assertTrue(PayrollWholeCrowns::mayCarryHalere(self::MEAL));
        self::assertFalse(PayrollWholeCrowns::required(self::MEAL));
        // Koncept bez zmrazeného rozpadu i celý osvobozený schválený vstup.
        self::assertFalse(PayrollWholeCrowns::required(self::MEAL, null));
        self::assertFalse(PayrollWholeCrowns::required(self::MEAL, 0));
    }

    public function testBenefitWithExcessPartMustHaveWholeCrownAmount(): void
    {
        self::assertTrue(PayrollWholeCrowns::required(self::MEAL, 38_950));
    }

    public function testWageExemptCompensationAndSavingsStayInWholeCrowns(): void
    {
        self::assertTrue(PayrollWholeCrowns::required(self::WAGE));
        // Náhrada mzdy při DPN je osvobozená, ale má vlastní kolonku 10342.
        self::assertTrue(PayrollWholeCrowns::required(
            ['jmhz_treatment' => 'included', 'tax_treatment' => 'exempt', 'exemption_basket' => null],
        ));
        // Příspěvek na penzi má vlastní atribut 10417 v celých korunách.
        self::assertTrue(PayrollWholeCrowns::required(
            ['jmhz_treatment' => 'included', 'tax_treatment' => 'exempt', 'exemption_basket' => 'old_age_savings'],
        ));
        // Neposouzená benefitní složka osvobozená není.
        self::assertTrue(PayrollWholeCrowns::required(
            ['jmhz_treatment' => 'included', 'tax_treatment' => 'manual_review', 'exemption_basket' => 'non_cash_leisure'],
        ));
        self::assertFalse(PayrollWholeCrowns::required(
            ['jmhz_treatment' => 'excluded', 'tax_treatment' => 'included', 'exemption_basket' => null],
        ));
    }

    public function testExemptIncomeIsReportedRoundedDown(): void
    {
        self::assertSame(2_460, PayrollWholeCrowns::reportedExemptCzk(246_050));
        self::assertSame(24_483, PayrollWholeCrowns::reportedExemptCzk(2_448_350));
        self::assertSame(2_590, PayrollWholeCrowns::reportedExemptCzk(259_000));
        self::assertSame(0, PayrollWholeCrowns::reportedExemptCzk(0));
        self::assertSame(-1, PayrollWholeCrowns::reportedExemptCzk(-50));
    }

    /**
     * @return iterable<string,array{int,int,?int}>
     */
    public static function totals(): iterable
    {
        yield 'úhrn v celých korunách se opisuje' => [7_000_000, 2_448_350, 70_000];
        yield 'haléře jen z osvobozené části' => [4_046_050, 246_050, 40_460];
        yield 'bez osvobozené části' => [4_300_000, 0, 43_000];
        yield 'haléř ve mzdě je chyba vstupu' => [4_123_456, 0, null];
        yield 'haléř ve mzdě vedle osvobozeného paušálu' => [4_169_506, 246_050, null];
    }

    #[DataProvider('totals')]
    public function testIncomeTotalKeepsTaxableAndExemptPartsConsistent(int $total, int $exempt, ?int $expected): void
    {
        self::assertSame($expected, PayrollWholeCrowns::reportedIncomeTotalCzk($total, $exempt));
    }

    public function testBasesAreReportedRoundedUp(): void
    {
        self::assertSame(45_517, PayrollWholeCrowns::reportedBaseCzk(4_551_650));
        self::assertSame(38_000, PayrollWholeCrowns::reportedBaseCzk(3_800_000));
        // 10286 = základ nahoru + osvobozená část dolů.
        self::assertSame(
            70_000,
            PayrollWholeCrowns::reportedBaseCzk(4_551_650) + PayrollWholeCrowns::reportedExemptCzk(2_448_350),
        );
        self::assertSame(['10297', '10344', '10476', '10535'], PayrollWholeCrowns::REPORTED_ROUNDED_UP);
    }
}
