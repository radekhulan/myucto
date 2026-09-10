<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;
use MyInvoice\Service\Tax\Return\DpfoReturnDataProvider;
use MyInvoice\Service\Tax\Return\NonDeductibleCostsService;
use MyInvoice\Service\Tax\Return\LegalProvisionLedgerService;
use MyInvoice\Service\Tax\Return\PreFinalizeCheckService;
use MyInvoice\Repository\LedgerReportRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class JournalReversalTaxOriginTest extends TestCase
{
    private PDO $pdo;
    private Connection $db;

    protected function setUp(): void
    {
        $this->pdo = new \Pdo\Sqlite('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->createFunction('CONCAT', static fn (...$parts): string => implode('', $parts));
        $this->pdo->exec("CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, supplier_id INTEGER, period_id INTEGER, source_type TEXT, source_id INTEGER, reversed_by INTEGER, entry_date TEXT, posted_at TEXT);
            CREATE TABLE journal_entry_lines (entry_id INTEGER, supplier_id INTEGER, account_id INTEGER, side TEXT, amount REAL);
            CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, account_code TEXT, account_type TEXT, tax_deductibility TEXT, parent_id INTEGER, name TEXT);
            CREATE TABLE purchase_invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, tax_deductible INTEGER);
            INSERT INTO chart_of_accounts VALUES (1,'518','expense','deductible',NULL,'Služby'),(2,'549','expense','non_deductible',NULL,'Manka'),(3,'552','expense','deductible',NULL,'Rezervy'),(4,'451','liability','deductible',NULL,'Rezervy');");
        $this->db = new Connection(new Config([]));
        (new \ReflectionProperty($this->db, 'pdo'))->setValue($this->db, $this->pdo);
    }

    public function testPurchaseReversalAndReversalOfReversalKeepTheirTaxOriginAndDates(): void
    {
        $this->pdo->exec('INSERT INTO purchase_invoices VALUES (10,1,0),(20,2,0)');
        $this->entry(1, 2024, 'purchase_invoice', 10, 2, 'debit', 100000);
        $this->entry(2, 2025, 'purchase_invoice', null, 3, 'credit', 100000);
        $this->entry(3, 2026, 'purchase_invoice', null, null, 'debit', 100000);
        $this->entry(4, 2025, 'purchase_invoice', 20, null, 'debit', 900000, supplier: 2);
        $service = new NonDeductibleCostsService($this->db);
        self::assertSame(100000.0, $service->sum(1, '2024-01-01', '2024-12-31'));
        self::assertSame(-100000.0, $service->sum(1, '2025-01-01', '2025-12-31'));
        self::assertSame(100000.0, $service->sum(1, '2026-01-01', '2026-12-31'));
        self::assertSame(0.0, $service->sum(1, '2024-01-01', '2025-12-31'));
        $fo = $this->service(DpfoReturnDataProvider::class);
        (new \ReflectionProperty($fo, 'nonDeductibleCostsService'))->setValue($fo, $service);
        $foResult = $this->call($fo, 'vhBase', 1, 2025);
        self::assertSame(0.0, $foResult[0] - $foResult[1]);
    }

    public function testStockReversalsCountWhileTechnicalClosingReversalsStayExcluded(): void
    {
        $this->entry(1, 2024, 'closing', 1000000000014, 2, 'credit', 100000);
        $this->entry(2, 2025, 'closing', null, 3, 'debit', 100000);
        $this->entry(3, 2026, 'closing', null, null, 'credit', 100000);
        $this->entry(4, 2024, 'closing', 1, 5, 'credit', 700000);
        $this->entry(5, 2025, 'closing', null, 6, 'debit', 700000);
        $this->entry(6, 2026, 'closing', null, null, 'credit', 700000);
        $provider = $this->service(DppoReturnDataProvider::class);
        self::assertSame(-100000.0, $this->call($provider, 'profitBeforeTax', 1, '2025-01-01', '2025-12-31'));
        self::assertSame(100000.0, $this->call($provider, 'profitBeforeTax', 1, '2026-01-01', '2026-12-31'));
        self::assertSame(100000.0, $this->call($provider, 'accountGroupExpense', 1, '2025-01-01', '2025-12-31', '518'));
        $checks = $this->service(PreFinalizeCheckService::class);
        self::assertSame(100000.0, $this->call($checks, 'accountTurnover', 1, '518', '2025-01-01', '2025-12-31'));
        $fo = $this->service(DpfoReturnDataProvider::class);
        (new \ReflectionProperty($fo, 'nonDeductibleCostsService'))->setValue($fo, new NonDeductibleCostsService($this->db));
        self::assertSame(100000.0, $this->call($fo, 'vhBase', 1, 2025)[1]);
    }

    public function testNondeductibleStockAndLegalReserveExcludeOnlyTechnicalClosingOrigin(): void
    {
        $this->entry(1, 2024, 'closing', 1000000000015, 2, 'debit', 10000, 2);
        $this->entry(2, 2025, 'closing', null, 3, 'credit', 10000, 2);
        $this->entry(3, 2026, 'closing', null, null, 'debit', 10000, 2);
        self::assertSame(-10000.0, (new NonDeductibleCostsService($this->db))->sum(1, '2025-01-01', '2025-12-31'));
        $this->entry(4, 2024, 'closing', 1, 5, 'credit', 90000, 3);
        $this->entry(5, 2025, 'closing', null, null, 'debit', 90000, 3);
        $this->entry(6, 2025, 'manual', null, 7, 'debit', 5000, 3);
        $this->entry(7, 2025, 'manual', null, 8, 'credit', 5000, 3);
        $this->entry(8, 2025, 'manual', null, null, 'debit', 5000, 3);
        $service = new LegalProvisionLedgerService($this->db);
        self::assertSame(5000.0, $this->call($service, 'expenseCreated', 1, '552', '2025-01-01', '2025-12-31'));
    }

    public function testLedgerReportAndTaxProviderUseTheSameReversalScope(): void
    {
        $this->entry(1, 2024, 'closing', 1000000000014, 2, 'credit', 100000);
        $this->entry(2, 2025, 'closing', null, null, 'debit', 100000);
        $this->entry(3, 2024, 'closing', 1, 4, 'credit', 700000);
        $this->entry(4, 2025, 'closing', null, null, 'debit', 700000);
        $ledger = new LedgerReportRepository($this->db);
        self::assertSame(['md' => 100000.0, 'd' => 0.0], $ledger->journalTotals(1, '2025-01-01', '2025-12-31', true));
        self::assertSame(['md' => 100000.0, 'd' => 0.0], $ledger->accountTurnovers(1, 1, '2025-01-01', '2025-12-31', true));
        self::assertSame(1, $ledger->accountLinesTotal(1, 1, '2025-01-01', '2025-12-31', true));
        self::assertSame(-100000.0, $ledger->netTurnoverForCodes(1, '2025-01-01', '2025-12-31', ['518']));
        self::assertSame(['md' => 800000.0, 'd' => 0.0], $ledger->journalTotals(1, '2025-01-01', '2025-12-31', false));
    }

    private function entry(int $id, int $year, string $type, ?int $source, ?int $reversedBy, string $side, float $amount, int $account = 1, int $supplier = 1): void
    {
        $this->pdo->prepare('INSERT INTO journal_entries VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$supplier,$year,$type,$source,$reversedBy,"$year-06-01","$year-06-01"]);
        $this->pdo->prepare('INSERT INTO journal_entry_lines VALUES (?,?,?,?,?)')->execute([$id,$supplier,$account,$side,$amount]);
    }

    private function service(string $class): object
    {
        $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($instance, 'db'))->setValue($instance, $this->db);
        return $instance;
    }

    private function call(object $service, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($service, $method))->invoke($service, ...$args);
    }
}
