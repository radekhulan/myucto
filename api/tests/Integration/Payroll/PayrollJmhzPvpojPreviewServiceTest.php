<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\JmhzPvpojPreviewRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreviewBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreviewException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreviewService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollJmhzPvpojPreviewServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private JmhzPvpojPreviewService $service;
    private int $supplierId;
    private int $otherSupplierId;
    private int $revisionId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        $this->db = $db;
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_run_persons',
            'payroll_run_employments',
            'payroll_statutory_results',
            'payroll_statutory_person_results',
            'payroll_statutory_relationship_results',
            'payroll_payment_liabilities',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $statutory = new PayrollStatutoryResultRepository($this->db);
        $this->service = new JmhzPvpojPreviewService(
            new JmhzPvpojPreviewRepository($this->db, $statutory),
            new JmhzPvpojPreviewBuilder(),
        );

        $pdo = $this->db->pdo();
        $sourceSupplier = $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        );
        if ($sourceSupplier === false) {
            throw new \RuntimeException('Výchozí firmu nelze načíst.');
        }
        $sourceSupplierId = (int) $sourceSupplier->fetchColumn();
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
        $officeId = $this->office($pdo, $this->supplierId);
        $employeeId = $this->employee($pdo, $this->supplierId);
        $employmentId = $this->employment(
            $pdo,
            $this->supplierId,
            $employeeId,
        );
        $this->revisionId = $this->revision(
            $pdo,
            $this->supplierId,
            $officeId,
            $employeeId,
            $employmentId,
            $statutory,
        );
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

    public function testReadsTenantScopedApprovedPreview(): void
    {
        $preview = $this->service->preview(
            $this->supplierId,
            $this->revisionId,
        );

        self::assertSame('2026-06', $preview->period);
        self::assertSame(3_125, $preview->pvpoj['pojistneUhrada']);
        self::assertSame(
            'internal_jmhz_pvpoj_preview',
            $preview->toArray()['document_kind'],
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $preview->sha256());

        try {
            $this->service->preview(
                $this->otherSupplierId,
                $this->revisionId,
            );
            self::fail('Cizí tenant nesmí načíst PVPOJ preview.');
        } catch (JmhzPvpojPreviewException $exception) {
            self::assertSame(
                'jmhz_pvpoj_source_not_found',
                $exception->validationCode,
            );
        }
    }

    public function testExplicitlyBlocksOfficialSubmission(): void
    {
        $this->expectException(JmhzPvpojPreviewException::class);
        $this->expectExceptionMessage('PVPOJ preview');

        $this->service->assertOfficialSubmissionSupported();
    }

    /**
     * Registrace u OSSZ je na mzdové účtárně, takže PVPOJ bez ní a bez jejího
     * variabilního symbolu podat nelze.
     */
    private function office(PDO $pdo, int $supplierId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, social_security_variable_symbol,
                 is_active)
             VALUES (?, "PVPOJ-SYN", "Syntetická účtárna PVPOJ",
                     "1234567890", 1)',
        )->execute([$supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from,
                 social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", "1234567890", "synthetic:pvpoj")',
        )->execute([$supplierId, $officeId]);

        return $officeId;
    }

    private function employee(PDO $pdo, int $supplierId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba PVPOJ", "employee", "hpp",
                     1, 1, 0, 10000, 0, 1)',
        )->execute([$supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function employment(
        PDO $pdo,
        int $supplierId,
        int $employeeId,
    ): int {
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, monthly_gross_minor)
             VALUES (?, ?, "pvpoj-synthetic", "employment", "active",
                     "2026-01-01", 1000000)',
        )->execute([$supplierId, $employeeId]);

        return (int) $pdo->lastInsertId();
    }

    private function revision(
        PDO $pdo,
        int $supplierId,
        int $officeId,
        int $employeeId,
        int $employmentId,
        PayrollStatutoryResultRepository $statutory,
    ): int {
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-06-01", "2026-07-10", "approved", 1)',
        )->execute([$supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $registrationStatement = $pdo->prepare(
            'SELECT version.id, version.effective_from,
                    version.social_security_variable_symbol,
                    version.source_reference, office.code, office.name
               FROM payroll_office_registration_versions version
               JOIN payroll_offices office
                 ON office.supplier_id = version.supplier_id
                AND office.id = version.office_id
              WHERE version.supplier_id = ? AND version.office_id = ?',
        );
        $registrationStatement->execute([$supplierId, $officeId]);
        $registrationRow = $registrationStatement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($registrationRow);
        $officeRegistration = [
            'id' => (int) $registrationRow['id'],
            'effective_from' => (string) $registrationRow['effective_from'],
            'social_security_variable_symbol' => (string) $registrationRow['social_security_variable_symbol'],
            'source_reference' => (string) $registrationRow['source_reference'],
            'office_code' => (string) $registrationRow['code'],
            'office_name' => (string) $registrationRow['name'],
        ];
        $officeRegistration['sha256'] = $this->hash($officeRegistration);
        $personInput = [
            'employee' => ['id' => $employeeId],
            'employments' => [[
                'employment' => [
                    'id' => $employmentId,
                    'employee_id' => $employeeId,
                    'office_id' => $officeId,
                    'office_registration' => $officeRegistration,
                ],
            ]],
        ];
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $supplierId,
            'office_id' => $officeId,
            'period_start' => '2026-06-01',
            'people' => [$personInput],
        ];
        $inputJson = CanonicalJson::encode($input);
        $resultJson = CanonicalJson::encode([
            'schema_version' => 'payroll-run-result.v2',
        ]);
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
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            hash('sha256', $resultJson),
            hash('sha256', "synthetic-pvpoj:{$supplierId}:{$runId}", true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")',
        )->execute([$supplierId, $revisionId, $employeeId]);
        $relationshipInput = $personInput['employments'][0];
        $relationshipInputJson = CanonicalJson::encode($relationshipInput);
        $pdo->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, employee_id, employment_id,
                 input_json, input_hash, status)
             VALUES (?, ?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $employmentId,
            $relationshipInputJson,
            hash('sha256', $relationshipInputJson),
        ]);

        $relationshipResult = [
            'relationship_id' => "employment:{$employmentId}",
            'participation' => [
                'relationship_id' => "employment:{$employmentId}",
                'status' => 'participates',
                'reason_codes' => ['synthetic'],
            ],
            'capped_assessment_base_minor_units' => 1_000_000,
            'part_time_employer_discount' => 'not_claimed',
            'part_time_employer_discount_evidence_reference' => null,
            'employer_rate_category' => 'ordinary',
        ];
        $personResult = [
            'person_id' => "employee:{$employeeId}",
            'status' => 'calculated',
            'capped_assessment_base_minor_units' => 1_000_000,
            'employee_contribution_before_discount_minor_units' => 71_000,
            'working_pensioner_discount_minor_units' => 6_500,
            'employee_contribution_minor_units' => 64_500,
            'working_pensioner_discount_evidence_reference' =>
                "evidence:pensioner:{$employeeId}",
            'issues' => [],
        ];
        $root = [
            'calculation_date' => '2026-06-30',
            'status' => 'calculated',
            'participating_assessment_base_minor_units' => 1_000_000,
            'capped_assessment_base_minor_units' => 1_000_000,
            'employee_contribution_minor_units' => 64_500,
            'employer_contribution_before_discount_minor_units' => 248_000,
            'part_time_discount_assessment_base_minor_units' => 0,
            'part_time_discount_minor_units' => 0,
            'employer_contribution_minor_units' => 248_000,
            'issues' => [],
            'ruleset_id' => 'cz-social-2026',
            'ruleset_hash' => str_repeat('b', 64),
        ];
        $statutory->store(
            $supplierId,
            $revisionId,
            'social_insurance',
            'payroll-social-result.v1',
            'calculated',
            'cz-social-2026',
            str_repeat('b', 64),
            $input,
            $root,
            [[
                'employee_id' => $employeeId,
                'input_snapshot' => $personInput,
                'result_snapshot' => $personResult,
                'result_status' => 'calculated',
                'relationships' => [[
                    'employment_id' => $employmentId,
                    'input_snapshot' => $relationshipInput,
                    'result_snapshot' => $relationshipResult,
                    'result_status' => 'calculated',
                ]],
            ]],
            null,
        );

        $liabilitySource = [
            'schema_reference' => 'payroll-payment-social-insurance-source.v1',
            'run_id' => $runId,
            'revision_id' => $revisionId,
            'revision_no' => 1,
            'statutory_result_hash' => $this->hash($root),
            'logical_reference' => "social-insurance:office:{$officeId}",
            'recipient_reference' =>
                'institution:social_security:110:account:5',
            'payroll_office_id' => $officeId,
            'variable_symbol' => '1234567890',
            'employee_contribution_minor' => 64_500,
            'employer_contribution_minor' => 248_000,
            'target_amount_minor' => 312_500,
            'prior_signed_minor' => 0,
            'delta_signed_minor' => 312_500,
        ];
        $liabilityJson = CanonicalJson::encode($liabilitySource);
        $pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?,
                     "social_insurance", "outgoing",
                     "institution:social_security:110:account:5",
                     "2026-07-20", "CZK", 312500, ?, ?, ?)',
        )->execute([
            $supplierId,
            $revisionId,
            "social-insurance:office:{$officeId}",
            $liabilityJson,
            hash('sha256', $liabilityJson),
            hash(
                'sha256',
                "synthetic-pvpoj-liability:{$supplierId}:{$revisionId}",
                true,
            ),
        ]);

        return $revisionId;
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', CanonicalJson::encode($value));
    }
}
