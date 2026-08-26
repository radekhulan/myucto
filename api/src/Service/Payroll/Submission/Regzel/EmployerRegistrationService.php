<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Regzel;

use MyInvoice\Repository\Payroll\PayrollRegzelRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final readonly class EmployerRegistrationService
{
    public function __construct(
        private PayrollRegzelRepository $repository,
        private RegzelPayloadSnapshotBuilder $snapshots,
        private RegzelXmlGenerator $generator,
        private RegzelXmlValidator $validator,
        private SecretEncryption $encryption,
    ) {}

    /**
     * @return array{
     *   supplier_id:int,social_enterprise:bool,employment_agency:bool,
     *   protected_labor_market:bool,tax_office_code:?string,
     *   tax_office_workplace_code:?string,payer_reference_number:?string,
     *   is_complete:bool,evidence_confirmed_at:string,
     *   row_version:int,updated_at:string
     * }|null
     */
    public function profile(int $supplierId): ?array
    {
        $profile = $this->repository->profile($supplierId);
        return $profile === null ? null : $this->publicProfile($profile);
    }

    public function taxOfficeWorkplaceSuggestion(int $supplierId): ?string
    {
        return RegzelTaxOfficeCode::suggestion(
            $this->repository->taxOfficeWorkplaceSuggestion($supplierId),
        );
    }

    /**
     * @return array{
     *   supplier_id:int,social_enterprise:bool,employment_agency:bool,
     *   protected_labor_market:bool,tax_office_code:string,
     *   tax_office_workplace_code:?string,payer_reference_number:?string,
     *   is_complete:bool,evidence_confirmed_at:string,
     *   row_version:int,updated_at:string
     * }
     */
    public function saveProfile(
        int $supplierId,
        int $userId,
        int $expectedVersion,
        bool $socialEnterprise,
        bool $employmentAgency,
        bool $protectedLaborMarket,
        mixed $taxOfficeCode,
        mixed $taxOfficeWorkplaceCode,
        mixed $payerReferenceNumber,
        bool $evidenceConfirmed,
    ): array {
        if (!$evidenceConfirmed) {
            throw new RegzelValidationException(
                'regzel_evidence_confirmation_required',
                'Před uložením musíš potvrdit správnost evidovaných údajů REGZEL.',
            );
        }
        if ($supplierId <= 0 || $userId <= 0 || $expectedVersion < 0) {
            throw new \InvalidArgumentException('REGZEL profil nemá platný rozsah nebo verzi.');
        }
        $taxOfficeCode = RegzelTaxOfficeCode::required($taxOfficeCode);
        $taxOfficeWorkplaceCode = RegzelTaxOfficeCode::optional(
            $taxOfficeWorkplaceCode,
        );
        RegzelTaxOfficeCode::validatePair(
            $taxOfficeCode,
            $taxOfficeWorkplaceCode,
        );
        $payerReferenceNumber = RegzelPayerReferenceNumber::optional(
            $payerReferenceNumber,
        );

        $profile = $this->repository->transaction(function () use (
            $supplierId,
            $userId,
            $expectedVersion,
            $socialEnterprise,
            $employmentAgency,
            $protectedLaborMarket,
            $taxOfficeCode,
            $taxOfficeWorkplaceCode,
            $payerReferenceNumber,
        ): array {
            if (!$this->repository->lockSupplier($supplierId)) {
                throw new \DomainException('Firma REGZEL nebyla nalezena.');
            }
            return $this->repository->saveProfile(
                $supplierId,
                $socialEnterprise,
                $employmentAgency,
                $protectedLaborMarket,
                $taxOfficeCode,
                $taxOfficeWorkplaceCode,
                $payerReferenceNumber,
                $userId,
                $expectedVersion,
            );
        });

        return $this->publicProfile($profile);
    }

    /**
     * @return array{
     *   id:int,environment:string,office_id:int,document_type:string,
     *   interaction_code:string,mapping_version:string,xsd_version:string,
     *   source_snapshot_hash:string,xml_sha256:string,xml_byte_size:int,
     *   request_fingerprint:string,xml:string,
     *   created:bool
     * }
     */
    public function prepareSupplementalInformation(
        int $supplierId,
        int $officeId,
        string $environment,
        string $idempotencyKey,
        ?int $createdBy,
    ): array {
        if ($createdBy !== null && $createdBy <= 0) {
            throw new \InvalidArgumentException('Uživatel REGZEL není platný.');
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 190) {
            throw new \InvalidArgumentException(
                'Idempotency klíč REGZEL musí mít 1 až 190 bajtů.',
            );
        }

        $idempotencyHash = hash('sha256', $idempotencyKey);

        return $this->repository->transaction(function () use (
            $supplierId,
            $officeId,
            $environment,
            $idempotencyHash,
            $createdBy,
        ): array {
            if (!$this->repository->lockSupplier($supplierId)) {
                throw new \DomainException('Firma REGZEL nebyla nalezena.');
            }
            $snapshot = $this->snapshots->buildSupplementalInformation(
                $supplierId,
                $officeId,
                $environment,
            );
            $snapshotJson = $snapshot->canonicalJson();
            $sourceHash = hash('sha256', $snapshotJson);
            $xml = $this->generator->generate($snapshot);
            $this->validator->validate($snapshot, $xml);
            $xmlHash = hash('sha256', $xml);
            $requestFingerprint = hash(
                'sha256',
                CanonicalJson::encode([
                    'schema_reference' => 'payroll-regzel-prepare.v1',
                    'supplier_id' => $supplierId,
                    'office_id' => $officeId,
                    'environment' => $snapshot->environment,
                    'document_type' => 'REGZELDOPL25',
                    'interaction_code' => $snapshot->interaction,
                    'mapping_version' =>
                        $snapshot->mappingVersion,
                    'xsd_version' => RegzelPayloadSnapshot::XSD_VERSION,
                    'source_snapshot_hash' => $sourceHash,
                    'xml_sha256' => $xmlHash,
                ]),
            );
            $existing = $this->repository->findByIdempotencyForUpdate(
                $supplierId,
                $snapshot->environment,
                $idempotencyHash,
            );
            if ($existing !== null) {
                if (!hash_equals(
                    $existing['request_fingerprint'],
                    $requestFingerprint,
                )) {
                    throw new \DomainException(
                        'Idempotency klíč REGZEL už patří jinému přesnému obsahu.',
                    );
                }

                return $this->result(
                    $existing['id'],
                    $snapshot,
                    $sourceHash,
                    $xml,
                    $xmlHash,
                    $requestFingerprint,
                    false,
                );
            }

            $manifest = CanonicalJson::encode([
                'schema_reference' => 'payroll-regzel-source-manifest.v1',
                'supplier_id' => $supplierId,
                'office_id' => $officeId,
                'environment' => $snapshot->environment,
                'document_type' => 'REGZELDOPL25',
                'interaction_code' => $snapshot->interaction,
                'mapping_version' =>
                    $snapshot->mappingVersion,
                'xsd_version' => RegzelPayloadSnapshot::XSD_VERSION,
                'source_snapshot_hash' => $sourceHash,
                'source_versions' => [
                    'employer_settings' =>
                        $snapshot->employerSettingsRowVersion,
                    'office' => $snapshot->officeRowVersion,
                    'profile' => $snapshot->profileRowVersion,
                    'supplier_updated_at' =>
                        $snapshot->supplierUpdatedAt,
                ],
            ]);
            $ciphertext = $this->encryption->encryptFor(
                $snapshotJson,
                $this->encryptionContext(
                    $supplierId,
                    $snapshot->environment,
                    $sourceHash,
                ),
            );
            $id = $this->repository->insertSnapshot([
                'supplier_id' => $supplierId,
                'environment' => $snapshot->environment,
                'office_id' => $officeId,
                'document_type' => 'REGZELDOPL25',
                'interaction_code' => $snapshot->interaction,
                'mapping_version' =>
                    $snapshot->mappingVersion,
                'xsd_version' => RegzelPayloadSnapshot::XSD_VERSION,
                'source_manifest_json' => $manifest,
                'snapshot_ciphertext' => $ciphertext,
                'source_snapshot_hash' => $sourceHash,
                'xml_sha256' => $xmlHash,
                'xml_byte_size' => strlen($xml),
                'request_fingerprint' => $requestFingerprint,
                'idempotency_key_hash' => $idempotencyHash,
                'created_by' => $createdBy,
            ]);

            return $this->result(
                $id,
                $snapshot,
                $sourceHash,
                $xml,
                $xmlHash,
                $requestFingerprint,
                true,
            );
        });
    }

    /**
     * Stránka snímků i s celkovým počtem. Dřív se vracelo prvních sto bez
     * jakéhokoli náznaku, že jich je víc — a k těm starším se nedalo dostat.
     *
     * @return array{items:list<array{
     *   id:int,environment:string,office_id:int,document_type:string,
     *   interaction_code:string,mapping_version:string,xsd_version:string,
     *   source_snapshot_hash:string,xml_sha256:string,xml_byte_size:int,
     *   created_at:string
     * }>,total:int}
     */
    public function snapshots(
        int $supplierId,
        string $environment,
        int $limit = PayrollRegzelRepository::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        $environment = $this->environment($environment);

        return [
            'items' => array_map(
                fn (array $row): array => $this->publicSnapshot($row),
                $this->repository->listSnapshots(
                    $supplierId,
                    $environment,
                    $limit,
                    $offset,
                ),
            ),
            'total' => $this->repository->countSnapshots($supplierId, $environment),
        ];
    }

    /**
     * @return array{xml:string,filename:string}
     */
    public function snapshotXml(
        int $supplierId,
        int $snapshotId,
        string $environment,
    ): array {
        $environment = $this->environment($environment);
        $row = $this->repository->findSnapshot(
            $supplierId,
            $snapshotId,
            $environment,
        );
        if ($row === null) {
            throw new \OutOfBoundsException('REGZEL snapshot nebyl nalezen.');
        }
        $json = $this->encryption->decryptFor(
            $row['snapshot_ciphertext'],
            $this->encryptionContext(
                $supplierId,
                $environment,
                $row['source_snapshot_hash'],
            ),
        );
        if (!hash_equals($row['source_snapshot_hash'], hash('sha256', $json))) {
            throw new \RuntimeException('REGZEL snapshot má neplatný zdrojový otisk.');
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $snapshot = $this->snapshotFromArray(
            $this->stringKeyedArray($decoded, 'kořen'),
        );
        if ($snapshot->supplierId !== $supplierId
            || $snapshot->officeId !== $row['office_id']
            || $snapshot->environment !== $environment
            || $snapshot->interaction !== $row['interaction_code']
            || $snapshot->mappingVersion !== $row['mapping_version']
            || $row['xsd_version'] !== RegzelPayloadSnapshot::XSD_VERSION
            || $row['document_type'] !== 'REGZELDOPL25'
        ) {
            throw new \RuntimeException('REGZEL snapshot neodpovídá archivním metadatům.');
        }
        $xml = $this->generator->generate($snapshot);
        $this->validator->validate($snapshot, $xml);
        if (!hash_equals($row['xml_sha256'], hash('sha256', $xml))
            || strlen($xml) !== $row['xml_byte_size']
        ) {
            throw new \RuntimeException('REGZEL XML neodpovídá archivnímu otisku.');
        }

        return [
            'xml' => $xml,
            'filename' => sprintf(
                'REGZELDOPL25-%s-%d.xml',
                $environment === 'test' ? 'TEST' : 'PRODUKCE',
                $snapshotId,
            ),
        ];
    }

    /**
     * @return array{
     *   id:int,environment:string,office_id:int,document_type:string,
     *   interaction_code:string,mapping_version:string,xsd_version:string,
     *   source_snapshot_hash:string,xml_sha256:string,xml_byte_size:int,
     *   request_fingerprint:string,xml:string,
     *   created:bool
     * }
     */
    private function result(
        int $id,
        RegzelPayloadSnapshot $snapshot,
        string $sourceHash,
        string $xml,
        string $xmlHash,
        string $requestFingerprint,
        bool $created,
    ): array {
        return [
            'id' => $id,
            'environment' => $snapshot->environment,
            'office_id' => $snapshot->officeId,
            'document_type' => 'REGZELDOPL25',
            'interaction_code' => $snapshot->interaction,
            'mapping_version' =>
                $snapshot->mappingVersion,
            'xsd_version' => RegzelPayloadSnapshot::XSD_VERSION,
            'source_snapshot_hash' => $sourceHash,
            'xml_sha256' => $xmlHash,
            'xml_byte_size' => strlen($xml),
            'request_fingerprint' => $requestFingerprint,
            'xml' => $xml,
            'created' => $created,
        ];
    }

    private function encryptionContext(
        int $supplierId,
        string $environment,
        string $sourceHash,
    ): string {
        return implode('|', [
            'payroll-regzel-snapshot.v1',
            (string) $supplierId,
            $environment,
            'REGZELDOPL25',
            $sourceHash,
        ]);
    }

    private function environment(string $environment): string
    {
        $environment = trim($environment);
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new RegzelValidationException(
                'regzel_environment_invalid',
                'Prostředí REGZEL není platné.',
            );
        }
        return $environment;
    }

    /**
     * @param array{
     *   supplier_id:int,social_enterprise:bool,employment_agency:bool,
     *   protected_labor_market:bool,tax_office_code:?string,
     *   tax_office_workplace_code:?string,payer_reference_number:?string,
     *   evidence_confirmed_by:int,
     *   evidence_confirmed_at:string,row_version:int,updated_at:string
     * } $profile
     * @return array{
     *   supplier_id:int,social_enterprise:bool,employment_agency:bool,
     *   protected_labor_market:bool,tax_office_code:?string,
     *   tax_office_workplace_code:?string,payer_reference_number:?string,
     *   is_complete:bool,evidence_confirmed_at:string,
     *   row_version:int,updated_at:string
     * }
     */
    private function publicProfile(array $profile): array
    {
        return [
            'supplier_id' => $profile['supplier_id'],
            'social_enterprise' => $profile['social_enterprise'],
            'employment_agency' => $profile['employment_agency'],
            'protected_labor_market' => $profile['protected_labor_market'],
            'tax_office_code' => $profile['tax_office_code'],
            'tax_office_workplace_code' =>
                $profile['tax_office_workplace_code'],
            'payer_reference_number' => $profile['payer_reference_number'],
            'is_complete' => $profile['tax_office_code'] !== null
                && (!RegzelTaxOfficeCode::requiresWorkplace(
                    $profile['tax_office_code'],
                ) || $profile['tax_office_workplace_code'] !== null),
            'evidence_confirmed_at' => $profile['evidence_confirmed_at'],
            'row_version' => $profile['row_version'],
            'updated_at' => $profile['updated_at'],
        ];
    }

    /**
     * @param array{
     *   id:int,supplier_id:int,environment:string,office_id:int,
     *   document_type:string,interaction_code:string,mapping_version:string,
     *   xsd_version:string,source_manifest_json:string,
     *   snapshot_ciphertext:string,source_snapshot_hash:string,
     *   xml_sha256:string,xml_byte_size:int,request_fingerprint:string,
     *   idempotency_key_hash:string,created_by:?int,created_at:string
     * } $row
     * @return array{
     *   id:int,environment:string,office_id:int,document_type:string,
     *   interaction_code:string,mapping_version:string,xsd_version:string,
     *   source_snapshot_hash:string,xml_sha256:string,xml_byte_size:int,
     *   created_at:string
     * }
     */
    private function publicSnapshot(array $row): array
    {
        return [
            'id' => $row['id'],
            'environment' => $row['environment'],
            'office_id' => $row['office_id'],
            'document_type' => $row['document_type'],
            'interaction_code' => $row['interaction_code'],
            'mapping_version' => $row['mapping_version'],
            'xsd_version' => $row['xsd_version'],
            'source_snapshot_hash' => $row['source_snapshot_hash'],
            'xml_sha256' => $row['xml_sha256'],
            'xml_byte_size' => $row['xml_byte_size'],
            'created_at' => $row['created_at'],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function snapshotFromArray(array $payload): RegzelPayloadSnapshot
    {
        if ($this->stringValue($payload, 'xsd_version')
            !== RegzelPayloadSnapshot::XSD_VERSION
        ) {
            throw new \RuntimeException(
                'REGZEL snapshot obsahuje nepodporovanou verzi XSD.',
            );
        }
        $header = $this->stringKeyedArray(
            $payload['header'] ?? null,
            'header',
        );
        $employer = $this->stringKeyedArray(
            $payload['employer'] ?? null,
            'employer',
        );
        $information = $this->stringKeyedArray(
            $employer['supplemental_information'] ?? null,
            'employer.supplemental_information',
        );
        $versions = $this->stringKeyedArray(
            $payload['source_versions'] ?? null,
            'source_versions',
        );

        return new RegzelPayloadSnapshot(
            supplierId: $this->intValue($payload, 'supplier_id'),
            officeId: $this->intValue($payload, 'office_id'),
            environment: $this->stringValue($payload, 'environment'),
            interaction: $this->stringValue($payload, 'interaction'),
            csszWorkplaceCode: $this->stringValue($header, 'cssz_workplace_code'),
            taxOfficeCode: $this->stringValue($header, 'tax_office_code'),
            taxOfficeWorkplaceCode:
                $this->nullableStringValue($header, 'tax_office_workplace_code'),
            socialSecurityVariableSymbol:
                $this->stringValue($employer, 'social_security_variable_symbol'),
            payerReferenceNumber:
                $this->nullableStringValue($employer, 'payer_reference_number'),
            notificationDataBoxId:
                $this->nullableStringValue($employer, 'notification_data_box_id'),
            socialEnterprise: $this->boolValue($information, 'social_enterprise'),
            employmentAgency: $this->boolValue($information, 'employment_agency'),
            protectedLaborMarket:
                $this->boolValue($information, 'protected_labor_market'),
            employerSettingsRowVersion:
                $this->intValue($versions, 'employer_settings_row_version'),
            officeRowVersion: $this->intValue($versions, 'office_row_version'),
            profileRowVersion: $this->intValue($versions, 'profile_row_version'),
            supplierUpdatedAt:
                $this->stringValue($versions, 'supplier_updated_at'),
            schemaReference:
                $this->stringValue($payload, 'schema_reference'),
            mappingVersion:
                $this->stringValue($payload, 'mapping_version'),
        );
    }

    /** @return array<string,mixed> */
    private function stringKeyedArray(mixed $value, string $label): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException(
                'REGZEL snapshot neobsahuje objekt ' . $label . '.',
            );
        }
        $normalized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \RuntimeException(
                    'REGZEL snapshot obsahuje neplatný objekt ' . $label . '.',
                );
            }
            $normalized[$key] = $item;
        }

        return $normalized;
    }

    /** @param array<string,mixed> $source */
    private function stringValue(array $source, string $key): string
    {
        if (!array_key_exists($key, $source) || !is_string($source[$key])) {
            throw new \RuntimeException('REGZEL snapshot obsahuje neplatné pole ' . $key . '.');
        }
        return $source[$key];
    }

    /** @param array<string,mixed> $source */
    private function nullableStringValue(array $source, string $key): ?string
    {
        if (!array_key_exists($key, $source)) {
            throw new \RuntimeException('REGZEL snapshot neobsahuje pole ' . $key . '.');
        }
        if ($source[$key] === null) {
            return null;
        }
        if (!is_string($source[$key])) {
            throw new \RuntimeException('REGZEL snapshot obsahuje neplatné pole ' . $key . '.');
        }
        return $source[$key];
    }

    /** @param array<string,mixed> $source */
    private function intValue(array $source, string $key): int
    {
        if (!array_key_exists($key, $source) || !is_int($source[$key])) {
            throw new \RuntimeException('REGZEL snapshot obsahuje neplatné pole ' . $key . '.');
        }
        return $source[$key];
    }

    /** @param array<string,mixed> $source */
    private function boolValue(array $source, string $key): bool
    {
        if (!array_key_exists($key, $source) || !is_bool($source[$key])) {
            throw new \RuntimeException('REGZEL snapshot obsahuje neplatné pole ' . $key . '.');
        }
        return $source[$key];
    }
}
