<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Submission\SubmissionInboxRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Service\Submission\Channel\InboxMessageHeader;
use MyInvoice\Service\Submission\InboxMessageClassifier;
use MyInvoice\Service\Submission\SubmissionInboxMessageProcessor;

/**
 * Bezpečně propojí odpověď zdravotní pojišťovny s odeslaným PPZ/HOZ.
 *
 * Neexistuje-li doložený strojový protokol pojišťovny, odpověď nikdy nemění
 * stav podání ani outboxu. Zůstává archivovaná v ISDS inboxu a explicitně
 * čeká na ruční vyhodnocení.
 */
final readonly class HealthInsuranceIsdsInboxProcessor implements SubmissionInboxMessageProcessor
{
    public function __construct(
        private SubmissionInboxRepository $inbox,
        private SubmissionOutboxRepository $outbox,
        private PayrollSubmissionRepository $payroll,
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
        if ($verdict['classification'] !== InboxMessageClassifier::HEALTH_INSURER_RESPONSE) {
            return self::result('not_applicable');
        }
        if ($verdict['matched_outbox_id'] === null) {
            return self::result('manual_review', 'health_isds_response_unmatched');
        }

        $inbox = $this->inbox->findById($supplierId, $inboxMessageId);
        if ($inbox === null
            || self::stringField($inbox, 'environment') !== $environment
            || self::stringField($inbox, 'channel') !== 'isds'
            || self::stringField($inbox, 'classification') !== InboxMessageClassifier::HEALTH_INSURER_RESPONSE
            || self::intField($inbox, 'matched_outbox_id') !== $verdict['matched_outbox_id']
            || self::stringField($inbox, 'external_message_id') !== $header->externalMessageId
        ) {
            return self::result('manual_review', 'health_isds_inbox_scope_mismatch');
        }

        $outbox = $this->outbox->find($supplierId, $verdict['matched_outbox_id']);
        if ($outbox === null
            || self::stringField($outbox, 'environment') !== $environment
            || self::stringField($outbox, 'channel') !== 'isds'
            || self::stringField($outbox, 'artifact_kind') !== 'payroll_submission'
        ) {
            return self::result('manual_review', 'health_isds_outbox_scope_mismatch');
        }

        $artifactId = self::intField($outbox, 'artifact_id');
        $artifact = $artifactId === null ? null : $this->payroll->findArtifact(
            $supplierId,
            $artifactId,
        );
        if ($artifact === null
            || $artifact['environment'] !== $environment
            || $artifact['direction'] !== 'outbound'
        ) {
            return self::result('manual_review', 'health_isds_payroll_artifact_mismatch');
        }

        $submissionId = (int) $artifact['submission_id'];
        $submission = $this->payroll->findSubmission($supplierId, $submissionId);
        $obligation = $this->payroll->findObligationOfSubmission(
            $supplierId,
            $environment,
            $submissionId,
        );
        if ($submission === null
            || $submission['environment'] !== $environment
            || $obligation === null
            || !in_array($obligation['agenda_code'], self::HEALTH_AGENDAS, true)
            || self::stringField($outbox, 'agenda_code') !== $obligation['agenda_code']
        ) {
            return self::result(
                'manual_review',
                'health_isds_payroll_submission_scope_mismatch',
                $submissionId,
            );
        }

        return self::result(
            'manual_review',
            'health_isds_response_archived_for_manual_review',
            $submissionId,
        );
    }

    /** @var list<string> */
    private const HEALTH_AGENDAS = [
        HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
        HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION,
    ];

    /**
     * @return array{status:string,code:?string,submission_id:?int,receipt_id:?int,remote_status:?string}
     */
    private static function result(
        string $status,
        ?string $code = null,
        ?int $submissionId = null,
    ): array {
        return [
            'status' => $status,
            'code' => $code,
            'submission_id' => $submissionId,
            'receipt_id' => null,
            'remote_status' => null,
        ];
    }

    /** @param array<string,mixed> $row */
    private static function stringField(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param array<string,mixed> $row */
    private static function intField(array $row, string $field): ?int
    {
        $value = $row[$field] ?? null;

        return is_int($value) ? $value : null;
    }
}
