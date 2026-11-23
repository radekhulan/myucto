<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\Myucto;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\Instance\InstanceExportService;
use MyInvoice\Service\Migration\Myucto\MyuctoExportReader;
use MyInvoice\Service\Migration\Myucto\MyuctoImporter;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kompletní export dat → obnova z exportu MyÚčta: ostatní pohledávky a závazky s druhy,
 * splátkami, úhradami, opakováním, kontací, dimenzemi a vazbou z faktury. Zaúčtovaná
 * položka se převezme se svým zápisem deníku a znovu se neúčtuje. Celá cesta jako v UI:
 * skutečný export, čtečka ZIPu, import.
 */
#[Group('integration')]
final class MyuctoImportOtherItemsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private PDO $pdo;
    private int $source;
    private int $target;
    private int $actor;
    private string $root;
    private string $lastExport = '';
    /** @var array<string,int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->pdo = Bootstrap::buildContainer()->get(Connection::class)->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $base = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->actor = (int) $this->pdo->query('SELECT id FROM users WHERE is_active = 1 LIMIT 1')->fetchColumn();
        $this->source = $this->createIsolatedSupplier($this->pdo, $base);
        $this->target = $this->createIsolatedSupplier($this->pdo, $base);
        foreach ([$this->source, $this->target] as $id) {
            $this->pdo->prepare("UPDATE supplier SET ic = '00000000', accounting_mode = 'double_entry', company_name = 'Syntetické ostatní položky', supplier_group_id = NULL WHERE id = ?")
                ->execute([$id]);
            $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_default) VALUES (?, 'CZK', 'Syntetická měna', 'Kč', 'Syntetická měna', 'Synthetic currency', 1)")->execute([$id]);
            $this->pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $this->pdo->lastInsertId(), $id]);
        }
        $this->root = sys_get_temp_dir() . '/myucto-other-import-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $this->seedSource();
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->removeDir($this->root);
        }
        if (!isset($this->source, $this->target)) {
            return;
        }
        foreach ([$this->source, $this->target] as $id) {
            $this->pdo->prepare('UPDATE invoices SET other_item_id = NULL WHERE supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE c FROM number_series_counters c JOIN number_series n ON n.id = c.series_id WHERE n.supplier_id = ?')->execute([$id]);
            foreach (['other_item_allocations', 'other_item_auto_match_blocks', 'other_item_schedule_occurrences', 'other_item_schedules',
                'other_item_installments', 'other_item_posting_lines', 'other_item_posting_overrides', 'other_item_settings', 'document_dimensions'] as $table) {
                $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE supplier_id = ?')->execute([$id]);
            }
            $this->pdo->prepare('UPDATE other_items SET journal_entry_id = NULL, reversal_entry_id = NULL WHERE supplier_id = ?')->execute([$id]);
            foreach (['journal_entry_lines', 'journal_entries', 'other_items', 'other_item_posting_profiles', 'number_series', 'accounting_document_series',
                'other_item_kinds', 'accounting_periods', 'dimension_values', 'dimension_types', 'external_entity_map'] as $table) {
                $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE supplier_id = ?')->execute([$id]);
            }
            $this->pdo->prepare('DELETE bt FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id WHERE bs.supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE ii FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE i.supplier_id = ?')->execute([$id]);
            foreach (['bank_statements', 'invoices', 'chart_of_accounts', 'clients', 'supplier_vat_status_history', 'supplier_accounting_frameworks',
                'invoice_counters', 'supplier_bank_accounts', 'bank_rule_templates', 'auto_posting_policy', 'pdf_signature_output_settings',
                'journal_entry_templates'] as $table) {
                $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE supplier_id = ?')->execute([$id]);
            }
            $baseCurrency = (int) $this->pdo->query('SELECT default_currency_id FROM supplier WHERE id NOT IN (' . $this->source . ', ' . $this->target . ') ORDER BY id LIMIT 1')->fetchColumn();
            $this->pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$baseCurrency, $id]);
            $this->pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$id]);
        }
    }

    public function testCompleteExportRestoresOtherItemsWithScheduleInstallmentsPaymentsAndDimensions(): void
    {
        $report = $this->exportAndImport();

        foreach (['other_item_kinds' => 1, 'other_items' => 2, 'other_item_installments' => 2, 'other_item_allocations' => 1,
            'other_item_schedules' => 1, 'other_item_schedule_occurrences' => 1, 'other_item_posting_profiles' => 1,
            'other_item_posting_overrides' => 1, 'other_item_posting_lines' => 1, 'other_item_auto_match_blocks' => 1,
            'other_item_settings' => 1] as $table => $expected) {
            self::assertSame($expected, $this->scalar('SELECT COUNT(*) FROM `' . $table . '` WHERE supplier_id = ?', $this->target), $table);
        }
        $posted = $this->scalar("SELECT id FROM other_items WHERE supplier_id = ? AND status = 'posted'", $this->target);
        $draft = $this->scalar("SELECT id FROM other_items WHERE supplier_id = ? AND status = 'draft'", $this->target);
        $kind = $this->scalar('SELECT id FROM other_item_kinds WHERE supplier_id = ?', $this->target);
        $client = $this->scalar('SELECT id FROM clients WHERE supplier_id = ?', $this->target);

        // Zaúčtovaná položka nese převzatý zápis deníku; žádné nové zaúčtování nevzniklo.
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?', $this->target));
        self::assertSame(
            [[(string) $posted, (string) $kind, (string) $client, 'OP-SYN-1', '1000.00']],
            $this->rows("SELECT e.source_id, oi.kind_id, oi.partner_id, oi.document_no, oi.amount FROM other_items oi
                JOIN journal_entries e ON e.id = oi.journal_entry_id AND e.source_type = 'other_item' WHERE oi.supplier_id = ?", $this->target),
        );
        self::assertSame([['378', 'debit', '1000.00'], ['668', 'credit', '1000.00']], $this->rows('SELECT a.account_code, l.side, l.amount
            FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id WHERE l.supplier_id = ? ORDER BY l.line_no', $this->target));
        self::assertNull($this->pdo->query('SELECT journal_entry_id FROM other_items WHERE id = ' . $draft)->fetchColumn() ?: null);

        // Splátky a úhrada z banky míří na převzatou položku, splátku i pohyb.
        self::assertSame([['1', '500.00'], ['2', '500.00']], $this->rows('SELECT position, amount FROM other_item_installments
            WHERE supplier_id = ? AND other_item_id = ' . $posted . ' ORDER BY position', $this->target));
        self::assertSame([['1', '500.00', '-500.00']], $this->rows('SELECT i.position, a.amount, bt.amount FROM other_item_allocations a
            JOIN other_item_installments i ON i.id = a.installment_id AND i.other_item_id = a.other_item_id
            JOIN bank_transactions bt ON bt.id = a.bank_transaction_id JOIN bank_statements bs ON bs.id = bt.statement_id AND bs.supplier_id = a.supplier_id
            WHERE a.supplier_id = ? AND a.other_item_id = ' . $posted, $this->target));

        // Opakování: rozvrh, výskyt i šablona s druhem a partnerem cílové firmy.
        $template = json_decode((string) $this->pdo->query('SELECT template_json FROM other_item_schedules WHERE supplier_id = ' . $this->target)->fetchColumn(), true);
        self::assertSame([$kind, $client, 'Syntetický nájem'], [$template['kind_id'], $template['partner_id'], $template['title']]);
        self::assertSame([[(string) $draft, '0']], $this->rows('SELECT o.item_id, o.occurrence_index FROM other_item_schedule_occurrences o
            JOIN other_item_schedules s ON s.id = o.schedule_id AND s.source_item_id = o.item_id WHERE o.supplier_id = ?', $this->target));

        // Dimenze položky i výchozí dimenze kontace druhu míří na dimenze cílové firmy.
        $value = $this->scalar("SELECT v.id FROM dimension_values v WHERE v.supplier_id = ? AND v.code = 'A'", $this->target);
        $type = $this->scalar("SELECT id FROM dimension_types WHERE supplier_id = ? AND code = 'STR'", $this->target);
        self::assertSame([[(string) $posted, (string) $value]], $this->rows("SELECT doc_id, dimension_value_id FROM document_dimensions
            WHERE supplier_id = ? AND doc_type = 'other_item'", $this->target));
        self::assertArrayNotHasKey('document_dimensions.other_item', $report['dropped']);
        self::assertSame([['type_id' => $type, 'value_id' => $value]], json_decode((string) $this->pdo->query(
            'SELECT dimensions_json FROM other_item_posting_profiles WHERE supplier_id = ' . $this->target)->fetchColumn(), true));
        self::assertSame([['payment_bank', '221', '378']], $this->rows('SELECT event, debit_account_code, credit_account_code
            FROM other_item_posting_overrides WHERE supplier_id = ? AND other_item_id = ' . $posted, $this->target));

        // Vazby z jiných dokladů a nastavení: faktura, číselná řada druhu, blokace párování, složka dokumentů.
        self::assertSame($posted, $this->scalar('SELECT other_item_id FROM invoices WHERE supplier_id = ?', $this->target));
        self::assertSame($kind, $this->scalar("SELECT scope_id FROM number_series WHERE supplier_id = ? AND scope_type = 'other_item_kind'", $this->target));
        self::assertSame($draft, $this->scalar('SELECT other_item_id FROM other_item_auto_match_blocks WHERE supplier_id = ?', $this->target));
        self::assertSame([['Ostatní doklady', (string) $this->actor]], $this->rows('SELECT document_folder, updated_by FROM other_item_settings WHERE supplier_id = ?', $this->target));

        // Opakovaná obnova stejného exportu jen ověří shodu, nic nepřidá ani nezaúčtuje.
        $repeat = $this->import($this->lastExport);
        self::assertSame(2, $repeat['reconciliation']['other_items']);
        self::assertSame(1, $repeat['reconciliation']['other_item_posting_lines']);
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM other_items WHERE supplier_id = ?', $this->target));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?', $this->target));
    }

    /** @return array<string,mixed> */
    private function exportAndImport(): array
    {
        $old = getenv('MYINVOICE_DATA_DIR');
        try {
            putenv('MYINVOICE_DATA_DIR=' . $this->root . '/runtime');
            $export = Bootstrap::buildContainer()->get(InstanceExportService::class)
                ->runForSupplier($this->source, [InstanceExportService::PART_RESTORE], targetDir: $this->root . '/export');
            $this->lastExport = $export['abs_path'];
            return $this->import($this->lastExport);
        } finally {
            putenv($old === false ? 'MYINVOICE_DATA_DIR' : 'MYINVOICE_DATA_DIR=' . $old);
        }
    }

    /** @return array<string,mixed> */
    private function import(string $path): array
    {
        $container = Bootstrap::buildContainer();
        $package = (new MyuctoExportReader())->read($path, (string) $container->get(Config::class)->get('cron.backup.password', ''));
        return $container->get(MyuctoImporter::class)->import($package, $this->target, $this->actor, 'synthetic-other-items', false);
    }

    private function seedSource(): void
    {
        $insert = function (string $table, array $row): int {
            $this->pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`, `', array_keys($row)) . '`) VALUES ('
                . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
            return (int) $this->pdo->lastInsertId();
        };
        $s = $this->source;
        $currency = (int) $this->pdo->query('SELECT default_currency_id FROM supplier WHERE id = ' . $s)->fetchColumn();
        $country = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $vat = (int) $this->pdo->query("SELECT id FROM vat_rates WHERE rate_percent = 21 AND country = 'CZ' AND is_reverse_charge = 0 LIMIT 1")->fetchColumn();

        $type = $insert('dimension_types', ['supplier_id' => $s, 'code' => 'STR', 'name' => 'Středisko', 'kind' => 'cost_center']);
        $value = $insert('dimension_values', ['type_id' => $type, 'supplier_id' => $s, 'code' => 'A', 'name' => 'Středisko A']);
        $client = $insert('clients', ['supplier_id' => $s, 'company_name' => 'Syntetický nájemce', 'street' => 'Test 1', 'city' => 'Test',
            'zip' => '11000', 'country_id' => $country, 'main_email' => 'najemce@example.test', 'currency_default_id' => $currency]);
        $period = $insert('accounting_periods', ['supplier_id' => $s, 'fiscal_year' => 2090, 'starts_on' => '2090-01-01', 'ends_on' => '2090-12-31']);
        $accounts = [];
        foreach ([['378', 'asset'], ['668', 'revenue'], ['221', 'asset']] as [$code, $kind]) {
            $accounts[$code] = $insert('chart_of_accounts', ['supplier_id' => $s, 'account_code' => $code, 'name' => 'Syntetický účet', 'account_type' => $kind]);
        }

        $kind = $insert('other_item_kinds', ['supplier_id' => $s, 'side' => 'receivable', 'name' => 'Syntetický nájem', 'own_series' => 1, 'created_by' => $this->actor]);
        $insert('number_series', ['supplier_id' => $s, 'doc_kind' => 'other_receivable', 'scope_type' => 'other_item_kind', 'scope_id' => $kind,
            'storage' => 'legacy', 'legacy_key' => 'doc:other_receivable:' . $kind, 'name' => 'Syntetický nájem']);
        $insert('accounting_document_series', ['supplier_id' => $s, 'series_code' => 'other_receivable', 'register_id' => $kind, 'fiscal_year' => 2090, 'prefix' => 'OP', 'next_number' => 2]);
        $insert('other_item_posting_profiles', ['supplier_id' => $s, 'kind_id' => $kind, 'event' => 'origin', 'debit_account_code' => '378',
            'credit_account_code' => '668', 'dimensions_json' => json_encode([['type_id' => $type, 'value_id' => $value]], JSON_THROW_ON_ERROR), 'created_by' => $this->actor]);

        $item = ['supplier_id' => $s, 'side' => 'receivable', 'kind' => 'other', 'kind_id' => $kind, 'partner_id' => $client, 'partner_name' => 'Syntetický nájemce',
            'title' => 'Syntetický nájem', 'amount' => '1000.00', 'currency' => 'CZK', 'issued_on' => '2090-01-05', 'due_on' => '2090-02-05',
            'account_code' => '378', 'counter_account_code' => '668', 'created_by' => $this->actor];
        $posted = $insert('other_items', [...$item, 'document_no' => 'OP-SYN-1', 'accounting_on' => '2090-01-05', 'status' => 'posted', 'posted_at' => '2090-01-05 10:00:00']);
        $entry = $insert('journal_entries', ['supplier_id' => $s, 'period_id' => $period, 'entry_date' => '2090-01-05', 'description' => 'Syntetický nájem',
            'source_type' => 'other_item', 'source_id' => $posted, 'posted_at' => '2090-01-05 10:00:00']);
        $insert('journal_entry_lines', ['supplier_id' => $s, 'entry_id' => $entry, 'account_id' => $accounts['378'], 'line_no' => 1, 'side' => 'debit', 'amount' => '1000.00']);
        $insert('journal_entry_lines', ['supplier_id' => $s, 'entry_id' => $entry, 'account_id' => $accounts['668'], 'line_no' => 2, 'side' => 'credit', 'amount' => '1000.00']);
        $this->pdo->prepare('UPDATE other_items SET journal_entry_id = ? WHERE id = ?')->execute([$entry, $posted]);
        $insert('other_item_posting_lines', ['supplier_id' => $s, 'other_item_id' => $posted, 'position' => 1, 'account_code' => '668', 'amount' => '1000.00']);
        $insert('other_item_posting_overrides', ['supplier_id' => $s, 'other_item_id' => $posted, 'event' => 'payment_bank',
            'debit_account_code' => '221', 'credit_account_code' => '378', 'created_by' => $this->actor]);
        $first = $insert('other_item_installments', ['supplier_id' => $s, 'other_item_id' => $posted, 'position' => 1, 'due_on' => '2090-01-20', 'amount' => '500.00']);
        $insert('other_item_installments', ['supplier_id' => $s, 'other_item_id' => $posted, 'position' => 2, 'due_on' => '2090-02-05', 'amount' => '500.00']);
        $insert('document_dimensions', ['supplier_id' => $s, 'doc_type' => 'other_item', 'doc_id' => $posted, 'item_no' => 0, 'dimension_type_id' => $type, 'dimension_value_id' => $value]);

        $statement = $insert('bank_statements', ['supplier_id' => $s, 'file_name' => 'synthetic.gpc', 'file_hash' => hash('sha256', 'synthetic-other-items-' . $s),
            'account_number' => '1000000005', 'bank_code' => '0100', 'currency' => 'CZK', 'statement_date' => '2090-01-20', 'imported_by' => $this->actor]);
        $paid = $insert('bank_transactions', ['statement_id' => $statement, 'source' => 'statement', 'posted_at' => '2090-01-20', 'amount' => '-500.00',
            'currency' => 'CZK', 'description' => 'Syntetická úhrada']);
        $blocked = $insert('bank_transactions', ['statement_id' => $statement, 'source' => 'statement', 'posted_at' => '2090-01-21', 'amount' => '-700.00',
            'currency' => 'CZK', 'description' => 'Syntetický nespárovaný pohyb']);
        $insert('other_item_allocations', ['supplier_id' => $s, 'other_item_id' => $posted, 'installment_id' => $first, 'bank_transaction_id' => $paid,
            'amount' => '500.00', 'payment_on' => '2090-01-20', 'created_by' => $this->actor]);

        $draft = $insert('other_items', [...$item, 'status' => 'draft', 'issued_on' => '2090-02-05', 'due_on' => '2090-03-05']);
        $template = ['side' => 'receivable', 'kind' => 'other', 'kind_id' => $kind, 'title' => 'Syntetický nájem', 'partner_id' => $client,
            'partner_name' => 'Syntetický nájemce', 'currency' => 'CZK', 'amount' => '1000.00', 'account_code' => '378', 'counter_account_code' => '668'];
        $schedule = $insert('other_item_schedules', ['supplier_id' => $s, 'source_item_id' => $draft, 'frequency' => 'monthly', 'anchor_on' => '2090-02-05',
            'due_days' => 28, 'template_json' => json_encode($template, JSON_THROW_ON_ERROR), 'created_by' => $this->actor]);
        $insert('other_item_schedule_occurrences', ['supplier_id' => $s, 'schedule_id' => $schedule, 'occurrence_index' => 0, 'item_id' => $draft]);
        $insert('other_item_auto_match_blocks', ['supplier_id' => $s, 'other_item_id' => $draft, 'bank_transaction_id' => $blocked]);
        $insert('other_item_settings', ['supplier_id' => $s, 'document_folder' => 'Ostatní doklady', 'updated_by' => $this->actor]);

        $invoice = $insert('invoices', ['supplier_id' => $s, 'client_id' => $client, 'currency_id' => $currency, 'invoice_type' => 'invoice',
            'varsymbol' => 'SYNTHETIC-OI-01', 'issue_date' => '2090-01-05', 'tax_date' => '2090-01-05', 'due_date' => '2090-01-20',
            'created_by' => $this->actor, 'prices_include_vat' => 1, 'total_without_vat' => 100, 'total_vat' => 21, 'total_with_vat' => 121,
            'other_item_id' => $posted, 'supplier_snapshot' => json_encode(['id' => $s, 'company_name' => 'Syntetické ostatní položky'], JSON_THROW_ON_ERROR)]);
        $insert('invoice_items', ['invoice_id' => $invoice, 'description' => 'Syntetická služba', 'unit_price_without_vat' => 121, 'vat_rate_id' => $vat,
            'vat_rate_snapshot' => 21, 'total_without_vat' => 100, 'total_vat' => 21, 'total_with_vat' => 121]);
    }

    private function scalar(string $sql, int $param): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$param]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<list<string>> */
    private function rows(string $sql, int $param): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$param]);
        return array_map(static fn (array $row): array => array_map('strval', $row), $stmt->fetchAll(PDO::FETCH_NUM));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
