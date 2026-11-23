<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaJournal;
use MyInvoice\Service\Migration\Pohoda\PohodaMdbAccounting;
use MyInvoice\Service\Migration\Pohoda\PohodaMdbTables;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaMdbExport as Mdb;
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

    /**
     * Řádek deníku nese id zdrojového dokladu (`RelAgID`, u banky id bankovního dokladu).
     * Podle něj se pozná, kterému z pohybů se stejným číslem řádek patří; 0 = bez dokladu.
     */
    public function testJournalCarriesSourceDocumentId(): void
    {
        $rows = iterator_to_array($this->mapper(['pUD' => [
            ['ID' => '1', 'RelUdAg' => '28', 'RelAgID' => '29180', 'Cislo' => 'B1', 'UMD' => '261000', 'UD' => '221000', 'Kc' => '100'],
            ['ID' => '2', 'RelUdAg' => '28', 'RelAgID' => '0', 'Cislo' => 'B1', 'UMD' => '568000', 'UD' => '221000', 'Kc' => '1'],
            ['ID' => '3', 'RelUdAg' => '28', 'Cislo' => 'B1', 'UMD' => '568000', 'UD' => '221000', 'Kc' => '1'],
        ]])->records('journal'), false);

        self::assertSame(['29180', '', ''], array_map(static fn (array $r): string => PohodaXml::text($r, 'sourceId'), $rows));
    }

    /**
     * Zakázky (`sZAK`) ve tvaru `con:contractDesc` z XML a číslo zakázky (`CisloZAK`) na řádku
     * deníku, hlavičce i položce dokladu jako `typ:contract`. Řádek bez čísla zakázky se
     * vynechá, RefAD 0 je zakázka bez partnera a export ze staršího převodníku zakázky nenese.
     */
    public function testContractsAndContractNumbersOfJournalAndDocuments(): void
    {
        $mapper = $this->mapper([
            'sZAK' => [
                ['ID' => '1', 'Cislo' => 'Z-01', 'SText' => 'Stavba', 'RefAD' => '7', 'Predani' => '2026-05-31T00:00:00'],
                ['ID' => '2', 'Cislo' => 'Z-02', 'SText' => 'Vývoj', 'RefAD' => '0'],
                ['ID' => '3', 'Cislo' => ''],
            ],
            'pUD' => [
                ['ID' => '1', 'RelUdAg' => '2', 'Cislo' => 'F1', 'UMD' => '311000', 'UD' => '602000', 'Kc' => '100', 'CisloZAK' => 'Z-01'],
                ['ID' => '2', 'RelUdAg' => '2', 'Cislo' => 'F1', 'UMD' => '311000', 'UD' => '343000', 'Kc' => '21'],
            ],
            'FA' => [['ID' => '5', 'Cislo' => 'F1', 'RelTpFak' => '1', 'Kc2' => '100', 'KcDPH2' => '21', 'CisloZAK' => 'Z-01']],
            'FApol' => [
                ['ID' => '51', 'RefAg' => '5', 'SText' => 'Práce', 'RelSzDPH' => '2', 'Kc' => '60', 'KcDPH' => '12.6', 'CisloZAK' => 'Z-02'],
                ['ID' => '52', 'RefAg' => '5', 'SText' => 'Materiál', 'RelSzDPH' => '2', 'Kc' => '40', 'KcDPH' => '8.4'],
            ],
        ]);

        $contracts = iterator_to_array($mapper->records('contracts'), false);
        self::assertCount(2, $contracts);
        self::assertSame(['Z-01', 'Stavba', '7', '2026-05-31'], [
            PohodaXml::text($contracts[0], 'contractDesc/number/numberRequested'), PohodaXml::text($contracts[0], 'contractDesc/text'),
            PohodaXml::text($contracts[0], 'contractDesc/partnerIdentity/id'), PohodaXml::text($contracts[0], 'contractDesc/dateDelivery'),
        ]);
        self::assertSame('', PohodaXml::text($contracts[1], 'contractDesc/partnerIdentity/id'));
        self::assertSame(['Z-01', ''], array_map(static fn (array $r): string => PohodaXml::text($r, 'contract/ids'), iterator_to_array($mapper->records('journal'), false)));
        $invoice = iterator_to_array($mapper->records('issued'), false)[0];
        self::assertSame('Z-01', PohodaXml::text($invoice, 'invoiceHeader/contract/ids'));
        self::assertSame(['Z-02', ''], array_map(static fn (array $i): string => PohodaXml::text($i, 'contract/ids'), PohodaXml::all($invoice, 'invoiceDetail/invoiceItem')));
        self::assertSame([], iterator_to_array($this->mapper([])->records('contracts'), false));
    }

    /**
     * Činnosti (`sCIN`) ve tvaru `acv:activityHeader` z XML a odkaz na činnost (`RefCin`) na řádku
     * deníku, hlavičce i položce dokladu jako `typ:activity` se zkratkou a názvem. RefCin 0
     * i odkaz na činnost, kterou číselník nemá, je bez činnosti; export ze staršího převodníku
     * (bez `sCIN`) činnost nenese, i když sloupec RefCin má.
     */
    public function testActivitiesAndActivityReferencesOfJournalAndDocuments(): void
    {
        $mapper = $this->mapper([
            'sCIN' => [
                ['ID' => '2', 'IDS' => 'PRODEJ', 'SText' => 'Prodej služeb', 'RelTypDP' => '3'],
                ['ID' => '4', 'IDS' => 'SERVIS', 'SText' => 'Servis'],
                ['ID' => '5', 'IDS' => ''],
            ],
            'pUD' => [
                ['ID' => '1', 'RelUdAg' => '2', 'Cislo' => 'F1', 'UMD' => '311000', 'UD' => '602000', 'Kc' => '100', 'RefCin' => '2'],
                ['ID' => '2', 'RelUdAg' => '2', 'Cislo' => 'F1', 'UMD' => '311000', 'UD' => '343000', 'Kc' => '21', 'RefCin' => '0'],
                ['ID' => '3', 'RelUdAg' => '2', 'Cislo' => 'F1', 'UMD' => '311000', 'UD' => '343000', 'Kc' => '1', 'RefCin' => '99'],
            ],
            'FA' => [['ID' => '5', 'Cislo' => 'F1', 'RelTpFak' => '1', 'Kc2' => '100', 'KcDPH2' => '21', 'RefCin' => '2']],
            'FApol' => [
                ['ID' => '51', 'RefAg' => '5', 'SText' => 'Práce', 'RelSzDPH' => '2', 'Kc' => '60', 'KcDPH' => '12.6', 'RefCin' => '4'],
                ['ID' => '52', 'RefAg' => '5', 'SText' => 'Materiál', 'RelSzDPH' => '2', 'Kc' => '40', 'KcDPH' => '8.4'],
            ],
        ]);

        $activities = iterator_to_array($mapper->records('activities'), false);
        self::assertSame([['PRODEJ', 'Prodej služeb'], ['SERVIS', 'Servis']], array_map(
            static fn (array $r): array => [PohodaXml::text($r, 'activityHeader/code'), PohodaXml::text($r, 'activityHeader/name')],
            $activities,
        ));
        $journal = iterator_to_array($mapper->records('journal'), false);
        self::assertSame(['PRODEJ', '', ''], array_map(static fn (array $r): string => PohodaXml::text($r, 'activity/ids'), $journal));
        self::assertSame('Prodej služeb', PohodaXml::text($journal[0], 'activity/name'));
        $invoice = iterator_to_array($mapper->records('issued'), false)[0];
        self::assertSame('PRODEJ', PohodaXml::text($invoice, 'invoiceHeader/activity/ids'));
        self::assertSame(['SERVIS', ''], array_map(static fn (array $i): string => PohodaXml::text($i, 'activity/ids'), PohodaXml::all($invoice, 'invoiceDetail/invoiceItem')));

        $older = $this->mapper(['pUD' => [['ID' => '1', 'RelUdAg' => '2', 'Cislo' => 'F1', 'UMD' => '311000', 'UD' => '602000', 'Kc' => '100', 'RefCin' => '2']]]);
        self::assertSame([], iterator_to_array($older->records('activities'), false));
        self::assertSame('', PohodaXml::text(iterator_to_array($older->records('journal'), false)[0], 'activity/ids'));
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

    public function testAdvanceInstallmentsCarryTheirOwnTaxDocumentReferences(): void
    {
        $export = PohodaExport::open(Mdb::writeTaxDocumentPayments($this->dir));
        $advance = iterator_to_array($export->records('issued_advance', 'invoice'))[0];
        $payments = PohodaXml::all($advance, 'liquidations/liquidation');
        self::assertSame(['101', '102'], array_column($payments, 'taxDocumentRef'));
        self::assertSame(['605', '363'], array_column($payments, 'amount'));

        $internal = iterator_to_array($export->records('internal', 'intDoc'));
        self::assertSame(Mdb::ISSUED_TAX_DOCUMENTS, array_map(
            static fn (array $record): string => PohodaXml::text($record, 'intDocHeader/number/numberRequested'),
            array_slice($internal, 0, 2),
        ));
        self::assertSame(['101', '102'], array_column(array_column(array_slice($internal, 0, 2), 'intDocHeader'), 'id'));
        self::assertTrue($export->hasTaxDocumentPaymentRefs());
    }

    public function testRefDDColumnIsRecognizedEvenWhenNull(): void
    {
        $dir = Mdb::write($this->dir, ['Uhrady' => [['ID' => '1', 'RefDD' => '0']]]);
        $file = $dir . '/' . PohodaMdbAccounting::FILE;
        file_put_contents($file, str_replace('<RefDD>0</RefDD>', '<RefDD nil="true"/>', file_get_contents($file)));

        self::assertTrue(PohodaExport::open($dir)->hasTaxDocumentPaymentRefs());
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
        yield 'historical VAT not matching amounts' => [['FA' => [['ID' => '1', 'Cislo' => 'TEST-F1', 'RelTpFak' => '1', 'Datum' => '2024-06-14',
            'HistSzDPH' => 'true', 'Kc1' => '1000', 'KcDPH1' => '50']]], 'issued', 'mdb_historical_vat'];
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
            'sZAK' => [['ID' => '1', 'Cislo' => 'Z-01', 'SText' => 'Testovací zakázka']],
            'sCIN' => [['ID' => '1', 'IDS' => 'C-01', 'SText' => 'Testovací činnost'], ['ID' => '2', 'IDS' => 'C-02', 'SText' => 'Druhá činnost']],
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
                'issued' => 1, 'purchase' => 2, 'internal' => 1, 'cash' => 1, 'bank' => 1, 'partners' => 1, 'contracts' => 1, 'activities' => 2],
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

    public function testDocumentCarriedOverFromOldYearTakesRatesValidOnItsTaxDate(): void
    {
        // POHODA převádí do nového roku i neuhrazené doklady s původním datem.
        $mapper = $this->mapper([
            'FA' => [['ID' => '1', 'Cislo' => 'TEST-OLD', 'RelTpFak' => '1', 'Datum' => '2012-12-31', 'DatZdPln' => '2012-12-31',
                'Kc1' => '100', 'KcDPH1' => '14', 'Kc2' => '200', 'KcDPH2' => '40']],
            'FApol' => [['ID' => '1', 'RefAg' => '1', 'RelSzDPH' => '2', 'Kc' => '200', 'KcDPH' => '40', 'KcJedn' => '200', 'Mnozstvi' => '1']],
        ]);
        $invoice = iterator_to_array($mapper->records('issued'))[0];
        $summary = $invoice['invoiceSummary']['homeCurrency'];
        self::assertSame('14', $summary['priceLowVAT']['@rate']);
        self::assertSame('20', $summary['priceHighVAT']['@rate']);
        self::assertSame('20', $invoice['invoiceDetail']['invoiceItem'][0]['rateVAT']['@value']);
    }

    /**
     * Přijatý doklad má DUZP z dokladu dodavatele v „Datum KH DPH" (`DatKHDPH`), `DatZdPln`
     * je datum odpočtu. Sazby platí ke dni plnění: faktura s plněním 28. 12. 2023 a odpočtem
     * v lednu 2024 má sníženou sazbu 15 %, ne 12 %. Vydaná faktura `DatKHDPH` nepoužívá.
     */
    public function testReceivedDocumentTakesRatesValidOnSupplierDuzp(): void
    {
        $row = ['Datum' => '2024-01-03', 'DatZdPln' => '2024-01-03', 'DatKHDPH' => '2023-12-28', 'Kc1' => '100', 'KcDPH1' => '15'];
        $mapper = $this->mapper([
            'FA' => [['ID' => '1', 'Cislo' => 'TEST-PF', 'RelTpFak' => '11'] + $row, ['ID' => '2', 'Cislo' => 'TEST-FV', 'RelTpFak' => '1'] + $row],
        ]);
        $received = iterator_to_array($mapper->records('received'))[0];
        self::assertSame('15', $received['invoiceSummary']['homeCurrency']['priceLowVAT']['@rate']);
        self::assertSame(['2024-01-03', '2023-12-28'], [$received['invoiceHeader']['dateTax'], $received['invoiceHeader']['dateKHDPH']]);
        $issued = iterator_to_array($mapper->records('issued'))[0];
        self::assertSame('12', $issued['invoiceSummary']['homeCurrency']['priceLowVAT']['@rate']);
    }

    /**
     * Zaškrtnuté historické sazby: sazby se určí z částek slotů. Vyúčtování zálohy z roku
     * 2023 má odpočet zálohy v historické sazbě 15 % a hlavičku v sazbě 12 % roku 2024;
     * doklad s DPH předchozích sazeb (15 %) je dostane i na položky bez vlastního procenta.
     */
    public function testHistoricalVatFlagTakesRatesMatchingAmounts(): void
    {
        $row = ['RelTpFak' => '1', 'Datum' => '2024-06-14', 'DatZdPln' => '2024-06-14', 'HistSzDPH' => 'true'];
        $mapper = $this->mapper([
            'FA' => [
                ['ID' => '1', 'Cislo' => 'TEST-CURRENT', 'Kc1' => '100000', 'KcDPH1' => '12000'] + $row,
                ['ID' => '2', 'Cislo' => 'TEST-PREVIOUS', 'Kc1' => '1000', 'KcDPH1' => '150', 'Kc2' => '100', 'KcDPH2' => '21'] + $row,
                ['ID' => '3', 'Cislo' => 'TEST-NO-VAT', 'Kc0' => '500'] + $row,
                ['ID' => '4', 'Cislo' => 'TEST-ADVANCE', 'RelTpFak' => '4', 'Datum' => '2023-11-20', 'Kc0' => '10000'],
            ],
            'FApol' => [
                ['ID' => '1', 'RefAg' => '2', 'RelSzDPH' => '1', 'Kc' => '1000', 'KcDPH' => '150', 'KcJedn' => '1000', 'Mnozstvi' => '1'],
                ['ID' => '2', 'RefAg' => '1', 'RelSzDPH' => '1', 'Kc' => '100000', 'KcDPH' => '12000', 'KcJedn' => '100000', 'Mnozstvi' => '1'],
                ['ID' => '3', 'RefAg' => '1', 'RelAgID' => '43', 'RefPol' => '4', 'RelSzDPH' => '101', 'ProcentoDPH' => '15', 'Kc' => '-10000', 'KcDPH' => '-1500', 'KcJedn' => '-10000', 'Mnozstvi' => '1'],
            ],
        ]);
        $invoices = iterator_to_array($mapper->records('issued'), false);
        $rates = array_map(static fn (array $i): array => [$i['invoiceSummary']['homeCurrency']['priceLowVAT']['@rate'], $i['invoiceSummary']['homeCurrency']['priceHighVAT']['@rate']], $invoices);
        self::assertSame([['12', '21'], ['15', '21'], ['12', '21']], $rates);
        self::assertSame('15', $invoices[1]['invoiceDetail']['invoiceItem'][0]['rateVAT']['@value']);
        self::assertSame('12', $invoices[0]['invoiceDetail']['invoiceItem'][0]['rateVAT']['@value']);
        self::assertSame('15', $invoices[0]['invoiceDetail']['invoiceAdvancePaymentItem'][0]['rateVAT']['@value']);
    }

    public function testAccessEmptyTaxDateFallsBackToDocumentDate(): void
    {
        $mapper = $this->mapper([
            'FA' => [['ID' => '1', 'Cislo' => 'TEST-F1', 'RelTpFak' => '1', 'Datum' => '2026-03-01', 'DatZdPln' => '1899-12-30', 'Kc1' => '100', 'KcDPH1' => '12']],
        ]);
        $invoice = iterator_to_array($mapper->records('issued'))[0];
        self::assertSame('12', $invoice['invoiceSummary']['homeCurrency']['priceLowVAT']['@rate']);
        self::assertSame('', $invoice['invoiceHeader']['dateTax']);
    }

    public function testOldDocumentWithoutVatIsAccepted(): void
    {
        $mapper = $this->mapper([
            'FA' => [['ID' => '1', 'Cislo' => 'TEST-OLD', 'RelTpFak' => '1', 'Datum' => '2003-12-31', 'Kc0' => '100']],
        ]);
        self::assertCount(1, iterator_to_array($mapper->records('issued')));
    }

    public function testVatBeforeMay2004IsRejectedWithDocumentNumber(): void
    {
        $this->expectException(PohodaException::class);
        $this->expectExceptionMessage('Doklad TEST-OLD (31. 12. 2003) má DPH ze sazeb platných před 1. 5. 2004');
        $this->mapper(['FA' => [['ID' => '1', 'Cislo' => 'TEST-OLD', 'RelTpFak' => '1', 'Datum' => '2003-12-31', 'Kc2' => '100', 'KcDPH2' => '22']]]);
    }

    public function testSecondReducedRateBefore2015IsRejected(): void
    {
        $this->expectException(PohodaException::class);
        $this->expectExceptionMessage('Doklad TEST-OLD (30. 6. 2014) má částku v druhé snížené sazbě');
        $this->mapper(['FA' => [['ID' => '1', 'Cislo' => 'TEST-OLD', 'RelTpFak' => '1', 'Datum' => '2014-06-30', 'Kc3' => '100', 'KcDPH3' => '10']]]);
    }

    /**
     * Objednávky (`OBJ`, `OBJpol`): druh podle `RelTpObj` (2 vydaná, 1 přijatá, jiný neznámý),
     * příznak vyřízení, storno, dodané množství a karta položky, měna z číselníku a partner
     * ve tvaru identity dokladu. Export ze staršího převodníku objednávky nenese.
     */
    public function testOrdersCarryTypeFulfilmentDeliveredQuantityAndCard(): void
    {
        self::assertFalse($this->mapper([])->hasOrders());
        self::assertSame([], iterator_to_array($this->mapper([])->orders()));

        $orders = iterator_to_array($this->mapper([
            'sCMeny' => [['ID' => '9', 'Kod' => 'eur']],
            'sZeme' => [['ID' => '1', 'IDS' => 'CZ']],
            'OBJ' => [
                ['ID' => '1', 'Cislo' => 'OV1', 'RelTpObj' => '2', 'Datum' => '2026-03-02T00:00:00', 'DatDod' => '2026-03-20T00:00:00', 'Vyrizeno' => 'false',
                    'RefAD' => '5', 'Firma' => 'Dodavatel Test s.r.o.', 'ICO' => '12345679', 'RefZeme' => '1', 'RefCM' => '9', 'CmKurs' => '25.1', 'CmMnoz' => '1'],
                ['ID' => '2', 'Cislo' => 'OP1', 'RelTpObj' => '1', 'Datum' => '2026-03-03T00:00:00', 'Vyrizeno' => 'true', 'RefAD' => '0', 'DatStorn' => '1899-12-30T00:00:00'],
                ['ID' => '3', 'Cislo' => 'OX1', 'RelTpObj' => '7', 'DatStorn' => '2026-03-05T00:00:00'],
            ],
            'OBJpol' => [
                ['ID' => '11', 'RefAg' => '1', 'RefSKz' => '0', 'SText' => 'Doprava', 'Mnozstvi' => '1', 'Kc' => '150', 'OrderFld' => '2'],
                ['ID' => '10', 'RefAg' => '1', 'RefSKz' => '101', 'Kod' => 'K-001', 'SText' => 'Kabel', 'Mnozstvi' => '10', 'Dodano' => '4', 'MJ' => 'm', 'MJKoef' => '1',
                    'Kc' => '950', 'ProcentoDPH' => '21', 'SDph' => 'false', 'Cm' => '37.85', 'OrderFld' => '1'],
            ],
        ])->orders(), false);

        self::assertSame(['issued', 'received', null], array_column($orders, 'type'));
        self::assertSame([false, true, false], array_column($orders, 'fulfilled'));
        self::assertSame([false, false, true], array_column($orders, 'cancelled'));
        self::assertSame(['2026-03-20', 'EUR', 25.1], [$orders[0]['delivery'], $orders[0]['currency'], $orders[0]['rate']]);
        self::assertSame(['5', 'Dodavatel Test s.r.o.', '12345679', 'CZ'], [$orders[0]['partner']['id'], $orders[0]['partner']['address']['company'],
            $orders[0]['partner']['address']['ico'], $orders[0]['partner']['address']['country']['ids']]);
        self::assertSame('', $orders[1]['partner']['id']);
        $items = $orders[0]['items'];
        self::assertSame(['101', ''], array_column($items, 'card'));
        self::assertSame([[10.0, 4.0, 950.0, 21.0, 37.85], [1.0, 0.0, 150.0, null, null]],
            array_map(static fn (array $i): array => [$i['quantity'], $i['delivered'], $i['price'], $i['vat_percent'], $i['foreign_price']], $items));
        self::assertSame([], $orders[1]['items']);
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
