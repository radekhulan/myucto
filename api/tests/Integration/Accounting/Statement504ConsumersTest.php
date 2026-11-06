<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use PHPUnit\Framework\TestCase;

final class Statement504ConsumersTest extends TestCase
{
    private \PDO $pdo;
    private FinancialStatementService $statements;
    private int $supplier;
    private int $period;
    private array $activity;
    private array $accounts = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $this->pdo = $db->pdo();
        $this->statements = $container->get(FinancialStatementService::class);
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO supplier (company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id)
            SELECT 'Synthetic 504 statements','Test 1','Test','10000','statement504@example.com',
                (SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1),
                (SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1),
                (SELECT id FROM vat_rates ORDER BY id LIMIT 1)");
        $this->supplier = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplier]);
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2025, '2024-07-01', '2025-06-30');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?, '2024-07-01', 'cz_504')")->execute([$this->supplier]);
        $catalog = (new SystemDimensionCatalog($db))->ensureForFramework($this->supplier, '2024-07-01');
        $this->activity = $catalog['activity'];
        foreach (['221' => 'asset', '022' => 'asset', '082' => 'asset', '315' => 'asset', '379' => 'liability',
            '901' => 'equity', '931' => 'equity', '932' => 'equity', '933' => 'equity',
            '501' => 'expense', '518' => 'expense', '551' => 'expense', '602' => 'revenue',
            '649' => 'revenue', '682' => 'revenue', '684' => 'revenue', '691' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts (supplier_id,account_code,name,account_type) VALUES (?,?,?,?)')
                ->execute([$this->supplier, (string) $code, 'Synthetic ' . $code, $type]);
            $this->accounts[$code] = (int) $this->pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    private function post(string $date, string $debit, string $credit, float $amount, ?string $activity = null, string $source = 'manual'): int
    {
        $this->pdo->prepare('INSERT INTO journal_entries (supplier_id,period_id,entry_date,source_type) VALUES (?,?,?,?)')
            ->execute([$this->supplier, $this->period, $date, $source]);
        $entry = (int) $this->pdo->lastInsertId();
        foreach (['debit' => $debit, 'credit' => $credit] as $side => $code) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines (supplier_id,entry_id,account_id,side,amount) VALUES (?,?,?,?,?)')
                ->execute([$this->supplier, $entry, $this->accounts[$code], $side, $amount]);
            $line = (int) $this->pdo->lastInsertId();
            if ($activity !== null && in_array($code[0], ['5', '6'], true)) {
                $this->pdo->prepare('INSERT INTO journal_entry_line_dimensions (supplier_id,line_id,dimension_type_id,dimension_value_id) VALUES (?,?,?,?)')
                    ->execute([$this->supplier, $line, $this->activity['id'], $this->activity['values'][$activity]]);
            }
        }
        $this->pdo->prepare('UPDATE journal_entries SET posted_at = NOW() WHERE id = ?')->execute([$entry]);
        return $entry;
    }

    public function testCrossCheckUses504ActualTotalsAndProfit(): void
    {
        $this->post('2024-07-01', '221', '901', 1000, null, 'opening');
        $this->post('2025-01-01', '221', '602', 200, 'main');
        $this->post('2025-01-02', '518', '221', 80, 'economic');
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->period]);
        $suite = Bootstrap::buildContainer()->get(\MyInvoice\Service\Report\CrossCheckSuite::class);
        $checks = array_column($suite->run($this->supplier, 2025), null, 'check');
        foreach (['balance_sheet_sides' => 1120.0, 'profit_balance_vs_income' => 120.0,
            'income_statement_vs_ledger' => 120.0] as $key => $expected) {
            self::assertFalse($checks[$key]['skipped']);
            self::assertTrue($checks[$key]['ok'], $key);
            self::assertSame($expected, $checks[$key]['a']);
            self::assertSame($expected, $checks[$key]['b']);
        }
    }

    public function testParallel504ReturnsSpecificUnsupportedCriterion(): void
    {
        $this->post('2024-07-01', '221', '931', 1000, null, 'opening');
        $service = Bootstrap::buildContainer()->get(\MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunReconciliation::class);
        foreach (['incomeStatement', 'balanceSheet'] as $field) {
            $source = new \MyInvoice\Service\Migration\Shared\ParallelRun\SourceSnapshot('synthetic',
                ...[$field => ['rows' => [], 'unit' => 1.0]]);
            $out = $service->run($this->supplier, 2025, 6, $source);
            self::assertSame('error', $out['criteria'][0]['status']);
            self::assertStringContainsString('Převod účetních výkazů podle vyhlášky 504', $out['criteria'][0]['message']);
        }
    }

    public function testCrossCheckHistorical500IsUnchangedByLater504AndManual504(): void
    {
        $this->pdo->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework='cz_500' WHERE supplier_id=?")->execute([$this->supplier]);
        $this->post('2025-01-01', '221', '602', 1234.56);
        $this->post('2025-01-02', '518', '221', 234.56);
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->period]);
        $suite = Bootstrap::buildContainer()->get(\MyInvoice\Service\Report\CrossCheckSuite::class);
        $before = $suite->run($this->supplier, 2025);
        $checks = array_column($before, null, 'check');
        foreach (['balance_sheet_sides', 'profit_balance_vs_income', 'income_statement_vs_ledger'] as $key) {
            self::assertSame(1000.0, $checks[$key]['a']);
            self::assertSame(1000.0, $checks[$key]['b']);
            self::assertTrue($checks[$key]['ok']);
        }
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?, '2025-07-01', 'cz_504')")->execute([$this->supplier]);
        $this->pdo->prepare("UPDATE supplier SET tax_accounting_decree = '504' WHERE id=?")->execute([$this->supplier]);
        self::assertSame($before, $suite->run($this->supplier, 2025));
    }

    public function testParallelHistorical500ResultIsUnchangedByLater504AndManual504(): void
    {
        $this->pdo->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework='cz_500' WHERE supplier_id=?")->execute([$this->supplier]);
        $this->post('2025-01-01', '221', '602', 1200);
        $source = new \MyInvoice\Service\Migration\Shared\ParallelRun\SourceSnapshot('synthetic',
            incomeStatement: ['rows' => ['VH' => 1200.0], 'unit' => 1.0]);
        $service = Bootstrap::buildContainer()->get(\MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunReconciliation::class);
        $before = $service->run($this->supplier, 2025, 6, $source);
        self::assertSame('differences', $before['criteria'][0]['status']);
        self::assertGreaterThan(0, $before['criteria'][0]['difference_count']);
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?, '2025-07-01', 'cz_504')")->execute([$this->supplier]);
        $this->pdo->prepare("UPDATE supplier SET tax_accounting_decree = '504' WHERE id=?")->execute([$this->supplier]);
        self::assertSame($before, $service->run($this->supplier, 2025, 6, $source));
    }

    public function testAppendixRejectsFrameworkChangeInsidePeriodBeforeBuildingStatements(): void
    {
        $this->pdo->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework='cz_500' WHERE supplier_id=?")->execute([$this->supplier]);
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?, '2025-01-01', 'cz_504'), (?, '2025-04-01', 'cz_500')")
            ->execute([$this->supplier, $this->supplier]);
        $this->expectException(\MyInvoice\Service\Tax\Return\TaxReturnException::class);
        Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\StatementOverrideSuggester::class)
            ->appAppendix($this->supplier, $this->period);
    }

    public function testAppendixRejectsDated504EvenWithManual500(): void
    {
        $this->pdo->prepare("UPDATE supplier SET tax_accounting_decree = '500' WHERE id = ?")->execute([$this->supplier]);
        try {
            Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\StatementOverrideSuggester::class)
                ->appAppendix($this->supplier, $this->period);
            self::fail('Ruční vyhláška 500 nesmí obejít důkaz nativního XML 504.');
        } catch (\MyInvoice\Service\Tax\Return\TaxReturnException $e) {
            self::assertSame('nonprofit_dppo_stored_proof_invalid', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testAppendixHistorical500IsUnchangedByLater504AndManual504(): void
    {
        $this->pdo->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework='cz_500' WHERE supplier_id=?")->execute([$this->supplier]);
        $this->post('2025-01-01', '221', '602', 1234.56);
        $this->post('2025-01-02', '518', '221', 234.56);
        $suggester = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\StatementOverrideSuggester::class);
        $before = $suggester->appAppendix($this->supplier, $this->period);
        self::assertNotEmpty($before['VetaUB']);
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?, '2025-07-01', 'cz_504')")->execute([$this->supplier]);
        $this->pdo->prepare("UPDATE supplier SET tax_accounting_decree = '504' WHERE id=?")->execute([$this->supplier]);
        self::assertSame($before, $suggester->appAppendix($this->supplier, $this->period));
    }
}
