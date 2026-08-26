<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollPaymentLedgerSchemaTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testPaymentLedgerSchemaAndTenantRelationsAreInstalled(): void
    {
        $connection = Bootstrap::buildContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $pdo = $connection->pdo();

        foreach ([
            'payroll_payment_liabilities',
            'payroll_payment_batches',
            'payroll_payment_items',
            'payroll_payment_allocations',
            'payroll_payment_matches',
        ] as $table) {
            self::assertTrue($connection->hasTable($table), "Missing table {$table}.");
        }

        $batchColumns = $this->query(
            $pdo,
            'SHOW COLUMNS FROM payroll_payment_batches',
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('planned_payment_date', $batchColumns);
        self::assertNotContains('actual_payment_date', $batchColumns);

        $matchColumns = $this->query(
            $pdo,
            'SHOW COLUMNS FROM payroll_payment_matches',
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('actual_payment_date', $matchColumns);
        self::assertContains('bank_statement_id', $matchColumns);
        self::assertContains('bank_transaction_id', $matchColumns);
        self::assertContains('cash_document_id', $matchColumns);
        self::assertContains('liability_id', $matchColumns);
        self::assertNotContains('planned_payment_date', $matchColumns);

        $matchCreate = (string) $this->query(
            $pdo,
            'SHOW CREATE TABLE payroll_payment_matches',
        )->fetch(PDO::FETCH_NUM)[1];
        self::assertStringContainsString(
            'FOREIGN KEY (`supplier_id`, `allocation_id`)',
            $matchCreate,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (`supplier_id`, `liability_id`)',
            $matchCreate,
        );
        self::assertMatchesRegularExpression(
            '/`allocation_id` bigint\(20\) unsigned DEFAULT NULL/i',
            $matchCreate,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (`supplier_id`, `bank_statement_id`)',
            $matchCreate,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (`bank_statement_id`, `bank_transaction_id`)',
            $matchCreate,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (`supplier_id`, `cash_document_id`)',
            $matchCreate,
        );

        $triggers = $this->query($pdo, 'SHOW TRIGGERS')
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ([
            'trg_payroll_payment_liability_validate_insert',
            'trg_payroll_payment_allocation_validate_insert',
            'trg_payroll_payment_match_validate_insert',
            'trg_payroll_payment_liability_immutable_update',
            'trg_payroll_payment_batch_immutable_update',
            'trg_payroll_payment_item_immutable_update',
            'trg_payroll_payment_allocation_immutable_update',
            'trg_payroll_payment_match_immutable_update',
        ] as $trigger) {
            self::assertContains($trigger, $triggers);
        }

        $connection->close();
    }

    public function testIncomingRefundMigrationRestoresImmutableGuardAfterBackfill(): void
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1559_payroll_incoming_refund_reconciliation.sql';
        $migration = file_get_contents($path);
        self::assertIsString($migration);

        $drop = strpos(
            $migration,
            'DROP TRIGGER IF EXISTS trg_payroll_payment_match_immutable_update;',
        );
        $backfill = strpos(
            $migration,
            'UPDATE payroll_payment_matches payment_match',
        );
        $temporaryGuard = strpos(
            $migration,
            'CREATE TRIGGER trg_payroll_payment_match_immutable_update',
        );
        $restore = strrpos(
            $migration,
            'CREATE TRIGGER trg_payroll_payment_match_immutable_update',
        );
        self::assertIsInt($drop);
        self::assertIsInt($backfill);
        self::assertIsInt($temporaryGuard);
        self::assertIsInt($restore);
        self::assertLessThan($temporaryGuard, $drop);
        self::assertLessThan($backfill, $temporaryGuard);
        self::assertLessThan($backfill, $drop);
        self::assertGreaterThan($backfill, $restore);
        self::assertStringContainsString(
            'OLD.liability_id IS NULL',
            substr($migration, $temporaryGuard, $backfill - $temporaryGuard),
        );
        self::assertStringContainsString(
            "SET MESSAGE_TEXT = 'Payroll payment matches are immutable';",
            substr($migration, $restore),
        );
    }

    public function testActualPaymentDateComesFromPartialBankAndCashEvidence(): void
    {
        $connection = Bootstrap::buildContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $this->query(
            $pdo,
            'SELECT MIN(id) FROM supplier',
        )->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);

        $pdo->beginTransaction();
        try {
            $supplierId = $this->createIsolatedSupplier(
                $pdo,
                $sourceSupplierId,
            );
            [$revisionId, $employeeId] = $this->createApprovedRevision(
                $pdo,
                $supplierId,
            );

            $wageLiabilityId = $this->insertLiability(
                $pdo,
                $supplierId,
                $revisionId,
                $employeeId,
                'net-wage.synthetic',
                100_000,
                'wage-liability',
            );
            $bankAllocationId = $this->insertAllocation(
                $pdo,
                $supplierId,
                $wageLiabilityId,
                'bank',
                100_000,
            );
            $bankStatementId = $this->insertBankStatement($pdo, $supplierId);

            $firstBankTransactionId = $this->insertBankTransaction(
                $pdo,
                $bankStatementId,
                '2099-01-20',
                '-600.00',
                'first-part',
            );
            $firstMatchId = $this->insertBankMatch(
                $pdo,
                $supplierId,
                $bankAllocationId,
                $bankStatementId,
                $firstBankTransactionId,
                'matched',
                60_000,
                null,
                'first-part',
            );
            self::assertSame(
                '2099-01-20',
                $this->paymentDate($pdo, $firstMatchId),
            );

            $secondBankTransactionId = $this->insertBankTransaction(
                $pdo,
                $bankStatementId,
                '2099-01-21',
                '-400.00',
                'second-part',
            );
            $this->insertBankMatch(
                $pdo,
                $supplierId,
                $bankAllocationId,
                $bankStatementId,
                $secondBankTransactionId,
                'matched',
                40_000,
                null,
                'second-part',
            );

            $reversalTransactionId = $this->insertBankTransaction(
                $pdo,
                $bankStatementId,
                '2099-01-25',
                '250.00',
                'partial-reversal',
            );
            $reversalId = $this->insertBankMatch(
                $pdo,
                $supplierId,
                $bankAllocationId,
                $bankStatementId,
                $reversalTransactionId,
                'reversed',
                -25_000,
                $firstMatchId,
                'partial-reversal',
            );
            self::assertSame(
                '2099-01-25',
                $this->paymentDate($pdo, $reversalId),
            );
            self::assertSame(
                75_000,
                (int) $this->query(
                    $pdo,
                    "SELECT SUM(amount_minor)
                       FROM payroll_payment_matches
                      WHERE supplier_id = {$supplierId}
                        AND allocation_id = {$bankAllocationId}",
                )->fetchColumn(),
            );

            $excessTransactionId = $this->insertBankTransaction(
                $pdo,
                $bankStatementId,
                '2099-01-26',
                '-300.00',
                'excess-payment',
            );
            $this->assertDatabaseFailure(
                fn () => $this->insertBankMatch(
                    $pdo,
                    $supplierId,
                    $bankAllocationId,
                    $bankStatementId,
                    $excessTransactionId,
                    'matched',
                    30_000,
                    null,
                    'excess-payment',
                ),
                'Payroll payment allocation settlement is outside bounds',
            );

            $cashLiabilityId = $this->insertLiability(
                $pdo,
                $supplierId,
                $revisionId,
                $employeeId,
                'net-wage.cash.synthetic',
                20_000,
                'cash-liability',
            );
            $cashAllocationId = $this->insertAllocation(
                $pdo,
                $supplierId,
                $cashLiabilityId,
                'cash',
                20_000,
            );
            $cashDocumentId = $this->insertCashDocument($pdo, $supplierId);
            $cashMatchId = $this->insertCashMatch(
                $pdo,
                $supplierId,
                $cashAllocationId,
                $cashDocumentId,
            );
            self::assertSame(
                '2099-01-22',
                $this->paymentDate($pdo, $cashMatchId),
                'Caller supplied date must be replaced by the cash evidence date.',
            );
            self::assertSame(
                '2099-01-10',
                (string) $this->query(
                    $pdo,
                    "SELECT batch.planned_payment_date
                       FROM payroll_payment_batches batch
                       JOIN payroll_payment_items item
                         ON item.supplier_id = batch.supplier_id
                        AND item.batch_id = batch.id
                       JOIN payroll_payment_allocations allocation
                         ON allocation.supplier_id = item.supplier_id
                        AND allocation.item_id = item.id
                      WHERE allocation.supplier_id = {$supplierId}
                        AND allocation.id = {$cashAllocationId}",
                )->fetchColumn(),
            );

            $this->assertDatabaseFailure(
                fn () => $pdo->exec(
                    "UPDATE payroll_payment_matches
                        SET amount_minor = amount_minor
                      WHERE supplier_id = {$supplierId}
                        AND id = {$cashMatchId}",
                ),
                'Payroll payment matches are immutable',
            );

            $duplicateStatement = $pdo->prepare(
                'INSERT INTO payroll_payment_liabilities
                    (supplier_id, revision_id, employee_id,
                     liability_reference, liability_kind, direction,
                     recipient_reference, due_on, currency_code, amount_minor,
                     source_snapshot_json, source_snapshot_hash,
                     idempotency_key_hash)
                 VALUES (?, ?, ?, ?, "net_wage", "outgoing", ?,
                         "2099-01-10", "CZK", 10000, ?, ?, ?)',
            );
            $this->assertDatabaseFailure(
                fn () => $duplicateStatement->execute([
                    $supplierId,
                    $revisionId,
                    $employeeId,
                    'net-wage.duplicate.synthetic',
                    'recipient:synthetic',
                    '{"schema":"synthetic-liability.v1"}',
                    hash(
                        'sha256',
                        '{"schema":"synthetic-liability.v1"}',
                    ),
                    hash('sha256', 'wage-liability', true),
                ]),
                'Duplicate entry',
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    /**
     * @return array{int, int}
     */
    private function createApprovedRevision(
        PDO $pdo,
        int $supplierId,
    ): array {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická platební osoba", "employee", 1)',
        )->execute([$supplierId]);
        $employeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status)
             VALUES (?, "2099-01-01", "2099-01-10", "approved")',
        )->execute([$supplierId]);
        $runId = (int) $pdo->lastInsertId();

        $snapshot = '{"schema":"synthetic-payroll-result.v1"}';
        $snapshotHash = hash('sha256', $snapshot);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "synthetic-payment.v1",
                     ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $supplierId,
            $runId,
            str_repeat('a', 64),
            $snapshot,
            $snapshotHash,
            $snapshot,
            $snapshotHash,
            hash('sha256', 'synthetic-approved-revision', true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $snapshot,
            $snapshotHash,
        ]);

        return [$revisionId, $employeeId];
    }

    private function insertLiability(
        PDO $pdo,
        int $supplierId,
        int $revisionId,
        int $employeeId,
        string $reference,
        int $amountMinor,
        string $idempotencyKey,
    ): int {
        $snapshot = '{"schema":"synthetic-liability.v1"}';
        $pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, ?, "net_wage", "outgoing", ?,
                     "2099-01-10", "CZK", ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $reference,
            'recipient:synthetic',
            $amountMinor,
            $snapshot,
            hash('sha256', $snapshot),
            hash('sha256', $idempotencyKey, true),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertAllocation(
        PDO $pdo,
        int $supplierId,
        int $liabilityId,
        string $channel,
        int $amountMinor,
    ): int {
        $batchKey = "{$channel}-batch-{$liabilityId}";
        $pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor, declared_item_count,
                 snapshot_ciphertext, snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, "manual", "outgoing", "2099-01-10", "CZK",
                     ?, ?, 1, ?, ?, ?)',
        )->execute([
            $supplierId,
            $batchKey,
            $channel,
            'payer:synthetic',
            $amountMinor,
            'enc:v2:synthetic-batch',
            hash('sha256', $batchKey),
            hash('sha256', $batchKey, true),
        ]);
        $batchId = (int) $pdo->lastInsertId();

        $itemKey = "{$channel}-item-{$liabilityId}";
        $pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference, recipient_reference,
                 amount_minor, instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $batchId,
            $itemKey,
            'recipient:synthetic',
            $amountMinor,
            'enc:v2:synthetic-instruction',
            hash('sha256', $itemKey),
            hash('sha256', $itemKey, true),
        ]);
        $itemId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_payment_allocations
                (supplier_id, item_id, liability_id, amount_minor,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $itemId,
            $liabilityId,
            $amountMinor,
            hash(
                'sha256',
                "{$channel}-allocation-{$liabilityId}",
                true,
            ),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertBankStatement(PDO $pdo, int $supplierId): int
    {
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number,
                 bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, "0100", "CZK", "2099-01-31")',
        )->execute([
            $supplierId,
            'synthetic-payroll-statement.gpc',
            hash(
                'sha256',
                "synthetic-payroll-statement-{$supplierId}",
            ),
            '1000000005',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertBankTransaction(
        PDO $pdo,
        int $statementId,
        string $postedAt,
        string $amount,
        string $reference,
    ): int {
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, ?, ?, "CZK", ?, ?)',
        )->execute([
            $statementId,
            $postedAt,
            $amount,
            "Syntetická mzdová platba {$reference}",
            hash('sha256', "synthetic-bank-{$reference}"),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertBankMatch(
        PDO $pdo,
        int $supplierId,
        int $allocationId,
        int $statementId,
        int $transactionId,
        string $eventKind,
        int $amountMinor,
        ?int $sourceMatchId,
        string $idempotencyKey,
    ): int {
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, source_match_id,
                 amount_minor, bank_statement_id, bank_transaction_id,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $allocationId,
            $eventKind,
            $sourceMatchId,
            $amountMinor,
            $statementId,
            $transactionId,
            hash('sha256', "bank-match-{$idempotencyKey}", true),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertCashDocument(PDO $pdo, int $supplierId): int
    {
        $pdo->prepare(
            'INSERT INTO cash_registers
                (supplier_id, name, currency_code, account_code)
             VALUES (?, "Syntetická mzdová pokladna", "CZK", "211999")',
        )->execute([$supplierId]);
        $registerId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number,
                 issue_date, description, total_amount, currency_code,
                 counter_account_code, status)
             VALUES (?, ?, "out", "other", ?, "2099-01-22",
                     "Syntetická hotovostní mzda", 200.00, "CZK",
                     "331", "posted")',
        )->execute([
            $supplierId,
            $registerId,
            "VPD-SYNTHETIC-{$supplierId}",
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertCashMatch(
        PDO $pdo,
        int $supplierId,
        int $allocationId,
        int $cashDocumentId,
    ): int {
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, amount_minor,
                 cash_document_id, actual_payment_date,
                 idempotency_key_hash)
             VALUES (?, ?, "matched", 20000, ?, "2000-01-01", ?)',
        )->execute([
            $supplierId,
            $allocationId,
            $cashDocumentId,
            hash('sha256', 'synthetic-cash-match', true),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function paymentDate(PDO $pdo, int $matchId): string
    {
        return (string) $this->query(
            $pdo,
            "SELECT actual_payment_date
               FROM payroll_payment_matches
              WHERE id = {$matchId}",
        )->fetchColumn();
    }

    /**
     * @param callable(): mixed $operation
     */
    private function assertDatabaseFailure(
        callable $operation,
        string $message,
    ): void {
        try {
            $operation();
            self::fail("Expected database failure containing: {$message}");
        } catch (PDOException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function query(PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return $statement;
    }
}
