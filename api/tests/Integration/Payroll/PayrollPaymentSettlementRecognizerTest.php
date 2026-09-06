<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use DomainException;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDeadlineOverviewRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentSettlementSignalRepository;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentEvidenceReference;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationCommand;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentSettlementDeclarationService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentSettlementRecognizer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rozpoznání zaplaceného odvodu v bankovních pohybech (migrace 1750).
 *
 * Hlídá tři věci, na kterých celý mechanismus stojí:
 *   1. z AVÍZA smí vzniknout jen provizorní signál — nikdy platba,
 *   2. z VÝPISU vzniká skutečná úhrada a signál se uzavře,
 *   3. při nejednoznačnosti se neděje nic.
 */
#[Group('integration')]
final class PayrollPaymentSettlementRecognizerTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD_START = '2026-01-01';
    private const DUE_ON = '2026-02-21';
    private const AMOUNT_MINOR = 302_400;
    private const VARIABLE_SYMBOL = '2137036200';

    private Connection $connection;
    private PDO $pdo;
    private PayrollPaymentSettlementRecognizer $recognizer;
    private PayrollPaymentSettlementSignalRepository $signals;
    private PayrollPaymentReconciliationService $reconciliation;
    private PayrollDeadlineOverviewRepository $deadlines;
    private int $supplierId;
    private int $liabilityId;
    private int $allocationId;
    private int $statementId;
    private int $actorId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $recognizer = $container->get(PayrollPaymentSettlementRecognizer::class);
        self::assertInstanceOf(PayrollPaymentSettlementRecognizer::class, $recognizer);
        $signals = $container->get(PayrollPaymentSettlementSignalRepository::class);
        self::assertInstanceOf(PayrollPaymentSettlementSignalRepository::class, $signals);
        $reconciliation = $container->get(PayrollPaymentReconciliationService::class);
        self::assertInstanceOf(PayrollPaymentReconciliationService::class, $reconciliation);
        $deadlines = $container->get(PayrollDeadlineOverviewRepository::class);
        self::assertInstanceOf(PayrollDeadlineOverviewRepository::class, $deadlines);

        $this->connection = $connection;
        $this->pdo = $connection->pdo();
        $this->recognizer = $recognizer;
        $this->signals = $signals;
        $this->reconciliation = $reconciliation;
        $this->deadlines = $deadlines;
        $this->pdo->beginTransaction();

        $sourceSupplierId = (int) $this->pdo
            ->query('SELECT MIN(id) FROM supplier')
            ->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $sourceSupplierId);
        $this->actorId = $this->createActor();

        $revisionId = $this->createApprovedRevision();
        $accountId = $this->createInstitutionAccount();
        $this->liabilityId = $this->insertLevyLiability($revisionId, $accountId);
        $this->allocationId = $this->insertAllocation($this->liabilityId);
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
     * Avízo je provizorní: závazek se jím NEUZAVÍRÁ, jen zhasne termín. Bez
     * téhle hranice by v knihách seděl uzavřený odvod, jehož platbu nikdo
     * nezaúčtoval — avízo se totiž nikdy neúčtuje.
     */
    public function testEmailNoticeCreatesSignalWithoutSettlingTheLiability(): void
    {
        $this->insertBankTransaction('2026-02-10', '-3024.00', 'notice', 'email_notice');

        self::assertSame(
            ['matched' => 0, 'signalled' => 1, 'ambiguous' => 0],
            $this->recognizer->recognizeForSupplier($this->supplierId),
        );
        self::assertSame(0, $this->matchCount(), 'Avízo nesmí založit platbu.');

        $signal = $this->signals->openForLiabilities(
            $this->supplierId,
            [$this->liabilityId],
        )[$this->liabilityId] ?? null;
        self::assertIsArray($signal);
        self::assertSame('bank_notice', $signal['origin']);
        self::assertSame(self::AMOUNT_MINOR, $signal['amount_minor']);

        self::assertSame(
            [],
            $this->openLevyDeadlines(),
            'Zaplacený odvod nemá po avízu strašit v hlídači termínů.',
        );
    }

    /** Opakovaný běh nesmí založit druhý signál na tentýž pohyb. */
    public function testRecognitionIsIdempotent(): void
    {
        $this->insertBankTransaction('2026-02-10', '-3024.00', 'notice', 'email_notice');
        $this->recognizer->recognizeForSupplier($this->supplierId);

        self::assertSame(
            ['matched' => 0, 'signalled' => 0, 'ambiguous' => 0],
            $this->recognizer->recognizeForSupplier($this->supplierId),
        );
        self::assertSame(1, $this->signalCount());
    }

    /** Z výpisu vzniká skutečná úhrada a provizorní signál se uzavře. */
    public function testStatementCreatesRealPaymentAndResolvesTheSignal(): void
    {
        $this->insertBankTransaction('2026-02-10', '-3024.00', 'notice', 'email_notice');
        $this->recognizer->recognizeForSupplier($this->supplierId);
        self::assertSame(1, $this->signalCount());

        $this->insertBankTransaction('2026-02-10', '-3024.00', 'statement', 'statement');
        self::assertSame(
            ['matched' => 1, 'signalled' => 0, 'ambiguous' => 0],
            $this->recognizer->recognizeForSupplier($this->supplierId),
        );

        self::assertSame(1, $this->matchCount());
        self::assertSame(
            [],
            $this->signals->openForLiabilities($this->supplierId, [$this->liabilityId]),
            'Skutečná úhrada musí provizorní signál uzavřít.',
        );
    }

    /**
     * Dvě stejně vzdálené platby = remíza. Automat couvne, ať platbu nepřiřadí
     * k jinému měsíci, než do kterého patří.
     */
    public function testTiedCandidatesAreLeftToTheAccountant(): void
    {
        $this->insertBankTransaction('2026-02-19', '-3024.00', 'first', 'statement');
        $this->insertBankTransaction('2026-02-23', '-3024.00', 'second', 'statement');

        self::assertSame(
            ['matched' => 0, 'signalled' => 0, 'ambiguous' => 1],
            $this->recognizer->recognizeForSupplier($this->supplierId),
        );
        self::assertSame(0, $this->matchCount());
        self::assertSame(0, $this->signalCount());
    }

    /** Pohyb s jiným variabilním symbolem není úhrada tohohle odvodu. */
    public function testForeignVariableSymbolIsIgnored(): void
    {
        $this->insertBankTransaction(
            '2026-02-10',
            '-3024.00',
            'foreign',
            'statement',
            '9999999999',
        );

        self::assertSame(
            ['matched' => 0, 'signalled' => 0, 'ambiguous' => 0],
            $this->recognizer->recognizeForSupplier($this->supplierId),
        );
        self::assertSame(0, $this->signalCount());
    }

    /** Ruční párování na avízo musí platební kniha odmítnout. */
    public function testManualMatchingRejectsEmailNoticeEvidence(): void
    {
        $transactionId = $this->insertBankTransaction(
            '2026-02-10',
            '-3024.00',
            'manual-notice',
            'email_notice',
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('avízo');
        $this->reconciliation->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                self::AMOUNT_MINOR,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $transactionId,
                ),
                'test-notice-evidence',
                null,
            ),
        );
    }

    /** „Zaplatil jsem" zhasne termín, ale závazek v saldu nechá otevřený. */
    public function testManualDeclarationSilencesDeadlineWithoutSettling(): void
    {
        $declarations = new PayrollPaymentSettlementDeclarationService($this->signals);
        $result = $declarations->declare(
            $this->supplierId,
            $this->liabilityId,
            '2026-02-18',
            'Zaplaceno z internetového bankovnictví.',
            null,
        );

        self::assertTrue($result['created']);
        self::assertSame(self::AMOUNT_MINOR, $result['amount_minor']);
        self::assertSame(0, $this->matchCount(), 'Prohlášení není platba.');
        self::assertSame([], $this->openLevyDeadlines());

        self::assertTrue($declarations->revoke($this->supplierId, $this->liabilityId));
        self::assertCount(
            1,
            $this->openLevyDeadlines(),
            'Po zrušení označení se termín musí vrátit.',
        );
    }

    public function testDeclarationRefusesFutureDate(): void
    {
        $declarations = new PayrollPaymentSettlementDeclarationService($this->signals);

        $this->expectException(\InvalidArgumentException::class);
        $declarations->declare(
            $this->supplierId,
            $this->liabilityId,
            (new \DateTimeImmutable('+1 day'))->format('Y-m-d'),
            null,
            null,
        );
    }

    /** @return list<array<string,mixed>> */
    private function openLevyDeadlines(): array
    {
        return $this->deadlines->levyDeadlines(
            $this->supplierId,
            '2026-01-01',
            '2026-12-31',
        );
    }

    private function matchCount(): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM payroll_payment_matches WHERE supplier_id = ?',
        );
        $statement->execute([$this->supplierId]);

        return (int) $statement->fetchColumn();
    }

    private function signalCount(): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM payroll_payment_settlement_signals
              WHERE supplier_id = ? AND resolved_at IS NULL',
        );
        $statement->execute([$this->supplierId]);

        return (int) $statement->fetchColumn();
    }

    private function createApprovedRevision(): int
    {
        $this->pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, ?, "2026-02-10", "approved", 1)',
        )->execute([$this->supplierId, self::PERIOD_START]);
        $runId = (int) $this->pdo->lastInsertId();

        $snapshot = '{"schema":"synthetic-payroll-result.v1"}';
        $snapshotHash = hash('sha256', $snapshot);
        $this->pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "synthetic-settlement.v1",
                     ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('b', 64),
            $snapshot,
            $snapshotHash,
            $snapshot,
            $snapshotHash,
            hash('sha256', "synthetic-settlement-revision-{$this->supplierId}", true),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createInstitutionAccount(): int
    {
        $this->pdo->prepare(
            'INSERT INTO payroll_institutions
                (supplier_id, institution_type, institution_code)
             VALUES (?, "health_insurer", "111")',
        )->execute([$this->supplierId]);
        $institutionId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO payroll_institution_accounts
                (supplier_id, institution_id, institution_name,
                 bank_account_ciphertext, bank_account_hash,
                 bank_account_masked, currency_code, variable_symbol,
                 valid_from, source_kind, source_reference, verified_on,
                 verified_by, created_by, updated_by, row_version)
             VALUES (?, ?, "Syntetická pojišťovna", "pending:v1", ?,
                     "••••0005", "CZK", ?, "2026-01-01",
                     "official_document", "synthetic:settlement",
                     "2026-01-01", ?, ?, ?, 1)',
        )->execute([
            $this->supplierId,
            $institutionId,
            random_bytes(32),
            self::VARIABLE_SYMBOL,
            $this->actorId,
            $this->actorId,
            $this->actorId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertLevyLiability(int $revisionId, int $accountId): int
    {
        $snapshot = '{"schema":"synthetic-liability.v1"}';
        $this->pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, "health-insurance.synthetic", "health_insurance",
                     "outgoing", ?, ?, "CZK", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $revisionId,
            'institution:health_insurer:111:account:' . $accountId,
            self::DUE_ON,
            self::AMOUNT_MINOR,
            $snapshot,
            hash('sha256', $snapshot),
            hash('sha256', "settlement-liability-{$this->supplierId}", true),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertAllocation(int $liabilityId): int
    {
        $reference = "settlement-{$liabilityId}";
        $this->pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor, declared_item_count,
                 snapshot_ciphertext, snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, "bank", "manual", "outgoing", ?, "CZK",
                     "payer:synthetic", ?, 1, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            "batch-{$reference}",
            self::DUE_ON,
            self::AMOUNT_MINOR,
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
             VALUES (?, ?, ?, "recipient:synthetic", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $batchId,
            "item-{$reference}",
            self::AMOUNT_MINOR,
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
            self::AMOUNT_MINOR,
            hash('sha256', "allocation-{$reference}", true),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createActor(): int
    {
        $this->pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický mzdový uživatel",
                     "accountant", "cs", 1)',
        )->execute([
            'payroll-settlement-' . bin2hex(random_bytes(6)) . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertBankStatement(): int
    {
        $this->pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number,
                 bank_code, currency, statement_date)
             VALUES (?, "synthetic-settlement.gpc", ?, "1000000005",
                     "0100", "CZK", "2026-02-28")',
        )->execute([
            $this->supplierId,
            hash('sha256', "synthetic-settlement-statement-{$this->supplierId}"),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertBankTransaction(
        string $postedAt,
        string $amount,
        string $reference,
        string $source,
        ?string $variableSymbol = null,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency,
                 variable_symbol, description, import_fingerprint)
             VALUES (?, ?, ?, ?, "CZK", ?, ?, ?)',
        )->execute([
            $this->statementId,
            $source,
            $postedAt,
            $amount,
            $variableSymbol ?? self::VARIABLE_SYMBOL,
            "Syntetický odvod {$reference}",
            hash('sha256', "synthetic-settlement-{$this->supplierId}-{$reference}"),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
