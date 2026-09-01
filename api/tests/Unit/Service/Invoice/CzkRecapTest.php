<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\CzkRecap;
use PHPUnit\Framework\TestCase;

final class CzkRecapTest extends TestCase
{
    public function testSingleVatGroupBasicConversion(): void
    {
        // 1 000 EUR base + 210 EUR VAT (21 %) × 24,360 = 24 360 + 5 115,60 = 29 475,60 CZK
        $r = CzkRecap::build(
            [['rate' => 21.0, 'base' => 1000.00, 'vat' => 210.00]],
            24.360,
            '2026-05-03',
        );
        self::assertSame(24.360, $r['rate']);
        self::assertSame('2026-05-03', $r['rate_date']);
        self::assertFalse($r['fallback_used']);
        self::assertCount(1, $r['breakdown']);
        self::assertSame(21.0,    $r['breakdown'][0]['rate']);
        self::assertSame(24360.0, $r['breakdown'][0]['base_czk']);
        self::assertSame(5115.60, $r['breakdown'][0]['vat_czk']);
        self::assertSame(29475.60, $r['breakdown'][0]['with_vat_czk']);
        self::assertSame(24360.0, $r['total_without_vat_czk']);
        self::assertSame(5115.60, $r['total_vat_czk']);
        self::assertSame(29475.60, $r['total_with_vat_czk']);
    }

    public function testMultipleVatGroupsRoundedPerGroup(): void
    {
        // Per-group HALF_UP rounding (vs. summing first then rounding)
        // 100 EUR @ 21 % × 24,365 = 2436,50 base + 511,665 VAT → 511,67
        // 50 EUR @ 12 %  × 24,365 = 1218,25 base + 146,19  VAT → 146,19
        $r = CzkRecap::build(
            [
                ['rate' => 21.0, 'base' => 100.00, 'vat' => 21.00],
                ['rate' => 12.0, 'base' => 50.00,  'vat' => 6.00],
            ],
            24.365,
            '2026-05-03',
        );
        self::assertCount(2, $r['breakdown']);
        self::assertSame(2436.50, $r['breakdown'][0]['base_czk']);
        self::assertSame(511.67,  $r['breakdown'][0]['vat_czk']);
        self::assertSame(1218.25, $r['breakdown'][1]['base_czk']);
        self::assertSame(146.19,  $r['breakdown'][1]['vat_czk']);
        self::assertSame(3654.75, $r['total_without_vat_czk']);  // 2436,50 + 1218,25
        self::assertSame(657.86,  $r['total_vat_czk']);          // 511,67 + 146,19
        self::assertSame(4312.61, $r['total_with_vat_czk']);
    }

    public function testFallbackUsedFlagPropagates(): void
    {
        $r = CzkRecap::build(
            [['rate' => 21.0, 'base' => 100.00, 'vat' => 21.00]],
            25.0,
            '2026-05-01',
            true,
        );
        self::assertTrue($r['fallback_used']);
        self::assertSame('2026-05-01', $r['rate_date']);
    }

    public function testHalfUpRounding(): void
    {
        // 1 EUR × 24,005 = 24,005 → HALF_UP → 24,01
        $r = CzkRecap::build(
            [['rate' => 0.0, 'base' => 1.00, 'vat' => 0.00]],
            24.005,
            '2026-05-03',
        );
        self::assertSame(24.01, $r['breakdown'][0]['base_czk']);
    }

    public function testEmptyBreakdownReturnsZeros(): void
    {
        $r = CzkRecap::build([], 24.0, '2026-05-03');
        self::assertSame([], $r['breakdown']);
        self::assertSame(0.0, $r['total_without_vat_czk']);
        self::assertSame(0.0, $r['total_vat_czk']);
        self::assertSame(0.0, $r['total_with_vat_czk']);
    }

    public function testDocumentVatIncludesOnlyNonOssItemsAndGroupsRates(): void
    {
        $r = CzkRecap::buildCzechVatForDocument([
            ['vat_rate_snapshot' => 21.0, 'total_vat' => 20.0, 'oss_applicable' => false],
            ['vat_rate_snapshot' => 21.0, 'total_vat' => 1.0, 'oss_applicable' => false],
            ['vat_rate_snapshot' => 23.0, 'total_vat' => 23.0, 'oss_applicable' => true],
        ], 24.365);

        self::assertSame([['rate' => 21.0, 'vat_czk' => 511.67]], $r);
    }

    public function testDocumentVatKeepsNegativeCzechVatOnCreditNote(): void
    {
        $r = CzkRecap::buildCzechVatForDocument([
            ['vat_rate_snapshot' => 21.0, 'total_vat' => -21.0, 'oss_applicable' => false],
        ], 24.365);

        self::assertSame([['rate' => 21.0, 'vat_czk' => -511.67]], $r);
    }

    /**
     * `multiplyHalfUp()` je SSOT přepočtu měny a musí dát MATEMATICKY správný HALF_UP,
     * i když přesný součin padne na půlhaléřovou hranici.
     *
     * Registr SSOT vedl „CzkRecap (bcmath) vs 3 další" jako neověřené hlášení agenta.
     * Měřením se potvrdilo, že drift je reálný: na 1,6 mil. kombinací částek a reálných
     * kurzů ČNB se metody rozešly 603× — vždy o jeden haléř a vždy v neprospěch
     * `round()`, protože v IEEE754 je 75,915 uloženo jako 75,9149999…
     *
     * @return iterable<string, array{float, float, float}>
     */
    public static function halfCentBoundaries(): iterable
    {
        yield '3,00 × 25,305'  => [3.00, 25.305, 75.92];
        yield '33,00 × 25,305' => [33.00, 25.305, 835.07];
        yield '7,00 × 30,115'  => [7.00, 30.115, 210.81];
        yield '21,00 × 24,365' => [21.00, 24.365, 511.67];
        yield '10,00 × 0,0435' => [10.00, 0.0435, 0.44];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('halfCentBoundaries')]
    public function testMultiplyHalfUpRoundsExactHalfCentUp(float $amount, float $rate, float $expected): void
    {
        self::assertSame(
            $expected,
            CzkRecap::multiplyHalfUp($amount, $rate),
            sprintf('%s × %s musí dát %s — round() dá o haléř míň.', $amount, $rate, $expected),
        );
    }

    /**
     * Pojistka, že vybrané případy skutečně LEŽÍ na hranici. Bez ní by test prošel
     * i s obyčejným `round()` a netvrdil by nic.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('halfCentBoundaries')]
    public function testChosenCasesActuallyDivergeFromPlainRound(float $amount, float $rate, float $expected): void
    {
        self::assertNotSame(
            round($amount * $rate, 2),
            $expected,
            sprintf('%s × %s se od round() neliší — případ nic nedokazuje.', $amount, $rate),
        );
    }
}
