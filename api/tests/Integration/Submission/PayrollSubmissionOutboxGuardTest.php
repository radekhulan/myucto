<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollSubmissionOutboxGuardTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private SubmissionOutboxService $outbox;
    private int $supplierId;
    private int $otherSupplierId;
    private int $recipientId;
    private int $userId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('submission_outbox')
            || !$this->db->hasTable('payroll_submission_artifacts')
        ) {
            $this->markTestSkipped('Migrace platformy podání neproběhly.');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT MIN(id) FROM supplier',
        )->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->userId = (int) $pdo->query(
            'SELECT MIN(id) FROM users',
        )->fetchColumn();

        $this->obligations = $container->get(PayrollObligationService::class);
        $this->submissions = $container->get(PayrollSubmissionService::class);
        $this->outbox = $container->get(SubmissionOutboxService::class);
        $recipients = $container->get(SubmissionRecipientRepository::class);
        $this->recipientId = $recipients->upsertForSupplier(
            $this->supplierId,
            [
                'code' => 'cssz_outbox_guard',
                'name' => 'Syntetická ČSSZ pro test fronty',
                'kind' => 'cssz',
                'isds_box_id' => 'abcdefg',
                'source_url' => 'https://example.test/cssz',
                'source_note' => 'Syntetický záznam',
                'is_active' => true,
            ],
            $this->userId,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testPreparedEldpControlXmlIsExplicitlyUntransportable(): void
    {
        $artifactId = $this->artifact(
            $this->supplierId,
            'ELDP',
            'test',
            'prepared',
        );

        $this->assertOutboxError(
            'payroll_artifact_untransportable',
            fn () => $this->enqueue(
                $this->supplierId,
                'test',
                'JMHZ25',
                $artifactId,
            ),
        );
    }

    public function testPayrollArtifactMustBelongToReadySubmission(): void
    {
        $artifactId = $this->artifact(
            $this->supplierId,
            'JMHZ25',
            'test',
            'prepared',
        );

        $this->assertOutboxError(
            'payroll_submission_not_ready',
            fn () => $this->enqueue(
                $this->supplierId,
                'test',
                'JMHZ25',
                $artifactId,
            ),
        );
    }

    public function testClientCannotSpoofPayrollAgenda(): void
    {
        $artifactId = $this->artifact(
            $this->supplierId,
            'JMHZ25',
            'test',
            'ready',
        );

        $this->assertOutboxError(
            'payroll_submission_agenda_mismatch',
            fn () => $this->enqueue(
                $this->supplierId,
                'test',
                'PPZ',
                $artifactId,
            ),
        );
    }

    public function testClientCannotSpoofPayrollEnvironment(): void
    {
        $artifactId = $this->artifact(
            $this->supplierId,
            'JMHZ25',
            'test',
            'ready',
        );

        $this->assertOutboxError(
            'payroll_submission_environment_mismatch',
            fn () => $this->enqueue(
                $this->supplierId,
                'production',
                'JMHZ25',
                $artifactId,
            ),
        );
    }

    public function testPayrollArtifactFromAnotherTenantIsInvisible(): void
    {
        $artifactId = $this->artifact(
            $this->otherSupplierId,
            'JMHZ25',
            'test',
            'ready',
        );

        $this->assertOutboxError(
            'artifact_not_found',
            fn () => $this->enqueue(
                $this->supplierId,
                'test',
                'JMHZ25',
                $artifactId,
            ),
        );
    }

    public function testReadyJmhzAndPpzArtifactsRemainEnqueueable(): void
    {
        foreach (['JMHZ25', 'PPZ'] as $agendaCode) {
            $artifactId = $this->artifact(
                $this->supplierId,
                $agendaCode,
                'test',
                'ready',
            );
            $queued = $this->enqueue(
                $this->supplierId,
                'test',
                $agendaCode,
                $artifactId,
            );

            self::assertTrue($queued['created']);
            self::assertSame($agendaCode, $queued['row']['agenda_code']);
            self::assertSame('test', $queued['row']['environment']);
            self::assertSame('ready', $queued['row']['dispatch_state']);
        }
    }

    public function testConfirmationRechecksAuthoritativeReadyState(): void
    {
        $artifactId = $this->artifact(
            $this->supplierId,
            'JMHZ25',
            'test',
            'ready',
        );
        $queued = $this->enqueue(
            $this->supplierId,
            'test',
            'JMHZ25',
            $artifactId,
        );
        $statement = $this->db->pdo()->prepare(
            'SELECT submission.id, submission.row_version
               FROM payroll_submission_artifacts artifact
               JOIN payroll_submissions submission
                 ON submission.supplier_id = artifact.supplier_id
                AND submission.id = artifact.submission_id
              WHERE artifact.supplier_id = ? AND artifact.id = ?',
        );
        $statement->execute([$this->supplierId, $artifactId]);
        $submission = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($submission);
        $this->submissions->transition(
            $this->supplierId,
            (int) $submission['id'],
            (int) $submission['row_version'],
            'validated',
        );

        $result = $this->outbox->confirmAndSend(
            $this->supplierId,
            (int) $queued['row']['id'],
            $this->userId,
            new ChannelContext(
                $this->supplierId,
                'test',
                new ChannelCredentials('zzzzzzz', 'certificate'),
            ),
        );

        self::assertFalse($result['dispatched']);
        self::assertSame('failed', $result['row']['dispatch_state']);
        self::assertSame(
            'payroll_submission_not_ready',
            $result['row']['last_error_code'],
        );
    }

    /** @return array{row:array<string,mixed>,created:bool} */
    private function enqueue(
        int $supplierId,
        string $environment,
        string $agendaCode,
        int $artifactId,
    ): array {
        return $this->outbox->enqueue(
            $supplierId,
            $environment,
            'isds',
            $agendaCode,
            'payroll_submission',
            $artifactId,
            $this->recipientId,
            'Syntetické mzdové podání',
            $this->userId,
        );
    }

    private function assertOutboxError(
        string $errorCode,
        callable $operation,
    ): void
    {
        try {
            $operation();
        } catch (SubmissionChannelException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
            return;
        }

        self::fail("Fronta měla odmítnout podání chybou {$errorCode}.");
    }

    private function artifact(
        int $supplierId,
        string $agendaCode,
        string $environment,
        string $targetStatus,
    ): int {
        $tag = strtolower($agendaCode) . '-' . ++$this->sequence;
        $submissionChannel = match ($agendaCode) {
            'JMHZ25' => 'vrep_apep',
            'PPZ' => 'health_portal',
            'ELDP' => 'other',
            default => 'isds',
        };
        $obligation = $this->obligations->register(
            $supplierId,
            $agendaCode,
            'office',
            'office:' . $tag,
            '2026-08-01',
            '2026-08-31',
            'regular',
            $submissionChannel,
            'synthetic_outbox_guard',
            'source:' . $tag,
            hash('sha256', 'source:' . $tag),
            '2026-09-01',
            '2026-09-20',
            'calendar_days',
            'synthetic-outbox-guard.v1',
            hash('sha256', 'rules:' . $tag),
            'obligation:' . $tag,
            null,
            $this->userId,
            null,
            $environment,
        );
        $submission = $this->submissions->prepare(
            $supplierId,
            $obligation['id'],
            'regular',
            $submissionChannel,
            hash('sha256', 'snapshot:' . $tag),
            'submission:' . $tag,
            null,
            null,
            $this->userId,
            $environment,
        );
        $artifact = $this->submissions->storeArtifact(
            $supplierId,
            $submission['id'],
            $submission['row_version'],
            null,
            'outbound_xml',
            'outbound',
            'application/xml',
            '<synthetic agenda="' . $agendaCode . '"/>',
            // Verze XSD, proti kterému se datová věta ověřila při mrazení.
            // Produkční cesty ji zapisují vždycky a fronta ji nově VYŽADUJE:
            // bez ní není doložené, že podklad prošel schématem, a poslední
            // brána před datovou schránkou by ho pustila mlčky.
            'synthetic-' . strtolower($agendaCode) . '.v1',
            null,
            $submissionChannel,
            'artifact:' . $tag,
            $this->userId,
        );
        if ($targetStatus === 'draft') {
            return $artifact['id'];
        }
        $validated = $this->submissions->transition(
            $supplierId,
            $submission['id'],
            $artifact['submission_row_version'],
            'validated',
        );
        if ($targetStatus === 'validated') {
            return $artifact['id'];
        }
        $this->submissions->transition(
            $supplierId,
            $submission['id'],
            $validated['row_version'],
            $targetStatus,
        );

        return $artifact['id'];
    }
}
