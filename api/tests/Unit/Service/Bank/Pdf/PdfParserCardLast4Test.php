<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Pdf;

use MyInvoice\Service\Bank\Pdf\CreditasStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\KbStatementPdfParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Koncovka karty z PDF výpisů CREDITAS a KB. Text je vymyšlený a odpovídá tvaru,
 * který vrací Smalot\PdfParser (viz sesterské testy parserů).
 */
final class PdfParserCardLast4Test extends TestCase
{
    public function testCreditasCardPaymentCarriesLast4(): void
    {
        $text = "8.6.2026\n5.6.2026\nOdchozí úhrada\n1000000002\n123456******7890 Testshop a.s.\nPrague CZE\n-500,00\n\n";

        $rows = (new CreditasStatementPdfParser(new NullLogger()))->parseTransactionsFromText($text);

        self::assertCount(1, $rows);
        self::assertSame('7890', $rows[0]['card_last4'] ?? null);
        self::assertSame('Testshop a.s.', $rows[0]['counterparty_name']);
    }

    public function testCreditasTransferHasNoCardKey(): void
    {
        $text = "1.6.2026 Příchozí úhrada\n1000000001\n1111111111/2010\nTESTFIRMA s.r.o.\nVS:2600001\n10 000,00\n\n";

        $rows = (new CreditasStatementPdfParser(new NullLogger()))->parseTransactionsFromText($text);

        self::assertArrayNotHasKey('card_last4', $rows[0]);
    }

    public function testKbCardPaymentCarriesLast4AndMerchant(): void
    {
        $rows = "12.06.2026\n"
              . "12.06.2026\n"
              . "PLATBA KARTOU\n"
              . "120-20260612 KA00000000001\n"
              . "516844******2222 DEBETNÍ\n"
              . "TESTOVACI OBCHOD PRAHA\n"
              . "             -250,00";
        $text = "Datum výpisu: 30.06.2026\nk účtu:12-3456789/0100\nměna:CZK\n"
            . "Počáteční zůstatek   1 000,00\nKonečný zůstatek   750,00\n"
            . "Název protiúčtu / Číslo a typ karty\nProtiúčet a kód banky / Obchodní místo\n"
            . $rows . "\nKONEČNÝ ZŮSTATEK   750,00\n";

        $out = (new KbStatementPdfParser(new NullLogger()))->parseTransactionsFromText($text);

        self::assertCount(1, $out);
        self::assertSame(-250.0, $out[0]['amount']);
        self::assertSame('2222', $out[0]['card_last4'] ?? null);
        self::assertSame('TESTOVACI OBCHOD PRAHA', $out[0]['counterparty_name']);
        self::assertNull($out[0]['counterparty_account']);
    }
}
