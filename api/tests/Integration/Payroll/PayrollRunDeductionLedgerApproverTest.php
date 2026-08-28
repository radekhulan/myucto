<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Run\PayrollRunDeductionLedgerApprover;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollRunDeductionLedgerApproverTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRunDeductionLedgerApprover $approver;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $actorUserId;
    private int $agreementId;
    private int $runId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        $approver = $container->get(PayrollRunDeductionLedgerApprover::class);
        if (!$db instanceof Connection
            || !$approver instanceof PayrollRunDeductionLedgerApprover
        ) {
            throw new \RuntimeException('Služby schválení srážek nejsou dostupné.');
        }
        $this->db = $db;
        foreach ([
            'payroll_deduction_agreements',
            'payroll_deduction_ledger',
            'payroll_run_revisions',
        ] as $table) {
            if (!$db->hasTable($table)) {
                $this->markTestSkipped('Migrace mzdových srážek neproběhla.');
            }
        }
        $pdo = $db->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn();
        $this->actorUserId = (int) $pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn();
        if ($sourceSupplierId <= 0 || $this->actorUserId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->createEmployee($pdo, $this->supplierId);
        $this->agreementId = $this->createAgreement(
            $pdo,
            $this->supplierId,
            $this->employeeId,
        );
        $this->runId = $this->createRun($pdo, $this->supplierId);
        $this->approver = $approver;
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

    public function testApprovalIsIdempotentAndCorrectionWritesOnlyDeltaWithPartialReversals(): void
    {
        $firstRevisionId = $this->createRevision(
            revisionNo: 1,
            previousRevisionId: null,
            revisionKind: 'regular',
            status: 'approved',
            inputSnapshot: $this->inputSnapshot(0),
            resultSnapshot: $this->resultSnapshot(6_000),
        );

        $this->approver->approve(
            $this->supplierId,
            $firstRevisionId,
            $this->actorUserId,
        );
        $this->approver->approve(
            $this->supplierId,
            $firstRevisionId,
            $this->actorUserId,
        );
        self::assertSame(6_000, $this->withheldTotal());
        self::assertSame([6_000], $this->ledgerAmounts());

        $secondInput = $this->approver->prepareCorrectionSnapshot(
            $this->supplierId,
            $this->runId,
            $this->inputSnapshot(6_000),
        );
        self::assertSame(
            0,
            $secondInput['people'][0]['deduction_agreements'][0]
                ['withheld_total_minor'],
        );
        $secondRevisionId = $this->createRevision(
            revisionNo: 2,
            previousRevisionId: $firstRevisionId,
            revisionKind: 'correction',
            status: 'approved',
            inputSnapshot: $secondInput,
            resultSnapshot: $this->resultSnapshot(9_000),
        );
        $this->approver->approve(
            $this->supplierId,
            $secondRevisionId,
            $this->actorUserId,
        );
        self::assertSame(9_000, $this->withheldTotal());
        self::assertSame([6_000, 3_000], $this->ledgerAmounts());

        $thirdInput = $this->approver->prepareCorrectionSnapshot(
            $this->supplierId,
            $this->runId,
            $this->inputSnapshot(9_000),
        );
        self::assertSame(
            0,
            $thirdInput['people'][0]['deduction_agreements'][0]
                ['withheld_total_minor'],
        );
        $thirdRevisionId = $this->createRevision(
            revisionNo: 3,
            previousRevisionId: $secondRevisionId,
            revisionKind: 'correction',
            status: 'approved',
            inputSnapshot: $thirdInput,
            resultSnapshot: $this->resultSnapshot(2_000),
        );
        $this->approver->approve(
            $this->supplierId,
            $thirdRevisionId,
            $this->actorUserId,
        );
        self::assertSame(2_000, $this->withheldTotal());
        self::assertSame([6_000, 3_000, -6_000, -1_000], $this->ledgerAmounts());
        self::assertCount(2, array_unique(array_filter(array_column(
            $this->ledgerRows(),
            'source_ledger_id',
        ))));

        $this->approver->approve(
            $this->supplierId,
            $thirdRevisionId,
            $this->actorUserId,
        );
        self::assertSame(2_000, $this->withheldTotal());
        self::assertSame([6_000, 3_000, -6_000, -1_000], $this->ledgerAmounts());
    }

    public function testRepeatedFullCorrectionDoesNotOscillateAndMissingAgreementReverses(): void
    {
        $firstRevisionId = $this->createRevision(
            revisionNo: 1,
            previousRevisionId: null,
            revisionKind: 'regular',
            status: 'approved',
            inputSnapshot: $this->inputSnapshot(0),
            resultSnapshot: $this->resultSnapshot(5_000),
        );
        $this->approver->approve(
            $this->supplierId,
            $firstRevisionId,
            $this->actorUserId,
        );

        $secondInput = $this->approver->prepareCorrectionSnapshot(
            $this->supplierId,
            $this->runId,
            $this->inputSnapshot(5_000),
        );
        $secondRevisionId = $this->createRevision(
            revisionNo: 2,
            previousRevisionId: $firstRevisionId,
            revisionKind: 'correction',
            status: 'approved',
            inputSnapshot: $secondInput,
            resultSnapshot: $this->resultSnapshot(5_000),
        );
        $this->approver->approve(
            $this->supplierId,
            $secondRevisionId,
            $this->actorUserId,
        );
        self::assertSame(5_000, $this->withheldTotal());
        self::assertSame([5_000], $this->ledgerAmounts());

        $missingAgreementInput = $this->inputSnapshot(5_000);
        $missingAgreementInput['people'][0]['deduction_agreements'] = [];
        $thirdRevisionId = $this->createRevision(
            revisionNo: 3,
            previousRevisionId: $secondRevisionId,
            revisionKind: 'correction',
            status: 'approved',
            inputSnapshot: $this->approver->prepareCorrectionSnapshot(
                $this->supplierId,
                $this->runId,
                $missingAgreementInput,
            ),
            resultSnapshot: $this->resultSnapshot(null),
        );
        $this->approver->approve(
            $this->supplierId,
            $thirdRevisionId,
            $this->actorUserId,
        );

        self::assertSame(0, $this->withheldTotal());
        self::assertSame([5_000, -5_000], $this->ledgerAmounts());
    }

    public function testRegularRevisionMayFollowAnUnapprovedCancelledAttempt(): void
    {
        $abandonedRevisionId = $this->createRevision(
            revisionNo: 1,
            previousRevisionId: null,
            revisionKind: 'regular',
            status: 'calculated',
            inputSnapshot: $this->inputSnapshot(0),
            resultSnapshot: $this->resultSnapshot(1_000),
        );
        $approvedRevisionId = $this->createRevision(
            revisionNo: 2,
            previousRevisionId: $abandonedRevisionId,
            revisionKind: 'regular',
            status: 'approved',
            inputSnapshot: $this->inputSnapshot(0),
            resultSnapshot: $this->resultSnapshot(2_000),
        );

        $this->approver->approve(
            $this->supplierId,
            $approvedRevisionId,
            $this->actorUserId,
        );

        self::assertSame(2_000, $this->withheldTotal());
        self::assertSame([2_000], $this->ledgerAmounts());
    }

    public function testRegularRevisionCannotPointToAnAttemptFromAnotherRun(): void
    {
        $originalRunId = $this->runId;
        $this->runId = $this->createRun(
            $this->db->pdo(),
            $this->supplierId,
            '2026-07-01',
            '2026-07-31',
        );
        $foreignRevisionId = $this->createRevision(
            revisionNo: 90,
            previousRevisionId: null,
            revisionKind: 'regular',
            status: 'calculated',
            inputSnapshot: $this->inputSnapshot(0),
            resultSnapshot: $this->resultSnapshot(1_000),
        );
        $this->runId = $originalRunId;
        $revisionId = $this->createRevision(
            revisionNo: 91,
            previousRevisionId: $foreignRevisionId,
            revisionKind: 'regular',
            status: 'approved',
            inputSnapshot: $this->inputSnapshot(0),
            resultSnapshot: $this->resultSnapshot(2_000),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('stejného mzdového běhu');
        try {
            $this->approver->approve(
                $this->supplierId,
                $revisionId,
                $this->actorUserId,
            );
        } finally {
            self::assertSame(0, $this->withheldTotal());
            self::assertSame([], $this->ledgerAmounts());
        }
    }

    public function testRegularRevisionCannotFollowAnApprovedRevision(): void
    {
        $previousRevisionId = $this->createRevision(
            revisionNo: 1,
            previousRevisionId: null,
            revisionKind: 'regular',
            status: 'approved',
            inputSnapshot: $this->inputSnapshot(0),
            resultSnapshot: $this->resultSnapshot(1_000),
        );
        $revisionId = $this->createRevision(
            revisionNo: 2,
            previousRevisionId: $previousRevisionId,
            revisionKind: 'regular',
            status: 'approved',
            inputSnapshot: $this->inputSnapshot(0),
            resultSnapshot: $this->resultSnapshot(2_000),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('dříve schválené srážky');
        try {
            $this->approver->approve(
                $this->supplierId,
                $revisionId,
                $this->actorUserId,
            );
        } finally {
            self::assertSame(0, $this->withheldTotal());
            self::assertSame([], $this->ledgerAmounts());
        }
    }

    public function testRegularRevisionCannotHideCancelledCorrectionLineage(): void
    {
        $approvedRevisionId = $this->createRevision(
            revisionNo: 1,
            previousRevisionId: null,
            revisionKind: 'regular',
            status: 'approved',
            inputSnapshot: $this->inputSnapshot(0),
            resultSnapshot: $this->resultSnapshot(1_000),
        );
        $correctionAttemptId = $this->createRevision(
            revisionNo: 2,
            previousRevisionId: $approvedRevisionId,
            revisionKind: 'correction',
            status: 'calculated',
            inputSnapshot: $this->inputSnapshot(0),
            resultSnapshot: $this->resultSnapshot(2_000),
        );
        $invalidRegularId = $this->createRevision(
            revisionNo: 3,
            previousRevisionId: $correctionAttemptId,
            revisionKind: 'regular',
            status: 'approved',
            inputSnapshot: $this->inputSnapshot(0),
            resultSnapshot: $this->resultSnapshot(3_000),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('dříve schválené srážky');
        try {
            $this->approver->approve(
                $this->supplierId,
                $invalidRegularId,
                $this->actorUserId,
            );
        } finally {
            self::assertSame(0, $this->withheldTotal());
            self::assertSame([], $this->ledgerAmounts());
        }
    }

    public function testRegularRevisionCannotFollowSupersededAttempt(): void
    {
        $supersededRevisionId = $this->createRevision(
            revisionNo: 1,
            previousRevisionId: null,
            revisionKind: 'regular',
            status: 'superseded',
            inputSnapshot: $this->inputSnapshot(0),
            resultSnapshot: $this->resultSnapshot(1_000),
        );
        $revisionId = $this->createRevision(
            revisionNo: 2,
            previousRevisionId: $supersededRevisionId,
            revisionKind: 'regular',
            status: 'approved',
            inputSnapshot: $this->inputSnapshot(0),
            resultSnapshot: $this->resultSnapshot(2_000),
        );

        $this->expectException(\DomainException::class);
        try {
            $this->approver->approve(
                $this->supplierId,
                $revisionId,
                $this->actorUserId,
            );
        } finally {
            self::assertSame(0, $this->withheldTotal());
            self::assertSame([], $this->ledgerAmounts());
        }
    }

    public function testRevisionCannotBeApprovedAcrossTenantBoundary(): void
    {
        $revisionId = $this->createRevision(
            revisionNo: 1,
            previousRevisionId: null,
            revisionKind: 'regular',
            status: 'approved',
            inputSnapshot: $this->inputSnapshot(0),
            resultSnapshot: $this->resultSnapshot(1_000),
        );

        $this->expectException(\OutOfBoundsException::class);
        try {
            $this->approver->approve(
                $this->otherSupplierId,
                $revisionId,
                $this->actorUserId,
            );
        } finally {
            self::assertSame(0, $this->withheldTotal());
            self::assertSame([], $this->ledgerAmounts());
        }
    }

    public function testAgreementLimitFailureRollsBackLedgerAtomically(): void
    {
        $input = $this->inputSnapshot(0);
        $input['people'][0]['deduction_agreements'][0]['requested_minor'] = 25_000;
        $result = $this->resultSnapshot(9_000);
        $result['people'][0]['statutory']['net_pay']['deductions'][0]
            ['requested_minor_units'] = 25_000;
        $result['people'][0]['statutory']['net_pay']['deductions'][0]
            ['applied_minor_units'] = 25_000;
        $result['people'][0]['statutory']['net_pay']['deductions'][0]
            ['unapplied_minor_units'] = 0;
        $revisionId = $this->createRevision(
            revisionNo: 1,
            previousRevisionId: null,
            revisionKind: 'regular',
            status: 'approved',
            inputSnapshot: $input,
            resultSnapshot: $result,
        );

        try {
            $this->approver->approve(
                $this->supplierId,
                $revisionId,
                $this->actorUserId,
            );
            self::fail('Srážka nad celkový limit musí selhat.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(0, $this->withheldTotal());
        self::assertSame([], $this->ledgerAmounts());
    }

    public function testOverflowingSnapshotIdentifierFailsClosed(): void
    {
        $result = $this->resultSnapshot(1_000);
        $result['people'][0]['statutory']['net_pay']['deductions'][0]
            ['deduction_reference'] = 'agreement:9223372036854775808';
        $revisionId = $this->createRevision(
            revisionNo: 1,
            previousRevisionId: null,
            revisionKind: 'regular',
            status: 'approved',
            inputSnapshot: $this->inputSnapshot(0),
            resultSnapshot: $result,
        );

        try {
            $this->approver->approve(
                $this->supplierId,
                $revisionId,
                $this->actorUserId,
            );
            self::fail('Identifikátor mimo číselný rozsah musí selhat.');
        } catch (\OverflowException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(0, $this->withheldTotal());
        self::assertSame([], $this->ledgerAmounts());
    }

    public function testApprovedLegacySnapshotWithoutDeductionSectionsIsNoOp(): void
    {
        $revisionId = $this->createRevision(
            revisionNo: 1,
            previousRevisionId: null,
            revisionKind: 'regular',
            status: 'approved',
            inputSnapshot: [
                'schema_version' => 'payroll-run-input.v2',
            ],
            resultSnapshot: [
                'schema_version' => 'payroll-run-result.v2',
            ],
        );

        $this->approver->approve(
            $this->supplierId,
            $revisionId,
            $this->actorUserId,
        );

        self::assertSame(0, $this->withheldTotal());
        self::assertSame([], $this->ledgerAmounts());
    }

    /** @return array<string,mixed> */
    private function inputSnapshot(int $withheldTotal): array
    {
        return [
            'schema_version' => 'payroll-run-input.v2',
            'people' => [[
                'employee' => ['id' => $this->employeeId],
                'deduction_agreements' => [[
                    'id' => $this->agreementId,
                    'agreement_reference' => 'SYNTHETIC-DEDUCTION',
                    'priority_no' => 10,
                    'requested_minor' => 9_000,
                    'total_limit_minor' => 20_000,
                    'withheld_total_minor' => $withheldTotal,
                ]],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function resultSnapshot(?int $applied): array
    {
        $deductions = $applied === null ? [] : [[
            'deduction_reference' => "agreement:{$this->agreementId}",
            'priority' => 10,
            'requested_minor_units' => 9_000,
            'applied_minor_units' => $applied,
            'unapplied_minor_units' => 9_000 - $applied,
            'active' => true,
        ]];

        return [
            'schema_version' => 'payroll-run-result.v2',
            'people' => [[
                'employee_id' => $this->employeeId,
                'statutory' => [
                    'net_pay' => [
                        'deductions' => $deductions,
                    ],
                ],
            ]],
        ];
    }

    private function createEmployee(PDO $pdo, int $supplierId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba", "employee", "hpp",
                     1, 1, 0, 10000, 0, 1)'
        )->execute([$supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function createAgreement(PDO $pdo, int $supplierId, int $employeeId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_deduction_agreements
                (supplier_id, employee_id, agreement_reference, title,
                 deduction_kind, status, priority_no, requested_minor,
                 total_limit_minor, withheld_total_minor, valid_from)
             VALUES (?, ?, "SYNTHETIC-DEDUCTION", "Syntetická srážka",
                     "other", "active", 10, 9000, 20000, 0, "2026-01-01")'
        )->execute([$supplierId, $employeeId]);

        return (int) $pdo->lastInsertId();
    }

    private function createRun(
        PDO $pdo,
        int $supplierId,
        string $periodStart = '2026-06-01',
        string $paymentDate = '2026-06-30',
    ): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, current_revision_no)
             VALUES (?, ?, ?, 0)'
        )->execute([$supplierId, $periodStart, $paymentDate]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $inputSnapshot
     * @param array<string,mixed> $resultSnapshot
     */
    private function createRevision(
        int $revisionNo,
        ?int $previousRevisionId,
        string $revisionKind,
        string $status,
        array $inputSnapshot,
        array $resultSnapshot,
    ): int {
        $inputJson = json_encode($inputSnapshot, JSON_THROW_ON_ERROR);
        $resultJson = json_encode($resultSnapshot, JSON_THROW_ON_ERROR);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version, ruleset_manifest_hash,
                 input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash,
                 idempotency_key_hash, superseded_at)
             VALUES (?, ?, ?, ?, ?, ?, "payroll-run-input.v2", ?,
                     ?, ?, ?, ?, ?,
                     -- Odsunutá revize musí nést razítko odsunutí (migrace
                     -- 1621). Bez něj by to nebyla revize, jaká v provozu
                     -- vzniká, ale nedoložený stav.
                     IF(? = "superseded", NOW(), NULL))'
        )->execute([
            $this->supplierId,
            $this->runId,
            $revisionNo,
            $previousRevisionId,
            $revisionKind,
            $status,
            str_repeat('a', 64),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            hash('sha256', $resultJson),
            hash('sha256', "synthetic-revision-{$revisionNo}", true),
            $status,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function withheldTotal(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT withheld_total_minor
               FROM payroll_deduction_agreements
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $this->agreementId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function ledgerRows(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, amount_minor, source_ledger_id
               FROM payroll_deduction_ledger
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY id'
        );
        $stmt->execute([$this->supplierId, $this->employeeId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<int> */
    private function ledgerAmounts(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['amount_minor'],
            $this->ledgerRows(),
        );
    }
}
