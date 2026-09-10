<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\MoneyS3Uploads;
use PHPUnit\Framework\TestCase;

/**
 * Rozbalená agenda (celé účetnictví firmy) nesmí na disku ležet, dokud ji neuklidí další
 * upload téže firmy — denní úklid ji po týdnu nečinnosti smaže u všech firem.
 */
final class MoneyS3UploadsTest extends TestCase
{
    private const SUPPLIER_STALE = 2147480001;
    private const SUPPLIER_FRESH = 2147480002;

    protected function tearDown(): void
    {
        foreach ([self::SUPPLIER_STALE, self::SUPPLIER_FRESH] as $supplierId) {
            $base = MoneyS3Uploads::base($supplierId);
            if (!is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($base);
        }
    }

    public function testDailyCleanupRemovesStaleUploadsOfEveryCompany(): void
    {
        $stale = $this->upload(self::SUPPLIER_STALE, str_repeat('a', 16), time() - 8 * 86400);
        $fresh = $this->upload(self::SUPPLIER_FRESH, str_repeat('b', 16), time() - 3600);

        $removed = MoneyS3Uploads::purgeStaleAll();

        self::assertDirectoryDoesNotExist($stale);
        self::assertDirectoryExists($fresh);
        self::assertGreaterThanOrEqual(1, $removed);
    }

    public function testRecentlyAttachedReportKeepsOldUploadAlive(): void
    {
        $dir = $this->upload(self::SUPPLIER_STALE, str_repeat('c', 16), time() - 8 * 86400);
        mkdir($dir . '/reports', 0755, true);
        file_put_contents($dir . '/reports/2024.csv', 'účet;PS');

        MoneyS3Uploads::purgeStaleAll();

        self::assertDirectoryExists($dir);
    }

    private function upload(int $supplierId, string $token, int $mtime): string
    {
        $dir = MoneyS3Uploads::dir($supplierId, $token);
        mkdir($dir . '/agenda', 0755, true);
        file_put_contents($dir . '/agenda/UcDenik.DAT', 'data');
        file_put_contents($dir . '/meta.json', '{}');
        touch($dir . '/meta.json', $mtime);
        return $dir;
    }
}
