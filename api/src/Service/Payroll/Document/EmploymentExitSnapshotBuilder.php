<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentExitRevisionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;

final class EmploymentExitSnapshotBuilder
{
    public const SCHEMA_VERSION = 'employment-certificate-snapshot.v1';
    public const MAPPING_VERSION = 'employment-certificate-mapping.v1';
    private const PURPOSE = 'employment_certificate';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentExitRevisionRepository $revisions,
        private readonly PayrollDocumentEmployerSnapshotProvider $employers,
        private readonly EmploymentCertificateEvidenceValidator $validator,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly SecretEncryption $encryption,
    ) {}

    /**
     * @param array<string,mixed> $evidenceInput
     * @return array{
     *   revision:array<string,mixed>,
     *   document:EmploymentCertificateDocumentData
     * }
     */
    public function build(
        int $supplierId,
        int $employmentId,
        array $evidenceInput,
        int $actorUserId,
    ): array {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException(
                'Výstupní snapshot pracovního vztahu vyžaduje aktivní transakci.',
            );
        }
        if ($supplierId <= 0 || $employmentId <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Identita výstupního potvrzení není platná.',
            );
        }

        $evidence = $this->validator->validate($evidenceInput);
        $sources = $this->revisions->lockCertificateSources(
            $supplierId,
            $employmentId,
        );
        $employment = $sources['employment'];
        $employee = $sources['employee'];
        $relationType = EmploymentExitRelationshipPolicy::documentKind(
            self::text($employment, 'relation_type'),
        );
        $this->assertPensionPeriodsWithinEmployment(
            $evidence['pre1993_pension_category_periods'],
            self::text($employment, 'actual_start_date'),
            self::text($employment, 'end_date'),
        );

        $claims = $this->revisions->lockContinuingDeductionClaims(
            $supplierId,
            self::positiveInt($employee, 'id'),
            self::text($employment, 'end_date'),
        );
        $deductions = $this->deductions($claims, $evidence['deductions']);
        if ($relationType === 'dpp') {
            if ($evidence['dpp_issuance_basis'] === 'sickness_insurance') {
                throw new EmploymentExitReadinessException(
                    'dpp_sickness_evidence_not_ready',
                    'Důvod vydání pro DPP z účasti na nemocenském pojištění '
                        . 'zatím nemá ověřený zdrojový podklad.',
                );
            }
            if ($evidence['dpp_issuance_basis'] !== 'wage_deductions') {
                throw new EmploymentExitReadinessException(
                    'dpp_issuance_basis_missing',
                    'Potvrzení pro DPP vyžaduje doložený zákonný důvod vydání.',
                );
            }
            if ($deductions === []) {
                throw new EmploymentExitReadinessException(
                    'dpp_wage_deduction_missing',
                    'Důvod vydání pro DPP neodpovídá žádné pokračující srážce.',
                );
            }
        } elseif ($evidence['dpp_issuance_basis'] !== null) {
            throw new \InvalidArgumentException(
                'Důvod vydání pro DPP nepatří k tomuto vztahu.',
            );
        }

        $employer = ($this->employers)($supplierId);
        $identity = $sources['identity'];
        $address = $sources['address'];
        $terms = $sources['terms'];
        $employerSnapshot = $employer->toArray();
        $manifest = [
            'schema_version' => 'employment-certificate-source-manifest.v1',
            'snapshot_schema_version' => self::SCHEMA_VERSION,
            'document_schema_version' =>
                EmploymentCertificateDocumentData::SCHEMA_VERSION,
            'mapping_version' => self::MAPPING_VERSION,
            'purpose' => self::PURPOSE,
            'supplier_id' => $supplierId,
            'employee_id' => self::positiveInt($employee, 'id'),
            'employment_id' => $employmentId,
            'employment_end_date' => self::text($employment, 'end_date'),
            'sources' => [
                'employment' => $this->sourceReference(
                    $employment,
                    'employment-certificate-employment-v1',
                    $supplierId,
                ),
                'employee' => [
                    'id' => self::positiveInt($employee, 'id'),
                    'source_hash' => $this->fingerprint(
                        $employee,
                        'employment-certificate-employee-v1',
                        $supplierId,
                    ),
                ],
                'identity' => $this->sourceReference(
                    $identity,
                    'employment-certificate-identity-v1',
                    $supplierId,
                ),
                'address' => $this->sourceReference(
                    $address,
                    'employment-certificate-address-v1',
                    $supplierId,
                ),
                'terms' => $this->sourceReference(
                    $terms,
                    'employment-certificate-terms-v1',
                    $supplierId,
                ),
                'deduction_claims' => array_map(
                    fn (array $claim): array => [
                        'id' => self::positiveInt($claim, 'id'),
                        'row_version' =>
                            self::positiveInt($claim, 'row_version'),
                        'source_hash' => $this->fingerprint(
                            $claim,
                            'employment-certificate-deduction-v1',
                            $supplierId,
                        ),
                    ],
                    $claims,
                ),
            ],
            'employer_snapshot_hash' => $this->fingerprint(
                $employerSnapshot,
                'employment-certificate-employer-v1',
                $supplierId,
            ),
            'evidence_snapshot_hash' => $this->fingerprint(
                $evidence,
                'employment-certificate-evidence-v1',
                $supplierId,
            ),
        ];
        $manifestJson = CanonicalJson::encode($manifest);
        $manifestHash = hash('sha256', $manifestJson);
        $existing = $this->revisions->findBySourceManifest(
            $supplierId,
            $employmentId,
            self::PURPOSE,
            $manifestHash,
        );
        if ($existing !== null) {
            return [
                'revision' => $existing,
                'document' => $this->hydrate(
                    $this->decrypt($existing),
                    self::hash($existing, 'snapshot_hash'),
                ),
            ];
        }

        $latest = $this->revisions->latest(
            $supplierId,
            $employmentId,
            self::PURPOSE,
        );
        if ($latest !== null && $evidence['correction_reason'] === null) {
            throw new EmploymentExitReadinessException(
                'correction_reason_required',
                'Nová revize potvrzení vyžaduje konkrétní důvod opravy.',
            );
        }
        if ($latest === null && $evidence['correction_reason'] !== null) {
            throw new \InvalidArgumentException(
                'První potvrzení nesmí být označeno jako oprava.',
            );
        }

        $issuedStatement = $this->db->pdo()->query('SELECT DATE(NOW())');
        if ($issuedStatement === false) {
            throw new \RuntimeException('Datum vydání nelze načíst.');
        }
        $issuedAtValue = $issuedStatement->fetchColumn();
        if (!is_string($issuedAtValue)) {
            throw new \UnexpectedValueException('Datum vydání není text.');
        }
        $issuedAt = $issuedAtValue;
        $snapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'mapping_version' => self::MAPPING_VERSION,
            'purpose' => self::PURPOSE,
            'employer' => $employerSnapshot,
            'employee' => [
                'name' => self::text($identity, 'full_name'),
                'birth_date' => self::text($employee, 'birth_date'),
                'address' => self::formatAddress($address),
            ],
            'relationship_kind' => $relationType,
            'employment_from' => self::text($employment, 'actual_start_date'),
            'employment_to' => self::text($employment, 'end_date'),
            'work_description' => $evidence['work_description'],
            'achieved_qualification' => $evidence['achieved_qualification'],
            'exposure_assessment_complete' => true,
            'exposure_facts' => $evidence['exposure_facts'],
            'deduction_assessment_complete' => true,
            'deductions' => array_map(
                static fn (EmploymentCertificateDeduction $deduction): array =>
                    $deduction->toArray(),
                $deductions,
            ),
            'pension_category_assessment_complete' => true,
            'pre1993_pension_category_periods' =>
                $evidence['pre1993_pension_category_periods'],
            'issued_at' => $issuedAt,
            'dpp_issuance_basis' => $evidence['dpp_issuance_basis'],
            'correction_reason' => $evidence['correction_reason'],
        ];
        $snapshotJson = CanonicalJson::encode($snapshot);
        $snapshotHash = $this->sensitiveData->keyedFingerprint(
            $snapshotJson,
            'employment-exit-snapshot-v1',
            $supplierId,
        );
        $revision = $this->revisions->insertApproved([
            'supplier_id' => $supplierId,
            'employee_id' => self::positiveInt($employee, 'id'),
            'employment_id' => $employmentId,
            'purpose' => self::PURPOSE,
            'employment_end_date' => self::text($employment, 'end_date'),
            'revision_no' => $latest === null
                ? 1
                : self::positiveInt($latest, 'revision_no') + 1,
            'previous_revision_id' => $latest['id'] ?? null,
            'snapshot_ciphertext' => $this->encryption->encryptFor(
                $snapshotJson,
                $this->encryptionContext(
                    $supplierId,
                    self::positiveInt($employee, 'id'),
                    $employmentId,
                    $manifestHash,
                ),
            ),
            'snapshot_hash' => $snapshotHash,
            'source_manifest_json' => $manifestJson,
            'source_manifest_hash' => $manifestHash,
            'approved_by' => $actorUserId,
        ]);

        return [
            'revision' => $revision,
            'document' => $this->hydrate($snapshot, $snapshotHash),
        ];
    }

    /**
     * Suchý běh datové části stavitele zápočtového listu — protějšek
     * {@see AverageEarningsSnapshotBuilder::probe()}.
     *
     * Projde přesně ty fail-closed brány, které NEZÁVISÍ na obsahu formuláře:
     * existenci a čitelnost zdrojů, přípustnost druhu vztahu a ledger
     * pokračujících srážek. Brány závislé na vyplněném podkladu (shoda evidence
     * se srážkami, důvod opravy, intervaly pracovních kategorií) tu nejsou —
     * ty jde posoudit až proti odeslanému formuláři a `readiness()` o nich nic
     * tvrdit nesmí.
     *
     * Bez tohohle běhu hlásila `readiness()` u zápočtového listu natvrdo
     * `available: true` a uživatel se o fail-closed bráně dozvěděl až z 422 po
     * vyplnění celého formuláře.
     *
     * @return list<int> ID pokračujících srážek, které do listu patří
     */
    public function probe(int $supplierId, int $employmentId): array
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException(
                'Suchý běh výstupního snapshotu vyžaduje aktivní transakci.',
            );
        }
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException(
                'Identita výstupního potvrzení není platná.',
            );
        }
        $sources = $this->revisions->lockCertificateSources(
            $supplierId,
            $employmentId,
        );
        $relationType = EmploymentExitRelationshipPolicy::documentKind(
            self::text($sources['employment'], 'relation_type'),
        );
        $claims = $this->revisions->lockContinuingDeductionClaims(
            $supplierId,
            self::positiveInt($sources['employee'], 'id'),
            self::text($sources['employment'], 'end_date'),
        );
        // U DPP je jediný podporovaný důvod vydání „srážky ze mzdy"
        // (`sickness_insurance` je fail-closed), takže prázdný ledger znamená,
        // že list nepůjde vydat bez ohledu na to, co uživatel vyplní.
        if ($relationType === 'dpp' && $claims === []) {
            throw new EmploymentExitReadinessException(
                'dpp_wage_deduction_missing',
                'Důvod vydání pro DPP neodpovídá žádné pokračující srážce.',
            );
        }
        // Snapshot zaměstnavatele je společná fail-closed brána generování —
        // chybějící nebo nečitelné údaje firmy shodí i zápočtový list.
        ($this->employers)($supplierId);

        return array_map(
            static fn (array $claim): int => self::positiveInt($claim, 'id'),
            $claims,
        );
    }

    /**
     * @param list<array<string,mixed>> $claims
     * @param list<array{source_claim_id:int,beneficiary:string,ordering_authority:string,decision_reference:string}> $evidence
     * @return list<EmploymentCertificateDeduction>
     */
    private function deductions(array $claims, array $evidence): array
    {
        $byId = [];
        foreach ($evidence as $row) {
            $byId[$row['source_claim_id']] = $row;
        }
        $claimIds = array_map(
            static fn (array $claim): int =>
                self::positiveInt($claim, 'id'),
            $claims,
        );
        sort($claimIds, SORT_NUMERIC);
        $evidenceIds = array_keys($byId);
        sort($evidenceIds, SORT_NUMERIC);
        if ($claimIds !== $evidenceIds) {
            throw new EmploymentExitReadinessException(
                'deduction_source_mismatch',
                'Podklad pokračujících srážek neodpovídá ověřenému ledgeru.',
            );
        }
        $result = [];
        foreach ($claims as $claim) {
            $source = $byId[self::positiveInt($claim, 'id')];
            $result[] = new EmploymentCertificateDeduction(
                beneficiary: $source['beneficiary'],
                claimAmountMinorUnits:
                    self::positiveInt($claim, 'claim_amount_minor_units'),
                withheldAmountMinorUnits:
                    self::nonNegativeInt($claim, 'withheld_amount_minor_units'),
                priorityDate: self::text($claim, 'priority_date'),
                orderingAuthority: $source['ordering_authority'],
                decisionReference: $source['decision_reference'],
            );
        }

        return $result;
    }

    /** @param array<string,mixed> $revision
     *  @return array<string,mixed>
     */
    private function decrypt(array $revision): array
    {
        $json = $this->encryption->decryptFor(
            self::text($revision, 'snapshot_ciphertext'),
            $this->encryptionContext(
                self::positiveInt($revision, 'supplier_id'),
                self::positiveInt($revision, 'employee_id'),
                self::positiveInt($revision, 'employment_id'),
                self::hash($revision, 'source_manifest_hash'),
            ),
        );
        $expected = $this->sensitiveData->keyedFingerprint(
            $json,
            'employment-exit-snapshot-v1',
            self::positiveInt($revision, 'supplier_id'),
        );
        if (!hash_equals(self::hash($revision, 'snapshot_hash'), $expected)) {
            throw new \DomainException(
                'Otisk výstupního snapshotu pracovního vztahu nesouhlasí.',
            );
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \DomainException(
                'Výstupní snapshot pracovního vztahu není objekt.',
            );
        }

        return self::normalizeObject($decoded, 'Výstupní snapshot');
    }

    /** @param array<string,mixed> $snapshot */
    private function hydrate(
        array $snapshot,
        string $snapshotHash,
    ): EmploymentCertificateDocumentData {
        if (($snapshot['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($snapshot['mapping_version'] ?? null) !== self::MAPPING_VERSION
            || ($snapshot['purpose'] ?? null) !== self::PURPOSE
        ) {
            throw new \DomainException(
                'Výstupní snapshot má nepodporované schéma nebo účel.',
            );
        }
        $employer = self::object($snapshot, 'employer');
        $employerAddress = self::object($employer, 'address');
        $issuer = self::object($employer, 'issuer');
        $employee = self::object($snapshot, 'employee');
        $deductions = [];
        foreach (self::list($snapshot, 'deductions') as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \DomainException('Srážka ve snapshotu není objekt.');
            }
            $row = self::normalizeObject($row, 'Srážka ve snapshotu');
            $deductions[] = new EmploymentCertificateDeduction(
                beneficiary: self::text($row, 'beneficiary'),
                claimAmountMinorUnits:
                    self::positiveInt($row, 'claim_amount_minor_units'),
                withheldAmountMinorUnits:
                    self::nonNegativeInt($row, 'withheld_amount_minor_units'),
                priorityDate: self::text($row, 'priority_date'),
                orderingAuthority: self::text($row, 'ordering_authority'),
                decisionReference: self::text($row, 'decision_reference'),
            );
        }

        return new EmploymentCertificateDocumentData(
            sourceSnapshotSha256: $snapshotHash,
            employer: new PayrollDocumentEmployerSnapshot(
                name: self::text($employer, 'name'),
                identificationNumber:
                    self::text($employer, 'identification_number'),
                taxIdentificationNumber:
                    self::text($employer, 'tax_identification_number'),
                streetLine: self::text($employerAddress, 'street_line'),
                city: self::text($employerAddress, 'city'),
                postalCode: self::text($employerAddress, 'postal_code'),
                countryCode: self::text($employerAddress, 'country_code'),
                countryName: self::text($employerAddress, 'country_name'),
                issuerName: self::text($issuer, 'name'),
                issuerEmail: self::text($issuer, 'email'),
                issuerPhone: self::text($issuer, 'phone'),
            ),
            employeeName: self::text($employee, 'name'),
            employeeBirthDate: self::text($employee, 'birth_date'),
            employeeAddress: self::text($employee, 'address'),
            relationshipKind: self::text($snapshot, 'relationship_kind'),
            employmentFrom: self::text($snapshot, 'employment_from'),
            employmentTo: self::text($snapshot, 'employment_to'),
            workDescription: self::text($snapshot, 'work_description'),
            achievedQualification:
                self::text($snapshot, 'achieved_qualification'),
            exposureAssessmentComplete:
                self::bool($snapshot, 'exposure_assessment_complete'),
            exposureFacts: self::stringList($snapshot, 'exposure_facts'),
            deductionAssessmentComplete:
                self::bool($snapshot, 'deduction_assessment_complete'),
            deductions: $deductions,
            pensionCategoryAssessmentComplete:
                self::bool($snapshot, 'pension_category_assessment_complete'),
            pre1993PensionCategoryPeriods:
                self::objectList(
                    $snapshot,
                    'pre1993_pension_category_periods',
                ),
            issuedAt: self::text($snapshot, 'issued_at'),
            dppIssuanceBasis:
                self::nullableText($snapshot, 'dpp_issuance_basis'),
        );
    }

    /** @param array<string,mixed> $value */
    private function fingerprint(
        array $value,
        string $purpose,
        int $supplierId,
    ): string {
        return $this->sensitiveData->keyedFingerprint(
            CanonicalJson::encode($value),
            $purpose,
            $supplierId,
        );
    }

    /** @param array<string,mixed> $row
     *  @return array{id:int,row_version:int,source_hash:string}
     */
    private function sourceReference(
        array $row,
        string $purpose,
        int $supplierId,
    ): array {
        return [
            'id' => self::positiveInt($row, 'id'),
            'row_version' => self::positiveInt($row, 'row_version'),
            'source_hash' => $this->fingerprint(
                $row,
                $purpose,
                $supplierId,
            ),
        ];
    }

    /** @param array<string,mixed> $address */
    private static function formatAddress(array $address): string
    {
        return implode(', ', [
            self::text($address, 'street_line'),
            self::text($address, 'postal_code')
                . ' '
                . self::text($address, 'city'),
            self::text($address, 'country_code'),
        ]);
    }

    private function encryptionContext(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $manifestHash,
    ): string {
        return implode(':', [
            'payroll-employment-exit',
            (string) $supplierId,
            (string) $employeeId,
            (string) $employmentId,
            self::PURPOSE,
            $manifestHash,
        ]);
    }

    /**
     * @param list<array{category:string,from:string,to:string}> $periods
     */
    private function assertPensionPeriodsWithinEmployment(
        array $periods,
        string $employmentFrom,
        string $employmentTo,
    ): void {
        $previousTo = null;
        foreach ($periods as $period) {
            if ($period['from'] < $employmentFrom
                || $period['to'] > $employmentTo
                || $period['to'] < $period['from']
                || $period['to'] >= '1993-01-01'
                || ($previousTo !== null && $period['from'] <= $previousTo)
            ) {
                throw new EmploymentExitReadinessException(
                    'pension_category_period_invalid',
                    'Interval pracovní kategorie nepatří do tohoto vztahu '
                        . 'nebo se překrývá.',
                );
            }
            $previousTo = $period['to'];
        }
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \DomainException("Pole {$field} ve snapshotu chybí.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableText(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \DomainException("Pole {$field} ve snapshotu není text.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function positiveInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \DomainException("Pole {$field} není celé číslo.");
        }
        $value = (int) $value;
        if ($value <= 0) {
            throw new \DomainException("Pole {$field} musí být kladné.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nonNegativeInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \DomainException("Pole {$field} není celé číslo.");
        }
        $value = (int) $value;
        if ($value < 0) {
            throw new \DomainException("Pole {$field} nesmí být záporné.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function hash(array $row, string $field): string
    {
        $value = self::text($row, $field);
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \DomainException("Pole {$field} není platný otisk.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function bool(array $row, string $field): bool
    {
        $value = $row[$field] ?? null;
        if (!is_bool($value)) {
            throw new \DomainException("Pole {$field} není boolean.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private static function object(array $row, string $field): array
    {
        $value = $row[$field] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("Pole {$field} není objekt.");
        }

        return self::normalizeObject($value, "Pole {$field}");
    }

    /** @param array<string,mixed> $row
     *  @return list<mixed>
     */
    private static function list(array $row, string $field): array
    {
        $value = $row[$field] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException("Pole {$field} není seznam.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row
     *  @return list<string>
     */
    private static function stringList(array $row, string $field): array
    {
        $result = self::list($row, $field);
        foreach ($result as $value) {
            if (!is_string($value)) {
                throw new \DomainException("Pole {$field} neobsahuje text.");
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $row
     *  @return list<array{category:string,from:string,to:string}>
     */
    private static function objectList(array $row, string $field): array
    {
        $result = [];
        foreach (self::list($row, $field) as $value) {
            if (!is_array($value) || array_is_list($value)) {
                throw new \DomainException("Pole {$field} neobsahuje objekty.");
            }
            $value = self::normalizeObject($value, "Pole {$field}");
            $result[] = [
                'category' => self::text($value, 'category'),
                'from' => self::text($value, 'from'),
                'to' => self::text($value, 'to'),
            ];
        }

        return $result;
    }

    /**
     * @param array<array-key,mixed> $value
     * @return array<string,mixed>
     */
    private static function normalizeObject(
        array $value,
        string $label,
    ): array {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \DomainException("{$label} má neplatný klíč.");
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
