<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\JmhzPreparationSnapshotRepository;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotException;

final readonly class JmhzPreparationSnapshotService
{
    private const LEGACY_MANIFEST_SCHEMA = 'payroll-jmhz-preparation-source-manifest.v1';
    private const PREVIOUS_V2_MANIFEST_SCHEMA = 'payroll-jmhz-preparation-source-manifest.v2';
    private const PREVIOUS_MANIFEST_SCHEMA = 'payroll-jmhz-preparation-source-manifest.v3';
    private const PREVIOUS_V4_MANIFEST_SCHEMA = 'payroll-jmhz-preparation-source-manifest.v4';
    private const PREVIOUS_V5_MANIFEST_SCHEMA = 'payroll-jmhz-preparation-source-manifest.v5';
    private const PREVIOUS_V6_MANIFEST_SCHEMA = 'payroll-jmhz-preparation-source-manifest.v6';
    private const PREVIOUS_V7_MANIFEST_SCHEMA = 'payroll-jmhz-preparation-source-manifest.v7';
    private const PREVIOUS_V8_MANIFEST_SCHEMA = 'payroll-jmhz-preparation-source-manifest.v8';
    private const PREVIOUS_V9_MANIFEST_SCHEMA = 'payroll-jmhz-preparation-source-manifest.v9';
    private const PREVIOUS_V10_MANIFEST_SCHEMA = 'payroll-jmhz-preparation-source-manifest.v10';
    private const CURRENT_MANIFEST_SCHEMA = 'payroll-jmhz-preparation-source-manifest.v11';
    private const LEGACY_REQUEST_SCHEMA = 'payroll-jmhz-preparation-request.v1';
    private const PREVIOUS_V2_REQUEST_SCHEMA = 'payroll-jmhz-preparation-request.v2';
    private const PREVIOUS_REQUEST_SCHEMA = 'payroll-jmhz-preparation-request.v3';
    private const PREVIOUS_V4_REQUEST_SCHEMA = 'payroll-jmhz-preparation-request.v4';
    private const PREVIOUS_V5_REQUEST_SCHEMA = 'payroll-jmhz-preparation-request.v5';
    private const PREVIOUS_V6_REQUEST_SCHEMA = 'payroll-jmhz-preparation-request.v6';
    private const PREVIOUS_V7_REQUEST_SCHEMA = 'payroll-jmhz-preparation-request.v7';
    private const PREVIOUS_V8_REQUEST_SCHEMA = 'payroll-jmhz-preparation-request.v8';
    private const PREVIOUS_V9_REQUEST_SCHEMA = 'payroll-jmhz-preparation-request.v9';
    private const PREVIOUS_V10_REQUEST_SCHEMA = 'payroll-jmhz-preparation-request.v10';
    private const CURRENT_REQUEST_SCHEMA = 'payroll-jmhz-preparation-request.v11';

    public function __construct(
        private JmhzPreparationSnapshotRepository $repository,
        private JmhzPreparationSnapshotBuilder $builder,
        private PayrollRegistrationIdentityService $identities,
        private PayrollComponentJmhzMappingRepository $mappings,
        private PayrollSensitiveData $sensitiveData,
        private SecretEncryption $encryption,
        private JmhzEldpEvidenceSnapshotService $eldpEvidence,
        private JmhzOrdinaryEvidenceService $ordinaryEvidence,
        private JmhzAnnualEvidenceService $annualEvidence,
        private JmhzEmployerAnnualEvidenceService $employerAnnualEvidence,
    ) {}

    public function loadVerified(
        int $supplierId,
        string $environment,
        int $preparationId,
    ): JmhzVerifiedPreparationSnapshot {
        if ($supplierId <= 0 || $preparationId <= 0
            || !in_array($environment, ['production', 'test'], true)
        ) {
            throw new JmhzPreparationSnapshotException(
                'jmhz_preparation_not_found',
                'Příprava JMHZ nebyla nalezena.',
            );
        }
        return $this->repository->transaction(function () use (
            $supplierId,
            $environment,
            $preparationId,
        ): JmhzVerifiedPreparationSnapshot {
            $stored = $this->repository->find(
                $supplierId,
                $environment,
                $preparationId,
            );
            if ($stored === null) {
                throw new JmhzPreparationSnapshotException(
                    'jmhz_preparation_not_found',
                    'Příprava JMHZ nebyla nalezena.',
                );
            }
            $verified = $this->verifyStored($stored);
            $source = $this->repository->lockSource(
                $supplierId,
                $verified->sourceRevisionId,
            );
            $revision = is_array($source) ? ($source['revision'] ?? null) : null;
            $sourceRevision = $verified->payload['source_revision'] ?? null;
            if (!is_array($revision) || array_is_list($revision)
                || !is_array($sourceRevision) || array_is_list($sourceRevision)
                || ($revision['run_id'] ?? null) !== $verified->runId
                || ($revision['revision_no'] ?? null) !== $verified->revisionNo
                || ($revision['current_revision_no'] ?? null) !== $verified->revisionNo
                || ($revision['status'] ?? null) !== 'approved'
                || ($revision['input_snapshot_hash'] ?? null)
                    !== ($sourceRevision['input_snapshot_hash'] ?? null)
                || ($revision['result_snapshot_hash'] ?? null)
                    !== ($sourceRevision['result_snapshot_hash'] ?? null)
                || ($revision['ruleset_manifest_hash'] ?? null)
                    !== ($sourceRevision['ruleset_manifest_hash'] ?? null)
            ) {
                throw new JmhzPreparationSnapshotException(
                    'jmhz_preparation_source_not_current',
                    'Příprava JMHZ již neodpovídá aktuální schválené revizi.',
                );
            }
            return $verified;
        });
    }

    /** @return array<string,mixed> */
    public function freeze(
        int $supplierId,
        int $sourceRevisionId,
        string $environment,
        string $idempotencyKey,
        ?int $createdBy,
    ): array {
        if ($supplierId <= 0 || $sourceRevisionId <= 0) {
            throw new \InvalidArgumentException('Firma a zdrojova revize musi byt kladna cisla.');
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new JmhzPreparationSnapshotException(
                'jmhz_preparation_environment_invalid',
                'Prostredi pripravy JMHZ neni platne.',
            );
        }
        if ($createdBy !== null && $createdBy <= 0) {
            throw new \InvalidArgumentException('Uzivatel pripravy musi byt kladne cislo.');
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 190) {
            throw new \InvalidArgumentException('Idempotency klic musi mit 1 az 190 bajtu.');
        }
        $idempotencyHash = hash('sha256', $idempotencyKey, true);

        return $this->repository->transaction(function () use (
            $supplierId,
            $sourceRevisionId,
            $environment,
            $idempotencyHash,
            $createdBy,
        ): array {
            $source = $this->repository->lockSource($supplierId, $sourceRevisionId);
            if ($source === null) {
                throw new JmhzPreparationSnapshotException(
                    'jmhz_revision_not_found',
                    'Zdrojova mzdova revize nebyla nalezena.',
                );
            }
            $inserted = $this->repository->insertIdempotencyClaim(
                $supplierId,
                $environment,
                $idempotencyHash,
                $sourceRevisionId,
                $createdBy,
            );
            if (!$inserted) {
                $claim = $this->repository->findIdempotencyClaimForUpdate(
                    $supplierId,
                    $environment,
                    $idempotencyHash,
                );
                if ($claim === null) {
                    throw new JmhzPreparationSnapshotException(
                        'jmhz_preparation_idempotency_incomplete',
                        'Idempotentni vazbu pripravy JMHZ nelze nacist.',
                    );
                }
                if ($claim['source_revision_id'] !== $sourceRevisionId) {
                    throw new JmhzPreparationSnapshotException(
                        'jmhz_preparation_idempotency_scope_mismatch',
                        'Idempotentni opakovani neodpovida puvodni zdrojove revizi.',
                    );
                }
                $preparationId = $claim['preparation_snapshot_id'] ?? null;
                if (!is_int($preparationId)) {
                    throw new JmhzPreparationSnapshotException(
                        'jmhz_preparation_idempotency_incomplete',
                        'Idempotentni vazba pripravy JMHZ neni dokoncena.',
                    );
                }
                $idempotent = $this->repository->find(
                    $supplierId,
                    $environment,
                    $preparationId,
                );
                if ($idempotent === null) {
                    throw new JmhzPreparationSnapshotException(
                        'jmhz_preparation_idempotency_incomplete',
                        'Idempotentni vazba pripravy JMHZ nema cilovy snapshot.',
                    );
                }
                $this->verifyStored($idempotent);
                return $this->result($idempotent, false);
            }

            JmhzScenarioRequirementSourceCatalog::load();
            JmhzControlSourceCatalog::load();
            (new JmhzSpecPackageCatalog())->load(
                JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
            );
            $revision = $source['revision'];
            if (!is_array($revision)) {
                throw new \UnexpectedValueException('Zdroj revize neni objekt.');
            }
            $inputJson = $revision['input_snapshot_json'] ?? null;
            if (!is_string($inputJson)) {
                throw new JmhzPreparationSnapshotException('jmhz_snapshot_missing', 'Vstupni snapshot revize chybi.');
            }
            $input = json_decode($inputJson, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($input) || array_is_list($input)) {
                throw new JmhzPreparationSnapshotException('jmhz_snapshot_invalid', 'Vstupni snapshot revize neni objekt.');
            }
            $periodEnd = (new \DateTimeImmutable((string) ($revision['period_start'] ?? '')))
                ->modify('last day of this month')
                ->format('Y-m-d');
            [$identitySources, $mappingSources, $sourceIssues, $eldpSources] = $this->supplements(
                $supplierId,
                $environment,
                $sourceRevisionId,
                $periodEnd,
                $input,
                $createdBy,
            );
            $ordinaryEvidence = $this->ordinaryEvidence->snapshotsForPreparation(
                $supplierId,
                $sourceRevisionId,
                $createdBy,
            );
            $sourceIssues = [
                ...$sourceIssues,
                ...$ordinaryEvidence['issues'],
            ];
            $annualEvidence = $this->annualEvidence->snapshotsForPreparation(
                $supplierId,
                $this->employeeIds($input),
                (int) substr((string) $revision['period_start'], 0, 4),
            );
            $employerAnnualEvidence = $this->employerAnnualEvidence
                ->snapshotForPreparation(
                    $supplierId,
                    (string) $revision['period_start'],
                );
            $snapshot = $this->builder->build(
                $supplierId,
                $environment,
                $source,
                $identitySources,
                $mappingSources,
                $sourceIssues,
                $eldpSources,
                $ordinaryEvidence['sources'],
                $annualEvidence,
                $employerAnnualEvidence,
            );
            $snapshotJson = $snapshot->canonicalJson();
            $snapshotFingerprint = $this->sensitiveData->keyedFingerprint(
                $snapshotJson,
                'jmhz-preparation-snapshot',
                $supplierId,
            );
            $readinessJson = CanonicalJson::encode($snapshot->readiness());
            $readinessHash = hash('sha256', $readinessJson);
            $scope = $snapshot->payload['scope'];
            if (!is_array($scope) || array_is_list($scope)) {
                throw new \UnexpectedValueException('Scope pripravy JMHZ neni objekt.');
            }
            $sourceManifestJson = CanonicalJson::encode([
                'schema_reference' => self::CURRENT_MANIFEST_SCHEMA,
                'builder_version' => JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
                'scope' => $scope,
                'specification' => $snapshot->payload['specification'],
                'source_revision' => $snapshot->payload['source_revision'],
                'source_versions' => $snapshot->payload['source_versions'],
                'snapshot_fingerprint' => $snapshotFingerprint,
                'readiness_sha256' => $readinessHash,
            ]);
            $sourceManifestHash = hash('sha256', $sourceManifestJson);
            $requestFingerprint = hash('sha256', CanonicalJson::encode([
                'schema_reference' => self::CURRENT_REQUEST_SCHEMA,
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'source_revision_id' => $sourceRevisionId,
                'source_manifest_sha256' => $sourceManifestHash,
            ]));
            $existing = $this->repository->findByRequestForUpdate(
                $supplierId,
                $environment,
                $requestFingerprint,
            );
            if ($existing !== null) {
                $this->verifyStored($existing);
                $this->repository->bindIdempotencyClaim(
                    $supplierId,
                    $environment,
                    $idempotencyHash,
                    (int) $existing['id'],
                );
                return $this->result($existing, false);
            }
            $ciphertext = $this->encryption->encryptFor(
                $snapshotJson,
                $this->encryptionContext(
                    $supplierId,
                    $environment,
                    $sourceRevisionId,
                    $snapshotFingerprint,
                    $sourceManifestHash,
                    $readinessHash,
                ),
            );
            $readiness = $snapshot->readiness();
            $id = $this->repository->insert([
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'run_id' => $scope['run_id'],
                'source_revision_id' => $sourceRevisionId,
                'period_start' => $scope['period_start'],
                'scenario_key' => 'mixed',
                'scenario_set_json' => CanonicalJson::encode($scope['scenario_set'] ?? []),
                'builder_version' => JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
                'readiness_status' => $readiness['status'],
                'issue_count' => $readiness['issue_count'],
                'source_manifest_json' => $sourceManifestJson,
                'source_manifest_sha256' => $sourceManifestHash,
                'readiness_json' => $readinessJson,
                'readiness_sha256' => $readinessHash,
                'snapshot_ciphertext' => $ciphertext,
                'snapshot_fingerprint' => $snapshotFingerprint,
                'request_fingerprint' => $requestFingerprint,
                'idempotency_key_hash' => $idempotencyHash,
                'created_by' => $createdBy,
            ]);
            $stored = $this->repository->find($supplierId, $environment, $id);
            if ($stored === null) {
                throw new \RuntimeException('Ulozenou pripravu JMHZ nelze nacist.');
            }
            $this->repository->bindIdempotencyClaim(
                $supplierId,
                $environment,
                $idempotencyHash,
                $id,
            );
            $this->verifyStored($stored);
            return $this->result($stored, true);
        });
    }

    /**
     * @param array<string,mixed> $input
     * @return array{array<int,array<string,mixed>>,array<int,array<string,mixed>>,list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}>,array<int,array<string,mixed>>}
     */
    private function supplements(
        int $supplierId,
        string $environment,
        int $sourceRevisionId,
        string $periodEnd,
        array $input,
        ?int $createdBy,
    ): array
    {
        $identities = [];
        $mappings = [];
        $issues = [];
        $eldpSources = [];
        $people = $input['people'] ?? [];
        if (!is_array($people) || !array_is_list($people)) {
            return [$identities, $mappings, $issues, $eldpSources];
        }
        foreach ($people as $person) {
            if (!is_array($person) || array_is_list($person)) {
                continue;
            }
            $employee = $person['employee'] ?? null;
            $employeeId = is_array($employee) && is_int($employee['id'] ?? null)
                ? $employee['id']
                : 0;
            $employments = $person['employments'] ?? [];
            if (!is_array($employments) || !array_is_list($employments)) {
                continue;
            }
            foreach ($employments as $entry) {
                if (!is_array($entry) || array_is_list($entry)) {
                    continue;
                }
                $employment = $entry['employment'] ?? null;
                $employmentId = is_array($employment) && is_int($employment['id'] ?? null)
                    ? $employment['id']
                    : 0;
                if ($employeeId > 0 && $employmentId > 0) {
                    $eldpState = $this->eldpEvidence->ensureForPreparation(
                        $supplierId,
                        $environment,
                        $sourceRevisionId,
                        $employmentId,
                        $createdBy,
                    );
                    $eldp = $eldpState['snapshot'];
                    if ($eldp !== null) {
                        $eldpSources[$employmentId] = $eldp;
                    }
                    if (is_string($eldpState['issue_code'])) {
                        $issues[] = [
                            'code' => $eldpState['issue_code'],
                            'entity_type' => 'employment',
                            'entity_id' => $employmentId,
                            'attribute_ids' => [
                                '10240', '10241', '10242', '10245', '10354',
                                '10355', '10356', '10357', '10358', '10359',
                                '10360', '10362', '10536', '10366', '10375',
                                '10462', '10463', '10464', '10465', '10466',
                                '10468', '10469', '10473', '10474', '10475',
                            ],
                        ];
                    }
                    try {
                        $identity = $this->identities->sensitiveSnapshotSourceAt(
                            $supplierId,
                            $employeeId,
                            $employmentId,
                            $environment,
                            $periodEnd,
                        );
                        $jmhz = $this->identities->sensitiveJmhzIdentityAt(
                            $supplierId,
                            $employeeId,
                            $employmentId,
                            $environment,
                            $periodEnd,
                        );
                        $identities[$employmentId] = $identity + [
                            'jmhz_environment' => $jmhz['environment'],
                            'person_external_identifier' => $jmhz['person_external_identifier'],
                            'jmhz_employment_external_identifier' => $jmhz['employment_external_identifier'],
                        ];
                    } catch (\DomainException $exception) {
                        $issues[] = [
                            'code' => $exception instanceof PayrollRegistrationIdentitySnapshotException
                                ? $exception->validationCode
                                : 'jmhz_identity_incomplete',
                            'entity_type' => 'employment',
                            'entity_id' => $employmentId,
                            'attribute_ids' => ['10051', '10228'],
                        ];
                    }
                }
                $inputRows = $entry['inputs'] ?? [];
                if (!is_array($inputRows) || !array_is_list($inputRows)) {
                    continue;
                }
                foreach ($inputRows as $inputRow) {
                    $component = is_array($inputRow) ? ($inputRow['component'] ?? null) : null;
                    if (!is_array($component)
                        || ($component['jmhz_treatment'] ?? null) !== 'included'
                        || !is_int($component['component_id'] ?? null)
                    ) {
                        continue;
                    }
                    $componentId = $component['component_id'];
                    try {
                        $mappings[$componentId] = $this->mappings->snapshot($supplierId, $componentId);
                    } catch (\DomainException) {
                    }
                }
            }
        }
        ksort($identities, SORT_NUMERIC);
        ksort($mappings, SORT_NUMERIC);
        ksort($eldpSources, SORT_NUMERIC);
        return [$identities, $mappings, $issues, $eldpSources];
    }

    /** @param array<string,mixed> $stored */
    private function verifyStored(array $stored): JmhzVerifiedPreparationSnapshot
    {
        foreach ([
            'source_manifest_json' => 'source_manifest_sha256',
            'readiness_json' => 'readiness_sha256',
        ] as $jsonField => $hashField) {
            $json = $stored[$jsonField] ?? null;
            $hash = $stored[$hashField] ?? null;
            if (!is_string($json) || !is_string($hash)
                || !hash_equals($hash, hash('sha256', $json))
            ) {
                throw new JmhzPreparationSnapshotException('jmhz_preparation_hash_mismatch', 'Otisk ulozene pripravy JMHZ nesouhlasi.');
            }
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || CanonicalJson::encode($decoded) !== $json) {
                throw new JmhzPreparationSnapshotException('jmhz_preparation_hash_mismatch', 'Ulozena priprava JMHZ neni kanonicka.');
            }
        }
        $manifest = json_decode(
            (string) $stored['source_manifest_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $readiness = json_decode(
            (string) $stored['readiness_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (!is_array($manifest) || array_is_list($manifest)
            || !is_array($readiness) || array_is_list($readiness)
        ) {
            throw new JmhzPreparationSnapshotException(
                'jmhz_preparation_hash_mismatch',
                'Metadata pripravy JMHZ nemaji platny tvar.',
            );
        }
        $scope = $manifest['scope'] ?? null;
        $contracts = $this->contracts((string) ($stored['builder_version'] ?? ''));
        $scenarioScopeMatches = false;
        if (is_array($scope) && !array_is_list($scope)) {
            $scenarioScopeMatches = ($scope['scenario_key'] ?? null) === ($stored['scenario_key'] ?? null);
        }
        if (is_array($scope) && !array_is_list($scope)
            && ($stored['builder_version'] ?? null) === JmhzPreparationSnapshotBuilder::BUILDER_VERSION
        ) {
            $storedScenarioSet = $stored['scenario_set_json'] ?? null;
            if (!is_string($storedScenarioSet)) {
                $scenarioScopeMatches = false;
            } else {
                $decodedScenarioSet = json_decode($storedScenarioSet, true);
                $scenarioScopeMatches = ($stored['scenario_key'] ?? null) === 'mixed'
                    && is_array($decodedScenarioSet)
                    && array_is_list($decodedScenarioSet)
                    && CanonicalJson::encode($decodedScenarioSet)
                        === CanonicalJson::encode($scope['scenario_set'] ?? null);
            }
        }
        if (!is_array($scope) || array_is_list($scope)
            || ($manifest['schema_reference'] ?? null)
                !== $contracts['manifest_schema']
            || ($manifest['builder_version'] ?? null)
                !== ($stored['builder_version'] ?? null)
            || ($manifest['snapshot_fingerprint'] ?? null)
                !== ($stored['snapshot_fingerprint'] ?? null)
            || ($manifest['readiness_sha256'] ?? null)
                !== ($stored['readiness_sha256'] ?? null)
            || ($scope['supplier_id'] ?? null) !== ($stored['supplier_id'] ?? null)
            || ($scope['environment'] ?? null) !== ($stored['environment'] ?? null)
            || ($scope['run_id'] ?? null) !== ($stored['run_id'] ?? null)
            || ($scope['source_revision_id'] ?? null)
                !== ($stored['source_revision_id'] ?? null)
            || ($scope['period_start'] ?? null) !== ($stored['period_start'] ?? null)
            || !$scenarioScopeMatches
            || ($readiness['status'] ?? null) !== ($stored['readiness_status'] ?? null)
            || ($readiness['issue_count'] ?? null) !== ($stored['issue_count'] ?? null)
            || ($readiness['official_submission_supported'] ?? null) !== false
        ) {
            throw new JmhzPreparationSnapshotException(
                'jmhz_preparation_hash_mismatch',
                'Manifest pripravy JMHZ neodpovida archivnim metadatum.',
            );
        }
        $plaintext = $this->encryption->decryptFor(
            (string) $stored['snapshot_ciphertext'],
            $this->encryptionContext(
                (int) $stored['supplier_id'],
                (string) $stored['environment'],
                (int) $stored['source_revision_id'],
                (string) $stored['snapshot_fingerprint'],
                (string) $stored['source_manifest_sha256'],
                (string) $stored['readiness_sha256'],
            ),
        );
        $fingerprint = $this->sensitiveData->keyedFingerprint(
            $plaintext,
            'jmhz-preparation-snapshot',
            (int) $stored['supplier_id'],
        );
        if (!hash_equals((string) $stored['snapshot_fingerprint'], $fingerprint)) {
            throw new JmhzPreparationSnapshotException('jmhz_preparation_hash_mismatch', 'Citlivy snapshot pripravy JMHZ ma jiny otisk.');
        }
        $snapshot = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || CanonicalJson::encode($snapshot) !== $plaintext) {
            throw new JmhzPreparationSnapshotException('jmhz_preparation_hash_mismatch', 'Citlivy snapshot pripravy JMHZ neni kanonicky.');
        }
        $snapshotScope = $snapshot['scope'] ?? null;
        $snapshotIssues = $snapshot['readiness_issues'] ?? null;
        if (!is_array($snapshotScope) || array_is_list($snapshotScope)
            || !is_array($snapshotIssues) || !array_is_list($snapshotIssues)
            || ($snapshot['schema_reference'] ?? null)
                !== $contracts['snapshot_schema']
            || ($snapshot['builder_version'] ?? null)
                !== ($stored['builder_version'] ?? null)
            || CanonicalJson::encode($snapshotScope) !== CanonicalJson::encode($scope)
            || CanonicalJson::encode($snapshot['specification'] ?? null)
                !== CanonicalJson::encode($manifest['specification'] ?? null)
            || CanonicalJson::encode($snapshot['source_revision'] ?? null)
                !== CanonicalJson::encode($manifest['source_revision'] ?? null)
            || CanonicalJson::encode($snapshot['source_versions'] ?? null)
                !== CanonicalJson::encode($manifest['source_versions'] ?? null)
        ) {
            throw new JmhzPreparationSnapshotException(
                'jmhz_preparation_hash_mismatch',
                'Citlivy snapshot pripravy JMHZ neodpovida manifestu.',
            );
        }
        $normalizedIssues = [];
        foreach ($snapshotIssues as $issue) {
            if (!is_array($issue) || array_is_list($issue)
                || !is_string($issue['code'] ?? null)
                || !is_string($issue['entity_type'] ?? null)
                || (!is_int($issue['entity_id'] ?? null)
                    && ($issue['entity_id'] ?? null) !== null)
                || !is_array($issue['attribute_ids'] ?? null)
                || !array_is_list($issue['attribute_ids'])
            ) {
                throw new JmhzPreparationSnapshotException(
                    'jmhz_preparation_hash_mismatch',
                    'Citlivy snapshot pripravy JMHZ ma neplatny blocker.',
                );
            }
            $normalizedIssues[] = [
                'code' => $issue['code'],
                'entity_type' => $issue['entity_type'],
                'entity_id' => $issue['entity_id'],
                'attribute_ids' => $issue['attribute_ids'],
            ];
        }
        $expectedReadiness = (new JmhzPreparationSnapshot(
            $snapshot,
            $normalizedIssues,
        ))->readiness();
        if (CanonicalJson::encode($expectedReadiness)
            !== (string) $stored['readiness_json']
        ) {
            throw new JmhzPreparationSnapshotException(
                'jmhz_preparation_hash_mismatch',
                'Readiness pripravy JMHZ neodpovida citlivemu snapshotu.',
            );
        }
        $expectedRequest = hash('sha256', CanonicalJson::encode([
            'schema_reference' => $contracts['request_schema'],
            'supplier_id' => $stored['supplier_id'],
            'environment' => $stored['environment'],
            'source_revision_id' => $stored['source_revision_id'],
            'source_manifest_sha256' => $stored['source_manifest_sha256'],
        ]));
        if (!hash_equals((string) $stored['request_fingerprint'], $expectedRequest)) {
            throw new JmhzPreparationSnapshotException(
                'jmhz_preparation_hash_mismatch',
                'Request fingerprint pripravy JMHZ nesouhlasi.',
            );
        }
        return new JmhzVerifiedPreparationSnapshot(
            (int) $stored['id'],
            (int) $stored['supplier_id'],
            (string) $stored['environment'],
            (int) $stored['run_id'],
            (int) $stored['source_revision_id'],
            (int) ($scope['revision_no'] ?? 0),
            (string) $stored['period_start'],
            (string) ($scope['period_end'] ?? ''),
            (string) $stored['scenario_key'],
            (string) $stored['builder_version'],
            (string) $stored['source_manifest_sha256'],
            (string) $stored['readiness_sha256'],
            (string) $stored['snapshot_fingerprint'],
            $manifest,
            $readiness,
            $snapshot,
        );
    }

    /** @return array{snapshot_schema:string,manifest_schema:string,request_schema:string} */
    private function contracts(string $builderVersion): array
    {
        return match ($builderVersion) {
            JmhzPreparationSnapshotBuilder::LEGACY_BUILDER_VERSION => [
                'snapshot_schema' => JmhzPreparationSnapshot::LEGACY_SCHEMA_REFERENCE,
                'manifest_schema' => self::LEGACY_MANIFEST_SCHEMA,
                'request_schema' => self::LEGACY_REQUEST_SCHEMA,
            ],
            JmhzPreparationSnapshotBuilder::PREVIOUS_V2_BUILDER_VERSION => [
                'snapshot_schema' => JmhzPreparationSnapshot::PREVIOUS_V2_SCHEMA_REFERENCE,
                'manifest_schema' => self::PREVIOUS_V2_MANIFEST_SCHEMA,
                'request_schema' => self::PREVIOUS_V2_REQUEST_SCHEMA,
            ],
            JmhzPreparationSnapshotBuilder::PREVIOUS_BUILDER_VERSION => [
                'snapshot_schema' => JmhzPreparationSnapshot::PREVIOUS_SCHEMA_REFERENCE,
                'manifest_schema' => self::PREVIOUS_MANIFEST_SCHEMA,
                'request_schema' => self::PREVIOUS_REQUEST_SCHEMA,
            ],
            JmhzPreparationSnapshotBuilder::PREVIOUS_V4_BUILDER_VERSION => [
                'snapshot_schema' => JmhzPreparationSnapshot::PREVIOUS_V4_SCHEMA_REFERENCE,
                'manifest_schema' => self::PREVIOUS_V4_MANIFEST_SCHEMA,
                'request_schema' => self::PREVIOUS_V4_REQUEST_SCHEMA,
            ],
            JmhzPreparationSnapshotBuilder::PREVIOUS_V5_BUILDER_VERSION => [
                'snapshot_schema' => JmhzPreparationSnapshot::PREVIOUS_V5_SCHEMA_REFERENCE,
                'manifest_schema' => self::PREVIOUS_V5_MANIFEST_SCHEMA,
                'request_schema' => self::PREVIOUS_V5_REQUEST_SCHEMA,
            ],
            JmhzPreparationSnapshotBuilder::PREVIOUS_V6_BUILDER_VERSION => [
                'snapshot_schema' => JmhzPreparationSnapshot::PREVIOUS_V6_SCHEMA_REFERENCE,
                'manifest_schema' => self::PREVIOUS_V6_MANIFEST_SCHEMA,
                'request_schema' => self::PREVIOUS_V6_REQUEST_SCHEMA,
            ],
            JmhzPreparationSnapshotBuilder::PREVIOUS_V7_BUILDER_VERSION => [
                'snapshot_schema' => JmhzPreparationSnapshot::PREVIOUS_V7_SCHEMA_REFERENCE,
                'manifest_schema' => self::PREVIOUS_V7_MANIFEST_SCHEMA,
                'request_schema' => self::PREVIOUS_V7_REQUEST_SCHEMA,
            ],
            JmhzPreparationSnapshotBuilder::PREVIOUS_V8_BUILDER_VERSION => [
                'snapshot_schema' => JmhzPreparationSnapshot::PREVIOUS_V8_SCHEMA_REFERENCE,
                'manifest_schema' => self::PREVIOUS_V8_MANIFEST_SCHEMA,
                'request_schema' => self::PREVIOUS_V8_REQUEST_SCHEMA,
            ],
            JmhzPreparationSnapshotBuilder::PREVIOUS_V9_BUILDER_VERSION => [
                'snapshot_schema' => JmhzPreparationSnapshot::PREVIOUS_V9_SCHEMA_REFERENCE,
                'manifest_schema' => self::PREVIOUS_V9_MANIFEST_SCHEMA,
                'request_schema' => self::PREVIOUS_V9_REQUEST_SCHEMA,
            ],
            JmhzPreparationSnapshotBuilder::PREVIOUS_V10_BUILDER_VERSION => [
                'snapshot_schema' => JmhzPreparationSnapshot::PREVIOUS_V10_SCHEMA_REFERENCE,
                'manifest_schema' => self::PREVIOUS_V10_MANIFEST_SCHEMA,
                'request_schema' => self::PREVIOUS_V10_REQUEST_SCHEMA,
            ],
            JmhzPreparationSnapshotBuilder::BUILDER_VERSION => [
                'snapshot_schema' => JmhzPreparationSnapshot::CURRENT_SCHEMA_REFERENCE,
                'manifest_schema' => self::CURRENT_MANIFEST_SCHEMA,
                'request_schema' => self::CURRENT_REQUEST_SCHEMA,
            ],
            default => throw new JmhzPreparationSnapshotException(
                'jmhz_preparation_version_unsupported',
                'Verze uložené přípravy JMHZ není podporovaná.',
            ),
        };
    }

    /** @param array<string,mixed> $input
     *  @return list<int>
     */
    private function employeeIds(array $input): array
    {
        $ids = [];
        $people = $input['people'] ?? null;
        if (!is_array($people) || !array_is_list($people)) {
            throw new JmhzPreparationSnapshotException(
                'jmhz_snapshot_invalid',
                'Vstupní snapshot revize nemá platný seznam osob.',
            );
        }
        foreach ($people as $person) {
            $employee = is_array($person) ? ($person['employee'] ?? null) : null;
            $employeeId = is_array($employee) ? ($employee['id'] ?? null) : null;
            if (!is_int($employeeId) || $employeeId <= 0) {
                throw new JmhzPreparationSnapshotException(
                    'jmhz_snapshot_invalid',
                    'Vstupní snapshot revize nemá platnou identitu osoby.',
                );
            }
            $ids[] = $employeeId;
        }

        return array_values(array_unique($ids));
    }

    private function encryptionContext(int $supplierId, string $environment, int $revisionId, string $snapshotFingerprint, string $manifestHash, string $readinessHash): string
    {
        return "payroll:jmhz-preparation:{$supplierId}:{$environment}:{$revisionId}:{$snapshotFingerprint}:{$manifestHash}:{$readinessHash}";
    }

    /**
     * @param array<string,mixed> $stored
     * @return array<string,mixed>
     */
    private function result(array $stored, bool $created): array
    {
        $readiness = json_decode(
            (string) $stored['readiness_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (!is_array($readiness) || array_is_list($readiness)) {
            throw new \UnexpectedValueException('Readiness pripravy JMHZ neni objekt.');
        }
        return [
            'id' => $stored['id'],
            'supplier_id' => $stored['supplier_id'],
            'environment' => $stored['environment'],
            'run_id' => $stored['run_id'],
            'source_revision_id' => $stored['source_revision_id'],
            'period_start' => $stored['period_start'],
            'scenario_key' => $stored['scenario_key'],
            'builder_version' => $stored['builder_version'],
            'readiness_status' => $stored['readiness_status'],
            'issue_count' => $stored['issue_count'],
            'issues' => $readiness['issues'] ?? [],
            'source_manifest_sha256' => $stored['source_manifest_sha256'],
            'readiness_sha256' => $stored['readiness_sha256'],
            'snapshot_fingerprint' => $stored['snapshot_fingerprint'],
            'official_submission_supported' => false,
            'created' => $created,
        ];
    }
}
