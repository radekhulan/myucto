<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PaymentOrderRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class PaymentOrderDeletionTest extends TestCase
{
    private PDO $pdo;
    private PaymentOrderRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('CREATE TABLE payment_orders (id INTEGER PRIMARY KEY, supplier_id INTEGER, archived_at TEXT, archived_by_user_id INTEGER)');
        $this->pdo->exec('CREATE TABLE payment_order_items (payment_order_id INTEGER, purchase_invoice_id INTEGER)');
        $this->pdo->exec('CREATE TABLE bank_payment_order_submissions (payment_order_id INTEGER REFERENCES payment_orders(id) ON DELETE RESTRICT, supplier_id INTEGER, status TEXT)');
        $this->pdo->exec('INSERT INTO payment_orders (id, supplier_id) VALUES (1, 10), (2, 20)');
        $this->pdo->exec('INSERT INTO payment_order_items VALUES (1, 100), (2, 200)');
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($this->pdo);
        $this->repository = new PaymentOrderRepository($db);
    }

    public function testDeletesOnlyOwnUnsubmittedOrderAndItsItems(): void
    {
        self::assertSame('not_found', $this->repository->deleteUnsubmitted(2, 10));
        self::assertSame('deleted', $this->repository->deleteUnsubmitted(1, 10));
        self::assertSame([2], $this->pdo->query('SELECT id FROM payment_orders')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame([2], $this->pdo->query('SELECT payment_order_id FROM payment_order_items')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame('not_found', $this->repository->deleteUnsubmitted(1, 10));
    }

    public function testEveryBankAttemptPreventsDeletion(): void
    {
        foreach (['unknown', 'rejected', 'accepted_awaiting_authorization', 'import_started'] as $status) {
            $this->pdo->exec('DELETE FROM bank_payment_order_submissions');
            $this->pdo->prepare('INSERT INTO bank_payment_order_submissions VALUES (1, 10, ?)')->execute([$status]);
            self::assertSame('submitted', $this->repository->deleteUnsubmitted(1, 10));
            self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM payment_order_items')->fetchColumn());
        }
    }

    public function testArchiveKeepsSubmissionAndItemsButRemovesOrderFromHistoryCount(): void
    {
        self::assertFalse($this->repository->archiveAfterBankCancellation(1, 10, 7));
        $this->pdo->exec("INSERT INTO bank_payment_order_submissions VALUES (1, 10, 'import_started')");
        self::assertFalse($this->repository->archiveAfterBankCancellation(1, 20, 7));
        self::assertSame(1, $this->repository->countHistory(10));
        self::assertTrue($this->repository->archiveAfterBankCancellation(1, 10, 7));
        self::assertSame(0, $this->repository->countHistory(10));
        self::assertSame(1, $this->repository->countHistory(20));
        self::assertSame(7, (int) $this->pdo->query('SELECT archived_by_user_id FROM payment_orders WHERE id=1')->fetchColumn());
        self::assertSame('import_started', $this->pdo->query('SELECT status FROM bank_payment_order_submissions')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM payment_order_items')->fetchColumn());
        self::assertSame('submitted', $this->repository->deleteUnsubmitted(1, 10));
        self::assertFalse($this->repository->archiveAfterBankCancellation(1, 10, 8));
    }

    public function testItemDeletionFailureRollsBackHeaderDeletion(): void
    {
        $this->pdo->exec("CREATE TRIGGER reject_item_delete BEFORE DELETE ON payment_order_items BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            $this->repository->deleteUnsubmitted(1, 10);
        } catch (\PDOException) {
        }
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM payment_orders')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM payment_order_items')->fetchColumn());
        self::assertFalse($this->pdo->inTransaction());
    }
}
