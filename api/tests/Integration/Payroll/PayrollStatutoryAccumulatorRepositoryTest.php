<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use DomainException;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorUnavailableException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollStatutoryAccumulatorRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollStatutoryAccumulatorRepository $repository;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        $repository = $container->get(PayrollStatutoryAccumulatorRepository::class);
        if (!$db instanceof Connection
            || !$repository instanceof PayrollStatutoryAccumulatorRepository
        ) {
            throw new \RuntimeException('Repozitář zákonných kumulací není dostupný.');
        }
        $this->db = $db;
        $this->repository = $repository;
        $pdo = $db->pdo();
        $sourceSupplierId = $this->firstSupplierId($pdo);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->createEmployee($pdo, $this->supplierId);
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

    public function testMissingExplicitOpeningBalanceFailsClosed(): void
    {
        $this->expectException(PayrollStatutoryAccumulatorUnavailableException::class);
        $this->repository->stateBeforePeriod(
            $this->supplierId,
            $this->employeeId,
            2026,
            '2026-01-01',
            'social_insurance',
        );
    }

    public function testOpeningBalanceIsIdempotentAndSafelyVersioned(): void
    {
        $openingId = $this->repository->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'social_insurance',
            ['assessment_base_minor_units' => 120_000],
            'synthetic-migration-certificate',
            ['verified' => true],
            'synthetic-opening-social-v1',
        );
        self::assertSame($openingId, $this->repository->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'social_insurance',
            ['assessment_base_minor_units' => 120_000],
            'synthetic-migration-certificate',
            ['verified' => true],
            'synthetic-opening-social-v1',
        ));

        $replacementId = $this->repository->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'social_insurance',
            ['assessment_base_minor_units' => 125_000],
            'synthetic-corrected-certificate',
            ['verified' => true, 'reason' => 'synthetic correction'],
            'synthetic-opening-social-v2',
            $openingId,
        );

        self::assertNotSame($openingId, $replacementId);
        $state = $this->repository->stateBeforePeriod(
            $this->supplierId,
            $this->employeeId,
            2026,
            '2026-01-01',
            'social_insurance',
        );
        self::assertSame($replacementId, $state['opening_balance']['id']);
        self::assertSame(
            ['assessment_base_minor_units' => 125_000],
            $state['totals'],
        );
        self::assertSame([], $state['approved_results']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $state['snapshot_hash']);
    }

    public function testCorrectionReplacesOriginalApprovedRevisionWithoutDoubleCounting(): void
    {
        $this->appendZeroTaxOpening();
        $january = $this->createApprovedRevision('2026-01-01');
        $januaryResultHash = $this->createStatutoryPersonResult(
            $january,
            'income_tax',
            'calculated',
        );
        $januaryEntryId = $this->repository->appendApprovedResult(
            $this->supplierId,
            $january,
            $this->employeeId,
            'income_tax',
            $this->taxContribution(1, 100_000, 15_000),
            $januaryResultHash,
        );
        self::assertSame($januaryEntryId, $this->repository->appendApprovedResult(
            $this->supplierId,
            $january,
            $this->employeeId,
            'income_tax',
            $this->taxContribution(1, 100_000, 15_000),
            $januaryResultHash,
        ));

        $correction = $this->createApprovedRevision(
            '2026-01-01',
            'correction',
            $january,
        );
        $correctionResultHash = $this->createStatutoryPersonResult(
            $correction,
            'income_tax',
            'calculated',
        );
        $correctedEntryId = $this->repository->appendApprovedResult(
            $this->supplierId,
            $correction,
            $this->employeeId,
            'income_tax',
            $this->taxContribution(1, 110_000, 16_500),
            $correctionResultHash,
        );
        $february = $this->createApprovedRevision('2026-02-01');

        $state = $this->repository->stateBeforeRevision(
            $this->supplierId,
            $this->employeeId,
            $february,
            'income_tax',
        );

        self::assertSame('2026-02-01', $state['before_period_start']);
        self::assertSame(1, $state['totals']['completed_months']);
        self::assertSame(110_000, $state['totals']['advance_base_minor_units']);
        self::assertSame(16_500, $state['totals']['advance_tax_minor_units']);
        self::assertSame([$correctedEntryId], array_column(
            $state['approved_results'],
            'id',
        ));
        self::assertNotContains($januaryEntryId, array_column(
            $state['approved_results'],
            'id',
        ));
        self::assertSame($february, $state['before_revision_id']);
    }

    public function testCorrectionAddingEmployeeStartsHisPeriodContribution(): void
    {
        $this->appendZeroTaxOpening();
        $september = $this->createApprovedRevision('2026-09-01');
        $this->repository->appendApprovedResult(
            $this->supplierId,
            $september,
            $this->employeeId,
            'income_tax',
            $this->taxContribution(1, 100_000, 15_000),
            $this->createStatutoryPersonResult($september, 'income_tax', 'calculated'),
        );

        $addedEmployeeId = $this->createEmployee($this->db->pdo(), $this->supplierId);
        $this->repository->appendOpeningBalance(
            $this->supplierId,
            $addedEmployeeId,
            2026,
            'income_tax',
            $this->taxContribution(0, 0, 0),
            'synthetic-empty-opening',
            ['verified' => true],
            'synthetic-empty-tax-opening-added',
        );
        $correction = $this->createApprovedRevision(
            '2026-09-01',
            'correction',
            $september,
            employeeIds: [$this->employeeId, $addedEmployeeId],
        );
        $addedEntryId = $this->repository->appendApprovedResult(
            $this->supplierId,
            $correction,
            $addedEmployeeId,
            'income_tax',
            $this->taxContribution(1, 40_000, 6_000),
            $this->createStatutoryPersonResult(
                $correction,
                'income_tax',
                'calculated',
                $addedEmployeeId,
            ),
        );

        $state = $this->repository->stateForYear(
            $this->supplierId,
            $addedEmployeeId,
            2026,
            'income_tax',
        );
        self::assertSame([$addedEntryId], array_column($state['approved_results'], 'id'));
        self::assertNull($state['approved_results'][0]['replaces_entry_id']);
        self::assertSame(40_000, $state['totals']['advance_base_minor_units']);
    }

    public function testCorrectionNotFollowingExistingPeriodContributionIsRejected(): void
    {
        $this->appendZeroTaxOpening();
        $september = $this->createApprovedRevision('2026-09-01');
        $this->repository->appendApprovedResult(
            $this->supplierId,
            $september,
            $this->employeeId,
            'income_tax',
            $this->taxContribution(1, 100_000, 15_000),
            $this->createStatutoryPersonResult($september, 'income_tax', 'calculated'),
        );
        $withoutEntry = $this->createApprovedRevision('2026-09-01', 'correction', $september);
        $correction = $this->createApprovedRevision('2026-09-01', 'correction', $withoutEntry);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Opravná revize nenavazuje');
        $this->repository->appendApprovedResult(
            $this->supplierId,
            $correction,
            $this->employeeId,
            'income_tax',
            $this->taxContribution(1, 110_000, 16_500),
            $this->createStatutoryPersonResult($correction, 'income_tax', 'calculated'),
        );
    }

    public function testSamePeriodRevisionIsExcludedFromItsOwnPriorState(): void
    {
        $this->appendZeroTaxOpening();
        $january = $this->createApprovedRevision('2026-01-01');
        $januaryResultHash = $this->createStatutoryPersonResult(
            $january,
            'income_tax',
            'calculated',
        );
        $this->repository->appendApprovedResult(
            $this->supplierId,
            $january,
            $this->employeeId,
            'income_tax',
            $this->taxContribution(1, 100_000, 15_000),
            $januaryResultHash,
        );
        $correction = $this->createApprovedRevision(
            '2026-01-01',
            'correction',
            $january,
        );

        $state = $this->repository->stateBeforeRevision(
            $this->supplierId,
            $this->employeeId,
            $correction,
            'income_tax',
        );

        self::assertSame(0, $state['totals']['completed_months']);
        self::assertSame([], $state['approved_results']);
    }

    public function testUnapprovedRevisionAndCrossTenantEmployeeAreRejected(): void
    {
        $revisionId = $this->createApprovedRevision('2026-03-01', status: 'calculated');

        try {
            $this->repository->appendApprovedResult(
                $this->supplierId,
                $revisionId,
                $this->employeeId,
                'social_insurance',
                ['assessment_base_minor_units' => 100_000],
                str_repeat('d', 64),
            );
            self::fail('Neschválená revize nesmí vstoupit do kumulace.');
        } catch (DomainException) {
        }

        $this->expectException(DomainException::class);
        $this->repository->appendOpeningBalance(
            $this->otherSupplierId,
            $this->employeeId,
            2026,
            'social_insurance',
            ['assessment_base_minor_units' => 0],
            'synthetic-cross-tenant',
            [],
            'synthetic-cross-tenant-opening',
        );
    }

    public function testArbitraryPersonResultHashCannotFeedAccumulator(): void
    {
        $revisionId = $this->createApprovedRevision('2026-04-01');
        $authoritativeHash = $this->createStatutoryPersonResult(
            $revisionId,
            'social_insurance',
            'calculated',
        );
        self::assertNotSame(str_repeat('1', 64), $authoritativeHash);

        $this->expectException(DomainException::class);
        $this->repository->appendApprovedResult(
            $this->supplierId,
            $revisionId,
            $this->employeeId,
            'social_insurance',
            ['assessment_base_minor_units' => 100_000],
            str_repeat('1', 64),
        );
    }

    public function testOnlyCalculatedPersonResultCanFeedAccumulator(): void
    {
        $revisionId = $this->createApprovedRevision('2026-05-01');
        $authoritativeHash = $this->createStatutoryPersonResult(
            $revisionId,
            'social_insurance',
            'manual_review',
        );

        $this->expectException(PayrollStatutoryAccumulatorUnavailableException::class);
        $this->repository->appendApprovedResult(
            $this->supplierId,
            $revisionId,
            $this->employeeId,
            'social_insurance',
            ['assessment_base_minor_units' => 100_000],
            $authoritativeHash,
        );
    }

    private function appendZeroTaxOpening(): void
    {
        $this->repository->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'income_tax',
            $this->taxContribution(0, 0, 0),
            'synthetic-empty-opening',
            ['verified' => true],
            'synthetic-empty-tax-opening',
        );
    }

    /** @return array<string,int> */
    private function taxContribution(
        int $completedMonths,
        int $advanceBaseMinor,
        int $advanceTaxMinor,
    ): array {
        return [
            'completed_months' => $completedMonths,
            'advance_base_minor_units' => $advanceBaseMinor,
            'withholding_base_minor_units' => 0,
            'advance_tax_minor_units' => $advanceTaxMinor,
            'withholding_tax_minor_units' => 0,
            'applied_non_refundable_credits_minor_units' => 0,
            'applied_child_credit_minor_units' => 0,
            'tax_bonus_minor_units' => 0,
            'bonus_qualifying_income_minor_units' => $advanceBaseMinor,
        ];
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

    private function createStatutoryPersonResult(
        int $revisionId,
        string $calculationKind,
        string $resultStatus,
        ?int $employeeId = null,
    ): string {
        $pdo = $this->db->pdo();
        $inputJson = '{"synthetic_input":true}';
        $resultJson = '{"synthetic_result":true}';
        $inputHash = hash('sha256', $inputJson);
        $resultHash = hash('sha256', $resultJson);
        $pdo->prepare(
            'INSERT INTO payroll_statutory_results
                (supplier_id, revision_id, calculation_kind, schema_version,
                 result_status, ruleset_id, ruleset_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, result_set_hash)
             VALUES (?, ?, ?, "synthetic.v1", ?, "synthetic-ruleset", ?,
                     ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $revisionId,
            $calculationKind,
            $resultStatus,
            str_repeat('2', 64),
            $inputJson,
            $inputHash,
            $resultJson,
            $resultHash,
            str_repeat('3', 64),
        ]);
        $statutoryResultId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_statutory_person_results
                (supplier_id, statutory_result_id, revision_id,
                 calculation_kind, employee_id, result_status,
                 input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $statutoryResultId,
            $revisionId,
            $calculationKind,
            $employeeId ?? $this->employeeId,
            $resultStatus,
            $inputJson,
            $inputHash,
            $resultJson,
            $resultHash,
        ]);

        return $resultHash;
    }

    /** @param list<int>|null $employeeIds */
    private function createApprovedRevision(
        string $periodStart,
        string $revisionKind = 'regular',
        ?int $previousRevisionId = null,
        string $status = 'approved',
        ?array $employeeIds = null,
    ): int {
        $pdo = $this->db->pdo();
        $revisionNo = 1;
        if ($previousRevisionId === null) {
            $pdo->prepare(
                'INSERT INTO payroll_runs
                    (supplier_id, period_start, payment_date, status, current_revision_no)
                 VALUES (?, ?, LAST_DAY(?), "approved", 1)'
            )->execute([$this->supplierId, $periodStart, $periodStart]);
            $runId = (int) $pdo->lastInsertId();
        } else {
            $previous = $pdo->prepare(
                'SELECT revision.run_id, revision.revision_no, run.period_start
                   FROM payroll_run_revisions revision
                   JOIN payroll_runs run
                     ON run.supplier_id = revision.supplier_id
                    AND run.id = revision.run_id
                  WHERE revision.supplier_id = ? AND revision.id = ?'
            );
            $previous->execute([$this->supplierId, $previousRevisionId]);
            $previousRow = $previous->fetch(PDO::FETCH_ASSOC);
            if (!is_array($previousRow)
                || $previousRow['period_start'] !== $periodStart
            ) {
                throw new \RuntimeException('Syntetická oprava nenavazuje na stejné období.');
            }
            $runId = (int) $previousRow['run_id'];
            $revisionNo = (int) $previousRow['revision_no'] + 1;
        }
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version, ruleset_manifest_hash,
                 input_snapshot_json, input_snapshot_hash, idempotency_key_hash,
                 approved_at)
             VALUES (?, ?, ?, ?, ?, ?, "payroll-run-input.v1", ?,
                     "{}", ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $runId,
            $revisionNo,
            $previousRevisionId,
            $revisionKind,
            $status,
            str_repeat('e', 64),
            str_repeat('f', 64),
            hash('sha256', implode(':', [
                'synthetic-revision',
                $periodStart,
                $revisionKind,
                (string) ($previousRevisionId ?? 0),
                (string) random_int(1, PHP_INT_MAX),
            ]), true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $person = $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")'
        );
        foreach ($employeeIds ?? [$this->employeeId] as $employeeId) {
            $person->execute([$this->supplierId, $revisionId, $employeeId]);
        }

        return $revisionId;
    }

    private function firstSupplierId(PDO $pdo): int
    {
        $stmt = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }
}
