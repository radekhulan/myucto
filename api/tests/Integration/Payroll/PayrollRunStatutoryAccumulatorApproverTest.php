<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorUnavailableException;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculationPipeline;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunStatutoryAccumulatorApprover;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollRunStatutoryAccumulatorApproverTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollStatutoryAccumulatorRepository $accumulators;
    private PayrollStatutoryResultRepository $results;
    private PayrollRunStatutoryAccumulatorApprover $approver;
    private PayrollRunCommandService $commands;
    private int $supplierId;
    private int $employeeId;
    /** @var list<int> */
    private array $actorUserIds;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $accumulators = $container->get(
            PayrollStatutoryAccumulatorRepository::class,
        );
        $results = $container->get(PayrollStatutoryResultRepository::class);
        $approver = $container->get(
            PayrollRunStatutoryAccumulatorApprover::class,
        );
        $commands = new PayrollRunCommandService(
            $db,
            $container->get(PayrollRunRepository::class),
            $container->get(PayrollRunSnapshotBuilder::class),
            $container->get(PayrollRunCalculationPipeline::class),
            $container->get(PayrollRunWorkflow::class),
            $container->get(PayrollPeriodOwnershipService::class),
        );
        if (!$db instanceof Connection
            || !$accumulators instanceof PayrollStatutoryAccumulatorRepository
            || !$results instanceof PayrollStatutoryResultRepository
            || !$approver instanceof PayrollRunStatutoryAccumulatorApprover
            || !$commands instanceof PayrollRunCommandService
        ) {
            throw new \RuntimeException(
                'Služby ročních mzdových kumulací nejsou dostupné.',
            );
        }
        foreach ([
            'payroll_statutory_results',
            'payroll_statutory_accumulator_openings',
            'payroll_statutory_accumulator_entries',
        ] as $table) {
            if (!$db->hasTable($table)) {
                $this->markTestSkipped('Migrace zákonných mzdových výsledků neproběhly.');
            }
        }

        $this->db = $db;
        $this->accumulators = $accumulators;
        $this->results = $results;
        $this->approver = $approver;
        $this->commands = $commands;
        $pdo = $db->pdo();
        $supplierQuery = $pdo->query(
            'SELECT MIN(id) FROM supplier',
        );
        if ($supplierQuery === false) {
            throw new \RuntimeException('Výchozí firmu se nepodařilo načíst.');
        }
        $sourceSupplierId = (int) $supplierQuery->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id = ?'
        )->execute([$this->supplierId]);
        $this->actorUserIds = [
            $this->createActor($pdo, 'calculator'),
            $this->createActor($pdo, 'reviewer'),
            $this->createActor($pdo, 'approver'),
        ];
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                start_period = VALUES(start_period)'
        )->execute([$this->supplierId, $this->actorUserIds[0]]);
        $this->employeeId = $this->createEmployee($pdo);
        $this->appendZeroOpenings();
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

    public function testApprovedCalculatedResultsAreStoredIdempotently(): void
    {
        $revisionId = $this->createRevision('2026-06-01', 'approved');
        $this->storeSocialResult($revisionId, 4_200_000, 'calculated');
        $this->storeIncomeTaxResult($revisionId, 'calculated');

        $this->approver->approve(
            $this->supplierId,
            $revisionId,
            $this->actorUserIds[2],
        );
        $this->approver->approve(
            $this->supplierId,
            $revisionId,
            $this->actorUserIds[2],
        );

        self::assertSame(2, $this->entryCount($revisionId));
        $social = $this->accumulators->stateBeforePeriod(
            $this->supplierId,
            $this->employeeId,
            2026,
            '2026-07-01',
            'social_insurance',
        );
        self::assertSame(
            4_200_000,
            $social['totals']['assessment_base_minor_units'],
        );
        $tax = $this->accumulators->stateBeforePeriod(
            $this->supplierId,
            $this->employeeId,
            2026,
            '2026-07-01',
            'income_tax',
        );
        self::assertSame(1, $tax['totals']['completed_months']);
        self::assertSame(4_200_000, $tax['totals']['advance_base_minor_units']);
        self::assertSame(356_700, $tax['totals']['advance_tax_minor_units']);
        self::assertSame(
            4_200_000,
            $tax['totals']['bonus_qualifying_income_minor_units'],
        );
    }

    public function testFailureOfOneDomainRollsBackAllAccumulatorEntries(): void
    {
        $revisionId = $this->createRevision('2026-07-01', 'approved');
        $this->storeSocialResult($revisionId, 4_500_000, 'calculated');
        $this->storeIncomeTaxResult($revisionId, 'manual_review');

        try {
            $this->approver->approve(
                $this->supplierId,
                $revisionId,
                $this->actorUserIds[2],
            );
            self::fail('Neověřený daňový výsledek nesmí vstoupit do kumulace.');
        } catch (PayrollStatutoryAccumulatorUnavailableException) {
        }

        self::assertSame(0, $this->entryCount($revisionId));
    }

    public function testReviewedRevisionCannotBeAccumulatedBeforeApproval(): void
    {
        $revisionId = $this->createRevision('2026-08-01', 'reviewed');
        $this->storeSocialResult($revisionId, 4_800_000, 'calculated');
        $this->storeIncomeTaxResult($revisionId, 'calculated');

        $this->expectException(\DomainException::class);
        $this->approver->approve(
            $this->supplierId,
            $revisionId,
            $this->actorUserIds[2],
        );
    }

    public function testApproveWorkflowPersistsAndReplaysAccumulatorEntries(): void
    {
        $revisionId = $this->createRevision('2026-09-01', 'reviewed');
        $runId = $this->runId($revisionId);
        $this->storeSocialResult($revisionId, 5_100_000, 'calculated');
        $this->storeIncomeTaxResult($revisionId, 'calculated');

        $approved = $this->commands->approve(
            $this->supplierId,
            $runId,
            1,
            'approve-annual-accumulators',
            $this->actorUserIds[2],
        );
        $replayed = $this->commands->approve(
            $this->supplierId,
            $runId,
            1,
            'approve-annual-accumulators',
            $this->actorUserIds[2],
        );

        self::assertSame('approved', $approved->run['status']);
        self::assertTrue($replayed->idempotentReplay);
        self::assertSame(2, $this->entryCount($revisionId));
    }

    public function testApproveWorkflowRollsBackRevisionAndPartialAccumulator(): void
    {
        $revisionId = $this->createRevision('2026-10-01', 'reviewed');
        $runId = $this->runId($revisionId);
        $this->storeSocialResult($revisionId, 5_400_000, 'calculated');

        try {
            $this->commands->approve(
                $this->supplierId,
                $runId,
                1,
                'approve-missing-tax-result',
                $this->actorUserIds[2],
            );
            self::fail('Neúplné zákonné výsledky nesmí schválit mzdový běh.');
        } catch (PayrollStatutoryAccumulatorUnavailableException) {
        }

        self::assertSame('reviewed', $this->revisionStatus($revisionId));
        self::assertSame('reviewed', $this->runStatus($runId));
        self::assertSame(0, $this->entryCount($revisionId));
        self::assertSame(0, $this->commandReceiptCount($runId));
    }

    private function appendZeroOpenings(): void
    {
        $this->accumulators->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'social_insurance',
            ['assessment_base_minor_units' => 0],
            'synthetic:social-opening',
            ['verified_zero' => true],
            "approval-social-opening:{$this->supplierId}",
            actorUserId: $this->actorUserIds[2],
        );
        $this->accumulators->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'income_tax',
            [
                'completed_months' => 0,
                'advance_base_minor_units' => 0,
                'withholding_base_minor_units' => 0,
                'advance_tax_minor_units' => 0,
                'withholding_tax_minor_units' => 0,
                'applied_non_refundable_credits_minor_units' => 0,
                'applied_child_credit_minor_units' => 0,
                'tax_bonus_minor_units' => 0,
                'bonus_qualifying_income_minor_units' => 0,
            ],
            'synthetic:tax-opening',
            ['verified_zero' => true],
            "approval-tax-opening:{$this->supplierId}",
            actorUserId: $this->actorUserIds[2],
        );
    }

    private function storeSocialResult(
        int $revisionId,
        int $cappedBaseMinorUnits,
        string $status,
    ): void {
        $this->storeResult(
            $revisionId,
            'social_insurance',
            $status,
            [
                'person_id' => "employee:{$this->employeeId}",
                'status' => $status,
                'capped_assessment_base_minor_units' => $cappedBaseMinorUnits,
            ],
        );
    }

    private function storeIncomeTaxResult(
        int $revisionId,
        string $status,
    ): void {
        $this->storeResult(
            $revisionId,
            'income_tax',
            $status,
            [
                'status' => $status,
                'employee_reference' => "employee:{$this->employeeId}",
                'advance_tax' => [
                    'taxable_income_minor_units' => 4_200_000,
                    'tax_after_credits_minor_units' => 356_700,
                    'tax_bonus_minor_units' => 0,
                ],
                'withholding_base_minor_units' => 0,
                'withholding_tax_minor_units' => 0,
                'applied_non_refundable_credits_minor_units' => 257_000,
                'applied_child_credit_minor_units' => 0,
            ],
        );
    }

    /** @param array<string,mixed> $personResult */
    private function storeResult(
        int $revisionId,
        string $kind,
        string $status,
        array $personResult,
    ): void {
        $this->results->store(
            $this->supplierId,
            $revisionId,
            $kind,
            "synthetic-{$kind}.v1",
            $status,
            "synthetic-{$kind}-ruleset",
            str_repeat($kind === 'income_tax' ? 'a' : 'b', 64),
            ['synthetic_input' => true],
            ['synthetic_result' => true],
            [[
                'employee_id' => $this->employeeId,
                'input_snapshot' => ['synthetic_person_input' => true],
                'relationships' => [],
                'result_snapshot' => $personResult,
                'result_status' => $status,
            ]],
            $this->actorUserIds[0],
        );
    }

    private function createRevision(string $periodStart, string $status): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, ?, LAST_DAY(?), ?, 1)'
        )->execute([
            $this->supplierId,
            $periodStart,
            $periodStart,
            $status,
        ]);
        $runId = (int) $pdo->lastInsertId();
        $inputJson = '{"schema_version":"payroll-run-input.v2"}';
        $resultJson = '{"people":[],"schema_version":"payroll-run-result.v2","statutory":{"status":"calculated"}}';
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, idempotency_key_hash,
                 result_snapshot_json, result_snapshot_hash,
                 calculated_by, calculated_at, reviewed_by, reviewed_at,
                 approved_by, approved_at)
             VALUES (?, ?, 1, "regular", ?, "payroll-run-input.v2", ?,
                     ?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), ?, ?)'
        )->execute([
            $this->supplierId,
            $runId,
            $status,
            str_repeat('c', 64),
            $inputJson,
            hash('sha256', $inputJson),
            hash(
                'sha256',
                "approval-test:{$this->supplierId}:{$periodStart}",
                true,
            ),
            $resultJson,
            hash('sha256', $resultJson),
            $this->actorUserIds[0],
            $this->actorUserIds[1],
            $status === 'approved' ? $this->actorUserIds[2] : null,
            $status === 'approved'
                ? (new \DateTimeImmutable())->format('Y-m-d H:i:s')
                : null,
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")'
        )->execute([
            $this->supplierId,
            $revisionId,
            $this->employeeId,
        ]);

        return $revisionId;
    }

    private function createEmployee(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba", "employee", "hpp",
                     1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function createActor(PDO $pdo, string $suffix): int
    {
        $pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, "x", "Syntetický schvalovatel",
                     "accountant", "cs", 1)'
        )->execute([
            "payroll-accumulator-approval-{$this->supplierId}-{$suffix}@example.invalid",
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function entryCount(int $revisionId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_statutory_accumulator_entries
              WHERE supplier_id = ? AND revision_id = ?'
        );
        $stmt->execute([$this->supplierId, $revisionId]);

        return (int) $stmt->fetchColumn();
    }

    private function runId(int $revisionId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT run_id
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $revisionId]);

        return (int) $stmt->fetchColumn();
    }

    private function revisionStatus(int $revisionId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $revisionId]);

        return (string) $stmt->fetchColumn();
    }

    private function runStatus(int $runId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status
               FROM payroll_runs
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $runId]);

        return (string) $stmt->fetchColumn();
    }

    private function commandReceiptCount(int $runId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_run_commands
              WHERE supplier_id = ? AND run_id = ?'
        );
        $stmt->execute([$this->supplierId, $runId]);

        return (int) $stmt->fetchColumn();
    }
}
