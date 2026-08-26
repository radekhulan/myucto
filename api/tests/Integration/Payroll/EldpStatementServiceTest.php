<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Data jsou zjevně syntetická a nic tady nesahá na síť ani na ČSSZ.
 */
#[Group('integration')]
final class EldpStatementServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private EldpStatementService $service;
    private int $supplierId;
    private int $employmentId;
    private int $createdBy;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_eldp_statements')) {
            $this->markTestSkipped('Migrace 1398 neproběhla.');
        }
        $service = $container->get(EldpStatementService::class);
        self::assertInstanceOf(EldpStatementService::class, $service);
        $this->service = $service;
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->createdBy = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $this->createdBy);
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employmentId = $this->source($pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testPreparesStatementObligationAndSubmissionAndStopsAtPrepared(): void
    {
        $result = $this->prepare();

        self::assertTrue($result['created']);
        self::assertSame('termination', $result['statement_kind']);
        self::assertSame(1, $result['section_count']);
        self::assertSame(51, $result['insurance_days']);
        self::assertSame(5, $result['excluded_days_total']);
        self::assertSame('2025-12-30', $result['due_on']);
        self::assertSame('prepared', $result['submission_status']);

        $submission = $this->db->pdo()->prepare(
            'SELECT submission.status, submission.channel, obligation.agenda_code
               FROM payroll_submissions submission
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.id = submission.obligation_id
              WHERE submission.supplier_id = ? AND submission.id = ?'
        );
        $submission->execute([$this->supplierId, $result['submission_id']]);
        $row = $submission->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('prepared', $row['status']);
        self::assertSame('other', $row['channel']);
        self::assertSame('ELDP', $row['agenda_code']);

        $obligation = $this->db->pdo()->prepare(
            'SELECT due_on, earliest_submission_on, ruleset_id
               FROM payroll_submission_deadlines
              WHERE supplier_id = ? AND obligation_id = ?'
        );
        $obligation->execute([$this->supplierId, $result['obligation_id']]);
        $deadline = $obligation->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($deadline);
        self::assertSame('2025-12-30', $deadline['due_on']);
        self::assertSame(
            'cz-eldp-deadlines.termination.v1',
            $deadline['ruleset_id'],
        );
    }

    public function testRepeatedPreparationCreatesNoSecondStatementOrSubmission(): void
    {
        $first = $this->prepare();
        $second = $this->prepare();

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['statement_id'], $second['statement_id']);
        self::assertSame($first['submission_id'], $second['submission_id']);
        self::assertSame($first['artifact_id'], $second['artifact_id']);
        self::assertSame($first['obligation_id'], $second['obligation_id']);

        $counts = $this->db->pdo()->prepare(
            'SELECT
               (SELECT COUNT(*) FROM payroll_eldp_statements WHERE supplier_id = ?) AS statements,
               (SELECT COUNT(*) FROM payroll_submissions WHERE supplier_id = ?) AS submissions,
               (SELECT COUNT(*) FROM payroll_obligations WHERE supplier_id = ?) AS obligations'
        );
        $counts->execute([$this->supplierId, $this->supplierId, $this->supplierId]);
        $row = $counts->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['statements']);
        self::assertSame(1, (int) $row['submissions']);
        self::assertSame(1, (int) $row['obligations']);
    }

    public function testPreparationUsesCurrentApprovedCorrectiveRevision(): void
    {
        $result = $this->prepare();

        self::assertTrue($result['created']);
        self::assertSame(51, $result['insurance_days']);
    }

    public function testStoredStatementIsEncryptedAndReadableOnlyThroughTheService(): void
    {
        $result = $this->prepare();

        $stored = $this->db->pdo()->prepare(
            'SELECT statement_ciphertext, source_manifest_json
               FROM payroll_eldp_statements WHERE supplier_id = ? AND id = ?'
        );
        $stored->execute([$this->supplierId, $result['statement_id']]);
        $row = $stored->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertStringStartsWith('enc:v2:', (string) $row['statement_ciphertext']);
        self::assertStringNotContainsString(
            'Syntetická osoba ELDP',
            (string) $row['source_manifest_json'],
        );

        $statement = $this->service->statement(
            $this->supplierId,
            'test',
            $this->employmentId,
            2025,
        );
        self::assertIsArray($statement);
        self::assertSame(51, $statement['insurance_days']);
        self::assertSame(
            9001,
            $statement['payload']['eldp_sections'][0]
                ['excluded_days_provenance'][0]['absence_id'],
        );
    }

    public function testDifferentConfirmationUnderTheSameKeyIsRejected(): void
    {
        $this->prepare();

        $this->expectException(EldpValidationException::class);
        $this->expectExceptionMessage('jiný obsah potvrzení');
        $this->service->prepare(
            $this->supplierId,
            $this->employmentId,
            2025,
            'test',
            [
                'excluded_days_confirmed' => true,
                'deducted_days_none' => true,
                'requested_by_authority' => true,
                'note' => 'Jiné potvrzení pod stejným idempotency klíčem.',
            ],
            'synthetic-eldp-statement',
            $this->createdBy,
        );
    }

    /** @return array<string,mixed> */
    private function prepare(): array
    {
        return $this->service->prepare(
            $this->supplierId,
            $this->employmentId,
            2025,
            'test',
            [
                'excluded_days_confirmed' => true,
                'deducted_days_none' => true,
                'requested_by_authority' => false,
                'note' => 'Syntetický evidenční list pro integrační test.',
            ],
            'synthetic-eldp-statement',
            $this->createdBy,
        );
    }

    private function source(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba ELDP", "employee", "hpp",
                     1, 1, 0, 10000, 0, 1)',
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, end_date, monthly_gross_minor)
             VALUES (?, ?, "eldp-statement", "employment", "ended",
                     "2025-10-01", "2025-11-20", 1000000)',
        )->execute([$this->supplierId, $employeeId]);
        $employmentId = (int) $pdo->lastInsertId();

        foreach ([
            ['2025-10-01', '2025-11-10', []],
            ['2025-11-01', '2025-12-10', [[
                'id' => 9001,
                'absence_type' => 'dpn',
                'date_from' => '2025-11-03',
                'date_to' => '2025-11-07',
            ]]],
        ] as [$periodStart, $paymentDate, $absences]) {
            $this->revision(
                $pdo,
                $employeeId,
                $employmentId,
                $periodStart,
                $paymentDate,
                $absences,
                $periodStart === '2025-11-01' ? 'correction' : 'regular',
            );
        }

        return $employmentId;
    }

    /** @param list<array<string,mixed>> $absences */
    private function revision(
        PDO $pdo,
        int $employeeId,
        int $employmentId,
        string $periodStart,
        string $paymentDate,
        array $absences,
        string $revisionKind,
    ): void {
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, ?, "approved", 1)',
        )->execute([$this->supplierId, $periodStart, $paymentDate]);
        $runId = (int) $pdo->lastInsertId();
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $this->supplierId,
            'period_start' => $periodStart,
            'people' => [[
                'employee' => ['id' => $employeeId],
                'employments' => [[
                    'employment' => [
                        'id' => $employmentId,
                        'employee_id' => $employeeId,
                        'relation_type' => 'employment',
                        'start_date' => '2025-10-01',
                        'actual_start_date' => '2025-10-01',
                        'end_date' => '2025-11-20',
                    ],
                    'term' => [
                        'id' => 801,
                        'row_version' => 1,
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                    ],
                    'absences' => $absences,
                    'inputs' => [],
                ]],
            ]],
        ];
        $inputJson = CanonicalJson::encode($input);
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash('sha256', $inputJson),
            'people' => [[
                'employee_id' => $employeeId,
                'employments' => [[
                    'employment_id' => $employmentId,
                    'totals' => [],
                ]],
                'statutory' => [
                    'social_insurance' => [
                        'status' => 'calculated',
                        'relationships' => [[
                            'relationship_id' => "employment:{$employmentId}",
                            'kind' => 'employment',
                            'participation' => [
                                'relationship_id' => "employment:{$employmentId}",
                                'status' => 'participates',
                            ],
                            'assessment_base_minor_units' => 1_000_000,
                            'capped_assessment_base_minor_units' => 1_000_000,
                        ]],
                    ],
                ],
            ]],
        ];
        $resultJson = CanonicalJson::encode($result);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json, result_snapshot_hash,
                 idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, ?, "approved", "payroll-run-input.v2",
                     ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            $revisionKind,
            str_repeat('a', 64),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            hash('sha256', $resultJson),
            hash('sha256', "synthetic-eldp-statement:{$this->supplierId}:{$periodStart}", true),
        ]);
    }
}
