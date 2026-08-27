<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\JmhzOrdinaryEvidenceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use Psr\Clock\ClockInterface;

final readonly class JmhzOrdinaryEvidenceService
{
    private const MANIFEST_SCHEMA = 'payroll-jmhz-ordinary-evidence-manifest.v1';
    private const REQUEST_SCHEMA = 'payroll-jmhz-ordinary-evidence-request.v1';

    public function __construct(
        private JmhzOrdinaryEvidenceRepository $repository,
        private JmhzOrdinaryEvidenceBuilder $builder,
        private PayrollSensitiveData $sensitiveData,
        private SecretEncryption $encryption,
        private ClockInterface $clock,
        private ActivityLogger $logger,
        private JmhzOrdinaryEvidenceApplicability $applicability,
    ) {}

    /**
     * Stav ordinary evidence revize: co je zmrazené a KOMU to ještě chybí.
     *
     * @return array{scopes:list<array<string,mixed>>,evidences:list<array<string,mixed>>}
     */
    public function evidence(int $supplierId, int $revisionId): array
    {
        if ($supplierId <= 0 || $revisionId <= 0) {
            throw new JmhzOrdinaryEvidenceException('jmhz_ordinary_evidence_not_found', 'Ordinary evidence nebyla nalezena.');
        }
        return $this->repository->transaction(function () use ($supplierId, $revisionId): array {
            $source = $this->repository->lockSource($supplierId, $revisionId);
            if ($source === null) {
                throw new JmhzOrdinaryEvidenceException(
                    'jmhz_ordinary_evidence_not_found',
                    'Zdrojová revize nebyla nalezena.',
                );
            }
            $revision = $source['revision'] ?? null;
            if (!is_array($revision) || array_is_list($revision)) {
                throw new \UnexpectedValueException('Zdrojová revize ordinary evidence není objekt.');
            }
            $evidences = [];
            $confirmed = [];
            foreach ($this->repository->findAllByRevision($supplierId, $revisionId) as $stored) {
                $payload = $this->verifyStored($stored);
                $evidence = $this->publicResult($stored, $payload, false);
                $evidences[] = $evidence;
                $confirmed[(int) $stored['employment_id']] = $this->preparationSource($stored, $payload);
            }
            $scopes = [];
            foreach ($this->frozenScopes($source) as $scope) {
                $term = $scope['term'];
                unset($scope['term']);
                $stored = $confirmed[$scope['employment_id']] ?? null;
                if (is_array($stored)) {
                    try {
                        $this->applicability->assertApplicable(
                            $stored,
                            $supplierId,
                            $revision,
                            $scope['employee_id'],
                            $scope['employment_id'],
                            $term,
                        );
                        $scopes[] = $scope + [
                            'confirmed' => true,
                            'resolution' => 'confirmed',
                            'attention_code' => null,
                            'attention_message' => null,
                        ];
                    } catch (JmhzOrdinaryEvidenceApplicabilityException $exception) {
                        $scopes[] = $scope + [
                            'confirmed' => false,
                            'resolution' => 'attention_required',
                            'attention_code' => $exception->validationCode,
                            'attention_message' => $exception->getMessage(),
                        ];
                    }
                    continue;
                }
                try {
                    $this->builder->build(
                        $supplierId,
                        $source,
                        $scope['employment_id'],
                        $this->ordinaryFacts(),
                        1,
                        '2000-01-01T00:00:00Z',
                        'derived_from_frozen_payroll_sources',
                    );
                    $scopes[] = $scope + [
                        'confirmed' => false,
                        'resolution' => 'automatic_on_preparation',
                        'attention_code' => null,
                        'attention_message' => null,
                    ];
                } catch (JmhzOrdinaryEvidenceException $exception) {
                    $scopes[] = $scope + [
                        'confirmed' => false,
                        'resolution' => 'attention_required',
                        'attention_code' => $exception->validationCode,
                        'attention_message' => $exception->getMessage(),
                    ];
                }
            }
            return ['scopes' => $scopes, 'evidences' => $evidences];
        });
    }

    /**
     * Pracovní vztahy zmrazené v revizi — rozsah, za který se evidence potvrzuje.
     *
     * @param array<string,mixed> $source
     * @return list<array{employee_id:int,employment_id:int,employee_name:string,term:array<string,mixed>}>
     */
    private function frozenScopes(array $source): array
    {
        $revision = $source['revision'] ?? null;
        $json = is_array($revision) ? ($revision['input_snapshot_json'] ?? null) : null;
        if (!is_string($json)) {
            return [];
        }
        $input = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $people = is_array($input) ? ($input['people'] ?? null) : null;
        if (!is_array($people) || !array_is_list($people)) {
            return [];
        }
        $scopes = [];
        foreach ($people as $person) {
            if (!is_array($person) || array_is_list($person)) {
                continue;
            }
            $employee = $person['employee'] ?? null;
            $employeeId = is_array($employee) && is_int($employee['id'] ?? null)
                ? $employee['id']
                : 0;
            $name = is_array($employee) && is_string($employee['full_name'] ?? null)
                ? $employee['full_name']
                : '';
            $employments = $person['employments'] ?? null;
            if ($employeeId <= 0 || !is_array($employments) || !array_is_list($employments)) {
                continue;
            }
            foreach ($employments as $entry) {
                $employment = is_array($entry) ? ($entry['employment'] ?? null) : null;
                $term = is_array($entry) ? ($entry['term'] ?? null) : null;
                $employmentId = is_array($employment) && is_int($employment['id'] ?? null)
                    ? $employment['id']
                    : 0;
                if ($employmentId <= 0) {
                    continue;
                }
                $scopes[] = [
                    'employee_id' => $employeeId,
                    'employment_id' => $employmentId,
                    'employee_name' => $name,
                    'term' => is_array($term) && !array_is_list($term) ? $term : [],
                ];
            }
        }
        usort(
            $scopes,
            static fn (array $left, array $right): int =>
                [$left['employee_id'], $left['employment_id']]
                <=> [$right['employee_id'], $right['employment_id']],
        );
        return $scopes;
    }

    /**
     * @param array<string,mixed> $facts
     * @return array<string,mixed>
     */
    public function confirm(
        int $supplierId,
        int $revisionId,
        int $employmentId,
        array $facts,
        string $idempotencyKey,
        int $confirmedBy,
        ?string $ip,
        ?string $userAgent,
        string $sourceKind = 'explicit_confirmation',
    ): array {
        if ($supplierId <= 0 || $revisionId <= 0 || $confirmedBy <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException('Firma, revize, vztah a potvrzující uživatel musí být kladná čísla.');
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 190) {
            throw new \InvalidArgumentException('Idempotency-Key musí mít 1 až 190 bajtů.');
        }
        $facts = JmhzOrdinaryEvidenceBuilder::normalizeFacts($facts);
        $idempotencyHash = hash('sha256', $idempotencyKey, true);
        $confirmationFingerprint = $this->sensitiveData->keyedFingerprint(
            CanonicalJson::encode([
                'schema_reference' => self::REQUEST_SCHEMA,
                'supplier_id' => $supplierId,
                'source_revision_id' => $revisionId,
                'employment_id' => $employmentId,
                'facts' => $facts,
                'source_kind' => $sourceKind,
            ]),
            'jmhz-ordinary-confirmation',
            $supplierId,
        );

        return $this->repository->transaction(function () use (
            $supplierId,
            $revisionId,
            $employmentId,
            $facts,
            $idempotencyHash,
            $confirmationFingerprint,
            $confirmedBy,
            $ip,
            $userAgent,
            $sourceKind,
        ): array {
            $source = $this->repository->lockSource($supplierId, $revisionId);
            if ($source === null) {
                throw new JmhzOrdinaryEvidenceException('jmhz_ordinary_evidence_not_found', 'Zdrojová revize nebyla nalezena.');
            }
            $preview = $this->builder->build(
                $supplierId,
                $source,
                $employmentId,
                $facts,
                $confirmedBy,
                '2000-01-01T00:00:00Z',
                $sourceKind,
            );
            $scope = $preview->payload['scope'];
            if (!is_array($scope) || array_is_list($scope)) {
                throw new \UnexpectedValueException('Scope ordinary evidence není objekt.');
            }
            $employeeId = (int) ($scope['employee_id'] ?? 0);
            $employmentId = (int) ($scope['employment_id'] ?? 0);
            $inserted = $this->repository->insertClaim(
                $supplierId,
                $idempotencyHash,
                $revisionId,
                $employeeId,
                $employmentId,
                $confirmationFingerprint,
                $confirmedBy,
            );
            if (!$inserted) {
                $claim = $this->repository->findClaimForUpdate($supplierId, $idempotencyHash);
                if ($claim === null
                    || ($claim['source_revision_id'] ?? null) !== $revisionId
                    || ($claim['employee_id'] ?? null) !== $employeeId
                    || ($claim['employment_id'] ?? null) !== $employmentId
                ) {
                    throw new JmhzOrdinaryEvidenceException('jmhz_ordinary_evidence_idempotency_scope_mismatch', 'Idempotentní opakování má jiný rozsah.');
                }
                if (!hash_equals((string) ($claim['confirmation_fingerprint'] ?? ''), $confirmationFingerprint)) {
                    throw new JmhzOrdinaryEvidenceException('jmhz_ordinary_evidence_idempotency_payload_mismatch', 'Idempotentní opakování má jiný obsah.');
                }
                $snapshotId = $claim['evidence_snapshot_id'] ?? null;
                if (!is_int($snapshotId)) {
                    throw new JmhzOrdinaryEvidenceException('jmhz_ordinary_evidence_idempotency_incomplete', 'Idempotentní ordinary evidence není dokončená.');
                }
                $stored = $this->repository->find($supplierId, $snapshotId);
                if ($stored === null) {
                    throw new JmhzOrdinaryEvidenceException('jmhz_ordinary_evidence_idempotency_incomplete', 'Idempotentní ordinary evidence chybí.');
                }
                return $this->publicResult($stored, $this->verifyStored($stored), false);
            }

            $confirmedAt = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z');
            $snapshot = $this->builder->build(
                $supplierId,
                $source,
                $employmentId,
                $facts,
                $confirmedBy,
                $confirmedAt,
                $sourceKind,
            );
            $plaintext = $snapshot->canonicalJson();
            $fingerprint = $this->sensitiveData->keyedFingerprint(
                $plaintext,
                'jmhz-ordinary-evidence',
                $supplierId,
            );
            $scope = $snapshot->payload['scope'];
            $manifestJson = CanonicalJson::encode([
                'schema_reference' => self::MANIFEST_SCHEMA,
                'builder_version' => JmhzOrdinaryEvidenceBuilder::BUILDER_VERSION,
                'scope' => $scope,
                'specification' => $snapshot->payload['specification'],
                'source_revision' => $snapshot->payload['source_revision'],
                'snapshot_fingerprint' => $fingerprint,
            ]);
            $manifestHash = hash('sha256', $manifestJson);
            $requestFingerprint = hash('sha256', CanonicalJson::encode([
                'schema_reference' => self::REQUEST_SCHEMA,
                'supplier_id' => $supplierId,
                'source_revision_id' => $revisionId,
                'source_manifest_sha256' => $manifestHash,
            ]));
            $existing = $this->repository->findByScopeForUpdate(
                $supplierId,
                $revisionId,
                $employeeId,
                $employmentId,
            );
            if ($existing !== null) {
                $existingPayload = $this->verifyStored($existing);
                if (!hash_equals((string) $existing['request_fingerprint'], $requestFingerprint)) {
                    throw new JmhzOrdinaryEvidenceException('jmhz_ordinary_evidence_scope_already_frozen', 'Pracovní vztah už má jiné immutable ordinary evidence.');
                }
                $this->repository->bindClaim($supplierId, $idempotencyHash, (int) $existing['id']);
                return $this->publicResult($existing, $existingPayload, false);
            }
            $ciphertext = $this->encryption->encryptFor(
                $plaintext,
                $this->encryptionContext($supplierId, $revisionId, $employmentId, $fingerprint, $manifestHash),
            );
            $id = $this->repository->insert([
                'supplier_id' => $supplierId,
                'run_id' => $scope['run_id'],
                'source_revision_id' => $revisionId,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'period_start' => $scope['period_start'],
                'schema_reference' => JmhzOrdinaryEvidenceSnapshot::SCHEMA_REFERENCE,
                'source_manifest_json' => $manifestJson,
                'source_manifest_sha256' => $manifestHash,
                'snapshot_ciphertext' => $ciphertext,
                'snapshot_fingerprint' => $fingerprint,
                'request_fingerprint' => $requestFingerprint,
                'idempotency_key_hash' => $idempotencyHash,
                'confirmed_by' => $confirmedBy,
                'confirmed_at' => str_replace(['T', 'Z'], [' ', ''], $confirmedAt),
            ]);
            $this->repository->bindClaim($supplierId, $idempotencyHash, $id);
            $this->logger->log(
                'payroll.jmhz_ordinary_evidence.confirmed',
                $confirmedBy,
                'payroll_jmhz_ordinary_evidence_snapshots',
                $id,
                [
                    'source_revision_id' => $revisionId,
                    'source_manifest_sha256' => $manifestHash,
                ],
                $ip,
                $userAgent,
                $supplierId,
            );
            $stored = $this->repository->find($supplierId, $id);
            if ($stored === null) {
                throw new \RuntimeException('Uloženou ordinary evidence nelze načíst.');
            }
            return $this->publicResult($stored, $this->verifyStored($stored), true);
        });
    }

    /**
     * Ordinary evidence revize podle pracovního vztahu.
     *
     * Klíčem je `employment_id` a mapa je seřazená, aby z ní příprava stavěla
     * deterministický (a tedy stabilně otisknutelný) snapshot.
     *
     * @return array{
     *   sources:array<int,array<string,mixed>>,
     *   issues:list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}>
     * }
     */
    public function snapshotsForPreparation(
        int $supplierId,
        int $revisionId,
        ?int $confirmedBy = null,
    ): array
    {
        if ($confirmedBy !== null) {
            $state = $this->evidence($supplierId, $revisionId);
            foreach ($state['scopes'] as $scope) {
                if (($scope['confirmed'] ?? false) === true
                    || ($scope['resolution'] ?? null) !== 'automatic_on_preparation'
                ) {
                    continue;
                }
                $employmentId = (int) ($scope['employment_id'] ?? 0);
                if ($employmentId <= 0) {
                    continue;
                }
                try {
                    $this->confirm(
                        $supplierId,
                        $revisionId,
                        $employmentId,
                        $this->ordinaryFacts(),
                        "jmhz-ordinary-derived-v1:{$revisionId}:{$employmentId}",
                        $confirmedBy,
                        null,
                        null,
                        'derived_from_frozen_payroll_sources',
                    );
                } catch (JmhzOrdinaryEvidenceException $exception) {
                    if (!in_array($exception->validationCode, [
                        'jmhz_ordinary_evidence_scenario_unsupported',
                        'jmhz_ordinary_evidence_profile_missing',
                        'jmhz_ordinary_evidence_profile_incomplete',
                        'jmhz_ordinary_evidence_monthly_exception_required',
                        'jmhz_ordinary_evidence_deduction_conflict',
                    ], true)) {
                        throw $exception;
                    }
                }
            }
        }
        $state = $this->evidence($supplierId, $revisionId);
        $attention = [];
        foreach ($state['scopes'] as $scope) {
            if (($scope['resolution'] ?? null) !== 'attention_required') {
                continue;
            }
            $code = $scope['attention_code'] ?? null;
            $employmentId = $scope['employment_id'] ?? null;
            if (is_string($code) && is_int($employmentId) && $employmentId > 0) {
                $attention[$employmentId] = [
                    'code' => $code,
                    'entity_type' => 'employment',
                    'entity_id' => $employmentId,
                    'attribute_ids' => ['10116', '10546'],
                ];
            }
        }
        $sources = [];
        foreach ($this->repository->findAllByRevision($supplierId, $revisionId) as $stored) {
            $employmentId = (int) $stored['employment_id'];
            if (isset($attention[$employmentId])) {
                continue;
            }
            $sources[$employmentId] = $this->preparationSource($stored, $this->verifyStored($stored));
        }
        ksort($sources, SORT_NUMERIC);
        ksort($attention, SORT_NUMERIC);
        return ['sources' => $sources, 'issues' => array_values($attention)];
    }

    /**
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function preparationSource(array $stored, array $payload): array
    {
        return [
            'id' => $stored['id'],
            'employee_id' => $stored['employee_id'],
            'employment_id' => $stored['employment_id'],
            'source_manifest_sha256' => $stored['source_manifest_sha256'],
            'snapshot_fingerprint' => $stored['snapshot_fingerprint'],
            'payload' => $payload,
        ];
    }

    /** @return array<string,false> */
    private function ordinaryFacts(): array
    {
        return [
            'reportable_wage_deductions_recorded' => false,
            'employee_social_discount_claimed' => false,
            'specific_legal_fact_occurred' => false,
            'ozp_employment_support_claimed' => false,
            'deep_mining_work_occurred' => false,
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
            throw new JmhzOrdinaryEvidenceException('jmhz_ordinary_evidence_hash_mismatch', 'Otisk manifestu ordinary evidence nesouhlasí.');
        }
        $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || array_is_list($manifest)
            || CanonicalJson::encode($manifest) !== $manifestJson
            || ($manifest['schema_reference'] ?? null) !== self::MANIFEST_SCHEMA
            || ($manifest['builder_version'] ?? null) !== JmhzOrdinaryEvidenceBuilder::BUILDER_VERSION
            || ($manifest['snapshot_fingerprint'] ?? null) !== ($stored['snapshot_fingerprint'] ?? null)
            || ($stored['schema_reference'] ?? null) !== JmhzOrdinaryEvidenceSnapshot::SCHEMA_REFERENCE
        ) {
            throw new JmhzOrdinaryEvidenceException('jmhz_ordinary_evidence_hash_mismatch', 'Manifest ordinary evidence není kanonický nebo úplný.');
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
            throw new JmhzOrdinaryEvidenceException('jmhz_ordinary_evidence_hash_mismatch', 'Scope ordinary evidence nesouhlasí.');
        }
        $plaintext = $this->encryption->decryptFor(
            (string) $stored['snapshot_ciphertext'],
            $this->encryptionContext(
                (int) $stored['supplier_id'],
                (int) $stored['source_revision_id'],
                (int) $stored['employment_id'],
                (string) $stored['snapshot_fingerprint'],
                (string) $stored['source_manifest_sha256'],
            ),
        );
        if (!hash_equals(
            (string) $stored['snapshot_fingerprint'],
            $this->sensitiveData->keyedFingerprint($plaintext, 'jmhz-ordinary-evidence', (int) $stored['supplier_id']),
        )) {
            throw new JmhzOrdinaryEvidenceException('jmhz_ordinary_evidence_hash_mismatch', 'Citlivý ordinary snapshot má jiný otisk.');
        }
        $payload = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload)
            || CanonicalJson::encode($payload) !== $plaintext
            || ($payload['schema_reference'] ?? null) !== JmhzOrdinaryEvidenceSnapshot::SCHEMA_REFERENCE
            || CanonicalJson::encode($payload['scope'] ?? null) !== CanonicalJson::encode($scope)
            || CanonicalJson::encode($payload['specification'] ?? null) !== CanonicalJson::encode($manifest['specification'] ?? null)
            || CanonicalJson::encode($payload['source_revision'] ?? null) !== CanonicalJson::encode($manifest['source_revision'] ?? null)
        ) {
            throw new JmhzOrdinaryEvidenceException('jmhz_ordinary_evidence_hash_mismatch', 'Citlivý ordinary snapshot neodpovídá manifestu.');
        }
        return $payload;
    }

    private function encryptionContext(
        int $supplierId,
        int $revisionId,
        int $employmentId,
        string $fingerprint,
        string $manifestHash,
    ): string {
        return "payroll:jmhz-ordinary:{$supplierId}:{$revisionId}:{$employmentId}:{$fingerprint}:{$manifestHash}";
    }

    /**
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function publicResult(array $stored, array $payload, bool $created): array
    {
        return [
            'id' => $stored['id'],
            'run_id' => $stored['run_id'],
            'revision_id' => $stored['source_revision_id'],
            'revision_no' => $payload['scope']['revision_no'],
            'employee_id' => $stored['employee_id'],
            'employment_id' => $stored['employment_id'],
            'period_start' => $stored['period_start'],
            'schema_reference' => $stored['schema_reference'],
            'source_manifest_sha256' => $stored['source_manifest_sha256'],
            'facts' => [
                'reportable_wage_deductions_recorded' =>
                    $payload['attribute_values']['10116'] ?? null,
                'employee_social_discount_claimed' =>
                    $payload['attribute_values']['10546'] ?? null,
                'specific_legal_fact_occurred' =>
                    $payload['interaction_decisions'][0]['triggered'] ?? null,
                'ozp_employment_support_claimed' =>
                    $payload['interaction_decisions'][1]['triggered'] ?? null,
                'deep_mining_work_occurred' =>
                    $payload['interaction_decisions'][2]['triggered'] ?? null,
            ],
            'source_kind' => $payload['confirmation']['source_kind'],
            'confirmed_at' => $payload['confirmation']['confirmed_at'],
            'created_at' => $stored['created_at'],
            'created' => $created,
        ];
    }
}
