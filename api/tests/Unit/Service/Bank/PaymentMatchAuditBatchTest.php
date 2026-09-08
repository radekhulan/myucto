<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\Match\PaymentMatchAuditChecker;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PaymentMatchAuditBatchTest extends TestCase
{
    private PaymentAuditCountingPdo $pdo;
    private PaymentMatchAuditChecker $checker;

    protected function setUp(): void
    {
        $this->pdo = new PaymentAuditCountingPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->createFunction('CONCAT', static fn (...$parts): string => implode('', $parts));
        foreach ([
            'bank_statements (id INTEGER PRIMARY KEY, currency TEXT)',
            'bank_transactions (id INTEGER PRIMARY KEY, statement_id INTEGER, posted_at TEXT, amount NUMERIC, currency TEXT, counterparty_name TEXT, matched_invoice_id INTEGER, match_status TEXT)',
            'invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, varsymbol TEXT, exchange_rate NUMERIC, amount_to_pay NUMERIC, status TEXT, currency_id INTEGER, client_id INTEGER)',
            'purchase_invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, varsymbol TEXT, vendor_invoice_number TEXT, exchange_rate NUMERIC, amount_to_pay NUMERIC, status TEXT, currency_id INTEGER, vendor_id INTEGER)',
            'currencies (id INTEGER PRIMARY KEY, code TEXT)',
            'clients (id INTEGER PRIMARY KEY, company_name TEXT)',
            'invoice_payments (invoice_id INTEGER, bank_transaction_id INTEGER, amount NUMERIC)',
            'payment_matches (purchase_invoice_id INTEGER, bank_transaction_id INTEGER, amount NUMERIC, supplier_id INTEGER)',
            'journal_entries (id INTEGER PRIMARY KEY, supplier_id INTEGER, source_type TEXT, source_id INTEGER, posted_at TEXT, reversed_by INTEGER)',
            'journal_entry_lines (entry_id INTEGER, supplier_id INTEGER, account_id INTEGER, amount NUMERIC)',
            'chart_of_accounts (id INTEGER PRIMARY KEY, account_code TEXT, parent_id INTEGER)',
        ] as $schema) {
            $this->pdo->exec('CREATE TABLE ' . $schema);
        }
        $this->pdo->exec("INSERT INTO bank_statements VALUES (1, 'CZK')");
        $this->pdo->exec("INSERT INTO currencies VALUES (1, 'CZK'), (2, 'EUR')");
        $this->pdo->exec("INSERT INTO clients VALUES (1, 'Synthetic partner')");
        $this->pdo->exec("INSERT INTO chart_of_accounts VALUES (1, '563', NULL), (2, '663', NULL), (3, '999001', 1), (4, '221', NULL)");
        for ($id = 1; $id <= 8; $id++) {
            $this->addIssued($id, $id === 7 ? 20 : 10);
        }
        $this->pdo->exec("UPDATE invoices SET currency_id = 2, exchange_rate = 25 WHERE id = 5");
        $this->pdo->exec('UPDATE bank_transactions SET amount = 2600 WHERE id = 5');
        $this->pdo->exec('UPDATE bank_transactions SET matched_invoice_id = NULL WHERE id = 6');
        $this->pdo->exec("INSERT INTO purchase_invoices VALUES (6, 10, 'TEST-P6', NULL, 1, 100, 'paid', 1, 1), (9, 10, 'TEST-P9', NULL, 1, 100, 'paid', 1, 1)");
        $this->pdo->exec('INSERT INTO payment_matches VALUES (6, 6, 100, 10), (9, 6, 100, 10)');
        $this->pdo->exec("INSERT INTO journal_entries VALUES
            (1, 10, 'bank', 1, '2099-01-01', NULL),
            (2, 10, 'bank', 2, '2099-01-01', 99),
            (3, 10, 'bank', 3, NULL, NULL),
            (4, 10, 'bank', 4, '2099-01-01', NULL),
            (5, 10, 'bank', 5, '2099-01-01', NULL),
            (6, 10, 'bank', 6, '2099-01-01', NULL),
            (7, 20, 'bank', 7, '2099-01-01', NULL),
            (8, 10, 'invoice', 8, '2099-01-01', NULL)");
        $this->pdo->exec('INSERT INTO journal_entry_lines VALUES
            (1, 10, 1, 4), (1, 10, 3, 6), (1, 10, 4, 100),
            (2, 10, 1, 50), (3, 10, 1, 50), (4, 20, 1, 50),
            (5, 10, 2, 100), (6, 10, 2, 11), (7, 20, 1, 99), (8, 10, 1, 99)');
        $db = new Connection(new Config([]));
        (new ReflectionProperty(Connection::class, 'pdo'))->setValue($db, $this->pdo);
        $this->checker = new PaymentMatchAuditChecker($db);
    }

    public function testBatchPreservesFxMagnitudeAndExcludesReversedUnpostedAndForeignLines(): void
    {
        $items = $this->checker->audit(10, '2099-01-01', '2099-12-31');
        $actual = [];
        foreach ($items as $item) {
            self::assertSame(['fx_on_czk_czk'], $item['issues']);
            self::assertSame($item['doc_id'] === 9 ? 6 : $item['doc_id'], $item['bank_transaction_id']);
            $actual[$item['doc_id']] = $item['detail']['fx_on_czk_czk']['amount'];
        }
        ksort($actual);
        self::assertSame([1 => 10.0, 6 => 11.0, 9 => 11.0], $actual);
        $foreign = $this->checker->audit(20, '2099-01-01', '2099-12-31');
        self::assertCount(1, $foreign);
        self::assertSame(7, $foreign[0]['doc_id']);
        self::assertSame(99.0, $foreign[0]['detail']['fx_on_czk_czk']['amount']);
    }

    public function testFxLookupCountIsBoundedByBatchesAndHandlesDuplicateTransactionIds(): void
    {
        for ($id = 100; $id < 1101; $id++) {
            $this->addIssued($id, 10);
        }
        $this->pdo->fxQueries = [];
        $items = $this->checker->audit(10, '2099-01-01', '2099-12-31');
        self::assertCount(3, $items);
        self::assertCount(4, $this->pdo->fxQueries);
        foreach ($this->pdo->fxQueries as $sql) {
            self::assertLessThanOrEqual(500, substr_count($sql, '?'));
        }
    }

    public function testRepeatedAuditReadsNewPostingsWithoutStaleCache(): void
    {
        self::assertCount(3, $this->checker->audit(10, '2099-01-01', '2099-12-31'));
        $this->pdo->exec("INSERT INTO journal_entries VALUES (9, 10, 'bank', 8, '2099-01-01', NULL)");
        $this->pdo->exec('INSERT INTO journal_entry_lines VALUES (9, 10, 1, 12)');
        $items = $this->checker->audit(10, '2099-01-01', '2099-12-31');
        self::assertCount(4, $items);
        self::assertSame(8, $items[0]['doc_id']);
        self::assertSame(12.0, $items[0]['impact_czk']);
    }

    public function testEmptyRangeDoesNotLoadJournalTotals(): void
    {
        $this->pdo->fxQueries = [];
        self::assertSame([], $this->checker->audit(10, '2100-01-01', '2100-12-31'));
        self::assertSame([], $this->pdo->fxQueries);
    }

    private function addIssued(int $id, int $supplierId): void
    {
        $this->pdo->prepare("INSERT INTO invoices VALUES (?, ?, ?, 1, 100, 'paid', 1, 1)")->execute([$id, $supplierId, 'TEST-I' . $id]);
        $this->pdo->prepare("INSERT INTO bank_transactions VALUES (?, 1, '2099-01-01', 100, 'CZK', '', ?, 'manual')")->execute([$id, $id]);
    }
}

final class PaymentAuditCountingPdo extends \Pdo\Sqlite
{
    public array $fxQueries = [];

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'SUM(l.amount) AS total')) {
            $this->fxQueries[] = $query;
        }
        return parent::prepare($query, $options);
    }
}
