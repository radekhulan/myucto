<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxResult;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCalculationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthEmploymentKind;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthResult;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsurerSnapshotStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthJurisdictionEvidence;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibility;
use MyInvoice\Service\Payroll\HealthInsurance\HealthParticipationDecision;
use MyInvoice\Service\Payroll\HealthInsurance\HealthParticipationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthPersonMonthResult;
use MyInvoice\Service\Payroll\HealthInsurance\HealthRelationshipResult;
use MyInvoice\Service\Payroll\IncomeTax\AnnualTaxAccumulatorResult;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentIncomeTaxPolicy2026;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxResult;
use MyInvoice\Service\Payroll\IncomeTax\RelationshipTaxResult;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxRegime;
use MyInvoice\Service\Payroll\Net\NetRelationshipIncome;
use MyInvoice\Service\Payroll\Net\PayrollNetPolicyV1;
use MyInvoice\Service\Payroll\Net\PayrollNetResult;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunStatutoryResultPersister;
use MyInvoice\Service\Payroll\Run\PayrollStatutoryBlockedPerson;
use MyInvoice\Service\Payroll\SocialInsurance\SocialCalculationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialDiscountEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmployerRateCategory;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmploymentKind;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthResult;
use MyInvoice\Service\Payroll\SocialInsurance\SocialJurisdictionEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationDecision;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPersonMonthResult;
use MyInvoice\Service\Payroll\SocialInsurance\SocialRelationshipResult;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollRunStatutoryResultPersisterTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const SOCIAL_RULESET_ID = 'synthetic-social-2026';
    private const HEALTH_RULESET_ID = 'synthetic-health-2026';
    private const TAX_RULESET_ID = 'synthetic-tax-2026';
    private const SOCIAL_RULESET_HASH =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const HEALTH_RULESET_HASH =
        'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const TAX_RULESET_HASH =
        'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private Connection $db;
    private PayrollStatutoryResultRepository $repository;
    private PayrollRunStatutoryResultPersister $persister;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $revisionId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        foreach ([
            'payroll_statutory_results',
            'payroll_statutory_person_results',
            'payroll_statutory_relationship_results',
        ] as $table) {
            if (!$db->hasTable($table)) {
                $this->markTestSkipped('Migrace 1255 neproběhla.');
            }
        }
        $this->db = $db;
        $this->repository = new PayrollStatutoryResultRepository($db);
        $this->persister = new PayrollRunStatutoryResultPersister(
            $this->repository,
            $db,
        );

        $pdo = $db->pdo();
        $sourceSupplierId = $this->firstSupplierId($pdo);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->createEmployee($pdo, $this->supplierId);
        $this->employmentId = $this->createEmployment(
            $pdo,
            $this->supplierId,
            $this->employeeId,
        );
        $this->revisionId = $this->createRevisionGraph(
            $pdo,
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
        );
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

    public function testPersistsFourCanonicalResultSetsWithFrozenDecomposition(): void
    {
        $ids = $this->persister->persist(
            $this->supplierId,
            $this->revisionId,
            null,
            $this->snapshot(),
            $this->socialResult(),
            $this->healthResult(),
            [$this->employeeId => $this->taxResult()],
            [$this->employeeId => $this->netResult()],
        );

        self::assertSame([
            'social_insurance',
            'health_insurance',
            'income_tax',
            'net_pay',
        ], array_keys($ids));
        foreach ($ids as $id) {
            self::assertGreaterThan(0, $id);
        }

        $social = $this->requiredStored('social_insurance');
        self::assertSame('payroll-social-result.v1', $social['schema_version']);
        self::assertSame(self::SOCIAL_RULESET_ID, $social['ruleset_id']);
        self::assertSame('calculated', $social['result_status']);
        self::assertSame($this->employeeId, $social['people'][0]['employee_id']);
        self::assertSame(
            $this->employmentId,
            $social['people'][0]['relationships'][0]['employment_id'],
        );
        self::assertEquals(
            $this->snapshot()['people'][0],
            $social['people'][0]['input_snapshot'],
        );
        self::assertEquals(
            $this->snapshot()['people'][0]['employments'][0],
            $social['people'][0]['relationships'][0]['input_snapshot'],
        );

        $tax = $this->requiredStored('income_tax');
        self::assertSame('payroll-income-tax-result.v1', $tax['schema_version']);
        self::assertSame(self::TAX_RULESET_ID, $tax['ruleset_id']);
        self::assertSame(
            EmploymentIncomeTaxPolicy2026::ID,
            $tax['result_snapshot']['policy_id'],
        );

        $net = $this->requiredStored('net_pay');
        self::assertSame('payroll-net-result.v1', $net['schema_version']);
        self::assertSame(PayrollNetPolicyV1::create()->id, $net['ruleset_id']);
        self::assertSame(80_000, $net['result_snapshot']['net_payable_minor_units']);
    }

    public function testRejectsForeignPersonBeforeWritingAnyResultSet(): void
    {
        $net = $this->netResult('employee:999999');

        try {
            $this->persister->persist(
                $this->supplierId,
                $this->revisionId,
                null,
                $this->snapshot(),
                $this->socialResult(),
                $this->healthResult(),
                [$this->employeeId => $this->taxResult()],
                [$this->employeeId => $net],
            );
            self::fail('Cizí osoba musela být odmítnuta.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('employee:999999', $e->getMessage());
        }

        self::assertSame(0, $this->storedCount());
    }

    public function testRejectsRelationshipOutsideFrozenPerson(): void
    {
        $tax = $this->taxResult('employment:999999');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('employment:999999');
        $this->persister->persist(
            $this->supplierId,
            $this->revisionId,
            null,
            $this->snapshot(),
            $this->socialResult(),
            $this->healthResult(),
            [$this->employeeId => $tax],
            [$this->employeeId => $this->netResult()],
        );
    }

    public function testRejectsResultForDifferentStatutoryDate(): void
    {
        $social = $this->socialResult(calculationDate: '2026-07-31');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('datum');
        $this->persister->persist(
            $this->supplierId,
            $this->revisionId,
            null,
            $this->snapshot(),
            $social,
            $this->healthResult(),
            [$this->employeeId => $this->taxResult()],
            [$this->employeeId => $this->netResult()],
        );
    }

    public function testRejectsInputSnapshotDifferentFromImmutableRevision(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employee']['full_name'] = 'Pozměněná osoba';

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('neodpovídá mzdové revizi');
        $this->persister->persist(
            $this->supplierId,
            $this->revisionId,
            null,
            $snapshot,
            $this->socialResult(),
            $this->healthResult(),
            [$this->employeeId => $this->taxResult()],
            [$this->employeeId => $this->netResult()],
        );
    }

    public function testRejectsRootThatHidesManualReviewPerson(): void
    {
        $social = $this->socialResult(
            rootStatus: SocialCalculationStatus::Calculated,
            personStatus: SocialCalculationStatus::ManualReview,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('skrýt závažnější stav');
        $this->persister->persist(
            $this->supplierId,
            $this->revisionId,
            null,
            $this->snapshot(),
            $social,
            $this->healthResult(),
            [$this->employeeId => $this->taxResult()],
            [$this->employeeId => $this->netResult()],
        );
    }

    public function testExplicitNetErrorIsPersistedAtEveryFrozenLevel(): void
    {
        $blocked = new PayrollStatutoryBlockedPerson(
            "employee:{$this->employeeId}",
            'error',
            ['net-input-invariant-failed'],
        );

        $this->persister->persist(
            $this->supplierId,
            $this->revisionId,
            null,
            $this->snapshot(),
            $this->socialResult(),
            $this->healthResult(),
            [$this->employeeId => $this->taxResult()],
            [$this->employeeId => $blocked],
        );

        $net = $this->requiredStored('net_pay');
        self::assertSame('error', $net['result_status']);
        self::assertSame('error', $net['people'][0]['result_status']);
        self::assertSame(
            'error',
            $net['people'][0]['relationships'][0]['result_status'],
        );
        self::assertSame(
            ['net-input-invariant-failed'],
            $net['people'][0]['result_snapshot']['issues'],
        );
        self::assertNull($net['result_snapshot']['net_payable_minor_units']);
    }

    public function testRejectsTaxPolicyThatDoesNotMatchCanonicalPolicy(): void
    {
        $tax = $this->taxResult(policyHash: str_repeat('d', 64));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('daňové politiky');
        $this->persister->persist(
            $this->supplierId,
            $this->revisionId,
            null,
            $this->snapshot(),
            $this->socialResult(),
            $this->healthResult(),
            [$this->employeeId => $tax],
            [$this->employeeId => $this->netResult()],
        );
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        return [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $this->supplierId,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'payment_date' => '2026-07-10',
            'statutory_period' => [
                'period_start' => '2026-06-01',
                'period_end' => '2026-06-30',
                'payment_date' => '2026-07-10',
                'tax_calculation_date' => '2026-06-30',
                'social_calculation_date' => '2026-06-30',
                'health_calculation_date' => '2026-06-30',
            ],
            'office_id' => null,
            'ruleset_manifest' => [
                ['id' => self::SOCIAL_RULESET_ID, 'sha256' => self::SOCIAL_RULESET_HASH],
                ['id' => self::HEALTH_RULESET_ID, 'sha256' => self::HEALTH_RULESET_HASH],
                ['id' => self::TAX_RULESET_ID, 'sha256' => self::TAX_RULESET_HASH],
            ],
            'people' => [[
                'employee' => [
                    'id' => $this->employeeId,
                    'full_name' => 'Syntetická osoba',
                    'profile_status' => 'complete',
                    'is_active' => true,
                ],
                'statutory_evidence' => ['schema_version' => 'synthetic.v1'],
                'enforcement_evidence' => null,
                'employments' => [[
                    'employment' => [
                        'id' => $this->employmentId,
                        'employee_id' => $this->employeeId,
                        'code' => 'synthetic',
                        'relation_type' => 'employment',
                    ],
                    'term' => ['id' => 1, 'effective_from' => '2026-01-01'],
                    'inputs' => [],
                    'absences' => [],
                    'time_month' => null,
                ]],
            ]],
        ];
    }

    private function socialResult(
        string $personReference = '',
        string $relationshipReference = '',
        string $calculationDate = '2026-06-30',
        SocialCalculationStatus $rootStatus = SocialCalculationStatus::Calculated,
        SocialCalculationStatus $personStatus = SocialCalculationStatus::Calculated,
    ): SocialInsuranceMonthResult {
        $personReference = $personReference !== ''
            ? $personReference
            : "employee:{$this->employeeId}";
        $relationshipReference = $relationshipReference !== ''
            ? $relationshipReference
            : "employment:{$this->employmentId}";
        $relationship = new SocialRelationshipResult(
            $relationshipReference,
            SocialEmploymentKind::Employment,
            new SocialParticipationDecision(
                $relationshipReference,
                SocialParticipationStatus::Participates,
                100_000,
                100_000,
                null,
                ['regular-employment'],
            ),
            100_000,
            100_000,
            ['BASE'],
            [],
            ['BASE'],
            [],
            SocialDiscountEvidence::NotClaimed,
            SocialEmployerRateCategory::Ordinary,
            1,
            null,
        );
        $person = new SocialPersonMonthResult(
            $personReference,
            $personStatus,
            SocialJurisdictionEvidence::CzechRegimeVerified,
            'evidence:social-a1',
            null,
            0,
            100_000,
            100_000,
            7_100,
            0,
            7_100,
            null,
            null,
            [$relationship],
            $personStatus === SocialCalculationStatus::Calculated
                ? []
                : ['manual-social-review'],
        );

        return new SocialInsuranceMonthResult(
            $calculationDate,
            $rootStatus,
            $rootStatus === SocialCalculationStatus::Calculated ? 100_000 : null,
            $rootStatus === SocialCalculationStatus::Calculated ? 100_000 : null,
            $rootStatus === SocialCalculationStatus::Calculated ? 7_100 : null,
            $rootStatus === SocialCalculationStatus::Calculated ? 24_800 : null,
            $rootStatus === SocialCalculationStatus::Calculated ? 0 : null,
            $rootStatus === SocialCalculationStatus::Calculated ? 0 : null,
            $rootStatus === SocialCalculationStatus::Calculated ? 24_800 : null,
            null,
            null,
            [],
            [$person],
            $rootStatus === SocialCalculationStatus::Calculated
                ? []
                : ['manual-social-review'],
            self::SOCIAL_RULESET_ID,
            self::SOCIAL_RULESET_HASH,
        );
    }

    private function healthResult(): HealthInsuranceMonthResult
    {
        $relationshipReference = "employment:{$this->employmentId}";
        $relationship = new HealthRelationshipResult(
            $relationshipReference,
            HealthEmploymentKind::Employment,
            new HealthParticipationDecision(
                $relationshipReference,
                HealthParticipationStatus::Participates,
                100_000,
                100_000,
                null,
                ['regular-employment'],
            ),
            100_000,
            100_000,
            ['BASE'],
            [],
            ['BASE'],
            [],
        );
        $person = new HealthPersonMonthResult(
            personId: "employee:{$this->employeeId}",
            status: HealthCalculationStatus::Calculated,
            jurisdiction: HealthJurisdictionEvidence::CzechRegimeVerified,
            jurisdictionEvidenceReference: 'evidence:health-jurisdiction',
            insurerStatus: HealthInsurerSnapshotStatus::Verified,
            insurerCode: '111',
            insurerEvidenceReference: 'evidence:health-insurer',
            assessmentBaseMinorUnits: 100_000,
            otherEmployerAssessmentBaseMinorUnits: 0,
            combinedAssessmentBaseMinorUnits: 100_000,
            employmentCalendarDays: 30,
            minimumExcludedCalendarDays: 0,
            minimumApplicableCalendarDays: 30,
            statutoryMonthlyMinimumMinorUnits: 0,
            effectiveMinimumMinorUnits: 0,
            topUpResponsibility: HealthMinimumTopUpResponsibility::Employee,
            topUpResponsibilityEvidenceReference: null,
            selectedTopUpEmployerEvidenceReference: null,
            standardContributionMinorUnits: 13_500,
            employeeStandardContributionMinorUnits: 4_500,
            employerStandardContributionMinorUnits: 9_000,
            employeeMinimumTopUpMinorUnits: 0,
            employerMinimumTopUpMinorUnits: 0,
            employeeContributionMinorUnits: 4_500,
            employerContributionMinorUnits: 9_000,
            totalContributionMinorUnits: 13_500,
            relationships: [$relationship],
            minimumReductionEvidence: [],
            otherEmployerEvidence: [],
            issues: [],
        );

        return new HealthInsuranceMonthResult(
            '2026-06-30',
            HealthCalculationStatus::Calculated,
            100_000,
            4_500,
            9_000,
            13_500,
            [$person],
            [],
            [],
            self::HEALTH_RULESET_ID,
            self::HEALTH_RULESET_HASH,
        );
    }

    private function taxResult(
        string $relationshipReference = '',
        ?string $policyHash = null,
    ): MonthlyEmploymentIncomeTaxResult {
        $relationshipReference = $relationshipReference !== ''
            ? $relationshipReference
            : "employment:{$this->employmentId}";
        $advance = new MonthlyAdvanceTaxResult(
            100_000,
            100_000,
            100_000,
            0,
            [],
            15_000,
            0,
            0,
            false,
            15_000,
            0,
            self::TAX_RULESET_ID,
            self::TAX_RULESET_HASH,
        );

        return new MonthlyEmploymentIncomeTaxResult(
            TaxCalculationStatus::Calculated,
            '2026-06-30',
            "employee:{$this->employeeId}",
            "supplier:{$this->supplierId}",
            [new RelationshipTaxResult(
                $relationshipReference,
                EmploymentRelationshipKind::Employment,
                100_000,
                TaxRegime::Advance,
                null,
            )],
            $advance,
            [],
            0,
            0,
            0,
            0,
            [],
            0,
            0,
            new AnnualTaxAccumulatorResult(
                2026,
                5,
                100_000,
                0,
                15_000,
                0,
                0,
                0,
                0,
                100_000,
                false,
                [],
                true,
                false,
            ),
            [],
            EmploymentIncomeTaxPolicy2026::ID,
            $policyHash ?? EmploymentIncomeTaxPolicy2026::contractHash(),
            self::TAX_RULESET_ID,
            self::TAX_RULESET_HASH,
        );
    }

    private function netResult(string $personReference = ''): PayrollNetResult
    {
        $personReference = $personReference !== ''
            ? $personReference
            : "employee:{$this->employeeId}";

        return new PayrollNetResult(
            $personReference,
            [new NetRelationshipIncome(
                "employment:{$this->employmentId}",
                100_000,
                0,
            )],
            100_000,
            0,
            7_100,
            4_500,
            8_400,
            0,
            0,
            0,
            80_000,
            0,
            80_000,
            [],
        );
    }

    /** @return array<string,mixed> */
    private function requiredStored(string $kind): array
    {
        $stored = $this->repository->find(
            $this->supplierId,
            $this->revisionId,
            $kind,
        );
        self::assertNotNull($stored);

        return $stored;
    }

    private function storedCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_statutory_results
              WHERE supplier_id = ? AND revision_id = ?',
        );
        $stmt->execute([$this->supplierId, $this->revisionId]);

        return (int) $stmt->fetchColumn();
    }

    private function createEmployee(PDO $pdo, int $supplierId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba", "employee", "hpp", 1, 1, 0, 10000, 0, 1)',
        )->execute([$supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function createEmployment(PDO $pdo, int $supplierId, int $employeeId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date)
             VALUES (?, ?, "synthetic", "employment", "active", "2026-01-01")',
        )->execute([$supplierId, $employeeId]);

        return (int) $pdo->lastInsertId();
    }

    private function createRevisionGraph(
        PDO $pdo,
        int $supplierId,
        int $employeeId,
        int $employmentId,
    ): int {
        $snapshotJson = CanonicalJson::encode($this->snapshot());
        $manifestJson = CanonicalJson::encode([
            'rulesets' => $this->snapshot()['ruleset_manifest'],
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, "2026-06-01", "2026-07-10")',
        )->execute([$supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "calculated",
                     "payroll-run-input.v2", ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $runId,
            hash('sha256', $manifestJson),
            $snapshotJson,
            hash('sha256', $snapshotJson),
            hash('sha256', 'synthetic-persister-' . $supplierId, true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")',
        )->execute([$supplierId, $revisionId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, employee_id, employment_id,
                 input_json, input_hash, status)
             VALUES (?, ?, ?, ?, "{}", ?, "calculated")',
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $employmentId,
            str_repeat('3', 64),
        ]);

        return $revisionId;
    }

    private function firstSupplierId(PDO $pdo): int
    {
        $stmt = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }
}
