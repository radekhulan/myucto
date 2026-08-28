<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollNetRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Append-only evidence srážek ze mzdy.
 *
 * Zápis do `payroll_net_results` a `payroll_payout_allocations` tenhle repozitář
 * už nemá — nikdy neměl produkčního volajícího a model ho přerostl (viz docblock
 * {@see PayrollNetRepository}). Čistá mzda se čte ze zmrazené revize a rozpis
 * výplaty z `payroll_payment_liabilities`; obojí pokrývá
 * `PayrollDeductionAgreementLifecycleTest`.
 */
#[Group('integration')]
final class PayrollNetPersistenceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollNetRepository $repository;
    private int $supplierId;
    private int $employeeId;
    private int $revisionId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        $repository = $container->get(PayrollNetRepository::class);
        if (!$db instanceof Connection || !$repository instanceof PayrollNetRepository) {
            throw new \RuntimeException('Služby čisté mzdy nejsou dostupné.');
        }
        $this->db = $db;
        if (!$db->hasTable('payroll_deduction_ledger')) {
            $this->markTestSkipped('Migrace 1250 neproběhla.');
        }
        $this->repository = $repository;
        $pdo = $db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'supplier');
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->createEmployee($pdo, $this->supplierId);
        $this->revisionId = $this->createRevision($pdo, $this->supplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testAppendOnlyLedgerSupportsIdempotencyAndExplicitReversal(): void
    {
        $withholdingId = $this->repository->appendLedgerMovement(
            $this->supplierId,
            null,
            $this->revisionId,
            $this->employeeId,
            'withheld',
            25_000,
            'synthetic-withholding-1',
            null,
            ['reason' => 'synthetic'],
            null,
        );
        self::assertSame($withholdingId, $this->repository->appendLedgerMovement(
            $this->supplierId,
            null,
            $this->revisionId,
            $this->employeeId,
            'withheld',
            25_000,
            'synthetic-withholding-1',
            null,
            ['reason' => 'synthetic'],
            null,
        ));
        $reversalId = $this->repository->appendLedgerMovement(
            $this->supplierId,
            null,
            $this->revisionId,
            $this->employeeId,
            'reversed',
            -25_000,
            'synthetic-withholding-1-reversal',
            $withholdingId,
            ['reason' => 'synthetic correction'],
            null,
        );
        $replayedReversalId = $this->repository->appendLedgerMovement(
            $this->supplierId,
            null,
            $this->revisionId,
            $this->employeeId,
            'reversed',
            -25_000,
            'synthetic-withholding-1-reversal',
            $withholdingId,
            ['reason' => 'synthetic correction'],
            null,
        );

        self::assertNotSame($withholdingId, $reversalId);
        self::assertSame($reversalId, $replayedReversalId);
        $ledger = $this->repository->ledger($this->supplierId, $this->employeeId);
        self::assertSame(['withheld', 'reversed'], array_column($ledger, 'event_kind'));
        self::assertSame([25_000, -25_000], array_column($ledger, 'amount_minor'));
    }

    public function testLedgerCannotUseAgreementOwnedByAnotherEmployee(): void
    {
        $otherEmployeeId = $this->createEmployee($this->db->pdo(), $this->supplierId);
        $agreementId = $this->createAgreement(
            $this->db->pdo(),
            $this->supplierId,
            $otherEmployeeId,
        );

        $this->expectException(PDOException::class);
        $this->repository->appendLedgerMovement(
            $this->supplierId,
            $agreementId,
            $this->revisionId,
            $this->employeeId,
            'withheld',
            1_000,
            'synthetic-cross-employee-agreement',
            null,
            [],
            null,
        );
    }

    private function createEmployee(PDO $pdo, int $supplierId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba", "employee", "hpp", 1, 1, 0, 10000, 0, 1)'
        )->execute([$supplierId]);
        return (int) $pdo->lastInsertId();
    }

    private function createRevision(PDO $pdo, int $supplierId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, "2026-06-01", "2026-06-30")'
        )->execute([$supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "calculated",
                     "payroll-run-input.v1", ?, "{}", ?, ?)'
        )->execute([
            $supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            hash('sha256', 'synthetic-idempotency', true),
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function createAgreement(PDO $pdo, int $supplierId, int $employeeId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_deduction_agreements
                (supplier_id, employee_id, agreement_reference, title,
                 deduction_kind, status, priority_no, requested_minor, valid_from)
             VALUES (?, ?, ?, "Syntetická srážka", "other", "active", 100, 1000,
                     "2026-06-01")'
        )->execute([
            $supplierId,
            $employeeId,
            'synthetic-agreement-' . $employeeId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function firstId(PDO $pdo, string $table): int
    {
        if ($table !== 'supplier') {
            throw new \InvalidArgumentException('Nepodporovaná testovací tabulka.');
        }
        $stmt = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }
}
