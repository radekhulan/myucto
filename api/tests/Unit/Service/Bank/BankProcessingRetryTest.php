<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Tests\Support\AbortedTransactionTrait;
use MyInvoice\Tests\Support\ScriptedFailurePdo;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Zpracování pohybů po importu výpisu (párování, zaúčtování) běží v transakcích, které
 * vlastní matcher a bankovní účtování. Odvolanou transakci (1020/1213) zopakují; bez
 * toho jediný deadlock přerušil zpracování celého výpisu.
 */
final class BankProcessingRetryTest extends TestCase
{
    use AbortedTransactionTrait;

    private const LOAD_TX = 'AS has_explicit_allocation';
    private const LOCK_PERIODS = 'FROM accounting_periods';

    public function testBankPostingRetriesAbortedTransaction(): void
    {
        $pdo = $this->pdo();
        $pdo->failOn(self::LOAD_TX, self::abortedFailure(1213));

        $result = $this->posting($pdo)->handleTransaction(1);

        self::assertSame(['action' => 'skipped', 'reason' => 'transaction_not_found'], $result);
        self::assertSame(2, $pdo->calls[self::LOAD_TX]);
        self::assertFalse($pdo->inTransaction());
    }

    public function testBankPostingInsideForeignTransactionIsNotRetried(): void
    {
        $pdo = $this->pdo();
        $pdo->failOn(self::LOAD_TX, self::abortedFailure(1020));
        $pdo->beginTransaction();

        try {
            $this->posting($pdo)->handleTransaction(1);
            self::fail('Odvolanou cizí transakci musí dostat její vlastník.');
        } catch (PDOException $e) {
            self::assertSame(1020, $e->errorInfo[1]);
        }
        self::assertSame(1, $pdo->calls[self::LOAD_TX]);
    }

    public function testMatcherRetriesAbortedTransaction(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO bank_statements (id, supplier_id) VALUES (1, 3)");
        $pdo->exec("INSERT INTO bank_transactions (id, statement_id, match_status) VALUES (1, 1, 'auto_exact')");
        $ordinary = new PDOException('synthetic ordinary failure');
        $ordinary->errorInfo = ['42000', 1064, 'synthetic ordinary failure'];
        $pdo->failOn(self::LOCK_PERIODS, self::abortedFailure(1213), $ordinary);

        try {
            $this->matcher($pdo)->match(1);
            self::fail('Druhý pokus má skončit běžnou chybou.');
        } catch (PDOException $e) {
            self::assertSame($ordinary, $e);
        }
        self::assertSame(2, $pdo->calls[self::LOCK_PERIODS], 'Odvolaná transakce párování se zopakuje.');
        self::assertFalse($pdo->inTransaction());
    }

    private function pdo(): ScriptedFailurePdo
    {
        $pdo = new ScriptedFailurePdo();
        $pdo->exec('CREATE TABLE bank_statements (id INTEGER PRIMARY KEY, supplier_id INTEGER, account_number TEXT, bank_code TEXT, currency TEXT)');
        $pdo->exec('CREATE TABLE bank_transactions (id INTEGER PRIMARY KEY, statement_id INTEGER, match_status TEXT)');
        $pdo->exec('CREATE TABLE invoice_payments (id INTEGER PRIMARY KEY, bank_transaction_id INTEGER)');
        $pdo->exec('CREATE TABLE payment_matches (id INTEGER PRIMARY KEY, bank_transaction_id INTEGER)');
        $pdo->exec('CREATE TABLE nonprofit_tax_saving_cost_bindings (supplier_id INTEGER, source_id INTEGER, event_id INTEGER, cost_line_id INTEGER)');

        return $pdo;
    }

    private function connection(ScriptedFailurePdo $pdo): Connection
    {
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        return $db;
    }

    private function posting(ScriptedFailurePdo $pdo): BankPostingService
    {
        return self::serviceWith(BankPostingService::class, ['db' => $this->connection($pdo)]);
    }

    private function matcher(ScriptedFailurePdo $pdo): StatementMatcher
    {
        return self::serviceWith(StatementMatcher::class, ['db' => $this->connection($pdo)]);
    }
}
