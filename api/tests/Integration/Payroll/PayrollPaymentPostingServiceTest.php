<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentEvidenceReference;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationCommand;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReversalCommand;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ú-16 — spárování platby zakládá účetní protizápis.
 *
 * Testuje se přes {@see PayrollPaymentReconciliationService}, ne přímo nad
 * {@see \MyInvoice\Service\Payroll\Payment\PayrollPaymentPostingService}: zápis
 * musí vzniknout UVNITŘ transakce párování, takže na tom, že se ta dvě volání
 * potkají, záleží stejně jako na samotném zápisu.
 */
#[Group('integration')]
final class PayrollPaymentPostingServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $connection;
    private PDO $pdo;
    private PayrollPaymentReconciliationService $service;
    private int $supplierId;
    private int $allocationId;
    private int $statementId;
    private int $liabilityId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $service = $container->get(PayrollPaymentReconciliationService::class);
        self::assertInstanceOf(PayrollPaymentReconciliationService::class, $service);

        $this->connection = $connection;
        $this->pdo = $connection->pdo();
        $this->service = $service;
        $this->pdo->beginTransaction();

        $sourceSupplierId = (int) $this->pdo
            ->query('SELECT MIN(id) FROM supplier')
            ->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier(
            $this->pdo,
            $sourceSupplierId,
        );
        $this->makeDoubleEntry();
        $revisionId = $this->createRevisionWithFrozenAccounts();
        $this->liabilityId = $this->insertLiability($revisionId, 'social_insurance');
        $this->allocationId = $this->insertAllocation($this->liabilityId, 100_000);
        $this->statementId = $this->insertBankStatement();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    /**
     * Nespárovaný bankovní pohyb, na který nesedlo žádné pravidlo, je jediná
     * díra, kterou mzdy doplňují — a musí ji doplnit správně: závazek MD proti
     * peněžnímu účtu D, na TÉŽE analytice, jakou nese zmrazená kontace revize.
     */
    public function testMatchedBankPaymentPostsLiabilityAgainstMoneyAccount(): void
    {
        $transactionId = $this->insertBankTransaction('-1000.00', 'odvod');
        $result = $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $transactionId,
                ),
                'posting-match',
                null,
            ),
        );

        $posting = $this->posting($result->id);
        self::assertSame('posted', $posting['posting_status']);
        self::assertNotNull($posting['journal_entry_id']);

        $entry = $this->entry((int) $posting['journal_entry_id']);
        self::assertSame('payroll_payment', $entry['source_type']);
        self::assertSame($result->id, (int) $entry['source_id']);

        self::assertSame(
            ['336.100|debit' => '1000.00', '221|credit' => '1000.00'],
            $this->lines((int) $posting['journal_entry_id']),
        );
    }

    /**
     * Storno platby je VLASTNÍ řádek platební knihy, takže dostane vlastní
     * zápis s obrácenými stranami. `PostingService::reverse()` se nepoužívá —
     * původní zápis zůstává v knihách nedotčený.
     */
    public function testReversalPostsTheOppositeEntry(): void
    {
        $transactionId = $this->insertBankTransaction('-1000.00', 'odvod');
        $matched = $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $transactionId,
                ),
                'posting-match',
                null,
            ),
        );
        $refundId = $this->insertBankTransaction('1000.00', 'vratka');
        $reversed = $this->service->reverse(
            new PayrollPaymentReversalCommand(
                $this->supplierId,
                $matched->id,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $refundId,
                ),
                'posting-reverse',
                null,
            ),
        );

        $posting = $this->posting($reversed->id);
        self::assertSame('posted', $posting['posting_status']);
        self::assertSame(
            ['336.100|credit' => '1000.00', '221|debit' => '1000.00'],
            $this->lines((int) $posting['journal_entry_id']),
        );
        // Původní zápis se nestornoval, jen k němu přibyl protipohyb.
        self::assertNull(
            $this->entry((int) $this->posting($matched->id)['journal_entry_id'])['reversed_by'],
        );
    }

    /**
     * Pohyb, který už zaúčtoval bankovní modul (typicky detektor odvodu na
     * předčíslí 0710), mzdy NEÚČTUJÍ podruhé — jen si vazbu poznamenají.
     */
    public function testMovementAlreadyPostedByBankIsNotPostedAgain(): void
    {
        $transactionId = $this->insertBankTransaction('-1000.00', 'odvod');
        $bankEntryId = $this->insertForeignEntry('bank', $transactionId);

        $result = $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $transactionId,
                ),
                'posting-elsewhere',
                null,
            ),
        );

        $posting = $this->posting($result->id);
        self::assertSame('posted_elsewhere', $posting['posting_status']);
        self::assertSame($bankEntryId, (int) $posting['journal_entry_id']);
        self::assertSame(
            0,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM journal_entries
                  WHERE supplier_id = {$this->supplierId}
                    AND source_type = 'payroll_payment'",
            )->fetchColumn(),
        );
    }

    /** Firma na daňové evidenci deník nemá, takže není co zaúčtovat. */
    public function testTaxEvidenceCompanyRecordsNotApplicable(): void
    {
        $this->pdo->prepare(
            'UPDATE supplier_accounting_modes
                SET accounting_mode = "tax_evidence"
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);

        $transactionId = $this->insertBankTransaction('-1000.00', 'odvod');
        $result = $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $transactionId,
                ),
                'posting-tax-evidence',
                null,
            ),
        );

        $posting = $this->posting($result->id);
        self::assertSame('not_applicable', $posting['posting_status']);
        self::assertNull($posting['journal_entry_id']);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeDoubleEntry(): void
    {
        $this->pdo->prepare(
            'INSERT INTO supplier_accounting_modes
                (supplier_id, effective_from, accounting_mode)
             VALUES (?, "2000-01-01", "double_entry")
             ON DUPLICATE KEY UPDATE accounting_mode = "double_entry"',
        )->execute([$this->supplierId]);
        $this->pdo->prepare(
            'INSERT INTO accounting_periods
                (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, 2099, "2099-01-01", "2099-12-31", "open")',
        )->execute([$this->supplierId]);
        foreach ([['336.100', 'liability'], ['221', 'asset']] as [$code, $type]) {
            $this->pdo->prepare(
                'INSERT IGNORE INTO chart_of_accounts
                    (supplier_id, account_code, name, account_type, is_active)
                 VALUES (?, ?, ?, ?, 1)',
            )->execute([$this->supplierId, $code, "Účet {$code}", $type]);
        }
    }

    /** Revize se ZMRAZENOU sadou předkontací — jediný zdroj účtu protizápisu. */
    private function createRevisionWithFrozenAccounts(): int
    {
        $this->pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status)
             VALUES (?, "2099-01-01", "2099-01-10", "approved")',
        )->execute([$this->supplierId]);
        $runId = (int) $this->pdo->lastInsertId();

        $snapshot = json_encode([
            'schema_version' => 'payroll-run-input.v2',
            'employer' => [
                'accounting_accounts' => [
                    'social_insurance_credit' => '336.100',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $snapshot);
        $this->pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "synthetic-payment.v1",
                     ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('b', 64),
            $snapshot,
            $hash,
            $snapshot,
            $hash,
            hash('sha256', "posting-revision-{$this->supplierId}", true),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertLiability(int $revisionId, string $kind): int
    {
        $snapshot = '{"schema":"synthetic-liability.v1"}';
        $this->pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, NULL, ?, ?, "outgoing", "institution:cssz",
                     "2099-01-20", "CZK", 100000, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $revisionId,
            "liability-{$kind}",
            $kind,
            $snapshot,
            hash('sha256', $snapshot),
            hash('sha256', "posting-liability-{$this->supplierId}", true),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertAllocation(int $liabilityId, int $amountMinor): int
    {
        $reference = "posting-{$liabilityId}";
        $this->pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor, declared_item_count,
                 snapshot_ciphertext, snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, "bank", "manual", "outgoing", "2099-01-10", "CZK",
                     "payer:synthetic", ?, 1, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            "batch-{$reference}",
            $amountMinor,
            'enc:v2:synthetic-batch',
            hash('sha256', "batch-{$reference}"),
            hash('sha256', "batch-{$reference}", true),
        ]);
        $batchId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference, recipient_reference,
                 amount_minor, instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "institution:cssz", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $batchId,
            "item-{$reference}",
            $amountMinor,
            'enc:v2:synthetic-instruction',
            hash('sha256', "item-{$reference}"),
            hash('sha256', "item-{$reference}", true),
        ]);
        $itemId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO payroll_payment_allocations
                (supplier_id, item_id, liability_id, amount_minor,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $itemId,
            $liabilityId,
            $amountMinor,
            hash('sha256', "allocation-{$reference}", true),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertBankStatement(): int
    {
        $this->pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number,
                 bank_code, currency, statement_date)
             VALUES (?, "synthetic-payroll-posting.gpc", ?,
                     "1000000009", "0100", "CZK", "2099-01-31")',
        )->execute([
            $this->supplierId,
            hash('sha256', "synthetic-payroll-posting-{$this->supplierId}"),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertBankTransaction(string $amount, string $reference): int
    {
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, "2099-01-20", ?, "CZK", ?, ?)',
        )->execute([
            $this->statementId,
            $amount,
            "Syntetická mzdová platba {$reference}",
            hash('sha256', "posting-bank-{$this->supplierId}-{$reference}"),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Zápis, který si pohyb nárokoval PŘED mzdami (bankovní modul). */
    private function insertForeignEntry(string $sourceType, int $sourceId): int
    {
        $periodId = (int) $this->pdo->query(
            "SELECT id FROM accounting_periods
              WHERE supplier_id = {$this->supplierId} LIMIT 1",
        )->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO journal_entries
                (supplier_id, period_id, entry_date, source_type, source_id, posted_at)
             VALUES (?, ?, "2099-01-20", ?, ?, NOW())',
        )->execute([$this->supplierId, $periodId, $sourceType, $sourceId]);

        return (int) $this->pdo->lastInsertId();
    }

    // ── čtení ───────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function posting(int $matchId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT posting_status, journal_entry_id, posting_skipped_reason
               FROM payroll_payment_match_postings
              WHERE supplier_id = ? AND match_id = ?',
        );
        $statement->execute([$this->supplierId, $matchId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Spárování {$matchId} nemá záznam o zaúčtování.");

        return $row;
    }

    /** @return array<string,mixed> */
    private function entry(int $entryId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT source_type, source_id, reversed_by
               FROM journal_entries
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $entryId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string,string> `účet|strana` → částka */
    private function lines(int $entryId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT account.account_code, line.side,
                    CAST(line.amount AS CHAR) AS amount
               FROM journal_entry_lines line
               JOIN chart_of_accounts account
                 ON account.supplier_id = line.supplier_id
                AND account.id = line.account_id
              WHERE line.supplier_id = ? AND line.entry_id = ?',
        );
        $statement->execute([$this->supplierId, $entryId]);
        $lines = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lines["{$row['account_code']}|{$row['side']}"] = (string) $row['amount'];
        }

        return $lines;
    }
}
