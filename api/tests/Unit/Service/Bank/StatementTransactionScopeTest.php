<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\StatementTransactionScope;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatementTransactionScopeTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE bank_statements (id INTEGER PRIMARY KEY, supplier_id INTEGER)');
        $this->pdo->exec('CREATE TABLE bank_transactions (id INTEGER PRIMARY KEY, statement_id INTEGER, match_status TEXT, source TEXT)');
        $this->pdo->exec('CREATE INDEX idx_statement ON bank_transactions (statement_id)');
        $this->pdo->exec('CREATE TABLE bank_transaction_imports (statement_id INTEGER, bank_transaction_id INTEGER, PRIMARY KEY (statement_id, bank_transaction_id))');
        $this->pdo->exec('CREATE TABLE postings (bank_transaction_id INTEGER PRIMARY KEY)');
        $this->pdo->exec('INSERT INTO bank_statements VALUES (1, 10), (2, 10), (3, 20), (4, NULL), (5, 10)');
        $this->pdo->exec("INSERT INTO bank_transactions VALUES
            (1, 1, 'auto_exact', 'statement'),
            (2, 1, 'ignored', 'statement'),
            (3, 1, 'unmatched', 'statement'),
            (4, 2, 'manual', 'statement'),
            (5, 2, 'ignored', 'statement'),
            (6, 2, 'unmatched', 'email'),
            (7, 3, 'manual', 'statement'),
            (8, NULL, 'manual', 'statement'),
            (9, 4, 'manual', 'statement'),
            (10, 99, 'manual', 'statement')");
        $this->pdo->exec('INSERT INTO bank_transaction_imports VALUES
            (1, 1), (1, 2), (1, 4), (1, 5), (1, 6), (1, 7), (1, 8), (1, 9), (1, 10),
            (2, 1), (2, 4), (3, 1), (4, 1), (4, 9)');
        $this->pdo->exec('INSERT INTO postings VALUES (1)');
    }

    public function testCountsPreserveDirectAndImportedScopeWithoutOverlapOrForeignOwnership(): void
    {
        $expected = [1 => 6, 2 => 4, 3 => 1, 4 => 1, 5 => 0];
        foreach ($expected as $id => $count) {
            $legacy = 'SELECT COUNT(*) FROM bank_transactions bt WHERE ' . StatementTransactionScope::sql($id);
            self::assertSame($count, (int) $this->pdo->query($legacy)->fetchColumn());
            self::assertSame($count, (int) $this->pdo->query('SELECT ' . StatementTransactionScope::countSql($id))->fetchColumn());
        }

        $sql = 'SELECT bs.id, ' . StatementTransactionScope::countSql('bs.id') . ' AS total FROM bank_statements bs ORDER BY bs.id';
        self::assertSame($expected, array_map('intval', $this->pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR)));
    }

    #[DataProvider('conditions')]
    public function testFiltersApplyToBothDisjointCounts(string $condition, int $expected): void
    {
        $legacy = 'SELECT COUNT(*) FROM bank_transactions tx WHERE ' . StatementTransactionScope::sql(1, 'tx') . " AND ($condition)";
        self::assertSame($expected, (int) $this->pdo->query($legacy)->fetchColumn());
        self::assertSame($expected, (int) $this->pdo->query('SELECT ' . StatementTransactionScope::countSql(1, 'tx', $condition))->fetchColumn());
    }

    public static function conditions(): array
    {
        return [
            'matched' => ["tx.match_status IN ('auto_exact', 'auto_partial', 'manual')", 2],
            'ignored' => ["tx.match_status = 'ignored'", 2],
            'unposted' => ["tx.source = 'statement' AND tx.match_status <> 'ignored' AND NOT EXISTS (SELECT 1 FROM postings p WHERE p.bank_transaction_id = tx.id)", 2],
            'empty' => ["tx.match_status = 'auto_partial'", 0],
        ];
    }

    public function testCorrelatedCountsUseIndexLookupsInsteadOfTransactionScans(): void
    {
        $sql = 'EXPLAIN QUERY PLAN SELECT ' . StatementTransactionScope::countSql('bs.id') . ' FROM bank_statements bs';
        $plan = implode("\n", array_column($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC), 'detail'));
        self::assertStringContainsString('idx_statement (statement_id=?)', $plan);
        self::assertStringContainsString('SEARCH bti USING COVERING INDEX', $plan);
        self::assertDoesNotMatchRegularExpression('/SCAN (?:bt|bti)\b/', $plan);
    }

    #[DataProvider('invalidScopes')]
    public function testInvalidScopesAreRejected(int|string $statementId, string $alias): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StatementTransactionScope::countSql($statementId, $alias);
    }

    public static function invalidScopes(): array
    {
        return [[0, 'bt'], [-1, 'bt'], ['1', 'bt'], ['bs.id OR 1=1', 'bt'], [1, 'bt; DROP TABLE bank_transactions']];
    }
}
