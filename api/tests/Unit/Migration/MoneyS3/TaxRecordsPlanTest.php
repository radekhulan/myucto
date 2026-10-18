<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\AgendaInfo;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Migration\MoneyS3\TaxRecordsPlan;
use MyInvoice\Tests\Fixtures\MoneyS3\Ms3FixtureWriter;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticTaxAgenda;
use PHPUnit\Framework\TestCase;

final class TaxRecordsPlanTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ms3plan_' . bin2hex(random_bytes(5));
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

    /**
     * @param array<string,string> $files
     * @return array{years:list<array<string,mixed>>,issues:list<array{level:string,code:string,message:string,context:array<string,mixed>}>}
     */
    private function check(array $files): array
    {
        $name = 'a' . bin2hex(random_bytes(3));
        SyntheticAgenda::writeLzFiles("{$this->tmp}/{$name}.lz", $files);
        $backup = Ms3Backup::extract("{$this->tmp}/{$name}.lz", "{$this->tmp}/{$name}");
        return TaxRecordsPlan::check($backup, AgendaInfo::fromBackup($backup));
    }

    /** @return array<string,string> kód => úroveň */
    private static function codes(array $check): array
    {
        return array_column($check['issues'], 'level', 'code');
    }

    /**
     * Peněžní deník syntetické agendy sedí s bankou a pokladnou, vazby vedou na doklady
     * a počáteční stavy navazují. Nulový pokladní doklad a interní doklady zápočtu deník
     * mít nemusí.
     */
    public function testConsistentAgendaHasNoIssuesAndSummarizesYears(): void
    {
        $check = $this->check(SyntheticTaxAgenda::files());
        self::assertSame([], $check['issues']);
        $years = array_column($check['years'], null, 'year');
        self::assertSame([2025, 2024], array_keys($years));
        self::assertSame(['dir' => 'ROK.002', 'year' => 2024, 'movements' => 7, 'bank' => 4, 'cash' => 3, 'internal' => 0,
            'links' => ['FP' => 2, 'FV' => 1, 'KZ' => 1]], array_diff_key($years[2024], ['totals' => true]));
        self::assertSame(['FP' => 1, 'KZ' => 1], $years[2025]['links']);
    }

    /** Doklad banky, který s peněžním deníkem nesedí nebo v něm chybí, a řádek deníku bez dokladu. */
    public function testProjectionMismatchMissingAndOrphanAreErrors(): void
    {
        // Poslední rok (2025): na něj žádné počáteční stavy nenavazují.
        $files = SyntheticTaxAgenda::with(SyntheticTaxAgenda::files(), 'ROK.001/BankKnih.DAT', SyntheticTaxAgenda::BANK_FIELDS,
            static fn (array $rows): array => [
                ...array_map(static fn (array $r): array => $r['Cislo'] === 3 ? ['Celkem' => 450.0] + $r : $r, $rows),
                ['Cislo' => 5, 'Doklad' => 'BV25009', 'Ucet' => 'BAN', 'Vydej' => 1, 'DatUcPr' => '2025-12-30', 'Celkem' => 100.0],
            ]);
        $files = SyntheticTaxAgenda::with($files, 'ROK.001/UcPenDen.DAT', SyntheticTaxAgenda::CASHBOOK_FIELDS,
            static fn (array $rows): array => [...$rows,
                ['Cislo' => 60, 'Datum' => '2025-12-30', 'Zdroj' => 'PK', 'ZRadek' => 77, 'Doklad' => 'PV25077', 'Typ' => 'V', 'Pohyb' => 'VP_160', 'Castka' => 10.0]]);

        $check = $this->check($files);
        self::assertSame(['cashbook_projection_missing' => 'error', 'cashbook_projection_mismatch' => 'error', 'cashbook_orphan' => 'error'], self::codes($check));
        $byCode = array_column($check['issues'], null, 'code');
        self::assertSame(['BP25001'], $byCode['cashbook_projection_mismatch']['context']['examples']);
        self::assertSame(['year' => 2025, 'dir' => 'ROK.001', 'count' => 1, 'examples' => ['BV25009']], $byCode['cashbook_projection_missing']['context']);
        self::assertStringStartsWith('Rok 2025 (adresář ROK.001): ', $byCode['cashbook_orphan']['message']);
    }

    /** Vazba na doklad, který v záloze není, neznámý pohyb a pohyb v cizí měně. */
    public function testUnknownMovementOrphanLinkAndForeignCurrencyAreErrors(): void
    {
        $files = SyntheticTaxAgenda::with(SyntheticTaxAgenda::files(), 'ROK.001/UcPenDen.DAT', SyntheticTaxAgenda::CASHBOOK_FIELDS,
            static fn (array $rows): array => array_map(static fn (array $r): array => match ($r['Doklad']) {
                'BP25001' => ['UZdroj' => 'FV', 'URadek' => 9] + $r,
                'BV25002' => ['Pohyb' => 'VP_XXX'] + $r,
                'BV25001' => ['Mena' => 'EUR', 'Kurz' => 25.0] + $r,
                default => $r,
            }, $rows));

        self::assertSame(['cashbook_link_orphan' => 'error', 'cashbook_movement_unknown' => 'error', 'cashbook_foreign_currency' => 'error'], self::codes($this->check($files)));
    }

    /** Sloupec peněžního deníku, který převod nezná, se nezařadí odhadem. */
    public function testUnknownColumnIsError(): void
    {
        $files = SyntheticTaxAgenda::with(SyntheticTaxAgenda::files(), 'ROK.001/UcPohyby.DAT', SyntheticTaxAgenda::MOVEMENT_FIELDS,
            static fn (array $rows): array => array_map(static fn (array $r): array => $r['Zkrat'] === 'PP_504' ? ['Sloupec' => 'PXXX'] + $r : $r, $rows));
        self::assertSame(['cashbook_column_unknown' => 'error'], self::codes($this->check($files)));
    }

    /** Interní doklad s řádky deníku jiné částky je jen upozornění (zápočet bez peněz). */
    public function testInternalDocumentMismatchIsWarning(): void
    {
        $files = SyntheticTaxAgenda::with(SyntheticTaxAgenda::files(), 'ROK.001/UcPenDen.DAT', SyntheticTaxAgenda::CASHBOOK_FIELDS,
            static fn (array $rows): array => [...$rows,
                ['Cislo' => 70, 'Druh' => 1, 'Datum' => '2025-03-15', 'Zdroj' => 'ID', 'ZRadek' => 1, 'Doklad' => 'ID25001', 'Typ' => 'V', 'Pohyb' => 'VP_105', 'Castka' => 600.0]]);
        self::assertSame(['cashbook_internal_mismatch' => 'warning'], self::codes($this->check($files)));
    }

    /** Počáteční stav účtu, který nenavazuje na konec předchozího roku, je upozornění s rozdílem. */
    public function testOpeningChainBreakIsWarning(): void
    {
        $files = SyntheticTaxAgenda::with(SyntheticTaxAgenda::files(), 'ROK.001/SzUcPokl.DAT', SyntheticTaxAgenda::ACCOUNT_FIELDS,
            static fn (array $rows): array => array_map(static fn (array $r): array => $r['Zkrat'] === 'BAN' ? ['PocStav' => 51000.0] + $r : $r, $rows));
        $check = $this->check($files);
        self::assertSame(['cash_opening_chain_break' => 'warning'], self::codes($check));
        self::assertSame(['year' => 2025, 'previous_year' => 2024, 'account' => 'BAN', 'difference' => -115.0], $check['issues'][0]['context']);
    }

    /** Majetek a mzdy převod daňové evidence zatím nepřebírá. */
    public function testAssetsAndPayrollAreNotSupported(): void
    {
        $files = SyntheticTaxAgenda::files();
        $files['MajInv.DAT'] = Ms3FixtureWriter::table([['Cislo', 'L', 4], ['Nazev', 'C', 60]], [['Cislo' => 1, 'Nazev' => 'Notebook']]);
        $files['Mzdy.DAT'] = Ms3FixtureWriter::table([['OsCislo', 'C', 10], ['Rok', 'W', 2]], [['OsCislo' => '1', 'Rok' => 2025]]);
        self::assertSame(['assets_not_supported' => 'error', 'payroll_not_supported' => 'error'], self::codes($this->check($files)));
    }
}
