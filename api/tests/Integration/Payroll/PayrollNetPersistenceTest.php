<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollNetRepository;
use MyInvoice\Service\Payroll\Net\NetRelationshipIncome;
use MyInvoice\Service\Payroll\Net\PayoutAllocationRequest;
use MyInvoice\Service\Payroll\Net\PayoutAllocationService;
use MyInvoice\Service\Payroll\Net\PayrollNetCalculator;
use MyInvoice\Service\Payroll\Net\PayrollNetInput;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollNetPersistenceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollNetRepository $repository;
    private int $supplierId;
    private int $otherSupplierId;
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
        foreach ([
            'payroll_net_results',
            'payroll_payout_allocations',
            'payroll_deduction_ledger',
        ] as $table) {
            if (!$db->hasTable($table)) {
                $this->markTestSkipped('Migrace 1250 neproběhla.');
            }
        }
        $this->repository = $repository;
        $pdo = $db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'supplier');
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
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

    public function testPersistsImmutableResultAndExactAllocationsIdempotently(): void
    {
        $result = (new PayrollNetCalculator())->calculate(new PayrollNetInput(
            personReference: (string) $this->employeeId,
            relationships: [
                new NetRelationshipIncome('employment-synthetic', 1_000_000, 50_000),
            ],
            employeeSocialMinorUnits: 71_000,
            employeeHealthMinorUnits: 45_000,
            advanceTaxMinorUnits: 80_000,
            withholdingTaxMinorUnits: 0,
            taxBonusMinorUnits: 0,
            correctionMinorUnits: 0,
            voluntaryDeductionCapacityMinorUnits: 804_000,
            deductions: [],
        ));
        $payout = (new PayoutAllocationService())->allocate(
            $result->netPayableMinorUnits,
            [PayoutAllocationRequest::remainder(
                'primary',
                'bank',
                'synthetic-primary-account',
                1,
            )],
        );

        $id = $this->repository->saveCalculation(
            $this->supplierId,
            $this->revisionId,
            $this->employeeId,
            $result,
            $payout,
        );
        $replayedId = $this->repository->saveCalculation(
            $this->supplierId,
            $this->revisionId,
            $this->employeeId,
            $result,
            $payout,
        );

        self::assertSame($id, $replayedId);
        $stored = $this->repository->findResult(
            $this->supplierId,
            $this->revisionId,
            $this->employeeId,
        );
        self::assertNotNull($stored);
        self::assertSame('2026-06-01', (string) $stored['period_start']);
        self::assertSame(
            hash('sha256', CanonicalJson::encode([
                'net' => $result->jsonSerialize(),
                'payout' => $payout->jsonSerialize(),
            ])),
            $stored['result_hash'],
        );
        self::assertSame(804_000, $stored['net_payable_minor']);
        $allocations = $this->repository->allocations(
            $this->supplierId,
            $this->revisionId,
            $this->employeeId,
        );
        self::assertCount(1, $allocations);
        self::assertSame(
            'synthetic-primary-account',
            $allocations[0]['destination_reference'],
        );
        self::assertNull($this->repository->findResult(
            $this->otherSupplierId,
            $this->revisionId,
            $this->employeeId,
        ));
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

    public function testForeignKeyViolationIsNotMisclassifiedAsIdempotentReplay(): void
    {
        $result = (new PayrollNetCalculator())->calculate(new PayrollNetInput(
            personReference: (string) PHP_INT_MAX,
            relationships: [
                new NetRelationshipIncome('employment-synthetic', 100_000, 0),
            ],
            employeeSocialMinorUnits: 0,
            employeeHealthMinorUnits: 0,
            advanceTaxMinorUnits: 0,
            withholdingTaxMinorUnits: 0,
            taxBonusMinorUnits: 0,
            correctionMinorUnits: 0,
            voluntaryDeductionCapacityMinorUnits: 100_000,
            deductions: [],
        ));
        $payout = (new PayoutAllocationService())->allocate(
            $result->netPayableMinorUnits,
            [PayoutAllocationRequest::remainder(
                'primary',
                'cash',
                null,
                1,
            )],
        );

        $this->expectException(PDOException::class);
        $this->repository->saveCalculation(
            $this->supplierId,
            $this->revisionId,
            PHP_INT_MAX,
            $result,
            $payout,
        );
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

    public function testCannotPersistResultForDifferentEmployeeIdentity(): void
    {
        $result = (new PayrollNetCalculator())->calculate(new PayrollNetInput(
            personReference: 'different-synthetic-person',
            relationships: [
                new NetRelationshipIncome('employment-synthetic', 100_000, 0),
            ],
            employeeSocialMinorUnits: 0,
            employeeHealthMinorUnits: 0,
            advanceTaxMinorUnits: 0,
            withholdingTaxMinorUnits: 0,
            taxBonusMinorUnits: 0,
            correctionMinorUnits: 0,
            voluntaryDeductionCapacityMinorUnits: 100_000,
            deductions: [],
        ));
        $payout = (new PayoutAllocationService())->allocate(
            $result->netPayableMinorUnits,
            [PayoutAllocationRequest::remainder('cash', 'cash', null, 1)],
        );

        $this->expectException(\DomainException::class);
        $this->repository->saveCalculation(
            $this->supplierId,
            $this->revisionId,
            $this->employeeId,
            $result,
            $payout,
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
