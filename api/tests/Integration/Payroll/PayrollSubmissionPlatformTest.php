<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionConflictException;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\PayrollAgendaCorrectionPolicy;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceipt;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class PayrollSubmissionPlatformTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private int $supplierId;
    private int $otherSupplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $encryption = $container->get(SecretEncryption::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        $this->db = $connection;
        $pdo = $connection->pdo();
        $sourceSupplierStatement = $pdo->query(
            'SELECT MIN(id) FROM supplier',
        );
        self::assertInstanceOf(\PDOStatement::class, $sourceSupplierStatement);
        $sourceSupplier = (int) $sourceSupplierStatement->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplier);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplier,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplier,
        );

        $repository = new PayrollSubmissionRepository($connection);
        $clock = new MockClock('2026-08-04 10:11:12 Europe/Prague');
        $this->obligations = new PayrollObligationService(
            $repository,
            $clock,
        );
        $this->submissions = new PayrollSubmissionService(
            $repository,
            new PayrollSubmissionStateMachine(),
            $encryption,
            $clock,
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

    public function testObligationAndSubmissionAreIdempotentAndTenantScoped(): void
    {
        $obligation = $this->createObligation();
        $replayed = $this->createObligation();
        self::assertTrue($obligation['created']);
        self::assertFalse($replayed['created']);
        self::assertSame($obligation['id'], $replayed['id']);
        self::assertSame('2026-08-20', $obligation['due_on']);

        $submission = $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            'manual_upload',
            str_repeat('a', 64),
            'regular-2026-07',
        );
        $submissionReplay = $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            'manual_upload',
            str_repeat('a', 64),
            'regular-2026-07',
        );
        self::assertTrue($submission['created']);
        self::assertFalse($submissionReplay['created']);
        self::assertSame($submission['id'], $submissionReplay['id']);

        $this->expectException(\DomainException::class);
        $this->submissions->get(
            $this->otherSupplierId,
            $submission['id'],
        );
    }

    public function testExactBytesAreEncryptedImmutableAndIdempotent(): void
    {
        $submission = $this->preparedSubmission();
        $part = $this->submissions->addPart(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            'jmhz-summary',
            'JMHZ',
            'office:synthetic',
            'run_revision',
            'revision:synthetic',
            str_repeat('b', 64),
        );
        $bytes = "<?xml version=\"1.0\"?><synthetic>žluťoučký</synthetic>";
        $artifact = $this->submissions->storeArtifact(
            $this->supplierId,
            $submission['id'],
            $part['submission_row_version'],
            $part['id'],
            'outbound_xml',
            'outbound',
            'application/xml',
            $bytes,
            'jmhz-1.0-test',
            'catalog-test',
            'manual_upload',
            'artifact-jmhz-summary',
        );
        $replayed = $this->submissions->storeArtifact(
            $this->supplierId,
            $submission['id'],
            $artifact['submission_row_version'],
            $part['id'],
            'outbound_xml',
            'outbound',
            'application/xml',
            $bytes,
            'jmhz-1.0-test',
            'catalog-test',
            'manual_upload',
            'artifact-jmhz-summary',
        );

        self::assertTrue($artifact['created']);
        self::assertFalse($replayed['created']);
        self::assertSame(hash('sha256', $bytes), $artifact['artifact_sha256']);
        self::assertSame(
            $bytes,
            $this->submissions->artifactBytes(
                $this->supplierId,
                $artifact['id'],
            ),
        );
        $stored = $this->db->pdo()->prepare(
            'SELECT content_ciphertext
               FROM payroll_submission_artifacts
              WHERE supplier_id = ? AND id = ?',
        );
        $stored->execute([$this->supplierId, $artifact['id']]);
        $ciphertext = (string) $stored->fetchColumn();
        self::assertStringStartsWith('enc:v2:', $ciphertext);
        self::assertStringNotContainsString('žluťoučký', $ciphertext);

        try {
            $this->submissions->storeArtifact(
                $this->supplierId,
                $submission['id'],
                $artifact['submission_row_version'],
                $part['id'],
                'outbound_xml',
                'outbound',
                'application/xml',
                '<different/>',
                'jmhz-1.0-test',
                'catalog-test',
                'manual_upload',
                'artifact-jmhz-summary',
            );
            self::fail('Změněné bajty nesmějí projít stejnou idempotencí.');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_submission_artifacts
                SET byte_size = byte_size + 1
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $artifact['id']]);
    }

    public function testSubmittedIsNotAcceptedUntilReceiptAndCorrectionIsExplicit(): void
    {
        $submission = $this->preparedSubmission();
        $validated = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            'validated',
        );
        $ready = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $validated['row_version'],
            'ready',
        );
        $submitted = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $ready['row_version'],
            'submitted',
            'synthetic-correlation',
        );
        self::assertSame('submitted', $submitted['status']);

        try {
            $this->submissions->transition(
                $this->supplierId,
                $submission['id'],
                $submitted['row_version'],
                'accepted',
            );
            self::fail('Submitted se nesmí rovnat accepted.');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }

        $receiptBytes = '<receipt status="rejected"/>';
        $receipt = $this->submissions->importReceipt(
            $this->supplierId,
            $submission['id'],
            $submitted['row_version'],
            null,
            $receiptBytes,
            'receipt:test:1',
            'synthetic-correlation',
            'REMOTE-VALIDATION',
            'rejected',
            'manual_upload',
            'receipt-import-1',
            null,
            new class implements PayrollReceiptVerifierInterface {
                public function verify(
                    string $bytes,
                    string $channel,
                    string $environment,
                    ?string $expectedCorrelationReference,
                ): PayrollVerifiedReceipt {
                    return new PayrollVerifiedReceipt(
                        'rejected',
                        $expectedCorrelationReference,
                    );
                }
            },
        );
        self::assertSame('rejected', $receipt['submission_status']);
        self::assertSame(
            hash('sha256', $receiptBytes),
            $receipt['artifact_sha256'],
        );

        $correction = $this->submissions->importReceipt(
            $this->supplierId,
            $submission['id'],
            $receipt['submission_row_version'],
            null,
            '<receipt status="correction_required"/>',
            'receipt:test:2',
            'synthetic-correlation',
            'REMOTE-CORRECTION',
            'correction_required',
            'manual_upload',
            'receipt-import-2',
            null,
            $this->trustedVerifier('correction_required'),
        );
        self::assertSame(
            'correction_required',
            $correction['submission_status'],
        );

        $this->expectException(PayrollSubmissionConflictException::class);
        $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $submitted['row_version'],
            'cancelled_in_time',
        );
    }

    public function testObligationIdempotencyRejectsDifferentCanonicalInputs(): void
    {
        $this->createObligation();

        $this->expectException(\DomainException::class);
        $this->obligations->register(
            $this->supplierId,
            'JMHZ',
            'office',
            'office:synthetic',
            '2026-07-01',
            '2026-07-31',
            'regular',
            'manual_upload',
            'payroll_run_approved',
            'run:synthetic:2026-07',
            str_repeat('c', 64),
            '2026-08-01',
            '2026-08-21',
            'calendar_days',
            'jmhz-deadline-test',
            str_repeat('d', 64),
            'obligation-jmhz-2026-07',
        );
    }

    public function testUnverifiedReceiptCannotDeclareLegalAcceptance(): void
    {
        $submission = $this->submittedSubmission('receipt-untrusted');
        $receipt = $this->submissions->importReceipt(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            null,
            '<anything status="accepted"/>',
            'receipt:untrusted:1',
            'synthetic-correlation',
            'UNVERIFIED-UPLOAD',
            'accepted',
            'manual_upload',
            'receipt-untrusted-1',
        );

        self::assertSame('submitted', $receipt['submission_status']);
        self::assertFalse($receipt['trusted']);

        $this->expectException(\DomainException::class);
        $this->submissions->importReceipt(
            $this->supplierId,
            $submission['id'],
            $receipt['submission_row_version'],
            null,
            '<anything status="accepted"/>',
            'receipt:untrusted:1',
            'synthetic-correlation',
            'DIFFERENT-PROTOCOL',
            'accepted',
            'manual_upload',
            'receipt-untrusted-1',
        );
    }

    public function testTrustedReceiptUsesVerifiedInsteadOfDeclaredCorrelation(): void
    {
        $submitted = $this->submittedSubmissionWithoutCorrelation();
        $receipt = $this->submissions->importReceipt(
            $this->supplierId,
            $submitted['id'],
            $submitted['row_version'],
            null,
            '<receipt status="accepted"/>',
            'receipt:verified-correlation',
            'caller-controlled-correlation',
            'REMOTE',
            'accepted',
            'manual_upload',
            'receipt-verified-correlation',
            null,
            $this->trustedVerifierWithCorrelation(
                'accepted',
                'verified-correlation',
            ),
        );

        self::assertSame('accepted', $receipt['submission_status']);
        $stored = $this->submissions->get(
            $this->supplierId,
            $submitted['id'],
        );
        self::assertSame(
            'verified-correlation',
            $stored['correlation_reference'],
        );
    }

    public function testTrustedReceiptWithoutVerifiedCorrelationIgnoresDeclaredOne(): void
    {
        $submitted = $this->submittedSubmissionWithoutCorrelation();
        $receipt = $this->submissions->importReceipt(
            $this->supplierId,
            $submitted['id'],
            $submitted['row_version'],
            null,
            '<receipt status="accepted"/>',
            'receipt:null-verified-correlation',
            'caller-controlled-correlation',
            'REMOTE',
            'accepted',
            'manual_upload',
            'receipt-null-verified-correlation',
            null,
            $this->trustedVerifierWithCorrelation('accepted', null),
        );

        self::assertSame('accepted', $receipt['submission_status']);
        $stored = $this->submissions->get(
            $this->supplierId,
            $submitted['id'],
        );
        self::assertNull($stored['correlation_reference']);
    }

    #[DataProvider('verifiedRemoteStatuses')]
    public function testPublicTransitionCannotApplyVerifiedRemoteStatus(
        string $targetStatus,
        bool $prepareProcessing,
    ): void {
        $submitted = $this->submittedSubmission(
            'public-remote-' . $targetStatus,
        );
        $rowVersion = $submitted['row_version'];
        if ($prepareProcessing) {
            $processing = $this->submissions->importReceipt(
                $this->supplierId,
                $submitted['id'],
                $rowVersion,
                null,
                '<receipt status="processing"/>',
                'receipt:processing:' . $targetStatus,
                'synthetic-correlation-public-remote-' . $targetStatus,
                'REMOTE',
                'processing',
                'manual_upload',
                'receipt-processing-' . $targetStatus,
                null,
                $this->trustedVerifier('processing'),
            );
            self::assertSame('processing', $processing['submission_status']);
            $rowVersion = $processing['submission_row_version'];
        }

        $this->expectException(\DomainException::class);
        $this->submissions->transition(
            $this->supplierId,
            $submitted['id'],
            $rowVersion,
            $targetStatus,
        );
    }

    /** @return iterable<string,array{string,bool}> */
    public static function verifiedRemoteStatuses(): iterable
    {
        yield 'processing' => ['processing', false];
        yield 'accepted' => ['accepted', true];
        yield 'partially accepted' => ['partially_accepted', false];
        yield 'rejected' => ['rejected', false];
        yield 'waiting for identity' => ['waiting_for_identity', false];
        yield 'correction required' => ['correction_required', false];
    }

    public function testCorrectionCannotTargetUnrelatedObligation(): void
    {
        $original = $this->preparedSubmission();
        $otherObligation = $this->obligations->register(
            $this->supplierId,
            'JMHZ',
            'office',
            'office:other',
            '2026-08-01',
            '2026-08-31',
            'correction',
            'manual_upload',
            'correction_requested',
            'run:synthetic:2026-08',
            str_repeat('e', 64),
            '2026-09-01',
            '2026-09-20',
            'calendar_days',
            'jmhz-deadline-test',
            str_repeat('f', 64),
            'obligation-jmhz-2026-08-correction',
        );

        $this->expectException(\DomainException::class);
        $this->submissions->prepare(
            $this->supplierId,
            $otherObligation['id'],
            'correction',
            'manual_upload',
            str_repeat('a', 64),
            'correction-unrelated',
            null,
            $original['id'],
        );
    }

    public function testCorrectionCanOnlyFollowAcceptedSubmissionOfSameScope(): void
    {
        $submitted = $this->submittedSubmission('valid-correction');
        $accepted = $this->submissions->importReceipt(
            $this->supplierId,
            $submitted['id'],
            $submitted['row_version'],
            null,
            '<receipt status="accepted"/>',
            'receipt:accepted:correction',
            'synthetic-correlation-valid-correction',
            'REMOTE',
            'accepted',
            'manual_upload',
            'receipt-valid-correction',
            null,
            $this->trustedVerifier('accepted'),
        );
        self::assertSame('accepted', $accepted['submission_status']);
        $correctionObligation = $this->obligations->register(
            $this->supplierId,
            'JMHZ',
            'office',
            'office:synthetic',
            '2026-07-01',
            '2026-07-31',
            'correction',
            'manual_upload',
            'correction_requested',
            'correction:synthetic:2026-07',
            str_repeat('e', 64),
            '2026-08-01',
            '2026-08-28',
            'calendar_days',
            'jmhz-correction-test',
            str_repeat('f', 64),
            'obligation-jmhz-2026-07-correction',
        );
        $correction = $this->submissions->prepare(
            $this->supplierId,
            $correctionObligation['id'],
            'correction',
            'manual_upload',
            str_repeat('a', 64),
            'correction-valid',
            null,
            $submitted['id'],
        );

        self::assertTrue($correction['created']);
        self::assertSame('correction', $correction['submission_kind']);
    }

    /**
     * INVARIANTA: agenda, která si rozšíření nedeklarovala, nesmí navázat
     * opravu na podání, o kterém úřad ještě nerozhodl.
     *
     * Kdyby to šlo plošně, agendy s okamžitým protokolem (EPO) by dovolily
     * podat opravu dřív, než se ví, jestli originál prošel — a duplicitní
     * podání se pozná až u správce daně, kdy se s tím nedá nic dělat.
     * JMHZ25 má naopak výslovně užší pravidlo; tady neznámá agenda drží
     * bezpečný obecný fallback, aby ho nikdo nerozvolnil zpátky omylem.
     */
    public function testCorrectionCannotFollowPendingSubmissionOfUndeclaredAgenda(): void
    {
        // Agenda „JMHZ" (bez ročníku) není deklarovaná agenda měsíčního
        // hlášení; i neznámý kód proto musí zůstat přísný.
        self::assertFalse(
            PayrollAgendaCorrectionPolicy::allowsPendingPredecessor('JMHZ'),
        );
        $submitted = $this->submittedSubmission('pending-predecessor');
        self::assertSame('submitted', $submitted['status']);
        $correctionObligation = $this->obligations->register(
            $this->supplierId,
            'JMHZ',
            'office',
            'office:synthetic',
            '2026-07-01',
            '2026-07-31',
            'correction',
            'manual_upload',
            'correction_requested',
            'correction:pending:2026-07',
            str_repeat('e', 64),
            '2026-08-01',
            '2026-08-28',
            'calendar_days',
            'jmhz-correction-test',
            str_repeat('f', 64),
            'obligation-jmhz-2026-07-pending-correction',
        );

        $this->expectException(\DomainException::class);
        $this->submissions->prepare(
            $this->supplierId,
            $correctionObligation['id'],
            'correction',
            'manual_upload',
            str_repeat('a', 64),
            'correction-pending-predecessor',
            null,
            $submitted['id'],
        );
    }

    public function testSubmissionCannotStartBeforeEarliestLegalDate(): void
    {
        $obligation = $this->obligations->register(
            $this->supplierId,
            'JMHZ',
            'office',
            'office:future-window',
            '2026-07-01',
            '2026-07-31',
            'regular',
            'manual_upload',
            'payroll_run_approved',
            'run:synthetic:future-window',
            str_repeat('1', 64),
            '2026-08-10',
            '2026-08-20',
            'calendar_days',
            'jmhz-deadline-test',
            str_repeat('2', 64),
            'obligation-future-window',
        );
        $submission = $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            'manual_upload',
            str_repeat('3', 64),
            'submission-future-window',
        );
        $validated = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            'validated',
        );
        $ready = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $validated['row_version'],
            'ready',
        );

        $this->expectException(\DomainException::class);
        $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $ready['row_version'],
            'submitted',
        );
    }

    public function testCorrelationReferenceIsSetOnce(): void
    {
        $submitted = $this->submittedSubmission('correlation-set-once');

        $this->expectException(\DomainException::class);
        $this->submissions->transition(
            $this->supplierId,
            $submitted['id'],
            $submitted['row_version'],
            'processing',
            'different-correlation',
        );
    }

    public function testAgendaMatrixRejectsOverlapAndUnknownBlocksAutomation(): void
    {
        $matrixId = $this->obligations->registerAgendaMatrix(
            $this->supplierId,
            'JMHZ',
            '2026-01-01',
            null,
            'unknown',
            'agenda-test',
            str_repeat('4', 64),
        );
        self::assertSame(
            $matrixId,
            $this->obligations->registerAgendaMatrix(
                $this->supplierId,
                'JMHZ',
                '2026-01-01',
                null,
                'unknown',
                'agenda-test',
                str_repeat('4', 64),
            ),
        );
        self::assertFalse(
            $this->obligations->isAgendaAutomationAllowed(
                $this->supplierId,
                'JMHZ',
                '2026-07-01',
            ),
        );

        $this->expectException(\DomainException::class);
        $this->obligations->registerAgendaMatrix(
            $this->supplierId,
            'JMHZ',
            '2026-06-01',
            '2026-12-31',
            'standalone',
            'agenda-test-2',
            str_repeat('5', 64),
        );
    }

    public function testProductionAndTestEnvironmentHaveIndependentIdempotency(): void
    {
        $production = $this->createObligation();
        $test = $this->obligations->register(
            $this->supplierId,
            'JMHZ',
            'office',
            'office:synthetic',
            '2026-07-01',
            '2026-07-31',
            'regular',
            'manual_upload',
            'payroll_run_approved',
            'run:synthetic:2026-07',
            str_repeat('c', 64),
            '2026-08-01',
            '2026-08-20',
            'calendar_days',
            'jmhz-deadline-test',
            str_repeat('d', 64),
            'obligation-jmhz-2026-07',
            null,
            null,
            null,
            'test',
        );

        self::assertTrue($production['created']);
        self::assertTrue($test['created']);
        self::assertNotSame($production['id'], $test['id']);
    }

    public function testApprovedSourceRevisionMustMatchPeriodAndHash(): void
    {
        $pdo = $this->db->pdo();
        $statement = $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-07-01", "2026-08-20", "approved", 1)',
        );
        $statement->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $resultHash = str_repeat('6', 64);
        $statement = $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "test.v1", ?, "{}", ?,
                     "{}", ?, ?, NOW())',
        );
        $statement->execute([
            $this->supplierId,
            $runId,
            str_repeat('7', 64),
            str_repeat('8', 64),
            $resultHash,
            hash('sha256', 'revision-idempotency', true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $obligation = $this->obligations->register(
            $this->supplierId,
            'JMHZ',
            'payroll_run',
            'payroll_run:' . $runId,
            '2026-07-01',
            '2026-07-31',
            'regular',
            'manual_upload',
            'payroll_run_approved',
            'run:' . $runId,
            $resultHash,
            '2026-08-01',
            '2026-08-20',
            'calendar_days',
            'jmhz-deadline-test',
            str_repeat('d', 64),
            'obligation-source-revision',
        );

        try {
            $this->submissions->prepare(
                $this->supplierId,
                $obligation['id'],
                'regular',
                'manual_upload',
                str_repeat('9', 64),
                'source-revision-wrong-hash',
                $revisionId,
            );
            self::fail('Podvržený hash schválené revize nesmí projít.');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }

        $submission = $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            'manual_upload',
            $resultHash,
            'source-revision-correct-hash',
            $revisionId,
        );
        self::assertTrue($submission['created']);
    }

    public function testLateTrustedReceiptIsArchivedWithoutStateRegression(): void
    {
        $submitted = $this->submittedSubmission('late-receipt');
        $first = $this->submissions->importReceipt(
            $this->supplierId,
            $submitted['id'],
            $submitted['row_version'],
            null,
            '<receipt status="rejected"/>',
            'receipt:late:1',
            'synthetic-correlation-late-receipt',
            'REMOTE',
            'rejected',
            'manual_upload',
            'receipt-late-1',
            null,
            $this->trustedVerifier('rejected'),
        );
        self::assertSame('rejected', $first['submission_status']);

        $late = $this->submissions->importReceipt(
            $this->supplierId,
            $submitted['id'],
            $first['submission_row_version'],
            null,
            '<receipt status="submitted" sequence="older"/>',
            'receipt:late:2',
            'synthetic-correlation-late-receipt',
            'REMOTE',
            'submitted',
            'manual_upload',
            'receipt-late-2',
            null,
            $this->trustedVerifier('submitted'),
        );
        self::assertTrue($late['created']);
        self::assertSame('rejected', $late['submission_status']);
    }

    public function testTrustedReceiptCanFinishAfterClosedYearIsReopened(): void
    {
        $submitted = $this->submittedSubmission('closed-year-receipt');
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_year_closures
                (supplier_id, calendar_year, status, row_version, closed_at)
             VALUES (?, 2026, 'closed', 1, NOW())",
        )->execute([$this->supplierId]);
        $bytes = '<receipt status="accepted"/>';
        $first = $this->submissions->importReceipt(
            $this->supplierId,
            $submitted['id'],
            $submitted['row_version'],
            null,
            $bytes,
            'receipt:closed-year',
            'synthetic-correlation-closed-year-receipt',
            'REMOTE',
            'accepted',
            'manual_upload',
            'receipt-closed-year',
            null,
            $this->trustedVerifier('accepted'),
        );
        self::assertTrue($first['created']);
        self::assertSame('submitted', $first['submission_status']);
        self::assertTrue($first['year_close_reopen_required']);

        $this->db->pdo()->prepare(
            "UPDATE payroll_year_closures
                SET status = 'open', row_version = row_version + 1,
                    closed_at = NULL, reopened_at = NOW()
              WHERE supplier_id = ? AND calendar_year = 2026",
        )->execute([$this->supplierId]);
        $replayed = $this->submissions->importReceipt(
            $this->supplierId,
            $submitted['id'],
            $first['submission_row_version'],
            null,
            $bytes,
            'receipt:closed-year',
            'synthetic-correlation-closed-year-receipt',
            'REMOTE',
            'accepted',
            'manual_upload',
            'receipt-closed-year',
            null,
            $this->trustedVerifier('accepted'),
        );

        self::assertFalse($replayed['created']);
        self::assertSame('accepted', $replayed['submission_status']);
        self::assertFalse($replayed['year_close_reopen_required']);
    }

    public function testTrustedReceiptUpdatesOnlyVerifiedPartMonotonically(): void
    {
        $submission = $this->preparedSubmission();
        $part = $this->submissions->addPart(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            'verified-part',
            'JMHZ',
            'office:synthetic',
            'run_revision',
            'revision:synthetic',
            str_repeat('a', 64),
        );
        $validated = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $part['submission_row_version'],
            'validated',
        );
        $ready = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $validated['row_version'],
            'ready',
        );
        $submitted = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $ready['row_version'],
            'submitted',
            'synthetic-correlation-part',
        );
        $receipt = $this->submissions->importReceipt(
            $this->supplierId,
            $submission['id'],
            $submitted['row_version'],
            $part['id'],
            '<receipt status="accepted"/>',
            'receipt:part:1',
            'synthetic-correlation-part',
            'REMOTE',
            'accepted',
            'manual_upload',
            'receipt-part-1',
            null,
            $this->trustedVerifier('accepted', [$part['id'] => 'accepted']),
        );
        self::assertSame('accepted', $receipt['submission_status']);
        $statement = $this->db->pdo()->prepare(
            'SELECT status FROM payroll_submission_parts
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $part['id']]);
        self::assertSame('accepted', $statement->fetchColumn());
    }

    public function testArtifactAadBindsImmutableSemanticMetadata(): void
    {
        $submission = $this->preparedSubmission();
        $bytes = '<synthetic/>';
        $artifact = $this->submissions->storeArtifact(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            null,
            'outbound_xml',
            'outbound',
            'application/xml',
            $bytes,
            'test-xsd',
            'test-catalog',
            'manual_upload',
            'artifact-aad-original',
        );
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_artifacts
                (supplier_id, environment, submission_id, part_id,
                 artifact_kind, direction, mime_type, content_ciphertext,
                 byte_size, artifact_sha256, xsd_version, catalog_version,
                 channel, idempotency_key_hash)
             SELECT supplier_id, environment, submission_id, part_id,
                    "manual_attachment", "internal", mime_type,
                    content_ciphertext, byte_size, artifact_sha256,
                    xsd_version, catalog_version, channel, ?
               FROM payroll_submission_artifacts
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([
            hash('sha256', 'artifact-aad-copy', true),
            $this->supplierId,
            $artifact['id'],
        ]);
        $copiedArtifactId = (int) $this->db->pdo()->lastInsertId();

        $this->expectException(\RuntimeException::class);
        $this->submissions->artifactBytes(
            $this->supplierId,
            $copiedArtifactId,
        );
    }

    public function testCompositeFkRejectsPartFromAnotherSubmissionInSameTenant(): void
    {
        $first = $this->preparedSubmission();
        $part = $this->submissions->addPart(
            $this->supplierId,
            $first['id'],
            $first['row_version'],
            'first-part',
            'JMHZ',
            'office:synthetic',
            'run_revision',
            'revision:first',
            str_repeat('a', 64),
        );
        $otherObligation = $this->obligations->register(
            $this->supplierId,
            'JMHZ',
            'office',
            'office:second',
            '2026-07-01',
            '2026-07-31',
            'regular',
            'manual_upload',
            'payroll_run_approved',
            'run:second',
            str_repeat('b', 64),
            '2026-08-01',
            '2026-08-20',
            'calendar_days',
            'jmhz-deadline-test',
            str_repeat('c', 64),
            'obligation-second',
        );
        $second = $this->submissions->prepare(
            $this->supplierId,
            $otherObligation['id'],
            'regular',
            'manual_upload',
            str_repeat('d', 64),
            'submission-second',
        );

        $this->expectException(\PDOException::class);
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_artifacts
                (supplier_id, environment, submission_id, part_id,
                 artifact_kind, direction, mime_type, content_ciphertext,
                 byte_size, artifact_sha256, channel, idempotency_key_hash)
             VALUES (?, "production", ?, ?, "manual_attachment",
                     "internal", "application/octet-stream", "enc:v2:test",
                     1, ?, "manual_upload", ?)',
        );
        $statement->execute([
            $this->supplierId,
            $second['id'],
            $part['id'],
            str_repeat('e', 64),
            hash('sha256', 'cross-submission-part', true),
        ]);
    }

    public function testDeadlineAndAgendaRowsAreDatabaseImmutable(): void
    {
        $obligation = $this->createObligation();
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_submission_deadlines
                SET due_on = "2026-08-21"
              WHERE supplier_id = ? AND obligation_id = ?',
        );
        try {
            $statement->execute([$this->supplierId, $obligation['id']]);
            self::fail('Právní termín nesmí být přepsán.');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        $matrixId = $this->obligations->registerAgendaMatrix(
            $this->supplierId,
            'JMHZ',
            '2026-01-01',
            '2026-12-31',
            'standalone',
            'agenda-immutable',
            str_repeat('f', 64),
        );
        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_agenda_matrix
                SET replacement_mode = "unknown"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $matrixId]);
    }

    /** @return array{id:int,status:string,row_version:int} */
    private function submittedSubmission(string $key): array
    {
        $submission = $this->preparedSubmission();
        $validated = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            'validated',
        );
        $ready = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $validated['row_version'],
            'ready',
        );

        return $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $ready['row_version'],
            'submitted',
            'synthetic-correlation-' . $key,
        );
    }

    /** @return array{id:int,status:string,row_version:int} */
    private function submittedSubmissionWithoutCorrelation(): array
    {
        $submission = $this->preparedSubmission();
        $validated = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            'validated',
        );
        $ready = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $validated['row_version'],
            'ready',
        );

        return $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $ready['row_version'],
            'submitted',
        );
    }

    /**
     * @param array<int,string> $partStatuses
     */
    private function trustedVerifier(
        string $status,
        array $partStatuses = [],
    ): PayrollReceiptVerifierInterface {
        return new class ($status, $partStatuses) implements PayrollReceiptVerifierInterface {
            /** @param array<int,string> $partStatuses */
            public function __construct(
                private readonly string $status,
                private readonly array $partStatuses,
            ) {}

            public function verify(
                string $bytes,
                string $channel,
                string $environment,
                ?string $expectedCorrelationReference,
            ): PayrollVerifiedReceipt {
                return new PayrollVerifiedReceipt(
                    $this->status,
                    $expectedCorrelationReference,
                    $this->partStatuses,
                );
            }
        };
    }

    private function trustedVerifierWithCorrelation(
        string $status,
        ?string $correlationReference,
    ): PayrollReceiptVerifierInterface {
        return new class ($status, $correlationReference) implements PayrollReceiptVerifierInterface {
            public function __construct(
                private readonly string $status,
                private readonly ?string $correlationReference,
            ) {}

            public function verify(
                string $bytes,
                string $channel,
                string $environment,
                ?string $expectedCorrelationReference,
            ): PayrollVerifiedReceipt {
                return new PayrollVerifiedReceipt(
                    $this->status,
                    $this->correlationReference,
                );
            }
        };
    }

    /** @return array{id:int,due_on:string,created:bool} */
    private function createObligation(): array
    {
        return $this->obligations->register(
            $this->supplierId,
            'JMHZ',
            'office',
            'office:synthetic',
            '2026-07-01',
            '2026-07-31',
            'regular',
            'manual_upload',
            'payroll_run_approved',
            'run:synthetic:2026-07',
            str_repeat('c', 64),
            '2026-08-01',
            '2026-08-20',
            'calendar_days',
            'jmhz-deadline-test',
            str_repeat('d', 64),
            'obligation-jmhz-2026-07',
        );
    }

    /** @return array{id:int,row_version:int} */
    private function preparedSubmission(): array
    {
        $obligation = $this->createObligation();

        return $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            'manual_upload',
            str_repeat('a', 64),
            'regular-2026-07',
        );
    }
}
