<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\CashBankImporter;
use PHPUnit\Framework\TestCase;

/**
 * Valuta pokladního dokladu v daňové evidenci: převod bere jen hodnoty, které Money uložilo,
 * nic nedopočítává, a uvede je v popisu dokladu korunové pokladny.
 */
final class CashForeignDescriptionTest extends TestCase
{
    public function testStoredForeignReadsOnlyValuesSavedInMoney(): void
    {
        self::assertSame(['currency' => 'EUR', 'amount' => 120.0, 'rate' => 25.0, 'units' => 1],
            CashBankImporter::storedForeign(['Mena' => 'EUR', 'Celkem' => 3000.0, 'ValutyKUhr' => 120.0, 'Kurs' => 25.0, 'PocetJedn' => 1]));
        // Záložní pole částky, kurz za 100 jednotek, vratka se zápornou valutou.
        self::assertSame(['currency' => 'HUF', 'amount' => 10120.0, 'rate' => 6.121, 'units' => 100],
            CashBankImporter::storedForeign(['Mena' => 'HUF', 'ValutyZak0' => -10120.0, 'Kurs' => 6.121, 'PocetJedn' => 100]));
        // Bez valutové částky a kurzu se z korun nic nedopočítá.
        self::assertSame(['currency' => 'EUR', 'amount' => null, 'rate' => null, 'units' => 1],
            CashBankImporter::storedForeign(['Mena' => 'EUR', 'Celkem' => 3000.0]));
        foreach (['', 'Kč', 'CZK'] as $mena) {
            self::assertNull(CashBankImporter::storedForeign(['Mena' => $mena, 'ValutyKUhr' => 10.0]), $mena);
        }
    }

    public function testForeignDescription(): void
    {
        self::assertSame('Ubytování (Money: 120,00 EUR, kurz 25,000 Kč)',
            CashBankImporter::foreignDescription('Ubytování', ['currency' => 'EUR', 'amount' => 120.0, 'rate' => 25.0, 'units' => 1]));
        self::assertSame('Nákup (Money: 10 120,00 HUF, kurz 6,121 Kč/100 HUF)',
            CashBankImporter::foreignDescription('Nákup', ['currency' => 'HUF', 'amount' => 10120.0, 'rate' => 6.121, 'units' => 100]));
        self::assertSame('Výdej (Money: EUR)',
            CashBankImporter::foreignDescription('Výdej', ['currency' => 'EUR', 'amount' => null, 'rate' => null, 'units' => 1]));
        self::assertSame('Výdej (Money: EUR, kurz 25,000 Kč)',
            CashBankImporter::foreignDescription('Výdej', ['currency' => 'EUR', 'amount' => null, 'rate' => 25.0, 'units' => 1]));
    }

    /** Popis má 255 znaků: zkrátí se text z Money, údaj o valutě zůstane celý. */
    public function testLongDescriptionKeepsForeignSuffix(): void
    {
        $out = CashBankImporter::foreignDescription(str_repeat('ž', 300), ['currency' => 'EUR', 'amount' => 120.0, 'rate' => 25.0, 'units' => 1]);
        self::assertSame(255, mb_strlen($out));
        self::assertStringEndsWith(' (Money: 120,00 EUR, kurz 25,000 Kč)', $out);
    }
}
