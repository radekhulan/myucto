<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Repository\Payroll\EldpManualCompletionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionConflictException;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class EldpManualCompletionService
{
    private const STATUSES = ['submitted', 'accepted'];

    public function __construct(
        private readonly EldpManualCompletionRepository $repository,
        private readonly DocumentRepository $documents,
        private readonly DocumentStorage $storage,
    ) {}

    /** @return array<string,mixed> */
    public function record(
        int $supplierId,
        string $environment,
        int $statementId,
        int $expectedObligationVersion,
        string $authorityStatus,
        int $documentId,
        string $authorityReference,
        string $confirmedOn,
        string $idempotencyKey,
        int $recordedBy,
    ): array {
        if ($supplierId <= 0 || $statementId <= 0 || $expectedObligationVersion <= 0
            || $documentId <= 0 || $recordedBy <= 0
        ) {
            throw new \InvalidArgumentException('ID a verze ručního dokončení musí být kladná čísla.');
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new EldpManualCompletionException('eldp_environment_invalid', 'Prostředí ELDP není platné.');
        }
        if (!in_array($authorityStatus, self::STATUSES, true)) {
            throw new EldpManualCompletionException('eldp_manual_status_invalid', 'Výsledek musí být submitted nebo accepted.');
        }
        $authorityReference = trim($authorityReference);
        if ($authorityReference === '' || mb_strlen($authorityReference) > 190) {
            throw new EldpManualCompletionException('eldp_manual_reference_invalid', 'Reference potvrzení musí mít 1 až 190 znaků.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $confirmedOn);
        if ($date === false || $date->format('Y-m-d') !== $confirmedOn) {
            throw new EldpManualCompletionException('eldp_manual_date_invalid', 'Datum potvrzení musí mít formát YYYY-MM-DD.');
        }
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague'));
        if ($date > $today) {
            throw new EldpManualCompletionException('eldp_manual_date_future', 'Datum potvrzení nesmí být v budoucnosti.');
        }
        $idempotencyKey = trim($idempotencyKey);
        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 190) {
            throw new \InvalidArgumentException('Idempotency klíč musí mít 8 až 190 bajtů.');
        }
        $idempotencyHash = hash('sha256', $idempotencyKey, true);

        return $this->repository->transaction(function () use (
            $supplierId,
            $environment,
            $statementId,
            $expectedObligationVersion,
            $authorityStatus,
            $documentId,
            $authorityReference,
            $confirmedOn,
            $idempotencyHash,
            $recordedBy,
        ): array {
            if (!$this->repository->lockSupplier($supplierId)) {
                throw new EldpManualCompletionException('eldp_manual_statement_not_found', 'Firma ELDP nebyla nalezena.', 404);
            }
            $context = $this->repository->contextForUpdate($supplierId, $environment, $statementId);
            if ($context === null) {
                throw new EldpManualCompletionException('eldp_manual_statement_not_found', 'Připravený ELDP nebyl nalezen v této firmě.', 404);
            }
            if ($context['local_submission_status'] !== 'prepared') {
                throw new EldpManualCompletionException(
                    'eldp_control_submission_state_invalid',
                    'Kontrolní ELDP musí zůstat ve stavu prepared.',
                    409,
                );
            }

            $idempotentReplay = $this->repository->byIdempotencyForUpdate(
                $supplierId,
                $environment,
                $idempotencyHash,
            );
            if ($idempotentReplay !== null) {
                if ((int) $idempotentReplay['statement_id'] !== $statementId
                    || (string) $idempotentReplay['authority_status'] !== $authorityStatus
                    || (int) $idempotentReplay['confirmation_document_id'] !== $documentId
                    || (string) $idempotentReplay['authority_reference'] !== $authorityReference
                    || (string) $idempotentReplay['confirmed_on'] !== $confirmedOn
                ) {
                    throw new EldpManualCompletionException(
                        'eldp_manual_completion_frozen',
                        'Idempotency klíč ELDP už je neměnně spojený s jiným výsledkem.',
                        409,
                    );
                }
                return $this->result($idempotentReplay, $context, false);
            }

            $document = $this->documents->findActiveFileReferenceForUpdate(
                $documentId,
                $supplierId,
                DocumentViewerContext::companyOnly(),
            );
            if ($document === null) {
                throw new EldpManualCompletionException(
                    'eldp_confirmation_document_required',
                    'Výsledek musí dokládat aktivní firemní dokument této firmy.',
                );
            }
            [$sha256, $byteSize] = $this->readDocumentEvidence($supplierId, $document);
            if (!hash_equals(strtolower($document['sha256']), $sha256)
                || $byteSize !== $document['size_bytes']
            ) {
                throw new EldpManualCompletionException(
                    'eldp_confirmation_document_corrupt',
                    'Bajty potvrzení neodpovídají evidenci dokumentu.',
                    409,
                );
            }

            $manifest = [
                'schema_reference' => 'payroll-eldp-manual-completion.v1',
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'statement_id' => $statementId,
                'obligation_id' => $context['obligation_id'],
                'authority_status' => $authorityStatus,
                'confirmation_document_supplier_id' => $supplierId,
                'confirmation_document_id' => $documentId,
                'confirmation_sha256' => $sha256,
                'confirmation_byte_size' => $byteSize,
                'confirmation_mime_type' => $document['mime_type'],
                'authority_reference' => $authorityReference,
                'confirmed_on' => $confirmedOn,
            ];
            $manifestJson = CanonicalJson::encode($manifest);
            $requestFingerprint = hash('sha256', $manifestJson);

            $existing = $this->repository->bySlotForUpdate(
                $supplierId,
                $environment,
                $statementId,
                $authorityStatus,
            );
            if ($existing !== null) {
                if (!hash_equals($existing['request_fingerprint'], $requestFingerprint)) {
                    throw new EldpManualCompletionException(
                        'eldp_manual_completion_frozen',
                        'Tento doložený stav ELDP už je neměnně uložen s jinými údaji.',
                        409,
                    );
                }
                return $this->result($existing, $context, false);
            }

            if ($context['obligation_row_version'] !== $expectedObligationVersion) {
                throw new EldpManualCompletionException(
                    'row_version_conflict',
                    'Povinnost ELDP se mezitím změnila.',
                    409,
                    $context['obligation_row_version'],
                );
            }
            if ($context['obligation_status'] === 'fulfilled') {
                throw new EldpManualCompletionException('eldp_manual_already_fulfilled', 'Povinnost ELDP už je splněná.', 409);
            }
            if ($authorityStatus === 'submitted' && $context['obligation_status'] === 'submitted') {
                throw new EldpManualCompletionException('eldp_manual_submitted_frozen', 'Doložení podání už je uložené.', 409);
            }

            $row = [
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'statement_id' => $statementId,
                'obligation_id' => $context['obligation_id'],
                'authority_status' => $authorityStatus,
                'confirmation_document_supplier_id' => $supplierId,
                'confirmation_document_id' => $documentId,
                'confirmation_sha256' => $sha256,
                'confirmation_byte_size' => $byteSize,
                'confirmation_mime_type' => $document['mime_type'],
                'authority_reference' => $authorityReference,
                'confirmed_on' => $confirmedOn,
                'evidence_manifest_json' => $manifestJson,
                'evidence_sha256' => hash('sha256', $manifestJson),
                'request_fingerprint' => $requestFingerprint,
                'idempotency_key_hash' => $idempotencyHash,
                'obligation_row_version_before' => $context['obligation_row_version'],
                'recorded_by' => $recordedBy,
            ];
            $row['id'] = $this->repository->insert($row);
            $targetObligationStatus = $authorityStatus === 'accepted' ? 'fulfilled' : 'submitted';
            try {
                $this->repository->updateObligationStatus(
                    $supplierId,
                    $environment,
                    $context['obligation_id'],
                    $context['obligation_row_version'],
                    $targetObligationStatus,
                );
            } catch (PayrollSubmissionConflictException $exception) {
                throw new EldpManualCompletionException('row_version_conflict', $exception->getMessage(), 409);
            }
            $context['obligation_status'] = $targetObligationStatus;
            ++$context['obligation_row_version'];
            return $this->result($row, $context, true);
        });
    }

    /** @return array<string,mixed>|null */
    public function overview(int $supplierId, string $environment, int $statementId): ?array
    {
        $context = $this->repository->context($supplierId, $environment, $statementId);
        if ($context === null) {
            return null;
        }
        $context['evidence'] = array_map($this->publicEvidence(...), $this->repository->history($supplierId, $environment, $statementId));
        return $context;
    }

    /** @param array<string,mixed> $document @return array{0:string,1:int} */
    private function readDocumentEvidence(int $supplierId, array $document): array
    {
        $path = $this->storage->pathFor($supplierId, $document['sha256'], $document['filename']);
        clearstatcache(true, $path);
        $sha256 = is_file($path) ? @hash_file('sha256', $path) : false;
        $byteSize = is_file($path) ? @filesize($path) : false;
        if (!is_string($sha256) || !is_int($byteSize) || $byteSize <= 0) {
            throw new EldpManualCompletionException('eldp_confirmation_document_missing', 'Bajty potvrzení v DMS nejsou dostupné.', 409);
        }
        return [$sha256, $byteSize];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $context @return array<string,mixed> */
    private function result(array $row, array $context, bool $created): array
    {
        return [
            ...$this->publicEvidence($row),
            'created' => $created,
            'obligation_status' => $context['obligation_status'],
            'obligation_row_version' => $context['obligation_row_version'],
            'local_submission_status' => $context['local_submission_status'],
            'submission_id' => $context['submission_id'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicEvidence(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'statement_id' => (int) $row['statement_id'],
            'obligation_id' => (int) $row['obligation_id'],
            'authority_status' => (string) $row['authority_status'],
            'confirmation_document_id' => (int) $row['confirmation_document_id'],
            'confirmation_sha256' => (string) $row['confirmation_sha256'],
            'confirmation_byte_size' => (int) $row['confirmation_byte_size'],
            'confirmation_mime_type' => (string) $row['confirmation_mime_type'],
            'authority_reference' => (string) $row['authority_reference'],
            'confirmed_on' => (string) $row['confirmed_on'],
            'recorded_by' => (int) $row['recorded_by'],
            'recorded_at' => isset($row['recorded_at']) ? (string) $row['recorded_at'] : null,
        ];
    }
}
