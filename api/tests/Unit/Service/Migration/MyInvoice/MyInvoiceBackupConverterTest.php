<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\MyInvoice;

use MyInvoice\Service\Migration\MyInvoice\MyInvoiceBackupConverter;
use MyInvoice\Service\Migration\Myucto\MyuctoExportReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class MyInvoiceBackupConverterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/myinvoice-backup-unit-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->root . '/.myinvoice-convert-*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testConvertsSelectedCompanyWithRecurringAndOriginalsWithoutIdentities(): void
    {
        $backup = $this->zip('db.zip', ['synthetic.sql' => $this->dump()]);
        $files = $this->zip('files.zip', [
            'storage/invoices-imported/sup-1/original.pdf' => '%PDF-synthetic',
            'storage/purchase-invoices/sup-1/original.isdoc' => '<synthetic/>',
            'storage/documents/private.txt' => 'excluded',
        ]);
        $out = $this->root . '/converted.zip';
        $result = (new MyInvoiceBackupConverter())->convert($backup, [$files], $out, 1, 'tax_evidence', $this->history());
        self::assertSame(2, $result['files']);
        $package = (new MyuctoExportReader())->read($out);
        $tables = $package['tables']->toArrays();
        self::assertCount(1, $tables['supplier']);
        self::assertCount(1, $tables['clients']);
        self::assertSame('Selected', $tables['clients'][11]['company_name']);
        self::assertSame('121.00', $tables['recurring_invoice_template_items'][31]['unit_price_without_vat']);
        self::assertSame('1', $tables['recurring_invoice_templates'][21]['prices_include_vat']);
        self::assertSame(1, $tables['bank_statements'][61]['supplier_id']);
        self::assertSame("\0\xff", $tables['bank_statements'][61]['file_content']);
        self::assertFalse($package['tables']->hasTable('users'));
        self::assertFalse($package['tables']->hasTable('branding_profiles'));
        self::assertCount(2, $package['assets']);
        $zip = new ZipArchive();
        $zip->open($out);
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                self::assertStringNotContainsString('excluded-password', $zip->getFromIndex($i));
            }
        } finally {
            $zip->close();
        }
    }

    public function testSelectsCompanyByTargetIcInTheCompleteBackup(): void
    {
        $backup = $this->zip('db.zip', ['synthetic.sql' => $this->dump(false)]);
        $out = $this->root . '/by-ic.zip';
        (new MyInvoiceBackupConverter())->convert($backup, [], $out, 0, 'tax_evidence', $this->history(), '', '', '00000000');
        self::assertSame([1], (new MyuctoExportReader())->read($out)['tables']->ids('supplier'));
    }

    public function testAmbiguousCompanyIcIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        MyInvoiceBackupConverter::companyByIc([
            ['id' => 1, 'ic' => '00000000'], ['id' => 2, 'ic' => '00000000'],
        ], '00000000');
    }

    public function testMissingCompanyIcIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        MyInvoiceBackupConverter::companyByIc([['id' => 1, 'ic' => '00000000']], '99999999');
    }

    public function testMissingOriginalFailsWithoutPublishingPartialOutput(): void
    {
        $backup = $this->zip('db.zip', ['synthetic.sql' => $this->dump()]);
        $out = $this->root . '/converted.zip';
        try {
            (new MyInvoiceBackupConverter())->convert($backup, [], $out, 1, 'tax_evidence', $this->history());
            self::fail('Originály se nesmějí tiše vynechat.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Chybí originál', $e->getMessage());
        }
        self::assertFileDoesNotExist($out);
        self::assertSame([], glob($this->root . '/.myinvoice-convert-*'));
    }

    public function testEncryptedInputProducesEncryptedCompatibleOutput(): void
    {
        $password = 'synthetic-only-password';
        $backup = $this->zip('db.zip', ['synthetic.sql' => $this->dump(false)], $password);
        $out = $this->root . '/converted.zip';
        $result = (new MyInvoiceBackupConverter())->convert($backup, [], $out, 1, 'tax_evidence', $this->history(), $password, $password);
        self::assertTrue($result['encrypted']);
        self::assertSame(1, (new MyuctoExportReader())->read($out, $password)['tables']->count('invoices'));
        $zip = new ZipArchive();
        $zip->open($out);
        self::assertFalse($zip->getFromName('manifest.json'));
        $zip->close();
    }

    public function testChangedOriginalIsRejectedBeforePublishing(): void
    {
        $backup = $this->zip('db.zip', ['synthetic.sql' => $this->dump()]);
        $files = $this->zip('files.zip', [
            'storage/invoices-imported/sup-1/original.pdf' => '%PDF-changed',
            'storage/purchase-invoices/sup-1/original.isdoc' => '<synthetic/>',
        ]);
        $out = $this->root . '/converted.zip';
        try {
            (new MyInvoiceBackupConverter())->convert($backup, [$files], $out, 1, 'tax_evidence', $this->history());
            self::fail('Změněný originál se nesmí obnovit.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('otiskem', $e->getMessage());
        }
        self::assertFileDoesNotExist($out);
    }

    public function testCliConversionWithSmallOriginalsFits64MiB(): void
    {
        $backup = $this->zip('db.zip', ['synthetic.sql' => $this->dump()]);
        $files = $this->zip('files.zip', [
            'storage/invoices-imported/sup-1/original.pdf' => '%PDF-synthetic',
            'storage/purchase-invoices/sup-1/original.isdoc' => '<synthetic/>',
        ]);
        $history = $this->root . '/history.json';
        file_put_contents($history, json_encode($this->history(), JSON_THROW_ON_ERROR));
        $out = $this->root . '/out.zip';
        $process = proc_open([
            PHP_BINARY, '-d', 'memory_limit=64M',
            dirname(__DIR__, 5) . '/bin/myinvoice-backup-convert.php',
            '--backup=' . $backup, '--files=' . $files, '--company=1',
            '--accounting-mode=tax_evidence', '--vat-history=' . $history, '--output=' . $out,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            array_merge(getenv(), ['MYINVOICE_BACKUP_PASSWORD' => '', 'MYINVOICE_BACKUP_OUTPUT_PASSWORD' => '']));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr . $stdout);
        self::assertSame(2, json_decode($stdout, true, 64, JSON_THROW_ON_ERROR)['files']);
        self::assertFileExists($out);
    }

    public function testExistingOutputCannotBeOverwritten(): void
    {
        $out = $this->root . '/existing.zip';
        file_put_contents($out, 'keep');
        try {
            (new MyInvoiceBackupConverter())->convert('unused', [], $out, 1, 'tax_evidence', $this->history());
            self::fail('Existující výstup se nesmí přepsat.');
        } catch (RuntimeException) {
            self::assertSame('keep', file_get_contents($out));
        }
    }

    public function testUnsafeZipEntryIsRejected(): void
    {
        $backup = $this->zip('db.zip', ['../synthetic.sql' => $this->dump(false)]);
        $this->expectExceptionMessage('neplatnou');
        (new MyInvoiceBackupConverter())->convert($backup, [], $this->root . '/out.zip', 1, 'tax_evidence', $this->history());
    }

    private function history(): array
    {
        return [['effective_from' => '1900-01-01', 'is_vat_payer' => 1,
            'is_identified' => 0, 'annual_deduction_percent' => null]];
    }

    private function zip(string $file, array $entries, string $password = ''): string
    {
        $path = $this->root . '/' . $file;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
            if ($password !== '') {
                self::assertTrue($zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password));
            }
        }
        $zip->close();
        return $path;
    }

    private function dump(bool $withFiles = true): string
    {
        $tables = [
            'supplier' => [
                ['id' => 1, 'company_name' => 'Selected company', 'ic' => '00000000', 'country_id' => 1, 'default_currency_id' => 1],
                ['id' => 2, 'company_name' => 'Other company', 'ic' => '99999999', 'country_id' => 1, 'default_currency_id' => 2],
            ],
            'countries' => [['id' => 1, 'iso2' => 'CZ']],
            'vat_rates' => [['id' => 1, 'code' => 'synthetic', 'rate_percent' => '21.00', 'country' => 'CZ']],
            'currencies' => [
                ['id' => 1, 'supplier_id' => 1, 'code' => 'CZK', 'account_number' => '1000000005', 'bank_code' => '0100'],
                ['id' => 2, 'supplier_id' => 2, 'code' => 'CZK', 'account_number' => '1000000005', 'bank_code' => '0300'],
            ],
            'clients' => [
                ['id' => 11, 'supplier_id' => 1, 'company_name' => 'Selected', 'api_key' => 'excluded-password'],
                ['id' => 12, 'supplier_id' => 2, 'company_name' => 'Other', 'api_key' => 'excluded-password'],
            ],
            'recurring_invoice_templates' => [['id' => 21, 'supplier_id' => 1, 'client_id' => 11, 'prices_include_vat' => 1]],
            'recurring_invoice_template_items' => [['id' => 31, 'template_id' => 21, 'unit_price_without_vat' => '121.00']],
            'invoices' => [['id' => 41, 'supplier_id' => 1, 'prices_include_vat' => 1, 'imported_pdf_path' => $withFiles ? 'sup-1/original.pdf' : null, 'imported_pdf_hash' => $withFiles ? hash('sha256', '%PDF-synthetic') : null]],
            'purchase_invoices' => [['id' => 51, 'supplier_id' => 1, 'prices_include_vat' => 1, 'source_path' => $withFiles ? 'sup-1/original.isdoc' : null, 'source_hash' => $withFiles ? hash('sha256', '<synthetic/>') : null]],
            'bank_statements' => [['id' => 61, 'account_number' => '1000000005', 'bank_code' => '0100', 'file_content' => "\0\xff"]],
            'bank_transactions' => [['id' => 71, 'statement_id' => 61, 'amount' => '121.00']],
            'users' => [['id' => 1, 'password_hash' => 'excluded-password']],
        ];
        $sql = '';
        foreach ($tables as $table => $rows) {
            $sql .= 'CREATE TABLE `' . $table . "` (\n";
            $sql .= implode(",\n", array_map(static fn ($c) => '  `' . $c . '` text', array_keys($rows[0])));
            $sql .= "\n);\n";
            foreach ($rows as $row) {
                $values = array_map(static fn ($v) => $v === null ? 'NULL' : "'" . str_replace(['\\', "'", "\0"], ['\\\\', "\\'", '\\0'], (string) $v) . "'", array_values($row));
                $sql .= 'INSERT INTO `' . $table . '` VALUES (' . implode(',', $values) . ");\n";
            }
        }
        return $sql;
    }
}
