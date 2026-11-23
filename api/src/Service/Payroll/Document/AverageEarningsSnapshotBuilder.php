<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentExitRevisionRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentTerminationRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Termination\PayrollTerminationReason;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;

/**
 * Schválený neměnný snapshot pro obě potvrzení o průměrném výdělku:
 * oddělené potvrzení podle § 313 odst. 2 zákoníku práce (průměrný měsíční
 * ČISTÝ výdělek pro Úřad práce) a samostatné potvrzení o hrubém průměrném
 * výdělku podle § 356 odst. 1 a 2.
 *
 * Zdrojem je vždy jen schválený snapshot průměru z MZ-07 — § 360 zákoníku
 * práce říká, že po skončení vztahu se použije průměr zjištěný naposledy
 * v jeho průběhu, takže se nic nedopočítává z běhů po skončení.
 */
final class AverageEarningsSnapshotBuilder
{
    public const SCHEMA_VERSION = 'average-earnings-snapshot.v1';
    public const MAPPING_VERSION = 'average-earnings-mapping.v1';

    public const CERTIFICATE_PURPOSE = 'average_earnings_certificate';
    public const STATEMENT_PURPOSE = 'average_earnings_statement';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentExitRevisionRepository $revisions,
        private readonly PayrollAverageEarningRepository $averageEarnings,
        private readonly PayrollDocumentEmployerSnapshotProvider $employers,
        private readonly AverageEarningsEvidenceValidator $validator,
        private readonly AverageEarningsMonthlyConverter $converter,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly SecretEncryption $encryption,
        private readonly PayrollEmploymentTerminationRepository $terminations,
    ) {}

    /**
     * @param array<string,mixed> $evidenceInput
     * @return array{
     *   revision:array<string,mixed>,
     *   document:AverageEarningsCertificateDocumentData
     *     |AverageEarningsStatementDocumentData
     * }
     */
    public function build(
        int $supplierId,
        int $employmentId,
        string $purpose,
        array $evidenceInput,
        int $actorUserId,
    ): array {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException(
                'Snapshot průměrného výdělku vyžaduje aktivní transakci.',
            );
        }
        if ($supplierId <= 0 || $employmentId <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Identita potvrzení o průměrném výdělku není platná.',
            );
        }
        $withNet = self::assertPurpose($purpose);
        $evidence = $withNet
            ? $this->validator->validateCertificate($evidenceInput)
            : $this->validator->validateStatement($evidenceInput);
        if ($withNet) {
            $this->assertMatchesTerminationRecord(
                $supplierId,
                $employmentId,
                (string) $evidence['termination_reason_kind'],
                $evidence['employee_stated_reason'],
            );
        }

        $sources = $this->revisions->lockCertificateSources(
            $supplierId,
            $employmentId,
        );
        $employment = $sources['employment'];
        $employee = $sources['employee'];
        $identity = $sources['identity'];
        $address = $sources['address'];
        $relationType = EmploymentExitRelationshipPolicy::documentKind(
            self::text($employment, 'relation_type'),
        );
        $employmentFrom = self::text($employment, 'actual_start_date');
        $employmentTo = self::text($employment, 'end_date');
        if ($withNet) {
            $this->assertPensionPeriods(
                $evidence['pension_insurance_periods'],
                $employmentFrom,
                $employmentTo,
            );
        }

        $end = new \DateTimeImmutable($employmentTo);
        $decisiveYear = (int) $end->format('Y');
        $decisiveQuarter = intdiv(((int) $end->format('n')) - 1, 3) + 1;
        $average = $this->averageEarnings->findApproved(
            $supplierId,
            $employmentId,
            $decisiveYear,
            $decisiveQuarter,
        );
        if ($average === null) {
            throw new EmploymentExitReadinessException(
                'average_earnings_snapshot_missing',
                sprintf(
                    'Pro rozhodné období %d/Q%d chybí schválený snapshot '
                        . 'průměrného výdělku. Nejprve ho založte a schvalte '
                        . 'v Mzdy → Absence a dovolená, záložka Průměrný výdělek.',
                    $decisiveYear,
                    $decisiveQuarter,
                ),
            );
        }
        $issuedAt = $this->databaseToday();
        $conversion = $this->converter->convert(
            $supplierId,
            self::positiveInt($employee, 'id'),
            $employmentId,
            $average,
            $issuedAt,
            $withNet,
        );

        $employer = ($this->employers)($supplierId);
        $employerSnapshot = $employer->toArray();
        $averageFingerprint = $this->fingerprint(
            [
                'id' => self::positiveInt($average, 'id'),
                'revision_no' => self::positiveInt($average, 'revision_no'),
                'source_kind' => self::text($average, 'source_kind'),
                'average_hourly_minor' =>
                    self::positiveInt($average, 'average_hourly_minor'),
                'decisive_from' => self::text($average, 'decisive_from'),
                'decisive_to' => self::text($average, 'decisive_to'),
                'ruleset_hash' => self::text($average, 'ruleset_hash'),
            ],
            'average-earnings-approved-v1',
            $supplierId,
        );
        $manifest = [
            'schema_version' => 'average-earnings-source-manifest.v1',
            'snapshot_schema_version' => self::SCHEMA_VERSION,
            'mapping_version' => self::MAPPING_VERSION,
            'purpose' => $purpose,
            'supplier_id' => $supplierId,
            'employee_id' => self::positiveInt($employee, 'id'),
            'employment_id' => $employmentId,
            'employment_end_date' => $employmentTo,
            'sources' => [
                'employment' => $this->sourceReference(
                    $employment,
                    'average-earnings-employment-v1',
                    $supplierId,
                ),
                'identity' => $this->sourceReference(
                    $identity,
                    'average-earnings-identity-v1',
                    $supplierId,
                ),
                'address' => $this->sourceReference(
                    $address,
                    'average-earnings-address-v1',
                    $supplierId,
                ),
                'average_earning_snapshot' => [
                    'id' => self::positiveInt($average, 'id'),
                    'revision_no' => self::positiveInt($average, 'revision_no'),
                    'source_hash' => $averageFingerprint,
                ],
                'weekly_hours_intervals' => $conversion->weeklyHoursIntervals,
            ],
            'employer_snapshot_hash' => $this->fingerprint(
                $employerSnapshot,
                'average-earnings-employer-v1',
                $supplierId,
            ),
            'evidence_snapshot_hash' => $this->fingerprint(
                $evidence,
                'average-earnings-evidence-v1',
                $supplierId,
            ),
            'conversion_hash' => $this->fingerprint(
                $conversion->toArray(),
                'average-earnings-conversion-v1',
                $supplierId,
            ),
        ];
        $manifestJson = CanonicalJson::encode($manifest);
        $manifestHash = hash('sha256', $manifestJson);
        $existing = $this->revisions->findBySourceManifest(
            $supplierId,
            $employmentId,
            $purpose,
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
            $purpose,
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

        $snapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'mapping_version' => self::MAPPING_VERSION,
            'purpose' => $purpose,
            'employer' => $employerSnapshot,
            'employee' => [
                'name' => self::text($identity, 'full_name'),
                'birth_date' => self::text($employee, 'birth_date'),
                'address' => self::formatAddress($address),
            ],
            'relationship_kind' => $relationType,
            'employment_from' => $employmentFrom,
            'employment_to' => $employmentTo,
            'average_kind' => self::averageKind($average),
            'average_applicable_year' => $decisiveYear,
            'average_applicable_quarter' => $decisiveQuarter,
            'average_snapshot_hash' => $averageFingerprint,
            'conversion' => $conversion->toArray(),
            'issued_at' => $issuedAt,
            'evidence' => $evidence,
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
            'purpose' => $purpose,
            'employment_end_date' => $employmentTo,
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
                    $purpose,
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
     * Suchý běh datové části stavitele: projde přesně ty fail-closed brány,
     * na kterých by spadlo generování, ale nic nezapisuje. Obrazovka díky
     * tomu nemůže nabízet dokument, který by neprošel.
     */
    public function probe(
        int $supplierId,
        int $employmentId,
        string $purpose,
    ): void {
        $withNet = self::assertPurpose($purpose);
        $sources = $this->revisions->lockCertificateSources(
            $supplierId,
            $employmentId,
        );
        EmploymentExitRelationshipPolicy::documentKind(
            self::text($sources['employment'], 'relation_type'),
        );
        $end = new \DateTimeImmutable(
            self::text($sources['employment'], 'end_date'),
        );
        $average = $this->averageEarnings->findApproved(
            $supplierId,
            $employmentId,
            (int) $end->format('Y'),
            intdiv(((int) $end->format('n')) - 1, 3) + 1,
        );
        if ($average === null) {
            throw new EmploymentExitReadinessException(
                'average_earnings_snapshot_missing',
                'Pro rozhodné období chybí schválený snapshot průměrného '
                    . 'výdělku.',
            );
        }
        self::averageKind($average);
        $this->converter->convert(
            $supplierId,
            self::positiveInt($sources['employee'], 'id'),
            $employmentId,
            $average,
            $this->databaseToday(),
            $withNet,
        );
    }

    /**
     * Způsob skončení v potvrzení pro Úřad práce má jediný zdroj — záznam
     * o skončení na kartě vztahu. Je-li vyplněný, potvrzení ho nesmí tvrdit
     * jinak. Bez záznamu zůstává ruční volba jako dosud.
     */
    private function assertMatchesTerminationRecord(
        int $supplierId,
        int $employmentId,
        string $kind,
        ?string $statedReason,
    ): void {
        $record = $this->terminations->find($supplierId, $employmentId);
        if ($record === null) {
            return;
        }
        $reason = new PayrollTerminationReason(
            (string) $record['termination_method'],
            (string) $record['legal_ground'],
        );
        if ($kind !== $reason->unemploymentOfficeKind()) {
            throw new EmploymentExitReadinessException(
                'termination_reason_mismatch',
                'Způsob skončení v potvrzení neodpovídá skončení zapsanému na kartě'
                    . ' vztahu (Skončení vztahu). Potvrzení bere způsob skončení odtamtud.',
            );
        }
        if ($statedReason !== null && !$reason->statedReasonAllowed()) {
            throw new EmploymentExitReadinessException(
                'termination_reason_mismatch',
                'Důvod uvedený zaměstnancem se k tomuto způsobu skončení netiskne.',
            );
        }
    }

    private static function assertPurpose(string $purpose): bool
    {
        return match ($purpose) {
            self::CERTIFICATE_PURPOSE => true,
            self::STATEMENT_PURPOSE => false,
            default => throw new \InvalidArgumentException(
                'Účel potvrzení o průměrném výdělku není podporovaný.',
            ),
        };
    }

    /** @param array<string,mixed> $average */
    private static function averageKind(array $average): string
    {
        $kind = self::text($average, 'source_kind');

        return match ($kind) {
            'actual' => 'actual',
            'probable' => 'probable',
            default => throw new EmploymentExitReadinessException(
                'average_earnings_source_kind_not_supported',
                'Druh schváleného průměru není pro potvrzení podporovaný.',
            ),
        };
    }

    private function databaseToday(): string
    {
        $statement = $this->db->pdo()->query('SELECT DATE(NOW())');
        if ($statement === false) {
            throw new \RuntimeException('Datum vydání nelze načíst.');
        }
        $value = $statement->fetchColumn();
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Datum vydání není text.');
        }

        return $value;
    }

    /** @param list<array{from:string,to:string}> $periods */
    private function assertPensionPeriods(
        array $periods,
        string $employmentFrom,
        string $employmentTo,
    ): void {
        $previousTo = null;
        foreach ($periods as $period) {
            if ($period['from'] < $employmentFrom
                || $period['to'] > $employmentTo
                || $period['to'] < $period['from']
                || ($previousTo !== null && $period['from'] <= $previousTo)
            ) {
                throw new EmploymentExitReadinessException(
                    'pension_insurance_period_invalid',
                    'Interval důchodového pojištění nepatří do tohoto vztahu '
                        . 'nebo se překrývá.',
                );
            }
            $previousTo = $period['to'];
        }
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return AverageEarningsCertificateDocumentData
     *   |AverageEarningsStatementDocumentData
     */
    private function hydrate(
        array $snapshot,
        string $snapshotHash,
    ): AverageEarningsCertificateDocumentData
        |AverageEarningsStatementDocumentData {
        if (($snapshot['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($snapshot['mapping_version'] ?? null) !== self::MAPPING_VERSION
        ) {
            throw new \DomainException(
                'Snapshot průměrného výdělku má nepodporované schéma.',
            );
        }
        $purpose = self::text($snapshot, 'purpose');
        $withNet = self::assertPurpose($purpose);
        $employer = self::object($snapshot, 'employer');
        $employerAddress = self::object($employer, 'address');
        $issuer = self::object($employer, 'issuer');
        $employee = self::object($snapshot, 'employee');
        $conversion = self::object($snapshot, 'conversion');
        $evidence = self::object($snapshot, 'evidence');
        $employerSnapshot = new PayrollDocumentEmployerSnapshot(
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
        );
        $trace = self::object($conversion, 'trace');

        if ($withNet) {
            return new AverageEarningsCertificateDocumentData(
                sourceSnapshotSha256: $snapshotHash,
                averageSnapshotSha256:
                    self::hash($snapshot, 'average_snapshot_hash'),
                employer: $employerSnapshot,
                employeeName: self::text($employee, 'name'),
                employeeBirthDate: self::text($employee, 'birth_date'),
                employeeAddress: self::text($employee, 'address'),
                relationshipKind: self::text($snapshot, 'relationship_kind'),
                employmentFrom: self::text($snapshot, 'employment_from'),
                employmentTo: self::text($snapshot, 'employment_to'),
                pensionInsurancePeriods: self::periods(
                    $evidence,
                    'pension_insurance_periods',
                ),
                averageKind: self::text($snapshot, 'average_kind'),
                averageApplicableYear:
                    self::positiveInt($snapshot, 'average_applicable_year'),
                averageApplicableQuarter:
                    self::positiveInt($snapshot, 'average_applicable_quarter'),
                averageMonthlyNetMinorUnits:
                    self::positiveInt($conversion, 'net_monthly_minor_units'),
                terminationReasonKind:
                    self::text($evidence, 'termination_reason_kind'),
                employeeStatedReason:
                    self::nullableText($evidence, 'employee_stated_reason'),
                issuedAt: self::text($snapshot, 'issued_at'),
            );
        }

        return new AverageEarningsStatementDocumentData(
            sourceSnapshotSha256: $snapshotHash,
            averageSnapshotSha256:
                self::hash($snapshot, 'average_snapshot_hash'),
            employer: $employerSnapshot,
            employeeName: self::text($employee, 'name'),
            employeeBirthDate: self::text($employee, 'birth_date'),
            employeeAddress: self::text($employee, 'address'),
            relationshipKind: self::text($snapshot, 'relationship_kind'),
            employmentFrom: self::text($snapshot, 'employment_from'),
            employmentTo: self::text($snapshot, 'employment_to'),
            averageKind: self::text($snapshot, 'average_kind'),
            averageApplicableYear:
                self::positiveInt($snapshot, 'average_applicable_year'),
            averageApplicableQuarter:
                self::positiveInt($snapshot, 'average_applicable_quarter'),
            decisiveFrom: self::text($trace, 'decisive_from'),
            decisiveTo: self::text($trace, 'decisive_to'),
            averageHourlyMinorUnits:
                self::positiveInt($conversion, 'applied_hourly_minor_units'),
            minimumWageFloorApplied:
                self::bool($conversion, 'minimum_wage_floor_applied'),
            weeklyHoursMilli:
                self::positiveInt($conversion, 'weekly_hours_milli'),
            grossMonthlyMinorUnits:
                self::positiveInt($conversion, 'gross_monthly_minor_units'),
            requestedPurpose: self::text($evidence, 'requested_purpose'),
            issuedAt: self::text($snapshot, 'issued_at'),
        );
    }

    /**
     * @param array<string,mixed> $revision
     * @return array<string,mixed>
     */
    private function decrypt(array $revision): array
    {
        $json = $this->encryption->decryptFor(
            self::text($revision, 'snapshot_ciphertext'),
            $this->encryptionContext(
                self::positiveInt($revision, 'supplier_id'),
                self::positiveInt($revision, 'employee_id'),
                self::positiveInt($revision, 'employment_id'),
                self::text($revision, 'purpose'),
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
                'Otisk snapshotu průměrného výdělku nesouhlasí.',
            );
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \DomainException(
                'Snapshot průměrného výdělku není objekt.',
            );
        }

        return self::normalizeObject($decoded, 'Snapshot průměrného výdělku');
    }

    public static function encryptionContext(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $purpose,
        string $manifestHash,
    ): string {
        return implode(':', [
            'payroll-employment-exit',
            (string) $supplierId,
            (string) $employeeId,
            (string) $employmentId,
            $purpose,
            $manifestHash,
        ]);
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

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,row_version:int,source_hash:string}
     */
    private function sourceReference(
        array $row,
        string $purpose,
        int $supplierId,
    ): array {
        return [
            'id' => self::positiveInt($row, 'id'),
            'row_version' => self::positiveInt($row, 'row_version'),
            'source_hash' => $this->fingerprint($row, $purpose, $supplierId),
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

    /**
     * @param array<string,mixed> $row
     * @return list<array{from:string,to:string}>
     */
    private static function periods(array $row, string $field): array
    {
        $value = $row[$field] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException("Pole {$field} není seznam.");
        }
        $result = [];
        foreach ($value as $period) {
            if (!is_array($period) || array_is_list($period)) {
                throw new \DomainException("Pole {$field} neobsahuje objekty.");
            }
            $period = self::normalizeObject($period, "Pole {$field}");
            $result[] = [
                'from' => self::text($period, 'from'),
                'to' => self::text($period, 'to'),
            ];
        }

        return $result;
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
    private static function bool(array $row, string $field): bool
    {
        $value = $row[$field] ?? null;
        if (!is_bool($value)) {
            throw new \DomainException("Pole {$field} není boolean.");
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

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function object(array $row, string $field): array
    {
        $value = $row[$field] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("Pole {$field} není objekt.");
        }

        return self::normalizeObject($value, "Pole {$field}");
    }

    /**
     * @param array<array-key,mixed> $value
     * @return array<string,mixed>
     */
    private static function normalizeObject(array $value, string $label): array
    {
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
