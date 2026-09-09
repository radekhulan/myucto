<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\AuthoritativeTransactionReconciler;
use MyInvoice\Service\Bank\BankApiMonthlyStatements;
use PDO;
use PHPUnit\Framework\TestCase;

final class BankApiBackfillCandidatesTest extends TestCase
{
    private PDO $pdo;
    private BankApiMonthlyStatements $monthly;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE bank_statements (id INTEGER PRIMARY KEY, source TEXT, supplier_id INTEGER, account_number TEXT, bank_code TEXT, currency TEXT)');
        $this->pdo->exec('CREATE TABLE bank_api_months (statement_id INTEGER, supplier_id INTEGER, account_key TEXT, currency TEXT)');
        $this->pdo->exec('CREATE TABLE bank_api_evidence_months (evidence_statement_id INTEGER)');
        $this->monthly = new BankApiMonthlyStatements($this->pdo);
    }

    public function testStandaloneGpcDoesNotTriggerBackfill(): void
    {
        $this->statement(1, 'gpc');
        self::assertSame([], $this->monthly->pendingBackfillAccounts());
    }

    public function testApiHistoryMatchesNormalizedAccountWithinSupplierAndCurrency(): void
    {
        $this->statement(1, 'gpc');
        $this->statement(2, 'bank_api', 1, 'CZK', '0000001000000005');
        $this->statement(3, 'gpc', 2);
        $this->statement(4, 'gpc', 1, 'EUR');
        $this->statement(5, 'gpc');
        self::assertSame(3, $this->pendingCount());
        $this->pdo->exec('INSERT INTO bank_api_evidence_months VALUES (1), (2), (5)');
        self::assertSame([], $this->monthly->pendingBackfillAccounts());
    }

    public function testExistingMonthlyAccountAllowsGpcWithoutApiSource(): void
    {
        $this->statement(1, 'gpc');
        $this->statement(2, 'gpc');
        $query = $this->pdo->prepare('INSERT INTO bank_api_months VALUES (?, ?, ?, ?)');
        $query->execute([2, 1, AuthoritativeTransactionReconciler::account('1000000005', '0100'), 'CZK']);
        self::assertSame(1, $this->pendingCount());
        $this->pdo->exec('INSERT INTO bank_api_evidence_months VALUES (1)');
        self::assertSame([], $this->monthly->pendingBackfillAccounts());
    }

    private function pendingCount(): int
    {
        return (int) array_sum(array_column($this->monthly->pendingBackfillAccounts(), 'statement_count'));
    }

    private function statement(int $id, string $source, int $supplier = 1, string $currency = 'CZK', string $account = '1000000005'): void
    {
        $this->pdo->prepare('INSERT INTO bank_statements VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $source, $supplier, $account, '0100', $currency]);
    }
}
