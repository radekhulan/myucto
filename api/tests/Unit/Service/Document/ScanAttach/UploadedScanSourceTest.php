<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Document\ScanAttach;

use MyInvoice\Service\Document\ScanAttach\UploadedScanSource;
use PHPUnit\Framework\TestCase;

final class UploadedScanSourceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scan-source-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testZipYieldsFilesAndSkipsSystemEntries(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip není dostupné.');
        }
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->dir . '/blob', \ZipArchive::CREATE));
        $zip->addFromString('slozka/4400123456.pdf', '%PDF-1.4 syntetický sken A');
        $zip->addFromString('foto 1.jpg', 'syntetický obrázek');
        $zip->addFromString('__MACOSX/slozka/._4400123456.pdf', 'metadata');
        $zip->addFromString('.DS_Store', 'metadata');
        $zip->addEmptyDir('prazdna');
        $zip->close();

        $source = new UploadedScanSource($this->dir);
        self::assertSame(2, $source->count());

        $seen = [];
        foreach ($source->files() as $f) {
            self::assertNull($f->error);
            self::assertFileExists($f->path);
            $seen[$f->name] = (string) file_get_contents($f->path);
            unlink($f->path);
        }
        self::assertSame(['4400123456.pdf' => '%PDF-1.4 syntetický sken A', 'foto 1.jpg' => 'syntetický obrázek'], $seen);

        // Opakovaný průchod (navázání po pádu) vydá tytéž soubory znovu.
        self::assertCount(2, iterator_to_array($source->files(), false));
    }

    public function testManifestYieldsCopiesAndKeepsStagedOriginals(): void
    {
        file_put_contents($this->dir . '/p1', '%PDF-1.4 syntetický sken B');
        file_put_contents($this->dir . '/manifest.jsonl',
            json_encode(['f' => 'p1', 'n' => 'PF20260268 foto1.pdf']) . "\n"
            . json_encode(['f' => 'chybi', 'n' => 'ztraceny.pdf']) . "\n");

        $source = new UploadedScanSource($this->dir);
        $files = iterator_to_array($source->files(), false);

        self::assertCount(2, $files);
        self::assertSame('PF20260268 foto1.pdf', $files[0]->name);
        self::assertNotSame($this->dir . '/p1', $files[0]->path, 'vydá se kopie, originál zůstává pro další běh');
        self::assertFileExists($this->dir . '/p1');
        self::assertSame('missing', $files[1]->error);

        $source->cleanup();
        self::assertDirectoryDoesNotExist($this->dir);
    }
}
