<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\AgendaInfo;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Migration\MoneyS3\Ms3Table;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticTaxAgenda;
use PHPUnit\Framework\TestCase;

final class Ms3BackupTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ms3test_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
    }

    public function testExtractsOnlyAgendaDataAndIgnoresPathTricks(): void
    {
        $lz = $this->tmp . '/agenda.lz';
        SyntheticAgenda::writeLz($lz, [
            '../escape.dat' => 'nesmí ven',
            'ROK.001/../../escape2.dat' => 'nesmí ven',
            'rok.003/Extra.DAT' => 'malá písmena v názvu roku',
            'ROK.001/pozn.txt' => 'není datový soubor',
        ]);
        $backup = Ms3Backup::extract($lz, $this->tmp . '/out');

        self::assertFileDoesNotExist($this->tmp . '/escape.dat');
        self::assertFileDoesNotExist($this->tmp . '/escape2.dat');
        self::assertFileDoesNotExist($this->tmp . '/out/Dokumenty.s3db');
        self::assertFileDoesNotExist($this->tmp . '/out/ROK.001/UcDenik.MDT');
        self::assertFileDoesNotExist($this->tmp . '/out/ROK.001/pozn.txt');
        self::assertFileExists($this->tmp . '/out/ROK.001/UcDenik.DAT');
        self::assertDirectoryExists($this->tmp . '/out/ROK.003');
        self::assertCount(3, $backup->yearDirs());
    }

    /**
     * Na Windows jsou `con`, `aux`, `nul`, `comN`, `lptN` zařízení i s příponou — zápis
     * do `con.dat` by nešel do souboru. Money tabulky s `$` (přístupová práva) převod
     * nepotřebuje.
     */
    public function testReservedWindowsNamesAndDollarTablesAreNotExtracted(): void
    {
        foreach (['con.dat', 'AUX.DAT', 'ROK.001/nul.dat', 'com1.dat', 'lpt9.DAT', '$DSPristup.DAT', 'ROK.001/$x.dat'] as $name) {
            self::assertNull(Ms3Backup::entryTarget($name), $name);
        }
        self::assertSame(['ROK.001', 'UcDenik.DAT'], Ms3Backup::entryTarget('rok.001/UcDenik.DAT'));
        self::assertSame(['', 'Agenda.DAT'], Ms3Backup::entryTarget('Agenda.DAT'));
        self::assertSame(['', 'console.dat'], Ms3Backup::entryTarget('console.dat'));

        $lz = $this->tmp . '/agenda.lz';
        SyntheticAgenda::writeLz($lz, ['$DSPristup.DAT' => 'práva uživatelů']);
        Ms3Backup::extract($lz, $this->tmp . '/out');
        self::assertFileDoesNotExist($this->tmp . '/out/$DSPristup.DAT');
    }

    /** Strop počtu rozbalených souborů — archiv s tisíci drobných položek vyčerpá inody. */
    public function testRejectsArchiveWithTooManyEntries(): void
    {
        $lz = $this->tmp . '/agenda.lz';
        SyntheticAgenda::writeLz($lz);

        $this->expectException(MoneyS3Exception::class);
        $this->expectExceptionMessageMatches('/příliš mnoho/u');
        Ms3Backup::extract($lz, $this->tmp . '/out', 5);
    }

    /** Přerostlý soubor tabulky se odmítne dřív, než se začne číst. */
    public function testRefusesOversizedTable(): void
    {
        SyntheticAgenda::writeDir($this->tmp . '/a');
        $path = $this->tmp . '/a/ROK.001/UcDenik.DAT';

        $this->expectException(MoneyS3Exception::class);
        Ms3Table::open($path, 100);
    }

    public function testRejectsArchiveWithoutAgenda(): void
    {
        $lz = $this->tmp . '/cizi.zip';
        $zip = new \ZipArchive();
        $zip->open($lz, \ZipArchive::CREATE);
        $zip->addFromString('faktura.pdf', '%PDF');
        $zip->close();

        $this->expectException(MoneyS3Exception::class);
        Ms3Backup::extract($lz, $this->tmp . '/out');
    }

    public function testRejectsNonZip(): void
    {
        file_put_contents($this->tmp . '/x.lz', 'není zip');
        $this->expectException(MoneyS3Exception::class);
        Ms3Backup::extract($this->tmp . '/x.lz', $this->tmp . '/out');
    }

    public function testAgendaInfoReadsCompanyYearsAndVersion(): void
    {
        SyntheticAgenda::writeDir($this->tmp . '/a');
        $info = AgendaInfo::fromBackup(Ms3Backup::open($this->tmp . '/a'));

        self::assertSame(SyntheticAgenda::NAME, $info->name);
        self::assertSame(SyntheticAgenda::ICO, $info->ico);
        self::assertSame('CZ' . SyntheticAgenda::ICO, $info->dic);
        self::assertSame('Brno', $info->city);
        self::assertSame(SyntheticAgenda::VERSION, $info->version);
        self::assertSame([2024, 2025], $info->fiscalYears());
        self::assertSame(9, $info->partners);
        self::assertSame(4, $info->years[0]['opening_rows']);
        self::assertSame(4, $info->years[0]['purchase_invoices']);
        self::assertSame([0, 0], array_column($info->years, 'cashbook_rows'));
        self::assertFalse($info->taxRecordsOnly());
        self::assertSame([], $info->warnings);
    }

    /**
     * Daňová evidence vede v Money místo účetního deníku peněžní deník. Náhled pozná agendu
     * vedenou celou jako daňová evidence (převod ji převede do daňové evidence). V agendě,
     * která na podvojné účetnictví přešla, rok daňové evidence řekne, že se nepřevede
     * (ne jen „rok nemá účetní deník"); převoditelná zůstává od roku podvojného účetnictví.
     */
    public function testAgendaInfoRecognizesTaxRecordsYears(): void
    {
        $agenda = function (string $name, array $files): AgendaInfo {
            SyntheticAgenda::writeLzFiles($this->tmp . "/{$name}.lz", $files);
            return AgendaInfo::fromBackup(Ms3Backup::extract($this->tmp . "/{$name}.lz", $this->tmp . "/{$name}"));
        };

        $taxRecords = $agenda('tax', SyntheticAgenda::filesWithTaxRecords());
        self::assertTrue($taxRecords->taxRecordsOnly());
        self::assertSame([0, 0], array_column($taxRecords->years, 'journal_rows'));
        self::assertSame([2, 2], array_column($taxRecords->years, 'cashbook_rows'));
        self::assertSame([], $taxRecords->warnings);
        // Náhled z meta.json bez nového údaje není úplný - průvodce ho přečte znovu ze zálohy.
        $stored = $taxRecords->toArray();
        self::assertTrue(AgendaInfo::isComplete($stored));
        self::assertTrue(AgendaInfo::fromArray($stored)->taxRecordsOnly());
        unset($stored['years'][0]['cashbook_rows']);
        self::assertFalse(AgendaInfo::isComplete($stored));

        $switched = $agenda('switched', SyntheticAgenda::filesWithTaxRecords(true));
        self::assertFalse($switched->taxRecordsOnly());
        self::assertSame([null, 2025], array_column($switched->years, 'fiscal_year'));
        self::assertSame(['tax_records_year'], array_column($switched->warnings, 'code'));
        self::assertStringStartsWith('Rok v adresáři ROK.001 nemá účetní deník, ale peněžní deník', $switched->warnings[0]['message']);
        self::assertStringEndsWith('rok se nepřevede.', $switched->warnings[0]['message']);
        // Přechod z daňové evidence na podvojné účetnictví je v pořádku.
        self::assertSame(['tax_evidence', 'double_entry'], array_column($switched->years, 'accounting_mode'));
        self::assertNull($switched->taxRecordsAfterDoubleEntry());
    }

    /**
     * `Rok.DAT` nese rok, hranice období, režim a plátcovství roku (sekce „Global"; stejně
     * pojmenovaná proměnná jiné sekce s rokem nesouvisí). Záloha bez `Rok.DAT` je nemá.
     */
    public function testYearSettingsReadMoneyYearModeAndVatPayer(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/tax.lz', SyntheticTaxAgenda::files());
        $backup = Ms3Backup::extract($this->tmp . '/tax.lz', $this->tmp . '/tax');
        self::assertSame(
            ['year' => 2025, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'double_entry' => false, 'vat_payer' => true],
            $backup->yearSettings($backup->dir() . '/ROK.001'),
        );
        self::assertSame(2024, $backup->yearSettings($backup->dir() . '/ROK.002')['year']);

        SyntheticAgenda::writeDir($this->tmp . '/a');
        self::assertSame(
            ['year' => null, 'period_start' => null, 'period_end' => null, 'double_entry' => null, 'vat_payer' => null],
            Ms3Backup::open($this->tmp . '/a')->yearSettings($this->tmp . '/a/ROK.001'),
        );
    }

    /**
     * Náhled agendy nese rok a režim, jak je vede Money: adresář ROK.001 je tu rok 2025,
     * ROK.002 rok 2024 (pořadí adresářů pořadí let neurčuje). Hláška u roku daňové evidence
     * uvádí i rok. Bez `Rok.DAT` rozhoduje obsah roku.
     */
    public function testAgendaInfoCarriesMoneyYearAndAccountingMode(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/tax.lz', SyntheticTaxAgenda::files());
        $tax = AgendaInfo::fromBackup(Ms3Backup::extract($this->tmp . '/tax.lz', $this->tmp . '/tax'));
        self::assertSame([2025, 2024], array_column($tax->years, 'money_year'));
        self::assertSame(['tax_evidence', 'tax_evidence'], array_column($tax->years, 'accounting_mode'));
        self::assertTrue($tax->taxRecordsOnly());
        self::assertNull($tax->taxRecordsAfterDoubleEntry());
        // Agenda vedená celá jako daňová evidence se převede do daňové evidence: rok převodu
        // je rok Money a upozornění „rok se nepřevede" nemá.
        self::assertSame([2025, 2024], array_column($tax->years, 'fiscal_year'));
        self::assertSame([2024, 2025], $tax->fiscalYears());
        self::assertSame([], $tax->warnings);

        $stored = $tax->toArray();
        self::assertTrue(AgendaInfo::isComplete($stored));
        unset($stored['years'][1]['accounting_mode']);
        self::assertFalse(AgendaInfo::isComplete($stored), 'meta.json bez režimu roku se přečte znovu ze zálohy');

        SyntheticAgenda::writeDir($this->tmp . '/a');
        $doubleEntry = AgendaInfo::fromBackup(Ms3Backup::open($this->tmp . '/a'));
        self::assertSame([null, null], array_column($doubleEntry->years, 'money_year'));
        self::assertSame(['double_entry', 'double_entry'], array_column($doubleEntry->years, 'accounting_mode'));
        self::assertNull($doubleEntry->taxRecordsAfterDoubleEntry());
    }

    /**
     * Agenda, která se v Money z podvojného účetnictví vrátila do daňové evidence: převod
     * by ji přepnul do podvojného i pro roky daňové evidence. Náhled vrátí první takový rok.
     */
    public function testAgendaReturnedToTaxRecordsIsRecognized(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/back.lz', SyntheticTaxAgenda::filesReturnedToTaxRecords());
        $agenda = AgendaInfo::fromBackup(Ms3Backup::extract($this->tmp . '/back.lz', $this->tmp . '/back'));
        self::assertSame(['double_entry', 'tax_evidence'], array_column($agenda->years, 'accounting_mode'));
        self::assertFalse($agenda->taxRecordsOnly());
        self::assertSame(['year' => 2025, 'dir' => 'ROK.002'], $agenda->taxRecordsAfterDoubleEntry());
        self::assertStringContainsString('od roku 2025 (adresář ROK.002) znovu daňovou evidenci', AgendaInfo::textTaxRecordsAfterDoubleEntry(['year' => 2025, 'dir' => 'ROK.002']));
    }
}
