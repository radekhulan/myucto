<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\ImportedStatementRebase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * První výpis banky dorovná stavy převzatých výpisů, další rozdíl zůstává nálezem.
 * Syntetický účet a rok 2099, vše v transakci, která se vrátí.
 */
#[Group('integration')]
final class ImportedStatementRebaseTest extends TestCase
{
    private const ACCOUNT = '9990562399';
    private Connection $db;
    private PDO $pdo;
    private ImportedStatementRebase $rebase;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $this->db = Bootstrap::buildContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }
        $this->pdo = $this->db->pdo();
        $this->rebase = new ImportedStatementRebase($this->db);
        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí dodavatel.');
        }
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testFirstBankStatementShiftsImportedChainAndShowsOpening(): void
    {
        $january = $this->statement('import', '2099-01-31', '0.00', '-100.00', [['2099-01-15', '-100.00']]);
        $february = $this->statement('import', '2099-02-28', '-100.00', '-150.00', [['2099-02-15', '-50.00']]);
        $march = $this->statement('gpc', '2099-03-31', '950.00', '1000.00', [['2099-03-10', '50.00']]);

        self::assertSame(['shift' => 1100.0, 'statements' => 2, 'opening' => 1100.0], $this->rebase->rebase($this->supplierId, $march));
        self::assertSame(['1100.00', '1000.00'], $this->balances($january));
        self::assertSame(['1000.00', '950.00'], $this->balances($february));
        self::assertSame(['950.00', '1000.00'], $this->balances($march));
    }

    public function testOverlappingBankStatementComparesAtItsOwnDate(): void
    {
        $january = $this->statement('import', '2099-01-31', '0.00', '-100.00', [['2099-01-15', '-100.00']]);
        $overlap = $this->statement('gpc', '2099-01-31', '400.00', '300.00', []);

        self::assertSame(400.0, $this->rebase->rebase($this->supplierId, $overlap)['shift']);
        self::assertSame(['400.00', '300.00'], $this->balances($january));
    }

    public function testSecondBankStatementDoesNotRebase(): void
    {
        $january = $this->statement('import', '2099-01-31', '0.00', '-100.00', [['2099-01-15', '-100.00']]);
        $this->statement('gpc', '2099-02-28', '-100.00', '-100.00', []);
        $march = $this->statement('gpc', '2099-03-31', '500.00', '500.00', []);

        self::assertNull($this->rebase->rebase($this->supplierId, $march));
        self::assertSame(['0.00', '-100.00'], $this->balances($january));
    }

    public function testGapAndMatchingBalanceDoNotRebase(): void
    {
        $january = $this->statement('import', '2099-01-31', '0.00', '-100.00', [['2099-01-15', '-100.00']]);
        $april = $this->statement('gpc', '2099-04-30', '500.00', '500.00', []);
        self::assertNull($this->rebase->rebase($this->supplierId, $april), 'Mezera může být z nezaevidovaných pohybů.');
        self::assertSame(['0.00', '-100.00'], $this->balances($january));

        $this->pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$april]);
        $february = $this->statement('gpc', '2099-02-28', '-100.00', '-100.00', []);
        self::assertNull($this->rebase->rebase($this->supplierId, $february), 'Stav sedí, není co dorovnat.');
    }

    /** @param list<array{0:string,1:string}> $moves */
    private function statement(string $source, string $date, string $prev, string $curr, array $moves): int
    {
        $this->pdo->prepare("INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, file_content, account_number, bank_code, currency,
                 statement_number, statement_date, prev_balance, curr_balance, credit_total, debit_total, transaction_count)
             VALUES (?, ?, ?, ?, ?, ?, '0800', 'CZK', ?, ?, ?, ?, 0, 0, ?)")
            ->execute([
                $this->supplierId, $source, 'test-' . $date, hash('sha256', $source . $date . random_int(0, PHP_INT_MAX)),
                $source === 'gpc' ? 'x' : null, self::ACCOUNT, substr($date, 5, 2), $date, $prev, $curr, count($moves),
            ]);
        $id = (int) $this->pdo->lastInsertId();
        foreach ($moves as [$posted, $amount]) {
            $this->pdo->prepare("INSERT INTO bank_transactions (source, statement_id, posted_at, amount, currency, import_fingerprint)
                VALUES ('statement', ?, ?, ?, 'CZK', ?)")->execute([$id, $posted, $amount, hash('sha256', $id . $posted . $amount)]);
        }
        return $id;
    }

    /** @return array{0:string,1:string} */
    private function balances(int $id): array
    {
        $row = $this->pdo->query("SELECT prev_balance, curr_balance FROM bank_statements WHERE id = {$id}")->fetch(PDO::FETCH_NUM);
        return [(string) $row[0], (string) $row[1]];
    }
}
