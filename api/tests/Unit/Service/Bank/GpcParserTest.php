<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\GpcParser;
use PHPUnit\Framework\TestCase;

/**
 * Layout reference: https://wiki.zdechov.net/GPC_export_z_Fio_banky
 * Datum format: DDMMYY (NE YYMMDD)
 */
final class GpcParserTest extends TestCase
{
    public function testReconstructedFlagSurvivesLaterOrdinaryHeader(): void
    {
        $export = (new \MyInvoice\Service\Bank\GpcExporter())->export([
            'status' => 'calculated', 'account_number' => '1000000005', 'bank_code' => '0100',
            'currency' => 'CZK', 'from' => '2026-01-01', 'to' => '2026-01-31',
            'opening' => 100, 'closing' => 110, 'credit' => 10, 'debit' => 0,
            'transactions' => [[
                'id' => 1, 'posted_at' => '2026-01-02', 'amount' => '10.00', 'bank_ref' => '123',
                'counterparty_account' => null, 'counterparty_bank' => null, 'counterparty_name' => null,
                'description' => null, 'variable_symbol' => null, 'constant_symbol' => null, 'specific_symbol' => null,
            ]],
        ]);
        $ordinaryHeader = substr_replace(explode("\r\n", $export, 2)[0], str_pad('BANK EXPORT', 20), 19, 20);
        $parsed = (new GpcParser())->parse($export . $ordinaryHeader . "\r\n");
        self::assertTrue($parsed['header']['reconstructed']);
        self::assertCount(1, $parsed['transactions']);
    }

    public function testParsesHeaderAnd075Transactions(): void
    {
        // 074 header — fixed-width per zdechov wiki:
        // pos 1-3: "074", 4-19: account, 20-39: name, 40-45: old_balance_date,
        // 46-59: old_balance, 60: sign, 61-74: new_balance, 75: sign,
        // 76-89: debit, 90: sign, 91-104: credit, 105: sign,
        // 106-108: sequence, 109-114: statement_date
        $header = '074'
            . str_pad('1234567890', 16)                            // own account
            . str_pad('Test Account', 20)                          // name
            . '300426'                                              // old_balance_date 30.4.2026
            . str_pad('100000', 14, '0', STR_PAD_LEFT) . '+'       // old_balance 1000.00
            . str_pad('250000', 14, '0', STR_PAD_LEFT) . '+'       // new_balance 2500.00
            . str_pad('100000', 14, '0', STR_PAD_LEFT) . '+'       // debit
            . str_pad('250000', 14, '0', STR_PAD_LEFT) . '+'       // credit
            . '001'                                                 // sequence
            . '010526';                                             // statement_date 1.5.2026 DDMMYY

        // 075 transaction
        // pos 1-3: "075", 4-19: own, 20-35: counterparty, 36-48: doc, 49-60: amount,
        // 61: code, 62-71: VS, 72-73: filler, 74-77: bank_code, 78-81: KS,
        // 82-91: SS, 92-97: value_date filler, 98-117: client_name, 118-122: currency,
        // 123-128: posting_date DDMMYY
        $tx = '075'
            . str_pad('1234567890', 16)                            // own account
            . str_pad('9876543210123456', 16)                      // counterparty
            . str_pad('DOC0000000001', 13)                         // doc number
            . str_pad('1815000', 12, '0', STR_PAD_LEFT)            // amount cents = 18150.00
            . '2'                                                   // code (2 = credit)
            . str_pad('202605001', 10, '0', STR_PAD_LEFT)          // VS
            . '00'                                                  // filler 2
            . '0100'                                                // bank code (KB)
            . '0308'                                                // KS
            . str_pad('0', 10, '0', STR_PAD_LEFT)                  // SS
            . '020526'                                              // value_date filler 6
            . str_pad('Platba ACME', 20)                           // description
            . '00203'                                               // currency 5 (00203 = CZK)
            . '020526';                                             // posting_date DDMMYY 2.5.2026

        $parser = new GpcParser();
        $r = $parser->parse($header . "\n" . $tx);

        // Header
        self::assertSame('1234567890', $r['header']['account_number']);
        self::assertSame('2026-05-01', $r['header']['statement_date']);
        self::assertSame(1000.0,  $r['header']['prev_balance']);
        self::assertSame(2500.0,  $r['header']['curr_balance']);
        self::assertSame('001',   $r['header']['statement_number']);

        // Transaction
        self::assertCount(1, $r['transactions']);
        $t = $r['transactions'][0];
        self::assertSame('2026-05-02', $t['posted_at']);
        self::assertSame(18150.0,      $t['amount']);
        self::assertSame('202605001',  $t['variable_symbol']);
        self::assertSame('308',        $t['constant_symbol']);   // ltrim('0') v parser
        self::assertSame('0100',       $t['counterparty_bank']);
        self::assertSame('Platba ACME', $t['description']);
        self::assertSame('CZK',        $t['currency']);
    }

    /**
     * Issue #150: VS (a KS/SS) doplněné zleva nulami se nesmí přijít o VÝZNAMNÉ
     * koncové nuly. Dřív `trim(" 0")` utínal nuly z obou stran → VS 260100010 → 26010001.
     */
    public function testTrailingZerosInSymbolsArePreserved(): void
    {
        $header = '074' . str_pad('1', 16) . str_pad('', 20) . '010126'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . '001' . '010126';
        $tx = '075'
            . str_pad('1', 16) . str_pad('1', 16) . str_pad('D', 13)
            . str_pad('100', 12, '0', STR_PAD_LEFT) . '2'
            . str_pad('260100010', 10, '0', STR_PAD_LEFT)          // VS končící nulou (zleva pad)
            . '00' . '0100'
            . str_pad('3100', 4, '0', STR_PAD_LEFT)                // KS končící nulami
            . str_pad('5500', 10, '0', STR_PAD_LEFT)               // SS končící nulami
            . '010126'
            . str_pad('Test', 20) . '00203' . '010126';

        $t = (new GpcParser())->parse($header . "\n" . $tx)['transactions'][0];
        self::assertSame('260100010', $t['variable_symbol']);   // ne 26010001
        self::assertSame('3100',      $t['constant_symbol']);    // ne 31
        self::assertSame('5500',      $t['specific_symbol']);    // ne 55
    }

    /**
     * Samé nuly / prázdné pole → null (žádný symbol), ne literál "0000000000".
     */
    public function testAllZeroSymbolsBecomeNull(): void
    {
        $header = '074' . str_pad('1', 16) . str_pad('', 20) . '010126'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . '001' . '010126';
        $tx = '075'
            . str_pad('1', 16) . str_pad('1', 16) . str_pad('D', 13)
            . str_pad('100', 12, '0', STR_PAD_LEFT) . '2'
            . str_pad('0', 10, '0', STR_PAD_LEFT)                  // VS = samé nuly
            . '00' . '0100'
            . str_pad('0', 4, '0', STR_PAD_LEFT)                   // KS = samé nuly
            . str_pad('0', 10, '0', STR_PAD_LEFT)                  // SS = samé nuly
            . '010126'
            . str_pad('Test', 20) . '00203' . '010126';

        $t = (new GpcParser())->parse($header . "\n" . $tx)['transactions'][0];
        self::assertNull($t['variable_symbol']);
        self::assertNull($t['constant_symbol']);
        self::assertNull($t['specific_symbol']);
    }

    /**
     * Karetní pohyb nemá protistranu — banka pole vyplní samými nulami. To není
     * číslo účtu; uložené by se tvářilo jako známá protistrana a shodilo by
     * cross-source dedup s e-mailovým avízem (EmailNoticeReconciler).
     */
    public function testAllZeroCounterpartyAccountBecomesNull(): void
    {
        $header = '074' . str_pad('1', 16) . str_pad('', 20) . '010126'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . '001' . '010126';
        $tx = '075'
            . str_pad('1', 16) . str_pad('', 16, '0')               // protiúčet = samé nuly
            . str_pad('D', 13)
            . str_pad('100', 12, '0', STR_PAD_LEFT) . '1'
            . str_pad('0', 10, '0', STR_PAD_LEFT)
            . '00' . '0000'
            . str_pad('0', 4, '0', STR_PAD_LEFT)
            . str_pad('0', 10, '0', STR_PAD_LEFT)
            . '010126'
            . str_pad('OPENAI', 20) . '00203' . '010126';

        $t = (new GpcParser())->parse($header . "\n" . $tx)['transactions'][0];
        self::assertNull($t['counterparty_account']);
    }

    /**
     * BUG 0: číslo dokladu (pozice 36-48) je ID pohybu od banky a MUSÍ se dostat do
     * `bank_ref`. Bez něj StatementImporter skládá identitu pohybu z VS/KS/SS/popisu,
     * takže dvě legitimní platby téže částky, dne a VS splynou a druhá se tiše zahodí.
     */
    public function testDocumentNumberIsExposedAsBankReference(): void
    {
        $header = '074' . str_pad('1', 16) . str_pad('', 20) . '010126'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . '001' . '010126';
        $mkTx = fn (string $doc): string => '075'
            . str_pad('1', 16) . str_pad('1', 16)
            . str_pad($doc, 13, '0', STR_PAD_LEFT)                 // číslo dokladu = ID pohybu
            . str_pad('12300', 12, '0', STR_PAD_LEFT) . '1'
            . str_pad('0', 10, '0', STR_PAD_LEFT)
            . '00' . '0100' . '0000'
            . str_pad('0', 10, '0', STR_PAD_LEFT)
            . '010126'
            . str_pad('Poplatek', 20) . '00203' . '010126';

        $r = (new GpcParser())->parse($header . "\n" . $mkTx('26001234567') . "\n" . $mkTx('26001234568'));

        self::assertSame('26001234567', $r['transactions'][0]['bank_ref'], 'Vodicí nuly paddingu se strhávají, ID pohybu zůstává.');
        self::assertSame('26001234568', $r['transactions'][1]['bank_ref']);
    }

    /**
     * Banka ID pohybu neposlala (pole samé nuly / prázdné) → NULL, ať importér ví,
     * že musí sáhnout po náhradní identitě, a neschová si prázdnou referenci.
     */
    public function testMissingDocumentNumberYieldsNullBankReference(): void
    {
        $header = '074' . str_pad('1', 16) . str_pad('', 20) . '010126'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . '001' . '010126';
        $mkTx = fn (string $doc): string => '075'
            . str_pad('1', 16) . str_pad('1', 16) . $doc
            . str_pad('100', 12, '0', STR_PAD_LEFT) . '2'
            . str_pad('0', 10, '0', STR_PAD_LEFT)
            . '00' . '0100' . '0000'
            . str_pad('0', 10, '0', STR_PAD_LEFT)
            . '010126'
            . str_pad('Test', 20) . '00203' . '010126';

        $parser = new GpcParser();
        self::assertNull($parser->parse($header . "\n" . $mkTx(str_pad('', 13, '0')))['transactions'][0]['bank_ref']);
        self::assertNull($parser->parse($header . "\n" . $mkTx(str_pad('', 13, ' ')))['transactions'][0]['bank_ref']);
    }

    public function testCurrencyMappingFromIsoNumeric(): void
    {
        // 00203 = CZK, 00978 = EUR, 00840 = USD — verify normalize ltrim works
        $header = '074' . str_pad('1', 16) . str_pad('', 20) . '010126'
            . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+'
            . '001' . '010126';

        $mkTx = function (string $currencyField): string {
            return '075'
                . str_pad('1', 16) . str_pad('1', 16) . str_pad('D', 13)
                . str_pad('100', 12, '0', STR_PAD_LEFT)
                . '2'
                . str_pad('1', 10, '0', STR_PAD_LEFT)
                . '00' . '0100' . '0000'
                . str_pad('0', 10, '0', STR_PAD_LEFT)
                . '010126'
                . str_pad('Test', 20)
                . $currencyField                              // 5 chars
                . '010126';
        };

        $parser = new GpcParser();
        self::assertSame('CZK', $parser->parse($header . "\n" . $mkTx('00203'))['transactions'][0]['currency']);
        self::assertSame('EUR', $parser->parse($header . "\n" . $mkTx('00978'))['transactions'][0]['currency']);
        self::assertSame('USD', $parser->parse($header . "\n" . $mkTx('00840'))['transactions'][0]['currency']);
        self::assertNull($parser->parse($header . "\n" . $mkTx('     '))['transactions'][0]['currency']);
    }

    public function testNegativeAmountForDebitCode(): void
    {
        // Posting code 1 = debit → záporná amount
        $header = '074' . str_pad('1', 16) . str_pad('', 20) . '010126'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . '001' . '010126';
        $tx = '075' . str_pad('1', 16) . str_pad('1', 16) . str_pad('D', 13)
            . str_pad('50000', 12, '0', STR_PAD_LEFT)
            . '1'                                                   // 1 = debit
            . str_pad('1', 10, '0', STR_PAD_LEFT)
            . '00' . '0100' . '0000'
            . str_pad('0', 10, '0', STR_PAD_LEFT) . '010126'
            . str_pad('Outgoing', 20) . '00203' . '010126';

        $parser = new GpcParser();
        self::assertSame(-500.0, $parser->parse($header . "\n" . $tx)['transactions'][0]['amount']);
    }

    /**
     * Issue #1: Air Bank GPC s diakritikou v názvu účtu (`Hlavní podnikatelský`).
     * Před fixem se po iconv→UTF-8 multibyte znaky `í`,`ý` natáhly z 1 na 2 bajty
     * a všechny offsety za názvem se posunuly → statement_date null, balances rozbité.
     */
    public function testAirBankHeaderWithCzechDiacritics(): void
    {
        // Raw CP1250 bajty (jak je banka generuje). \xed = í, \xfd = ý.
        $cp1250Header = '074'
            . '0000002847527018'                         // account 16
            . "Hlavn\xed podnikatelsk\xfd"               // name 20 single-byte chars in CP1250
            . '310326'                                    // old_balance_date 31.3.2026
            . '00000001797559' . '+'                      // prev 17975.59
            . '00000001355384' . '+'                      // curr 13553.84
            . '00000004919425' . '0'                      // debit 49194.25, sign='0' (Air Bank)
            . '00000004477250' . '0'                      // credit 44772.50, sign='0'
            . '004'                                        // statement_no
            . '300426';                                    // statement_date 30.4.2026

        $parser = new GpcParser();

        // 1) parse z raw CP1250 vstupu
        $r = $parser->parse($cp1250Header);
        self::assertSame('0000002847527018', $r['header']['account_number']);
        self::assertSame('2026-04-30',       $r['header']['statement_date']);
        self::assertSame('004',              $r['header']['statement_number']);
        self::assertSame(17975.59,           $r['header']['prev_balance']);
        self::assertSame(13553.84,           $r['header']['curr_balance']);
        self::assertSame(49194.25,           $r['header']['debit_total']);
        self::assertSame(44772.50,           $r['header']['credit_total']);

        // 2) parse vstupu už převedeného na UTF-8 (např. user otevřel a uložil v editoru)
        $utf8Header = iconv('CP1250', 'UTF-8', $cp1250Header);
        $r2 = $parser->parse($utf8Header);
        self::assertSame('2026-04-30', $r2['header']['statement_date']);
        self::assertSame(17975.59,     $r2['header']['prev_balance']);
        self::assertSame(49194.25,     $r2['header']['debit_total']);
    }

    /**
     * Defenzivní fallback: když statement_date nelze rozparsovat (poškozený řádek),
     * parser nesmí crashnout SQL insert (statement_date NOT NULL) — fallback na
     * old_balance_date, případně na dnešní datum.
     */
    public function testStatementDateFallbackToOldBalanceDate(): void
    {
        // Vyrobíme řádek s VALID old_balance_date ale INVALID statement_date (samé mezery).
        $line = '074'
            . str_pad('1', 16) . str_pad('', 20) . '150326'        // old_balance_date 15.3.2026
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . '001'
            . '      ';                                              // statement_date = blank

        $parser = new GpcParser();
        $r = $parser->parse($line);
        self::assertSame('2026-03-15', $r['header']['statement_date']);
    }

    public function testThrowsOnMissingHeader(): void
    {
        $parser = new GpcParser();
        $this->expectException(\RuntimeException::class);
        $parser->parse("nějaký nesmyslný obsah bez 074 řádku");
    }

    public function testMergesO78AdvisoIntoTransaction(): void
    {
        // Karetní platba: 075 má prázdné client_name, název obchodníka je v navazujícím
        // 078 řádku. Bez parsování 078 by se název obchodníka ztratil. (Fiktivní data.)
        $header = '074' . str_pad('1', 16) . str_pad('', 20) . '010126'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . '001' . '010126';
        $tx = '075'
            . str_pad('1', 16) . str_pad('1', 16) . str_pad('D', 13)
            . str_pad('28500', 12, '0', STR_PAD_LEFT)              // 285.00
            . '1'                                                   // debit
            . str_pad('0', 10, '0', STR_PAD_LEFT)
            . '00' . '0100' . '0000'
            . str_pad('0', 10, '0', STR_PAD_LEFT)
            . '200426'
            . str_pad('', 20)                                       // client_name PRÁZDNÉ (karta)
            . '00203' . '200426';
        // Fiktivní data (NE reálný výpis).
        $advizo = '078ACME OBCHOD PRAHA CZ PK: 000000******0000';

        $r = (new GpcParser())->parse($header . "\n" . $tx . "\n" . $advizo);

        self::assertCount(1, $r['transactions']);
        $t = $r['transactions'][0];
        // Název obchodníka doplněn z 078 (část před " PK:").
        self::assertSame('ACME OBCHOD PRAHA CZ', $t['counterparty_name']);
        // description zachová celé avízo (název + lokalita + maskovaná karta).
        self::assertStringContainsString('ACME OBCHOD PRAHA CZ PK: 000000******0000', (string) $t['description']);
    }

    public function testO78DoesNotOverwriteExisting075Name(): void
    {
        // Když 075 client_name vyplněné je, 078 ho nepřepíše (jen doplní description).
        $header = '074' . str_pad('1', 16) . str_pad('', 20) . '010126'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . '001' . '010126';
        $tx = '075'
            . str_pad('1', 16) . str_pad('1', 16) . str_pad('D', 13)
            . str_pad('100', 12, '0', STR_PAD_LEFT) . '2'
            . str_pad('0', 10, '0', STR_PAD_LEFT) . '00' . '0100' . '0000'
            . str_pad('0', 10, '0', STR_PAD_LEFT) . '200426'
            . str_pad('ACME s.r.o.', 20) . '00203' . '200426';
        $advizo = '078Poznamka k platbe';

        $t = (new GpcParser())->parse($header . "\n" . $tx . "\n" . $advizo)['transactions'][0];
        self::assertSame('ACME s.r.o.', $t['counterparty_name']);
        self::assertStringContainsString('Poznamka k platbe', (string) $t['description']);
    }
}
