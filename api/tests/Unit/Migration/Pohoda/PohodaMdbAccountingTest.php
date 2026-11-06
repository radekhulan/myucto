<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaJournal;
use MyInvoice\Service\Migration\Pohoda\PohodaMdbAccounting;
use MyInvoice\Service\Migration\Pohoda\PohodaMdbTables;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PohodaMdbAccountingTest extends TestCase
{
    private string $path;
    private string $dir;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'pohoda_accounting_unit_');
        $this->dir = $this->path . '_files';
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        unlink($this->path);
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dir);
    }

    public function testJournalHasSameDebitCreditAndAmountsAsOfficialXml(): void
    {
        file_put_contents($this->path, '<root><accountingItem><homeCurrency><priceSum>1250.50</priceSum></homeCurrency><accounting><credit>518000</credit><debit>321000</debit></accounting></accountingItem></root>');
        $official = iterator_to_array(PohodaXml::records($this->path, 'accountingItem'))[0];
        $mapper = $this->mapper(['pUD' => [
            ['ID' => '1', 'RelUdAg' => '3', 'UMD' => '518000', 'UD' => '321000', 'Kc' => '1250.50', 'Datum' => '2026-02-03T00:00:00'],
            ['ID' => '2', 'RelUdAg' => '3', 'UMD' => '518000', 'UD' => '321000', 'Kc' => '-80.25'],
        ]]);
        $records = iterator_to_array($mapper->records('journal'));
        self::assertSame(PohodaJournal::effect($official), PohodaJournal::effect($records[0]));
        self::assertSame(['debit' => '518000', 'credit' => '321000', 'amount' => 1250.5], PohodaJournal::effect($records[0]));
        self::assertSame(['debit' => '321000', 'credit' => '518000', 'amount' => 80.25], PohodaJournal::effect($records[1]));
        self::assertSame('2026-02-03', $records[0]['date']);
    }

    /** Partner řádku deníku v tvaru XML; RefAD 0 znamená bez vazby, starší export sloupce nemá. */
    public function testJournalCarriesPartnerOfTheRow(): void
    {
        $rows = iterator_to_array($this->mapper(['pUD' => [
            ['ID' => '1', 'RelUdAg' => '29', 'RefAD' => '7', 'Firma' => 'Test s.r.o.', 'ParICO' => '11223344'],
            ['ID' => '2', 'RelUdAg' => '29', 'RefAD' => '0', 'Firma' => 'Bez vazby s.r.o.'],
            ['ID' => '3', 'RelUdAg' => '29'],
        ]])->records('journal'));

        $first = PohodaJournal::partner($rows[0]);
        self::assertSame(['7', 'Test s.r.o.', '11223344'], [$first['pohoda_id'], $first['name'], $first['ico']]);
        self::assertSame('', PohodaJournal::partner($rows[1])['pohoda_id']);
        $last = PohodaJournal::partner($rows[2]);
        self::assertSame(['', '', ''], [$last['pohoda_id'], $last['name'], $last['ico']]);
    }

    public function testReceivedProformaAndCorrectiveDocumentsKeepTheirTypesAndPayments(): void
    {
        $mapper = $this->mapper([
            'FA' => [
                ['ID' => '10', 'Cislo' => 'TEST-PROFORMA', 'RelTpFak' => '16', 'Datum' => '2026-02-03',
                    'Kc2' => '100', 'KcDPH2' => '21', 'KcLikv' => '121', 'DatLikv' => '2026-02-10'],
                ['ID' => '11', 'Cislo' => 'TEST-CORRECTION', 'RelTpFak' => '18', 'Datum' => '2026-02-04',
                    'Kc2' => '-50', 'KcDPH2' => '-10.50'],
            ],
            'BV' => [['ID' => '1', 'RelTpBV' => '2', 'Cislo' => 'TEST-B1', 'Kc0' => '121']],
            'Uhrady' => [
                ['ID' => '7', 'RelIDH' => '10', 'RelAgH' => '44', 'RelAgU' => '28', 'RelIDU' => '1',
                    'DatumU' => '2026-02-10', 'KcU' => '121'],
                ['ID' => '8', 'RelIDH' => '10', 'RelAgH' => '3', 'RelAgU' => '28', 'RelIDU' => '1', 'KcU' => '999'],
            ],
        ]);
        self::assertTrue($mapper->has('received_proforma'));
        $proforma = iterator_to_array($mapper->records('received_proforma'), false)[0];
        self::assertSame('TEST-PROFORMA', $proforma['invoiceHeader']['number']['numberRequested']);
        self::assertSame('121', $proforma['invoiceSummary']['homeCurrency']['priceHighSum']);
        self::assertSame('121', $proforma['invoiceHeader']['liquidation']['amountHome']);
        self::assertCount(1, $proforma['liquidations']['liquidation']);
        self::assertSame('121', $proforma['liquidations']['liquidation'][0]['amount']);
        self::assertSame(['id' => '1', 'number' => 'TEST-B1'], $proforma['liquidations']['liquidation'][0]['sourceDocument']);
        $corrective = iterator_to_array($mapper->records('received_corrective'), false)[0];
        self::assertSame('-60.5', $corrective['invoiceSummary']['homeCurrency']['priceHighSum']);
        $agenda = $this->dir . '/12345678_2026';
        mkdir($agenda);
        copy($this->path, $agenda . '/' . PohodaMdbAccounting::FILE);
        self::assertSame(2, PohodaExport::open($agenda)->counts()['purchase']);
    }

    public function testInvoicePreservesVatBucketsAndSeparatesAdvanceDeduction(): void
    {
        $mapper = $this->mapper([
            'FA' => [['ID' => '1', 'Cislo' => 'TEST-F1', 'RelTpFak' => '1', 'Kc0' => '20', 'Kc1' => '100', 'KcDPH1' => '12', 'Kc2' => '200', 'KcDPH2' => '42', 'KcZaokr' => '0.25']],
            'FApol' => [
                ['ID' => '1', 'RefAg' => '1', 'RelSzDPH' => '2', 'Kc' => '200', 'KcDPH' => '42', 'KcJedn' => '100', 'Mnozstvi' => '2'],
                ['ID' => '2', 'RefAg' => '1', 'RelAgID' => '43', 'Kc' => '-100', 'KcDPH' => '-21', 'RelSzDPH' => '2'],
            ],
        ]);
        $invoice = iterator_to_array($mapper->records('issued'))[0];
        $summary = $invoice['invoiceSummary']['homeCurrency'];
        self::assertSame('20', $summary['priceNone']);
        self::assertSame('100', $summary['priceLow']);
        self::assertSame('12', $summary['priceLowVAT']['#']);
        self::assertSame('200', $summary['priceHigh']);
        self::assertSame('42', $summary['priceHighVAT']['#']);
        self::assertSame('112', $summary['priceLowSum']);
        self::assertSame('242', $summary['priceHighSum']);
        self::assertSame('0.25', $summary['round']['priceRound']);
        self::assertCount(1, $invoice['invoiceDetail']['invoiceItem']);
        self::assertCount(1, $invoice['invoiceDetail']['invoiceAdvancePaymentItem']);
        self::assertSame('242', $invoice['invoiceDetail']['invoiceItem'][0]['homeCurrency']['priceSum']);
        self::assertSame('-121', $invoice['invoiceDetail']['invoiceAdvancePaymentItem'][0]['homeCurrency']['priceSum']);
        self::assertSame('21', $invoice['invoiceDetail']['invoiceItem'][0]['rateVAT']['@value']);
    }

    /**
     * Doklad v režimu OSS z MDB nese stát spotřeby, typ plnění, skutečné procento sazby
     * a částky položky v cizí měně na týchž místech jako XML POHODY - převod pak z obou
     * zdrojů rozhoduje stejně (issue #75). Bez MOSS se elementy vynechávají jako v XML.
     */
    public function testOssFieldsMapToSameElementsAsOfficialXml(): void
    {
        $mapper = $this->mapper([
            'sCMeny' => [['ID' => '2', 'Kod' => 'EUR']],
            'FA' => [
                ['ID' => '1', 'Cislo' => 'TEST-OSS', 'RelTpFak' => '1', 'Datum' => '2026-02-10', 'Kc1' => '1000', 'KcDPH1' => '230',
                    'RefCM' => '2', 'CmKurs' => '25', 'CmMnoz' => '1', 'CmCelkem' => '49.2', 'MOSS' => 'SK', 'MOSSDukaz' => 'G'],
                ['ID' => '2', 'Cislo' => 'TEST-CZ', 'RelTpFak' => '1', 'Datum' => '2026-02-11', 'Kc2' => '100', 'KcDPH2' => '21'],
            ],
            'FApol' => [
                ['ID' => '1', 'RefAg' => '1', 'RelSzDPH' => '1', 'ProcentoDPH' => '23', 'Kc' => '1000', 'KcDPH' => '230', 'MJ' => 'ks',
                    'MOSSDruh' => 'GD', 'CmJedn' => '40', 'Cm' => '40', 'CmDPH' => '9.2'],
                ['ID' => '2', 'RefAg' => '2', 'RelSzDPH' => '2', 'Kc' => '100', 'KcDPH' => '21'],
            ],
        ]);
        [$oss, $domestic] = iterator_to_array($mapper->records('issued'), false);

        self::assertSame('SK', PohodaXml::text($oss['invoiceHeader'], 'MOSS/ids'));
        self::assertSame('G', PohodaXml::text($oss['invoiceHeader'], 'evidentiaryResourcesMOSS/ids'));
        $item = $oss['invoiceDetail']['invoiceItem'][0];
        self::assertSame('GD', PohodaXml::text($item, 'typeServiceMOSS/ids'));
        self::assertSame('23', PohodaXml::text($item, 'percentVAT'));
        self::assertSame('40', PohodaXml::text($item, 'foreignCurrency/price'));
        self::assertSame('9.2', PohodaXml::text($item, 'foreignCurrency/priceVAT'));
        self::assertSame('EUR', PohodaXml::text($oss, 'invoiceSummary/foreignCurrency/currency/ids'));

        // Doklad mimo OSS a v Kč: nic z toho nenese, stejně jako XML POHODY.
        self::assertSame('', PohodaXml::text($domestic['invoiceHeader'], 'MOSS/ids'));
        self::assertArrayNotHasKey('evidentiaryResourcesMOSS', $domestic['invoiceHeader']);
        self::assertArrayNotHasKey('typeServiceMOSS', $domestic['invoiceDetail']['invoiceItem'][0]);
        self::assertArrayNotHasKey('foreignCurrency', $domestic['invoiceDetail']['invoiceItem'][0]);
    }

    public function testBankDirectionAndMovementNumberArePreserved(): void
    {
        $mapper = $this->mapper(['BV' => [
            ['ID' => '1', 'RelTpBV' => '1', 'Cislo' => 'TEST-B1', 'Vypis' => '4/7', 'Kc0' => '125.50'],
            ['ID' => '2', 'RelTpBV' => '2', 'Cislo' => 'TEST-B2', 'Vypis' => '4/8', 'Kc0' => '33.25'],
        ]]);
        $records = iterator_to_array($mapper->records('bank'));
        self::assertSame('receipt', $records[0]['bankHeader']['bankType']);
        self::assertSame('expense', $records[1]['bankHeader']['bankType']);
        self::assertSame(['statementNumber' => '4', 'numberMovement' => '7'], $records[0]['bankHeader']['statementNumber']);
        self::assertSame('33.25', $records[1]['bankSummary']['homeCurrency']['priceNone']);
    }

    /**
     * Úhradu bankou váže POHODA na bankovní doklad id (`Uhrady.RelIDU`) i z položky pohybu
     * (`BVpol.RelIDUhrady`); opis čísla v úhradě (`CisloU`) může mířit na doklad jiného roku
     * se stejným číslem. Párovací symboly pohybu a položek jdou do exportu pro párování.
     */
    public function testPaymentLinksAndPairingSymbolsComeFromIdsNotFromCopiedNumbers(): void
    {
        $mapper = $this->mapper([
            'FA' => [['ID' => '10', 'RelTpFak' => '11', 'Cislo' => 'TEST-P1', 'Datum' => '2026-02-03', 'Kc2' => '100', 'KcDPH2' => '21']],
            'BV' => [
                ['ID' => '1', 'RelTpBV' => '2', 'Cislo' => 'TEST-B1', 'Kc0' => '121', 'ParSym' => 'TEST-P1'],
                ['ID' => '2', 'RelTpBV' => '2', 'Cislo' => 'TEST-B2', 'Kc0' => '50'],
            ],
            'BVpol' => [['ID' => '5', 'RefAg' => '1', 'RelIDUhrady' => '7', 'ParSym' => '2026007', 'Kc' => '121']],
            'Uhrady' => [
                // RelIDU chybí, CisloU je opis čísla dokladu jiného roku - platí vazba z položky pohybu.
                ['ID' => '7', 'RelIDH' => '10', 'RelAgH' => '3', 'RelAgU' => '28', 'DatumU' => '2026-02-10', 'CisloU' => 'TEST-B2', 'KcU' => '121'],
                ['ID' => '8', 'RelIDH' => '10', 'RelAgH' => '3', 'RelAgU' => '28', 'DatumU' => '2026-02-11', 'RelIDU' => '2', 'CisloU' => 'TEST-OLD', 'KcU' => '1'],
            ],
        ]);
        $invoice = iterator_to_array($mapper->records('received'))[0];
        $liquidations = $invoice['liquidations']['liquidation'];
        self::assertSame(['id' => '1', 'number' => 'TEST-B1'], $liquidations[0]['sourceDocument']);
        self::assertSame(['id' => '2', 'number' => 'TEST-B2'], $liquidations[1]['sourceDocument']);

        $bank = iterator_to_array($mapper->records('bank'));
        self::assertSame('TEST-P1', $bank[0]['bankHeader']['symPar']);
        self::assertSame('2026007', $bank[0]['bankDetail']['bankItem'][0]['symPar']);
        self::assertSame('', $bank[1]['bankHeader']['symPar']);
    }

    /** Export staršího převodníku (bez sloupců pro párování) se čte beze změny. */
    public function testExportWithoutPairingColumnsKeepsCopiedPaymentNumber(): void
    {
        $mapper = $this->mapper([
            'FA' => [['ID' => '10', 'RelTpFak' => '11', 'Cislo' => 'TEST-P1', 'Datum' => '2026-02-03', 'Kc2' => '100', 'KcDPH2' => '21']],
            'BV' => [['ID' => '1', 'RelTpBV' => '2', 'Cislo' => 'TEST-B1', 'Kc0' => '121']],
            'Uhrady' => [['ID' => '7', 'RelIDH' => '10', 'RelAgH' => '3', 'RelAgU' => '28', 'DatumU' => '2026-02-10', 'CisloU' => 'TEST-B1', 'KcU' => '121']],
        ]);
        $invoice = iterator_to_array($mapper->records('received'))[0];
        self::assertSame(['id' => '', 'number' => 'TEST-B1'], $invoice['liquidations']['liquidation'][0]['sourceDocument']);
        self::assertSame('', iterator_to_array($mapper->records('bank'))[0]['bankHeader']['symPar']);
    }

    /**
     * Časové rozlišení (RelUdAg 174) je běžná agenda deníku; neznámá agenda převod
     * nezastaví, řádek přejde pod označením agendy a krok deníku to ohlásí.
     */
    public function testAccrualsAndUnknownJournalAgendaAreImported(): void
    {
        $mapper = $this->mapper(['pUD' => [
            ['ID' => '1', 'RelUdAg' => '174', 'Cislo' => 'TEST-CR1', 'Datum' => '2026-03-31', 'UMD' => '548', 'UD' => '381', 'Kc' => '100'],
            ['ID' => '2', 'RelUdAg' => '999', 'Cislo' => 'TEST-X1', 'Datum' => '2026-03-31', 'UMD' => '518', 'UD' => '321', 'Kc' => '50'],
        ]]);
        $rows = iterator_to_array($mapper->records('journal'));
        self::assertSame(PohodaJournal::ACCRUALS, $rows[0]['source']);
        self::assertSame(PohodaJournal::UNKNOWN_AGENDA_PREFIX . '999', $rows[1]['source']);
        self::assertSame('manual', PohodaJournal::sourceType($rows[0]['source']));
    }

    #[DataProvider('unknownTypes')]
    public function testUnknownTypesFailInsteadOfGuessing(array $tables, string $key, string $code): void
    {
        try {
            $mapper = $this->mapper($tables);
            iterator_to_array($mapper->records($key));
            self::fail('Unknown source type must be rejected.');
        } catch (PohodaException $error) {
            self::assertSame($code, $error->errorCode);
        }
    }

    public static function unknownTypes(): iterable
    {
        yield 'historical VAT' => [['FA' => [['ID' => '1', 'Cislo' => 'TEST-F1', 'RelTpFak' => '1', 'HistSzDPH' => 'true']]], 'issued', 'mdb_historical_vat'];
    }

    public function testUnknownDocumentAndPaymentTypesAreReportedAndDoNotStopOtherRecords(): void
    {
        $mapper = $this->mapper([
            'FA' => [
                ['ID' => '1', 'Cislo' => 'TEST-UNKNOWN', 'RelTpFak' => '999', 'Datum' => '2026-02-03'],
                ['ID' => '2', 'Cislo' => 'TEST-PAYMENT', 'RelTpFak' => '11', 'Datum' => '2026-02-04'],
                ['ID' => '3', 'Cislo' => 'TEST-OK', 'RelTpFak' => '11', 'Datum' => '2026-02-05', 'Kc0' => '100'],
            ],
            'Uhrady' => [['ID' => '1', 'RelIDH' => '2', 'RelAgH' => '3', 'RelAgU' => '999', 'KcU' => '100']],
            'BV' => [
                ['ID' => '1', 'RelTpBV' => '999', 'Cislo' => 'TEST-BAD-BANK'],
                ['ID' => '2', 'RelTpBV' => '2', 'Cislo' => 'TEST-OK-BANK', 'Kc0' => '100'],
            ],
            'pUD' => [['ID' => '1', 'RelUdAg' => '3', 'Cislo' => 'TEST-UNKNOWN', 'Kc' => '100', 'UMD' => '518', 'UD' => '321']],
        ]);
        $agenda = $this->dir . '/12345678_2026';
        mkdir($agenda);
        copy($this->path, $agenda . '/' . PohodaMdbAccounting::FILE);
        $export = PohodaExport::open($agenda);
        self::assertSame(1, $export->counts()['purchase']);
        self::assertSame(1, $export->counts()['bank']);
        self::assertSame('TEST-OK', iterator_to_array($export->records('received', 'invoice'), false)[0]['invoiceHeader']['number']['numberRequested']);
        self::assertCount(1, iterator_to_array($export->records('journal', 'accountingItem'), false));
        $warnings = $export->sourceWarnings();
        self::assertCount(3, $warnings);
        self::assertSame(['mdb_invoice_type', 'mdb_payment_source', 'mdb_direction'], array_column($warnings, 'code'));
        self::assertSame('999', $warnings[0]['context']['source_type']);
        self::assertSame('TEST-UNKNOWN', $warnings[0]['context']['document_no']);
        self::assertSame($warnings, $export->sourceWarnings());
    }

    public function testUnknownXmlDocumentTypesAreReportedBeforeYieldAndOtherRecordsContinue(): void
    {
        $agenda = $this->dir . '/12345678_2026';
        mkdir($agenda);
        file_put_contents($agenda . '/' . PohodaExport::FILES['issued'], '<root><invoice><invoiceHeader><invoiceType>unknownType</invoiceType><number><numberRequested>TEST-UNKNOWN</numberRequested></number></invoiceHeader></invoice><invoice><invoiceHeader><invoiceType>issuedInvoice</invoiceType><number><numberRequested>TEST-OK</numberRequested></number></invoiceHeader></invoice></root>');
        file_put_contents($agenda . '/' . PohodaExport::FILES['bank'], '<root><bank><bankHeader><bankType>unknownDirection</bankType><number>TEST-BAD</number></bankHeader></bank><bank><bankHeader><bankType>expense</bankType><number>TEST-OK-BANK</number></bankHeader></bank></root>');
        file_put_contents($agenda . '/' . PohodaExport::FILES['journal'], '<root/>');
        file_put_contents($agenda . '/' . PohodaExport::FILES['chart'], '<root/>');
        file_put_contents($agenda . '/' . PohodaExport::FILES['vat_classes'], '<root/>');
        $export = PohodaExport::open($agenda);
        self::assertCount(1, iterator_to_array($export->records('issued', 'invoice'), false));
        self::assertCount(1, iterator_to_array($export->records('bank', 'bank'), false));
        $warnings = $export->sourceWarnings();
        self::assertCount(2, $warnings);
        self::assertSame('unknownType', $warnings[0]['context']['source_type']);
        self::assertSame('unknownDirection', $warnings[1]['context']['source_type']);
    }

    public function testZipAutomaticallySelectsMdbReaderAndProducesOverview(): void
    {
        $this->mapper([
            'pUD' => [['ID' => '1', 'RelUdAg' => '2', 'Datum' => '2026-02-03', 'Cislo' => 'TEST-F1', 'UMD' => '311000', 'UD' => '602000', 'Kc' => '100']],
            'FA' => [['ID' => '1', 'RelTpFak' => '1', 'Cislo' => 'TEST-F1', 'Datum' => '2026-02-03', 'Kc2' => '100', 'KcDPH2' => '21']],
            'FApol' => [['ID' => '1', 'RefAg' => '1', 'RelSzDPH' => '2', 'Kc' => '100', 'KcDPH' => '21']],
        ]);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->dir . '/source.zip', \ZipArchive::CREATE));
        $zip->addFile($this->path, '12345678_2026/' . PohodaMdbAccounting::FILE);
        $zip->close();
        PohodaExport::extractArchive($this->dir . '/source.zip', $this->dir . '/unpacked');
        $overview = PohodaExport::overview($this->dir . '/unpacked');
        self::assertCount(1, $overview);
        self::assertSame('12345678', $overview[0]['ico']);
        self::assertSame(2026, $overview[0]['year']);
        self::assertSame(1, $overview[0]['counts']['journal']);
        self::assertSame(1, $overview[0]['counts']['issued']);
        self::assertSame('2026-02-03', $overview[0]['counts']['first_date']);
        self::assertTrue($overview[0]['has_accounting']);
        $export = PohodaExport::open($this->dir . '/unpacked/12345678_2026');
        $first = iterator_to_array($export->records('issued', 'invoice'));
        self::assertSame($first, iterator_to_array($export->records('issued', 'invoice')));
        self::assertSame('121', $first[0]['invoiceSummary']['homeCurrency']['priceHighSum']);
        self::assertSame('121', $first[0]['invoiceDetail']['invoiceItem'][0]['homeCurrency']['priceSum']);
    }

    /**
     * Výkon: otevření agendy ověří strukturu i doklady jedním průchodem souborem a přehled
     * nahrání (deník a počty všech agend) přidá nejvýš dva další, ne průchod na každou
     * tabulku a druh faktury.
     */
    public function testOpenAndOverviewCountsReadFileInFewPasses(): void
    {
        $this->mapper([
            'pUD' => [
                ['ID' => '1', 'RelUdAg' => '2', 'Datum' => '2026-02-03', 'Cislo' => 'TEST-F1', 'UMD' => '311000', 'UD' => '602000', 'Kc' => '100'],
                ['ID' => '2', 'RelUdAg' => '63', 'Datum' => '2026-01-01', 'UMD' => '221000', 'UD' => '701000', 'Kc' => '50'],
            ],
            'FA' => [
                ['ID' => '1', 'RelTpFak' => '1', 'Cislo' => 'TEST-F1', 'Datum' => '2026-02-03', 'Kc2' => '100', 'KcDPH2' => '21'],
                ['ID' => '2', 'RelTpFak' => '11', 'Cislo' => 'TEST-P1', 'Datum' => '2026-02-04', 'Kc2' => '200', 'KcDPH2' => '42'],
                ['ID' => '3', 'RelTpFak' => '15', 'Cislo' => 'TEST-Z1', 'Datum' => '2026-02-05', 'Kc0' => '10'],
            ],
            'FApol' => [['ID' => '1', 'RefAg' => '1', 'RelSzDPH' => '2', 'Kc' => '100', 'KcDPH' => '21']],
            'BV' => [['ID' => '1', 'RelTpBV' => '1', 'Cislo' => 'TEST-B1', 'Datum' => '2026-02-10', 'Vypis' => '1/1']],
            'HO' => [['ID' => '1', 'RelTpHO' => '2', 'Cislo' => 'TEST-H1', 'Datum' => '2026-02-11']],
            'pINT' => [['ID' => '1', 'Cislo' => 'TEST-I1', 'Datum' => '2026-02-12']],
            'AD' => [['ID' => '1', 'Firma' => 'Testovací odběratel']],
        ]);
        $agenda = $this->dir . '/12345678_2026';
        mkdir($agenda);
        copy($this->path, $agenda . '/' . PohodaMdbAccounting::FILE);

        $export = PohodaExport::open($agenda);
        $mdb = (new \ReflectionProperty(PohodaExport::class, 'mdb'))->getValue($export);
        self::assertInstanceOf(PohodaMdbAccounting::class, $mdb);
        // Před opravou: úvodní průchod + firma a rok + pět tabulek dokladů = 7.
        self::assertSame(1, $mdb->tables->passes());

        $counts = $export->counts();
        self::assertSame(
            ['journal' => 2, 'opening' => 1, 'first_date' => '2026-02-03', 'last_date' => '2026-02-03', 'later_years' => [],
                'issued' => 1, 'purchase' => 2, 'internal' => 1, 'cash' => 1, 'bank' => 1, 'partners' => 1],
            $counts,
        );
        // Před opravou: deník + sedm druhů faktur zvlášť + každý číselník a položky zvlášť (přes 20).
        self::assertLessThanOrEqual(3, $mdb->tables->passes());
        self::assertSame(['TEST-P1'], array_map(
            static fn (array $invoice): string => $invoice['invoiceHeader']['number']['numberRequested'],
            iterator_to_array($export->records('received', 'invoice'), false),
        ));
    }

    public function testMixedMdbAndOfficialXmlIsRejected(): void
    {
        $this->mapper([]);
        $agenda = $this->dir . '/12345678_2026';
        mkdir($agenda);
        copy($this->path, $agenda . '/' . PohodaMdbAccounting::FILE);
        file_put_contents($agenda . '/' . PohodaExport::FILES['journal'], '<root/>');
        $this->expectException(PohodaException::class);
        $this->expectExceptionMessage('současně MDB');
        PohodaExport::open($agenda);
    }

    public function testDirectoryMetadataMismatchIsRejected(): void
    {
        $this->mapper([]);
        $agenda = $this->dir . '/12345678_2025';
        mkdir($agenda);
        copy($this->path, $agenda . '/' . PohodaMdbAccounting::FILE);
        $this->expectException(PohodaException::class);
        $this->expectExceptionMessage('Složka agendy neodpovídá');
        PohodaExport::open($agenda);
    }

    public function testSourceMetadataMismatchIsRejected(): void
    {
        $this->expectException(PohodaException::class);
        $this->expectExceptionMessage('údajům zdrojové MDB');
        $this->mapper(['sKonfig' => [['ICO' => '12345678', 'Rok' => '2025']]]);
    }

    public function testUnsupportedHistoricalDateIsRejectedBeforeImport(): void
    {
        $this->expectException(PohodaException::class);
        $this->expectExceptionMessage('staršími sazbami');
        $this->mapper(['FA' => [['ID' => '1', 'Cislo' => 'TEST-OLD', 'RelTpFak' => '1', 'Datum' => '2012-12-31', 'Kc2' => '100']]]);
    }

    private function mapper(array $data): PohodaMdbAccounting
    {
        $tables = array_fill_keys(['pUD', 'pOS', 'sDPH', 'sDPHTp', 'pPK', 'FA', 'FApol', 'AD', 'sUcet', 'sZeme', 'sCMeny', 'sFormUh', 'BV', 'BVpol', 'HO', 'HOpol', 'pINT', 'pINTpol', 'Uhrady'], []);
        $tables['sKonfig'] = [['ICO' => '12345678', 'Rok' => '2026']];
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startElement('pohodaMdbAccounting');
        foreach (['formatVersion' => '1', 'ico' => '12345678', 'year' => '2026', 'programVersion' => 'test'] as $key => $value) {
            $xml->writeAttribute($key, $value);
        }
        foreach (array_replace($tables, $data) as $name => $rows) {
            $xml->startElement('table');
            $xml->writeAttribute('name', $name);
            foreach ($rows as $row) {
                $xml->startElement('row');
                foreach ($row as $key => $value) {
                    $xml->writeElement($key, $value);
                }
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
        file_put_contents($this->path, $xml->outputMemory());
        return new PohodaMdbAccounting(new PohodaMdbTables($this->path));
    }
}
