<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Daňové posouzení neziskových organizací nesmí zatěžovat zaúčtování běžných firem:
 * žádný trigger ani pomocná kopie deníku, zápis jen řádků samotného dokladu.
 */
final class NonprofitTaxJournalOverheadTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testJournalTablesHaveNoNonprofitTaxTriggers(): void
    {
        $stmt = $this->pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN ('journal_entries','journal_entry_lines')
              AND (TRIGGER_NAME LIKE 'trg\\_ntsll\\_%' OR ACTION_STATEMENT LIKE '%nonprofit\\_tax%')");
        self::assertSame([], $stmt->fetchAll(PDO::FETCH_COLUMN));
        $table = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nonprofit_tax_snapshot_line_locator'")->fetchColumn();
        self::assertSame(0, (int) $table);
    }

    public function testPostingForOrdinaryCompanyWritesOnlyTheDocumentRows(): void
    {
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$supplier]);
        $period = (new AccountingPeriodRepository($this->db))->create($supplier, 2026, '2026-01-01', '2026-12-31');
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($supplier, '2026-01-01');
        $accounts = $this->pdo->prepare("SELECT account_code,id FROM chart_of_accounts WHERE supplier_id=? AND account_code IN ('501','221')");
        $accounts->execute([$supplier]);
        $ids = $accounts->fetchAll(PDO::FETCH_KEY_PAIR);
        $journal = Bootstrap::buildContainer()->get(JournalEntryRepository::class);
        $inserted = [];
        foreach ([2, 50] as $size) {
            $lines = [];
            for ($i = 0; $i < $size; ++$i) {
                $lines[] = ['account_id' => (int) $ids[$i % 2 === 0 ? '501' : '221'], 'side' => $i % 2 === 0 ? 'debit' : 'credit', 'amount' => '1.00'];
            }
            $before = $this->rowsInserted();
            $journal->insert(['supplier_id' => $supplier, 'period_id' => $period, 'entry_date' => '2026-03-01',
                'source_type' => 'manual', 'description' => 'Synthetic ordinary posting', 'posted_at' => '2026-03-01 10:00:00'], $lines);
            $inserted[$size] = $this->rowsInserted() - $before;
        }
        self::assertSame(48, $inserted[50] - $inserted[2], json_encode($inserted));
    }

    private function rowsInserted(): int
    {
        return (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Handler_write'")->fetch(PDO::FETCH_NUM)[1];
    }
}
