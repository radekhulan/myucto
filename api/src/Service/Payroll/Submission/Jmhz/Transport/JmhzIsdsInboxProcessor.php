<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Service\Document\ZfoExtractor;
use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionDispatchProjection;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceipt;
use MyInvoice\Service\Submission\Channel\InboxMessageHeader;
use MyInvoice\Service\Submission\InboxMessageClassifier;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Service\Submission\SubmissionInboxMessageProcessor;

/** Bezpečné zpracování protokolu ČSSZ staženého uvnitř příchozího ZFO. */
final readonly class JmhzIsdsInboxProcessor implements SubmissionInboxMessageProcessor
{
    public function __construct(
        private SubmissionOutboxRepository $outbox,
        private PayrollSubmissionRepository $payrollRepository,
        private PayrollSubmissionService $submissions,
        private PayrollSubmissionDispatchProjection $dispatchProjection,
        private SubmissionOutboxService $outboxService,
        private ZfoExtractor $zfo,
        private JmhzProtocolSignatureVerifierInterface $signatures,
        private JmhzProtocolParser $parser = new JmhzProtocolParser(),
    ) {}

    /**
     * @param array{classification:string,matched_outbox_id:?int} $verdict
     * @return array{status:string,code:?string,submission_id:?int,receipt_id:?int,remote_status:?string}
     */
    public function process(
        int $supplierId,
        string $environment,
        int $inboxMessageId,
        InboxMessageHeader $header,
        array $verdict,
        string $zfoBytes,
        ?int $actorUserId,
    ): array {
        if ($verdict['classification'] !== InboxMessageClassifier::CSSZ_PROTOCOL) {
            return self::result('not_applicable');
        }
        if ($verdict['matched_outbox_id'] === null) {
            return self::result('manual_review', 'jmhz_isds_response_unmatched');
        }

        $outbox = $this->outbox->find(
            $supplierId,
            $verdict['matched_outbox_id'],
        );
        if ($outbox === null
            || (string) $outbox['environment'] !== $environment
            || (string) $outbox['channel'] !== 'isds'
            || (string) $outbox['artifact_kind'] !== 'payroll_submission'
        ) {
            return self::result('manual_review', 'jmhz_isds_outbox_scope_mismatch');
        }

        $matcher = new JmhzIsdsResponseMatcher();
        $reference = $matcher->parseSubject($header->subject);
        $sentMessageId = trim((string) ($outbox['external_message_id'] ?? ''));
        if ($reference === null
            || !$matcher->matches(
                $header->subject,
                $sentMessageId,
                JmhzDispatchService::SUBMISSION_CLASS,
            )
        ) {
            return self::result('manual_review', 'jmhz_isds_response_identity_mismatch');
        }

        $attachment = $this->protocolAttachment(
            $zfoBytes,
            $reference,
        );
        if ($attachment === null) {
            return self::result('manual_review', 'jmhz_isds_protocol_attachment_missing');
        }

        $artifact = $this->payrollRepository->findArtifact(
            $supplierId,
            (int) $outbox['artifact_id'],
        );
        if ($artifact === null
            || (string) $artifact['environment'] !== $environment
            || (string) $artifact['direction'] !== 'outbound'
        ) {
            return self::result('manual_review', 'jmhz_isds_payroll_artifact_mismatch');
        }
        $submissionId = (int) $artifact['submission_id'];
        $this->dispatchProjection->project(
            $supplierId,
            'payroll_submission',
            (int) $outbox['artifact_id'],
            $sentMessageId,
        );
        $submission = $this->submissions->get($supplierId, $submissionId);
        if ((string) $submission['environment'] !== $environment
            || (string) $submission['channel'] !== 'isds'
        ) {
            return self::result(
                'manual_review',
                'jmhz_isds_payroll_submission_scope_mismatch',
                $submissionId,
            );
        }

        $report = $this->parser->parse(
            $attachment['bytes'],
            1,
            $reference->correlationId,
        );
        if (!hash_equals($reference->className, $report->submissionClass)) {
            return self::result(
                'manual_review',
                'jmhz_isds_protocol_class_mismatch',
                $submissionId,
            );
        }
        $declaredStatus = $report->status->payrollRemoteStatus();
        $idempotencyKey = 'jmhz-isds-inbox:' . $inboxMessageId
            . ':' . hash('sha256', $attachment['bytes']);
        $verifier = $this->boundVerifier($reference->correlationId);

        try {
            $receipt = $this->submissions->importReceipt(
                $supplierId,
                $submissionId,
                (int) $submission['row_version'],
                null,
                $attachment['bytes'],
                $header->externalMessageId,
                $reference->correlationId,
                $reference->className,
                $declaredStatus,
                'isds',
                $idempotencyKey,
                $actorUserId,
                $verifier,
            );
        } catch (\Throwable $exception) {
            $current = $this->submissions->get($supplierId, $submissionId);
            $receipt = $this->submissions->importReceipt(
                $supplierId,
                $submissionId,
                (int) $current['row_version'],
                null,
                $attachment['bytes'],
                $header->externalMessageId,
                $reference->correlationId,
                $reference->className,
                $declaredStatus,
                'isds',
                $idempotencyKey,
                $actorUserId,
                null,
            );

            return self::result(
                'manual_review',
                $exception instanceof JmhzTransportException
                    ? $exception->errorCode
                    : 'jmhz_isds_protocol_untrusted',
                $submissionId,
                (int) $receipt['id'],
            );
        }

        $this->outboxService->applyVerifiedProtocolOutcome(
            $supplierId,
            (int) $outbox['id'],
            $declaredStatus,
            'Ověřený protokol ČSSZ z datové schránky.',
        );

        return self::result(
            'processed',
            null,
            $submissionId,
            (int) $receipt['id'],
            $declaredStatus,
        );
    }

    /** @return array{name:string,bytes:string}|null */
    private function protocolAttachment(
        string $zfoBytes,
        JmhzIsdsResponseReference $reference,
    ): ?array {
        $expected = JmhzIsdsResponseMatcher::ATTACHMENT_PREFIX
            . $reference->className . '-'
            . $reference->correlationId . '-'
            . $reference->originalMessageId . '.xml';
        $matches = [];
        foreach ($this->zfo->extract($zfoBytes)['attachments'] as $attachment) {
            if (strcasecmp(basename($attachment['name']), $expected) === 0) {
                $matches[] = [
                    'name' => $attachment['name'],
                    'bytes' => $attachment['bytes'],
                ];
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function boundVerifier(
        string $protocolCorrelation,
    ): PayrollReceiptVerifierInterface {
        $delegate = new JmhzReceiptVerifier($this->signatures, $this->parser);

        return new readonly class ($delegate, $protocolCorrelation)
            implements PayrollReceiptVerifierInterface {
            public function __construct(
                private JmhzReceiptVerifier $delegate,
                private string $protocolCorrelation,
            ) {}

            public function verify(
                string $bytes,
                string $channel,
                string $environment,
                ?string $expectedCorrelationReference,
            ): PayrollVerifiedReceipt {
                $verified = $this->delegate->verify(
                    $bytes,
                    $channel,
                    $environment,
                    $this->protocolCorrelation,
                );

                return new PayrollVerifiedReceipt(
                    $verified->remoteStatus,
                    null,
                    $verified->partStatuses,
                    $verified->formOutcomes,
                );
            }
        };
    }

    /**
     * @return array{status:string,code:?string,submission_id:?int,receipt_id:?int,remote_status:?string}
     */
    private static function result(
        string $status,
        ?string $code = null,
        ?int $submissionId = null,
        ?int $receiptId = null,
        ?string $remoteStatus = null,
    ): array {
        return [
            'status' => $status,
            'code' => $code,
            'submission_id' => $submissionId,
            'receipt_id' => $receiptId,
            'remote_status' => $remoteStatus,
        ];
    }
}
