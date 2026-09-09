<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Export;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Export\Instance\InstanceExportArchive;
use MyInvoice\Service\Export\Instance\InstanceExportService;
use MyInvoice\Service\Export\Instance\InstanceExportSupplementalFiles;
use MyInvoice\Service\Export\Instance\TenantScopeResolver;
use MyInvoice\Service\Pdf\PurchaseInvoicePdfRenderer;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use ZipArchive;

#[Group('integration')]
final class InstanceExportCompletenessTest extends TestCase
{
    private string $temp;
    private string|false $previousDataDir;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->temp = sys_get_temp_dir() . '/instance-completeness-' . bin2hex(random_bytes(8));
        mkdir($this->temp, 0700, true);
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        putenv('MYINVOICE_DATA_DIR=' . $this->temp);
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    protected function tearDown(): void
    {
        putenv($this->previousDataDir === false ? 'MYINVOICE_DATA_DIR' : 'MYINVOICE_DATA_DIR=' . $this->previousDataDir);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->temp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->temp);
    }

    public function testSupplementalFilesIncludeLogosAndOnlyTenantOwnedImportReports(): void
    {
        $this->pdo->exec('CREATE TABLE import_jobs (id INTEGER PRIMARY KEY, supplier_id INTEGER, result_path TEXT)');
        $paths = [
            'supplier-logos/sup-7.png', 'supplier-logos/sup-7.svg',
            'supplier-logos/sup-7-brand-3-012345abcdef.png', 'supplier-logos/sup-7-brand-3-012345abcdef.svg',
            'supplier-logos/sup-70.png', 'supplier-logos/sup-8.png',
            'import-jobs/7/reports/1.json', 'import-jobs/7/reports/2.json',
            'import-jobs/7/reports/3.json', 'import-jobs/8/reports/4.json',
        ];
        foreach ($paths as $path) {
            $this->file($path, 'synthetic:' . $path);
        }
        $this->pdo->exec("INSERT INTO import_jobs VALUES
            (1,7,'import-jobs/7/reports/1.json'),
            (2,8,'import-jobs/7/reports/2.json'),
            (3,7,'import-jobs/8/reports/4.json')");
        $files = (new InstanceExportSupplementalFiles($this->pdo))->forSupplier(7);
        self::assertCount(5, $files);
        $actual = array_column($files, 'storage_path');
        foreach (array_slice($paths, 0, 4) as $path) {
            self::assertContains($path, $actual);
        }
        self::assertContains('import-jobs/7/reports/1.json', $actual);
        foreach ($files as $file) {
            self::assertSame(hash('sha256', 'synthetic:' . $file['storage_path']), $file['sha256']);
        }
    }

    public function testSharedConfigurationExportsCustomValuesAndRemovesGlobalActor(): void
    {
        foreach (['countries', 'vat_rates', 'units', 'tax_constants', 'exchange_rates'] as $table) {
            $this->pdo->exec('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, value TEXT)');
            $this->pdo->exec("INSERT INTO " . $table . " VALUES (901,'synthetic-custom-" . $table . "')");
        }
        $this->pdo->exec('CREATE TABLE email_templates (id INTEGER PRIMARY KEY, body_html TEXT, updated_by INTEGER)');
        $this->pdo->exec("INSERT INTO email_templates VALUES (902,'synthetic-custom-template',424242)");
        $service = $this->service();
        $archive = new InstanceExportArchive($this->temp . '/shared.zip', '', 10_000_000);
        $manifest = (new ReflectionMethod($service, 'exportSharedTables'))->invoke($service, $archive, $this->temp);
        $archive->finish();
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->temp . '/shared.zip'));
        self::assertSame(InstanceExportService::SHARED_TABLES, array_keys($manifest));
        foreach ($manifest as $table => $meta) {
            self::assertSame(1, $meta['rows']);
            $row = json_decode((string) $zip->getFromName($meta['entry']), true, flags: JSON_THROW_ON_ERROR);
            if ($table === 'email_templates') {
                self::assertSame('synthetic-custom-template', $row['body_html']);
                self::assertArrayNotHasKey('updated_by', $row);
            } else {
                self::assertSame('synthetic-custom-' . $table, $row['value']);
            }
        }
        $zip->close();
    }

    public function testPurchaseSourceAndReconstructedPdfAreRestorableAndMissingOriginalIsReported(): void
    {
        $this->documentFixture();
        $service = $this->service();
        $archive = new InstanceExportArchive($this->temp . '/documents.zip', '', 10_000_000);
        $blobs = [];
        $documents = [];
        $args = [$archive, 7, null, null, null, null, $this->temp, &$blobs, &$documents];
        $summary = (new ReflectionMethod($service, 'exportDocuments'))->invokeArgs($service, $args);
        $archive->finish();
        self::assertSame(1, $summary['prijate_pdf']);
        self::assertSame(1, $summary['warnings']);
        self::assertStringContainsString('rekonstrukci', $summary['warning_details'][0]);
        self::assertCount(2, $documents);
        self::assertSame('purchase_invoice_source', $documents[0]['kind']);
        self::assertSame('purchase-invoices/sources/supplier-7/source.isdoc', $documents[0]['storage_path']);
        self::assertSame('purchase_invoice_reconstructed_pdf', $documents[1]['kind']);
        self::assertSame(['table' => 'purchase_invoices', 'id' => 11, 'column' => 'pdf_path', 'value' => 'sup-7/restored/purchase-11.pdf'], $documents[1]['link']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->temp . '/documents.zip'));
        self::assertSame('<Invoice>synthetic</Invoice>', $zip->getFromName($documents[0]['entry']));
        self::assertStringStartsWith('%PDF', (string) $zip->getFromName($documents[1]['entry']));
        foreach ($documents as $document) {
            self::assertSame($document['sha256'], hash('sha256', (string) $zip->getFromName($document['entry'])));
        }
        $zip->close();
    }

    public function testRestorableExportIncludesOldDocumentsBlobsAndAccountingArchivesOutsideSelectedDates(): void
    {
        $this->documentFixture();
        $this->pdo->exec('ALTER TABLE supplier ADD accounting_mode TEXT');
        $this->pdo->exec('ALTER TABLE supplier ADD is_vat_payer INTEGER');
        $this->pdo->exec('ALTER TABLE supplier ADD vat_period TEXT');
        $this->pdo->exec("INSERT INTO supplier (id, company_name) VALUES (7,'Synthetic export')");
        $this->pdo->exec('CREATE TABLE user_suppliers (user_id INTEGER, supplier_id INTEGER, role TEXT, role_id INTEGER, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE import_jobs (id INTEGER, supplier_id INTEGER, result_path TEXT)');
        $this->pdo->exec("INSERT INTO bank_statements (id,supplier_id,statement_date,file_name,file_content) VALUES (91,7,'2020-01-01','synthetic.gpc','synthetic-bank-source')");
        $this->file('archives/sup-7/archive-2020.zip', 'synthetic-accounting-archive');
        $this->file('archives/sup-8/foreign.zip', 'foreign-archive');
        $this->file('supplier-logos/sup-7.svg', '<svg>synthetic</svg>');
        $this->pdo->beginTransaction();
        $result = $this->service()->runForSupplier(7, [InstanceExportService::PART_RESTORE], '2026-01-01', '2026-12-31', $this->temp);
        self::assertTrue($this->pdo->inTransaction());
        $this->pdo->rollBack();
        $manifest = $result['manifest'];
        self::assertSame(1, $manifest['sections']['doklady']['prijate_pdf']);
        self::assertCount(1, $manifest['restore']['blobs']);
        self::assertCount(2, $manifest['restore']['documents']);
        self::assertSame(['from' => '2026-01-01', 'to' => '2026-12-31'], $manifest['range']);
        self::assertSame('non-atomic', $manifest['data_snapshot']);
        self::assertSame('caller-transaction', $manifest['database_snapshot']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($result['abs_path']));
        self::assertSame('synthetic-bank-source', $zip->getFromName($manifest['restore']['blobs'][0]['entry']));
        $paths = array_column($manifest['restore']['files'], 'storage_path');
        self::assertContains('archives/sup-7/archive-2020.zip', $paths);
        self::assertContains('supplier-logos/sup-7.svg', $paths);
        self::assertNotContains('archives/sup-8/foreign.zip', $paths);
        self::assertSame('synthetic-accounting-archive', $zip->getFromName('prilohy/ucetni-archivy/archive-2020.zip'));
        $zip->close();
    }

    private function documentFixture(): void
    {
        $this->pdo->exec('CREATE TABLE invoices (id INTEGER, supplier_id INTEGER, varsymbol TEXT, invoice_type TEXT, issue_date TEXT, imported_pdf_path TEXT)');
        $this->pdo->exec('CREATE TABLE purchase_invoices (id INTEGER, supplier_id INTEGER, vendor_id INTEGER, varsymbol TEXT, vendor_invoice_number TEXT, issue_date TEXT, pdf_path TEXT, source_path TEXT)');
        $this->pdo->exec('CREATE TABLE clients (id INTEGER, company_name TEXT, street TEXT, city TEXT, zip TEXT, ic TEXT, dic TEXT, main_email TEXT, phone TEXT, country_id INTEGER)');
        $this->pdo->exec('CREATE TABLE supplier (id INTEGER, company_name TEXT, street TEXT, city TEXT, zip TEXT, ic TEXT, dic TEXT, email TEXT, phone TEXT, country_id INTEGER)');
        $this->pdo->exec('CREATE TABLE countries (id INTEGER, iso2 TEXT)');
        $this->pdo->exec('CREATE TABLE bank_statements (id INTEGER, supplier_id INTEGER, statement_date TEXT, account_number TEXT, file_name TEXT, pdf_name TEXT, file_content BLOB, pdf_content BLOB)');
        $this->pdo->exec("INSERT INTO purchase_invoices VALUES (11,7,1,'SYN-11','SYN-11','2020-01-01','supplier-7/missing.pdf','sources/supplier-7/source.isdoc')");
        $this->file('purchase-invoices/sources/supplier-7/source.isdoc', '<Invoice>synthetic</Invoice>');
    }

    private function service(): InstanceExportService
    {
        $schema = ['tables' => [], 'columns' => [], 'primaryKeys' => [], 'foreignKeys' => []];
        foreach ($this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $schema['tables'][$table] = 'BASE TABLE';
            $schema['primaryKeys'][$table] = ['cols' => [], 'autoInc' => null];
            foreach ($this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $schema['columns'][$table][$column['name']] = [
                    'COLUMN_NAME' => $column['name'], 'COLUMN_KEY' => $column['pk'] ? 'PRI' : '',
                    'DATA_TYPE' => strtolower($column['type']), 'EXTRA' => '', 'GENERATION_EXPRESSION' => '',
                ];
                if ($column['pk']) {
                    $schema['primaryKeys'][$table]['cols'][] = $column['name'];
                }
            }
        }
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($this->pdo);
        $db->method('schemaSnapshot')->willReturn($schema);
        $repo = $this->createStub(PurchaseInvoiceRepository::class);
        $repo->method('find')->willReturn(['id' => 11, 'vendor_id' => 1, 'items' => [], 'vendor_invoice_number' => 'SYN-11']);
        $config = new Config([]);
        $reflection = new ReflectionClass(InstanceExportService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        foreach (['db' => $db, 'config' => $config, 'log' => new NullLogger(), 'scopes' => new TenantScopeResolver($db),
            'purchasePdf' => new PurchaseInvoicePdfRenderer($repo, $db, $config)] as $name => $value) {
            $reflection->getProperty($name)->setValue($service, $value);
        }
        return $service;
    }

    private function file(string $relative, string $content): void
    {
        $path = $this->temp . '/storage/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $content);
    }
}
