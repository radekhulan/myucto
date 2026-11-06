<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\HealthInsuranceOverviewRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceOverviewException;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewBuilder;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollHealthPaymentOverviewServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollStatutoryResultRepository $statutoryResults;
    private HealthPaymentOverviewService $service;
    private int $supplierId;
    private int $otherSupplierId;
    private int $revisionId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        $this->db = $db;
        $this->statutoryResults = new PayrollStatutoryResultRepository(
            $this->db,
        );
        $repository = new HealthInsuranceOverviewRepository(
            $this->db,
            $this->statutoryResults,
        );
        $this->service = new HealthPaymentOverviewService(
            $repository,
            new HealthPaymentOverviewBuilder(),
        );
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_statutory_results',
            'payroll_statutory_person_results',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }

        $pdo = $this->db->pdo();
        $supplierStatement = $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        );
        if ($supplierStatement === false) {
            throw new \RuntimeException('Výchozí firmu nelze načíst.');
        }
        $sourceSupplierId = (int) $supplierStatement->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $employeeId = $this->employee($pdo, $this->supplierId);
        $this->revisionId = $this->revision(
            $pdo,
            $this->supplierId,
            $employeeId,
        );
        $this->storeResult($employeeId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testReadsApprovedImmutableResultInTenantScope(): void
    {
        $overviews = $this->service->overviews(
            $this->supplierId,
            $this->revisionId,
        );

        self::assertCount(1, $overviews);
        self::assertSame('111', $overviews[0]->insurerCode);
        self::assertSame('2026-06', $overviews[0]->period);
        self::assertSame(135_000, $overviews[0]->totals[
            'total_contribution_minor_units'
        ]);
        self::assertSame(
            'Syntetická osoba PPZ',
            $overviews[0]->people[0]['display_name'],
        );
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $overviews[0]->sha256(),
        );

        try {
            $this->service->overviews(
                $this->otherSupplierId,
                $this->revisionId,
            );
            self::fail('Cizí tenant nesmí načíst přehled.');
        } catch (HealthInsuranceOverviewException $exception) {
            self::assertSame(
                'health_insurance_result_not_found',
                $exception->validationCode,
            );
        }
    }

    public function testExplicitlyBlocksElectronicSubmission(): void
    {
        $this->expectException(HealthInsuranceOverviewException::class);
        $this->expectExceptionMessage('není podporované');
        $this->service->assertElectronicSubmissionSupported();
    }

    private function employee(PDO $pdo, int $supplierId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba PPZ", "employee", "hpp",
                     1, 1, 0, 10000, 0, 1)',
        )->execute([$supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function revision(
        PDO $pdo,
        int $supplierId,
        int $employeeId,
    ): int {
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-06-01", "2026-07-10", "approved", 1)',
        )->execute([$supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $input = '{"schema_version":"payroll-run-input.v2"}';
        $result = '{"schema_version":"payroll-run-result.v2"}';
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "regular", "approved",
                     "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $supplierId,
            $runId,
            str_repeat('a', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash(
                'sha256',
                "synthetic-health-overview:{$supplierId}:{$runId}",
                true,
            ),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")',
        )->execute([$supplierId, $revisionId, $employeeId]);

        return $revisionId;
    }

    private function storeResult(int $employeeId): void
    {
        $this->statutoryResults->store(
            $this->supplierId,
            $this->revisionId,
            'health_insurance',
            'payroll-health-result.v1',
            'calculated',
            'cz-health-2026',
            str_repeat('b', 64),
            ['schema_version' => 'payroll-run-input.v2'],
            [
                'calculation_date' => '2026-06-30',
                'status' => 'calculated',
                'assessment_base_minor_units' => 1_000_000,
                'employee_contribution_minor_units' => 45_000,
                'employer_contribution_minor_units' => 90_000,
                'total_contribution_minor_units' => 135_000,
                'insurer_liabilities' => [[
                    'insurer_code' => '111',
                    'person_count' => 1,
                    'assessment_base_minor_units' => 1_000_000,
                    'employee_contribution_minor_units' => 45_000,
                    'employer_contribution_minor_units' => 90_000,
                    'total_contribution_minor_units' => 135_000,
                ]],
                'issues' => [],
                'ruleset_id' => 'cz-health-2026',
                'ruleset_hash' => str_repeat('b', 64),
            ],
            [[
                'employee_id' => $employeeId,
                'result_status' => 'calculated',
                'input_snapshot' => [
                    'employee' => [
                        'id' => $employeeId,
                        'full_name' => 'Syntetická osoba PPZ',
                    ],
                ],
                'result_snapshot' => [
                    'person_id' => "employee:{$employeeId}",
                    'status' => 'calculated',
                    'insurer_status' => 'verified',
                    'insurer_code' => '111',
                    'ppz_counted' => true,
                    'assessment_base_minor_units' => 1_000_000,
                    'employee_contribution_minor_units' => 45_000,
                    'employer_contribution_minor_units' => 90_000,
                    'total_contribution_minor_units' => 135_000,
                ],
                'relationships' => [],
            ]],
            null,
        );
    }
}
