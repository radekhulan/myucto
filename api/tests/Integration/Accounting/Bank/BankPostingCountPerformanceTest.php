<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class BankPostingCountPerformanceTest extends BankPostingTestCase
{
    public function testCountDoesNotFetchTransactionDetails(): void
    {
        $this->transaction($this->statement(), -100.00);
        $pdo = $this->db->pdo();
        $before = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
        $total = $this->suggestionRepo->unpostedCount($this->supplierId);
        $after = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
        self::assertGreaterThan(0, $total);
        self::assertSame(1, $after - $before);
    }

    public function testCountingDoesNotScanTheJournalForEveryTransaction(): void
    {
        $statement = $this->statement();
        for ($i = 0; $i < 80; $i++) {
            $tx = $this->transaction($statement, -100.00);
            $this->postPredpis('bank', $tx, '518', '221', 100.00);
            $this->transaction($statement, -200.00);
        }
        $pdo = $this->db->pdo();
        $rows = (int) $pdo->query('SELECT (SELECT COUNT(*) FROM bank_transactions) + (SELECT COUNT(*) FROM journal_entries)')->fetchColumn();
        $before = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Handler_read_next'")->fetch()['Value'];
        $total = $this->suggestionRepo->unpostedCount($this->supplierId);
        $after = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Handler_read_next'")->fetch()['Value'];
        self::assertGreaterThanOrEqual(80, $total);
        self::assertLessThan($rows * 20, $after - $before);
    }
}
