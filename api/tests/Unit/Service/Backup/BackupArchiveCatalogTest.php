<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Backup\BackupArchiveCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Katalog záloh pro stránku „Stažení záloh".
 *
 * Dvě věci, na kterých to stojí: rozdělení do sekcí se počítá z názvu souboru
 * (cron skripty jinou stopu nenechávají) a stahovat jde jen to, co katalog sám
 * vylistoval — název z requestu se nikdy nesmí stát cestou.
 */
final class BackupArchiveCatalogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/myucto-backup-catalog-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->dir . '/.*') ?: [] as $file) {
            if (!is_dir($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    public function testFilesAreSplitIntoSectionsByCronNamingConvention(): void
    {
        $this->write('myucto-2026-09-11_08-00.zip');
        $this->write('myucto-documents-2026-09-11_02-35.zip');
        $this->write('myucto-pdf-2026-09-11_02-30.zip');
        $this->write('myucto-payroll-2026-09-11_02-40.zip');

        $kinds = array_column($this->catalog()->sections(), 'kind');

        self::assertSame(
            [
                BackupArchiveCatalog::KIND_DATABASE,
                BackupArchiveCatalog::KIND_DOCUMENTS,
                BackupArchiveCatalog::KIND_PDF,
                BackupArchiveCatalog::KIND_PAYROLL,
            ],
            $kinds,
        );
    }

    /**
     * Soubor mimo konvenci se nesmí ztratit. Záloha, která v adresáři leží a v UI
     * není, vypadá jako ztracená záloha — a to je horší než nepořádek v seznamu.
     */
    public function testUnrecognizedArchiveFallsIntoOtherSectionInsteadOfDisappearing(): void
    {
        $this->write('rucni-kopie-pred-migraci.zip');
        $this->write('export-bez-data.sql.gz');

        $sections = $this->catalog()->sections();

        self::assertCount(1, $sections);
        self::assertSame(BackupArchiveCatalog::KIND_OTHER, $sections[0]['kind']);
        self::assertCount(2, $sections[0]['files']);
    }

    /**
     * Prefix názvu je jméno databáze v době zálohy. Přejmenovaná databáze nebo
     * lokální přepnutí na jinou (cfg.local.php) nesmí z automatických záloh
     * udělat „ostatní" — kategorie se pozná podle tvaru názvu, ne podle prefixu.
     */
    public function testKindIsRecognizedEvenWhenPrefixDiffersFromCurrentDatabaseName(): void
    {
        $this->write('myucto-2026-09-11_08-00.zip');
        $this->write('myucto-documents-2026-09-11_02-35.zip');
        $this->write('myucto-pdf-2026-09-11_02-30.zip');
        $this->write('myucto-payroll-2026-09-11_02-40.zip');
        $this->write('myucto-2026-01-01.sql.gz');

        $sections = $this->catalog('myucto_prod2')->sections();

        self::assertSame(
            [
                BackupArchiveCatalog::KIND_DATABASE,
                BackupArchiveCatalog::KIND_DOCUMENTS,
                BackupArchiveCatalog::KIND_PDF,
                BackupArchiveCatalog::KIND_PAYROLL,
            ],
            array_column($sections, 'kind'),
        );
        self::assertSame(2, $sections[0]['total_files']);
    }

    public function testEachSectionOffersOnlyTheFiveNewestBackups(): void
    {
        for ($day = 1; $day <= 7; $day++) {
            $this->write(sprintf('myucto-2026-09-%02d_02-00.zip', $day));
        }
        $this->write('myucto-payroll-2026-09-10_02-40.zip');
        $this->write('myucto-payroll-2026-09-11_02-40.zip');

        $sections = $this->catalog()->sections();

        self::assertSame(BackupArchiveCatalog::KIND_DATABASE, $sections[0]['kind']);
        self::assertSame([
            'myucto-2026-09-07_02-00.zip',
            'myucto-2026-09-06_02-00.zip',
            'myucto-2026-09-05_02-00.zip',
            'myucto-2026-09-04_02-00.zip',
            'myucto-2026-09-03_02-00.zip',
        ], array_column($sections[0]['files'], 'name'));
        self::assertSame(7, $sections[0]['total_files']);
        self::assertSame(7 * 64, $sections[0]['size_bytes']);

        self::assertSame(BackupArchiveCatalog::KIND_PAYROLL, $sections[1]['kind']);
        self::assertCount(2, $sections[1]['files']);
        self::assertSame(2, $sections[1]['total_files']);
    }

    /** Pracovní pozůstatky cronů — mezi nimi `.dump.cnf` s heslem k databázi. */
    public function testWorkingLeftoversOfCronScriptsAreNotListed(): void
    {
        $this->write('.myucto-2026-09-11_08-00.sql');
        $this->write('.dump.cnf');
        $this->write('.last-error');
        $this->write('myucto-2026-09-11_08-00.zip');

        $names = array_column($this->catalog()->list(), 'name');

        self::assertSame(['myucto-2026-09-11_08-00.zip'], $names);
    }

    public function testNewestBackupComesFirstEvenWhenModificationTimesAreUseless(): void
    {
        // Kopie adresáře (migrace na jiný server) přepíše mtime všem najednou.
        $this->write('myucto-2026-09-09_02-00.zip');
        $this->write('myucto-2026-09-11_08-00.zip');
        $this->write('myucto-2026-09-10_14-00.zip');
        foreach (glob($this->dir . '/*.zip') ?: [] as $file) {
            touch($file, 1_600_000_000);
        }

        $names = array_column($this->catalog()->list(), 'name');

        self::assertSame([
            'myucto-2026-09-11_08-00.zip',
            'myucto-2026-09-10_14-00.zip',
            'myucto-2026-09-09_02-00.zip',
        ], $names);
    }

    public function testDownloadResolvesOnlyFilesTheCatalogItselfListed(): void
    {
        $this->write('myucto-2026-09-11_08-00.zip');
        $this->write('.dump.cnf');
        $catalog = $this->catalog();

        self::assertNotNull($catalog->resolveForDownload('myucto-2026-09-11_08-00.zip'));
        // Skrytý soubor s heslem k databázi se nevylistoval, takže se ani nestáhne.
        self::assertNull($catalog->resolveForDownload('.dump.cnf'));
        self::assertNull($catalog->resolveForDownload('neexistuje.zip'));
    }

    public function testNameFromRequestNeverBecomesAPath(): void
    {
        $this->write('myucto-2026-09-11_08-00.zip');
        file_put_contents(dirname($this->dir) . '/myucto-outside-secret.zip', 'x');
        $catalog = $this->catalog();

        try {
            foreach ([
                '../myucto-outside-secret.zip',
                '..\\myucto-outside-secret.zip',
                $this->dir . '/myucto-2026-09-11_08-00.zip',
                'sub/myucto-2026-09-11_08-00.zip',
                '',
            ] as $attempt) {
                self::assertNull($catalog->resolveForDownload($attempt), $attempt);
            }
        } finally {
            @unlink(dirname($this->dir) . '/myucto-outside-secret.zip');
        }
    }

    private function catalog(string $dbName = 'myucto'): BackupArchiveCatalog
    {
        return new BackupArchiveCatalog(new Config([
            'db' => ['name' => $dbName],
            'cron' => ['backup' => ['output_dir' => $this->dir]],
        ]));
    }

    private function write(string $name): void
    {
        file_put_contents($this->dir . '/' . $name, str_repeat('x', 64));
    }
}
