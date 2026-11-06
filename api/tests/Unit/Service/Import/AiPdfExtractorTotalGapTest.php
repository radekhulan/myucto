<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\AiPdfExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Doklad může mít řádek MIMO daňovou rekapitulaci — spropitné, vratnou zálohu,
 * poplatek. Rekapitulace je pro extrakci autorita, takže se z ní sestaví totály
 * a nedaněný řádek za ní propadne; doklad se pak naimportuje kratší a nesedne
 * na platbu.
 *
 * Rozdíl do koruny je zaokrouhlení a patří do `rounding`. Cokoli většího se má
 * ohlásit, ne zahodit — a dohadovat chybějící řádek se nemá vůbec.
 *
 * Čísla vycházejí ze zachyceného dokladu (k úhradě 5 096,57, rekapitulace
 * 5 076,57, spropitné 20,00); nic neidentifikují.
 */
final class AiPdfExtractorTotalGapTest extends TestCase
{
    public function testHaleroveRozdilyJsouZaokrouhleni(): void
    {
        self::assertTrue(AiPdfExtractor::totalGapIsRounding(0.01));
        self::assertTrue(AiPdfExtractor::totalGapIsRounding(-0.43));
        self::assertTrue(AiPdfExtractor::totalGapIsRounding(0.99));
    }

    public function testNulovyRozdilNeniZaokrouhleni(): void
    {
        // Doklad sedí — není co ukládat ani co hlásit.
        self::assertFalse(AiPdfExtractor::totalGapIsRounding(0.0));
    }

    public function testKorunaAVysSeJizNezaokrouhluje(): void
    {
        self::assertFalse(AiPdfExtractor::totalGapIsRounding(1.0));
        self::assertFalse(AiPdfExtractor::totalGapIsRounding(20.0));
        self::assertFalse(AiPdfExtractor::totalGapIsRounding(-20.0));
    }

    public function testVarovaniPojmenujeObeCastkyIRozdil(): void
    {
        $warning = AiPdfExtractor::totalGapWarning(5096.57, 5076.57, 20.0, 'CZK');

        self::assertStringContainsString('5 096,57 CZK', $warning);
        self::assertStringContainsString('5 076,57 CZK', $warning);
        self::assertStringContainsString('20,00 CZK', $warning);
        // Uživatel má vědět, kde hledat, ne jen že něco nesedí.
        self::assertStringContainsString('mimo daňovou rekapitulaci', $warning);
    }

    public function testVarovaniUvadiRozdilVzdyKladne(): void
    {
        // Doklad nižší než řádky (přeplatek v položkách) hlásí tutéž odchylku.
        $warning = AiPdfExtractor::totalGapWarning(5076.57, 5096.57, -20.0, 'EUR');

        self::assertStringContainsString('20,00 EUR', $warning);
        self::assertStringNotContainsString('-20,00', $warning);
    }
}
