<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificatePaymentEvidenceProvider;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class AnnualTaxCertificatePaymentEvidenceProviderTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testProvesCurrentNetLiabilityFromImmutableActualPayment(): void
    {
        [$connection, $fixture] = $this->fixture('2026-02-15', 42_000_000);
        try {
            $proof = (new AnnualTaxCertificatePaymentEvidenceProvider(
                $connection,
            ))->prove(
                $fixture['supplier_id'],
                $fixture['employee_id'],
                $fixture['run_id'],
                $fixture['revision_id'],
                42_000_000,
                '2027-01-31',
            );

            self::assertSame('2026-02-15', $proof['last_payment_date']);
            self::assertSame(42_000_000, $proof['expected_net_minor_units']);
            self::assertCount(1, $proof['liabilities']);
            self::assertSame(
                42_000_000,
                $proof['liabilities'][0]['settled_minor_units'],
            );
            self::assertMatchesRegularExpression(
                '/^[a-f0-9]{64}$/D',
                $proof['liabilities'][0]['events'][0]['evidence_fact_hash'],
            );
        } finally {
            $this->rollback($connection);
        }
    }

    public function testFailsClosedWhenActualPaymentIsAfterJanuaryCutoff(): void
    {
        [$connection, $fixture] = $this->fixture('2027-02-01', 42_000_000);
        try {
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('31. 1. 2027');

            (new AnnualTaxCertificatePaymentEvidenceProvider(
                $connection,
            ))->prove(
                $fixture['supplier_id'],
                $fixture['employee_id'],
                $fixture['run_id'],
                $fixture['revision_id'],
                42_000_000,
                '2027-01-31',
            );
        } finally {
            $this->rollback($connection);
        }
    }

    public function testFailsClosedWhenLiabilityVectorDoesNotEqualApprovedNet(): void
    {
        [$connection, $fixture] = $this->fixture('2026-02-15', 41_000_000);
        try {
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('schválené čisté mzdě');

            (new AnnualTaxCertificatePaymentEvidenceProvider(
                $connection,
            ))->prove(
                $fixture['supplier_id'],
                $fixture['employee_id'],
                $fixture['run_id'],
                $fixture['revision_id'],
                42_000_000,
                '2027-01-31',
            );
        } finally {
            $this->rollback($connection);
        }
    }

    /**
     * Vrácená platba je důkaz OPAČNÉHO směru než úhrada: banka peníze poslala
     * zpátky, takže mzda vyplacená není. Mezní datum § 5 odst. 4 se proto smí
     * uplatnit jen na `matched` událost. Dokud filtr platil na obě, stačilo, aby
     * se vrácení zaúčtovalo 1. února, a nevyplacená mzda prošla na potvrzení
     * jako řádně vyplacená.
     */
    public function testCountsBankReversalEvenWhenRecordedAfterTheCutoff(): void
    {
        [$connection, $fixture] = $this->fixture('2026-02-15', 42_000_000);
        try {
            $this->reverse($connection, $fixture['supplier_id'], '2027-02-05');

            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('31. 1. 2027');

            (new AnnualTaxCertificatePaymentEvidenceProvider(
                $connection,
            ))->prove(
                $fixture['supplier_id'],
                $fixture['employee_id'],
                $fixture['run_id'],
                $fixture['revision_id'],
                42_000_000,
                '2027-01-31',
            );
        } finally {
            $this->rollback($connection);
        }
    }

    public function testReversedAndReissuedPaymentWithinCutoffStillProves(): void
    {
        [$connection, $fixture] = $this->fixture('2026-02-15', 42_000_000);
        try {
            $this->reverse($connection, $fixture['supplier_id'], '2026-02-16');
            $this->rematch(
                $connection,
                $fixture['supplier_id'],
                '2026-02-17',
                42_000_000,
            );

            $proof = (new AnnualTaxCertificatePaymentEvidenceProvider(
                $connection,
            ))->prove(
                $fixture['supplier_id'],
                $fixture['employee_id'],
                $fixture['run_id'],
                $fixture['revision_id'],
                42_000_000,
                '2027-01-31',
            );

            self::assertSame('2026-02-17', $proof['last_payment_date']);
            self::assertSame(
                42_000_000,
                $proof['liabilities'][0]['settled_minor_units'],
            );
            self::assertCount(3, $proof['liabilities'][0]['events']);
        } finally {
            $this->rollback($connection);
        }
    }

    /** Zaúčtuje vrácení poslední spárované platby k datu `$postedAt`. */
    private function reverse(
        Connection $connection,
        int $supplierId,
        string $postedAt,
    ): void {
        $pdo = $connection->pdo();
        $source = $pdo->prepare(
            'SELECT payment_match.id, payment_match.allocation_id,
                    payment_match.amount_minor
               FROM payroll_payment_matches payment_match
              WHERE payment_match.supplier_id = ?
                AND payment_match.event_kind = "matched"
              ORDER BY payment_match.id DESC
              LIMIT 1',
        );
        $source->execute([$supplierId]);
        $row = $source->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $amountMinor = (int) $row['amount_minor'];
        $statementId = $this->bankStatement($pdo, $supplierId, 'reversal');
        $transactionId = $this->bankTransaction(
            $pdo,
            $statementId,
            $postedAt,
            -$amountMinor,
        );
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, amount_minor,
                 bank_statement_id, bank_transaction_id, source_match_id,
                 idempotency_key_hash)
             VALUES (?, ?, "reversed", ?, ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            (int) $row['allocation_id'],
            -$amountMinor,
            $statementId,
            $transactionId,
            (int) $row['id'],
            random_bytes(32),
        ]);
    }

    /** Znovu odeslaná platba na tutéž alokaci. */
    private function rematch(
        Connection $connection,
        int $supplierId,
        string $postedAt,
        int $amountMinor,
    ): void {
        $pdo = $connection->pdo();
        $allocationId = (int) $pdo->query(
            'SELECT allocation_id FROM payroll_payment_matches
              WHERE supplier_id = ' . $supplierId . '
              ORDER BY id DESC LIMIT 1',
        )->fetchColumn();
        $statementId = $this->bankStatement($pdo, $supplierId, 'rematch');
        $transactionId = $this->bankTransaction(
            $pdo,
            $statementId,
            $postedAt,
            $amountMinor,
        );
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, amount_minor,
                 bank_statement_id, bank_transaction_id, idempotency_key_hash)
             VALUES (?, ?, "matched", ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $allocationId,
            $amountMinor,
            $statementId,
            $transactionId,
            random_bytes(32),
        ]);
    }

    /**
     * @return array{
     *   Connection,
     *   array{supplier_id:int,employee_id:int,run_id:int,revision_id:int}
     * }
     */
    private function fixture(
        string $actualPaymentDate,
        int $liabilityAmountMinor,
    ): array {
        $connection = Bootstrap::buildContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        if (!$connection->hasTable('payroll_payment_matches')) {
            $this->markTestSkipped('Migrace platební evidence mezd neproběhla.');
        }
        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $pdo->beginTransaction();
        try {
            return $this->createFixture(
                $connection,
                $sourceSupplierId,
                $actualPaymentDate,
                $liabilityAmountMinor,
            );
        } catch (\Throwable $exception) {
            $this->rollback($connection);
            throw $exception;
        }
    }

    /**
     * @return array{
     *   Connection,
     *   array{supplier_id:int,employee_id:int,run_id:int,revision_id:int}
     * }
     */
    private function createFixture(
        Connection $connection,
        int $sourceSupplierId,
        string $actualPaymentDate,
        int $liabilityAmountMinor,
    ): array {
        $pdo = $connection->pdo();
        $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);

        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická platební osoba", "employee", 1)',
        )->execute([$supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-01-01", "2026-02-15", "approved", 1)',
        )->execute([$supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $snapshot = '{"schema":"synthetic-tax-certificate-payment.v1"}';
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
            hash(
                'sha256',
                "synthetic-tax-certificate-revision-{$supplierId}",
                true,
            ),
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

        $liabilitySnapshot = '{"schema":"synthetic-liability.v1"}';
        $pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, ?, "net_wage", "outgoing", ?,
                     "2026-02-15", "CZK", ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            'net-wage.tax-certificate',
            'recipient:synthetic',
            $liabilityAmountMinor,
            $liabilitySnapshot,
            hash('sha256', $liabilitySnapshot),
            hash(
                'sha256',
                "synthetic-tax-certificate-liability-{$supplierId}",
                true,
            ),
        ]);
        $liabilityId = (int) $pdo->lastInsertId();
        $allocationId = $this->allocation(
            $pdo,
            $supplierId,
            $liabilityId,
            $liabilityAmountMinor,
        );
        $statementId = $this->bankStatement($pdo, $supplierId);
        $transactionId = $this->bankTransaction(
            $pdo,
            $statementId,
            $actualPaymentDate,
            $liabilityAmountMinor,
        );
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, amount_minor,
                 bank_statement_id, bank_transaction_id,
                 idempotency_key_hash)
             VALUES (?, ?, "matched", ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $allocationId,
            $liabilityAmountMinor,
            $statementId,
            $transactionId,
            hash(
                'sha256',
                "synthetic-tax-certificate-match-{$supplierId}",
                true,
            ),
        ]);

        return [
            $connection,
            [
                'supplier_id' => $supplierId,
                'employee_id' => $employeeId,
                'run_id' => $runId,
                'revision_id' => $revisionId,
            ],
        ];
    }

    private function allocation(
        PDO $pdo,
        int $supplierId,
        int $liabilityId,
        int $amountMinor,
    ): int {
        $reference = "tax-certificate-{$liabilityId}";
        $pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor, declared_item_count,
                 snapshot_ciphertext, snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, "bank", "manual", "outgoing", "2026-02-15",
                     "CZK", "payer:synthetic", ?, 1, ?, ?, ?)',
        )->execute([
            $supplierId,
            "{$reference}-batch",
            $amountMinor,
            'enc:v2:synthetic-batch',
            hash('sha256', "{$reference}-batch"),
            hash('sha256', "{$reference}-batch", true),
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference, recipient_reference,
                 amount_minor, instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "recipient:synthetic", ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $batchId,
            "{$reference}-item",
            $amountMinor,
            'enc:v2:synthetic-instruction',
            hash('sha256', "{$reference}-item"),
            hash('sha256', "{$reference}-item", true),
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
            hash('sha256', "{$reference}-allocation", true),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function bankStatement(
        PDO $pdo,
        int $supplierId,
        string $discriminator = '',
    ): int {
        $key = "synthetic-tax-certificate-{$supplierId}{$discriminator}";
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number,
                 bank_code, currency, statement_date)
             VALUES (?, ?, ?, "1000000005", "0100", "CZK", "2027-02-01")',
        )->execute([
            $supplierId,
            "{$key}.gpc",
            hash('sha256', $key),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function bankTransaction(
        PDO $pdo,
        int $statementId,
        string $postedAt,
        int $amountMinor,
    ): int {
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, ?, ?, "CZK", "Syntetická roční mzdová úhrada", ?)',
        )->execute([
            $statementId,
            $postedAt,
            number_format(-$amountMinor / 100, 2, '.', ''),
            hash(
                'sha256',
                "synthetic-tax-certificate-transaction-{$statementId}"
                . "-{$amountMinor}",
            ),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function rollback(Connection $connection): void
    {
        if ($connection->pdo()->inTransaction()) {
            $connection->pdo()->rollBack();
        }
        $connection->close();
    }
}
