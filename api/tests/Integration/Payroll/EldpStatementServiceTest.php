<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEldpAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Repository\Deletion\DocumentDeletionGuard;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpManualCompletionService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Data jsou zjevně syntetická a nic tady nesahá na síť ani na ČSSZ.
 */
#[Group('integration')]
final class EldpStatementServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private EldpStatementService $service;
    private EldpManualCompletionService $completions;
    private DocumentStorage $documentStorage;
    private DocumentRepository $documents;
    private DocumentDeletionGuard $documentDeletionGuard;
    private PayrollEldpAction $action;
    private int $supplierId;
    private int $employmentId;
    private int $createdBy;
    /** @var list<string> */
    private array $storedEvidencePaths = [];

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
        $this->completions = $container->get(EldpManualCompletionService::class);
        $this->documentStorage = $container->get(DocumentStorage::class);
        $this->documents = $container->get(DocumentRepository::class);
        $this->documentDeletionGuard = $container->get(DocumentDeletionGuard::class);
        $this->action = $container->get(PayrollEldpAction::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->createdBy = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $this->createdBy);
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->employmentId = $this->source($pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        foreach ($this->storedEvidencePaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
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

    public function testManualEvidenceKeepsControlXmlPreparedAndFulfilsOnlyAcceptedOutcome(): void
    {
        $prepared = $this->prepare();
        $submittedDocument = $this->evidenceDocument(
            'submitted.pdf',
            '%PDF-1.7 synthetic ELDP submission confirmation',
        );
        $acceptedDocument = $this->evidenceDocument(
            'accepted.pdf',
            '%PDF-1.7 synthetic ELDP acceptance confirmation',
        );

        $submittedResponse = $this->action->complete(
            $this->request('session', [
                'payroll.submissions' => AccessLevel::WRITE->value,
                'documents' => AccessLevel::READ->value,
            ])->withParsedBody([
                'environment' => 'test',
                'expected_obligation_row_version' => 2,
                'authority_status' => 'submitted',
                'confirmation_document_id' => $submittedDocument,
                'authority_reference' => 'CSSZ-SYNTHETIC-SUBMITTED-2025',
                'confirmed_on' => '2026-01-05',
                'idempotency_key' => 'synthetic-eldp-manual-submitted',
            ]),
            new Response(),
            ['statementId' => (string) $prepared['statement_id']],
        );
        self::assertSame(200, $submittedResponse->getStatusCode());
        $submittedPayload = json_decode((string) $submittedResponse->getBody(), true);
        self::assertIsArray($submittedPayload);
        $submitted = $submittedPayload['manual_completion'] ?? null;
        self::assertIsArray($submitted);

        self::assertTrue($submitted['created']);
        self::assertSame('submitted', $submitted['authority_status']);
        self::assertSame('submitted', $submitted['obligation_status']);
        self::assertSame('prepared', $submitted['local_submission_status']);
        self::assertSame(3, $submitted['obligation_row_version']);
        $audit = $this->db->pdo()->prepare(
            'SELECT entity_id, payload FROM activity_log
              WHERE supplier_id = ? AND action = "payroll.eldp.manual_completion_recorded"
              ORDER BY id DESC LIMIT 1'
        );
        $audit->execute([$this->supplierId]);
        $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($auditRow);
        self::assertSame((int) $submitted['id'], (int) $auditRow['entity_id']);
        self::assertStringContainsString('submitted', (string) $auditRow['payload']);

        $accepted = $this->completions->record(
            $this->supplierId,
            'test',
            $prepared['statement_id'],
            $submitted['obligation_row_version'],
            'accepted',
            $acceptedDocument,
            'CSSZ-SYNTHETIC-ACCEPTED-2025',
            '2026-01-06',
            'synthetic-eldp-manual-accepted',
            $this->createdBy,
        );

        self::assertTrue($accepted['created']);
        self::assertSame('accepted', $accepted['authority_status']);
        self::assertSame('fulfilled', $accepted['obligation_status']);
        self::assertSame('prepared', $accepted['local_submission_status']);
        self::assertSame(4, $accepted['obligation_row_version']);
        self::assertNull(
            $this->documents->findRaw(
                $acceptedDocument,
                $this->supplierId,
                DocumentViewerContext::forUser($this->createdBy),
            ),
            'Potvrzení ELDP nesmí být dostupné přes obecné Dokumenty bez práva k podáním.',
        );
        self::assertNotNull($this->documents->findRaw(
            $acceptedDocument,
            $this->supplierId,
            DocumentViewerContext::forUser($this->createdBy, false, false, true),
        ));

        $replay = $this->completions->record(
            $this->supplierId,
            'test',
            $prepared['statement_id'],
            $submitted['obligation_row_version'],
            'accepted',
            $acceptedDocument,
            'CSSZ-SYNTHETIC-ACCEPTED-2025',
            '2026-01-06',
            'synthetic-eldp-manual-accepted',
            $this->createdBy,
        );
        self::assertFalse($replay['created']);
        self::assertSame(4, $replay['obligation_row_version']);

        $stored = $this->db->pdo()->prepare(
            'SELECT authority_status, confirmation_document_id,
                    confirmation_sha256, confirmation_byte_size,
                    authority_reference, confirmed_on
               FROM payroll_eldp_manual_completions
              WHERE supplier_id = ? AND statement_id = ?
              ORDER BY id'
        );
        $stored->execute([$this->supplierId, $prepared['statement_id']]);
        $rows = $stored->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        self::assertSame('submitted', $rows[0]['authority_status']);
        self::assertSame('accepted', $rows[1]['authority_status']);
        self::assertSame($acceptedDocument, (int) $rows[1]['confirmation_document_id']);
        self::assertSame(
            hash('sha256', '%PDF-1.7 synthetic ELDP acceptance confirmation'),
            $rows[1]['confirmation_sha256'],
        );
        self::assertSame(
            strlen('%PDF-1.7 synthetic ELDP acceptance confirmation'),
            (int) $rows[1]['confirmation_byte_size'],
        );
        $blockedDocuments = $this->documentDeletionGuard->blockedTrashDocuments(
            $this->supplierId,
            [$acceptedDocument],
        );
        self::assertArrayHasKey($acceptedDocument, $blockedDocuments);
        self::assertSame(
            1,
            $blockedDocuments[$acceptedDocument]->counts['payroll_eldp_manual_completion'] ?? 0,
        );
        $this->db->pdo()->prepare(
            'UPDATE documents SET deleted_at = NOW() WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $acceptedDocument]);
        $replayAfterTrash = $this->completions->record(
            $this->supplierId,
            'test',
            $prepared['statement_id'],
            $submitted['obligation_row_version'],
            'accepted',
            $acceptedDocument,
            'CSSZ-SYNTHETIC-ACCEPTED-2025',
            '2026-01-06',
            'synthetic-eldp-manual-accepted',
            $this->createdBy,
        );
        self::assertFalse($replayAfterTrash['created']);
        self::assertSame(4, $replayAfterTrash['obligation_row_version']);

        $submissionStatus = $this->db->pdo()->prepare(
            'SELECT status FROM payroll_submissions WHERE supplier_id = ? AND id = ?'
        );
        $submissionStatus->execute([$this->supplierId, $prepared['submission_id']]);
        self::assertSame('prepared', $submissionStatus->fetchColumn());

        try {
            $this->db->pdo()->prepare(
                'UPDATE payroll_eldp_manual_completions
                    SET authority_reference = "tampered"
                  WHERE supplier_id = ? AND id = ?'
            )->execute([$this->supplierId, $accepted['id']]);
            self::fail('Doložené ruční dokončení ELDP musí být neměnné.');
        } catch (\PDOException $exception) {
            self::assertStringContainsString(
                'ELDP manual completion is immutable',
                $exception->getMessage(),
            );
        }
    }

    public function testManualCompletionRequiresSessionAndBothPermissions(): void
    {
        $bearer = $this->action->complete(
            $this->request('bearer', [
                'payroll.submissions' => AccessLevel::WRITE->value,
                'documents' => AccessLevel::READ->value,
            ]),
            new Response(),
            ['statementId' => '1'],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->errorCode($bearer));

        $withoutDocuments = $this->action->complete(
            $this->request('session', [
                'payroll.submissions' => AccessLevel::WRITE->value,
            ]),
            new Response(),
            ['statementId' => '1'],
        );
        self::assertSame(403, $withoutDocuments->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($withoutDocuments));
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

    private function evidenceDocument(string $name, string $bytes): int
    {
        $stored = $this->documentStorage->storeFromBytes(
            $bytes,
            $this->supplierId,
            $name,
        );
        $this->storedEvidencePaths[] = $this->documentStorage->pathFor(
            $this->supplierId,
            $stored['sha256'],
            $stored['filename'],
        );

        return $this->documents->insert([
            'supplier_id' => $this->supplierId,
            'folder_id' => null,
            'title' => 'Syntetický důkaz ELDP ' . $name,
            'description' => null,
            'original_name' => $name,
            'filename' => $stored['filename'],
            'sha256' => $stored['sha256'],
            'mime_type' => $stored['mime_type'],
            'size_bytes' => $stored['size_bytes'],
            'doc_type' => $stored['doc_type'],
            'source' => 'manual',
            'uploaded_by' => $this->createdBy,
            'scope' => 'company',
        ]);
    }

    /** @param array<string,int> $permissions */
    private function request(string $authMethod, array $permissions): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/submissions/eldp/1/manual-completion')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->createdBy,
                'role' => 'accountant',
            ])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withAttribute('auth.effective_role', new EffectiveRole(
                159,
                'Syntetická ELDP role',
                'staff',
                true,
                $permissions,
            ));
    }

    private function errorCode(Response $response): string
    {
        $response->getBody()->rewind();
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        return (string) ($payload['error']['code'] ?? '');
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
