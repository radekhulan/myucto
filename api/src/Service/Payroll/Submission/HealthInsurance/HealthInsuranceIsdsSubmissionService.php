<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistrationService;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionOutboxService;

/** Zařazení ověřeného přehledu zdravotní pojišťovny do obecné ISDS fronty. */
final readonly class HealthInsuranceIsdsSubmissionService
{
    private const CHANNEL = 'isds';
    private const ARTIFACT_KIND = 'payroll_submission';

    public function __construct(
        private PayrollSubmissionRepository $submissions,
        private SubmissionRecipientRepository $recipients,
        private SubmissionOutboxService $outbox,
        private HealthInsurerChannelCatalog $channels,
        private ?IsdsGatewayRegistrationService $gateway = null,
    ) {}

    /**
     * @return array{
     *   outbox_id:int,created:bool,row:array<string,mixed>,
     *   recipient:array{box_id:string,name:string},subject:string,
     *   attachment:array{filename:string,mime:string,sha256:string,bytes:int},
     *   transport:array{automatic:bool,channel:string,reason:?string}
     * }
     */
    public function enqueue(
        int $supplierId,
        int $submissionId,
        string $insurerCode,
        ?int $userId,
    ): array {
        $this->channels->forInsurer($insurerCode);
        $submission = $this->submissions->findSubmission($supplierId, $submissionId);
        if ($submission === null) {
            throw new SubmissionChannelException(
                'health_submission_not_found',
                'Připravené podání zdravotní pojišťovny nebylo nalezeno.',
                404,
            );
        }
        $environment = (string) $submission['environment'];
        if ($environment !== 'production') {
            throw new SubmissionChannelException(
                'zp_isds_production_only',
                'Datové schránky zdravotních pojišťoven jsou doložené jen pro ostré prostředí.',
                409,
            );
        }
        if ((string) $submission['status'] !== 'ready') {
            throw new SubmissionChannelException(
                'health_submission_not_ready',
                'Do ISDS lze zařadit jen podání, které prošlo ověřením schématu.',
                409,
            );
        }

        $obligation = $this->submissions->findObligationOfSubmission(
            $supplierId,
            $environment,
            $submissionId,
        );
        if ($obligation === null
            || (string) $obligation['agenda_code']
                !== HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW
            || !str_ends_with(
                (string) $obligation['subject_reference'],
                ':' . $insurerCode,
            )
        ) {
            throw new SubmissionChannelException(
                'health_submission_scope_mismatch',
                'Podání nepatří zvolené zdravotní pojišťovně nebo agendě PPZ.',
                409,
            );
        }

        [$format, $artifactKind, $artifactId] = $this->frozenAttachment(
            $supplierId,
            $environment,
            $submissionId,
        );
        $mimeType = match ($format) {
            HealthInsurerIsdsAttachmentFormat::Xml => 'application/xml',
            HealthInsurerIsdsAttachmentFormat::TextPdf => 'application/pdf',
            HealthInsurerIsdsAttachmentFormat::None => throw new \LogicException(
                'MIME přílohy ISDS nebylo určeno.',
            ),
        };
        $artifact = $this->submissions->findArtifact($supplierId, $artifactId);
        if ($artifact === null
            || (string) $artifact['environment'] !== $environment
            || (string) $artifact['artifact_kind'] !== $artifactKind
            || (string) $artifact['direction'] !== 'outbound'
            || (string) $artifact['mime_type'] !== $mimeType
        ) {
            throw new SubmissionChannelException(
                'health_submission_artifact_invalid',
                'Uložený podklad nemá doložený formát přílohy zdravotní pojišťovny.',
                409,
            );
        }

        $recipient = $this->recipient($supplierId, $insurerCode);
        $period = substr((string) $obligation['period_start'], 0, 7);
        $subject = sprintf(
            'PPPZ %s — zdravotní pojišťovna %s',
            $period,
            $insurerCode,
        );
        $enqueued = $this->outbox->enqueue(
            $supplierId,
            $environment,
            self::CHANNEL,
            HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
            self::ARTIFACT_KIND,
            $artifactId,
            (int) $recipient['id'],
            $subject,
            $userId,
        );
        $row = $enqueued['row'];

        return [
            'outbox_id' => (int) $row['id'],
            'created' => $enqueued['created'],
            'row' => $row,
            'recipient' => [
                'box_id' => (string) $recipient['isds_box_id'],
                'name' => (string) $recipient['name'],
            ],
            'subject' => $subject,
            'attachment' => [
                'filename' => (string) $row['artifact_filename'],
                'mime' => (string) $artifact['mime_type'],
                'sha256' => (string) $artifact['artifact_sha256'],
                'bytes' => (int) $artifact['byte_size'],
                'format' => $format->value,
            ],
            'transport' => $this->transportAvailability($environment),
        ];
    }

    /** @return array<string,mixed> */
    private function recipient(int $supplierId, string $insurerCode): array
    {
        $recipientCode = $this->channels->recipientCodeFor($insurerCode);
        $recipient = $this->recipients->findVisibleByCode(
            $supplierId,
            $recipientCode,
        );
        if ($recipient === null || !$recipient['is_active']) {
            throw new SubmissionChannelException(
                'zp_isds_recipient_missing',
                'Příjemce zdravotní pojišťovny v číselníku chybí nebo je vypnutý.',
                409,
            );
        }
        $actualBoxId = strtolower(trim((string) ($recipient['isds_box_id'] ?? '')));
        if ($actualBoxId === '') {
            throw new SubmissionChannelException(
                'zp_isds_recipient_undocumented',
                'Příjemce zdravotní pojišťovny nemá vyplněné ID datové schránky.',
                409,
            );
        }

        return $recipient;
    }

    /** @return array{HealthInsurerIsdsAttachmentFormat,string,int} */
    private function frozenAttachment(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): array {
        /** @var list<array{0:HealthInsurerIsdsAttachmentFormat,1:string}> $candidates */
        $candidates = [
            [HealthInsurerIsdsAttachmentFormat::Xml, 'outbound_xml'],
            [HealthInsurerIsdsAttachmentFormat::TextPdf, 'outbound_pdf'],
        ];
        /** @var list<array{0:HealthInsurerIsdsAttachmentFormat,1:string,2:int}> $frozen */
        $frozen = [];
        foreach ($candidates as [$format, $artifactKind]) {
            $artifactId = $this->submissions->findOutboundArtifactIdByCatalogVersion(
                $supplierId,
                $environment,
                $submissionId,
                $artifactKind,
                HealthInsuranceSubmissionService::isdsAttachmentCatalogVersion(
                    $format,
                ),
            );
            if ($artifactId !== null) {
                $frozen[] = [$format, $artifactKind, $artifactId];
            }
        }
        if (count($frozen) === 1) {
            return $frozen[0];
        }
        if (count($frozen) > 1) {
            throw new SubmissionChannelException(
                'health_submission_attachment_ambiguous',
                'Připravené podání má více zmrazených formátů přílohy ISDS.',
                409,
            );
        }

        throw new SubmissionChannelException(
            'health_submission_attachment_unfrozen',
            'Připravené podání nemá zmrazený doložený formát přílohy ISDS.',
            409,
        );
    }

    /** @return array{automatic:bool,channel:string,reason:?string} */
    private function transportAvailability(string $environment): array
    {
        if ($this->gateway !== null && $this->gateway->isUsable($environment)) {
            return ['automatic' => true, 'channel' => 'gateway', 'reason' => null];
        }

        return [
            'automatic' => false,
            'channel' => 'manual_upload',
            'reason' => 'isds_gateway_unavailable',
        ];
    }

}
