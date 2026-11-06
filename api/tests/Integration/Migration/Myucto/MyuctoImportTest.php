<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\Myucto;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\SchemaMetadataProvider;
use MyInvoice\Service\Export\Instance\InstanceExportBinaryCodec;
use MyInvoice\Service\Export\Instance\TenantScopeResolver;
use MyInvoice\Service\Migration\Myucto\MyuctoExportReader;
use MyInvoice\Service\Migration\Myucto\MyuctoImporter;
use MyInvoice\Service\Migration\Myucto\MyuctoImportFiles;
use MyInvoice\Service\Migration\Myucto\MyuctoImportProfile;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[Group('integration')]
final class MyuctoImportTest extends TestCase
{
    use IsolatedSupplierTrait;
    private Connection $db;
    private PDO $pdo;
    private int $source;
    private int $target;
    private int $actor;
    private int $invoice;
    private int $purchase;
    private string $archive;
    private string $root;
    private MyuctoImporter $importer;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class); $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $base = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->actor = (int) $this->pdo->query('SELECT id FROM users WHERE is_active = 1 LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $base); self::assertGreaterThan(0, $this->actor);
        $this->source = $this->createIsolatedSupplier($this->pdo, $base);
        $this->target = $this->createIsolatedSupplier($this->pdo, $base);
        foreach ([$this->source, $this->target] as $id) {
            $this->pdo->prepare("UPDATE supplier SET ic = '00000000', accounting_mode = 'double_entry', company_name = 'Syntetický import', email = 'source-import@example.test' WHERE id = ?")->execute([$id]);
            $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_default) VALUES (?, 'CZK', 'Syntetická měna', 'Kč', 'Syntetická měna', 'Synthetic currency', 1)")->execute([$id]);
            $currency = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currency, $id]);
        }
        $currency = (int) $this->pdo->query('SELECT default_currency_id FROM supplier WHERE id = ' . $this->source)->fetchColumn();
        $country = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $vat = (int) $this->pdo->query("SELECT id FROM vat_rates WHERE rate_percent = 21 AND country = 'CZ' AND is_reverse_charge = 0 LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $vat);
        $this->pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id) VALUES (?, 'Syntetický partner', 'Test 1', 'Test', '11000', ?, 'partner@example.test', ?)")->execute([$this->source, $country, $currency]);
        $client = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO recurring_invoice_templates (supplier_id, client_id, currency_id, name, frequency, anchor_date, next_run_date, created_by, prices_include_vat, auto_issue, auto_send_email) VALUES (?, ?, ?, 'Syntetický paušál', 'monthly', '2090-01-01', '2090-02-01', ?, 1, 1, 1)")->execute([$this->source, $client, $currency, $this->actor]);
        $template = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO recurring_invoice_template_items (template_id, description, unit_price_without_vat, vat_rate_id) VALUES (?, 'Syntetický paušál', 121, ?)")->execute([$template, $vat]);
        $snapshot = json_encode(['id' => $this->source, 'company_name' => 'Syntetický import'], JSON_THROW_ON_ERROR);
        $this->pdo->prepare("INSERT INTO invoices (supplier_id, client_id, currency_id, invoice_type, varsymbol, issue_date, tax_date, due_date, created_by, recurring_template_id, prices_include_vat, total_without_vat, total_vat, total_with_vat, supplier_snapshot) VALUES (?, ?, ?, 'invoice', 'SYNTHETIC-2090-01', '2090-01-01', '2090-01-01', '2090-01-15', ?, ?, 1, 100, 21, 121, ?)")->execute([$this->source, $client, $currency, $this->actor, $template, $snapshot]);
        $this->invoice = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("UPDATE invoices SET status = 'issued', auto_send_reminders = 1, approval_status = 'requested' WHERE id = ?")->execute([$this->invoice]);
        $this->pdo->prepare("INSERT INTO invoice_items (invoice_id, description, unit_price_without_vat, vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat) VALUES (?, 'Syntetická služba', 121, ?, 21, 100, 21, 121)")->execute([$this->invoice, $vat]);
        $bytes = "%PDF-synthetic-original\n";
        $this->pdo->prepare("INSERT INTO purchase_invoices (supplier_id, vendor_id, currency_id, vendor_invoice_number, vendor_snapshot, issue_date, tax_date, due_date, received_at, received_at_source, created_by, status, prices_include_vat, total_without_vat, total_vat, total_with_vat, pdf_path, pdf_hash) VALUES (?, ?, ?, 'SYNTHETIC-PURCHASE-2090-01', ?, '2090-01-05', '2090-01-05', '2090-02-15', '2090-02-01', 'manual', ?, 'received', 1, 100, 21, 121, ?, ?)")->execute([$this->source, $client, $currency, json_encode(['company_name' => 'Syntetický partner'], JSON_THROW_ON_ERROR), $this->actor, 'sup-' . $this->source . '/synthetic-original.pdf', hash('sha256', $bytes)]);
        $this->purchase = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO purchase_invoice_items (purchase_invoice_id, description, unit_price_without_vat, vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat) VALUES (?, 'Syntetický náklad', 121, ?, 21, 100, 21, 121)")->execute([$this->purchase, $vat]);
        $this->pdo->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on) VALUES (?, 2090, '2090-01-01', '2090-12-31')")->execute([$this->source]);
        $period = (int) $this->pdo->lastInsertId(); $accounts = [];
        foreach ([['311990', 'asset'], ['602990', 'revenue'], ['343990', 'liability']] as [$code, $type]) {
            $this->pdo->prepare("INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type) VALUES (?, ?, 'Syntetický účet', ?)")->execute([$this->source, $code, $type]);
            $accounts[] = (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare("INSERT INTO journal_entries (supplier_id, period_id, entry_date, description, source_type, source_id, posted_at) VALUES (?, ?, '2090-01-01', 'Syntetický zápis', 'invoice', ?, '2090-01-01 00:00:00')")->execute([$this->source, $period, $this->invoice]);
        $entry = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO journal_document_postings (supplier_id, doc_type, doc_id, entry_id) VALUES (?, 'invoice', ?, ?)")
            ->execute([$this->source, $this->invoice, $entry]);
        foreach ([['debit', '121.00'], ['credit', '100.00'], ['credit', '21.00']] as $index => [$side, $amount]) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines (supplier_id, entry_id, account_id, line_no, side, amount) VALUES (?, ?, ?, ?, ?, ?)')->execute([$this->source, $entry, $accounts[$index], $index + 1, $side, $amount]);
        }
        $this->root = sys_get_temp_dir() . '/myucto-native-import-' . bin2hex(random_bytes(6)); mkdir($this->root, 0700);
        $scopes = new TenantScopeResolver($this->db);
        $this->importer = new MyuctoImporter($this->db, $scopes, new MyuctoImportFiles(new Config(['purchase_invoice' => ['archive_storage' => $this->root . '/purchase-invoices']])));
        $this->seedClassifications();
        $this->archive = $this->makeArchive();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->archive)) @unlink($this->archive);
        if (isset($this->root)) $this->removeDir($this->root);
        if (!isset($this->source, $this->target)) return;
        $ids = [$this->source, $this->target];
        foreach ($ids as $id) {
            $this->pdo->prepare('DELETE bt FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id WHERE bs.supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM bank_statements WHERE supplier_id = ?')->execute([$id]);
            foreach (['de_movement_classification_history', 'de_movement_classification', 'posting_rules', 'cash_documents', 'cash_registers', 'journal_entry_lines', 'journal_entries', 'accounting_periods', 'chart_of_accounts'] as $table) $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE pii FROM purchase_invoice_items pii JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id WHERE pi.supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE ii FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE i.supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE ri FROM recurring_invoice_template_items ri JOIN recurring_invoice_templates r ON r.id = ri.template_id WHERE r.supplier_id = ?')->execute([$id]);
            foreach (['external_entity_map', 'recurring_invoice_templates', 'clients', 'supplier_vat_status_history', 'supplier_accounting_frameworks'] as $table) $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE supplier_id = ?')->execute([$id]);
            $baseCurrency = (int) $this->pdo->query('SELECT default_currency_id FROM supplier WHERE id NOT IN (' . $this->source . ', ' . $this->target . ') ORDER BY id LIMIT 1')->fetchColumn();
            $this->pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$baseCurrency, $id]);
            $this->pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$id]);
        }
    }

    public function testDryRunApplyAndRepeatPreserveAmountsAndTargetConfiguration(): void
    {
        $package = (new MyuctoExportReader())->read($this->archive);
        $before = $this->pdo->query('SELECT * FROM supplier WHERE id = ' . $this->target)->fetch(PDO::FETCH_ASSOC);
        $dry = $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance');
        self::assertSame(1, $dry['created']['invoices']); self::assertSame(0, $this->targetCount('invoices'));
        self::assertSame(0, $this->targetCount('external_entity_map'));
        self::assertSame([], glob($this->root . '/purchase-invoices/sup-' . $this->target . '/myucto-import/*/*') ?: []);
        $applied = $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
        self::assertSame(1, $applied['reconciliation']['invoice_items']);
        self::assertSame(3, $applied['reconciliation']['journal_entry_lines']);
        $purchase = $this->pdo->query('SELECT * FROM purchase_invoices WHERE supplier_id = ' . $this->target)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, $applied['files']);
        self::assertStringStartsWith('sup-' . $this->target . '/', $purchase['pdf_path']);
        self::assertSame("%PDF-synthetic-original\n", file_get_contents($this->root . '/purchase-invoices/' . $purchase['pdf_path']));
        $row = $this->pdo->query('SELECT * FROM invoices WHERE supplier_id = ' . $this->target)->fetch(PDO::FETCH_ASSOC);
        self::assertNotSame($this->invoice, (int) $row['id']); self::assertSame('100.00', $row['total_without_vat']);
        self::assertSame('21.00', $row['total_vat']); self::assertSame('121.00', $row['total_with_vat']); self::assertSame(1, (int) $row['prices_include_vat']);
        self::assertSame(0, (int) $row['auto_send_reminders']); self::assertSame('none', $row['approval_status']); self::assertNull($row['public_token']);
        self::assertSame($this->target, json_decode($row['supplier_snapshot'], true)['id']);
        $journal = $this->pdo->query('SELECT source_id FROM journal_entries WHERE supplier_id = ' . $this->target)->fetchColumn();
        self::assertSame((int) $row['id'], (int) $journal);
        $untraced = $this->pdo->query("SELECT COUNT(*) FROM journal_entries e WHERE e.supplier_id = {$this->target}
            AND e.posted_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM activity_log al
                WHERE al.entity_type = 'journal_entry' AND al.entity_id = e.id AND al.action = 'accounting.posted')")->fetchColumn();
        self::assertSame(0, (int) $untraced, 'Obnovený zaúčtovaný zápis musí mít auditní stopu (§ 33a, I28).');
        $posting = $this->pdo->query('SELECT jdp.doc_id, e.source_id FROM journal_document_postings jdp
            JOIN journal_entries e ON e.id=jdp.entry_id AND e.supplier_id=jdp.supplier_id
            WHERE jdp.supplier_id=' . $this->target)->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($posting, 'Obnova musí zachovat vlastnictví zaúčtování, nikoli jen účetní řádky.');
        self::assertSame((int) $row['id'], (int) $posting['doc_id']);
        self::assertSame((int) $row['id'], (int) $posting['source_id']);
        $ledger = Bootstrap::buildContainer()->get(\MyInvoice\Service\Report\VatLedgerService::class);
        $january = $ledger->rows($this->target, '2090-01-01', '2090-01-31');
        $february = $ledger->rows($this->target, '2090-02-01', '2090-02-28');
        self::assertCount(1, $january); self::assertSame('sale', $january[0]['source']); self::assertEquals(21, $january[0]['vat_czk']);
        self::assertCount(1, $february); self::assertSame('purchase', $february[0]['source']); self::assertEquals(21, $february[0]['vat_czk']);
        $template = $this->pdo->query('SELECT * FROM recurring_invoice_templates WHERE supplier_id = ' . $this->target)->fetch(PDO::FETCH_ASSOC);
        self::assertSame('paused', $template['status']); self::assertSame(0, (int) $template['auto_issue']); self::assertSame(0, (int) $template['auto_send_email']);
        self::assertSame((int) $template['id'], (int) $row['recurring_template_id']);
        self::assertSame($before, $this->pdo->query('SELECT * FROM supplier WHERE id = ' . $this->target)->fetch(PDO::FETCH_ASSOC));
        $repeat = $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
        self::assertSame([], $repeat['created']); self::assertSame(1, $repeat['existing']['invoices']); self::assertSame(1, $this->targetCount('invoices'));
    }

    public function testUnusedSetupPeriodOfNewCompanyIsReplacedByExportPeriods(): void
    {
        $this->pdo->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, created_reason) VALUES (?, 2090, '2090-01-01', '2090-12-31', 'setup')")
            ->execute([$this->target]);
        $package = (new MyuctoExportReader())->read($this->archive);
        $applied = $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
        self::assertSame([['fiscal_year' => 2090, 'starts_on' => '2090-01-01', 'ends_on' => '2090-12-31']], $applied['replaced_periods']);
        self::assertSame(1, $applied['created']['accounting_periods']);
        self::assertSame(1, $this->targetCount('accounting_periods'));
        self::assertNull($this->pdo->query('SELECT created_reason FROM accounting_periods WHERE supplier_id = ' . $this->target)->fetchColumn());
    }

    public function testUsedTargetPeriodStillBlocksImport(): void
    {
        $this->pdo->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status, created_reason) VALUES (?, 2090, '2090-01-01', '2090-12-31', 'closed', 'setup')")
            ->execute([$this->target]);
        $package = (new MyuctoExportReader())->read($this->archive);
        try { $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false); self::fail('Použité období nesmí převod přepsat.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('accounting_periods', $e->getMessage()); }
        self::assertSame(0, $this->targetCount('invoices'));
        self::assertSame('closed', $this->pdo->query('SELECT status FROM accounting_periods WHERE supplier_id = ' . $this->target)->fetchColumn());
    }

    public function testCrossTenantRowIsRejectedBeforeAnyWrite(): void
    {
        $package = (new MyuctoExportReader())->read($this->archive);
        $id = array_key_first($package['tables']['clients']); $package['tables']['clients'][$id]['supplier_id'] = $this->target;
        try { $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false); self::fail('Cizí firma nesmí projít.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('jiné firmy', $e->getMessage()); }
        self::assertSame(0, $this->targetCount('invoices')); self::assertSame(0, $this->targetCount('clients'));
    }

    public function testRestoreRemapsEveryPostingAndBothDocumentTypes(): void
    {
        $package = (new MyuctoExportReader())->read($this->archive);
        $originalEntry = reset($package['tables']['journal_entries']);
        $originalPosting = reset($package['tables']['journal_document_postings']);
        $nextEntry = max(array_keys($package['tables']['journal_entries']));
        $nextPosting = max(array_keys($package['tables']['journal_document_postings']));
        $nextLine = max(array_keys($package['tables']['journal_entry_lines']));
        $originalLines = array_filter($package['tables']['journal_entry_lines'], static fn (array $line): bool => (int) $line['entry_id'] === (int) $originalEntry['id']);
        foreach ([['manual', null, 'invoice', $this->invoice], ['purchase_invoice', $this->purchase, 'purchase_invoice', $this->purchase]] as [$sourceType, $sourceId, $docType, $docId]) {
            $entry = array_replace($originalEntry, ['id' => ++$nextEntry, 'source_type' => $sourceType, 'source_id' => $sourceId]);
            $package['tables']['journal_entries'][$nextEntry] = $entry;
            ++$nextPosting;
            $package['tables']['journal_document_postings'][$nextPosting] = array_replace($originalPosting, [
                'id' => $nextPosting, 'entry_id' => $nextEntry, 'doc_type' => $docType, 'doc_id' => $docId,
            ]);
            foreach ($originalLines as $line) {
                ++$nextLine;
                $package['tables']['journal_entry_lines'][$nextLine] = array_replace($line, ['id' => $nextLine, 'entry_id' => $nextEntry]);
            }
        }

        $report = $this->importer->import($package, $this->target, $this->actor, 'synthetic-groups', false);
        self::assertSame(3, $report['created']['journal_document_postings']);
        $invoice = (int) $this->pdo->query('SELECT id FROM invoices WHERE supplier_id=' . $this->target)->fetchColumn();
        $purchase = (int) $this->pdo->query('SELECT id FROM purchase_invoices WHERE supplier_id=' . $this->target)->fetchColumn();
        $rows = $this->pdo->query('SELECT jdp.doc_type, jdp.doc_id, e.source_type, e.source_id
            FROM journal_document_postings jdp JOIN journal_entries e ON e.supplier_id=jdp.supplier_id AND e.id=jdp.entry_id
            WHERE jdp.supplier_id=' . $this->target . ' ORDER BY jdp.id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([
            ['invoice', $invoice, 'invoice', $invoice],
            ['invoice', $invoice, 'manual', null],
            ['purchase_invoice', $purchase, 'purchase_invoice', $purchase],
        ], array_map(static fn (array $row): array => [$row['doc_type'], (int) $row['doc_id'], $row['source_type'], $row['source_id'] === null ? null : (int) $row['source_id']], $rows));
        $repeat = $this->importer->import($package, $this->target, $this->actor, 'synthetic-groups', false);
        self::assertSame(3, $repeat['existing']['journal_document_postings']);
        self::assertSame(3, $this->targetCount('journal_document_postings'));
    }

    public function testMissingPostingDocumentRollsBackEntireGraph(): void
    {
        $package = (new MyuctoExportReader())->read($this->archive);
        $posting = array_key_first($package['tables']['journal_document_postings']);
        $package['tables']['journal_document_postings'][$posting]['doc_id'] = 999999999;
        try {
            $this->importer->import($package, $this->target, $this->actor, 'synthetic-missing-group', false);
            self::fail('Vlastnictví bez zdrojového dokladu nesmí projít.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Chybí zdrojová vazba journal_document_postings.doc_id', $e->getMessage());
        }
        self::assertSame(0, $this->targetCount('journal_document_postings'));
        self::assertSame(0, $this->targetCount('invoices'));
        self::assertSame(0, $this->targetCount('external_entity_map'));
    }

    public function testMissingReferenceRollsBackEntireGraph(): void
    {
        $package = (new MyuctoExportReader())->read($this->archive);
        $package['tables']['invoices'][$this->invoice]['client_id'] = 999999999;
        try { $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false); self::fail('Chybějící vazba nesmí projít.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('Chybí zdrojová vazba', $e->getMessage()); }
        self::assertSame(0, $this->targetCount('clients')); self::assertSame(0, $this->targetCount('external_entity_map'));
    }

    public function testChangedSourceIsRejectedAfterApply(): void
    {
        $package = (new MyuctoExportReader())->read($this->archive);
        $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
        $package['tables']['invoices'][$this->invoice]['total_vat'] = '22.00';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('jinými daty');
        $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
    }

    public function testDifferentCompanyIdentityIsRejected(): void
    {
        $package = (new MyuctoExportReader())->read($this->archive);
        $package['tables']['supplier'][$this->source]['ic'] = '99999999';
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('ic');
        $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
    }

    public function testReadsArchiveProducedByExistingCompleteExport(): void
    {
        $oldDataDir = getenv('MYINVOICE_DATA_DIR');
        try {
            putenv('MYINVOICE_DATA_DIR=' . $this->root . '/native-runtime');
            $container = Bootstrap::buildContainer();
            $config = $container->get(Config::class);
            $sourcePath = (string) $config->get('purchase_invoice.archive_storage') . '/sup-' . $this->source . '/synthetic-original.pdf';
            mkdir(dirname($sourcePath), 0700, true); file_put_contents($sourcePath, "%PDF-synthetic-original\n");
            $exporter = $container->get(\MyInvoice\Service\Export\Instance\InstanceExportService::class);
            $export = $exporter->runForSupplier($this->source, [\MyInvoice\Service\Export\Instance\InstanceExportService::PART_RESTORE], targetDir: $this->root . '/native-export');
            $package = (new MyuctoExportReader())->read($export['abs_path'], (string) $config->get('cron.backup.password', ''));
            self::assertSame($this->source, $package['manifest']['supplier']['id']);
            self::assertCount(1, $package['tables']['recurring_invoice_templates']);
            self::assertArrayHasKey('posting_rules', $package['tables']);
            self::assertArrayHasKey('de_movement_classification', $package['tables']);
            self::assertArrayHasKey('de_movement_classification_history', $package['tables']);
            self::assertCount(1, $package['tables']['journal_document_postings']);
            self::assertCount(3, $package['tables']['posting_rules']);
            self::assertCount(14, $package['tables']['de_movement_classification']);
            self::assertCount(30, $package['tables']['de_movement_classification_history']);
            $report = $container->get(MyuctoImporter::class)->import($package, $this->target, $this->actor, 'synthetic-live-export');
            self::assertSame(1, $report['created']['invoices']); self::assertSame(1, $report['files']);
            self::assertSame(0, $this->targetCount('invoices'));
        } finally { putenv($oldDataDir === false ? 'MYINVOICE_DATA_DIR' : 'MYINVOICE_DATA_DIR=' . $oldDataDir); }
    }

    public function testOwnTargetDataIsNotMerged(): void
    {
        $package = (new MyuctoExportReader())->read($this->archive);
        $currency = (int) $this->pdo->query('SELECT default_currency_id FROM supplier WHERE id = ' . $this->target)->fetchColumn();
        $country = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $this->pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id) VALUES (?, 'Vlastní syntetický partner', 'Test', 'Test', '11000', ?, 'own@example.test', ?)")->execute([$this->target, $country, $currency]);
        try { $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false); self::fail('Vlastní data cíle nesmí být přimíchána.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('vlastní data', $e->getMessage()); }
        self::assertSame(1, $this->targetCount('clients')); self::assertSame(0, $this->targetCount('invoices'));
    }

    public function testHistoricalVatConfigurationMustMatch(): void
    {
        $package = (new MyuctoExportReader())->read($this->archive);
        $this->pdo->prepare("INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified) VALUES (?, '2089-01-01', 0, 0)")->execute([$this->target]);
        try { $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false); self::fail('Odlišná historie DPH nesmí projít.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('Historie DPH', $e->getMessage()); }
        self::assertSame(0, $this->targetCount('external_entity_map'));
    }

    public function testRepeatDetectsChangedTarget(): void
    {
        $package = (new MyuctoExportReader())->read($this->archive);
        $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
        $this->pdo->prepare('UPDATE invoices SET total_vat = 22 WHERE supplier_id = ?')->execute([$this->target]);
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('Rekonciliace');
        $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
    }

    private function targetCount(string $table): int { return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '` WHERE supplier_id = ' . $this->target)->fetchColumn(); }

    public function testLargeEncryptedZipUsesSharedJobsAndPreservesNativeGraphs(): void
    {
        $password = bin2hex(random_bytes(16));
        $padding = $this->root . '/synthetic-padding.bin';
        $fp = fopen($padding, 'wb');
        for ($i = 0; $i < 65; $i++) {
            fwrite($fp, str_repeat('S', 1024 * 1024));
        }
        fclose($fp);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->archive));
        $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $entry = 'outside-scope/synthetic-padding.bin';
        $manifest['checksums'][$entry] = ['size' => filesize($padding), 'sha256' => hash_file('sha256', $padding)];
        $zip->addFile($padding, $entry);
        $zip->setCompressionName($entry, ZipArchive::CM_STORE);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256, $password);
        self::assertTrue($zip->close());
        self::assertGreaterThan(64 * 1024 * 1024, filesize($this->archive));

        $oldDataDir = getenv('MYINVOICE_DATA_DIR');
        putenv('MYINVOICE_DATA_DIR=' . $this->root);
        try {
            $config = new Config(['app' => ['secret_encryption_key' => base64_encode(random_bytes(32))]]);
            $workflow = new \MyInvoice\Service\Migration\Myucto\MyuctoImportWorkflow(new MyuctoExportReader(), $this->importer, $config);
            $jobs = new \MyInvoice\Repository\ImportJobRepository($this->db);
            $service = new \MyInvoice\Service\Migration\Myucto\MyuctoImportJobService(
                $jobs, $workflow, new \MyInvoice\Service\Auth\SecretEncryption($config),
                new \MyInvoice\Service\Migration\Shared\MigrationCompanyLock($this->db),
                new \MyInvoice\Service\ActivityLogger($this->db), new \Psr\Log\NullLogger(),
            );
            $upload = $workflow->init($this->target, $this->actor, 'synthetic-export.zip', filesize($this->archive));
            $token = $upload['token'];
            $fp = fopen($this->archive, 'rb');
            $offset = 0;
            while (!feof($fp)) {
                $chunk = fread($fp, $upload['chunk_size']);
                if ($chunk === '') break;
                $workflow->chunk($this->target, $this->actor, $token, $offset, (new \Slim\Psr7\Factory\StreamFactory())->createStream($chunk));
                $offset += strlen($chunk);
            }
            fclose($fp);
            $workflow->complete($this->target, $this->actor, $token);
            $started = $service->start($this->target, $this->actor, $token, 'synthetic-jobs', $password, false, false);
            $id = $started['job_id'];
            $queued = $jobs->find($id, $this->target);
            self::assertSame('queued', $queued['status']);
            self::assertStringNotContainsString($password, json_encode($queued));
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM invoices WHERE supplier_id = ' . $this->target)->fetchColumn());
            $service->run($id);
            $preview = $service->status($this->target, $this->actor, $id);
            self::assertSame('completed', $preview['status'], $preview['last_error'] ?? '');
            self::assertTrue($preview['result']['report']['dry_run']);
            self::assertArrayNotHasKey('password_enc', $jobs->find($id, $this->target)['params']);
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM invoices WHERE supplier_id = ' . $this->target)->fetchColumn());
            $applied = $service->start($this->target, $this->actor, $token, 'synthetic-jobs', $password, true, true);
            $service->run($applied['job_id']);
            $result = $service->status($this->target, $this->actor, $applied['job_id']);
            self::assertSame('completed', $result['status'], $result['last_error'] ?? '');
            self::assertFalse($result['result']['report']['dry_run']);
            self::assertSame(1, $result['result']['report']['reconciliation']['invoices']);
            self::assertSame(1, $result['result']['report']['reconciliation']['recurring_invoice_templates']);
            self::assertCount(2, $service->history($this->target, $this->actor)['runs']);
            self::assertSame('0', (string) $this->pdo->query('SELECT auto_issue FROM recurring_invoice_templates WHERE supplier_id = ' . $this->target)->fetchColumn());
        } finally {
            putenv($oldDataDir === false ? 'MYINVOICE_DATA_DIR' : 'MYINVOICE_DATA_DIR=' . $oldDataDir);
            $this->pdo->prepare('DELETE FROM import_jobs WHERE supplier_id = ?')->execute([$this->target]);
        }
    }

    private function seedClassifications(): void
    {
        foreach ([[10, 1, '311990'], [120, 1, null], [200, 0, '311990']] as [$priority, $active, $debit]) {
            $this->pdo->prepare("INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active, created_at) VALUES (?, 'invoice.services.issued', 'Syntetická předkontace', ?, '602990', ?, ?, '2090-01-01 10:00:00')")
                ->execute([$this->source, $debit, $priority, $active]);
        }
        $this->pdo->prepare("INSERT INTO cash_registers (supplier_id, name, currency_code) VALUES (?, 'Syntetická pokladna', 'CZK')")->execute([$this->source]);
        $register = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date, imported_by) VALUES (?, 'synthetic.gpc', ?, '1000000005', '0100', 'CZK', '2090-01-15', ?)")
            ->execute([$this->source, hash('sha256', 'synthetic-classifications-' . $this->source), $this->actor]);
        $statement = (int) $this->pdo->lastInsertId();
        foreach (\MyInvoice\Repository\MovementClassificationRepository::TAX_BUCKETS as $index => $bucket) {
            $out = str_starts_with($bucket, 'expense') || $bucket === 'private';
            $amount = ($index + 1) * 100;
            $date = $index % 2 === 0 ? '2090-01-15' : '2090-02-15';
            foreach (['bank', 'cash'] as $type) {
                if ($type === 'bank') {
                    $this->pdo->prepare("INSERT INTO bank_transactions (statement_id, source, posted_at, amount, currency, description) VALUES (?, 'statement', ?, ?, 'CZK', 'Syntetický pohyb')")
                        ->execute([$statement, $date, $out ? -$amount : $amount]);
                } else {
                    $this->pdo->prepare("INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, description, vat_mode, total_amount, currency_code, fx_rate, status, created_by) VALUES (?, ?, ?, 'other', ?, ?, 'Syntetický pohyb', 'none', ?, 'CZK', 1, 'posted', ?)")
                        ->execute([$this->source, $register, $out ? 'out' : 'in', 'SYNTHETIC-CASH-' . $index, $date, $amount, $this->actor]);
                }
                $movement = (int) $this->pdo->lastInsertId();
                $this->pdo->prepare("INSERT INTO de_movement_classification (supplier_id, source_type, bank_transaction_id, cash_document_id, tax_bucket, note, classified_by, classified_at, updated_at) VALUES (?, ?, ?, ?, ?, 'Syntetická poznámka', 999999, '2090-03-01 10:00:00', '2090-03-02 10:00:00')")
                    ->execute([$this->source, $type, $type === 'bank' ? $movement : null, $type === 'cash' ? $movement : null, $bucket]);
                foreach ([[null, 'private'], ['private', $bucket]] as [$previous, $new]) {
                    $this->pdo->prepare("INSERT INTO de_movement_classification_history (supplier_id, source_type, source_id, previous_tax_bucket, new_tax_bucket, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, 999999, '2090-03-02 10:00:00')")
                        ->execute([$this->source, $type, $movement, $previous, $new]);
                }
            }
        }
        $this->pdo->prepare("INSERT INTO bank_transactions (statement_id, source, posted_at, amount, currency, description) VALUES (?, 'statement', '2090-02-15', -50, 'CZK', 'Syntetická zrušená klasifikace')")->execute([$statement]);
        $movement = (int) $this->pdo->lastInsertId();
        foreach ([[null, 'private'], ['private', null]] as [$previous, $new]) {
            $this->pdo->prepare("INSERT INTO de_movement_classification_history (supplier_id, source_type, source_id, previous_tax_bucket, new_tax_bucket, changed_by, changed_at) VALUES (?, 'bank', ?, ?, ?, NULL, '2090-03-02 10:00:00')")
                ->execute([$this->source, $movement, $previous, $new]);
        }
    }

    public function testStoredClassificationsHistoryAndEffectiveRulesSurviveRestore(): void
    {
        $package = (new MyuctoExportReader())->read($this->archive);
        $package['tables']['de_movement_classification_history'] = array_reverse(
            $package['tables']['de_movement_classification_history'],
            true
        );
        $dry = $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance');
        self::assertSame(14, $dry['created']['de_movement_classification']);
        self::assertSame(30, $dry['created']['de_movement_classification_history']);
        foreach (['posting_rules', 'de_movement_classification', 'de_movement_classification_history'] as $table) {
            self::assertSame(0, $this->targetCount($table));
        }
        $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
        $rules = new \MyInvoice\Repository\PostingRuleRepository($this->db);
        foreach ([$rules->resolve($this->source, 'invoice.services.issued'), $rules->effectiveMap($this->source)['invoice.services.issued']] as $index => $source) {
            $target = $index === 0 ? $rules->resolve($this->target, 'invoice.services.issued') : $rules->effectiveMap($this->target)['invoice.services.issued'];
            unset($source['id'], $source['supplier_id'], $target['id'], $target['supplier_id']);
            self::assertSame($source, $target);
            self::assertSame(120, $target['priority']);
            self::assertNull($target['debit_account_code']);
        }
        $service = Bootstrap::buildContainer()->get(\MyInvoice\Service\TaxEvidence\CashJournalService::class);
        foreach ([['2090-01-01', '2090-01-31'], ['2090-02-01', '2090-02-28']] as [$from, $to]) {
            $source = $service->build($this->source, $from, $to);
            $target = $service->build($this->target, $from, $to);
            self::assertNotEmpty($source['rows']);
            self::assertGreaterThan(0, array_sum(array_map('abs', $source['totals'])));
            $expected = $from === '2090-01-01'
                ? ['prijem_danovy' => 200, 'prijem_nedanovy' => 600, 'vydaj_nedanovy' => 1000, 'private' => 1400]
                : ['prijem_osvobozeny' => 400, 'vydaj_danovy' => 800, 'prevody' => 1200];
            foreach ($expected as $bucket => $amount) {
                self::assertEqualsWithDelta($amount, $source['totals'][$bucket], 0.01, $bucket);
            }
            self::assertSame($source['totals'], $target['totals']);
            self::assertCount(count($source['rows']), $target['rows']);
        }
        foreach (['de_movement_classification', 'de_movement_classification_history', 'posting_rules'] as $table) {
            $rows = $this->pdo->query('SELECT * FROM `' . $table . '` WHERE supplier_id = ' . $this->target . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $sourceRows = $package['tables'][$table];
            ksort($sourceRows, SORT_NUMERIC);
            foreach (array_values($sourceRows) as $index => $source) {
                $actual = $rows[$index];
                foreach ($source as $column => $value) {
                    if (in_array($column, ['id', 'supplier_id', 'bank_transaction_id', 'cash_document_id', 'source_id', 'classified_by', 'changed_by'], true)) {
                        continue;
                    }
                    self::assertSame($value, $actual[$column], $table . '.' . $column);
                }
                $movementTable = ($source['source_type'] ?? null) === 'bank' ? 'bank_transactions' : 'cash_documents';
                $column = $table === 'de_movement_classification_history' ? 'source_id' : (($source['source_type'] ?? null) === 'bank' ? 'bank_transaction_id' : 'cash_document_id');
                if ($table !== 'posting_rules') {
                    $stmt = $this->pdo->prepare("SELECT internal_id FROM external_entity_map WHERE supplier_id = ? AND external_id = ?");
                    $stmt->execute([$this->target, $movementTable . ':' . $source[$column]]);
                    self::assertSame((int) $stmt->fetchColumn(), (int) $actual[$column]);
                    $actorColumn = $table === 'de_movement_classification_history' ? 'changed_by' : 'classified_by';
                    self::assertSame($source[$actorColumn] === null ? null : $this->actor, $actual[$actorColumn] === null ? null : (int) $actual[$actorColumn]);
                }
            }
        }
        $repeat = $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
        self::assertSame([], $repeat['created']);
        self::assertSame(30, $this->targetCount('de_movement_classification_history'));
    }

    public function testIdenticalPostingRuleIsReusedAndConflictRollsBack(): void
    {
        $this->pdo->prepare("INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active) SELECT ?, rule_key, description, debit_account_code, credit_account_code, priority, is_active FROM posting_rules WHERE supplier_id = ? AND priority = 120")
            ->execute([$this->target, $this->source]);
        $package = (new MyuctoExportReader())->read($this->archive);
        $dry = $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance');
        self::assertSame(1, $dry['reused']['posting_rules']);
        $this->pdo->prepare("UPDATE posting_rules SET is_active = 0 WHERE supplier_id = ?")->execute([$this->target]);
        try {
            $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
            self::fail('Konfliktní předkontace nesmí projít.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('posting_rules.is_active', $e->getMessage());
        }
        self::assertSame(0, $this->targetCount('invoices'));
        self::assertSame(0, $this->targetCount('external_entity_map'));
        self::assertSame(0, $this->targetCount('de_movement_classification_history'));
        self::assertSame(1, $this->targetCount('posting_rules'));
    }

    public function testTargetPostingRuleMissingInSourceStopsFirstRestore(): void
    {
        $this->pdo->prepare("INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active) SELECT ?, 'synthetic.target.only', description, debit_account_code, credit_account_code, priority, is_active FROM posting_rules WHERE supplier_id = ? AND priority = 120")
            ->execute([$this->target, $this->source]);
        $package = (new MyuctoExportReader())->read($this->archive);
        try {
            $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
            self::fail('Předkontace navíc v cílové firmě nesmí projít.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('synthetic.target.only', $e->getMessage());
        }
        self::assertSame(0, $this->targetCount('invoices'));
        self::assertSame(0, $this->targetCount('external_entity_map'));
        self::assertSame(1, $this->targetCount('posting_rules'));
    }

    public function testOldProfileCannotSilentlyExtendAnExistingRestore(): void
    {
        $package = (new MyuctoExportReader())->read($this->archive);
        $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
        $this->pdo->prepare("UPDATE external_entity_map SET external_id = ? WHERE supplier_id = ? AND entity_type = 'myucto_import'")
            ->execute([str_repeat('a', 64), $this->target]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('starším importním profilem');
        $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
    }

    /** Export ze zdroje bez skupin zaúčtování obnovený starším profilem se opakuje bez chyby. */
    public function testEarlierProfileRestoreOfSameDataCanBeRepeated(): void
    {
        $package = (new MyuctoExportReader())->read($this->archive);
        unset($package['tables']['journal_document_postings']);
        $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
        $this->pdo->prepare("UPDATE external_entity_map SET external_id = REPLACE(external_id, ?, 'classifications-v1:')
            WHERE supplier_id = ? AND entity_type = 'myucto_import'")
            ->execute([MyuctoImportProfile::VERSION . ':', $this->target]);

        $repeat = $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);

        self::assertSame([], $repeat['created']);
        self::assertSame(1, $repeat['existing']['invoices']);
    }

    #[DataProvider('invalidClassificationGraphs')]
    public function testInvalidClassificationGraphLeavesTargetEmpty(string $table, string $column, mixed $value): void
    {
        $package = (new MyuctoExportReader())->read($this->archive);
        $id = array_key_first($package['tables'][$table]);
        $package['tables'][$table][$id][$column] = $value === 'foreign_supplier' ? $this->target : $value;
        try {
            $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
            self::fail('Neplatný graf nesmí projít.');
        } catch (\RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());
        }
        foreach (['invoices', 'posting_rules', 'de_movement_classification', 'de_movement_classification_history', 'external_entity_map'] as $targetTable) {
            self::assertSame(0, $this->targetCount($targetTable));
        }
    }

    public static function invalidClassificationGraphs(): iterable
    {
        yield 'foreign owner' => ['de_movement_classification', 'supplier_id', 'foreign_supplier'];
        yield 'missing bank movement' => ['de_movement_classification', 'bank_transaction_id', 999999999];
        yield 'invalid XOR' => ['de_movement_classification', 'cash_document_id', 999999999];
        yield 'orphan history' => ['de_movement_classification_history', 'source_id', 999999999];
        yield 'invalid history bucket' => ['de_movement_classification_history', 'new_tax_bucket', 'unknown'];
        yield 'missing posting account' => ['posting_rules', 'credit_account_code', '999999'];
    }

    public function testRepeatRejectsChangedClassificationTimestamp(): void
    {
        $package = (new MyuctoExportReader())->read($this->archive);
        $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
        $this->pdo->prepare("UPDATE de_movement_classification SET updated_at = '2090-04-01 10:00:00' WHERE supplier_id = ?")
            ->execute([$this->target]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('de_movement_classification.updated_at');
        $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
    }

    public function testCompanyEmailTemplatesRoundTripPreservesContentAndRemapsAuthor(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO supplier_email_templates (supplier_id, code, locale, subject, body_html, body_text, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$this->source, 'invoice_send', 'cs', 'Syntetický předmět {{ invoice.varsymbol }}', '<p>Syntetický obsah</p>', 'Syntetický obsah', $this->actor]);
        $insert->execute([$this->source, 'invoice_send', 'en', 'Synthetic subject', '<p>Synthetic content</p>', 'Synthetic content', null]);
        $this->archive = $this->makeArchive();
        $package = (new MyuctoExportReader())->read($this->archive);
        self::assertCount(2, $package['tables']['supplier_email_templates']);
        // Autor z jiné instalace nesmí být použit jako lokální ID uživatele.
        foreach ($package['tables']['supplier_email_templates'] as &$row) {
            self::assertSame($this->source, (int) $row['supplier_id']);
            if ($row['updated_by'] !== null) $row['updated_by'] = 987654321;
        }
        unset($row);

        $dry = $this->importer->import($package, $this->target, $this->actor, 'synthetic-email-templates');
        self::assertSame(2, $dry['created']['supplier_email_templates']);
        self::assertSame(0, $this->targetCount('supplier_email_templates'));
        $this->importer->import($package, $this->target, $this->actor, 'synthetic-email-templates', false);
        $rows = $this->pdo->query('SELECT * FROM supplier_email_templates WHERE supplier_id = ' . $this->target . ' ORDER BY locale')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        foreach (array_values($package['tables']['supplier_email_templates']) as $source) {
            $actual = array_values(array_filter($rows, static fn (array $r): bool => $r['locale'] === $source['locale']))[0];
            foreach (['code', 'locale', 'subject', 'body_html', 'body_text', 'updated_at'] as $field) {
                self::assertSame($source[$field], $actual[$field], $field);
            }
            self::assertSame($source['updated_by'] === null ? null : $this->actor, $actual['updated_by'] === null ? null : (int) $actual['updated_by']);
            self::assertSame($this->target, (int) $actual['supplier_id']);
            self::assertNotSame((int) $source['id'], (int) $actual['id']);
        }
        $repeat = $this->importer->import($package, $this->target, $this->actor, 'synthetic-email-templates', false);
        self::assertSame([], $repeat['created']);
        self::assertSame(2, $this->targetCount('supplier_email_templates'));
    }

    public function testCompanyEmailTemplateConflictPreservesTargetAndRollsBack(): void
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO supplier_email_templates (supplier_id, code, locale, subject, body_html, body_text) VALUES (?, 'invoice_send', 'cs', ?, '<p>Test</p>', 'Test')"
        );
        $insert->execute([$this->source, 'Synthetic source']);
        $insert->execute([$this->target, 'Synthetic existing target']);
        $this->archive = $this->makeArchive();
        $package = (new MyuctoExportReader())->read($this->archive);
        try {
            $this->importer->import($package, $this->target, $this->actor, 'synthetic-email-conflict', false);
            self::fail('Existující firemní šablona se nesmí přepsat importem.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('supplier_email_templates', $e->getMessage());
        }
        self::assertSame('Synthetic existing target', $this->pdo->query('SELECT subject FROM supplier_email_templates WHERE supplier_id = ' . $this->target)->fetchColumn());
        self::assertSame(0, $this->targetCount('invoices'));
        self::assertSame(0, $this->targetCount('external_entity_map'));
    }

    public function testNonprofitFrameworkOfSourceMustMatchTargetBeforeAnyWrite(): void
    {
        $this->framework($this->source, '1900-01-01', 'cz_504');
        $package = (new MyuctoExportReader())->read($this->makeArchive(['supplier_accounting_frameworks'], 'framework-export.zip'));
        self::assertArrayNotHasKey('supplier_accounting_frameworks', $package['tables']);
        self::assertSame(['supplier_accounting_frameworks' => 1], $package['skipped']);
        try {
            $this->importer->import($package, $this->target, $this->actor, 'synthetic-framework', false);
            self::fail('Deník podle vyhlášky 504 nesmí projít do firmy s rámcem 500.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Účetní rámec cílové firmy se liší od exportu k 1900-01-01', $e->getMessage());
        }
        self::assertSame(0, $this->targetCount('chart_of_accounts'));
        self::assertSame(0, $this->targetCount('journal_entries'));
        self::assertSame(0, $this->targetCount('external_entity_map'));
    }

    public function testMatchingNonprofitFrameworkRestoresAndReportsFrameworkOutsideScope(): void
    {
        $this->framework($this->source, '1900-01-01', 'cz_504');
        $this->framework($this->target, '1900-01-01', 'cz_504');
        $package = (new MyuctoExportReader())->read($this->makeArchive(['supplier_accounting_frameworks'], 'framework-export.zip'));
        $report = $this->importer->import($package, $this->target, $this->actor, 'synthetic-framework', false);
        self::assertSame(1, $report['created']['journal_entries']);
        self::assertSame(['supplier_accounting_frameworks' => 1], $report['outside_scope']);
    }

    public function testExportWithoutFrameworkHistoryCannotFillNonprofitTarget(): void
    {
        $this->framework($this->target, '2090-01-01', 'cz_504');
        $package = (new MyuctoExportReader())->read($this->archive);
        try {
            $this->importer->import($package, $this->target, $this->actor, 'synthetic-framework', false);
            self::fail('Export v rámci 500 nesmí projít do období vedeného podle vyhlášky 504.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('k 2090-01-01: export vede účetnictví podle vyhlášky 500, cílová firma podle vyhlášky 504', $e->getMessage());
        }
        self::assertSame(0, $this->targetCount('journal_entries'));
    }

    private function framework(int $supplier, string $from, string $framework): void
    {
        $this->pdo->prepare('INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE accounting_framework = VALUES(accounting_framework)')
            ->execute([$supplier, $from, $framework]);
    }

    /** @param list<string> $extraTables */
    private function makeArchive(array $extraTables = [], string $name = 'synthetic-export.zip'): string
    {
        $path = $this->root . '/' . $name; $zip = new ZipArchive(); self::assertTrue($zip->open($path, ZipArchive::CREATE));
        $schema = SchemaMetadataProvider::load($this->pdo); $tables = []; $shared = []; $checksums = [];
        $scopes = (new TenantScopeResolver($this->db))->resolveAll($this->source);
        foreach (['supplier', ...MyuctoImportProfile::TABLES, ...MyuctoImportProfile::CONFIG_TABLES, ...$extraTables, ...array_keys(MyuctoImportProfile::GLOBAL_KEYS)] as $table) {
            $scope = $scopes[$table] ?? null;
            $columns = $scope?->columns ?? array_keys($schema['columns'][$table]);
            $columns = array_values(array_filter($columns, fn ($c): bool => ($schema['columns'][$table][$c]['GENERATION_EXPRESSION'] ?? '') === ''));
            $stmt = $this->pdo->prepare('SELECT `' . implode('`, `', $columns) . '` FROM `' . $table . '`' . ($scope === null ? '' : ' WHERE ' . $scope->where));
            $stmt->execute($scope?->params ?? []); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $entry = null;
            if ($rows !== []) {
                $entry = 'data/' . $table . '.jsonl'; $data = '';
                foreach ($rows as $row) $data .= json_encode(InstanceExportBinaryCodec::encodeRow($row), JSON_THROW_ON_ERROR) . "\n";
                $zip->addFromString($entry, $data); $checksums[$entry] = ['sha256' => hash('sha256', $data), 'size' => strlen($data)];
            }
            $info = ['rows' => count($rows), 'entry' => $entry];
            if (isset(MyuctoImportProfile::GLOBAL_KEYS[$table])) $shared[$table] = $info; else $tables[$table] = $info;
        }
        $bytes = "%PDF-synthetic-original\n";
        $zip->addFromString('doklady/synthetic-original.pdf', $bytes);
        $checksums['doklady/synthetic-original.pdf'] = ['sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
        $manifest = ['format' => 'myucto-instance-export', 'version' => 6, 'supplier' => ['id' => $this->source], 'restore' => ['available' => true, 'documents' => [['entry' => 'doklady/synthetic-original.pdf', 'storage_path' => 'purchase-invoices/sup-' . $this->source . '/synthetic-original.pdf']]],
            'sections' => ['data' => ['tables' => $tables, 'shared_tables' => $shared]], 'checksums' => $checksums];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)); self::assertTrue($zip->close()); return $path;
    }

    private function removeDir(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') { $child = $path . '/' . $entry; if (is_dir($child)) $this->removeDir($child); else @unlink($child); }
        @rmdir($path);
    }
}
