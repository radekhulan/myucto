<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Migration\Shared\ImportJobUploads;
use MyInvoice\Service\Migration\Shared\ImportJobReportFiles;
use MyInvoice\Service\Export\Instance\InstanceExportArchive;
use MyInvoice\Service\Export\Instance\CompleteInstanceRestoreService;
use MyInvoice\Service\Export\Instance\InstanceExportSupplementalFiles;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxRunReportStore;
use MyInvoice\Service\Migration\StereoNx\StereoNxUploads;
use PHPUnit\Framework\TestCase;

final class StereoNxRunReportStoreTest extends TestCase
{
    private string $root;
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = getenv('MYINVOICE_DATA_DIR');
        $this->root = sys_get_temp_dir() . '/stereo-run-report-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        putenv('MYINVOICE_DATA_DIR=' . $this->root);
    }

    protected function tearDown(): void
    {
        if ($this->previous === false) putenv('MYINVOICE_DATA_DIR');
        else putenv('MYINVOICE_DATA_DIR=' . $this->previous);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) ($file->isDir() && !$file->isLink()) ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }

    public function testStoredReportSurvivesUploadDeletionAndKeepsRawFailedResult(): void
    {
        $store = new StereoNxRunReportStore();
        $report = ['ok' => false, 'errors' => [['code' => 'synthetic_error', 'message' => 'Syntetická chyba']],
            'warnings' => [], 'counts' => ['assets_source' => 1]];
        $stored = $store->save(123, 45, $report);
        self::assertSame('import-jobs/123/reports/stereo-nx-45.json', $stored['path']);
        self::assertSame('application/json', $stored['mime']);
        $token = str_repeat('a', 32);
        mkdir(StereoNxUploads::dir(123, $token), 0700, true);
        StereoNxUploads::save(123, $token, ['uploaded_by' => 7, 'status' => 'ready']);
        StereoNxUploads::delete(123, $token);
        self::assertSame($report, $store->read(123, 45, $stored['path']));
        self::assertSame($stored['size'], filesize(RuntimePaths::storage($stored['path'])));
    }

    public function testCompletedReportIsExportedWithRestorablePathAndContents(): void
    {
        $report = ['ok' => false, 'counts' => ['synthetic_source' => 1]];
        $stored = (new StereoNxRunReportStore())->save(123, 45, $report);
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetch')->willReturnOnConsecutiveCalls(['id' => 45, 'result_path' => $stored['path']], false);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $files = (new InstanceExportSupplementalFiles($pdo))->forSupplier(123);
        self::assertCount(1, $files);
        self::assertSame($stored['path'], $files[0]['storage_path']);
        self::assertSame('prilohy/importy/stereo-nx-45.json', $files[0]['entry']);
        self::assertSame($report, json_decode(file_get_contents($files[0]['source']), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(hash_file('sha256', $files[0]['source']), $files[0]['sha256']);
    }

    public function testDeletingJobPurgesItsReportWithoutTouchingAnotherJob(): void
    {
        $store = new StereoNxRunReportStore();
        $stored = $store->save(123, 45, ['ok' => false]);
        $other = $store->save(123, 46, ['ok' => true]);
        $jobs = $this->createMock(ImportJobRepository::class);
        $jobs->expects(self::never())->method('listForTenant');
        self::assertSame(0, ImportJobUploads::purgeOrphaned($jobs, [
            'id' => 45, 'supplier_id' => 123, 'source' => 'stereo_nx', 'result_path' => $stored['path'],
        ]));
        self::assertFileDoesNotExist(RuntimePaths::storage($stored['path']));
        self::assertFileExists(RuntimePaths::storage($other['path']));
    }

    public function testBothReportFormatsRoundTripThroughArchiveAndFileRestore(): void
    {
        $stereo = (new StereoNxRunReportStore())->save(123, 45, ['ok' => false]);
        $legacyPath = 'import-jobs/123/reports/46.json';
        file_put_contents(RuntimePaths::storage($legacyPath), '{"summary":{"synthetic":1}}');
        $files = $this->exportReports([
            ['id' => 45, 'result_path' => $stereo['path']], ['id' => 46, 'result_path' => $legacyPath],
        ]);
        self::assertCount(2, $files);
        $archivePath = $this->root . '/reports.zip';
        $archive = new InstanceExportArchive($archivePath, '', 1_000_000);
        $manifest = [];
        foreach ($files as $file) {
            $archive->addFile($file['entry'], $file['source']);
            $manifest[] = array_diff_key($file, ['source' => true]);
        }
        $archive->finish();
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archivePath));
        $extracted = $this->root . '/extracted';
        self::assertTrue($zip->extractTo($extracted));
        $zip->close();
        $restoreRoot = $this->root . '/restored';
        $restore = new CompleteInstanceRestoreService($this->createStub(\PDO::class), $restoreRoot);
        self::assertSame(2, (new \ReflectionMethod($restore, 'restoreFiles'))->invoke($restore, $extracted, $manifest));
        foreach ($files as $file) {
            self::assertSame(file_get_contents($file['source']), file_get_contents($restoreRoot . '/' . $file['storage_path']));
        }
        $jobs = $this->createStub(ImportJobRepository::class);
        ImportJobUploads::purgeOrphaned($jobs, ['id' => 46, 'supplier_id' => 123, 'source' => 'legacy-source', 'result_path' => $legacyPath]);
        self::assertFileDoesNotExist(RuntimePaths::storage($legacyPath));
        self::assertFileExists(RuntimePaths::storage($stereo['path']));
    }

    public function testExportAndCleanupRejectForeignOrNonCanonicalReportPaths(): void
    {
        $stored = (new StereoNxRunReportStore())->save(124, 45, ['ok' => true]);
        $otherJob = (new StereoNxRunReportStore())->save(123, 46, ['ok' => true]);
        $rows = [
            ['id' => 45, 'result_path' => $stored['path']],
            ['id' => 45, 'result_path' => $otherJob['path']],
            ['id' => 45, 'result_path' => 'import-jobs/123/reports/../reports/stereo-nx-46.json'],
        ];
        self::assertSame([], $this->exportReports($rows));
        foreach ($rows as $row) ImportJobReportFiles::purge($row + ['supplier_id' => 123]);
        self::assertFileExists(RuntimePaths::storage($stored['path']));
        self::assertFileExists(RuntimePaths::storage($otherJob['path']));
    }

    public function testExportAndCleanupRejectReportAndAncestorSymlinks(): void
    {
        if (!function_exists('symlink')) self::markTestSkipped('Symbolic links are unavailable.');
        $store = new StereoNxRunReportStore();
        $stored = $store->save(123, 45, ['ok' => true]);
        $path = RuntimePaths::storage($stored['path']);
        $outside = $this->root . '/outside.json';
        file_put_contents($outside, '{"ok":false}');
        unlink($path);
        if (!@symlink($outside, $path)) self::markTestSkipped('Cannot create symbolic links on this platform.');
        $row = ['id' => 45, 'result_path' => $stored['path']];
        self::assertSame([], $this->exportReports([$row]));
        ImportJobReportFiles::purge($row + ['supplier_id' => 123]);
        self::assertTrue(is_link($path));
        self::assertSame('{"ok":false}', file_get_contents($outside));
        unlink($path);
        rmdir(dirname($path));
        $outsideDir = $this->root . '/outside-dir';
        mkdir($outsideDir);
        file_put_contents($outsideDir . '/stereo-nx-45.json', '{"ok":true}');
        self::assertTrue(symlink($outsideDir, dirname($path)));
        self::assertSame([], $this->exportReports([$row]));
        ImportJobReportFiles::purge($row + ['supplier_id' => 123]);
        self::assertFileExists($outsideDir . '/stereo-nx-45.json');
    }

    public function testLinkedStorageRootKeepsReportsSavedExportedAndPurged(): void
    {
        $volume = $this->root . '/volume';
        mkdir($volume, 0700);
        $link = RuntimePaths::storage();
        $this->linkDirectory($volume, $link);
        try {
            $store = new StereoNxRunReportStore();
            $stored = $store->save(123, 45, ['ok' => true]);
            self::assertFileExists($volume . '/' . $stored['path']);
            self::assertSame(['ok' => true], $store->read(123, 45, $stored['path']));
            $legacyPath = 'import-jobs/123/reports/46.json';
            file_put_contents($volume . '/' . $legacyPath, '{"summary":{"synthetic":1}}');
            $files = $this->exportReports([
                ['id' => 45, 'result_path' => $stored['path']], ['id' => 46, 'result_path' => $legacyPath],
            ]);
            self::assertSame([$stored['path'], $legacyPath], array_column($files, 'storage_path'));
            self::assertSame(['prilohy/importy/stereo-nx-45.json', 'prilohy/importy/46.json'], array_column($files, 'entry'));
            ImportJobReportFiles::purge(['id' => 46, 'supplier_id' => 123, 'result_path' => $legacyPath]);
            self::assertFileDoesNotExist($volume . '/' . $legacyPath);
            self::assertFileExists($volume . '/' . $stored['path']);
        } finally {
            $this->unlinkDirectory($link);
        }
    }

    public function testLinkInsideLinkedStorageRootPointingOutsideIsRejected(): void
    {
        $volume = $this->root . '/volume';
        mkdir($volume, 0700);
        $link = RuntimePaths::storage();
        $this->linkDirectory($volume, $link);
        $outside = $this->root . '/outside-reports';
        mkdir($outside, 0700);
        file_put_contents($outside . '/stereo-nx-45.json', '{"ok":true}');
        file_put_contents($outside . '/46.json', '{"ok":true}');
        mkdir($volume . '/import-jobs/123', 0700, true);
        $reports = $volume . '/import-jobs/123/reports';
        $this->linkDirectory($outside, $reports);
        try {
            $rows = [
                ['id' => 45, 'result_path' => 'import-jobs/123/reports/stereo-nx-45.json'],
                ['id' => 46, 'result_path' => 'import-jobs/123/reports/46.json'],
            ];
            self::assertNull((new StereoNxRunReportStore())->read(123, 45, $rows[0]['result_path']));
            self::assertSame([], $this->exportReports($rows));
            foreach ($rows as $row) ImportJobReportFiles::purge($row + ['supplier_id' => 123]);
            self::assertFileExists($outside . '/stereo-nx-45.json');
            self::assertFileExists($outside . '/46.json');
        } finally {
            $this->unlinkDirectory($reports);
            $this->unlinkDirectory($link);
        }
    }

    /** Na Windows junction (nevyžaduje oprávnění pro symlinky), jinde symbolický odkaz. */
    private function linkDirectory(string $target, string $link): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('mklink /J ' . escapeshellarg(str_replace('/', '\\', $link)) . ' '
                . escapeshellarg(str_replace('/', '\\', $target)) . ' 2>NUL', $output, $code);
            if ($code !== 0) self::markTestSkipped('Junction nelze na tomto systému vytvořit.');
            return;
        }
        if (!function_exists('symlink') || !@symlink($target, $link)) {
            self::markTestSkipped('Symbolický odkaz nelze na tomto systému vytvořit.');
        }
    }

    private function unlinkDirectory(string $link): void
    {
        if (PHP_OS_FAMILY === 'Windows') @rmdir($link);
        else @unlink($link);
    }

    /** @param list<array{id:int,result_path:string}> $rows */
    private function exportReports(array $rows): array
    {
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        return (new InstanceExportSupplementalFiles($pdo))->forSupplier(123);
    }

    public function testOnlyCanonicalTenantAndJobPathCanBeRead(): void
    {
        $store = new StereoNxRunReportStore();
        $stored = $store->save(123, 45, ['ok' => true]);
        foreach ([null, '', '../reports/stereo-nx-45.json', 'import-jobs/123/reports/../reports/stereo-nx-45.json',
            'import-jobs/124/reports/stereo-nx-45.json', 'import-jobs/123/reports/stereo-nx-46.json'] as $path) {
            self::assertNull($store->read(123, 45, $path));
        }
        self::assertNull($store->read(124, 45, $stored['path']));
        self::assertNull($store->read(123, 46, $stored['path']));
        self::assertNull($store->read(123, 0, $stored['path']));
    }

    public function testMalformedJsonIsUnavailable(): void
    {
        $store = new StereoNxRunReportStore();
        $stored = $store->save(123, 45, ['ok' => true]);
        foreach (['{', 'null', '[]', '{"ok":"true"}'] as $json) {
            file_put_contents(RuntimePaths::storage($stored['path']), $json);
            self::assertNull($store->read(123, 45, $stored['path']));
        }
    }

    public function testReportSymlinkIsNeverReadOrOverwritten(): void
    {
        if (!function_exists('symlink')) self::markTestSkipped('Symbolic links are unavailable.');
        $store = new StereoNxRunReportStore();
        $stored = $store->save(123, 45, ['ok' => true]);
        $path = RuntimePaths::storage($stored['path']);
        $outside = $this->root . '/outside.json';
        file_put_contents($outside, '{"ok":false}');
        unlink($path);
        if (!@symlink($outside, $path)) self::markTestSkipped('Cannot create a symbolic link on this platform.');
        self::assertNull($store->read(123, 45, $stored['path']));
        try {
            $store->save(123, 45, ['ok' => true]);
            self::fail('A symlink must not be overwritten.');
        } catch (StereoNxException $e) {
            self::assertSame('storage_error', $e->errorCode);
        }
        self::assertSame('{"ok":false}', file_get_contents($outside));
    }

    public function testReportsDirectorySymlinkIsRejected(): void
    {
        if (!function_exists('symlink')) self::markTestSkipped('Symbolic links are unavailable.');
        mkdir(RuntimePaths::storage('import-jobs/123'), 0700, true);
        $outside = $this->root . '/outside';
        mkdir($outside, 0700);
        if (!@symlink($outside, RuntimePaths::storage('import-jobs/123/reports'))) self::markTestSkipped('Cannot create a symbolic link on this platform.');
        $store = new StereoNxRunReportStore();
        self::assertNull($store->read(123, 45, 'import-jobs/123/reports/stereo-nx-45.json'));
        $this->expectException(StereoNxException::class);
        $store->save(123, 45, ['ok' => true]);
    }
}
