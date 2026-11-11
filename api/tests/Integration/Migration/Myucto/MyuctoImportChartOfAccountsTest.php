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
 * Kompletní export dat → obnova z exportu MyÚčta do firmy, která má vlastní (výchozí)
 * účtovou osnovu. Prázdná cílová firma převezme význam účtů zdroje, firma s převzatými
 * daty rozdíl odmítne s výpisem účtů.
 */
#[Group('integration')]
final class MyuctoImportChartOfAccountsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private PDO $pdo;
    private int $source;
    private int $target;
    private int $actor;
    private string $root;
    private string $lastExport = '';

    protected function setUp(): void
    {
        $this->pdo = Bootstrap::buildContainer()->get(Connection::class)->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $base = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->actor = (int) $this->pdo->query('SELECT id FROM users WHERE is_active = 1 LIMIT 1')->fetchColumn();
        $this->source = $this->createIsolatedSupplier($this->pdo, $base);
        $this->target = $this->createIsolatedSupplier($this->pdo, $base);
        foreach ([$this->source, $this->target] as $id) {
            $this->pdo->prepare("UPDATE supplier SET ic = '00000000', accounting_mode = 'double_entry', company_name = 'Syntetická osnova', supplier_group_id = NULL WHERE id = ?")
                ->execute([$id]);
            $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_default) VALUES (?, 'CZK', 'Syntetická měna', 'Kč', 'Syntetická měna', 'Synthetic currency', 1)")->execute([$id]);
            $this->pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $this->pdo->lastInsertId(), $id]);
        }
        $this->root = sys_get_temp_dir() . '/myucto-coa-import-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $this->seed();
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
            foreach (['journal_entry_lines', 'journal_entries', 'accounting_periods', 'chart_of_accounts', 'external_entity_map', 'supplier_vat_status_history',
                'supplier_accounting_frameworks', 'supplier_bank_accounts', 'bank_rule_templates', 'auto_posting_policy', 'pdf_signature_output_settings',
                'journal_entry_templates', 'number_series', 'accounting_document_series'] as $table) {
                $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE supplier_id = ?')->execute([$id]);
            }
            $baseCurrency = (int) $this->pdo->query('SELECT default_currency_id FROM supplier WHERE id NOT IN (' . $this->source . ', ' . $this->target . ') ORDER BY id LIMIT 1')->fetchColumn();
            $this->pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$baseCurrency, $id]);
            $this->pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$id]);
        }
    }

    public function testEmptyTargetAdoptsSourceChartSemantics(): void
    {
        $targetAccount = $this->account($this->target, '518');

        $report = $this->exportAndImport();

        self::assertSame(['518: name, tax_deductibility', '518100: is_active'], $report['adopted_chart']);
        self::assertSame([['518', 'Syntetické neuznatelné služby', 'non_deductible', '1'], ['518100', 'Syntetická analytika', 'deductible', '0'],
            ['521', 'Syntetické mzdy', 'deductible', '1']], $this->rows('SELECT account_code, name, tax_deductibility, is_active
            FROM chart_of_accounts WHERE supplier_id = ? ORDER BY account_code', $this->target));
        // Spárovaný účet zůstal tentýž řádek, deník na něj účtuje; druhý účet osnovy nevznikl.
        self::assertSame($targetAccount, $this->account($this->target, '518'));
        self::assertSame([['518', 'debit', '100.00'], ['521', 'credit', '100.00']], $this->rows('SELECT a.account_code, l.side, l.amount
            FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id WHERE l.supplier_id = ? ORDER BY l.line_no', $this->target));

        // Opakovaná obnova stejných dat osnovu už nemění, jen ověří shodu.
        self::assertSame([], $this->import($this->lastExport)['adopted_chart']);
    }

    public function testTargetWithRestoredDataRejectsChartConflictWithAccountList(): void
    {
        $this->exportAndImport();
        $this->pdo->prepare("UPDATE chart_of_accounts SET tax_deductibility = 'deductible', account_type = 'revenue' WHERE supplier_id = ? AND account_code = '518'")
            ->execute([$this->target]);

        try {
            $this->import($this->lastExport);
            self::fail('Rozdílný význam účtu ve firmě s převzatými daty nesmí projít.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('u 1 účtů jiný význam', $e->getMessage());
            self::assertStringContainsString('518 (account_type: export expense, cílová firma revenue, tax_deductibility: export non_deductible, cílová firma deductible)', $e->getMessage());
        }
        self::assertSame([['revenue', 'deductible']], $this->rows("SELECT account_type, tax_deductibility FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '518'", $this->target));
        self::assertSame(1, (int) $this->rows('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?', $this->target)[0][0]);
    }

    public function testTargetWithOwnJournalKeepsChartAndRejectsRestore(): void
    {
        $period = $this->insert('accounting_periods', ['supplier_id' => $this->target, 'fiscal_year' => 2091, 'starts_on' => '2091-01-01', 'ends_on' => '2091-12-31']);
        $entry = $this->insert('journal_entries', ['supplier_id' => $this->target, 'period_id' => $period, 'entry_date' => '2091-01-10',
            'description' => 'Vlastní zápis cílové firmy', 'source_type' => 'manual', 'posted_at' => '2091-01-10 00:00:00']);
        $this->insert('journal_entry_lines', ['supplier_id' => $this->target, 'entry_id' => $entry, 'account_id' => $this->account($this->target, '518'),
            'line_no' => 1, 'side' => 'debit', 'amount' => '10.00']);

        try {
            $this->exportAndImport();
            self::fail('Firma s vlastním deníkem není prázdná.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Cílová firma už obsahuje vlastní data', $e->getMessage());
        }
        self::assertSame([['deductible', 'Výchozí služby']], $this->rows("SELECT tax_deductibility, name FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '518'", $this->target));
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
        return $container->get(MyuctoImporter::class)->import($package, $this->target, $this->actor, 'synthetic-chart', false);
    }

    private function seed(): void
    {
        $s = $this->source;
        // Výchozí osnova nové firmy: stejné kódy, jiný význam a název.
        $this->insert('chart_of_accounts', ['supplier_id' => $this->target, 'account_code' => '518', 'name' => 'Výchozí služby', 'account_type' => 'expense']);
        $this->insert('chart_of_accounts', ['supplier_id' => $this->target, 'account_code' => '518100', 'name' => 'Syntetická analytika', 'account_type' => 'expense', 'is_synthetic' => 0]);

        $period = $this->insert('accounting_periods', ['supplier_id' => $s, 'fiscal_year' => 2090, 'starts_on' => '2090-01-01', 'ends_on' => '2090-12-31']);
        $expense = $this->insert('chart_of_accounts', ['supplier_id' => $s, 'account_code' => '518', 'name' => 'Syntetické neuznatelné služby',
            'account_type' => 'expense', 'tax_deductibility' => 'non_deductible']);
        $this->insert('chart_of_accounts', ['supplier_id' => $s, 'account_code' => '518100', 'name' => 'Syntetická analytika', 'account_type' => 'expense',
            'is_synthetic' => 0, 'is_active' => 0]);
        $wages = $this->insert('chart_of_accounts', ['supplier_id' => $s, 'account_code' => '521', 'name' => 'Syntetické mzdy', 'account_type' => 'expense']);
        $entry = $this->insert('journal_entries', ['supplier_id' => $s, 'period_id' => $period, 'entry_date' => '2090-01-10',
            'description' => 'Syntetický zápis', 'source_type' => 'manual', 'posted_at' => '2090-01-10 00:00:00']);
        $this->insert('journal_entry_lines', ['supplier_id' => $s, 'entry_id' => $entry, 'account_id' => $expense, 'line_no' => 1, 'side' => 'debit', 'amount' => '100.00']);
        $this->insert('journal_entry_lines', ['supplier_id' => $s, 'entry_id' => $entry, 'account_id' => $wages, 'line_no' => 2, 'side' => 'credit', 'amount' => '100.00']);
    }

    private function insert(string $table, array $row): int
    {
        $this->pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`, `', array_keys($row)) . '`) VALUES ('
            . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        return (int) $this->pdo->lastInsertId();
    }

    private function account(int $supplier, string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$supplier, $code]);
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
