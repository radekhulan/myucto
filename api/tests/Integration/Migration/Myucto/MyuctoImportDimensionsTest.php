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
use RuntimeException;

/**
 * Kompletní export dat → obnova z exportu MyÚčta: přiřazení dimenzí řádkům deníku
 * i dokladům, jejich rozpady na podíly, nastavení dimenzí firmy a sdílené dimenze
 * skupiny firem. Celá cesta jako v UI: skutečný export, čtečka ZIPu, import.
 */
#[Group('integration')]
final class MyuctoImportDimensionsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private PDO $pdo;
    private int $source;
    private int $target;
    private int $actor;
    private int $group;
    private ?int $otherGroup = null;
    private string $root;
    /** @var array<string,int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->pdo = Bootstrap::buildContainer()->get(Connection::class)->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $base = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->actor = (int) $this->pdo->query('SELECT id FROM users WHERE is_active = 1 LIMIT 1')->fetchColumn();
        $this->pdo->exec("INSERT INTO supplier_groups (name) VALUES ('Syntetická skupina dimenzí')");
        $this->group = (int) $this->pdo->lastInsertId();
        $this->source = $this->createIsolatedSupplier($this->pdo, $base);
        $this->target = $this->createIsolatedSupplier($this->pdo, $base);
        foreach ([$this->source, $this->target] as $id) {
            $this->pdo->prepare("UPDATE supplier SET ic = '00000000', accounting_mode = 'double_entry', company_name = 'Syntetické dimenze', supplier_group_id = ? WHERE id = ?")
                ->execute([$this->group, $id]);
            $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_default) VALUES (?, 'CZK', 'Syntetická měna', 'Kč', 'Syntetická měna', 'Synthetic currency', 1)")->execute([$id]);
            $this->pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $this->pdo->lastInsertId(), $id]);
        }
        $this->root = sys_get_temp_dir() . '/myucto-dim-import-' . bin2hex(random_bytes(6));
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
            foreach (['journal_entry_line_dimension_splits', 'journal_entry_line_dimensions', 'document_dimension_splits', 'document_dimensions',
                'dimension_defaults', 'dimension_account_map', 'dimension_account_rules'] as $table) {
                $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE supplier_id = ?')->execute([$id]);
            }
            $this->pdo->prepare('UPDATE dimension_values SET parent_id = NULL WHERE supplier_id = ?')->execute([$id]);
            foreach (['dimension_values', 'dimension_types', 'journal_entry_lines', 'journal_entries', 'accounting_periods',
                'journal_document_postings', 'external_entity_map'] as $table) {
                $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE supplier_id = ?')->execute([$id]);
            }
            $this->pdo->prepare('DELETE ii FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE i.supplier_id = ?')->execute([$id]);
            foreach (['invoices', 'chart_of_accounts', 'clients', 'supplier_vat_status_history', 'supplier_accounting_frameworks',
                'invoice_counters', 'number_series', 'accounting_document_series', 'supplier_bank_accounts', 'bank_rule_templates',
                'auto_posting_policy', 'pdf_signature_output_settings', 'journal_entry_templates'] as $table) {
                $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE supplier_id = ?')->execute([$id]);
            }
            $baseCurrency = (int) $this->pdo->query('SELECT default_currency_id FROM supplier WHERE id NOT IN (' . $this->source . ', ' . $this->target . ') ORDER BY id LIMIT 1')->fetchColumn();
            $this->pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$baseCurrency, $id]);
            $this->pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$id]);
        }
        foreach (array_filter([$this->group, $this->otherGroup]) as $group) {
            $this->pdo->prepare('DELETE FROM dimension_values WHERE supplier_group_id = ?')->execute([$group]);
            $this->pdo->prepare('DELETE FROM dimension_types WHERE supplier_group_id = ?')->execute([$group]);
            $this->pdo->prepare('DELETE FROM supplier_groups WHERE id = ?')->execute([$group]);
        }
    }

    public function testCompleteExportRestoresDimensionAssignmentsSplitsSettingsAndSharedGroupValues(): void
    {
        $report = $this->exportAndImport();

        // Sdílený typ a hodnota skupiny se nepřenesly jako vlastní, ale ponechaly původní ID.
        self::assertSame(['dimension_types' => 1, 'dimension_values' => 1], $report['shared_dimensions']);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM dimension_types WHERE supplier_group_id = ?', $this->group));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM dimension_values WHERE supplier_group_id = ?', $this->group));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM dimension_types WHERE supplier_id = ?', $this->target));

        self::assertSame(
            [['debit', 'STR', 'A'], ['credit', 'GPRJ', 'G1']],
            $this->rows('SELECT l.side, t.code, v.code FROM journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id = d.line_id
                JOIN dimension_types t ON t.id = d.dimension_type_id JOIN dimension_values v ON v.id = d.dimension_value_id
                WHERE d.supplier_id = ? ORDER BY l.line_no', $this->target),
        );
        self::assertSame($this->ids['group_value'], $this->scalar('SELECT dimension_value_id FROM journal_entry_line_dimensions d
            JOIN dimension_types t ON t.id = d.dimension_type_id WHERE d.supplier_id = ? AND t.supplier_id IS NULL', $this->target));
        self::assertSame(
            [['A', '0.4000000000'], ['B', '0.6000000000']],
            $this->rows('SELECT v.code, s.share FROM journal_entry_line_dimension_splits s JOIN dimension_values v ON v.id = s.dimension_value_id
                JOIN journal_entry_lines l ON l.id = s.line_id WHERE s.supplier_id = ? ORDER BY v.code', $this->target),
        );
        $invoice = $this->scalar("SELECT id FROM invoices WHERE supplier_id = ?", $this->target);
        self::assertSame(
            [['invoice', 'GPRJ', 'G1'], ['invoice', 'STR', 'A']],
            $this->rows('SELECT d.doc_type, t.code, v.code FROM document_dimensions d JOIN dimension_types t ON t.id = d.dimension_type_id
                JOIN dimension_values v ON v.id = d.dimension_value_id WHERE d.supplier_id = ? AND d.doc_id = ' . $invoice . ' ORDER BY t.code', $this->target),
        );
        self::assertSame(
            [['A', '0.5000000000'], ['B', '0.5000000000']],
            $this->rows("SELECT v.code, s.share FROM document_dimension_splits s JOIN dimension_values v ON v.id = s.dimension_value_id
                WHERE s.supplier_id = ? AND s.doc_type = 'invoice' AND s.doc_id = " . $invoice . ' ORDER BY v.code', $this->target),
        );
        // Dimenze ostatní položky, kterou export nenese (smazaná), se vynechají a vykážou, nic nezmizí potichu.
        self::assertSame(1, $report['dropped']['document_dimensions.other_item'] ?? null);
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM document_dimensions WHERE supplier_id = ? AND doc_type = 'other_item'", $this->target));

        self::assertSame([['5', 'A']], $this->rows('SELECT r.account_mask, v.code FROM dimension_account_rules r
            JOIN dimension_values v ON v.id = r.default_value_id WHERE r.supplier_id = ?', $this->target));
        self::assertSame([['518', '518100', 'A']], $this->rows('SELECT s.account_code, a.account_code, v.code FROM dimension_account_map m
            JOIN chart_of_accounts s ON s.id = m.synthetic_account_id JOIN chart_of_accounts a ON a.id = m.analytic_account_id
            JOIN dimension_values v ON v.id = m.dimension_value_id WHERE m.supplier_id = ?', $this->target));
        self::assertSame([['Syntetický odběratel', 'B']], $this->rows('SELECT c.company_name, v.code FROM dimension_defaults d
            JOIN clients c ON c.id = d.client_id JOIN dimension_values v ON v.id = d.dimension_value_id WHERE d.supplier_id = ?', $this->target));

        // Opakovaná obnova stejného exportu jen ověří shodu, nic nepřidá.
        $repeat = $this->import($this->lastExport);
        self::assertSame(2, $repeat['reconciliation']['journal_entry_line_dimensions']);
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM journal_entry_line_dimensions WHERE supplier_id = ?', $this->target));
    }

    public function testCompanyWithoutGroupDimensionsRestoresSplitsAndDimensionSettings(): void
    {
        foreach (['journal_entry_line_dimensions', 'document_dimensions'] as $table) {
            $this->pdo->prepare('DELETE d FROM `' . $table . '` d JOIN dimension_types t ON t.id = d.dimension_type_id
                WHERE d.supplier_id = ? AND t.supplier_id IS NULL')->execute([$this->source]);
        }

        $report = $this->exportAndImport();

        self::assertArrayNotHasKey('shared_dimensions', $report);
        foreach (['journal_entry_line_dimension_splits' => 2, 'document_dimension_splits' => 2, 'dimension_account_rules' => 1,
            'dimension_account_map' => 1, 'dimension_defaults' => 1, 'journal_entry_line_dimensions' => 1] as $table => $expected) {
            self::assertSame($expected, $this->scalar('SELECT COUNT(*) FROM `' . $table . '` WHERE supplier_id = ?', $this->target), $table);
        }
    }

    public function testSharedGroupValuesAreMatchedByCodeInTargetGroup(): void
    {
        $this->pdo->exec("INSERT INTO supplier_groups (name) VALUES ('Syntetická cílová skupina')");
        $this->otherGroup = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO dimension_types (supplier_group_id, code, name, kind) VALUES (?, 'gprj', 'Zakázky skupiny', 'project')")->execute([$this->otherGroup]);
        $type = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO dimension_values (type_id, supplier_group_id, code, name) VALUES (?, ?, 'G1', 'Zakázka 1')")->execute([$type, $this->otherGroup]);
        $value = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE supplier SET supplier_group_id = ? WHERE id = ?')->execute([$this->otherGroup, $this->target]);

        $this->exportAndImport();

        self::assertSame([[(string) $type, (string) $value]], $this->rows('SELECT d.dimension_type_id, d.dimension_value_id FROM journal_entry_line_dimensions d
            JOIN journal_entry_lines l ON l.id = d.line_id WHERE d.supplier_id = ? AND l.side = \'credit\'', $this->target));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM dimension_types WHERE supplier_id = ?', $this->target));
    }

    public function testTargetWithoutGroupRejectsRestoreBeforeAnyWrite(): void
    {
        $this->pdo->prepare('UPDATE supplier SET supplier_group_id = NULL WHERE id = ?')->execute([$this->target]);
        try {
            $this->exportAndImport();
            self::fail('Sdílené dimenze bez skupiny cílové firmy nesmí projít.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('nepatří', $e->getMessage());
            self::assertStringContainsString('„G1"', $e->getMessage());
        }
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?', $this->target));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM dimension_types WHERE supplier_id = ?', $this->target));
    }

    private string $lastExport = '';

    /**
     * Firma nad dřívějšími limity čtečky (100 000 řádků / 64 MiB dat): 120 000 přiřazení
     * dimenzí dokladu. Obnova projde celou cestou proudem a paměť nedrží řádky, jen indexy.
     */
    public function testCompanyAboveFormerReaderLimitsRestoresByStreaming(): void
    {
        $invoice = $this->scalar("SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = 'SYNTHETIC-DIM-01'", $this->source);
        $this->pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind) VALUES (?, 'STR2', 'Středisko 2', 'cost_center')")->execute([$this->source]);
        $type = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, 'C', 'Středisko C')")->execute([$type, $this->source]);
        $value = (int) $this->pdo->lastInsertId();
        $first = $this->scalar("SELECT id FROM dimension_types WHERE supplier_id = ? AND code = 'STR'", $this->source);
        $firstValue = $this->scalar("SELECT v.id FROM dimension_values v JOIN dimension_types t ON t.id = v.type_id WHERE v.supplier_id = ? AND t.code = 'STR' AND v.code = 'B'", $this->source);
        $this->pdo->prepare("INSERT INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id)
            SELECT ?, 'invoice', ?, s.seq, t.type_id, t.value_id FROM seq_1_to_60000 s
            CROSS JOIN (SELECT ? AS type_id, ? AS value_id UNION ALL SELECT ?, ?) t")
            ->execute([$this->source, $invoice, $first, $firstValue, $type, $value]);
        $rows = $this->scalar("SELECT COUNT(*) FROM document_dimensions WHERE supplier_id = ? AND doc_type = 'invoice'", $this->source);
        self::assertGreaterThan(100_000, $rows);

        $path = $this->export();
        gc_collect_cycles();
        $base = memory_get_usage();
        memory_reset_peak_usage();
        $report = $this->import($path);
        $peak = memory_get_peak_usage() - $base;

        self::assertSame($rows, $report['created']['document_dimensions']);
        self::assertSame($rows, $report['reconciliation']['document_dimensions']);
        self::assertSame(['document_dimensions.other_item' => 1], array_filter($report['dropped'], static fn (string $k): bool => str_starts_with($k, 'document_dimensions'), ARRAY_FILTER_USE_KEY));
        self::assertSame($rows, $this->scalar("SELECT COUNT(*) FROM document_dimensions WHERE supplier_id = ? AND doc_type = 'invoice'", $this->target));
        self::assertSame(
            $this->rows("SELECT d.item_no, t.code, v.code FROM document_dimensions d JOIN dimension_types t ON t.id = d.dimension_type_id
                JOIN dimension_values v ON v.id = d.dimension_value_id WHERE d.supplier_id = ? AND d.item_no IN (1, 30000, 60000) ORDER BY 1, 2", $this->source),
            $this->rows("SELECT d.item_no, t.code, v.code FROM document_dimensions d JOIN dimension_types t ON t.id = d.dimension_type_id
                JOIN dimension_values v ON v.id = d.dimension_value_id WHERE d.supplier_id = ? AND d.item_no IN (1, 30000, 60000) ORDER BY 1, 2", $this->target),
        );
        // Řádky v polích (export, přemapované a cílové) by zabraly přes 250 MiB; proudem
        // zůstanou jen indexy a klíče (kolem 50 MiB včetně metadat schématu).
        self::assertLessThan(96 * 1024 * 1024, $peak, 'Paměť obnovy roste s počtem řádků: ' . round($peak / 1048576) . ' MiB.');
    }

    /** @return array<string,mixed> */
    private function exportAndImport(): array
    {
        $this->lastExport = $this->export();
        return $this->import($this->lastExport);
    }

    private function export(): string
    {
        $old = getenv('MYINVOICE_DATA_DIR');
        try {
            putenv('MYINVOICE_DATA_DIR=' . $this->root . '/runtime');
            $container = Bootstrap::buildContainer();
            $export = $container->get(InstanceExportService::class)
                ->runForSupplier($this->source, [InstanceExportService::PART_RESTORE], targetDir: $this->root . '/export');
            return $export['abs_path'];
        } finally {
            putenv($old === false ? 'MYINVOICE_DATA_DIR' : 'MYINVOICE_DATA_DIR=' . $old);
        }
    }

    /** @return array<string,mixed> */
    private function import(string $path): array
    {
        $container = Bootstrap::buildContainer();
        $package = (new MyuctoExportReader())->read($path, (string) $container->get(Config::class)->get('cron.backup.password', ''));
        return $container->get(MyuctoImporter::class)->import($package, $this->target, $this->actor, 'synthetic-dimensions', false);
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

        $groupType = $insert('dimension_types', ['supplier_group_id' => $this->group, 'code' => 'GPRJ', 'name' => 'Zakázky skupiny', 'kind' => 'project']);
        $this->ids['group_value'] = $insert('dimension_values', ['type_id' => $groupType, 'supplier_group_id' => $this->group, 'code' => 'G1', 'name' => 'Zakázka 1']);
        $type = $insert('dimension_types', ['supplier_id' => $s, 'code' => 'STR', 'name' => 'Středisko', 'kind' => 'cost_center']);
        $a = $insert('dimension_values', ['type_id' => $type, 'supplier_id' => $s, 'code' => 'A', 'name' => 'Středisko A']);
        $b = $insert('dimension_values', ['type_id' => $type, 'supplier_id' => $s, 'code' => 'B', 'name' => 'Středisko B']);

        $client = $insert('clients', ['supplier_id' => $s, 'company_name' => 'Syntetický odběratel', 'street' => 'Test 1', 'city' => 'Test',
            'zip' => '11000', 'country_id' => $country, 'main_email' => 'odberatel@example.test', 'currency_default_id' => $currency]);
        $invoice = $insert('invoices', ['supplier_id' => $s, 'client_id' => $client, 'currency_id' => $currency, 'invoice_type' => 'invoice',
            'varsymbol' => 'SYNTHETIC-DIM-01', 'issue_date' => '2090-01-01', 'tax_date' => '2090-01-01', 'due_date' => '2090-01-15',
            'created_by' => $this->actor, 'prices_include_vat' => 1, 'total_without_vat' => 100, 'total_vat' => 21, 'total_with_vat' => 121,
            'supplier_snapshot' => json_encode(['id' => $s, 'company_name' => 'Syntetické dimenze'], JSON_THROW_ON_ERROR)]);
        $insert('invoice_items', ['invoice_id' => $invoice, 'description' => 'Syntetická služba', 'unit_price_without_vat' => 121, 'vat_rate_id' => $vat,
            'vat_rate_snapshot' => 21, 'total_without_vat' => 100, 'total_vat' => 21, 'total_with_vat' => 121]);

        $period = $insert('accounting_periods', ['supplier_id' => $s, 'fiscal_year' => 2090, 'starts_on' => '2090-01-01', 'ends_on' => '2090-12-31']);
        $accounts = [];
        foreach ([['518', 'expense'], ['518100', 'expense'], ['321', 'liability']] as [$code, $kind]) {
            $accounts[$code] = $insert('chart_of_accounts', ['supplier_id' => $s, 'account_code' => $code, 'name' => 'Syntetický účet', 'account_type' => $kind]);
        }
        $entry = $insert('journal_entries', ['supplier_id' => $s, 'period_id' => $period, 'entry_date' => '2090-01-10',
            'description' => 'Syntetický zápis', 'source_type' => 'manual', 'posted_at' => '2090-01-10 00:00:00']);
        $debit = $insert('journal_entry_lines', ['supplier_id' => $s, 'entry_id' => $entry, 'account_id' => $accounts['518'], 'line_no' => 1, 'side' => 'debit', 'amount' => '100.00']);
        $credit = $insert('journal_entry_lines', ['supplier_id' => $s, 'entry_id' => $entry, 'account_id' => $accounts['321'], 'line_no' => 2, 'side' => 'credit', 'amount' => '100.00']);

        $insert('journal_entry_line_dimensions', ['line_id' => $debit, 'dimension_type_id' => $type, 'supplier_id' => $s, 'dimension_value_id' => $a]);
        $insert('journal_entry_line_dimensions', ['line_id' => $credit, 'dimension_type_id' => $groupType, 'supplier_id' => $s, 'dimension_value_id' => $this->ids['group_value']]);
        foreach ([[$a, '0.4'], [$b, '0.6']] as [$value, $share]) {
            $insert('journal_entry_line_dimension_splits', ['line_id' => $debit, 'dimension_type_id' => $type, 'dimension_value_id' => $value, 'supplier_id' => $s, 'share' => $share]);
            $insert('document_dimension_splits', ['supplier_id' => $s, 'doc_type' => 'invoice', 'doc_id' => $invoice, 'item_no' => 0,
                'dimension_type_id' => $type, 'dimension_value_id' => $value, 'share' => '0.5']);
        }
        $insert('document_dimensions', ['supplier_id' => $s, 'doc_type' => 'invoice', 'doc_id' => $invoice, 'item_no' => 0, 'dimension_type_id' => $type, 'dimension_value_id' => $a]);
        $insert('document_dimensions', ['supplier_id' => $s, 'doc_type' => 'invoice', 'doc_id' => $invoice, 'item_no' => 0, 'dimension_type_id' => $groupType, 'dimension_value_id' => $this->ids['group_value']]);
        $insert('document_dimensions', ['supplier_id' => $s, 'doc_type' => 'other_item', 'doc_id' => 987654, 'item_no' => 0, 'dimension_type_id' => $type, 'dimension_value_id' => $b]);

        $insert('dimension_account_rules', ['supplier_id' => $s, 'dimension_type_id' => $type, 'account_mask' => '5', 'default_value_id' => $a, 'created_by' => $this->actor]);
        $insert('dimension_account_map', ['supplier_id' => $s, 'dimension_type_id' => $type, 'dimension_value_id' => $a,
            'synthetic_account_id' => $accounts['518'], 'analytic_account_id' => $accounts['518100'], 'created_by' => $this->actor]);
        $insert('dimension_defaults', ['supplier_id' => $s, 'client_id' => $client, 'dimension_type_id' => $type, 'dimension_value_id' => $b]);
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
