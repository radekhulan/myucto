<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Myucto;

use MyInvoice\Service\Migration\Myucto\MyuctoExportReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class MyuctoExportReaderTest extends TestCase
{
    private array $paths = [];
    protected function tearDown(): void { foreach ($this->paths as $path) @unlink($path); }

    public function testExistingSingleCompanyFormatAndEncryptedArchive(): void
    {
        foreach (['', 'synthetic-export-password'] as $password) {
            $path = $this->archive([], [], $password);
            $result = (new MyuctoExportReader())->read($path, $password);
            self::assertSame('Syntetická firma', $result['tables']->get('supplier', 71)['company_name']);
            self::assertSame(['runtime_jobs' => 1], $result['skipped']);
        }
    }

    public function testWrongPasswordIsRejected(): void
    {
        $path = $this->archive([], [], 'synthetic-export-password');
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($path, 'different-synthetic-password');
    }

    public function testCorruptPayloadIsRejectedBeforeRowsAreReturned(): void
    {
        $path = $this->archive(); $zip = new ZipArchive(); $zip->open($path);
        $zip->addFromString('data/supplier.jsonl', '{"id":71,"company_name":"changed"}' . "\n"); $zip->close();
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($path);
    }

    public function testTraversalIsRejectedWithoutExtraction(): void
    {
        $path = $this->archive(); $zip = new ZipArchive(); $zip->open($path);
        $zip->addFromString('../outside.txt', 'synthetic'); $zip->close();
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($path);
    }

    public function testFutureFormatIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($this->archive(['version' => 999]));
    }

    public function testWrongRowCountIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($this->archive([], ['supplier' => ['rows' => 2, 'entry' => 'data/supplier.jsonl']]));
    }

    public function testDuplicateSourceIdsAreRejected(): void
    {
        $path = $this->archive([], [], '', ['supplier' => [['id' => 71], ['id' => 71]]]);
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($path);
    }

    public function testMultipleSourceCompaniesAreRejected(): void
    {
        $path = $this->archive([], [], '', ['supplier' => [['id' => 71], ['id' => 72]]]);
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($path);
    }

    public function testLimitedDateRangeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($this->archive(['range' => ['from' => '2090-01-01', 'to' => null]]));
    }

    public function testOriginalDocumentBytesAndBinaryStatementAreRead(): void
    {
        $path = $this->archive([], [], '', ['purchase_invoices' => [['id' => 81, 'pdf_path' => 'sup-71/original.pdf']]]);
        $zip = new ZipArchive(); $zip->open($path);
        $manifest = json_decode($zip->getFromName('manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        $bytes = "%PDF-synthetic\0";
        $zip->addFromString('doklady/original.pdf', $bytes);
        $manifest['checksums']['doklady/original.pdf'] = ['size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        $manifest['restore']['documents'] = [['entry' => 'doklady/original.pdf', 'storage_path' => 'purchase-invoices/sup-71/original.pdf']];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)); $zip->close();
        $result = (new MyuctoExportReader())->read($path);
        self::assertSame($bytes, file_get_contents($result['assets'][0]['file']));
        self::assertSame(hash('sha256', $bytes), $result['assets'][0]['sha256']);
        self::assertSame('purchase_invoices', $result['assets'][0]['table']);
    }

    public function testHighlyCompressedEntryIsRejectedBeforeExtraction(): void
    {
        $path = $this->archive(); $zip = new ZipArchive(); $zip->open($path);
        $zip->addFromString('data/bomb.jsonl', str_repeat("\n", 8 * 1024 * 1024)); $zip->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kompresní poměr');
        (new MyuctoExportReader())->read($path);
    }

    public function testOversizedLineIsRejected(): void
    {
        $path = $this->archive([], [], '', ['supplier' => [['id' => 71, 'company_name' => str_repeat('x', 200)]]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Řádek exportu překračuje limit');
        (new MyuctoExportReader(maxLineBytes: 128))->read($path);
    }

    public function testRowAndDataLimitsAreEnforcedPerExport(): void
    {
        $path = $this->archive([], [], '', ['clients' => [['id' => 1, 'supplier_id' => 71], ['id' => 2, 'supplier_id' => 71]]]);
        try {
            (new MyuctoExportReader(maxTableRows: 1))->read($path);
            self::fail('Limit řádků tabulky se nevynutil.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('clients překračuje limit 1 řádků', $e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Data pro převod překračují limit');
        (new MyuctoExportReader(maxDataBytes: 64))->read($path);
    }

    public function testMoreRowsThanDeclaredAreRejected(): void
    {
        $path = $this->archive([], ['clients' => ['rows' => 1, 'entry' => 'data/clients.jsonl']], '',
            ['clients' => [['id' => 1, 'supplier_id' => 71], ['id' => 2, 'supplier_id' => 71]]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Počet řádků exportu nesedí: clients');
        (new MyuctoExportReader())->read($path);
    }

    /** Manifest firmy s tisíci příloh má megabajty kontrolních součtů (dřív limit 2 MiB). */
    public function testLargeManifestIsAccepted(): void
    {
        $path = $this->archive(['notes' => bin2hex(random_bytes(1_500_000))]);
        $result = (new MyuctoExportReader())->read($path);
        self::assertSame(1, $result['tables']->count('supplier'));
    }

    /**
     * Tabulka nad dřívějším limitem 100 000 řádků se přečte proudem: v paměti zůstane
     * jen index, ne řádky.
     */
    public function testTableAboveFormerRowLimitIsStreamedWithoutHoldingRows(): void
    {
        $clients = [];
        for ($i = 1; $i <= 120_000; $i++) {
            $clients[] = ['id' => $i, 'supplier_id' => 71, 'company_name' => 'Syntetický odběratel ' . $i, 'city' => 'Test'];
        }
        $path = $this->archive([], [], '', ['clients' => $clients]);
        unset($clients);
        gc_collect_cycles();
        $base = memory_get_usage();
        memory_reset_peak_usage();
        $result = (new MyuctoExportReader())->read($path);
        $peak = memory_get_peak_usage() - $base;
        self::assertSame(120_000, $result['tables']->count('clients'));
        self::assertSame('Syntetický odběratel 120000', $result['tables']->get('clients', 120_000)['company_name']);
        // 120 000 řádků v polích zabere přes 60 MiB, index jen jednotky MiB.
        self::assertLessThan(24 * 1024 * 1024, $peak, 'Čtečka drží řádky v paměti: ' . round($peak / 1048576) . ' MiB.');
    }

    private function archive(array $overrides = [], array $tableOverrides = [], string $password = '', array $rows = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'myucto-export-test-'); $this->paths[] = $path;
        $zip = new ZipArchive(); self::assertTrue($zip->open($path, ZipArchive::OVERWRITE));
        $rows += ['supplier' => [['id' => 71, 'company_name' => 'Syntetická firma']], 'runtime_jobs' => [['id' => 501]]];
        $tables = []; $checksums = [];
        foreach ($rows as $table => $records) {
            $entry = 'data/' . $table . '.jsonl';
            $data = implode("\n", array_map(static fn ($r): string => json_encode($r, JSON_THROW_ON_ERROR), $records)) . "\n";
            $zip->addFromString($entry, $data);
            if ($password !== '') $zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $password);
            $checksums[$entry] = ['sha256' => hash('sha256', $data), 'size' => strlen($data)];
            $tables[$table] = ['entry' => $entry, 'rows' => count($records)];
        }
        $manifest = $overrides + ['format' => 'myucto-instance-export', 'version' => 6, 'supplier' => ['id' => 71],
            'restore' => ['available' => true], 'sections' => ['data' => ['tables' => $tableOverrides + $tables]], 'checksums' => $checksums];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        if ($password !== '') $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256, $password);
        self::assertTrue($zip->close()); return $path;
    }
}
