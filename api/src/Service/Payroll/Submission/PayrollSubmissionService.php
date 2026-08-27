<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionConflictException;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\PayrollYearClosedException;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationReceiptIdentityService;
use Psr\Clock\ClockInterface;

final class PayrollSubmissionService
{
    private const SUBMISSION_KINDS = [
        'regular',
        'correction',
        'cancellation',
    ];
    private const CHANNELS = [
        'manual_upload',
        'isds',
        'vrep_apep',
        'pikr',
        'health_portal',
        'other',
    ];
    private const ARTIFACT_KINDS = [
        'outbound_xml',
        'outbound_pdf',
        'outbound_zip',
        'validation_protocol',
        'receipt_original',
        'receipt_parsed',
        'manual_attachment',
    ];
    private const DIRECTIONS = ['outbound', 'inbound', 'internal'];
    private const REMOTE_STATUSES = [
        'submitted',
        'processing',
        'accepted',
        'partially_accepted',
        'rejected',
        'waiting_for_identity',
        'correction_required',
    ];
    private const VERIFIED_REMOTE_STATUSES = [
        'processing',
        'accepted',
        'partially_accepted',
        'rejected',
        'waiting_for_identity',
        'correction_required',
    ];
    private const ISSUE_SEVERITIES = [
        'blocker',
        'error',
        'warning',
        'info',
    ];
    private const ISSUE_STAGES = [
        'source',
        'xsd',
        'catalog',
        'transport',
        'remote',
    ];
    private const ENVIRONMENTS = ['production', 'test'];

    public function __construct(
        private readonly PayrollSubmissionRepository $repository,
        private readonly PayrollSubmissionStateMachine $stateMachine,
        private readonly SecretEncryption $encryption,
        private readonly ClockInterface $clock,
        private readonly ?PayrollRegistrationReceiptIdentityService $registrationReceiptIdentities = null,
    ) {}

    /**
     * @return array{
     *   id:int,status:string,row_version:int,request_fingerprint:string,
     *   source_snapshot_hash:string,submission_kind:string,channel:string,
     *   environment:string,obligation_id:int,corrects_submission_id:?int,
     *   correlation_reference:?string,
     *   created:bool
     * }
     */
    public function prepare(
        int $supplierId,
        int $obligationId,
        string $submissionKind,
        string $channel,
        string $sourceSnapshotHash,
        string $idempotencyKey,
        ?int $sourceRevisionId = null,
        ?int $correctsSubmissionId = null,
        ?int $createdBy = null,
        string $environment = 'production',
    ): array {
        $this->assertPositive($supplierId, 'Firma podání');
        $this->assertPositive($obligationId, 'Povinnost');
        $this->assertOptionalPositive($sourceRevisionId, 'Revize');
        $this->assertOptionalPositive(
            $correctsSubmissionId,
            'Opravované podání',
        );
        $this->assertOptionalPositive($createdBy, 'Uživatel');
        $this->assertAllowed(
            $submissionKind,
            self::SUBMISSION_KINDS,
            'Druh podání',
        );
        $this->assertAllowed($channel, self::CHANNELS, 'Kanál');
        $this->assertAllowed(
            $environment,
            self::ENVIRONMENTS,
            'Prostředí podání',
        );
        $this->assertHash($sourceSnapshotHash, 'Otisk zdroje');
        if (($submissionKind === 'regular')
            !== ($correctsSubmissionId === null)
        ) {
            throw new \InvalidArgumentException(
                'Oprava nebo storno musí odkazovat na původní podání.',
            );
        }
        $idempotencyHash = $this->idempotencyHash($idempotencyKey);
        $requestFingerprint = hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-submission-prepare.v1',
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'obligation_id' => $obligationId,
                'submission_kind' => $submissionKind,
                'channel' => $channel,
                'source_revision_id' => $sourceRevisionId,
                'source_snapshot_hash' => $sourceSnapshotHash,
                'corrects_submission_id' => $correctsSubmissionId,
            ]),
        );

        return $this->repository->transaction(function () use (
            $supplierId,
            $obligationId,
            $submissionKind,
            $channel,
            $sourceRevisionId,
            $sourceSnapshotHash,
            $correctsSubmissionId,
            $createdBy,
            $idempotencyHash,
            $requestFingerprint,
            $environment,
        ): array {
            if (!$this->repository->lockSupplier($supplierId)) {
                throw new \DomainException('Firma podání nebyla nalezena.');
            }
            $existing = $this->repository
                ->findSubmissionByIdempotencyForUpdate(
                    $supplierId,
                    $idempotencyHash,
                    $environment,
                );
            if ($existing !== null) {
                if ($existing['request_fingerprint']
                    !== $requestFingerprint
                ) {
                    throw new \DomainException(
                        'Idempotency klíč podání už patří jiným vstupům.',
                    );
                }

                return [...$existing, 'created' => false];
            }
            $obligation = $this->repository->lockObligation(
                $supplierId,
                $obligationId,
                $environment,
            );
            if ($obligation === null) {
                throw new \DomainException(
                    'Povinnost podání nebyla nalezena ve stejné firmě.',
                );
            }
            if ($obligation['obligation_kind'] !== $submissionKind) {
                throw new \DomainException(
                    'Druh podání neodpovídá druhu evidované povinnosti.',
                );
            }
            if ($sourceRevisionId !== null) {
                $revision = $this->repository->approvedRevisionScope(
                    $supplierId,
                    $sourceRevisionId,
                );
                if ($revision === null
                    || !in_array(
                        $revision['status'],
                        ['approved', 'superseded'],
                        true,
                    )
                    || $revision['period_start'] !== $obligation['period_start']
                    || !$this->revisionMatchesObligationScope(
                        $revision,
                        $obligation,
                    )
                    || $revision['result_snapshot_hash'] === null
                    || !hash_equals(
                        $revision['result_snapshot_hash'],
                        $sourceSnapshotHash,
                    )
                ) {
                    throw new \DomainException(
                        'Zdrojová revize není schválený důkaz stejného období.',
                    );
                }
            }
            if ($correctsSubmissionId !== null) {
                $corrected = $this->repository->lockSubmission(
                    $supplierId,
                    $correctsSubmissionId,
                );
                $correctedObligation = $corrected === null
                    ? null
                    : $this->repository->lockObligation(
                        $supplierId,
                        $corrected['obligation_id'],
                        $corrected['environment'],
                    );
                if ($corrected === null
                    || $corrected['environment'] !== $environment
                    || $correctedObligation === null
                    || !$this->sameObligationScope(
                        $obligation,
                        $correctedObligation,
                    )
                    // Způsobilé stavy rozhoduje AGENDA, ne tahle služba.
                    // Výchozí sada je přísná a JMHZ ji zužuje jen na konečně
                    // přijatý nebo částečně přijatý řádný kořen. Stavy
                    // `draft`…`ready` nejsou způsobilé nikdy: u nich úřad nemá
                    // co rušit a oprava by se vázala na dokument, který nikdy
                    // neopustil aplikaci.
                    || !in_array(
                        $corrected['status'],
                        PayrollAgendaCorrectionPolicy::correctableStatuses(
                            (string) $obligation['agenda_code'],
                        ),
                        true,
                    )
                ) {
                    throw new \DomainException(
                        'Opravované podání není způsobilý předchůdce stejné povinnosti.',
                    );
                }
            }

            $submissionId = $this->repository->insertSubmission(
                $supplierId,
                $environment,
                $obligationId,
                $correctsSubmissionId,
                $submissionKind,
                $channel,
                $sourceRevisionId,
                $sourceSnapshotHash,
                $requestFingerprint,
                $idempotencyHash,
                $createdBy,
            );

            return [
                'id' => $submissionId,
                'status' => 'draft',
                'row_version' => 1,
                'request_fingerprint' => $requestFingerprint,
                'source_snapshot_hash' => $sourceSnapshotHash,
                'submission_kind' => $submissionKind,
                'channel' => $channel,
                'environment' => $environment,
                'obligation_id' => $obligationId,
                'corrects_submission_id' => $correctsSubmissionId,
                'correlation_reference' => null,
                'created' => true,
            ];
        });
    }

    /**
     * @return array{
     *   id:int,status:string,row_version:int,request_fingerprint:string,
     *   source_snapshot_hash:string,submission_kind:string,channel:string,
     *   environment:string,obligation_id:int,corrects_submission_id:?int,
     *   correlation_reference:?string
     * }
     */
    public function get(int $supplierId, int $submissionId): array
    {
        $submission = $this->repository->findSubmission(
            $supplierId,
            $submissionId,
        );
        if ($submission === null) {
            throw new \DomainException(
                'Podání nebylo nalezeno ve stejné firmě.',
            );
        }

        return $submission;
    }

    /**
     * @return array{id:int,submission_row_version:int}
     */
    public function addPart(
        int $supplierId,
        int $submissionId,
        int $expectedRowVersion,
        string $partReference,
        string $agendaCode,
        string $subjectReference,
        string $sourceEntityType,
        string $sourceEntityReference,
        string $sourceSnapshotHash,
    ): array {
        $this->assertPositive($supplierId, 'Firma podání');
        $this->assertPositive($submissionId, 'Podání');
        $this->assertPositive($expectedRowVersion, 'Verze podání');
        $this->assertReference($partReference, 96);
        $this->assertReference($subjectReference, 96);
        $this->assertReference($sourceEntityReference, 96);
        $this->assertCode($agendaCode, 48, 'Agenda');
        $this->assertCode($sourceEntityType, 64, 'Zdroj entity');
        $this->assertHash($sourceSnapshotHash, 'Otisk části');

        return $this->repository->transaction(function () use (
            $supplierId,
            $submissionId,
            $expectedRowVersion,
            $partReference,
            $agendaCode,
            $subjectReference,
            $sourceEntityType,
            $sourceEntityReference,
            $sourceSnapshotHash,
        ): array {
            $submission = $this->lockedExpectedSubmission(
                $supplierId,
                $submissionId,
                $expectedRowVersion,
            );
            if (!in_array(
                $submission['status'],
                ['draft', 'validated', 'prepared'],
                true,
            )) {
                throw new \DomainException(
                    'Část lze přidat jen do připravovaného podání.',
                );
            }
            $partId = $this->repository->insertPart(
                $supplierId,
                $submission['environment'],
                $submissionId,
                $partReference,
                $agendaCode,
                $subjectReference,
                $sourceEntityType,
                $sourceEntityReference,
                $sourceSnapshotHash,
            );
            $rowVersion = $this->repository->bumpSubmissionVersion(
                $supplierId,
                $submissionId,
                $expectedRowVersion,
            );

            return [
                'id' => $partId,
                'submission_row_version' => $rowVersion,
            ];
        });
    }

    /**
     * @return array{
     *   id:int,artifact_sha256:string,byte_size:int,
     *   submission_row_version:int,created:bool
     * }
     */
    public function storeArtifact(
        int $supplierId,
        int $submissionId,
        int $expectedRowVersion,
        ?int $partId,
        string $artifactKind,
        string $direction,
        string $mimeType,
        string $bytes,
        ?string $xsdVersion,
        ?string $catalogVersion,
        string $channel,
        string $idempotencyKey,
        ?int $createdBy = null,
    ): array {
        $this->assertPositive($supplierId, 'Firma artefaktu');
        $this->assertPositive($submissionId, 'Podání artefaktu');
        $this->assertPositive($expectedRowVersion, 'Verze podání');
        $this->assertOptionalPositive($partId, 'Část podání');
        $this->assertOptionalPositive($createdBy, 'Uživatel');
        $this->assertAllowed(
            $artifactKind,
            self::ARTIFACT_KINDS,
            'Druh artefaktu',
        );
        $this->assertAllowed($direction, self::DIRECTIONS, 'Směr artefaktu');
        $this->assertAllowed($channel, self::CHANNELS, 'Kanál');
        $this->assertCode($mimeType, 96, 'MIME typ');
        if ($bytes === '' || strlen($bytes) > 50 * 1024 * 1024) {
            throw new \InvalidArgumentException(
                'Artefakt musí mít 1 B až 50 MB.',
            );
        }
        if ($xsdVersion !== null) {
            $this->assertCode($xsdVersion, 96, 'Verze XSD');
        }
        if ($catalogVersion !== null) {
            $this->assertCode($catalogVersion, 96, 'Verze katalogu');
        }
        $artifactHash = hash('sha256', $bytes);
        $idempotencyHash = $this->idempotencyHash($idempotencyKey);

        return $this->repository->transaction(function () use (
            $supplierId,
            $submissionId,
            $expectedRowVersion,
            $partId,
            $artifactKind,
            $direction,
            $mimeType,
            $bytes,
            $xsdVersion,
            $catalogVersion,
            $channel,
            $idempotencyHash,
            $artifactHash,
            $createdBy,
        ): array {
            $submission = $this->repository->lockSubmission(
                $supplierId,
                $submissionId,
            );
            if ($submission === null) {
                throw new \DomainException(
                    'Podání artefaktu nebylo nalezeno ve stejné firmě.',
                );
            }
            $existing = $this->repository
                ->findArtifactByIdempotencyForUpdate(
                    $supplierId,
                    $idempotencyHash,
                    $submission['environment'],
                );
            if ($existing !== null) {
                if (!$this->sameArtifact(
                    $existing,
                    $submissionId,
                    $partId,
                    $artifactKind,
                    $direction,
                    $mimeType,
                    strlen($bytes),
                    $artifactHash,
                    $xsdVersion,
                    $catalogVersion,
                    $channel,
                )) {
                    throw new \DomainException(
                        'Idempotency klíč artefaktu už patří jiným bajtům.',
                    );
                }

                return [
                    'id' => $existing['id'],
                    'artifact_sha256' => $existing['artifact_sha256'],
                    'byte_size' => $existing['byte_size'],
                    'submission_row_version' => $submission['row_version'],
                    'created' => false,
                ];
            }
            if ($submission['row_version'] !== $expectedRowVersion) {
                throw new PayrollSubmissionConflictException(
                    'Podání se mezitím změnilo.',
                );
            }
            if ($submission['channel'] !== $channel) {
                throw new \DomainException(
                    'Kanál artefaktu neodpovídá podání.',
                );
            }
            if ($direction === 'outbound'
                && !in_array(
                    $submission['status'],
                    ['draft', 'validated', 'prepared', 'ready'],
                    true,
                )
            ) {
                throw new \DomainException(
                    'Odchozí artefakt nelze přidat po odeslání podání.',
                );
            }
            if ($partId !== null
                && !$this->repository->partBelongsToSubmission(
                    $supplierId,
                    $submissionId,
                    $partId,
                    $submission['environment'],
                )
            ) {
                throw new \DomainException(
                    'Část artefaktu nepatří do stejného podání a firmy.',
                );
            }
            $context = $this->artifactContext(
                $supplierId,
                $submission['environment'],
                $submissionId,
                $partId,
                $artifactKind,
                $direction,
                $mimeType,
                $artifactHash,
                $xsdVersion,
                $catalogVersion,
                $channel,
            );
            $ciphertext = $this->encryption->encryptFor(
                base64_encode($bytes),
                $context,
            );
            $artifactId = $this->repository->insertArtifact(
                $supplierId,
                $submission['environment'],
                $submissionId,
                $partId,
                $artifactKind,
                $direction,
                $mimeType,
                $ciphertext,
                strlen($bytes),
                $artifactHash,
                $xsdVersion,
                $catalogVersion,
                $channel,
                $idempotencyHash,
                $createdBy,
            );
            $rowVersion = $this->repository->bumpSubmissionVersion(
                $supplierId,
                $submissionId,
                $expectedRowVersion,
            );

            return [
                'id' => $artifactId,
                'artifact_sha256' => $artifactHash,
                'byte_size' => strlen($bytes),
                'submission_row_version' => $rowVersion,
                'created' => true,
            ];
        });
    }

    public function artifactBytes(int $supplierId, int $artifactId): string
    {
        $artifact = $this->repository->findArtifact(
            $supplierId,
            $artifactId,
        );
        if ($artifact === null) {
            throw new \DomainException(
                'Artefakt nebyl nalezen ve stejné firmě.',
            );
        }
        $encoded = $this->encryption->decryptFor(
            $artifact['content_ciphertext'],
            $this->artifactContext(
                $supplierId,
                $artifact['environment'],
                $artifact['submission_id'],
                $artifact['part_id'],
                $artifact['artifact_kind'],
                $artifact['direction'],
                $artifact['mime_type'],
                $artifact['artifact_sha256'],
                $artifact['xsd_version'],
                $artifact['catalog_version'],
                $artifact['channel'],
            ),
        );
        $bytes = base64_decode($encoded, true);
        if ($bytes === false
            || strlen($bytes) !== $artifact['byte_size']
            || !hash_equals(
                $artifact['artifact_sha256'],
                hash('sha256', $bytes),
            )
        ) {
            throw new \UnexpectedValueException(
                'Obsah artefaktu neodpovídá archivovanému SHA-256.',
            );
        }

        return $bytes;
    }

    /**
     * @return array{id:int,status:string,row_version:int}
     */
    public function transition(
        int $supplierId,
        int $submissionId,
        int $expectedRowVersion,
        string $targetStatus,
        ?string $correlationReference = null,
    ): array {
        return $this->transitionWithEvidence(
            $supplierId,
            $submissionId,
            $expectedRowVersion,
            $targetStatus,
            $correlationReference,
            null,
        );
    }

    /**
     * @return array{id:int,status:string,row_version:int}
     */
    private function transitionVerifiedReceipt(
        int $supplierId,
        int $submissionId,
        int $expectedRowVersion,
        PayrollVerifiedReceipt $verifiedReceipt,
        ?string $correlationReference,
        bool $processingAcknowledgement = false,
    ): array {
        return $this->transitionWithEvidence(
            $supplierId,
            $submissionId,
            $expectedRowVersion,
            $processingAcknowledgement
                ? 'processing'
                : $verifiedReceipt->remoteStatus,
            $correlationReference,
            $verifiedReceipt,
        );
    }

    /**
     * @return array{row_version:int,status:string,year_close_reopen_required:bool}
     */
    private function applyVerifiedReceiptTransitions(
        int $supplierId,
        int $submissionId,
        int $currentVersion,
        string $currentStatus,
        ?PayrollVerifiedReceipt $verified,
        ?string $remoteStatus,
        ?string $trustedCorrelationReference,
    ): array {
        $yearCloseReopenRequired = false;
        if ($verified !== null
            && $remoteStatus !== null
            && $currentStatus === 'submitted'
            && !in_array($remoteStatus, ['submitted', 'processing'], true)
        ) {
            try {
                $processing = $this->transitionVerifiedReceipt(
                    $supplierId,
                    $submissionId,
                    $currentVersion,
                    $verified,
                    $trustedCorrelationReference,
                    true,
                );
                $currentVersion = $processing['row_version'];
                $currentStatus = 'processing';
            } catch (PayrollYearClosedException) {
                $yearCloseReopenRequired = true;
            }
        }
        if ($verified !== null
            && $remoteStatus !== null
            && $remoteStatus !== $currentStatus
            && $this->shouldApplyRemoteStatus($currentStatus, $remoteStatus)
        ) {
            try {
                $result = $this->transitionVerifiedReceipt(
                    $supplierId,
                    $submissionId,
                    $currentVersion,
                    $verified,
                    $trustedCorrelationReference,
                );
                $currentVersion = $result['row_version'];
                $currentStatus = $result['status'];
            } catch (PayrollYearClosedException) {
                $yearCloseReopenRequired = true;
            }
        }

        return [
            'row_version' => $currentVersion,
            'status' => $currentStatus,
            'year_close_reopen_required' => $yearCloseReopenRequired,
        ];
    }

    /**
     * @return array{id:int,status:string,row_version:int}
     */
    private function transitionWithEvidence(
        int $supplierId,
        int $submissionId,
        int $expectedRowVersion,
        string $targetStatus,
        ?string $correlationReference,
        ?PayrollVerifiedReceipt $verifiedReceipt,
    ): array {
        $this->assertPositive($supplierId, 'Firma podání');
        $this->assertPositive($submissionId, 'Podání');
        $this->assertPositive($expectedRowVersion, 'Verze podání');
        if (!$this->stateMachine->isKnownStatus($targetStatus)) {
            throw new \InvalidArgumentException(
                'Cílový stav podání není známý.',
            );
        }
        if (in_array(
            $targetStatus,
            self::VERIFIED_REMOTE_STATUSES,
            true,
        ) && $verifiedReceipt === null) {
            throw new \DomainException(
                'Vzdálený stav podání vyžaduje ověřený protokol.',
            );
        }
        if ($verifiedReceipt !== null
            && $targetStatus !== 'processing'
            && !hash_equals(
                $verifiedReceipt->remoteStatus,
                $targetStatus,
            )
        ) {
            throw new \LogicException(
                'Ověřený protokol neopravňuje požadovaný vzdálený stav.',
            );
        }
        if ($correlationReference !== null) {
            $this->assertReference($correlationReference, 128);
        }

        return $this->repository->transaction(function () use (
            $supplierId,
            $submissionId,
            $expectedRowVersion,
            $targetStatus,
            $correlationReference,
        ): array {
            $submission = $this->lockedExpectedSubmission(
                $supplierId,
                $submissionId,
                $expectedRowVersion,
            );
            if ($targetStatus === 'superseded') {
                throw new \DomainException(
                    'Nahrazení původního podání smí provést jen přijetí jeho opravy.',
                );
            }
            if ($submission['correlation_reference'] !== null
                && $correlationReference !== null
                && !hash_equals(
                    $submission['correlation_reference'],
                    $correlationReference,
                )
            ) {
                throw new \DomainException(
                    'Correlation reference podání je neměnná.',
                );
            }
            $this->stateMachine->assertTransition(
                $submission['status'],
                $targetStatus,
            );
            $predecessor = null;
            $predecessorObligation = null;
            if ($targetStatus === 'accepted'
                && $submission['corrects_submission_id'] !== null
            ) {
                $predecessor = $this->repository->lockSubmission(
                    $supplierId,
                    $submission['corrects_submission_id'],
                );
                $predecessorObligation = $predecessor === null
                    ? null
                    : $this->repository->lockObligation(
                        $supplierId,
                        $predecessor['obligation_id'],
                        $predecessor['environment'],
                    );
            }
            $obligation = $this->repository->lockObligation(
                $supplierId,
                $submission['obligation_id'],
                $submission['environment'],
            );
            if ($obligation === null) {
                throw new \DomainException(
                    'Povinnost podání nebyla nalezena ve stejné firmě.',
                );
            }
            if ($targetStatus === 'submitted') {
                $today = \DateTimeImmutable::createFromInterface(
                    $this->clock->now(),
                )->setTimezone(new \DateTimeZone('Europe/Prague'))
                    ->format('Y-m-d');
                if ($today < $obligation['earliest_submission_on']) {
                    throw new \DomainException(
                        'Podání ještě není v zákonném časovém okně.',
                    );
                }
                $replacementMode = $this->repository
                    ->effectiveAgendaReplacementMode(
                        $supplierId,
                        $obligation['agenda_code'],
                        $obligation['period_start'],
                    );
                if ($replacementMode === 'unknown') {
                    throw new \DomainException(
                        'Agenda má pro období neznámý režim nahrazení.',
                    );
                }
            }
            $now = $this->now();
            $submittedAt = $targetStatus === 'submitted' ? $now : null;
            $decidedAt = in_array(
                $targetStatus,
                [
                    'accepted',
                    'partially_accepted',
                    'rejected',
                    'correction_required',
                    'superseded',
                    'cancelled_in_time',
                ],
                true,
            ) ? $now : null;
            $rowVersion = $this->repository->updateSubmissionStatus(
                $supplierId,
                $submissionId,
                $expectedRowVersion,
                $targetStatus,
                $correlationReference,
                $submittedAt,
                $decidedAt,
            );
            $obligationStatus = $this->obligationStatus($targetStatus);
            if ($obligationStatus !== null) {
                $this->repository->updateObligationStatus(
                    $supplierId,
                    $submission['environment'],
                    $submission['obligation_id'],
                    $obligation['row_version'],
                    $obligationStatus,
                );
            }
            if ($targetStatus === 'accepted'
                && $submission['corrects_submission_id'] !== null
            ) {
                if ($predecessor === null
                    || $predecessor['environment']
                        !== $submission['environment']
                    || $predecessorObligation === null
                    || !$this->sameObligationScope(
                        $obligation,
                        $predecessorObligation,
                    )
                    || !in_array(
                        $predecessor['status'],
                        PayrollAgendaCorrectionPolicy::correctableStatuses(
                            (string) $obligation['agenda_code'],
                        ),
                        true,
                    )
                ) {
                    throw new \DomainException(
                        'Předchůdce přijaté opravy už není způsobilý k nahrazení.',
                    );
                }
                if (PayrollAgendaCorrectionPolicy::supersedesPredecessorOnAcceptance(
                    (string) $obligation['agenda_code'],
                    (string) $submission['submission_kind'],
                )) {
                    $supersedesCorrectionChain = PayrollAgendaCorrectionPolicy::supersedesCorrectionChainOnAcceptance(
                            (string) $obligation['agenda_code'],
                            (string) $submission['submission_kind'],
                        );
                    $correctionChain = $supersedesCorrectionChain
                        ? $this->repository->resolvedCorrectionsForRoot(
                            $supplierId,
                            $submission['environment'],
                            $predecessor['id'],
                        )
                        : [];
                    $this->repository->updateSubmissionStatus(
                        $supplierId,
                        $predecessor['id'],
                        $predecessor['row_version'],
                        'superseded',
                        null,
                        null,
                        $now,
                    );
                    foreach ($correctionChain as $correction) {
                        $this->stateMachine->assertTransition(
                            $correction['status'],
                            'superseded',
                        );
                        $this->repository->updateSubmissionStatus(
                            $supplierId,
                            $correction['id'],
                            $correction['row_version'],
                            'superseded',
                            null,
                            null,
                            $now,
                        );
                    }
                }
            }

            return [
                'id' => $submissionId,
                'status' => $targetStatus,
                'row_version' => $rowVersion,
            ];
        });
    }

    /**
     * @return array{
     *   id:int,artifact_id:int,artifact_sha256:string,
     *   submission_status:string,submission_row_version:int,created:bool,
     *   trusted:bool,year_close_reopen_required:bool
     * }
     */
    public function importReceipt(
        int $supplierId,
        int $submissionId,
        int $expectedRowVersion,
        ?int $partId,
        string $bytes,
        string $receiptReference,
        ?string $correlationReference,
        string $protocolCode,
        string $declaredRemoteStatus,
        string $channel,
        string $idempotencyKey,
        ?int $importedBy = null,
        ?PayrollReceiptVerifierInterface $verifier = null,
    ): array {
        $this->assertPositive($supplierId, 'Firma protokolu');
        $this->assertPositive($submissionId, 'Podání protokolu');
        $this->assertPositive($expectedRowVersion, 'Verze podání');
        $this->assertOptionalPositive($partId, 'Část protokolu');
        $this->assertOptionalPositive($importedBy, 'Importující uživatel');
        $this->assertAllowed($channel, self::CHANNELS, 'Kanál protokolu');
        $this->assertAllowed(
            $declaredRemoteStatus,
            self::REMOTE_STATUSES,
            'Deklarovaný vzdálený stav',
        );
        if ($bytes === '' || strlen($bytes) > 50 * 1024 * 1024) {
            throw new \InvalidArgumentException(
                'Protokol musí mít 1 B až 50 MB.',
            );
        }
        $this->assertReference($receiptReference, 128);
        if ($correlationReference !== null) {
            $this->assertReference($correlationReference, 128);
        }
        $this->assertCode($protocolCode, 64, 'Kód protokolu');
        $idempotencyHash = $this->idempotencyHash($idempotencyKey);
        $summaryHash = hash('sha256', $bytes);

        return $this->repository->transaction(function () use (
            $supplierId,
            $submissionId,
            $expectedRowVersion,
            $partId,
            $bytes,
            $receiptReference,
            $correlationReference,
            $protocolCode,
            $declaredRemoteStatus,
            $channel,
            $idempotencyKey,
            $idempotencyHash,
            $summaryHash,
            $importedBy,
            $verifier,
        ): array {
            $submission = $this->repository->lockSubmission(
                $supplierId,
                $submissionId,
            );
            if ($submission === null) {
                throw new \DomainException(
                    'Podání protokolu nebylo nalezeno ve stejné firmě.',
                );
            }
            if ($submission['channel'] !== $channel) {
                throw new \DomainException(
                    'Kanál protokolu neodpovídá podání.',
                );
            }
            if ($partId !== null
                && !$this->repository->partBelongsToSubmission(
                    $supplierId,
                    $submissionId,
                    $partId,
                    $submission['environment'],
                )
            ) {
                throw new \DomainException(
                    'Část protokolu nepatří do stejného podání.',
                );
            }
            $verified = $verifier?->verify(
                $bytes,
                $channel,
                $submission['environment'],
                $submission['correlation_reference'],
            );
            $trusted = $verified !== null;
            $remoteStatus = $verified?->remoteStatus;
            $verifiedCorrelation = $verified?->correlationReference;
            if ($trusted
                && $submission['correlation_reference'] !== null
                && $verifiedCorrelation !== null
                && !hash_equals(
                    $submission['correlation_reference'],
                    $verifiedCorrelation,
                )
            ) {
                throw new \DomainException(
                    'Ověřený protokol patří jiné correlation reference.',
                );
            }
            $trustedCorrelationReference = $verifiedCorrelation
                ?? $submission['correlation_reference'];
            $requestFingerprint = hash(
                'sha256',
                CanonicalJson::encode([
                    'schema_reference' =>
                        'payroll-submission-receipt-import.v2',
                    'supplier_id' => $supplierId,
                    'environment' => $submission['environment'],
                    'submission_id' => $submissionId,
                    'part_id' => $partId,
                    'artifact_sha256' => $summaryHash,
                    'receipt_reference' => $receiptReference,
                    'correlation_reference' => $correlationReference,
                    'protocol_code' => $protocolCode,
                    'declared_remote_status' => $declaredRemoteStatus,
                    'trusted_remote_status' => $remoteStatus,
                    'trusted_correlation_reference' =>
                        $verifiedCorrelation,
                    'trusted_part_statuses' =>
                        $verified === null ? [] : $verified->partStatuses,
                    'trusted_form_outcomes' => $verified === null
                        ? []
                        : array_map(
                            static fn (PayrollVerifiedReceiptFormOutcome $outcome): array
                                => $outcome->fingerprintData(),
                            $verified->formOutcomes,
                        ),
                    'channel' => $channel,
                ]),
            );
            $existing = $this->repository
                ->findReceiptByIdempotencyForUpdate(
                    $supplierId,
                    $idempotencyHash,
                    $submission['environment'],
                );
            if ($existing !== null) {
                if (!hash_equals(
                    $existing['request_fingerprint'],
                    $requestFingerprint,
                )) {
                    throw new \DomainException(
                        'Idempotency klíč protokolu už patří jinému obsahu.',
                    );
                }
                $transition = $this->applyVerifiedReceiptTransitions(
                    $supplierId,
                    $submissionId,
                    $submission['row_version'],
                    $submission['status'],
                    $verified,
                    $remoteStatus,
                    $trustedCorrelationReference,
                );
                return [
                    'id' => $existing['id'],
                    'artifact_id' => $existing['artifact_id'],
                    'artifact_sha256' => $summaryHash,
                    'submission_status' => $transition['status'],
                    'submission_row_version' => $transition['row_version'],
                    'created' => false,
                    'trusted' => $existing['remote_status'] !== null,
                    'year_close_reopen_required' => $transition['year_close_reopen_required'],
                ];
            }
            if ($submission['row_version'] !== $expectedRowVersion) {
                throw new PayrollSubmissionConflictException(
                    'Podání se mezitím změnilo.',
                );
            }
            if (!in_array(
                $submission['status'],
                [
                    'submitted',
                    'processing',
                    'waiting_for_identity',
                    'accepted',
                    'partially_accepted',
                    'rejected',
                    'correction_required',
                    'superseded',
                ],
                true,
            )) {
                throw new \DomainException(
                    'Protokol lze importovat jen k odeslanému podání.',
                );
            }

            $artifact = $this->storeArtifact(
                $supplierId,
                $submissionId,
                $expectedRowVersion,
                $partId,
                'receipt_original',
                'inbound',
                'application/xml',
                $bytes,
                null,
                null,
                $channel,
                $idempotencyKey . ':artifact',
                $importedBy,
            );
            $currentVersion = $artifact['submission_row_version'];
            $currentStatus = $submission['status'];
            $yearCloseReopenRequired = false;
            if ($verified !== null) {
                foreach ($verified->partStatuses as $verifiedPartId => $status) {
                    if (!in_array(
                        $status,
                        [
                            'submitted',
                            'processing',
                            'accepted',
                            'rejected',
                            'correction_required',
                        ],
                        true,
                    )) {
                        throw new \DomainException(
                            'Protokol obsahuje nepodporovaný dílčí stav.',
                        );
                    }
                    $part = $this->repository->lockPart(
                        $supplierId,
                        $submission['environment'],
                        $submissionId,
                        $verifiedPartId,
                    );
                    if ($part === null) {
                        throw new \DomainException(
                            'Ověřený protokol odkazuje na cizí část podání.',
                        );
                    }
                    if ($this->shouldApplyPartStatus(
                        $part['status'],
                        $status,
                    )) {
                        $this->repository->updatePartStatus(
                            $supplierId,
                            $submission['environment'],
                            $submissionId,
                            $verifiedPartId,
                            $part['row_version'],
                            $status,
                        );
                    }
                }
            }
            if (!$trusted) {
                $this->repository->insertIssue(
                    $supplierId,
                    $submission['environment'],
                    $submissionId,
                    $partId,
                    'warning',
                    'remote',
                    'receipt_unverified',
                    null,
                    null,
                    null,
                    null,
                    $importedBy,
                );
                $currentVersion = $this->repository->bumpSubmissionVersion(
                    $supplierId,
                    $submissionId,
                    $currentVersion,
                );
                $obligation = $this->repository->lockObligation(
                    $supplierId,
                    $submission['obligation_id'],
                    $submission['environment'],
                );
                if ($obligation === null) {
                    throw new \DomainException(
                        'Povinnost protokolu nebyla nalezena.',
                    );
                }
                $this->repository->updateObligationStatus(
                    $supplierId,
                    $submission['environment'],
                    $submission['obligation_id'],
                    $obligation['row_version'],
                    'manual_review',
                );
            }
            $transition = $this->applyVerifiedReceiptTransitions(
                $supplierId,
                $submissionId,
                $currentVersion,
                $currentStatus,
                $verified,
                $remoteStatus,
                $trustedCorrelationReference,
            );
            $currentVersion = $transition['row_version'];
            $currentStatus = $transition['status'];
            $yearCloseReopenRequired = $transition['year_close_reopen_required'];
            $receiptId = $this->repository->insertReceipt(
                $supplierId,
                $submission['environment'],
                $submissionId,
                $partId,
                $artifact['id'],
                $receiptReference,
                $correlationReference,
                $protocolCode,
                $remoteStatus,
                $trusted ? 'trusted' : 'unverified',
                $summaryHash,
                $requestFingerprint,
                $idempotencyHash,
                $this->now(),
                $importedBy,
            );
            if ($verified !== null && $verified->formOutcomes !== []) {
                if (!in_array(
                    $protocolCode,
                    ['CSSZ_JMHZ', 'CSSZ_REGZEC', 'CSSZ_PREZEC'],
                    true,
                )) {
                    throw new \DomainException(
                        'Výsledky formulářů lze uložit jen k protokolu ČSSZ.',
                    );
                }
                foreach ($verified->formOutcomes as $outcome) {
                    $errorsJson = CanonicalJson::encode(array_map(
                        static fn (PayrollVerifiedReceiptFormError $error): array
                            => $error->fingerprintData(),
                        $outcome->errors,
                    ));
                    $errorsHash = hash('sha256', $errorsJson);
                    $this->repository->insertJmhzProtocolFormOutcome(
                        $supplierId,
                        $submission['environment'],
                        $submissionId,
                        $receiptId,
                        $artifact['id'],
                        $outcome->partId,
                        $outcome->formReference,
                        $outcome->protocolStatusCode,
                        $outcome->protocolStatusName,
                        $outcome->remoteStatus,
                        $outcome->externalPersonReference,
                        $outcome->externalEmploymentReference,
                        count($outcome->errors),
                        $this->encryption->encryptFor(
                            $errorsJson,
                            self::formOutcomeErrorsContext(
                                $supplierId,
                                $submission['environment'],
                                $submissionId,
                                $receiptId,
                                $outcome->formReference,
                                $errorsHash,
                            ),
                        ),
                        $errorsHash,
                    );
                }
            }
            if ($trusted
                && in_array(
                    $remoteStatus,
                    ['accepted', 'partially_accepted'],
                    true,
                )
                && $this->registrationReceiptIdentities !== null
            ) {
                $this->registrationReceiptIdentities
                    ->applyAcceptedVariableSymbolTransfer(
                        $supplierId,
                        $submission['environment'],
                        $submissionId,
                        $receiptId,
                        $importedBy,
                    );
                $this->registrationReceiptIdentities
                    ->applyAcceptedEmploymentRegistration(
                        $supplierId,
                        $submission['environment'],
                        $submissionId,
                        $receiptId,
                        $importedBy,
                    );
            }

            return [
                'id' => $receiptId,
                'artifact_id' => $artifact['id'],
                'artifact_sha256' => $artifact['artifact_sha256'],
                'submission_status' => $currentStatus,
                'submission_row_version' => $currentVersion,
                'created' => true,
                'trusted' => $trusted,
                'year_close_reopen_required' => $yearCloseReopenRequired,
            ];
        });
    }

    /** @return list<array<string,mixed>> */
    public function jmhzProtocolFormOutcomes(
        int $supplierId,
        string $environment,
        int $receiptId,
    ): array {
        $this->assertPositive($supplierId, 'Firma protokolu');
        $this->assertPositive($receiptId, 'Protokol');
        $this->assertAllowed($environment, self::ENVIRONMENTS, 'Prostředí protokolu');
        $rows = $this->repository->listJmhzProtocolFormOutcomes(
            $supplierId,
            $environment,
            $receiptId,
        );
        foreach ($rows as &$row) {
            $errorsJson = $this->encryption->decryptFor(
                (string) $row['errors_ciphertext'],
                self::formOutcomeErrorsContext(
                    $supplierId,
                    $environment,
                    (int) $row['submission_id'],
                    (int) $row['receipt_id'],
                    (string) $row['form_guid'],
                    (string) $row['errors_sha256'],
                ),
            );
            if (!hash_equals((string) $row['errors_sha256'], hash('sha256', $errorsJson))) {
                throw new \UnexpectedValueException(
                    'Otisk chyb formuláře protokolu JMHZ nesouhlasí.',
                );
            }
            $decoded = json_decode($errorsJson, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !array_is_list($decoded)) {
                throw new \UnexpectedValueException(
                    'Uložené chyby formuláře protokolu JMHZ nemají platný tvar.',
                );
            }
            if (count($decoded) !== (int) $row['error_count']) {
                throw new \UnexpectedValueException(
                    'Počet uložených chyb formuláře protokolu JMHZ nesouhlasí.',
                );
            }
            $row['errors'] = $decoded;
            unset($row['errors_ciphertext']);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string,mixed>|null $details
     * @return array{id:int,submission_row_version:int}
     */
    public function recordIssue(
        int $supplierId,
        int $submissionId,
        int $expectedRowVersion,
        ?int $partId,
        string $severity,
        string $validationStage,
        string $issueCode,
        ?string $entityType = null,
        ?string $entityReference = null,
        ?array $details = null,
        ?int $createdBy = null,
    ): array {
        $this->assertAllowed(
            $severity,
            self::ISSUE_SEVERITIES,
            'Závažnost',
        );
        $this->assertAllowed(
            $validationStage,
            self::ISSUE_STAGES,
            'Fáze validace',
        );
        $this->assertCode($issueCode, 96, 'Kód problému');
        if ($entityType !== null) {
            $this->assertCode($entityType, 64, 'Druh entity');
        }
        if ($entityReference !== null) {
            $this->assertReference($entityReference, 96);
        }

        return $this->repository->transaction(function () use (
            $supplierId,
            $submissionId,
            $expectedRowVersion,
            $partId,
            $severity,
            $validationStage,
            $issueCode,
            $entityType,
            $entityReference,
            $details,
            $createdBy,
        ): array {
            $submission = $this->lockedExpectedSubmission(
                $supplierId,
                $submissionId,
                $expectedRowVersion,
            );
            if ($partId !== null
                && !$this->repository->partBelongsToSubmission(
                    $supplierId,
                    $submissionId,
                    $partId,
                    $submission['environment'],
                )
            ) {
                throw new \DomainException(
                    'Část problému nepatří do stejného podání.',
                );
            }
            $detailsJson = $details === null
                ? null
                : CanonicalJson::encode($details);
            $detailsHash = $detailsJson === null
                ? null
                : hash('sha256', $detailsJson);
            $detailsCiphertext = $detailsJson === null
                ? null
                : $this->encryption->encryptFor(
                    $detailsJson,
                    'payroll-submission-issue:'
                        . hash(
                            'sha256',
                            CanonicalJson::encode([
                                'supplier_id' => $supplierId,
                                'environment' =>
                                    $submission['environment'],
                                'submission_id' => $submissionId,
                                'part_id' => $partId,
                                'severity' => $severity,
                                'validation_stage' => $validationStage,
                                'issue_code' => $issueCode,
                                'entity_type' => $entityType,
                                'entity_reference' => $entityReference,
                                'details_hash' => $detailsHash,
                            ]),
                        ),
                );
            $issueId = $this->repository->insertIssue(
                $supplierId,
                $submission['environment'],
                $submissionId,
                $partId,
                $severity,
                $validationStage,
                $issueCode,
                $entityType,
                $entityReference,
                $detailsCiphertext,
                $detailsHash,
                $createdBy,
            );
            $rowVersion = $this->repository->bumpSubmissionVersion(
                $supplierId,
                $submissionId,
                $expectedRowVersion,
            );

            return [
                'id' => $issueId,
                'submission_row_version' => $rowVersion,
            ];
        });
    }

    /**
     * @return array{
     *   id:int,status:string,row_version:int,request_fingerprint:string,
     *   source_snapshot_hash:string,submission_kind:string,channel:string,
     *   environment:string,obligation_id:int,corrects_submission_id:?int,
     *   correlation_reference:?string
     * }
     */
    private function lockedExpectedSubmission(
        int $supplierId,
        int $submissionId,
        int $expectedRowVersion,
    ): array {
        $submission = $this->repository->lockSubmission(
            $supplierId,
            $submissionId,
        );
        if ($submission === null) {
            throw new \DomainException(
                'Podání nebylo nalezeno ve stejné firmě.',
            );
        }
        if ($submission['row_version'] !== $expectedRowVersion) {
            throw new PayrollSubmissionConflictException(
                'Podání se mezitím změnilo.',
            );
        }

        return $submission;
    }

    /**
     * @param array{
     *   id:int,submission_id:int,part_id:?int,artifact_kind:string,
     *   direction:string,mime_type:string,byte_size:int,
     *   artifact_sha256:string,xsd_version:?string,
     *   catalog_version:?string,channel:string,environment:string
     * } $artifact
     */
    private function sameArtifact(
        array $artifact,
        int $submissionId,
        ?int $partId,
        string $artifactKind,
        string $direction,
        string $mimeType,
        int $byteSize,
        string $artifactHash,
        ?string $xsdVersion,
        ?string $catalogVersion,
        string $channel,
    ): bool {
        return $artifact['submission_id'] === $submissionId
            && $artifact['part_id'] === $partId
            && $artifact['artifact_kind'] === $artifactKind
            && $artifact['direction'] === $direction
            && $artifact['mime_type'] === $mimeType
            && $artifact['byte_size'] === $byteSize
            && hash_equals($artifact['artifact_sha256'], $artifactHash)
            && $artifact['xsd_version'] === $xsdVersion
            && $artifact['catalog_version'] === $catalogVersion
            && $artifact['channel'] === $channel;
    }

    private function artifactContext(
        int $supplierId,
        string $environment,
        int $submissionId,
        ?int $partId,
        string $artifactKind,
        string $direction,
        string $mimeType,
        string $artifactHash,
        ?string $xsdVersion,
        ?string $catalogVersion,
        string $channel,
    ): string {
        return 'payroll-submission-artifact:'
            . hash(
                'sha256',
                CanonicalJson::encode([
                    'schema_reference' =>
                        'payroll-submission-artifact-aad.v2',
                    'supplier_id' => $supplierId,
                    'environment' => $environment,
                    'submission_id' => $submissionId,
                    'part_id' => $partId,
                    'artifact_kind' => $artifactKind,
                    'direction' => $direction,
                    'mime_type' => $mimeType,
                    'artifact_sha256' => $artifactHash,
                    'xsd_version' => $xsdVersion,
                    'catalog_version' => $catalogVersion,
                    'channel' => $channel,
                ]),
            );
    }

    private function obligationStatus(string $submissionStatus): ?string
    {
        return match ($submissionStatus) {
            'prepared', 'ready' => 'prepared',
            'submitted', 'processing', 'waiting_for_identity' => 'submitted',
            'accepted' => 'fulfilled',
            'partially_accepted', 'rejected', 'correction_required' =>
                'manual_review',
            'cancelled_in_time' => 'cancelled',
            default => null,
        };
    }

    private function shouldApplyRemoteStatus(
        string $currentStatus,
        string $remoteStatus,
    ): bool {
        return $this->stateMachine->canTransition(
            $currentStatus,
            $remoteStatus,
        );
    }

    private function shouldApplyPartStatus(
        string $currentStatus,
        string $remoteStatus,
    ): bool {
        if ($currentStatus === $remoteStatus) {
            return false;
        }
        if (in_array(
            $currentStatus,
            ['accepted', 'correction_required'],
            true,
        )) {
            return false;
        }
        if ($currentStatus === 'rejected') {
            return $remoteStatus === 'correction_required';
        }
        $rank = [
            'draft' => 0,
            'validated' => 1,
            'prepared' => 2,
            'ready' => 3,
            'submitted' => 4,
            'processing' => 5,
            'accepted' => 6,
            'rejected' => 6,
            'correction_required' => 7,
        ];

        return isset($rank[$currentStatus], $rank[$remoteStatus])
            && $rank[$remoteStatus] > $rank[$currentStatus];
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function sameObligationScope(array $left, array $right): bool
    {
        foreach ([
            'environment',
            'agenda_code',
            'subject_type',
            'subject_reference',
            'period_start',
            'period_end',
        ] as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{office_id:?int,run_id:int} $revision
     * @param array{subject_type:string,subject_reference:string} $obligation
     */
    private function revisionMatchesObligationScope(
        array $revision,
        array $obligation,
    ): bool {
        return match ($obligation['subject_type']) {
            'office' => $revision['office_id'] !== null
                && $obligation['subject_reference']
                    === 'office:' . $revision['office_id'],
            'payroll_run' => $obligation['subject_reference']
                === 'payroll_run:' . $revision['run_id'],
            default => true,
        };
    }

    private function now(): string
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private static function formOutcomeErrorsContext(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $receiptId,
        string $formReference,
        string $errorsHash,
    ): string {
        return 'payroll-jmhz-protocol-form-errors:' . hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' => 'payroll-jmhz-protocol-form-errors-aad.v1',
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'submission_id' => $submissionId,
                'receipt_id' => $receiptId,
                'form_reference' => $formReference,
                'errors_hash' => $errorsHash,
            ]),
        );
    }

    private function idempotencyHash(string $key): string
    {
        if (mb_strlen($key, '8bit') < 8
            || mb_strlen($key, '8bit') > 200
        ) {
            throw new \InvalidArgumentException(
                'Idempotency klíč podání není platný.',
            );
        }

        return hash('sha256', $key, true);
    }

    private function assertPositive(int $value, string $field): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException(
                "{$field} musí být kladné číslo.",
            );
        }
    }

    private function assertOptionalPositive(
        ?int $value,
        string $field,
    ): void {
        if ($value !== null) {
            $this->assertPositive($value, $field);
        }
    }

    private function assertHash(string $value, string $field): void
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                "{$field} není SHA-256.",
            );
        }
    }

    private function assertReference(string $value, int $maxLength): void
    {
        if ($maxLength < 1
            || $maxLength > 128
            || preg_match(
                '/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,'
                    . ($maxLength - 1) . '}$/D',
                $value,
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Interní reference není platná.',
            );
        }
    }

    private function assertCode(
        string $value,
        int $maxLength,
        string $field,
    ): void {
        if ($value === ''
            || mb_strlen($value, 'UTF-8') > $maxLength
        ) {
            throw new \InvalidArgumentException(
                "{$field} není platná hodnota.",
            );
        }
    }

    /** @param list<string> $allowed */
    private function assertAllowed(
        string $value,
        array $allowed,
        string $field,
    ): void {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "{$field} není podporovaný.",
            );
        }
    }
}
