<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\JmhzEldpEvidenceSnapshotRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;

final readonly class JmhzEldpEvidenceSnapshotService
{
    private const MANIFEST_SCHEMA = 'payroll-jmhz-eldp-evidence-manifest.v1';
    private const REQUEST_SCHEMA = 'payroll-jmhz-eldp-evidence-request.v1';

    public function __construct(
        private JmhzEldpEvidenceSnapshotRepository $repository,
        private JmhzEldpEvidenceBuilder $builder,
        private PayrollSensitiveData $sensitiveData,
        private SecretEncryption $encryption,
        private ActivityLogger $logger,
    ) {}

    /**
     * @param array<string,mixed> $confirmation
     * @return array<string,mixed>
     */
    public function freeze(
        int $supplierId,
        int $sourceRevisionId,
        int $employmentId,
        string $environment,
        array $confirmation,
        string $idempotencyKey,
        ?int $createdBy,
        string $sourceKind = 'explicit_confirmation',
    ): array {
        if ($supplierId <= 0 || $sourceRevisionId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException('Firma, revize a pracovní vztah musí být kladná čísla.');
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new JmhzEldpEvidenceException('jmhz_eldp_environment_invalid', 'Prostředí ELDP není platné.');
        }
        if ($createdBy === null || $createdBy <= 0) {
            throw new \InvalidArgumentException('Uživatel potvrzení ELDP musí být kladné číslo.');
        }
        if (!in_array($sourceKind, ['explicit_confirmation', 'derived_from_frozen_payroll_sources'], true)) {
            throw new \InvalidArgumentException('Zdroj potvrzení ELDP není platný.');
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 190) {
            throw new \InvalidArgumentException('Idempotency klíč musí mít 1 až 190 bajtů.');
        }
        $idempotencyHash = hash('sha256', $idempotencyKey, true);
        $confirmationFingerprint = $this->confirmationFingerprint(
            $supplierId,
            $environment,
            $sourceRevisionId,
            $employmentId,
            $confirmation,
        );

        return $this->repository->transaction(function () use (
            $supplierId,
            $sourceRevisionId,
            $employmentId,
            $environment,
            $confirmation,
            $idempotencyHash,
            $confirmationFingerprint,
            $createdBy,
            $sourceKind,
        ): array {
            $source = $this->repository->lockSource($supplierId, $sourceRevisionId);
            if ($source === null) {
                throw new JmhzEldpEvidenceException('jmhz_eldp_revision_not_found', 'Zdrojová revize ELDP nebyla nalezena.');
            }
            $inserted = $this->repository->insertClaim(
                $supplierId,
                $environment,
                $idempotencyHash,
                $sourceRevisionId,
                $employmentId,
                $confirmationFingerprint,
                $createdBy,
            );
            if (!$inserted) {
                $claim = $this->repository->findClaimForUpdate($supplierId, $environment, $idempotencyHash);
                if ($claim === null
                    || ($claim['source_revision_id'] ?? null) !== $sourceRevisionId
                    || ($claim['employment_id'] ?? null) !== $employmentId
                ) {
                    throw new JmhzEldpEvidenceException('jmhz_eldp_idempotency_scope_mismatch', 'Opakování ELDP neodpovídá původnímu rozsahu.');
                }
                if (!hash_equals((string) ($claim['confirmation_fingerprint'] ?? ''), $confirmationFingerprint)) {
                    throw new JmhzEldpEvidenceException('jmhz_eldp_idempotency_payload_mismatch', 'Idempotentní opakování ELDP má jiný obsah potvrzení.');
                }
                $snapshotId = $claim['evidence_snapshot_id'] ?? null;
                if (!is_int($snapshotId)) {
                    throw new JmhzEldpEvidenceException('jmhz_eldp_idempotency_incomplete', 'Idempotentní vazba ELDP není dokončená.');
                }
                $stored = $this->repository->find($supplierId, $environment, $snapshotId);
                if ($stored === null) {
                    throw new JmhzEldpEvidenceException('jmhz_eldp_idempotency_incomplete', 'Idempotentní vazba ELDP nemá snapshot.');
                }
                $this->verifyStored($stored);
                return $this->result($stored, false);
            }

            JmhzScenarioRequirementSourceCatalog::load();
            JmhzControlSourceCatalog::load();
            $snapshot = $this->builder->build($supplierId, $employmentId, $source, $confirmation);
            $plaintext = $snapshot->canonicalJson();
            $fingerprint = $this->sensitiveData->keyedFingerprint(
                $plaintext,
                'jmhz-eldp-evidence',
                $supplierId,
            );
            $scope = $snapshot->payload['scope'];
            if (!is_array($scope) || array_is_list($scope)) {
                throw new \UnexpectedValueException('Scope ELDP není objekt.');
            }
            $manifestJson = CanonicalJson::encode([
                'schema_reference' => self::MANIFEST_SCHEMA,
                'builder_version' => JmhzEldpEvidenceBuilder::BUILDER_VERSION,
                'scope' => $scope,
                'specification' => $snapshot->payload['specification'],
                'source_revision' => $snapshot->payload['source_revision'],
                'source_evidence' => [
                    'term_id' => $snapshot->payload['source_evidence']['term_id'],
                    'term_row_version' => $snapshot->payload['source_evidence']['term_row_version'],
                    'work_summary_id' => $snapshot->payload['source_evidence']['work_summary_id'],
                    'work_summary_sha256' => $snapshot->payload['source_evidence']['work_summary_sha256'],
                ],
                'section_count' => 1,
                'snapshot_fingerprint' => $fingerprint,
            ]);
            $manifestHash = hash('sha256', $manifestJson);
            $requestFingerprint = hash('sha256', CanonicalJson::encode([
                'schema_reference' => self::REQUEST_SCHEMA,
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'source_revision_id' => $sourceRevisionId,
                'employment_id' => $employmentId,
                'source_manifest_sha256' => $manifestHash,
            ]));
            $existing = $this->repository->findByScopeForUpdate(
                $supplierId,
                $environment,
                $sourceRevisionId,
                $employmentId,
            );
            if ($existing !== null) {
                $this->verifyStored($existing);
                if (!hash_equals((string) $existing['request_fingerprint'], $requestFingerprint)) {
                    throw new JmhzEldpEvidenceException('jmhz_eldp_scope_already_frozen', 'ELDP tohoto vztahu je již zmrazené s jiným obsahem.');
                }
                $this->repository->bindClaim($supplierId, $environment, $idempotencyHash, (int) $existing['id']);
                return $this->result($existing, false);
            }
            $ciphertext = $this->encryption->encryptFor(
                $plaintext,
                $this->encryptionContext(
                    $supplierId,
                    $environment,
                    $sourceRevisionId,
                    $employmentId,
                    $fingerprint,
                    $manifestHash,
                ),
            );
            $id = $this->repository->insert([
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'run_id' => $scope['run_id'],
                'source_revision_id' => $sourceRevisionId,
                'employee_id' => $scope['employee_id'],
                'employment_id' => $employmentId,
                'period_start' => $scope['period_start'],
                'schema_reference' => JmhzEldpEvidenceSnapshot::SCHEMA_REFERENCE,
                'section_count' => 1,
                'source_manifest_json' => $manifestJson,
                'source_manifest_sha256' => $manifestHash,
                'snapshot_ciphertext' => $ciphertext,
                'snapshot_fingerprint' => $fingerprint,
                'request_fingerprint' => $requestFingerprint,
                'idempotency_key_hash' => $idempotencyHash,
                'created_by' => $createdBy,
            ]);
            $this->repository->bindClaim($supplierId, $environment, $idempotencyHash, $id);
            $this->logger->log(
                'payroll.jmhz_eldp_evidence.frozen',
                $createdBy,
                'payroll_jmhz_eldp_evidence_snapshots',
                $id,
                [
                    'environment' => $environment,
                    'source_revision_id' => $sourceRevisionId,
                    'employment_id' => $employmentId,
                    'source_kind' => $sourceKind,
                    'source_manifest_sha256' => $manifestHash,
                ],
                null,
                null,
                $supplierId,
            );
            $stored = $this->repository->find($supplierId, $environment, $id);
            if ($stored === null) {
                throw new \RuntimeException('Uložené ELDP nelze načíst.');
            }
            $this->verifyStored($stored);
            return $this->result($stored, true);
        });
    }

    /**
     * @return array{snapshot:array<string,mixed>|null,issue_code:string|null}
     */
    public function ensureForPreparation(
        int $supplierId,
        string $environment,
        int $sourceRevisionId,
        int $employmentId,
        ?int $createdBy,
    ): array {
        $existing = $this->snapshotForPreparation(
            $supplierId,
            $environment,
            $sourceRevisionId,
            $employmentId,
        );
        if ($existing !== null || $createdBy === null) {
            return ['snapshot' => $existing, 'issue_code' => null];
        }

        $source = $this->repository->lockSource($supplierId, $sourceRevisionId);
        if ($source === null) {
            return ['snapshot' => null, 'issue_code' => 'jmhz_eldp_revision_not_found'];
        }
        try {
            $confirmation = $this->builder->deriveOrdinaryConfirmation(
                $supplierId,
                $employmentId,
                $source,
            );
        } catch (JmhzEldpEvidenceException $exception) {
            return ['snapshot' => null, 'issue_code' => $exception->validationCode];
        }

        $this->freeze(
            $supplierId,
            $sourceRevisionId,
            $employmentId,
            $environment,
            $confirmation,
            "jmhz-eldp-derived-v1:{$sourceRevisionId}:{$employmentId}",
            $createdBy,
            'derived_from_frozen_payroll_sources',
        );

        return [
            'snapshot' => $this->snapshotForPreparation(
                $supplierId,
                $environment,
                $sourceRevisionId,
                $employmentId,
            ),
            'issue_code' => null,
        ];
    }

    /** @return array<string,mixed>|null */
    public function snapshotForPreparation(
        int $supplierId,
        string $environment,
        int $sourceRevisionId,
        int $employmentId,
    ): ?array {
        $stored = $this->repository->findByScopeForUpdate(
            $supplierId,
            $environment,
            $sourceRevisionId,
            $employmentId,
        );
        if ($stored === null) {
            return null;
        }
        $payload = $this->verifyStored($stored);
        return [
            'id' => $stored['id'],
            'source_manifest_sha256' => $stored['source_manifest_sha256'],
            'snapshot_fingerprint' => $stored['snapshot_fingerprint'],
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string,mixed> $stored
     * @return array<string,mixed>
     */
    private function verifyStored(array $stored): array
    {
        $manifestJson = $stored['source_manifest_json'] ?? null;
        $manifestHash = $stored['source_manifest_sha256'] ?? null;
        if (!is_string($manifestJson) || !is_string($manifestHash)
            || !hash_equals($manifestHash, hash('sha256', $manifestJson))
        ) {
            throw new JmhzEldpEvidenceException('jmhz_eldp_hash_mismatch', 'Otisk manifestu ELDP nesouhlasí.');
        }
        $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || array_is_list($manifest)
            || CanonicalJson::encode($manifest) !== $manifestJson
            || ($manifest['schema_reference'] ?? null) !== self::MANIFEST_SCHEMA
            || ($manifest['builder_version'] ?? null) !== JmhzEldpEvidenceBuilder::BUILDER_VERSION
            || ($manifest['section_count'] ?? null) !== 1
            || ($stored['schema_reference'] ?? null) !== JmhzEldpEvidenceSnapshot::SCHEMA_REFERENCE
            || ($stored['section_count'] ?? null) !== 1
            || ($manifest['snapshot_fingerprint'] ?? null) !== ($stored['snapshot_fingerprint'] ?? null)
        ) {
            throw new JmhzEldpEvidenceException('jmhz_eldp_hash_mismatch', 'Manifest ELDP není kanonický nebo úplný.');
        }
        $scope = $manifest['scope'] ?? null;
        if (!is_array($scope) || array_is_list($scope)
            || ($scope['supplier_id'] ?? null) !== ($stored['supplier_id'] ?? null)
            || ($scope['run_id'] ?? null) !== ($stored['run_id'] ?? null)
            || ($scope['source_revision_id'] ?? null) !== ($stored['source_revision_id'] ?? null)
            || ($scope['employee_id'] ?? null) !== ($stored['employee_id'] ?? null)
            || ($scope['employment_id'] ?? null) !== ($stored['employment_id'] ?? null)
            || ($scope['period_start'] ?? null) !== ($stored['period_start'] ?? null)
        ) {
            throw new JmhzEldpEvidenceException('jmhz_eldp_hash_mismatch', 'Scope ELDP neodpovídá uloženým metadatům.');
        }
        $plaintext = $this->encryption->decryptFor(
            (string) $stored['snapshot_ciphertext'],
            $this->encryptionContext(
                (int) $stored['supplier_id'],
                (string) $stored['environment'],
                (int) $stored['source_revision_id'],
                (int) $stored['employment_id'],
                (string) $stored['snapshot_fingerprint'],
                (string) $stored['source_manifest_sha256'],
            ),
        );
        if (!hash_equals(
            (string) $stored['snapshot_fingerprint'],
            $this->sensitiveData->keyedFingerprint($plaintext, 'jmhz-eldp-evidence', (int) $stored['supplier_id']),
        )) {
            throw new JmhzEldpEvidenceException('jmhz_eldp_hash_mismatch', 'Citlivý snapshot ELDP má jiný otisk.');
        }
        $payload = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload)
            || CanonicalJson::encode($payload) !== $plaintext
            || ($payload['schema_reference'] ?? null) !== JmhzEldpEvidenceSnapshot::SCHEMA_REFERENCE
            || CanonicalJson::encode($payload['scope'] ?? null) !== CanonicalJson::encode($scope)
            || CanonicalJson::encode($payload['specification'] ?? null) !== CanonicalJson::encode($manifest['specification'] ?? null)
            || CanonicalJson::encode($payload['source_revision'] ?? null) !== CanonicalJson::encode($manifest['source_revision'] ?? null)
            || count($payload['eldp_sections'] ?? []) !== 1
        ) {
            throw new JmhzEldpEvidenceException('jmhz_eldp_hash_mismatch', 'Citlivý snapshot ELDP neodpovídá manifestu.');
        }
        $payloadEvidence = $payload['source_evidence'] ?? null;
        $manifestEvidence = $manifest['source_evidence'] ?? null;
        if (!is_array($payloadEvidence) || array_is_list($payloadEvidence)
            || !is_array($manifestEvidence) || array_is_list($manifestEvidence)
            || CanonicalJson::encode([
                'term_id' => $payloadEvidence['term_id'] ?? null,
                'term_row_version' => $payloadEvidence['term_row_version'] ?? null,
                'work_summary_id' => $payloadEvidence['work_summary_id'] ?? null,
                'work_summary_sha256' => $payloadEvidence['work_summary_sha256'] ?? null,
            ]) !== CanonicalJson::encode($manifestEvidence)
        ) {
            throw new JmhzEldpEvidenceException('jmhz_eldp_hash_mismatch', 'Provenance ELDP neodpovídá manifestu.');
        }
        $expectedRequest = hash('sha256', CanonicalJson::encode([
            'schema_reference' => self::REQUEST_SCHEMA,
            'supplier_id' => $stored['supplier_id'],
            'environment' => $stored['environment'],
            'source_revision_id' => $stored['source_revision_id'],
            'employment_id' => $stored['employment_id'],
            'source_manifest_sha256' => $stored['source_manifest_sha256'],
        ]));
        if (!hash_equals((string) $stored['request_fingerprint'], $expectedRequest)) {
            throw new JmhzEldpEvidenceException('jmhz_eldp_hash_mismatch', 'Request fingerprint ELDP nesouhlasí.');
        }
        return $payload;
    }

    private function encryptionContext(
        int $supplierId,
        string $environment,
        int $revisionId,
        int $employmentId,
        string $fingerprint,
        string $manifestHash,
    ): string {
        return "payroll:jmhz-eldp:{$supplierId}:{$environment}:{$revisionId}:{$employmentId}:{$fingerprint}:{$manifestHash}";
    }

    /** @param array<string,mixed> $confirmation */
    private function confirmationFingerprint(
        int $supplierId,
        string $environment,
        int $sourceRevisionId,
        int $employmentId,
        array $confirmation,
    ): string {
        $note = $confirmation['confirmation_note'] ?? null;
        $normalized = [
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'source_revision_id' => $sourceRevisionId,
            'employment_id' => $employmentId,
            'confirmation' => [
                'insurance_from' => $confirmation['insurance_from'] ?? null,
                'insurance_to' => $confirmation['insurance_to'] ?? null,
                'valid_from' => $confirmation['valid_from'] ?? null,
                'valid_to' => $confirmation['valid_to'] ?? null,
                'insurance_days' => $confirmation['insurance_days'] ?? null,
                'code' => $confirmation['code'] ?? null,
                'assessment_base_czk' => $confirmation['assessment_base_czk'] ?? null,
                'in03_active' => $confirmation['in03_active'] ?? null,
                'in04_active' => $confirmation['in04_active'] ?? null,
                'confirmation_note' => is_string($note) ? trim($note) : $note,
            ],
        ];
        return $this->sensitiveData->keyedFingerprint(
            CanonicalJson::encode($normalized),
            'jmhz-eldp-confirmation',
            $supplierId,
        );
    }

    /**
     * @param array<string,mixed> $stored
     * @return array<string,mixed>
     */
    private function result(array $stored, bool $created): array
    {
        return [
            'id' => $stored['id'],
            'supplier_id' => $stored['supplier_id'],
            'environment' => $stored['environment'],
            'run_id' => $stored['run_id'],
            'source_revision_id' => $stored['source_revision_id'],
            'employee_id' => $stored['employee_id'],
            'employment_id' => $stored['employment_id'],
            'period_start' => $stored['period_start'],
            'schema_reference' => $stored['schema_reference'],
            'section_count' => $stored['section_count'],
            'source_manifest_sha256' => $stored['source_manifest_sha256'],
            'snapshot_fingerprint' => $stored['snapshot_fingerprint'],
            'created' => $created,
        ];
    }
}
