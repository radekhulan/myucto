<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\AgendaInfo;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
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
        self::assertSame(3, $info->partners);
        self::assertSame(4, $info->years[0]['opening_rows']);
        self::assertSame(1, $info->years[0]['purchase_invoices']);
        self::assertSame([], $info->warnings);
    }
}
