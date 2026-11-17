<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Pdf;

use MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry;
use MyInvoice\Service\Bank\Pdf\CeskaSporitelnaStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\CreditasStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\CsobStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\KbAccountStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\KbStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\MonetaStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\RaiffeisenbankStatementPdfParser;
use MyInvoice\Service\Bank\Pdf\RevolutStatementPdfParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * PDF výpis z účtu Revolut Business. Data jsou VYMYŠLENÁ, pozice buněk odpovídají
 * layoutu výpisu: přehled zůstatků nad tabulkou, sloupce Datum (UTC) / Popis /
 * Odchozí / Příchozí / Zůstatek, částky zarovnané doprava a bez znaménka.
 */
final class RevolutStatementPdfParserTest extends TestCase
{
    private const IBAN = 'LT63 3250 0012 3456 7890';

    private const X_DATE = 47.51;
    private const X_TYPE = 123.9;
    private const X_DESC = 174.83;
    private const X_OUT = 358.24;
    private const X_IN = 434.6;
    private const X_BALANCE = 498.2;

    private static function textCs(string $prev, string $in, string $out, string $curr): string
    {
        return "Nahlásit ztrátu nebo krádež karty\n"
            . "Revolut Bank UAB je úvěrová instituce licencovaná v Litvě.\n"
            . "© 2099Revolut Bank UAB\n"
            . "Název účtu\tMain\nMěna\tCZK\nTyp\tMezinárodní\n"
            . 'IBAN' . "\t" . self::IBAN . "\nBIC\tREVOLT21\n"
            . "Výpis z účtu\nVygenerováno 3. listopadu 2099\nTestovací firma s.r.o.\n"
            . "Přehled zůstatků\n"
            . "Počáteční zůstatek\t{$prev}\nPříchozí\t{$in}\nOdchozí\t{$out}\nKoncový zůstatek\t{$curr}\n"
            . "   Transakceod1. října 2099do31. října 2099\n"
            . "Datum (UTC)\tPopis\tOdchozí Příchozí Zůstatek\n"
            . "Typy transakcí\n Poplatky Revolut(FEE)\n{$out} \n";
    }

    private static function textEn(): string
    {
        return "Report lost or stolen card\n"
            . "Revolut Bank UAB is a credit institution licensed in Lithuania.\n"
            . "Account name\tMain\nCurrency\tEUR\n"
            . 'IBAN' . "\t" . self::IBAN . "\nBIC\tREVOLT21\n"
            . "Account statement\nGenerated on 3 November 2099\nTest Company Ltd\n"
            . "Balance summary\n"
            . "Opening balance\t1,000.00 EUR\nMoney in\t2,500.00 EUR\nMoney out\t- 120.50 EUR\nClosing balance\t3,379.50 EUR\n"
            . "   Transactionsfrom1 October 2099to31 October 2099\n"
            . "Date (UTC)\tDescription\tMoney out Money in Balance\n"
            . "Transaction types\n";
    }

    /**
     * Stránka výpisu: záhlaví tabulky, pohyby a patička. První strana nese i přehled
     * zůstatků, jehož popisky „Příchozí"/„Odchozí" nesmí posloužit jako záhlaví.
     *
     * @param list<array{0:float,1:float,2:string}> $rows
     * @param array<string,string> $labels
     */
    private static function page(int $page, array $rows, array $labels, bool $summary, bool $types): array
    {
        $chunks = [];
        if ($summary) {
            $chunks[] = [46.76, 567.04, $labels['opening']];
            $chunks[] = [46.76, 544.54, $labels['in']];
            $chunks[] = [46.76, 522.04, $labels['out']];
        }
        $chunks = [
            ...$chunks,
            [self::X_DATE, 400.8, $labels['date']],
            [73.61, 400.8, ' (UTC)'],
            [self::X_DESC, 400.8, $labels['description']],
            [367.76, 400.8, $labels['out']],
            [444.9, 400.8, $labels['in']],
            [518.66, 400.8, $labels['balance']],
            ...$rows,
        ];
        if ($types) {
            $chunks[] = [40.01, 230.67, $labels['types']];
            $chunks[] = [430.16, 205.14, $labels['types_fee']];
            $chunks[] = [430.16, 195.39, '- 250.00 CZK'];
        }
        $chunks[] = [71.51, 60.75, $labels['lost']];
        $chunks[] = [178.24, 61.65, $labels['legal']];
        $chunks[] = [63.75, 24.0, 'Revolut Bank UAB'];
        $chunks[] = [545.14, 18.52, ($page + 1) . '/2'];

        return array_map(static fn (array $c): array => ['page' => $page, 'x' => $c[0], 'y' => $c[1], 'text' => $c[2]], $chunks);
    }

    /** @return array<string,string> */
    private static function labelsCs(): array
    {
        return [
            'opening' => 'Počáteční zůstatek', 'in' => 'Příchozí', 'out' => 'Odchozí', 'date' => 'Datum',
            'description' => 'Popis', 'balance' => 'Zůstatek', 'types' => 'Typy transakcí',
            'types_fee' => 'Poplatky Revolut', 'lost' => 'Nahlásit ztrátu nebo krádež karty',
            'legal' => 'Revolut Bank UAB je úvěrová instituce licencovaná v Litvě. ',
        ];
    }

    /** @return array<string,string> */
    private static function labelsEn(): array
    {
        return [
            'opening' => 'Opening balance', 'in' => 'Money in', 'out' => 'Money out', 'date' => 'Date',
            'description' => 'Description', 'balance' => 'Balance', 'types' => 'Transaction types',
            'types_fee' => 'Revolut fees', 'lost' => 'Report lost or stolen card',
            'legal' => 'Revolut Bank UAB is a credit institution licensed in Lithuania. ',
        ];
    }

    /** @return list<array{0:float,1:float,2:string}> */
    private static function incomingAndCardRows(): array
    {
        return [
            [self::X_DATE, 379.01, '2 říj 2099'],
            [self::X_TYPE, 379.01, 'MOR'],
            [self::X_DESC, 379.01, 'Platba od Testovací odběratel s.r.o.'],
            [self::X_DESC, 365.51, 'Reference: Faktura 2099001'],
            [self::X_IN, 379.01, '12 345.67 CZK'],
            [self::X_BALANCE, 379.01, '22 345.67 CZK'],

            [self::X_DATE, 340.2, '5 říj 2099'],
            [self::X_TYPE, 340.2, 'CAR'],
            [self::X_DESC, 340.2, 'Testovací obchod'],
            [self::X_DESC, 326.7, 'Karta: 459999******4321'],
            [self::X_DESC, 313.2, '12.50 EUR, 1 EUR = 25.00 CZK'],
            [self::X_OUT, 340.2, '312.50 CZK'],
            [self::X_BALANCE, 340.2, '22 033.17 CZK'],
        ];
    }

    /** @return list<array{0:float,1:float,2:string}> */
    private static function feeRow(float $y): array
    {
        return [
            [self::X_DATE, $y, '26 říj 2099'],
            [self::X_TYPE, $y, 'FEE'],
            [self::X_DESC, $y, 'Poplatky Revolut Business • Poplatek za '],
            [self::X_DESC, $y - 13.5, 'Basic plán'],
            [self::X_OUT, $y, '250.00 CZK'],
            [self::X_BALANCE, $y, '21 783.17 CZK'],
        ];
    }

    public function testParsesCzechStatementWithMultilineDescriptions(): void
    {
        $parser = new RevolutStatementPdfParser();
        $text = self::textCs('10 000.00 CZK', '12 345.67 CZK', '- 562.50 CZK', '21 783.17 CZK');
        self::assertTrue($parser->supports($text));

        $chunks = self::page(0, [...self::incomingAndCardRows(), ...self::feeRow(290.0)], self::labelsCs(), true, true);
        $result = $parser->parseChunks($chunks, $text);

        $header = $result['header'];
        self::assertSame('LT633250001234567890', $header['account_number']);
        self::assertSame('2099-10-31', $header['statement_date']);
        self::assertSame('2099-10-01', $header['period_from']);
        self::assertSame(10000.0, $header['prev_balance']);
        self::assertSame(21783.17, $header['curr_balance']);
        self::assertSame(12345.67, $header['credit_total']);
        self::assertSame(562.5, $header['debit_total']);
        self::assertSame('CZK', $header['account_currency']);

        $txs = $result['transactions'];
        self::assertCount(3, $txs);

        self::assertSame('2099-10-02', $txs[0]['posted_at']);
        self::assertSame(12345.67, $txs[0]['amount']);
        self::assertSame('CZK', $txs[0]['currency']);
        self::assertNull($txs[0]['variable_symbol']);
        self::assertSame('MOR | Platba od Testovací odběratel s.r.o. | Reference: Faktura 2099001', $txs[0]['description']);

        self::assertSame(-312.5, $txs[1]['amount']);
        self::assertSame('4321', $txs[1]['card_last4']);
        self::assertStringContainsString('12.50 EUR, 1 EUR = 25.00 CZK', (string) $txs[1]['description'], 'Původní částka a kurz zůstávají v popisu.');

        self::assertSame('2099-10-26', $txs[2]['posted_at']);
        self::assertSame(-250.0, $txs[2]['amount']);
        self::assertSame('FEE | Poplatky Revolut Business • Poplatek za Basic plán', $txs[2]['description'], 'Zalomený řádek popisu se spojí mezerou.');
    }

    public function testParsesEnglishStatementWithCounterpartyIban(): void
    {
        $parser = new RevolutStatementPdfParser();
        $text = self::textEn();
        self::assertTrue($parser->supports($text));

        $rows = [
            [self::X_DATE, 379.01, '3 Oct 2099'],
            [self::X_TYPE, 379.01, 'MOA'],
            [self::X_DESC, 379.01, 'Money added from TEST USER'],
            [self::X_IN, 379.01, '2,500.00 EUR'],
            [self::X_BALANCE, 379.01, '3,500.00 EUR'],

            [self::X_DATE, 352.0, '20 Oct 2099'],
            [self::X_TYPE, 352.0, 'MOS'],
            [self::X_DESC, 352.0, 'To Test Supplier GmbH'],
            [self::X_DESC, 338.5, 'DE89 3704 0044 0532 0130 00'],
            [self::X_DESC, 325.0, 'Reference: INV-2099-7'],
            [self::X_OUT, 352.0, '120.50 EUR'],
            [self::X_BALANCE, 352.0, '3,379.50 EUR'],
        ];
        $result = $parser->parseChunks(self::page(0, $rows, self::labelsEn(), true, false), $text);

        self::assertSame('LT633250001234567890', $result['header']['account_number']);
        self::assertSame('EUR', $result['header']['account_currency']);
        self::assertSame(1000.0, $result['header']['prev_balance']);
        self::assertSame(3379.5, $result['header']['curr_balance']);

        $txs = $result['transactions'];
        self::assertCount(2, $txs);
        self::assertSame('2099-10-03', $txs[0]['posted_at']);
        self::assertSame(2500.0, $txs[0]['amount']);
        self::assertSame('EUR', $txs[0]['currency']);
        self::assertSame(-120.5, $txs[1]['amount']);
        self::assertSame('DE89370400440532013000', $txs[1]['counterparty_account']);
        self::assertStringContainsString('Reference: INV-2099-7', (string) $txs[1]['description']);
    }

    /** Záhlaví se na další straně opakuje; popis zalomený přes konec strany patří pohybu z předchozí strany. */
    public function testParsesStatementAcrossPages(): void
    {
        $text = self::textCs('10 000.00 CZK', '12 345.67 CZK', '- 562.50 CZK', '21 783.17 CZK');
        $first = self::page(0, self::incomingAndCardRows(), self::labelsCs(), true, false);
        $second = self::page(1, [
            [self::X_DESC, 385.0, 'Kartová platba v cizí měně'],
            ...self::feeRow(360.0),
        ], self::labelsCs(), false, true);

        $txs = (new RevolutStatementPdfParser())->parseChunks([...$first, ...$second], $text)['transactions'];

        self::assertCount(3, $txs);
        self::assertSame([12345.67, -312.5, -250.0], array_column($txs, 'amount'));
        self::assertStringEndsWith('Kartová platba v cizí měně', (string) $txs[1]['description']);
        self::assertSame('FEE | Poplatky Revolut Business • Poplatek za Basic plán', $txs[2]['description']);
    }

    public function testRejectsWhenSummaryTotalsDoNotMatchMovements(): void
    {
        $text = self::textCs('10 000.00 CZK', '12 345.67 CZK', '- 500.00 CZK', '21 783.17 CZK');
        $chunks = self::page(0, [...self::incomingAndCardRows(), ...self::feeRow(290.0)], self::labelsCs(), true, true);

        $this->expectException(\RuntimeException::class);
        (new RevolutStatementPdfParser())->parseChunks($chunks, $text);
    }

    public function testRejectsWhenBalanceChainBreaks(): void
    {
        $text = self::textCs('10 000.00 CZK', '12 345.67 CZK', '- 562.50 CZK', '21 783.17 CZK');
        $rows = self::incomingAndCardRows();
        $rows[4][2] = '12 345.76 CZK';
        $chunks = self::page(0, [...$rows, ...self::feeRow(290.0)], self::labelsCs(), true, true);

        $this->expectException(\RuntimeException::class);
        (new RevolutStatementPdfParser())->parseChunks($chunks, $text);
    }

    public function testRejectsInvalidIban(): void
    {
        $text = str_replace(self::IBAN, 'LT64 3250 0012 3456 7890', self::textCs('0.00 CZK', '0.00 CZK', '0.00 CZK', '0.00 CZK'));

        $this->expectException(\RuntimeException::class);
        (new RevolutStatementPdfParser())->parseHeaderFromText($text);
    }

    /** Revolut nepřebírá výpisy českých bank a ty nepřebírají jeho, ani když text zmiňuje druhou banku. */
    public function testSupportsDoesNotCollideWithOtherBankParsers(): void
    {
        $logger = new NullLogger();
        $others = [
            new CreditasStatementPdfParser($logger),
            new CsobStatementPdfParser($logger),
            new CeskaSporitelnaStatementPdfParser(),
            new KbAccountStatementPdfParser(),
            new KbStatementPdfParser($logger),
            new MonetaStatementPdfParser(),
            new RaiffeisenbankStatementPdfParser($logger),
        ];
        $revolut = new RevolutStatementPdfParser();
        $revolutText = self::textCs('10 000.00 CZK', '0.00 CZK', '0.00 CZK', '10 000.00 CZK');

        foreach ($others as $parser) {
            self::assertFalse($parser->supports($revolutText), $parser->key() . ' nesmí převzít výpis Revolut.');
            self::assertFalse($parser->supports(self::textEn()), $parser->key() . ' nesmí převzít anglický výpis Revolut.');
        }

        self::assertFalse($revolut->supports("Výpis z účtu\nPŘEHLED POHYBŮ NA ÚČTU\nČeská spořitelna, a.s.\nRevolut Bank UAB REVOLT21\n"));
        self::assertFalse($revolut->supports("Výpis pohybů\nRaiffeisenbank a.s.\nwww.rb.cz\nRZBCCZPP\nREVOLT21 Revolut Bank UAB\n"));
    }

    /**
     * Popis pohybu Revolut může nést název a BIC české banky protistrany. Parser
     * Raiffeisenbank by takový výpis přijal, proto je Revolut v registru dřív.
     */
    public function testRegistryPrefersRevolutWhenDescriptionMentionsCzechBank(): void
    {
        $registry = \MyInvoice\Bootstrap::buildContainer()->get(BankStatementPdfParserRegistry::class);
        $text = self::textCs('10 000.00 CZK', '0.00 CZK', '0.00 CZK', '10 000.00 CZK')
            . "Přijatá částka od Raiffeisenbank a.s. RZBCCZPP www.rb.cz\n";

        self::assertSame('revolut', $registry->parserFor($text)?->key());
    }
}
