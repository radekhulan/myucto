<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualDocumentRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementResult;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;

/**
 * Zmrazí výsledek ročního zúčtování do neměnné roční revize.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč se nestaví druhý mechanismus
 * ─────────────────────────────────────────────────────────────────────────────
 * `payroll_annual_document_revisions` už existují (migrace 1265) a jejich CHECK
 * hodnotu `annual_settlement_result` povoluje od začátku — jen ji dosud nikdo
 * nezapsal. Zdroje se přitom vážou přes `payroll_annual_document_sources` na
 * konkrétní schválené mzdové revize a trigger v databázi ověřuje, že jde
 * SKUTEČNĚ o nejnovější schválenou revizi daného měsíce. Přesně to roční
 * zúčtování potřebuje: § 38ch odst. 4 ho staví na úhrnu mezd za celý rok, takže
 * musí být dohledatelné, ze kterých měsíců vzniklo.
 *
 * Manifest zdrojů navíc dělá idempotenci zadarmo. Kdyby se zúčtování spustilo
 * podruhé nad týmiž měsíci, vyjde stejný `source_manifest_hash` a unikátní klíč
 * `uq_payroll_annual_revision_source` vrátí PŮVODNÍ revizi místo založení další.
 * Do manifestu proto patří i otisk výsledku výpočtu — kdyby se změnila jen
 * evidence nároků a měsíce zůstaly, musí to být jiný manifest, ne tichý návrat
 * starého čísla.
 */
final class AnnualSettlementSnapshotBuilder
{
    public const SCHEMA_VERSION = AnnualSettlementResult::SCHEMA_VERSION;
    public const PURPOSE = 'annual_settlement_result';
    public const MAPPING_VERSION = 'annual-settlement-mapping.v2';

    /**
     * Doména klíčovaného otisku snapshotu. Je veřejná proto, že revizi čte
     * i mzdový list (§ 38j odst. 2 písm. h) a otisk musí ověřovat POD TOUTÉŽ
     * doménou, pod kterou vznikl — jinak mu žádná skutečná revize neprojde.
     */
    public const SNAPSHOT_FINGERPRINT_DOMAIN = 'annual-settlement-snapshot-v1';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAnnualDocumentRepository $annualRevisions,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly SecretEncryption $encryption,
    ) {}

    /**
     * @param list<array{label:string,amount_minor_units:int}> $creditRows
     * @param list<array{label:string,months:int,amount_minor_units:int}> $childRows
     * @return array{
     *   revision:array<string,mixed>,
     *   document:AnnualSettlementDocumentData,
     *   created:bool
     * }
     */
    public function build(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        AnnualSettlementResult $result,
        string $settledOn,
        array $creditRows,
        array $childRows,
        ?int $actorUserId,
    ): array {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException(
                'Snapshot ročního zúčtování vyžaduje aktivní transakci.',
            );
        }
        if (!$result->performed) {
            throw new \LogicException(
                'Neprovedené roční zúčtování se do dokladu nezmrazuje.',
            );
        }

        $sources = $this->annualRevisions->lockApprovedYearSources(
            $supplierId,
            $employeeId,
            $taxYear,
        );
        if ($sources === []) {
            throw new \DomainException(
                'Roční zúčtování nelze doložit bez schváleného mzdového výsledku v daném roce.',
            );
        }
        $manifestSources = [];
        foreach ($sources as $source) {
            $source = self::object($source, 'source');
            $manifestSources[] = [
                'period_start' => self::stringValue($source['period_start'] ?? null, 'source.period_start'),
                'run_id' => self::positiveIntValue($source['run_id'] ?? null, 'source.run_id'),
                'revision_id' => self::positiveIntValue($source['revision_id'] ?? null, 'source.revision_id'),
                'result_snapshot_hash' => self::stringValue(
                    $source['result_snapshot_hash'] ?? null,
                    'source.result_snapshot_hash',
                ),
                'person_result_hash' => self::stringValue(
                    $source['person_result_hash'] ?? null,
                    'source.person_result_hash',
                ),
            ];
        }

        $profile = $this->profileSnapshot($supplierId, $employeeId, $taxYear);
        $employer = $this->employerSnapshot($supplierId);
        $childRows = $this->childRowsSnapshot(
            $supplierId,
            $employeeId,
            $taxYear,
            $childRows,
        );

        $snapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'tax_year' => $taxYear,
            'settled_on' => $settledOn,
            'employer' => $employer,
            'employee' => $profile,
            'result' => $result->jsonSerialize(),
            'credit_rows' => $creditRows,
            'child_rows' => $childRows,
        ];
        $snapshotJson = CanonicalJson::encode($snapshot);
        $snapshotHash = $this->snapshotFingerprint($snapshotJson, $supplierId);

        $manifest = [
            'schema_version' => 'payroll-annual-source-manifest.v1',
            'document_schema_version' => self::SCHEMA_VERSION,
            'mapping_version' => self::MAPPING_VERSION,
            'purpose' => self::PURPOSE,
            'tax_year' => $taxYear,
            'employee_id' => $employeeId,
            // Otisk výsledku patří do manifestu: dva různé výsledky nad týmiž
            // měsíci musí být dvě různé revize, ne jedna přehraná.
            'result_hash' => hash('sha256', CanonicalJson::encode($result->jsonSerialize())),
            'document_rows_hash' => hash('sha256', CanonicalJson::encode([
                'credit_rows' => $creditRows,
                'child_rows' => $childRows,
            ])),
            'sources' => $manifestSources,
        ];
        $manifestJson = CanonicalJson::encode($manifest);
        $manifestHash = hash('sha256', $manifestJson);

        $existing = $this->annualRevisions->findBySourceManifest(
            $supplierId,
            $employeeId,
            $taxYear,
            self::PURPOSE,
            $manifestHash,
        );
        if ($existing !== null) {
            $existingHash = self::stringValue(
                $existing['snapshot_hash'] ?? null,
                'revision.snapshot_hash',
            );
            if (!hash_equals($existingHash, $snapshotHash)) {
                throw new \DomainException(
                    'Stejný manifest ročního zúčtování odkazuje na jiný snapshot.',
                );
            }

            return [
                'revision' => $existing,
                'document' => $this->hydrate($snapshot, $existingHash),
                'created' => false,
            ];
        }

        $previous = $this->annualRevisions->latest(
            $supplierId,
            $employeeId,
            $taxYear,
            self::PURPOSE,
        );
        $ciphertext = $this->encryption->encryptFor(
            $snapshotJson,
            $this->encryptionContext($supplierId, $employeeId, $taxYear, $manifestHash),
        );
        $revision = $this->annualRevisions->insertApproved([
            'supplier_id' => $supplierId,
            'employee_id' => $employeeId,
            'tax_year' => $taxYear,
            'purpose' => self::PURPOSE,
            'revision_no' => $previous === null
                ? 1
                : self::positiveIntValue(
                    $previous['revision_no'] ?? null,
                    'previous.revision_no',
                ) + 1,
            'previous_revision_id' => $previous['id'] ?? null,
            'snapshot_ciphertext' => $ciphertext,
            'snapshot_hash' => $snapshotHash,
            'source_manifest_json' => $manifestJson,
            'source_manifest_hash' => $manifestHash,
            'approved_by' => $actorUserId,
        ], $sources);

        return [
            'revision' => $revision,
            'document' => $this->hydrate($snapshot, $snapshotHash),
            'created' => true,
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private function hydrate(
        array $snapshot,
        string $snapshotHash,
    ): AnnualSettlementDocumentData {
        $employer = self::object($snapshot['employer'] ?? null, 'employer');
        $employee = self::object($snapshot['employee'] ?? null, 'employee');
        $result = self::object($snapshot['result'] ?? null, 'result');
        $trace = self::object($result['trace'] ?? null, 'result.trace');
        // Starší revize klíč nenesly — chybějící příspěvek je nula, protože se
        // tehdy potvrzení od jiného plátce nepoužilo vůbec.
        $external = ($trace['external_certificates'] ?? null) === null
            ? []
            : self::object($trace['external_certificates'], 'result.trace.external_certificates');

        return new AnnualSettlementDocumentData(
            $snapshotHash,
            self::integerValue($snapshot['tax_year'] ?? null, 'tax_year'),
            self::stringValue($employer['name'] ?? null, 'employer.name'),
            self::stringValue(
                $employer['identification_number'] ?? null,
                'employer.identification_number',
            ),
            self::stringValue($employer['address'] ?? null, 'employer.address'),
            self::stringValue($employee['name'] ?? null, 'employee.name'),
            self::stringValue(
                $employee['identifier_label'] ?? null,
                'employee.identifier_label',
            ),
            self::stringValue(
                $employee['identifier_value'] ?? null,
                'employee.identifier_value',
            ),
            self::stringValue($snapshot['settled_on'] ?? null, 'settled_on'),
            self::integerValue($trace['completed_months'] ?? null, 'result.trace.completed_months'),
            self::integerValue(
                $trace['advance_base_minor_units'] ?? null,
                'result.trace.advance_base_minor_units',
            ),
            self::integerValue(
                $result['rounded_tax_base_minor_units'] ?? null,
                'result.rounded_tax_base_minor_units',
            ),
            self::integerValue(
                $result['tax_before_credits_minor_units'] ?? null,
                'result.tax_before_credits_minor_units',
            ),
            self::creditRows($snapshot['credit_rows'] ?? null),
            self::integerValue(
                $result['applied_credits_minor_units'] ?? null,
                'result.applied_credits_minor_units',
            ),
            self::childDocumentRows($snapshot['child_rows'] ?? null),
            self::integerValue(
                $result['child_credit_minor_units'] ?? null,
                'result.child_credit_minor_units',
            ),
            self::integerValue(
                $result['annual_tax_bonus_minor_units'] ?? null,
                'result.annual_tax_bonus_minor_units',
            ),
            self::integerValue(
                $result['tax_after_all_credits_minor_units'] ?? null,
                'result.tax_after_all_credits_minor_units',
            ),
            self::integerValue($trace['advance_tax_minor_units'] ?? null, 'result.trace.advance_tax_minor_units'),
            self::integerValue(
                $trace['monthly_tax_bonus_minor_units'] ?? null,
                'result.trace.monthly_tax_bonus_minor_units',
            ),
            self::integerValue($result['tax_difference_minor_units'] ?? null, 'result.tax_difference_minor_units'),
            self::integerValue($result['bonus_difference_minor_units'] ?? null, 'result.bonus_difference_minor_units'),
            self::integerValue(
                $result['settlement_difference_minor_units'] ?? null,
                'result.settlement_difference_minor_units',
            ),
            self::integerValue($result['payable_minor_units'] ?? null, 'result.payable_minor_units'),
            self::stringValue($result['outcome'] ?? null, 'result.outcome'),
            self::integerValue($external['count'] ?? 0, 'result.trace.external_certificates.count'),
            self::integerValue(
                $external['advance_base_minor_units'] ?? 0,
                'result.trace.external_certificates.advance_base_minor_units',
            ),
            self::integerValue(
                $external['advance_tax_minor_units'] ?? 0,
                'result.trace.external_certificates.advance_tax_minor_units',
            ),
            self::integerValue(
                $external['tax_bonus_minor_units'] ?? 0,
                'result.trace.external_certificates.tax_bonus_minor_units',
            ),
        );
    }

    /** @return list<array{label:string,amount_minor_units:int}> */
    private static function creditRows(mixed $value): array
    {
        $rows = [];
        foreach (self::listValue($value) as $item) {
            $row = self::object($item, 'credit_row');
            $rows[] = [
                'label' => self::stringValue($row['label'] ?? null, 'credit_row.label'),
                'amount_minor_units' => self::integerValue(
                    $row['amount_minor_units'] ?? null,
                    'credit_row.amount_minor_units',
                ),
            ];
        }

        return $rows;
    }

    /** @return list<array{label:string,months:int,amount_minor_units:int}> */
    private static function childDocumentRows(mixed $value): array
    {
        $rows = [];
        foreach (self::listValue($value) as $item) {
            $row = self::object($item, 'child_row');
            $rows[] = [
                'label' => self::stringValue($row['label'] ?? null, 'child_row.label'),
                'months' => self::integerValue($row['months'] ?? null, 'child_row.months'),
                'amount_minor_units' => self::integerValue(
                    $row['amount_minor_units'] ?? null,
                    'child_row.amount_minor_units',
                ),
            ];
        }

        return $rows;
    }

    /** @return list<mixed> */
    private static function listValue(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException('Řádky ročního zúčtování nejsou seznam.');
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("Snapshot ročního zúčtování: {$field} není objekt.");
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \DomainException("Snapshot ročního zúčtování: {$field} má neplatný klíč.");
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function childRowsSnapshot(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        array $rows,
    ): array {
        if ($rows === []) {
            return [];
        }
        $requestStatement = $this->db->pdo()->prepare(
            'SELECT id, other_household_caregiver_status
               FROM payroll_annual_settlement_requests
              WHERE supplier_id = ? AND employee_id = ? AND tax_year = ?
              FOR UPDATE',
        );
        $requestStatement->execute([$supplierId, $employeeId, $taxYear]);
        $request = self::object(
            $requestStatement->fetch(PDO::FETCH_ASSOC),
            'request',
        );
        $caregiverStatus = self::stringValue(
            $request['other_household_caregiver_status'] ?? null,
            'other_household_caregiver_status',
        );
        if (!in_array($caregiverStatus, ['none', 'present'], true)) {
            throw new \DomainException(
                'Pro JMHZ chybí údaj o jiné osobě uplatňující dítě.',
            );
        }
        $caregiverStatement = $this->db->pdo()->prepare(
            'SELECT given_name, family_name, birth_date, months_mask
               FROM payroll_annual_settlement_other_caregivers
              WHERE supplier_id = ? AND request_id = ?
              ORDER BY position, id
              FOR UPDATE',
        );
        $caregiverStatement->execute([
            $supplierId,
            self::positiveIntValue($request['id'] ?? null, 'request.id'),
        ]);
        $caregivers = [];
        while (($fetched = $caregiverStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $caregiver = self::object($fetched, 'caregiver');
            $caregivers[] = [
                'identity' => [
                    'given_name' => trim(self::stringValue($caregiver['given_name'] ?? null, 'caregiver.given_name')),
                    'family_name' => trim(self::stringValue($caregiver['family_name'] ?? null, 'caregiver.family_name')),
                    'birth_date' => self::stringValue($caregiver['birth_date'] ?? null, 'caregiver.birth_date'),
                    'birth_number' => null,
                ],
                'months_mask' => self::stringValue($caregiver['months_mask'] ?? null, 'caregiver.months_mask'),
            ];
        }
        $hasOtherCaregiver = $caregiverStatus === 'present';
        if ($hasOtherCaregiver !== ($caregivers !== [])) {
            throw new \DomainException(
                'Evidence jiné osoby uplatňující dítě není úplná.',
            );
        }

        $result = [];
        foreach ($rows as $row) {
            $reference = $row['child_reference'] ?? null;
            if (!is_string($reference)
                || preg_match('/^dependant-([1-9][0-9]*)$/D', $reference, $match) !== 1
            ) {
                throw new \DomainException(
                    'Řádek dítěte v ročním zúčtování nemá platnou identitu.',
                );
            }
            $dependantId = (int) $match[1];
            $statement = $this->db->pdo()->prepare(
                'SELECT id, given_name, family_name, birth_date
                   FROM payroll_dependants
                  WHERE supplier_id = ? AND employee_id = ? AND id = ?
                  FOR UPDATE',
            );
            $statement->execute([$supplierId, $employeeId, $dependantId]);
            $dependant = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($dependant)) {
                throw new \DomainException(
                    'Pro roční zúčtování chybí uplatněné dítě.',
                );
            }
            $claimedMonths = self::monthVector($row['claimed_months'] ?? null);
            $ztpMonths = self::monthVector($row['ztp_p_claimed_months'] ?? null);
            $order = self::positiveIntValue($row['order'] ?? null, 'child.order');
            if ($order < 1 || $order > 20 || $claimedMonths === []) {
                throw new \DomainException(
                    'Roční zúčtování nemá přesný měsíční vektor dítěte.',
                );
            }
            $row['given_name'] = is_string($dependant['given_name'] ?? null)
                ? trim((string) $dependant['given_name'])
                : null;
            $row['family_name'] = is_string($dependant['family_name'] ?? null)
                ? trim((string) $dependant['family_name'])
                : null;
            $row['birth_date'] = self::stringValue(
                $dependant['birth_date'] ?? null,
                'child.birth_date',
            );
            if ($row['given_name'] === '' || $row['family_name'] === '') {
                throw new \DomainException(
                    'Uplatněné dítě nemá jméno potřebné pro JMHZ.',
                );
            }
            $row['birth_number'] = null;
            $row['ztp_p_months_mask'] = self::yesNoMask($ztpMonths);
            $row['order_months_mask'] = self::orderMask($claimedMonths, $order);
            $row['other_household_caregiver'] = $hasOtherCaregiver;
            $row['other_household_caregivers'] = $caregivers;
            $result[] = $row;
        }

        return $result;
    }

    private static function stringValue(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \DomainException("Snapshot ročního zúčtování: {$field} není text.");
        }

        return $value;
    }

    private static function positiveIntValue(mixed $value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int) $value;
        }
        throw new \DomainException("Snapshot ročního zúčtování: {$field} není kladné celé číslo.");
    }

    private static function integerValue(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new \DomainException("Snapshot ročního zúčtování: {$field} není celé číslo.");
    }

    /** @return list<int> */
    private static function monthVector(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        $months = [];
        foreach ($value as $month) {
            if (!is_int($month) || $month < 1 || $month > 12 || isset($months[$month])) {
                return [];
            }
            $months[$month] = true;
        }
        ksort($months);

        return array_keys($months);
    }

    /** @param list<int> $months */
    private static function yesNoMask(array $months): string
    {
        $set = array_fill_keys($months, true);

        return implode('', array_map(
            static fn (int $month): string => isset($set[$month]) ? 'A' : 'N',
            range(1, 12),
        ));
    }

    /** @param list<int> $months */
    private static function orderMask(array $months, int $order): string
    {
        $set = array_fill_keys($months, true);
        $value = (string) min(3, $order);

        return implode('', array_map(
            static fn (int $month): string => isset($set[$month]) ? $value : 'N',
            range(1, 12),
        ));
    }

    /** @return array<string,string> */
    private function profileSnapshot(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): array {
        $from = sprintf('%04d-01-01', $taxYear);
        $to = sprintf('%04d-12-31', $taxYear);

        $employee = $this->db->pdo()->prepare(
            'SELECT birth_date FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $employee->execute([$supplierId, $employeeId]);
        $employeeRow = $employee->fetch(PDO::FETCH_ASSOC);
        if (!is_array($employeeRow)) {
            throw new \DomainException('Zaměstnanec ročního zúčtování neexistuje.');
        }

        $identity = $this->db->pdo()->prepare(
            'SELECT full_name FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1
              FOR UPDATE'
        );
        $identity->execute([$supplierId, $employeeId, $to, $from]);
        $name = $identity->fetchColumn();
        if (!is_string($name) || trim($name) === '') {
            throw new \DomainException(
                'Pro roční zúčtování chybí účinná historie jména zaměstnance.',
            );
        }

        $identifier = $this->db->pdo()->prepare(
            'SELECT id, value_ciphertext
               FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ?
                AND identifier_type = "birth_number"
              LIMIT 1
              FOR UPDATE'
        );
        $identifier->execute([$supplierId, $employeeId]);
        $identifierRow = $identifier->fetch(PDO::FETCH_ASSOC);
        if (is_array($identifierRow)) {
            $identifierRow = self::object($identifierRow, 'employee.identifier');
            $label = 'Rodné číslo';
            $value = $this->sensitiveData->reveal(
                self::stringValue(
                    $identifierRow['value_ciphertext'] ?? null,
                    'employee.identifier.value_ciphertext',
                ),
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $supplierId,
                self::positiveIntValue($identifierRow['id'] ?? null, 'employee.identifier.id'),
                PayrollRevealPurpose::DOCUMENT_ANNUAL_SETTLEMENT,
            );
        } elseif (is_string($employeeRow['birth_date'] ?? null)) {
            $label = 'Datum narození';
            $value = (new \DateTimeImmutable(
                (string) $employeeRow['birth_date'],
            ))->format('d.m.Y');
        } else {
            throw new \DomainException(
                'Pro roční zúčtování chybí rodné číslo i datum narození.',
            );
        }

        return [
            'name' => trim($name),
            'identifier_label' => $label,
            'identifier_value' => $value,
        ];
    }

    /** @return array<string,string> */
    private function employerSnapshot(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COALESCE(NULLIF(display_name, ""), company_name) AS name,
                    ic, street, zip, city
               FROM supplier
              WHERE id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \DomainException('Zaměstnavatel ročního zúčtování neexistuje.');
        }
        foreach (['name', 'ic', 'street', 'zip', 'city'] as $field) {
            if (!is_string($row[$field] ?? null) || trim((string) $row[$field]) === '') {
                throw new \DomainException(
                    "Zaměstnavatel nemá pro roční zúčtování vyplněné pole {$field}.",
                );
            }
        }

        return [
            'name' => trim((string) $row['name']),
            'identification_number' => trim((string) $row['ic']),
            'address' => trim((string) $row['street'])
                . ', '
                . trim((string) $row['zip'] . ' ' . (string) $row['city']),
        ];
    }

    private function encryptionContext(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        string $manifestHash,
    ): string {
        return implode(':', [
            'payroll-annual-document',
            (string) $supplierId,
            (string) $employeeId,
            (string) $taxYear,
            self::PURPOSE,
            $manifestHash,
        ]);
    }

    private function snapshotFingerprint(string $snapshotJson, int $supplierId): string
    {
        return $this->sensitiveData->keyedFingerprint(
            $snapshotJson,
            self::SNAPSHOT_FINGERPRINT_DOMAIN,
            $supplierId,
        );
    }
}
