<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaJournal;
use MyInvoice\Service\Migration\Pohoda\PohodaVat;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaMdbExport;
use PHPUnit\Framework\TestCase;

/**
 * Čtení XML exportu z POHODY: strany deníku, členění DPH, proudové čtení záznamů
 * a bezpečné rozbalení ZIP exportu.
 */
final class PohodaExportTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_unit_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    /** POHODA pojmenovává strany obráceně: `act:credit` je MD, `act:debit` Dal. */
    public function testJournalSidesFollowPohodaNaming(): void
    {
        $item = ['homeCurrency' => ['priceSum' => '500'], 'accounting' => ['credit' => '518000', 'debit' => '321001']];
        self::assertSame(['debit' => '518000', 'credit' => '321001', 'amount' => 500.0], PohodaJournal::effect($item));

        $negative = ['homeCurrency' => ['priceSum' => '-80'], 'accounting' => ['credit' => '221001', 'debit' => '311001']];
        self::assertSame(['debit' => '311001', 'credit' => '221001', 'amount' => 80.0], PohodaJournal::effect($negative));

        $same = ['homeCurrency' => ['priceSum' => '10'], 'accounting' => ['credit' => '349000', 'debit' => '349000']];
        self::assertNull(PohodaJournal::effect($same));
        self::assertSame(PohodaJournal::OPENING_KEY, PohodaJournal::groupKey(['source' => PohodaJournal::OPENING]));
    }

    public function testExportIsReadInWindows1250AndSidesAreSummed(): void
    {
        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp));
        self::assertSame(SyntheticPohodaExport::ICO, $export->ico);
        self::assertSame(SyntheticPohodaExport::YEAR, $export->year);

        $sources = [];
        foreach ($export->records('journal', 'accountingItem') as $item) {
            $sources[PohodaJournal::source($item)] = true;
        }
        self::assertArrayHasKey('Počáteční stavy účtů', $sources, 'Diakritika z Windows-1250 musí projít do UTF-8.');

        $tb = PohodaJournal::trialBalance($export);
        self::assertSame([100000.0, 555.0, 100555.0], $tb['221']);
        self::assertSame([0.0, 500.0, 500.0], $tb['518']);
    }

    /** Členění se páruje podle řádků přiznání v číselníku jednotky, ne podle zkratky. */
    public function testVatClassificationByReturnLines(): void
    {
        $vat = PohodaVat::fromExport(PohodaExport::open(SyntheticPohodaExport::write($this->tmp)));
        self::assertSame(['in_return' => true, 'code' => null], $vat->sale('UD', 21.0));
        self::assertSame(['in_return' => false, 'code' => null], $vat->sale('UN', 0.0));
        self::assertSame(['in_return' => true, 'deduction' => 'full', 'reverse' => false, 'fixed_asset' => false], $vat->purchase('PD'));
        self::assertSame(['in_return' => true, 'deduction' => 'full', 'reverse' => true, 'fixed_asset' => false], $vat->purchase('PDslRegEU'));
        self::assertSame('24e', $vat->selfAssessmentCode('DDslRegEU'));
        self::assertTrue($vat->forcesA5('UDA5'));
        self::assertFalse($vat->forcesA5('UD'));
        self::assertNull($vat->sale('NEZNAME', 21.0));
    }

    public function testAccountingDateTypoIsCorrectedInTheDocumentAndJournal(): void
    {
        $dir = SyntheticPohodaExport::write($this->tmp);
        $cash = $dir . '/' . PohodaExport::FILES['cash'];
        file_put_contents($cash, '<root>'
            . '<voucher><voucherHeader><number><numberRequested>TEST-POK-1</numberRequested></number><voucherType>receipt</voucherType>'
            . '<date>2026-09-01</date><dateAccounting>6026-09-01</dateAccounting><dateTax>2026-08-31</dateTax></voucherHeader></voucher>'
            . '<voucher><voucherHeader><number><numberRequested>TEST-POK-2</numberRequested></number><voucherType>receipt</voucherType>'
            . '<date>2026-09-01</date><dateAccounting>2026-09-03</dateAccounting></voucherHeader></voucher></root>');
        file_put_contents($dir . '/' . PohodaExport::FILES['journal'], '<responsePack ico="' . SyntheticPohodaExport::ICO . '" state="ok">'
            . '<accountingItem><source>Pokladna</source><number><numberRequested>TEST-POK-1</numberRequested></number>'
            . '<date>6026-09-01</date><dateTax>2026-08-31</dateTax></accountingItem>'
            . '<accountingItem><source>Pokladna</source><number><numberRequested>TEST-POK-2</numberRequested></number>'
            . '<date>2026-09-03</date></accountingItem></responsePack>');
        $export = PohodaExport::open($dir);

        $entries = iterator_to_array($export->records('journal', 'accountingItem'), false);
        self::assertSame(['2026-09-01', '2026-09-03'], array_column($entries, 'date'));
        self::assertSame('2026-08-31', $entries[0]['dateTax']);
        self::assertSame([], $export->counts()['later_years']);
        $headers = array_column(iterator_to_array($export->records('cash', 'voucher'), false), 'voucherHeader');
        self::assertSame(['2026-09-01', '2026-09-03'], array_column($headers, 'dateAccounting'));
        $warnings = $export->sourceWarnings();
        self::assertSame(['accounting_date_corrected'], array_column($warnings, 'code'));
        self::assertSame('TEST-POK-1', $warnings[0]['context']['document_no']);
        self::assertSame('6026-09-01', $warnings[0]['context']['accounting_date']);
        self::assertSame('2026-09-01', $warnings[0]['context']['document_date']);
    }

    public function testMdbAccountingDatesRespectTheTransferYearsAndTheDocumentId(): void
    {
        $dir = SyntheticPohodaMdbExport::write($this->tmp, [
            'BV' => [
                ['ID' => '11', 'Cislo' => 'TEST-BANK', 'RelTpBV' => '1', 'Datum' => '2026-12-31', 'DatUcP' => '2027-01-02'],
                ['ID' => '12', 'Cislo' => 'TEST-BANK', 'RelTpBV' => '1', 'Datum' => '2027-01-02', 'DatUcP' => '2027-01-02'],
            ],
            'pUD' => [
                ['ID' => '21', 'RelUdAg' => '28', 'RelAgID' => '11', 'Cislo' => 'TEST-BANK', 'Datum' => '2027-01-02'],
                ['ID' => '22', 'RelUdAg' => '28', 'RelAgID' => '12', 'Cislo' => 'TEST-BANK', 'Datum' => '2027-01-02'],
            ],
        ]);
        $allYears = PohodaExport::open($dir);
        $entries = static fn (PohodaExport $export): array => array_column(iterator_to_array($export->records('journal', 'accountingItem'), false), 'date');
        self::assertSame(['2027-01-02', '2027-01-02'], $entries($allYears));
        self::assertSame([], $allYears->sourceWarnings());

        $only2026 = $allYears->withPostingYears(FiscalCalendar::calendar(), 2026, [2027], [2026]);
        self::assertSame(['2026-12-31', '2027-01-02'], $entries($only2026));
        self::assertSame(['accounting_date_corrected'], array_column($only2026->sourceWarnings(), 'code'));
        self::assertSame(['2027-01-02', '2027-01-02'], $entries($allYears));
    }

    public function testRepeatedElementsAndAttributes(): void
    {
        $file = $this->tmp . '/list.xml';
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?><root><invoice><item><price>1</price></item><item><price>2</price></item>'
            . '<rate value="21">high</rate></invoice><invoice><item><price>3</price></item></invoice></root>');
        $records = iterator_to_array(PohodaXml::records($file, 'invoice'), false);
        self::assertCount(2, $records);
        self::assertCount(2, PohodaXml::all($records[0], 'item'));
        self::assertCount(1, PohodaXml::all($records[1], 'item'));
        self::assertSame('21', PohodaXml::attr($records[0], 'rate', 'value'));
        self::assertSame('high', PohodaXml::text($records[0], 'rate'));
        self::assertSame(2, PohodaXml::count($file, 'invoice'));
    }

    public function testDoctypeIsRejected(): void
    {
        $file = $this->tmp . '/evil.xml';
        file_put_contents($file, '<?xml version="1.0"?><!DOCTYPE root [<!ENTITY x "y">]><root><invoice>&x;</invoice></root>');
        $this->expectException(PohodaException::class);
        iterator_to_array(PohodaXml::records($file, 'invoice'));
    }

    /** DOCTYPE schovaný za dlouhým komentářem v prologu se odmítne stejně jako na začátku souboru. */
    public function testDoctypeAfterPaddingIsRejected(): void
    {
        $file = $this->tmp . '/padded.xml';
        file_put_contents($file, '<?xml version="1.0"?><!--' . str_repeat(' ', 9000) . '--><!DOCTYPE root [<!ENTITY x "y">]><root><invoice>&x;</invoice></root>');
        $this->expectException(PohodaException::class);
        iterator_to_array(PohodaXml::records($file, 'invoice'));
    }

    /** Hlavička balíku se čte i v HTTP náhledu - DOCTYPE tam nesmí projít. */
    public function testDoctypeInPackHeaderIsRejected(): void
    {
        $file = $this->tmp . '/pack.xml';
        file_put_contents($file, '<?xml version="1.0"?><!--' . str_repeat(' ', 9000) . '--><!DOCTYPE r [<!ENTITY a "12345678">]><responsePack ico="&a;" state="ok"/>');
        $this->expectException(PohodaException::class);
        PohodaXml::packInfo($file);
    }

    /** Dva soubory lišící se jen velikostí písmen by se na Windows tiše přepsaly - ZIP se odmítne. */
    public function testArchiveWithCaseCollisionIsRejected(): void
    {
        $zipPath = $this->tmp . '/case.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString(SyntheticPohodaExport::ICO . '_2026/01_ucetni_denik.xml', '<a/>');
        $zip->addFromString('obal/' . SyntheticPohodaExport::ICO . '_2026/01_UCETNI_DENIK.xml', '<b/>');
        $zip->close();
        $this->expectException(PohodaException::class);
        PohodaExport::extractArchive($zipPath, $this->tmp . '/out');
    }

    /** Z archivu se berou jen XML agend a přehled jednotek, jméno z archivu nikdy nevede mimo cíl. */
    public function testArchiveExtractsOnlyAgendaFiles(): void
    {
        $zipPath = $this->tmp . '/export.zip';
        SyntheticPohodaExport::writeZip($zipPath, $this->tmp . '/src');
        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $zip->addFromString('../../mimo.xml', '<x/>');
        $zip->addFromString(SyntheticPohodaExport::ICO . '_' . SyntheticPohodaExport::YEAR . '/skript.php', '<?php echo 1;');
        $zip->addFromString('obal/' . SyntheticPohodaExport::ICO . '_2025/01_ucetni_denik.xml', '<x/>');
        $zip->close();

        $target = $this->tmp . '/out';
        PohodaExport::extractArchive($zipPath, $target);

        self::assertFileExists($target . '/00_ucetni_jednotky.xml');
        self::assertFileExists($target . '/' . SyntheticPohodaExport::ICO . '_' . SyntheticPohodaExport::YEAR . '/01_ucetni_denik.xml');
        self::assertFileExists($target . '/' . SyntheticPohodaExport::ICO . '_2025/01_ucetni_denik.xml', 'Agenda ve vnořené složce se rozbalí do kořene.');
        self::assertFileDoesNotExist($target . '/' . SyntheticPohodaExport::ICO . '_' . SyntheticPohodaExport::YEAR . '/skript.php');
        self::assertFileDoesNotExist($this->tmp . '/mimo.xml');
        self::assertFileDoesNotExist($target . '/souhrn.txt');

        $overview = PohodaExport::overview($target);
        $agenda = array_values(array_filter($overview, static fn (array $a): bool => $a['year'] === SyntheticPohodaExport::YEAR))[0];
        self::assertSame(SyntheticPohodaExport::NAME, $agenda['company']);
        self::assertSame(9, $agenda['counts']['journal']);
        self::assertSame(2, $agenda['counts']['opening']);
        self::assertSame(2, $agenda['counts']['issued']);
        self::assertSame(1, $agenda['counts']['purchase']);
        self::assertSame(4, $agenda['counts']['bank']);
        self::assertSame(2, $agenda['counts']['partners']);
    }

    /**
     * Starší verze exportního nástroje balila do ZIPu i složku `_pozadavky` s ODESLANÝMI
     * dotazy. Leží v ní stejnojmenný `00_ucetni_jednotky.xml` jako v kořeni, jen s dotazem
     * místo odpovědi — import proto spadl na kontrole duplicit a uživatel musel soubor
     * z archivu ručně vyhodit. Dotazy do převodu nepatří, takže se přeskakují.
     */
    public function testRequestFolderFromOlderExportToolDoesNotBlockImport(): void
    {
        $zipPath = $this->tmp . '/pozadavky.zip';
        SyntheticPohodaExport::writeZip($zipPath, $this->tmp . '/src-req');
        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $zip->addFromString('_pozadavky/00_ucetni_jednotky.xml', '<dataPack><listAccountingUnitRequest/></dataPack>');
        $zip->addFromString('_pozadavky/' . SyntheticPohodaExport::ICO . '_' . SyntheticPohodaExport::YEAR . '_01_ucetni_denik.xml', '<dataPack/>');
        $zip->close();

        $target = $this->tmp . '/out-req';
        PohodaExport::extractArchive($zipPath, $target);

        self::assertFileExists($target . '/00_ucetni_jednotky.xml');
        self::assertStringNotContainsString(
            'listAccountingUnitRequest',
            (string) file_get_contents($target . '/00_ucetni_jednotky.xml'),
            'Přehled jednotek musí zůstat ODPOVĚDÍ z kořene, ne dotazem z pracovní složky.',
        );
        self::assertDirectoryDoesNotExist($target . '/_pozadavky');
    }

    /** Přebalený ZIP se stejnojmenným přehledem o složku hloub: bereme ten z kořene, nepadáme. */
    public function testDeeperUnitsOverviewDoesNotBlockImport(): void
    {
        $zipPath = $this->tmp . '/hloubka.zip';
        SyntheticPohodaExport::writeZip($zipPath, $this->tmp . '/src-deep');
        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $zip->addFromString('kopie/00_ucetni_jednotky.xml', '<dataPack><listAccountingUnitRequest/></dataPack>');
        $zip->close();

        $target = $this->tmp . '/out-deep';
        PohodaExport::extractArchive($zipPath, $target);

        self::assertFileExists($target . '/00_ucetni_jednotky.xml');
        self::assertStringNotContainsString(
            'listAccountingUnitRequest',
            (string) file_get_contents($target . '/00_ucetni_jednotky.xml'),
        );
    }

    public function testNonZipIsRejected(): void
    {
        file_put_contents($this->tmp . '/x.zip', 'neni zip');
        $this->expectException(PohodaException::class);
        PohodaExport::extractArchive($this->tmp . '/x.zip', $this->tmp . '/out');
    }
}
