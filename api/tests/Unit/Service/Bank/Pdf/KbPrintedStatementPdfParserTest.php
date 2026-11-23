<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Pdf;

use MyInvoice\Service\Bank\Pdf\KbAccountStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\KbPrintedStatementPdfParser;
use PHPUnit\Framework\TestCase;

/**
 * PDF výpis KB vytištěný z internetového bankovnictví („Vytištěno dne … pro účet").
 * Text je VYMYŠLENÝ se zachovanou strukturou textové vrstvy: každá buňka na vlastním
 * řádku, prázdné buňky jako mezera, hlavička a záhlaví sloupců na každé stránce.
 */
final class KbPrintedStatementPdfParserTest extends TestCase
{
    private const COLUMNS = "Datum splatnosti\nDatum zúčtování\nDatum odepsání v JB\nPopis platby\nNázev protiúčtu\n"
        . "Protiúčet a kód banky\nIdentifikace transakce\nVS\nKS\nSS\nPřipsáno\nOdepsáno\n";

    /** Příchozí platba z jiné banky: tři data, odepsání v JB o den dřív. */
    private const CREDIT = "01.10.2099\n01.10.2099\n30.09.2099\nPříchozí úhrada\nTESTOVACI ODBERATEL\n"
        . "FAKTURA 2099001\nTESTOVACI ODBERATEL\n1000000005/0800\n120-30092099 TEST00000001\n"
        . "2099001\n308\n0\n \n \n 1 000,00\n \n \n";

    /** Odchozí platba bez názvu protiúčtu: nad účtem je jen identifikátor KB. */
    private const DEBIT = "02.10.2099\n02.10.2099\nPlatba na vrub vašeho účtu\nFP2099-TESTOVACI DODAVATEL\n"
        . "YU0000TEST1\n1000000005/0100\n264-02102099 1602TEST1\n7126012917\n0\n0\n \n -200,00\n \n";

    /** Karetní platba v cizí měně: částka v měně karty nesmí přebít zúčtovanou částku. */
    private const CARD = "05.10.2099\n05.10.2099\nOpakovaná platba\n00098400 PLATEBNÍ KARTY\nTESTOVACI OBCHOD\nIE\n"
        . "4917 01** **** 0096 VISA 04.10.2099\n4,00 EUR\nPRIME VISA - PLATEBNÍ KARTY CZ\n"
        . "244-05102099 10800000001\n9840069\n1178\n102312001\n \n -100,50\n \n";

    private static function page(int $page, int $pages, string $rows): string
    {
        return "Komerční banka, a. s.\nKOMBCZPPXXX\nVÝPIS\nPERIODICKÝ\nk účtu: 1000000005\n"
            . "IBAN: CZ00 0100 0000 0010 0000 0005\ntyp: BĚŽNÝ ÚČET\nměna: CZK\nčíslo výpisu:\t10\n"
            . "strana:\t{$page}/{$pages}\nzpůsob zaslání: elektronicky\nza období:\tříjna 2099\n"
            . ($page === 1
                ? "Předchozí výpis ze dne:\n30.09.2099\nPočáteční zůstatek:\t500,00\nKonečný zůstatek:\t1 199,50\n"
                    . "Připsáno:\t1 000,00\nOdepsáno\t300,50\nPočet transakcí:\t3\nTESTOVACI FIRMA S.R.O.\nTESTOVACI 1\n"
                : '')
            . self::COLUMNS
            . $rows
            . "Vytištěno dne 09/11/2099 pro účet 1000000005\tStrana {$page} \n";
    }

    private static function statement(string $page1Rows = self::CREDIT . self::DEBIT, string $page2Rows = self::CARD): string
    {
        return self::page(1, 2, $page1Rows) . self::page(2, 2, $page2Rows);
    }

    public function testParsesHeaderAndTransactionsAcrossPages(): void
    {
        $parser = new KbPrintedStatementPdfParser();
        $text = self::statement();

        self::assertTrue($parser->supports($text));
        $result = $parser->parse('%PDF-fake', $text);

        $header = $result['header'];
        self::assertSame('1000000005', $header['account_number']);
        self::assertSame('2099-10-31', $header['statement_date']);
        self::assertSame('10', $header['statement_number']);
        self::assertSame(500.0, $header['prev_balance']);
        self::assertSame(1199.5, $header['curr_balance']);
        self::assertSame(1000.0, $header['credit_total']);
        self::assertSame(300.5, $header['debit_total']);
        self::assertSame('CZK', $header['account_currency']);

        [$credit, $debit, $card] = $result['transactions'];
        self::assertCount(3, $result['transactions']);

        self::assertSame('2099-10-01', $credit['posted_at']);
        self::assertSame(1000.0, $credit['amount']);
        self::assertSame('2099001', $credit['variable_symbol']);
        self::assertSame('308', $credit['constant_symbol']);
        self::assertNull($credit['specific_symbol']);
        self::assertSame('1000000005', $credit['counterparty_account']);
        self::assertSame('0800', $credit['counterparty_bank']);
        self::assertSame('TESTOVACI ODBERATEL', $credit['counterparty_name']);
        self::assertSame('120-30092099 TEST00000001', $credit['bank_ref']);
        self::assertSame('Příchozí úhrada | FAKTURA 2099001', $credit['description']);

        self::assertSame(-200.0, $debit['amount']);
        self::assertSame('7126012917', $debit['variable_symbol']);
        self::assertNull($debit['counterparty_name']);

        self::assertSame('2099-10-05', $card['posted_at']);
        self::assertSame(-100.5, $card['amount']);
        self::assertSame('0096', $card['card_last4']);
        self::assertSame('TESTOVACI OBCHOD IE', $card['counterparty_name']);
        self::assertNull($card['counterparty_account']);
    }

    public function testRejectsWrongTransactionCount(): void
    {
        $this->expectExceptionMessage('výpis uvádí 3 transakcí, přečteno 2');
        (new KbPrintedStatementPdfParser())->parse('%PDF-fake', self::statement(self::CREDIT, self::CARD));
    }

    public function testRejectsWrongBalance(): void
    {
        $text = str_replace('Konečný zůstatek:	1 199,50', 'Konečný zůstatek:	1 199,60', self::statement());
        $this->expectExceptionMessage('nesedí na změnu zůstatku');
        (new KbPrintedStatementPdfParser())->parse('%PDF-fake', $text);
    }

    public function testRejectsTransactionWithoutSymbols(): void
    {
        $broken = str_replace("2099001\n308\n0\n", "2099001\n", self::CREDIT);
        $this->expectExceptionMessage('chybí symboly nebo částka');
        (new KbPrintedStatementPdfParser())->parse('%PDF-fake', self::statement($broken . self::DEBIT));
    }

    public function testDoesNotClaimOtherKbLayouts(): void
    {
        $old = "VÝPIS PERIODICKÝ\nk účtu:12-3456789/0100\nBIC / SWIFT kód: KOMBCZPPXXX\nKomerční banka, a.s.\n";
        $parser = new KbPrintedStatementPdfParser();

        self::assertFalse($parser->supports($old));
        self::assertFalse((new KbAccountStatementPdfParser())->supports(self::statement()));
    }
}
