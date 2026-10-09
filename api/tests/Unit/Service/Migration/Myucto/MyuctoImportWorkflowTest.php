<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Myucto;

use DG\BypassFinals;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Migration\Myucto\MyuctoExportReader;
use MyInvoice\Service\Migration\Myucto\MyuctoImporter;
use MyInvoice\Service\Migration\Myucto\MyuctoImportException;
use MyInvoice\Service\Migration\Myucto\MyuctoImportWorkflow;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class MyuctoImportWorkflowTest extends TestCase
{
    private string $dir;
    private string|false $oldDataDir;
    private MyuctoImportWorkflow $workflow;
    private MyuctoImporter $importer;
    private MyuctoExportReader $reader;

    protected function setUp(): void
    {
        BypassFinals::enable();
        $this->dir = sys_get_temp_dir() . '/myucto-workflow-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        $this->oldDataDir = getenv('MYINVOICE_DATA_DIR');
        putenv('MYINVOICE_DATA_DIR=' . $this->dir);
        $this->reader = $this->createStub(MyuctoExportReader::class);
        $this->importer = $this->createMock(MyuctoImporter::class);
        $this->reader->method('read')->willReturn(['tables' => ['supplier' => [1 => ['company_name' => 'Syntetická firma', 'ic' => '00000000']]]]);
        $this->workflow = new MyuctoImportWorkflow($this->reader, $this->importer, new Config([]));
    }

    protected function tearDown(): void
    {
        putenv($this->oldDataDir === false ? 'MYINVOICE_DATA_DIR' : 'MYINVOICE_DATA_DIR=' . $this->oldDataDir);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($this->dir);
    }

    private function upload(): string
    {
        $init = $this->workflow->init(4, 1, 'synthetic.zip', 3);
        self::assertSame(['received' => 3], $this->workflow->chunk(4, 1, $init['token'], 0, (new StreamFactory())->createStream('ZIP')));
        $this->workflow->complete(4, 1, $init['token']);
        return $init['token'];
    }

    public function testApplyRequiresSuccessfulPreviewAndConfirmation(): void
    {
        $token = $this->upload();
        $this->importer->expects(self::never())->method('import');
        $this->expectException(MyuctoImportException::class);
        $this->expectExceptionMessage('Nejprve proveďte');
        $this->workflow->run(4, 1, $token, 'synthetic', null, true, true);
    }

    public function testPreviewApplyAndRetryRemainBoundToFileAndSource(): void
    {
        $token = $this->upload();
        $modes = [];
        $this->importer->expects(self::exactly(3))->method('import')->willReturnCallback(static function ($package, $supplier, $actor, $source, $dryRun) use (&$modes): array {
            self::assertSame(4, $supplier); self::assertSame(1, $actor); self::assertSame('synthetic', $source);
            $modes[] = $dryRun;
            return ['dry_run' => $dryRun];
        });
        $this->workflow->run(4, 1, $token, 'synthetic', 'synthetic-password', false, false);
        $this->workflow->run(4, 1, $token, 'synthetic', 'synthetic-password', true, true);
        $this->workflow->run(4, 1, $token, 'synthetic', 'synthetic-password', true, true);
        self::assertSame([true, false, false], $modes);
        self::assertFalse($this->workflow->show(4, 1, $token)['result']['report']['dry_run']);
        $state = file_get_contents($this->dir . '/storage/myucto-import/4/' . $token . '/upload.json');
        self::assertStringNotContainsString('synthetic-password', $state);
    }

    public function testSuccessfulPreviewStillRequiresExplicitConfirmation(): void
    {
        $token = $this->upload();
        $this->importer->expects(self::once())->method('import')->willReturn(['dry_run' => true]);
        $this->workflow->run(4, 1, $token, 'synthetic', null, false, false);
        $this->expectException(MyuctoImportException::class);
        $this->expectExceptionMessage('potvrďte import');
        $this->workflow->run(4, 1, $token, 'synthetic', null, true, false);
    }

    public function testChangedSourceCannotUsePreviousPreview(): void
    {
        $token = $this->upload();
        $this->importer->expects(self::once())->method('import')->willReturn(['dry_run' => true]);
        $this->workflow->run(4, 1, $token, 'synthetic', null, false, false);
        $this->expectException(MyuctoImportException::class);
        $this->workflow->run(4, 1, $token, 'different', null, true, true);
    }

    public function testPreviewFromOldProfileCannotAuthorizeQueuedOrDirectApply(): void
    {
        $token = $this->upload();
        $this->importer->expects(self::once())->method('import')->willReturn(['dry_run' => true]);
        $this->workflow->run(4, 1, $token, 'synthetic', null, false, false);
        $this->workflow->uploads()->updateState(4, $token, ['checked_profile' => null]);
        try {
            $this->workflow->validateStart(4, 1, $token, 'synthetic', true, true);
            self::fail('Starší kontrola nesmí povolit nový profil.');
        } catch (MyuctoImportException $e) {
            self::assertStringContainsString('Nejprve proveďte', $e->getMessage());
        }
        $this->expectExceptionMessage('Nejprve proveďte');
        $this->workflow->run(4, 1, $token, 'synthetic', null, true, true);
    }

    public function testChangedFileIsRejected(): void
    {
        $token = $this->upload();
        $this->importer->expects(self::once())->method('import')->willReturn(['dry_run' => true]);
        $this->workflow->run(4, 1, $token, 'synthetic', null, false, false);
        file_put_contents($this->dir . '/storage/myucto-import/4/' . $token . '/export.zip', 'CHANGED');
        $this->expectExceptionMessage('export se změnil');
        $this->workflow->run(4, 1, $token, 'synthetic', null, false, false);
    }

    public function testUploadCannotBeAccessedByAnotherActorOrCompany(): void
    {
        $token = $this->upload();
        foreach ([[4, 2], [5, 1]] as [$supplier, $actor]) {
            try { $this->workflow->show($supplier, $actor, $token); self::fail('Foreign upload was accessible.'); }
            catch (MyuctoImportException $e) { self::assertSame(404, $e->getCode()); }
        }
    }

    public function testAcceptsLargeZipButStillRequiresPreviewForQueuedApply(): void
    {
        $init = $this->workflow->init(4, 1, 'synthetic.zip', 65 * 1024 * 1024);
        self::assertNotEmpty($init['token']);
        $token = $this->upload();
        $this->workflow->validateStart(4, 1, $token, 'synthetic', false, false);
        $this->expectExceptionMessage('Nejprve proveďte');
        $this->workflow->validateStart(4, 1, $token, 'synthetic', true, true);
    }

    public function testFailedPreviewInvalidatesPreviousSuccessfulPreview(): void
    {
        $token = $this->upload();
        $this->importer->expects(self::exactly(2))->method('import')->willReturnOnConsecutiveCalls(
            ['dry_run' => true],
            self::throwException(new \RuntimeException('Syntetická chyba kontroly.')),
        );
        $this->workflow->run(4, 1, $token, 'synthetic', null, false, false);
        try {
            $this->workflow->run(4, 1, $token, 'synthetic', null, false, false);
            self::fail('Failed preview was accepted.');
        } catch (MyuctoImportException) {
            self::assertNull($this->workflow->show(4, 1, $token)['result']);
        }
        $this->expectExceptionMessage('Nejprve proveďte');
        $this->workflow->validateStart(4, 1, $token, 'synthetic', true, true);
    }

    public function testIncompleteUploadCannotBeSealed(): void
    {
        $init = $this->workflow->init(4, 1, 'synthetic.zip', 3);
        $this->expectExceptionMessage('není nahraný celý');
        $this->workflow->complete(4, 1, $init['token']);
    }
}
