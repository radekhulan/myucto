<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\LedgerReportRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Zůstatky pro výkazy: rozvahové účty od kotvy (poslední otevírací zápis), výsledkové od
 * plFrom. Spodní mez data v dotazu je jen výkonová a výsledek nesmí změnit — ani když se
 * kotva a plFrom liší, ani když kotva chybí.
 */
final class LedgerSyntheticBalancesWindowTest extends TestCase
{
    private PDO $pdo;
    private LedgerReportRepository $ledger;

    protected function setUp(): void
    {
        $this->pdo = new \Pdo\Sqlite('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("CREATE TABLE accounting_periods (id INTEGER PRIMARY KEY, supplier_id INTEGER, starts_on TEXT);
            CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, supplier_id INTEGER, period_id INTEGER, source_type TEXT, source_id INTEGER, reversed_by INTEGER, entry_date TEXT, posted_at TEXT);
            CREATE TABLE journal_entry_lines (entry_id INTEGER, supplier_id INTEGER, account_id INTEGER, side TEXT, amount REAL, is_red_storno INTEGER GENERATED ALWAYS AS (0) VIRTUAL, signed_amount REAL GENERATED ALWAYS AS (amount) VIRTUAL);
            CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, account_code TEXT, account_type TEXT, parent_id INTEGER, name TEXT);
            INSERT INTO chart_of_accounts VALUES (1,'221','asset',NULL,'Banka'),(2,'518','expense',NULL,'Služby'),
                (3,'602','revenue',NULL,'Tržby'),(4,'702','closing',NULL,'Počáteční účet rozvažný'),(5,'999','offbalance',NULL,'Podrozvaha');
            INSERT INTO accounting_periods VALUES (1,1,'2024-01-01'),(2,1,'2025-01-01'),(3,2,'2024-01-01'),(4,2,'2025-01-01');");
        \MyInvoice\Tests\Support\AccountDefinitionSqliteFixture::install($this->pdo);
        $this->pdo->exec("INSERT INTO chart_of_accounts (id,supplier_id,account_code,account_type,parent_id,name)
            SELECT id+100,2,account_code,account_type,CASE WHEN parent_id IS NULL THEN NULL ELSE parent_id+100 END,name FROM chart_of_accounts WHERE supplier_id=1");
        $db = new Connection(new Config([]));
        (new \ReflectionProperty($db, 'pdo'))->setValue($db, $this->pdo);
        $this->ledger = new LedgerReportRepository($db);

        // Firma 1: otevírací zápis 1. 1. 2025 → kotva 2025-01-01.
        $this->entry(1, 1, 1, 'invoice', '2024-03-01', [[1, 'debit', 1000], [3, 'credit', 1000]]);
        $this->entry(2, 1, 2, 'opening', '2025-01-01', [[1, 'debit', 5000], [4, 'credit', 5000]]);
        $this->entry(3, 1, 2, 'invoice', '2025-03-01', [[1, 'debit', 200], [3, 'credit', 200]]);
        $this->entry(4, 1, 2, 'purchase_invoice', '2025-08-01', [[2, 'debit', 300], [1, 'credit', 300]]);
        $this->entry(5, 1, 2, 'manual', '2025-08-02', [[5, 'debit', 50], [5, 'credit', 50]]);
        // Firma 2: bez otevíracího zápisu → kotva chybí, rozvaha kumulativně od počátku.
        $this->entry(6, 2, 3, 'invoice', '2024-03-01', [[1, 'debit', 1000], [3, 'credit', 1000]]);
        $this->entry(7, 2, 4, 'purchase_invoice', '2025-08-01', [[2, 'debit', 300], [1, 'credit', 300]]);
    }

    public function testBalanceAccountsStartAtAnchorWhenProfitWindowStartsLater(): void
    {
        self::assertSame([
            '221' => [5200.0, 300.0],
            '518' => [300.0, 0.0],
        ], $this->balances(1, '2025-12-31', '2025-07-01'));
    }

    public function testProfitAccountsStartAtPlFrom(): void
    {
        self::assertSame([
            '221' => [5200.0, 300.0],
            '518' => [300.0, 0.0],
            '602' => [0.0, 200.0],
        ], $this->balances(1, '2025-12-31', '2025-01-01'));
    }

    public function testWithoutAnchorBalanceAccountsStayCumulative(): void
    {
        self::assertSame([
            '221' => [1000.0, 300.0],
            '518' => [300.0, 0.0],
        ], $this->balances(2, '2025-12-31', '2025-01-01'));
    }

    public function testWithoutProfitWindowEverythingIsCumulative(): void
    {
        self::assertSame([
            '221' => [5200.0, 300.0],
            '518' => [300.0, 0.0],
            '602' => [0.0, 1200.0],
        ], $this->balances(1, '2025-12-31', null));
    }

    /** @return array<string, array{0: float, 1: float}> */
    private function balances(int $supplierId, string $asOf, ?string $plFrom): array
    {
        $out = [];
        foreach ($this->ledger->syntheticBalances($supplierId, $asOf, $plFrom) as $row) {
            $out[$row['code']] = [$row['md'], $row['d']];
        }
        return $out;
    }

    /** @param list<array{0:int,1:string,2:float}> $lines */
    private function entry(int $id, int $supplierId, int $periodId, string $type, string $date, array $lines): void
    {
        $this->pdo->prepare('INSERT INTO journal_entries VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id, $supplierId, $periodId, $type, null, null, $date, $date]);
        foreach ($lines as [$account, $side, $amount]) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines VALUES (?,?,?,?,?)')
                ->execute([$id, $supplierId, $supplierId === 2 ? $account + 100 : $account, $side, $amount]);
        }
    }
}
