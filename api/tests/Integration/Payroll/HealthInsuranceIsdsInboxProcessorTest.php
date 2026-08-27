<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Submission\SubmissionInboxRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceIsdsInboxProcessor;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use MyInvoice\Service\Submission\Channel\InboxMessageHeader;
use MyInvoice\Service\Submission\CompositeSubmissionInboxMessageProcessor;
use MyInvoice\Service\Submission\InboxMessageClassifier;
use MyInvoice\Service\Submission\SubmissionInboxMessageProcessor;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Odpověď pojišťovny je důkaz pro člověka, ne strojový výrok o přijetí.
 * Každá větev proto musí skončit bez mutace mzdového podání.
 */
#[Group('integration')]
final class HealthInsuranceIsdsInboxProcessorTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private SubmissionInboxRepository $inbox;
    private SubmissionOutboxRepository $outbox;
    private PayrollSubmissionRepository $payroll;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private HealthInsuranceIsdsInboxProcessor $processor;
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
        $this->inbox = new SubmissionInboxRepository($connection);
        if (!$this->inbox->isAvailable()
            || !$connection->hasTable('payroll_submissions')
            || !$connection->hasTable('submission_outbox')
        ) {
            $this->markTestSkipped('Chybí migrace inboxu nebo platformy mzdových podání.');
        }
        $this->outbox = new SubmissionOutboxRepository($connection);
        $this->payroll = new PayrollSubmissionRepository($connection);
        $clock = new MockClock('2026-08-27 10:00:00 Europe/Prague');
        $this->obligations = new PayrollObligationService($this->payroll, $clock);
        $this->submissions = new PayrollSubmissionService(
            $this->payroll,
            new PayrollSubmissionStateMachine(),
            $encryption,
            $clock,
        );
        $this->processor = new HealthInsuranceIsdsInboxProcessor(
            $this->inbox,
            $this->outbox,
            $this->payroll,
        );

        $pdo = $connection->pdo();
        $pdo->beginTransaction();
        $sourceStatement = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $sourceStatement);
        $sourceSupplier = (int) $sourceStatement->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testLinkedHealthResponseIsArchivedForManualReviewWithoutChangingSubmission(): void
    {
        $fixture = $this->fixture($this->supplierId, HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW);
        $message = $this->inboxMessage($this->supplierId, $fixture['outbox_id']);
        $beforeSubmission = $this->payroll->findSubmission($this->supplierId, $fixture['submission_id']);
        $beforeOutbox = $this->outbox->find($this->supplierId, $fixture['outbox_id']);

        $result = $this->process($this->supplierId, 'production', $message, $fixture['outbox_id']);

        self::assertSame('manual_review', $result['status']);
        self::assertSame('health_isds_response_archived_for_manual_review', $result['code']);
        self::assertSame($fixture['submission_id'], $result['submission_id']);
        self::assertNull($result['receipt_id']);
        self::assertNull($result['remote_status']);
        self::assertSame($beforeSubmission, $this->payroll->findSubmission($this->supplierId, $fixture['submission_id']));
        self::assertSame($beforeOutbox, $this->outbox->find($this->supplierId, $fixture['outbox_id']));
    }

    public function testCompositeBindingKeepsJmhzProcessorAvailableAlongsideHealthProcessor(): void
    {
        $resolved = Bootstrap::buildContainer()->get(SubmissionInboxMessageProcessor::class);

        self::assertInstanceOf(CompositeSubmissionInboxMessageProcessor::class, $resolved);
    }

    public function testCrossTenantInboxCannotBeUsedToReachAnotherTenantsSubmission(): void
    {
        $fixture = $this->fixture($this->otherSupplierId, HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW);
        $message = $this->inboxMessage($this->otherSupplierId, $fixture['outbox_id']);

        $result = $this->process($this->supplierId, 'production', $message, $fixture['outbox_id']);

        self::assertSame('manual_review', $result['status']);
        self::assertSame('health_isds_inbox_scope_mismatch', $result['code']);
        self::assertNull($result['submission_id']);
        self::assertSame('draft', $this->submissionStatus(
            $this->otherSupplierId,
            $fixture['submission_id'],
        ));
    }

    public function testEnvironmentMismatchCannotReachLinkedSubmission(): void
    {
        $fixture = $this->fixture($this->supplierId, HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW);
        $message = $this->inboxMessage($this->supplierId, $fixture['outbox_id']);

        $result = $this->process($this->supplierId, 'test', $message, $fixture['outbox_id']);

        self::assertSame('manual_review', $result['status']);
        self::assertSame('health_isds_inbox_scope_mismatch', $result['code']);
        self::assertSame('draft', $this->submissionStatus(
            $this->supplierId,
            $fixture['submission_id'],
        ));
    }

    public function testNonHealthAgendaCannotBeTreatedAsHealthInsurerResponse(): void
    {
        $fixture = $this->fixture($this->supplierId, 'JMHZ26');
        $message = $this->inboxMessage($this->supplierId, $fixture['outbox_id']);

        $result = $this->process($this->supplierId, 'production', $message, $fixture['outbox_id']);

        self::assertSame('manual_review', $result['status']);
        self::assertSame('health_isds_payroll_submission_scope_mismatch', $result['code']);
        self::assertSame($fixture['submission_id'], $result['submission_id']);
        self::assertSame('draft', $this->submissionStatus(
            $this->supplierId,
            $fixture['submission_id'],
        ));
    }

    public function testOutboxMustUseIsdsAndPayrollSubmissionArtifact(): void
    {
        $fixture = $this->fixture($this->supplierId, HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION, 'epo');
        $message = $this->inboxMessage($this->supplierId, $fixture['outbox_id']);

        $result = $this->process($this->supplierId, 'production', $message, $fixture['outbox_id']);

        self::assertSame('manual_review', $result['status']);
        self::assertSame('health_isds_outbox_scope_mismatch', $result['code']);
        self::assertSame('draft', $this->submissionStatus(
            $this->supplierId,
            $fixture['submission_id'],
        ));
    }

    public function testOutboxMustReferenceAPayrollSubmissionArtifact(): void
    {
        $fixture = $this->fixture(
            $this->supplierId,
            HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION,
            artifactKind: 'document',
        );
        $message = $this->inboxMessage($this->supplierId, $fixture['outbox_id']);

        $result = $this->process($this->supplierId, 'production', $message, $fixture['outbox_id']);

        self::assertSame('manual_review', $result['status']);
        self::assertSame('health_isds_outbox_scope_mismatch', $result['code']);
        self::assertSame('draft', $this->submissionStatus(
            $this->supplierId,
            $fixture['submission_id'],
        ));
    }

    public function testUnmatchedHealthResponseCannotChangeAnySubmission(): void
    {
        $result = $this->processor->process(
            $this->supplierId,
            'production',
            123456,
            $this->header('DM-UNMATCHED'),
            [
                'classification' => InboxMessageClassifier::HEALTH_INSURER_RESPONSE,
                'matched_outbox_id' => null,
            ],
            'synthetic-zfo',
            null,
        );

        self::assertSame('manual_review', $result['status']);
        self::assertSame('health_isds_response_unmatched', $result['code']);
        self::assertNull($result['submission_id']);
    }

    /** @return array{submission_id:int,outbox_id:int} */
    private function fixture(
        int $supplierId,
        string $agendaCode,
        string $outboxChannel = 'isds',
        string $artifactKind = 'payroll_submission',
    ): array
    {
        $key = bin2hex(random_bytes(6));
        $obligation = $this->obligations->register(
            $supplierId,
            $agendaCode,
            'payroll_run',
            'payroll_run:fixture:' . $key,
            '2026-08-01',
            '2026-08-31',
            'regular',
            'health_portal',
            'payroll_health_fixture',
            'fixture:' . $key,
            hash('sha256', 'source:' . $key),
            '2026-08-01',
            '2026-09-20',
            'calendar_days',
            'health-inbox-fixture',
            hash('sha256', 'ruleset:' . $key),
            'obligation:' . $key,
            environment: 'production',
        );
        $submission = $this->submissions->prepare(
            $supplierId,
            $obligation['id'],
            'regular',
            'health_portal',
            hash('sha256', 'snapshot:' . $key),
            'submission:' . $key,
            environment: 'production',
        );
        $artifact = $this->submissions->storeArtifact(
            $supplierId,
            $submission['id'],
            $submission['row_version'],
            null,
            'outbound_xml',
            'outbound',
            'application/xml',
            '<synthetic-health-submission/>',
            'health-fixture',
            'health-fixture',
            'health_portal',
            'artifact:' . $key,
        );
        $queued = $this->outbox->enqueue([
            'supplier_id' => $supplierId,
            'environment' => 'production',
            'channel' => $outboxChannel,
            'agenda_code' => $agendaCode,
            'recipient_id' => null,
            'recipient_box_id' => 'vzp0000',
            'subject' => 'Syntetická odpověď zdravotní pojišťovny',
            'artifact_kind' => $artifactKind,
            'artifact_id' => $artifact['id'],
            'artifact_filename' => 'synthetic-health.xml',
            'artifact_sha256' => $artifact['artifact_sha256'],
            'correlation_reference' => 'HEALTH-' . strtoupper($key),
            'created_by' => null,
        ], 'outbox:' . $key);

        $outboxId = $queued['row']['id'] ?? null;
        self::assertIsInt($outboxId);

        return [
            'submission_id' => $submission['id'],
            'outbox_id' => $outboxId,
        ];
    }

    /** @return array{id:int,external_message_id:string} */
    private function inboxMessage(int $supplierId, int $outboxId): array
    {
        $messageId = 'DM-HEALTH-' . bin2hex(random_bytes(5));

        $stored = $this->inbox->record([
            'supplier_id' => $supplierId,
            'environment' => 'production',
            'channel' => 'isds',
            'external_message_id' => $messageId,
            'sender_box_id' => 'vzp0000',
            'sender_name' => 'Syntetická zdravotní pojišťovna',
            'subject' => 'Syntetická odpověď',
            'sender_ident' => null,
            'classification' => InboxMessageClassifier::HEALTH_INSURER_RESPONSE,
            'matched_outbox_id' => $outboxId,
            'document_id' => null,
            'delivered_at' => '2026-08-27 10:00:00',
            'accepted_at' => null,
            'raw_sha256' => hash('sha256', $messageId),
        ]);
        $id = $stored['id'] ?? null;
        self::assertIsInt($id);

        return ['id' => $id, 'external_message_id' => $messageId];
    }

    /**
     * @param array{id:int,external_message_id:string} $message
     * @return array{status:string,code:?string,submission_id:?int,receipt_id:?int,remote_status:?string}
     */
    private function process(int $supplierId, string $environment, array $message, int $outboxId): array
    {
        return $this->processor->process(
            $supplierId,
            $environment,
            $message['id'],
            $this->header($message['external_message_id']),
            [
                'classification' => InboxMessageClassifier::HEALTH_INSURER_RESPONSE,
                'matched_outbox_id' => $outboxId,
            ],
            'synthetic-zfo',
            null,
        );
    }

    private function header(string $messageId): InboxMessageHeader
    {
        return new InboxMessageHeader(
            $messageId,
            'vzp0000',
            'Syntetická zdravotní pojišťovna',
            'Syntetická odpověď',
            null,
            new \DateTimeImmutable('2026-08-27 10:00:00 UTC'),
            null,
        );
    }

    private function submissionStatus(int $supplierId, int $submissionId): string
    {
        $submission = $this->payroll->findSubmission($supplierId, $submissionId);
        self::assertNotNull($submission);

        return $submission['status'];
    }
}
