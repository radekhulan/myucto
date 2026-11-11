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
            $this->pdo->prepare('UPDATE documents SET parent_document_id = NULL, signature_for_id = NULL WHERE supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('UPDATE document_folders SET parent_id = NULL WHERE supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('UPDATE dimension_values SET parent_id = NULL WHERE supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE p FROM invoice_pdfs p JOIN invoices i ON i.id = p.invoice_id WHERE i.supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE c FROM number_series_counters c JOIN number_series n ON n.id = c.series_id WHERE n.supplier_id = ?')->execute([$id]);
            foreach (['document_links', 'document_dimensions', 'journal_entry_line_dimensions', 'document_extractions', 'document_files', 'documents', 'document_folders',
                'internal_document_lines', 'internal_documents', 'journal_entry_document_links', 'journal_entry_templates', 'journal_entry_notes',
                'tax_advance_schedules', 'income_tax_returns', 'accounting_corrections', 'bank_posting_rules', 'bank_card_merchant_map', 'expense_classification_rules',
                'bank_rule_templates', 'auto_posting_policy', 'pdf_signature_output_settings', 'accounting_closing_steps', 'dimension_values', 'dimension_types',
                'invoice_counters', 'purchase_invoice_counters', 'number_series', 'accounting_document_series', 'supplier_bank_accounts'] as $table) {
                $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE supplier_id = ?')->execute([$id]);
            }
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
        $package = self::readArrays($this->archive);
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

    public function testCompositeSourceStaysCompositeInInstallationWithSingleDefault(): void
    {
        $this->installationDefaultSingle($this->target);
        $package = self::readArrays($this->makeArchive(self::ENTRY_FORM_TABLES, 'entry-form-export.zip'));
        self::assertSame([], $package['tables']['accounting_entry_form_history']);

        $this->importer->import($package, $this->target, $this->actor, 'synthetic-entry-form', false);

        self::assertSame([], $this->entryFormHistory($this->target), 'Obnova složené firmy nesmí převzít výchozí formu instalace.');
        self::assertSame($this->journalImage($this->source), $this->journalImage($this->target));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM journal_entry_lines WHERE pair_no IS NOT NULL AND supplier_id = ' . $this->target)->fetchColumn());
        $audit = $this->pdo->prepare("SELECT payload FROM activity_log WHERE action = 'accounting.entry_form_changed' AND entity_type = 'supplier' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $audit->execute([$this->target]);
        $payload = json_decode((string) $audit->fetchColumn(), true);
        self::assertSame('myucto_import', $payload['source'] ?? null);
        self::assertSame([], $payload['to'] ?? null);
    }

    public function testExportFromOlderVersionWithoutEntryFormTablesIsComposite(): void
    {
        $this->installationDefaultSingle($this->target);
        $package = self::readArrays($this->archive);
        self::assertArrayNotHasKey('accounting_entry_form_history', $package['tables']);

        $this->importer->import($package, $this->target, $this->actor, 'synthetic-entry-form', false);

        self::assertSame([], $this->entryFormHistory($this->target));
        self::assertSame($this->journalImage($this->source), $this->journalImage($this->target));
        $repeat = $this->importer->import(self::readArrays($this->makeArchive(self::ENTRY_FORM_TABLES, 'entry-form-export.zip')),
            $this->target, $this->actor, 'synthetic-entry-form', false);
        self::assertSame([], $repeat['created'], 'Export s prázdnými tabulkami formy má stejný otisk jako export starší verze.');
    }

    #[DataProvider('singleTargets')]
    public function testSingleSourceRestoresSingleWithPairsHistoryAndAssessments(bool $installationDefault): void
    {
        if ($installationDefault) {
            $this->installationDefaultSingle($this->target);
        }
        $this->singleSourceJournal();
        $package = self::readArrays($this->makeArchive(self::ENTRY_FORM_TABLES, 'entry-form-export.zip'));
        self::assertCount(1, $package['tables']['journal_entry_pair_status']);
        self::assertCount(1, $package['tables']['journal_pair_reversals']);

        $report = $this->importer->import($package, $this->target, $this->actor, 'synthetic-entry-form', false);

        self::assertSame(1, $report['created']['journal_entry_pair_status']);
        self::assertSame(1, $report['created']['journal_pair_reversals']);
        self::assertSame([['2090-06-01', 'single', 'Syntetická volba jednoduchého zápisu', '2090-05-20 08:00:00', $this->actor]], $this->entryFormHistory($this->target));
        self::assertSame($this->journalImage($this->source), $this->journalImage($this->target));
        $unpaired = $this->pdo->query("SELECT COUNT(*) FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
            WHERE l.supplier_id = {$this->target} AND e.entry_date >= '2090-06-01' AND l.pair_no IS NULL
              AND NOT EXISTS (SELECT 1 FROM journal_entry_pair_status s WHERE s.supplier_id = e.supplier_id AND s.entry_id = e.id)")->fetchColumn();
        self::assertSame(0, (int) $unpaired, 'V období jednoduchého zápisu má každý řádek dvojici nebo zápis posouzení.');
        self::assertSame($this->pairTablesImage($this->source), $this->pairTablesImage($this->target));
        self::assertTrue((new \MyInvoice\Service\Accounting\EntryForm\EntryFormService($this->db, new \MyInvoice\Service\ActivityLogger($this->db),
            new \MyInvoice\Repository\AccountingModeRepository($this->db), new \MyInvoice\Security\FrameworkScopePolicy($this->db)))
            ->formAt($this->target, '2090-06-15', true)->isSingle());

        $repeat = $this->importer->import($package, $this->target, $this->actor, 'synthetic-entry-form', false);
        self::assertSame([], $repeat['created']);
        self::assertSame(1, $repeat['existing']['journal_pair_reversals']);
    }

    public static function singleTargets(): iterable
    {
        yield 'nová firma s výchozí formou instalace single' => [true];
        yield 'nová firma bez výchozí formy' => [false];
    }

    public function testTargetWithOwnDifferentEntryFormRejectsRestoreBeforeAnyWrite(): void
    {
        $this->pdo->prepare("INSERT INTO accounting_entry_form_history (supplier_id, valid_from, entry_form, note) VALUES (?, '2090-01-01', 'single', 'Syntetická vlastní volba')")
            ->execute([$this->target]);
        $package = self::readArrays($this->archive);
        try {
            $this->importer->import($package, $this->target, $this->actor, 'synthetic-entry-form', false);
            self::fail('Firma s vlastní jinou formou zápisu nesmí obnovu převzít tiše.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('liší od exportu k 2090-01-01: export účtuje složeným zápisem, cílová firma jednoduchým zápisem', $e->getMessage());
        }
        self::assertSame(0, $this->targetCount('journal_entries'));
        self::assertSame(0, $this->targetCount('invoices'));
        self::assertSame('single', $this->entryFormHistory($this->target)[0][1]);
    }

    public function testPairedJournalWithoutSingleFormInExportIsRejected(): void
    {
        $this->singleSourceJournal();
        $package = self::readArrays($this->makeArchive(self::ENTRY_FORM_TABLES, 'entry-form-export.zip'));
        $package['tables']['accounting_entry_form_history'] = [];
        try {
            $this->importer->import($package, $this->target, $this->actor, 'synthetic-entry-form', false);
            self::fail('Deník po dvojicích bez jednoduchého zápisu v exportu nesmí projít.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('nenese jednoduchý zápis', $e->getMessage());
        }
        self::assertSame(0, $this->targetCount('journal_entries'));
    }

    private const ENTRY_FORM_TABLES = ['accounting_entry_form_history', 'journal_entry_pair_status', 'journal_pair_reversals'];

    private function installationDefaultSingle(int $supplier): void
    {
        $default = new \MyInvoice\Service\Accounting\EntryForm\EntryFormInstallationDefault(
            $this->db, new Config(['accounting' => ['default_entry_form' => 'single']]),
        );
        self::assertNotNull($default->apply($supplier, $this->actor));
        self::assertSame('single', $this->entryFormHistory($supplier)[0][1]);
    }

    /** Zdroj v jednoduchém zápisu od 2090-06-01: zápis po dvojicích, storno dvojice a posouzený zápis M : N. */
    private function singleSourceJournal(): void
    {
        $this->pdo->prepare("INSERT INTO accounting_entry_form_history (supplier_id, valid_from, entry_form, created_by, created_at, note) VALUES (?, '2090-06-01', 'single', ?, '2090-05-20 08:00:00', 'Syntetická volba jednoduchého zápisu')")
            ->execute([$this->source, $this->actor]);
        $period = (int) $this->pdo->query('SELECT id FROM accounting_periods WHERE supplier_id = ' . $this->source)->fetchColumn();
        $account = [];
        foreach ($this->pdo->query('SELECT account_code, id FROM chart_of_accounts WHERE supplier_id = ' . $this->source)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $account[$row['account_code']] = (int) $row['id'];
        }
        $entry = function (string $date, string $description, array $lines) use ($period, $account): int {
            $this->pdo->prepare("INSERT INTO journal_entries (supplier_id, period_id, entry_date, description, source_type, source_id, posted_at) VALUES (?, ?, ?, ?, 'manual', NULL, ?)")
                ->execute([$this->source, $period, $date, $description, $date . ' 00:00:00']);
            $id = (int) $this->pdo->lastInsertId();
            foreach ($lines as $index => [$pair, $code, $side, $amount, $text]) {
                $this->pdo->prepare('INSERT INTO journal_entry_lines (supplier_id, entry_id, account_id, line_no, pair_no, line_text, side, amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$this->source, $id, $account[$code], $index + 1, $pair, $text, $side, $amount]);
            }
            return $id;
        };
        $paired = $entry('2090-06-15', 'Syntetický zápis po dvojicích', [
            [1, '311990', 'debit', '100.00', 'Syntetická dvojice 1'], [1, '602990', 'credit', '100.00', 'Syntetická dvojice 1'],
            [2, '311990', 'debit', '21.00', 'Syntetická dvojice 2'], [2, '343990', 'credit', '21.00', 'Syntetická dvojice 2'],
        ]);
        $reversal = $entry('2090-06-20', 'Syntetické storno dvojice', [
            [1, '602990', 'debit', '100.00', 'Storno dvojice 1'], [1, '311990', 'credit', '100.00', 'Storno dvojice 1'],
        ]);
        $this->pdo->prepare('INSERT INTO journal_pair_reversals (supplier_id, entry_id, pair_no, reversal_entry_id, reversal_pair_no, replacement_pair_no, created_by, created_at) VALUES (?, ?, 1, ?, 1, NULL, ?, ?)')
            ->execute([$this->source, $paired, $reversal, $this->actor, '2090-06-20 10:00:00']);
        $composite = $entry('2090-06-25', 'Syntetický zápis M : N', [
            [null, '311990', 'debit', '60.00', null], [null, '343990', 'debit', '40.00', null],
            [null, '602990', 'credit', '70.00', null], [null, '343990', 'credit', '30.00', null],
        ]);
        $this->pdo->prepare("INSERT INTO journal_entry_pair_status (supplier_id, entry_id, status, reason, run_id, assessed_at) VALUES (?, ?, 'composite_mn', 'synthetic', NULL, '2090-06-25 10:00:00')")
            ->execute([$this->source, $composite]);
    }

    /** @return list<array{0:string,1:string,2:?string,3:string,4:?int}> */
    private function entryFormHistory(int $supplier): array
    {
        $stmt = $this->pdo->prepare('SELECT valid_from, entry_form, note, created_at, created_by FROM accounting_entry_form_history WHERE supplier_id = ? ORDER BY valid_from');
        $stmt->execute([$supplier]);
        return array_map(static fn (array $r): array => [$r['valid_from'], $r['entry_form'], $r['note'], $r['created_at'], $r['created_by'] === null ? null : (int) $r['created_by']],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Deník firmy bez interních ID: všechny sloupce zápisů a řádků (včetně dvojic) s kódem účtu. */
    private function journalImage(int $supplier): array
    {
        $entries = $this->pdo->query('SELECT * FROM journal_entries WHERE supplier_id = ' . $supplier . ' ORDER BY entry_date, id')->fetchAll(PDO::FETCH_ASSOC);
        $image = [];
        foreach ($entries as $entry) {
            $lines = $this->pdo->query('SELECT l.*, a.account_code FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
                WHERE l.supplier_id = ' . $supplier . ' AND l.entry_id = ' . (int) $entry['id'] . ' ORDER BY l.line_no, l.id')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($lines as &$line) {
                unset($line['id'], $line['supplier_id'], $line['entry_id'], $line['account_id']);
            }
            unset($line);
            unset($entry['id'], $entry['supplier_id'], $entry['period_id'], $entry['source_id'], $entry['active_source_id'], $entry['created_by'], $entry['posted_by']);
            $image[] = [$entry, $lines];
        }
        return $image;
    }

    /** Posouzení a storna dvojic s datem zápisu místo ID. */
    private function pairTablesImage(int $supplier): array
    {
        return [
            $this->pdo->query('SELECT e.entry_date, s.status, s.reason, s.run_id, s.assessed_at FROM journal_entry_pair_status s
                JOIN journal_entries e ON e.id = s.entry_id AND e.supplier_id = s.supplier_id WHERE s.supplier_id = ' . $supplier)->fetchAll(PDO::FETCH_ASSOC),
            $this->pdo->query('SELECT e.entry_date, r.pair_no, x.entry_date AS reversal_date, r.reversal_pair_no, r.replacement_pair_no, r.created_at FROM journal_pair_reversals r
                JOIN journal_entries e ON e.id = r.entry_id AND e.supplier_id = r.supplier_id
                JOIN journal_entries x ON x.id = r.reversal_entry_id AND x.supplier_id = r.supplier_id WHERE r.supplier_id = ' . $supplier)->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function testUnusedSetupPeriodOfNewCompanyIsReplacedByExportPeriods(): void
    {
        $this->pdo->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, created_reason) VALUES (?, 2090, '2090-01-01', '2090-12-31', 'setup')")
            ->execute([$this->target]);
        $package = self::readArrays($this->archive);
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
        $package = self::readArrays($this->archive);
        try { $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false); self::fail('Použité období nesmí převod přepsat.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('accounting_periods', $e->getMessage()); }
        self::assertSame(0, $this->targetCount('invoices'));
        self::assertSame('closed', $this->pdo->query('SELECT status FROM accounting_periods WHERE supplier_id = ' . $this->target)->fetchColumn());
    }

    public function testCrossTenantRowIsRejectedBeforeAnyWrite(): void
    {
        $package = self::readArrays($this->archive);
        $id = array_key_first($package['tables']['clients']); $package['tables']['clients'][$id]['supplier_id'] = $this->target;
        try { $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false); self::fail('Cizí firma nesmí projít.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('jiné firmy', $e->getMessage()); }
        self::assertSame(0, $this->targetCount('invoices')); self::assertSame(0, $this->targetCount('clients'));
    }

    public function testRestoreRemapsEveryPostingAndBothDocumentTypes(): void
    {
        $package = self::readArrays($this->archive);
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

    /**
     * Export z produkce nesl zápočet dobropisu a vazby na číselník řad. Obnova
     * je odmítla jako „nepodporovanou vazbu", přestože zápočet umí přemapovat
     * a ostatní vazby míří na nastavení, které obnova nepřenáší.
     */
    public function testCreditNoteOffsetIsRemappedAndSettingsReferencesAreDetached(): void
    {
        $package = self::readArrays($this->archive);
        $creditNote = max(array_keys($package['tables']['invoices'])) + 1;
        $package['tables']['invoices'][$creditNote] = array_replace($package['tables']['invoices'][$this->invoice], [
            'id' => $creditNote, 'invoice_type' => 'credit_note', 'varsymbol' => 'SYNTHETIC-2090-02',
            'total_without_vat' => '-100.00', 'total_vat' => '-21.00', 'total_with_vat' => '-121.00',
        ]);
        $package['tables']['invoices'][$this->invoice]['number_series_id'] = 987654;
        $package['tables']['invoices'][$this->invoice]['price_level_id'] = 987655;
        $item = array_key_first($package['tables']['purchase_invoice_items']);
        $package['tables']['purchase_invoice_items'][$item]['expense_rule_id'] = 987656;
        $package['tables']['credit_note_offsets'][1] = [
            'id' => 1, 'supplier_id' => $this->source, 'doc_type' => 'invoice', 'invoice_id' => $this->invoice,
            'credit_note_id' => $creditNote, 'amount' => '121.00', 'offset_on' => '2090-01-01',
            'invoice_payment_id' => null, 'created_by' => $this->actor, 'created_at' => '2090-01-01 00:00:00',
        ];

        $report = $this->importer->import($package, $this->target, $this->actor, 'synthetic-credit-note', false);

        self::assertSame(1, $report['created']['credit_note_offsets']);
        self::assertSame(
            ['invoices.number_series_id' => 1, 'invoices.price_level_id' => 1, 'purchase_invoice_items.expense_rule_id' => 1],
            $report['detached'],
        );
        $ids = $this->pdo->query('SELECT invoice_type, id FROM invoices WHERE supplier_id=' . $this->target)->fetchAll(PDO::FETCH_KEY_PAIR);
        $offset = $this->pdo->query('SELECT invoice_id, credit_note_id FROM credit_note_offsets WHERE supplier_id=' . $this->target)->fetch(PDO::FETCH_ASSOC);
        self::assertSame([(int) $ids['invoice'], (int) $ids['credit_note']], [(int) $offset['invoice_id'], (int) $offset['credit_note_id']]);
        $invoice = $this->pdo->query('SELECT number_series_id, price_level_id, varsymbol FROM invoices WHERE id=' . (int) $ids['invoice'])->fetch(PDO::FETCH_ASSOC);
        self::assertSame([null, null, 'SYNTHETIC-2090-01'], [$invoice['number_series_id'], $invoice['price_level_id'], $invoice['varsymbol']]);

        $repeat = $this->importer->import($package, $this->target, $this->actor, 'synthetic-credit-note', false);
        self::assertSame(1, $repeat['existing']['credit_note_offsets']);
    }

    /**
     * Export z produkce nesl tři věci, na kterých obnova skončila: zápis zúčtování DPH
     * (`source_id` je klíč období, ne doklad), přeúčtovaný doklad (stornovaný zápis má
     * storno s vyšším ID, takže byl při vkládání chvíli aktivní vedle nového zaúčtování)
     * a vícoměnový bankovní účet, který nová firma při založení dostala jako jednoměnový.
     */
    public function testVatClearingReversedPostingAndSetupBankAccountAreRestored(): void
    {
        $package = self::readArrays($this->archive);
        $original = reset($package['tables']['journal_entries']);
        $lines = array_filter($package['tables']['journal_entry_lines'], static fn (array $line): bool => (int) $line['entry_id'] === (int) $original['id']);
        $nextEntry = max(array_keys($package['tables']['journal_entries']));
        $nextLine = max(array_keys($package['tables']['journal_entry_lines']));
        $add = function (array $values) use (&$package, &$nextEntry, &$nextLine, $original, $lines): int {
            $id = ++$nextEntry;
            $package['tables']['journal_entries'][$id] = array_replace($original, ['id' => $id, 'reversed_by' => null], $values);
            foreach ($lines as $line) {
                ++$nextLine;
                $package['tables']['journal_entry_lines'][$nextLine] = array_replace($line, ['id' => $nextLine, 'entry_id' => $id]);
            }
            return $id;
        };
        $reversedPosting = $add(['source_type' => 'purchase_invoice', 'source_id' => $this->purchase, 'description' => 'Syntetické původní zaúčtování']);
        $reversal = $add(['source_type' => 'purchase_invoice', 'source_id' => null, 'description' => 'Syntetické storno']);
        $package['tables']['journal_entries'][$reversedPosting]['reversed_by'] = $reversal;
        $add(['source_type' => 'purchase_invoice', 'source_id' => $this->purchase, 'description' => 'Syntetické nové zaúčtování']);
        $periodKey = \MyInvoice\Service\Accounting\Vat\VatClearingService::sourceIdFor(2090, 1, 'monthly');
        $clearing = $add(['source_type' => 'vat_clearing', 'source_id' => $periodKey, 'description' => 'Syntetické zúčtování DPH']);
        $package['tables']['vat_clearing_runs'][1] = [
            'id' => 1, 'supplier_id' => $this->source, 'source_id' => $periodKey, 'period_year' => 2090, 'period_first_month' => 1,
            'period_type' => 'monthly', 'period_start' => '2090-01-01', 'period_end' => '2090-01-31', 'entry_id' => $clearing,
            'input_vat' => '21.00', 'output_vat' => '21.00', 'settlement' => '0.00', 'status' => 'posted', 'trigger_source' => 'return_filed',
            'submission_id' => 987654, 'submission_form' => 'dphdp3', 'submission_variant' => 'B', 'submitted_at' => '2090-02-20 10:00:00',
            'computed_at' => '2090-02-20 10:00:00', 'computed_by' => $this->actor,
        ];
        $currency = (int) $this->pdo->query('SELECT default_currency_id FROM supplier WHERE id = ' . $this->source)->fetchColumn();
        $package['tables']['supplier_bank_accounts'][1] = [
            'id' => 1, 'supplier_id' => $this->source, 'currency_id' => null, 'label' => 'Syntetický vícoměnový účet',
            'account_number' => '1000000005', 'bank_code' => '0100', 'iban' => 'CZ0001000000001000000005', 'currency' => null,
            'account_canonical' => '1000000005', 'bank_code_norm' => '0100', 'kind' => 'current', 'analytic_suffix' => '002',
            'document_series' => 'BSYN', 'source' => 'currencies', 'is_active' => 1,
            'created_at' => '2090-01-01 00:00:00', 'updated_at' => '2090-01-01 00:00:00',
        ];
        self::assertGreaterThan(0, $currency);
        $targetCurrency = (int) $this->pdo->query('SELECT default_currency_id FROM supplier WHERE id = ' . $this->target)->fetchColumn();
        $this->pdo->prepare("INSERT INTO supplier_bank_accounts (supplier_id, currency_id, label, account_number, bank_code, currency, account_canonical, bank_code_norm, source)
            VALUES (?, ?, 'Výchozí účet z průvodce', '1000000005', '0100', 'CZK', '1000000005', '0100', 'currencies')")->execute([$this->target, $targetCurrency]);

        $dry = $this->importer->import($package, $this->target, $this->actor, 'synthetic-clearing');
        self::assertSame(1, $dry['created']['vat_clearing_runs']);
        $report = $this->importer->import($package, $this->target, $this->actor, 'synthetic-clearing', false);

        self::assertSame(1, $report['created']['vat_clearing_runs']);
        self::assertSame(['vat_clearing_runs.submission_id' => 1], $report['detached']);
        $purchase = (int) $this->pdo->query('SELECT id FROM purchase_invoices WHERE supplier_id = ' . $this->target)->fetchColumn();
        $postings = $this->pdo->query("SELECT description, source_id, reversed_by IS NOT NULL AS reversed FROM journal_entries
            WHERE supplier_id = {$this->target} AND source_type = 'purchase_invoice' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([
            ['Syntetické původní zaúčtování', $purchase, 1],
            ['Syntetické storno', null, 0],
            ['Syntetické nové zaúčtování', $purchase, 0],
        ], array_map(static fn (array $r): array => [$r['description'], $r['source_id'] === null ? null : (int) $r['source_id'], (int) $r['reversed']], $postings));
        $entry = $this->pdo->query("SELECT id, source_id FROM journal_entries WHERE supplier_id = {$this->target} AND source_type = 'vat_clearing'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame($periodKey, (int) $entry['source_id']);
        $run = $this->pdo->query('SELECT source_id, entry_id, submission_id, submission_variant, settlement, computed_at FROM vat_clearing_runs WHERE supplier_id = ' . $this->target)->fetch(PDO::FETCH_ASSOC);
        self::assertSame([$periodKey, (int) $entry['id'], null, 'B', '0.00', '2090-02-20 10:00:00'],
            [(int) $run['source_id'], (int) $run['entry_id'], $run['submission_id'], $run['submission_variant'], $run['settlement'], $run['computed_at']]);
        $account = $this->pdo->query('SELECT label, iban, currency, currency_id, analytic_suffix, document_series FROM supplier_bank_accounts WHERE supplier_id = ' . $this->target)->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([['label' => 'Syntetický vícoměnový účet', 'iban' => 'CZ0001000000001000000005', 'currency' => null, 'currency_id' => null,
            'analytic_suffix' => '002', 'document_series' => 'BSYN']], $account);

        $repeat = $this->importer->import($package, $this->target, $this->actor, 'synthetic-clearing', false);
        self::assertSame([], $repeat['created']);
        self::assertSame(1, $repeat['existing']['vat_clearing_runs']);
    }

    /**
     * Obnova reálného exportu přenesla jen doklady a deník; dokumenty, dimenze, interní
     * doklady, poznámky a šablony deníku, daně, automatiku banky, historii oprav a číselné
     * řady bylo nutné doplnit ručně a nová firma si nechala své výchozí nastavení.
     */
    public function testCompleteCompanyIsRestoredWithDocumentsAutomationAndNumbering(): void
    {
        $fixture = $this->seedCompleteCompany();
        $this->seedTargetDefaults();
        $targetSeries = (int) $this->pdo->query("SELECT id FROM number_series WHERE legacy_key = 'issued:invoice' AND supplier_id = " . $this->target)->fetchColumn();
        $package = self::readArrays($this->makeArchive(array_keys(MyuctoImportProfile::KEYLESS_TABLES), 'complete-export.zip', $fixture['files']));
        // Vazba na ostatní doklad (obnova je nepřenáší) se vynechá a vykáže.
        $package['tables']['document_links'][] = ['document_id' => $fixture['document'], 'supplier_id' => $this->source,
            'entity_type' => 'other_item', 'entity_id' => 987654, 'created_at' => '2090-01-20 10:00:00'];
        $oldDataDir = getenv('MYINVOICE_DATA_DIR');
        putenv('MYINVOICE_DATA_DIR=' . $this->root . '/runtime');
        try {
            $dry = $this->importer->import($package, $this->target, $this->actor, 'synthetic-complete');
            self::assertSame(2, $dry['created']['documents']);
            self::assertSame(0, $this->targetCount('documents'));
            self::assertSame([], glob($this->root . '/runtime/storage/documents/sup-' . $this->target . '/*/*') ?: []);
            self::assertSame([], glob($this->root . '/runtime/storage/invoices/sup-' . $this->target . '/_archive/*/*') ?: []);
            self::assertSame(1, $this->targetCount('bank_rule_templates'), 'Nanečisto se výchozí nastavení cíle nemění.');

            $report = $this->importer->import($package, $this->target, $this->actor, 'synthetic-complete', false);

            foreach (['document_folders' => 2, 'documents' => 2, 'document_files' => 1, 'document_dms_messages' => 1, 'document_extractions' => 2,
                'document_links' => 2, 'invoice_pdfs' => 1, 'dimension_types' => 1, 'dimension_values' => 2, 'document_dimensions' => 1,
                'journal_entry_line_dimensions' => 1, 'internal_documents' => 1, 'internal_document_lines' => 2, 'journal_entry_document_links' => 1,
                'journal_entry_templates' => 1, 'journal_entry_template_lines' => 2, 'journal_entry_notes' => 1, 'income_tax_returns' => 1,
                'tax_advance_schedules' => 1, 'bank_posting_rules' => 1, 'bank_card_merchant_map' => 1, 'expense_classification_rules' => 1,
                'bank_rule_templates' => 1, 'auto_posting_policy' => 1, 'pdf_signature_output_settings' => 1, 'accounting_corrections' => 1,
                'accounting_closing_steps' => 1, 'number_series' => 1, 'number_series_counters' => 1, 'invoice_counters' => 2,
                'accounting_document_series' => 1] as $table => $count) {
                self::assertSame($count, $report['created'][$table] ?? 0, $table);
            }
            self::assertSame(1, $report['reused']['number_series'] ?? 0);
            self::assertSame(['purchase_invoice_counters' => 1], $report['existing']);
            self::assertSame(['accounting_corrections.bank_transaction' => 1, 'document_links.other_item' => 1, 'internal_documents.document_links_json' => 1, 'number_series.other_item_kind' => 1], $report['dropped']);
            self::assertSame(['bank_posting_rules.last_rejected_tx_id' => 1], $report['detached']);
            self::assertSame(['auto_posting_policy' => 1, 'bank_rule_templates' => 1, 'journal_entry_templates' => 1, 'pdf_signature_output_settings' => 1], $report['replaced_setup']);
            self::assertSame([], $report['numbering_check']);
            self::assertSame(['invoice_number_format', 'invoice_number_period'], $report['numbering_adopted']);
            self::assertSame(4, $report['files']);
            self::assertSame([], $report['outside_scope']);

            $t = $this->target;
            $invoice = (int) $this->pdo->query('SELECT id FROM invoices WHERE supplier_id = ' . $t)->fetchColumn();
            $purchase = (int) $this->pdo->query('SELECT id FROM purchase_invoices WHERE supplier_id = ' . $t)->fetchColumn();
            $entry = (int) $this->pdo->query('SELECT id FROM journal_entries WHERE supplier_id = ' . $t)->fetchColumn();
            $line = (int) $this->pdo->query('SELECT MIN(id) FROM journal_entry_lines WHERE supplier_id = ' . $t)->fetchColumn();
            $client = (int) $this->pdo->query('SELECT id FROM clients WHERE supplier_id = ' . $t)->fetchColumn();
            $period = (int) $this->pdo->query('SELECT id FROM accounting_periods WHERE supplier_id = ' . $t)->fetchColumn();

            // 1. Dokumenty: strom složek, dokument z rozbalené zprávy, koš, soubory a vazby.
            $folders = $this->pdo->query('SELECT id, parent_id, name FROM document_folders WHERE supplier_id = ' . $t . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            self::assertNull($folders[0]['parent_id']);
            self::assertSame((int) $folders[0]['id'], (int) $folders[1]['parent_id']);
            $documents = $this->pdo->query('SELECT * FROM documents WHERE supplier_id = ' . $t . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            self::assertSame((int) $folders[1]['id'], (int) $documents[0]['folder_id']);
            self::assertSame((int) $documents[0]['id'], (int) $documents[1]['parent_document_id']);
            self::assertSame((int) $documents[1]['id'], (int) $documents[0]['signature_for_id']);
            self::assertSame([$this->actor, null, 'none'], [(int) $documents[0]['uploaded_by'], $documents[0]['thumb_path'], $documents[0]['thumb_status']]);
            self::assertSame(['2090-01-25 08:00:00', $this->actor], [$documents[1]['deleted_at'], (int) $documents[1]['deleted_by']]);
            foreach ($fixture['documentContents'] as $sha => $content) {
                self::assertSame($content, file_get_contents($this->root . '/runtime/storage/documents/sup-' . $t . '/' . substr($sha, 0, 2) . '/' . $sha));
            }
            self::assertSame((int) $documents[0]['id'], (int) $this->pdo->query('SELECT document_id FROM document_files WHERE supplier_id = ' . $t)->fetchColumn());
            self::assertSame((int) $documents[1]['id'], (int) $this->pdo->query('SELECT m.document_id FROM document_dms_messages m JOIN documents d ON d.id = m.document_id WHERE d.supplier_id = ' . $t)->fetchColumn());
            self::assertSame([null, (int) $documents[0]['id']], array_map(static fn ($v): ?int => $v === null ? null : (int) $v,
                $this->pdo->query('SELECT document_id FROM document_extractions WHERE supplier_id = ' . $t . ' ORDER BY document_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)));
            $links = $this->pdo->query('SELECT entity_type, entity_id FROM document_links WHERE supplier_id = ' . $t . ' ORDER BY entity_type')->fetchAll(PDO::FETCH_KEY_PAIR);
            self::assertSame(['invoice' => $invoice, 'purchase_invoice' => $purchase], array_map('intval', $links));

            // 2. Archiv PDF vydaných faktur.
            $pdf = $this->pdo->query('SELECT p.invoice_id, p.filename FROM invoice_pdfs p JOIN invoices i ON i.id = p.invoice_id WHERE i.supplier_id = ' . $t)->fetch(PDO::FETCH_ASSOC);
            self::assertSame($invoice, (int) $pdf['invoice_id']);
            self::assertSame($fixture['archiveContent'], file_get_contents($this->root . '/runtime/storage/invoices/sup-' . $t . '/_archive/2090-01/' . $pdf['filename']));

            // 3. Dimenze a jejich přiřazení dokladům i řádkům deníku.
            $type = (int) $this->pdo->query('SELECT id FROM dimension_types WHERE supplier_id = ' . $t)->fetchColumn();
            $values = $this->pdo->query('SELECT code, id FROM dimension_values WHERE supplier_id = ' . $t)->fetchAll(PDO::FETCH_KEY_PAIR);
            self::assertSame((int) $values['SYN-A'], (int) $this->pdo->query("SELECT parent_id FROM dimension_values WHERE code = 'SYN-A1' AND supplier_id = " . $t)->fetchColumn());
            self::assertSame([$invoice, $type, (int) $values['SYN-A']], array_map('intval', $this->pdo->query('SELECT doc_id, dimension_type_id, dimension_value_id FROM document_dimensions WHERE supplier_id = ' . $t)->fetch(PDO::FETCH_NUM)));
            self::assertSame([$line, (int) $values['SYN-A1']], array_map('intval', $this->pdo->query('SELECT line_id, dimension_value_id FROM journal_entry_line_dimensions WHERE supplier_id = ' . $t)->fetch(PDO::FETCH_NUM)));

            // 4. Interní doklad s řádky (dimenze v JSON se přemapují).
            $internal = $this->pdo->query('SELECT id, partner_id, document_links_json FROM internal_documents WHERE supplier_id = ' . $t)->fetch(PDO::FETCH_ASSOC);
            self::assertSame($client, (int) $internal['partner_id']);
            self::assertSame([['doc_type' => 'invoice', 'doc_id' => $invoice]], json_decode((string) $internal['document_links_json'], true));
            self::assertSame([(string) $type => (int) $values['SYN-A']], json_decode((string) $this->pdo->query('SELECT dimensions_json FROM internal_document_lines WHERE position = 1 AND supplier_id = ' . $t)->fetchColumn(), true));

            // 5. Deník: vazby zápisu na doklady, šablony, poznámky.
            self::assertSame([$entry, $invoice], array_map('intval', $this->pdo->query('SELECT entry_id, doc_id FROM journal_entry_document_links WHERE supplier_id = ' . $t)->fetch(PDO::FETCH_NUM)));
            self::assertSame(['Syntetická šablona'], $this->pdo->query('SELECT name FROM journal_entry_templates WHERE supplier_id = ' . $t)->fetchAll(PDO::FETCH_COLUMN));
            self::assertSame([$entry, 'Syntetická poznámka'], [(int) ($note = $this->pdo->query('SELECT entry_id, body FROM journal_entry_notes WHERE supplier_id = ' . $t)->fetch(PDO::FETCH_ASSOC))['entry_id'], $note['body']]);

            // 6. Daně: přiznání a zálohy z něj.
            $return = (int) $this->pdo->query('SELECT id FROM income_tax_returns WHERE supplier_id = ' . $t)->fetchColumn();
            self::assertSame($return, (int) $this->pdo->query('SELECT source_return_id FROM tax_advance_schedules WHERE supplier_id = ' . $t)->fetchColumn());

            // 7. Banka a automatika; výchozí nastavení nové firmy nahradilo nastavení zdroje.
            self::assertSame([null, 0], array_map(static fn ($v): ?int => $v === null ? null : (int) $v, $this->pdo->query('SELECT last_rejected_tx_id, hit_count FROM bank_posting_rules WHERE supplier_id = ' . $t)->fetch(PDO::FETCH_NUM)));
            self::assertSame($client, (int) $this->pdo->query('SELECT client_id FROM bank_card_merchant_map WHERE supplier_id = ' . $t)->fetchColumn());
            self::assertSame($client, (int) $this->pdo->query('SELECT vendor_client_id FROM expense_classification_rules WHERE supplier_id = ' . $t)->fetchColumn());
            self::assertSame(['synthetic.source'], $this->pdo->query('SELECT template_key FROM bank_rule_templates WHERE supplier_id = ' . $t)->fetchAll(PDO::FETCH_COLUMN));
            self::assertSame(['document.invoice' => 'auto'], $this->pdo->query('SELECT operation_type, level FROM auto_posting_policy WHERE supplier_id = ' . $t)->fetchAll(PDO::FETCH_KEY_PAIR));
            self::assertSame(['invoice' => 1], array_map('intval', $this->pdo->query('SELECT output_type, enabled FROM pdf_signature_output_settings WHERE supplier_id = ' . $t)->fetchAll(PDO::FETCH_KEY_PAIR)));

            // 8. Historie oprav a kroků uzávěrky.
            self::assertSame($invoice, (int) $this->pdo->query('SELECT entity_id FROM accounting_corrections WHERE supplier_id = ' . $t)->fetchColumn());
            self::assertSame($period, (int) $this->pdo->query('SELECT period_id FROM accounting_closing_steps WHERE supplier_id = ' . $t)->fetchColumn());

            // 9. Číselné řady navazují: řada z průvodce převzala předponu zdroje, počítadla sedí.
            $series = $this->pdo->query('SELECT legacy_key, id, prefix, template, scope_id FROM number_series WHERE supplier_id = ' . $t . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            self::assertSame([['issued:invoice', $targetSeries, 'FV', '{YY}{CCCC}', 0], [null, null, null, 'K{CCC}', $client]],
                array_map(static fn (array $r): array => [$r['legacy_key'], $r['legacy_key'] === null ? null : (int) $r['id'], $r['prefix'], $r['template'], (int) $r['scope_id']], $series));
            self::assertSame($targetSeries, (int) $this->pdo->query('SELECT number_series_id FROM invoices WHERE id = ' . $invoice)->fetchColumn(), 'Doklad zůstane ve své řadě.');
            self::assertSame(4, (int) $this->pdo->query('SELECT c.last_number FROM number_series_counters c JOIN number_series n ON n.id = c.series_id WHERE n.supplier_id = ' . $t)->fetchColumn());
            self::assertSame(['0' => 12, (string) $client => 3], array_map('intval', $this->pdo->query("SELECT client_id, last_number FROM invoice_counters WHERE supplier_id = {$t} ORDER BY client_id")->fetchAll(PDO::FETCH_KEY_PAIR)));
            self::assertSame(5, (int) $this->pdo->query("SELECT last_number FROM purchase_invoice_counters WHERE supplier_id = {$t} AND period = '209001'")->fetchColumn());
            self::assertSame(['ID', 5], [($ads = $this->pdo->query('SELECT prefix, next_number FROM accounting_document_series WHERE supplier_id = ' . $t)->fetch(PDO::FETCH_ASSOC))['prefix'], (int) $ads['next_number']]);
            self::assertSame(['{YY}{CCCC}', 'year'], array_values($this->pdo->query('SELECT invoice_number_format, invoice_number_period FROM supplier WHERE id = ' . $t)->fetch(PDO::FETCH_ASSOC)));

            // Opakovaná obnova týchž dat projde; firma mezitím smí vydat další doklad.
            $this->pdo->prepare("UPDATE invoice_counters SET last_number = 13 WHERE supplier_id = ? AND client_id = 0")->execute([$t]);
            $repeat = $this->importer->import($package, $this->target, $this->actor, 'synthetic-complete', false);
            self::assertSame([], $repeat['created']);
            self::assertSame(2, $repeat['existing']['documents']);
            self::assertSame(2, $repeat['existing']['invoice_counters']);
            self::assertSame(2, $repeat['reconciliation']['document_links']);

            // Změněný obsah obnovené firmy opakovaná obnova odhalí.
            $this->pdo->prepare("UPDATE journal_entry_notes SET body = 'Změněno' WHERE supplier_id = ?")->execute([$t]);
            try {
                $this->importer->import($package, $this->target, $this->actor, 'synthetic-complete', false);
                self::fail('Změněná poznámka musí rekonciliaci zastavit.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('journal_entry_notes.body', $e->getMessage());
            }
        } finally {
            putenv($oldDataDir === false ? 'MYINVOICE_DATA_DIR' : 'MYINVOICE_DATA_DIR=' . $oldDataDir);
        }
    }

    /**
     * Export starší verze ani převod zálohy MyInvoice nové tabulky nenese: obnova projde
     * jako dřív, výchozí nastavení cíle zůstane a řady bez počítadel vykáže ke kontrole.
     */
    public function testExportWithoutNewTablesRestoresUnchangedAndFlagsNumbering(): void
    {
        $this->seedTargetDefaults();
        $package = self::readArrays($this->archive);
        foreach ([...MyuctoImportProfile::OPTIONAL_GRAPH_TABLES, ...array_keys(MyuctoImportProfile::KEYLESS_TABLES)] as $table) {
            self::assertSame([], $package['tables'][$table] ?? [], $table);
            unset($package['tables'][$table]);
        }
        self::assertArrayNotHasKey('stored_files', $package);
        $report = $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
        self::assertSame([], array_intersect_key($report['created'], array_flip([...MyuctoImportProfile::OPTIONAL_GRAPH_TABLES, ...array_keys(MyuctoImportProfile::KEYLESS_TABLES)])));
        self::assertSame(['invoice_counters', 'purchase_invoice_counters'], $report['numbering_check']);
        self::assertSame([], $report['replaced_setup']);
        self::assertSame(1, $this->targetCount('bank_rule_templates'));
        self::assertSame(1, $this->targetCount('journal_entry_templates'));
        self::assertSame(1, $this->targetCount('purchase_invoice_counters'));

        // Obnova starším profilem ze shodných dat se dá zopakovat i s úplným profilem.
        $this->pdo->prepare("UPDATE external_entity_map SET external_id = REPLACE(external_id, ?, 'vat-clearing-runs-v1:')
            WHERE supplier_id = ? AND entity_type = 'myucto_import'")
            ->execute([MyuctoImportProfile::VERSION . ':', $this->target]);
        $repeat = $this->importer->import(self::readArrays($this->archive), $this->target, $this->actor, 'synthetic-instance', false);
        self::assertSame([], $repeat['created']);
        self::assertSame(1, $repeat['existing']['invoices']);
    }

    /** Výchozí nastavení, které nová firma dostane při založení a průvodci řad. */
    private function seedTargetDefaults(): void
    {
        $t = $this->target;
        $this->pdo->prepare("INSERT INTO bank_rule_templates (supplier_id, template_key, name_cs, name_en, direction, operation_type, rule_key) VALUES (?, 'synthetic.default', 'Výchozí', 'Default', 'outgoing', 'bank.fee', 'bank.fee')")->execute([$t]);
        $this->pdo->prepare("INSERT INTO auto_posting_policy (supplier_id, operation_type, level) VALUES (?, 'document.invoice', 'suggest')")->execute([$t]);
        $this->pdo->prepare("INSERT INTO pdf_signature_output_settings (supplier_id, output_type) VALUES (?, 'invoice')")->execute([$t]);
        $this->pdo->prepare("INSERT INTO journal_entry_templates (supplier_id, name, is_seeded, seed_key) VALUES (?, 'Mzdy', 1, 'payroll')")->execute([$t]);
        $this->pdo->prepare("INSERT INTO number_series (supplier_id, doc_kind, scope_type, scope_id, storage, legacy_key, name, is_default) VALUES (?, 'invoice', 'company', 0, 'legacy', 'issued:invoice', '', 1)")->execute([$t]);
        $this->pdo->prepare("INSERT INTO purchase_invoice_counters (supplier_id, period, last_number) VALUES (?, '209001', 1)")->execute([$t]);
    }

    /**
     * Zdrojová firma se vším, co obnova nově přenáší (skupiny 1–9 zadání).
     *
     * @return array{files:list<array{storage_path:string,content:string,kind:string}>,documentContents:array<string,string>,archiveContent:string,document:int}
     */
    private function seedCompleteCompany(): array
    {
        $s = $this->source;
        $a = $this->actor;
        $pdo = $this->pdo;
        $client = (int) $pdo->query('SELECT id FROM clients WHERE supplier_id = ' . $s)->fetchColumn();
        $entry = (int) $pdo->query('SELECT id FROM journal_entries WHERE supplier_id = ' . $s)->fetchColumn();
        $line = (int) $pdo->query('SELECT MIN(id) FROM journal_entry_lines WHERE supplier_id = ' . $s)->fetchColumn();
        $period = (int) $pdo->query('SELECT id FROM accounting_periods WHERE supplier_id = ' . $s)->fetchColumn();
        $insert = function (string $table, array $row) use ($pdo): int {
            $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`, `', array_keys($row)) . '`) VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
            return (int) $pdo->lastInsertId();
        };
        $root = $insert('document_folders', ['supplier_id' => $s, 'name' => 'Syntetická složka', 'created_by' => $a]);
        $child = $insert('document_folders', ['supplier_id' => $s, 'parent_id' => $root, 'name' => 'Syntetická podsložka', 'created_by' => $a]);
        $contents = ["%PDF-synthetic-dms-original\n", "synthetic-dms-extracted\n"];
        $shas = array_map(static fn (string $c): string => hash('sha256', $c), $contents);
        $document = $insert('documents', ['supplier_id' => $s, 'folder_id' => $child, 'title' => 'Syntetický dokument', 'original_name' => 'syntheticky.pdf',
            'filename' => $shas[0], 'sha256' => $shas[0], 'mime_type' => 'application/pdf', 'size_bytes' => strlen($contents[0]), 'doc_type' => 'pdf',
            'thumb_path' => 'sup-' . $s . '/_thumbs/synthetic.png', 'thumb_status' => 'generated', 'uploaded_by' => $a, 'created_at' => '2090-01-20 08:00:00']);
        $extracted = $insert('documents', ['supplier_id' => $s, 'folder_id' => $child, 'title' => 'Syntetická příloha zprávy', 'original_name' => 'priloha.txt',
            'filename' => $shas[1], 'sha256' => $shas[1], 'mime_type' => 'text/plain', 'size_bytes' => strlen($contents[1]), 'doc_type' => 'other',
            'source' => 'zfo_extract', 'parent_document_id' => $document, 'uploaded_by' => $a, 'deleted_at' => '2090-01-25 08:00:00', 'deleted_by' => $a,
            'created_at' => '2090-01-20 08:00:00']);
        $pdo->prepare('UPDATE documents SET signature_for_id = ? WHERE id = ?')->execute([$extracted, $document]);
        $insert('document_files', ['document_id' => $document, 'supplier_id' => $s, 'role' => 'primary', 'sha256' => $shas[0], 'filename' => $shas[0], 'uploaded_by' => $a]);
        $insert('document_dms_messages', ['document_id' => $extracted, 'dm_id' => '1234567890', 'direction' => 'received', 'sender_box_id' => 'abc1234']);
        $insert('document_extractions', ['supplier_id' => $s, 'document_id' => null, 'sha256' => hash('sha256', 'synthetic-unlinked'), 'status' => 'ok', 'extracted_at' => '2090-01-20 08:00:00']);
        $insert('document_extractions', ['supplier_id' => $s, 'document_id' => $document, 'sha256' => $shas[0], 'status' => 'ok', 'extracted_at' => '2090-01-20 08:00:00']);
        $insert('document_links', ['document_id' => $document, 'supplier_id' => $s, 'entity_type' => 'invoice', 'entity_id' => $this->invoice]);
        $insert('document_links', ['document_id' => $document, 'supplier_id' => $s, 'entity_type' => 'purchase_invoice', 'entity_id' => $this->purchase]);
        $archiveContent = "%PDF-synthetic-archived-invoice\n";
        $archiveName = '20900115-101010-' . substr(hash('sha256', $archiveContent), 0, 8) . '-Faktura-SYNTHETIC.pdf';
        $insert('invoice_pdfs', ['invoice_id' => $this->invoice, 'filename' => $archiveName, 'size_bytes' => strlen($archiveContent),
            'sha256' => hash('sha256', $archiveContent), 'was_sent' => 1, 'reason' => 'sent', 'archived_at' => '2090-01-15 10:10:10']);

        $type = $insert('dimension_types', ['supplier_id' => $s, 'code' => 'syn_stredisko', 'name' => 'Syntetické středisko', 'kind' => 'cost_center']);
        $value = $insert('dimension_values', ['type_id' => $type, 'supplier_id' => $s, 'code' => 'SYN-A', 'name' => 'Syntetická hodnota', 'responsible_user_id' => $a]);
        $subValue = $insert('dimension_values', ['type_id' => $type, 'supplier_id' => $s, 'parent_id' => $value, 'code' => 'SYN-A1', 'name' => 'Syntetická podhodnota']);
        $insert('document_dimensions', ['supplier_id' => $s, 'doc_type' => 'invoice', 'doc_id' => $this->invoice, 'item_no' => 0,
            'dimension_type_id' => $type, 'dimension_value_id' => $value, 'assignment_origin' => 'manual']);
        $insert('journal_entry_line_dimensions', ['line_id' => $line, 'dimension_type_id' => $type, 'supplier_id' => $s,
            'dimension_value_id' => $subValue, 'assignment_origin' => 'manual']);

        $internal = $insert('internal_documents', ['supplier_id' => $s, 'kind' => 'manual', 'title' => 'Syntetický interní doklad', 'document_date' => '2090-01-31',
            'entry_date' => '2090-01-31', 'partner_id' => $client, 'amount' => '50.00', 'status' => 'draft', 'origin' => 'draft', 'created_by' => $a,
            'document_links_json' => json_encode([['doc_type' => 'invoice', 'doc_id' => $this->invoice], ['doc_type' => 'invoice', 'doc_id' => 987654321]])]);
        $insert('internal_document_lines', ['supplier_id' => $s, 'internal_document_id' => $internal, 'position' => 1, 'account_code' => '311990',
            'side' => 'debit', 'amount' => '50.00', 'dimensions_json' => json_encode([(string) $type => $value])]);
        $insert('internal_document_lines', ['supplier_id' => $s, 'internal_document_id' => $internal, 'position' => 2, 'account_code' => '602990',
            'side' => 'credit', 'amount' => '50.00']);

        $insert('journal_entry_document_links', ['supplier_id' => $s, 'entry_id' => $entry, 'doc_type' => 'invoice', 'doc_id' => $this->invoice, 'created_by' => $a]);
        $template = $insert('journal_entry_templates', ['supplier_id' => $s, 'name' => 'Syntetická šablona', 'is_seeded' => 0, 'created_by' => $a]);
        $insert('journal_entry_template_lines', ['template_id' => $template, 'line_no' => 1, 'account_code' => '311990', 'side' => 'debit']);
        $insert('journal_entry_template_lines', ['template_id' => $template, 'line_no' => 2, 'account_code' => '602990', 'side' => 'credit']);
        $insert('journal_entry_notes', ['supplier_id' => $s, 'entry_id' => $entry, 'body' => 'Syntetická poznámka', 'created_by' => $a]);

        $return = $insert('income_tax_returns', ['supplier_id' => $s, 'year' => 2089, 'taxpayer_type' => 'po', 'inputs' => '{}', 'created_by' => $a,
            'created_at' => '2090-03-01 10:00:00']);
        $insert('tax_advance_schedules', ['supplier_id' => $s, 'taxpayer_type' => 'po', 'advance_kind' => 'tax', 'period_year' => 2090, 'seq_no' => 1,
            'amount' => '1000.00', 'due_date' => '2090-06-15', 'source_return_id' => $return]);

        $insert('bank_posting_rules', ['supplier_id' => $s, 'name' => 'Syntetické pravidlo', 'direction' => 'outgoing', 'message_contains' => 'SYNTETICKY',
            'debit_account_code' => '311990', 'credit_account_code' => '602990', 'last_rejected_tx_id' => 987654321, 'created_by' => $a]);
        $insert('bank_card_merchant_map', ['supplier_id' => $s, 'merchant_key' => 'synthetic merchant', 'client_id' => $client]);
        $insert('expense_classification_rules', ['supplier_id' => $s, 'name' => 'Syntetické zařazení', 'vendor_client_id' => $client,
            'expense_kind' => 'service', 'created_by' => $a]);
        $insert('bank_rule_templates', ['supplier_id' => $s, 'template_key' => 'synthetic.source', 'name_cs' => 'Zdroj', 'name_en' => 'Source',
            'direction' => 'incoming', 'operation_type' => 'bank.interest', 'rule_key' => 'bank.interest']);
        $insert('auto_posting_policy', ['supplier_id' => $s, 'operation_type' => 'document.invoice', 'level' => 'auto', 'updated_by' => $a]);
        $insert('pdf_signature_output_settings', ['supplier_id' => $s, 'output_type' => 'invoice', 'enabled' => 1]);

        $insert('accounting_corrections', ['supplier_id' => $s, 'event_type' => 'manual_post', 'entity_type' => 'invoice', 'entity_id' => $this->invoice, 'created_by' => $a]);
        // Oprava u pohybu, který ve zdroji mezitím zanikl.
        $insert('accounting_corrections', ['supplier_id' => $s, 'event_type' => 'reject', 'entity_type' => 'bank_transaction', 'entity_id' => 987654321]);
        $insert('accounting_closing_steps', ['supplier_id' => $s, 'period_id' => $period, 'step_key' => 'precheck', 'status' => 'done', 'done_by' => $a]);

        $legacySeries = $insert('number_series', ['supplier_id' => $s, 'doc_kind' => 'invoice', 'scope_type' => 'company', 'scope_id' => 0, 'storage' => 'legacy',
            'legacy_key' => 'issued:invoice', 'name' => '', 'prefix' => 'FV', 'template' => '{YY}{CCCC}', 'period' => 'year', 'is_default' => 1]);
        $pdo->prepare('UPDATE invoices SET number_series_id = ? WHERE id = ?')->execute([$legacySeries, $this->invoice]);
        $native = $insert('number_series', ['supplier_id' => $s, 'doc_kind' => 'invoice', 'scope_type' => 'client', 'scope_id' => $client, 'storage' => 'native',
            'name' => 'Syntetická řada klienta', 'template' => 'K{CCC}', 'period' => 'year', 'is_default' => 0]);
        $insert('number_series_counters', ['series_id' => $native, 'period_key' => '2090', 'last_number' => 4]);
        $insert('number_series', ['supplier_id' => $s, 'doc_kind' => 'other_receivable', 'scope_type' => 'other_item_kind', 'scope_id' => 987654,
            'storage' => 'legacy', 'legacy_key' => 'doc:other_receivable:987654', 'name' => 'Ostatní']);
        $insert('invoice_counters', ['supplier_id' => $s, 'client_id' => 0, 'revenue_category_id' => 0, 'invoice_type' => 'invoice', 'period' => '2090', 'last_number' => 12]);
        $insert('invoice_counters', ['supplier_id' => $s, 'client_id' => $client, 'revenue_category_id' => 0, 'invoice_type' => 'invoice', 'period' => '2090', 'last_number' => 3]);
        $insert('purchase_invoice_counters', ['supplier_id' => $s, 'period' => '209001', 'last_number' => 5]);
        $insert('accounting_document_series', ['supplier_id' => $s, 'series_code' => 'manual', 'register_id' => 0, 'fiscal_year' => 2090, 'prefix' => 'ID', 'next_number' => 5]);
        $pdo->prepare("UPDATE supplier SET invoice_number_format = '{YY}{CCCC}', invoice_number_period = 'year' WHERE id = ?")->execute([$s]);
        $pdo->prepare("UPDATE supplier SET invoice_number_format = NULL, invoice_number_period = 'month' WHERE id = ?")->execute([$this->target]);

        $files = [];
        foreach ($contents as $index => $content) {
            $files[] = ['storage_path' => 'documents/sup-' . $s . '/' . substr($shas[$index], 0, 2) . '/' . $shas[$index], 'content' => $content, 'kind' => 'document'];
        }
        $files[] = ['storage_path' => 'invoices/sup-' . $s . '/_archive/2090-01/' . $archiveName, 'content' => $archiveContent, 'kind' => 'attachment'];

        return ['files' => $files, 'documentContents' => array_combine($shas, $contents), 'archiveContent' => $archiveContent,
            'document' => $document];
    }

    public function testMissingPostingDocumentRollsBackEntireGraph(): void
    {
        $package = self::readArrays($this->archive);
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
        $package = self::readArrays($this->archive);
        $package['tables']['invoices'][$this->invoice]['client_id'] = 999999999;
        try { $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false); self::fail('Chybějící vazba nesmí projít.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('Chybí zdrojová vazba', $e->getMessage()); }
        self::assertSame(0, $this->targetCount('clients')); self::assertSame(0, $this->targetCount('external_entity_map'));
    }

    public function testChangedSourceIsRejectedAfterApply(): void
    {
        $package = self::readArrays($this->archive);
        $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
        $package['tables']['invoices'][$this->invoice]['total_vat'] = '22.00';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('jinými daty');
        $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false);
    }

    public function testDifferentCompanyIdentityIsRejected(): void
    {
        $package = self::readArrays($this->archive);
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
            // Počítadla řad export nese (tabulka s `supplier_id`); obnova je dřív zahazovala mimo rozsah.
            $this->pdo->prepare("INSERT INTO invoice_counters (supplier_id, client_id, revenue_category_id, invoice_type, period, last_number) VALUES (?, 0, 0, 'invoice', '2090', 7)")->execute([$this->source]);
            $exporter = $container->get(\MyInvoice\Service\Export\Instance\InstanceExportService::class);
            $export = $exporter->runForSupplier($this->source, [\MyInvoice\Service\Export\Instance\InstanceExportService::PART_RESTORE], targetDir: $this->root . '/native-export');
            $package = self::readArrays($export['abs_path'], (string) $config->get('cron.backup.password', ''));
            self::assertSame($this->source, $package['manifest']['supplier']['id']);
            self::assertCount(1, $package['tables']['recurring_invoice_templates']);
            self::assertArrayHasKey('posting_rules', $package['tables']);
            self::assertArrayHasKey('de_movement_classification', $package['tables']);
            self::assertArrayHasKey('de_movement_classification_history', $package['tables']);
            self::assertCount(1, $package['tables']['journal_document_postings']);
            self::assertCount(3, $package['tables']['posting_rules']);
            self::assertCount(14, $package['tables']['de_movement_classification']);
            self::assertCount(30, $package['tables']['de_movement_classification_history']);
            self::assertCount(1, $package['tables']['invoice_counters']);
            self::assertArrayNotHasKey('invoice_counters', $package['skipped']);
            $report = $container->get(MyuctoImporter::class)->import($package, $this->target, $this->actor, 'synthetic-live-export');
            self::assertSame(1, $report['created']['invoices']); self::assertSame(1, $report['files']);
            self::assertSame(0, $this->targetCount('invoices'));
        } finally { putenv($oldDataDir === false ? 'MYINVOICE_DATA_DIR' : 'MYINVOICE_DATA_DIR=' . $oldDataDir); }
    }

    public function testOwnTargetDataIsNotMerged(): void
    {
        $package = self::readArrays($this->archive);
        $currency = (int) $this->pdo->query('SELECT default_currency_id FROM supplier WHERE id = ' . $this->target)->fetchColumn();
        $country = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $this->pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id) VALUES (?, 'Vlastní syntetický partner', 'Test', 'Test', '11000', ?, 'own@example.test', ?)")->execute([$this->target, $country, $currency]);
        try { $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false); self::fail('Vlastní data cíle nesmí být přimíchána.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('vlastní data', $e->getMessage()); }
        self::assertSame(1, $this->targetCount('clients')); self::assertSame(0, $this->targetCount('invoices'));
    }

    public function testHistoricalVatConfigurationMustMatch(): void
    {
        $package = self::readArrays($this->archive);
        $this->pdo->prepare("INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified) VALUES (?, '2089-01-01', 0, 0)")->execute([$this->target]);
        try { $this->importer->import($package, $this->target, $this->actor, 'synthetic-instance', false); self::fail('Odlišná historie DPH nesmí projít.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('Historie DPH', $e->getMessage()); }
        self::assertSame(0, $this->targetCount('external_entity_map'));
    }

    public function testRepeatDetectsChangedTarget(): void
    {
        $package = self::readArrays($this->archive);
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
        $package = self::readArrays($this->archive);
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
        $package = self::readArrays($this->archive);
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
        $package = self::readArrays($this->archive);
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
        $package = self::readArrays($this->archive);
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
        $package = self::readArrays($this->archive);
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
        $package = self::readArrays($this->archive);
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
        $package = self::readArrays($this->archive);
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
        $package = self::readArrays($this->archive);
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
        $package = self::readArrays($this->archive);
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
        $package = self::readArrays($this->makeArchive(['supplier_accounting_frameworks'], 'framework-export.zip'));
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
        $package = self::readArrays($this->makeArchive(['supplier_accounting_frameworks'], 'framework-export.zip'));
        $report = $this->importer->import($package, $this->target, $this->actor, 'synthetic-framework', false);
        self::assertSame(1, $report['created']['journal_entries']);
        self::assertSame(['supplier_accounting_frameworks' => 1], $report['outside_scope']);
    }

    public function testExportWithoutFrameworkHistoryCannotFillNonprofitTarget(): void
    {
        $this->framework($this->target, '2090-01-01', 'cz_504');
        $package = self::readArrays($this->archive);
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

    /**
     * @param list<string> $extraTables
     * @param list<array{storage_path:string,content:string,kind:string}> $files soubory úložiště v `restore.files`
     */
    private function makeArchive(array $extraTables = [], string $name = 'synthetic-export.zip', array $files = []): string
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
        $restoreFiles = [];
        foreach ($files as $index => $file) {
            $entry = 'prilohy/synthetic-' . $index . '.bin';
            $zip->addFromString($entry, $file['content']);
            $checksums[$entry] = ['sha256' => hash('sha256', $file['content']), 'size' => strlen($file['content'])];
            $restoreFiles[] = ['entry' => $entry, 'storage_path' => $file['storage_path'], 'sha256' => hash('sha256', $file['content']), 'kind' => $file['kind']];
        }
        $manifest = ['format' => 'myucto-instance-export', 'version' => 6, 'supplier' => ['id' => $this->source], 'restore' => ['available' => true, 'files' => $restoreFiles, 'documents' => [['entry' => 'doklady/synthetic-original.pdf', 'storage_path' => 'purchase-invoices/sup-' . $this->source . '/synthetic-original.pdf']]],
            'sections' => ['data' => ['tables' => $tables, 'shared_tables' => $shared]], 'checksums' => $checksums];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)); self::assertTrue($zip->close()); return $path;
    }

    private function removeDir(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') { $child = $path . '/' . $entry; if (is_dir($child)) $this->removeDir($child); else @unlink($child); }
        @rmdir($path);
    }

    /**
     * Balíček s tabulkami v polích, aby test mohl export cíleně poškodit. Úložiště čtečky
     * zůstává v balíčku: drží dočasné soubory příloh, dokud balíček žije.
     */
    private static function readArrays(string $path, string $password = ''): array
    {
        $package = (new MyuctoExportReader())->read($path, $password);
        $package['store'] = $package['tables'];
        $package['tables'] = $package['tables']->toArrays();
        return $package;
    }
}
