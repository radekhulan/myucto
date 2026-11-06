<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualDocumentRepository;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorUnavailableException;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Document\AnnualSettlementSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;

/**
 * Čte roční daňové skutečnosti výhradně z jejich neměnných revizí a zmrazí
 * i negativní výsledek dotazu. Resolver pak nemusí sahat do živé evidence.
 */
final readonly class JmhzAnnualEvidenceService
{
    /**
     * Osoba u firmy ve zdaňovacím roce neměla žádný pracovní vztah, takže
     * u ní o roční zúčtování požádat nemohla (§ 38ch odst. 1 ZDP: žádá se
     * u plátce, od kterého poplatník v roce pobíral příjem). Hlášení pak nese
     * „nepožádal" bez ručního zápisu. Platí JEN pro rok, který mzdy v aplikaci
     * pokrývají celý; u firmy, která přešla v průběhu nebo bez převzaté
     * historie, chybějící vztah nic nedokazuje.
     */
    public const PROOF_NO_EMPLOYMENT_IN_TAX_YEAR = 'request_impossible_no_employment_in_tax_year';

    public function __construct(
        private Connection $db,
        private PayrollAnnualSettlementRepository $settlements,
        private PayrollAnnualDocumentRepository $documents,
        private PayrollSensitiveData $sensitiveData,
        private SecretEncryption $encryption,
        private PayrollStatutoryAccumulatorRepository $accumulators,
    ) {}

    /**
     * @param list<int> $employeeIds
     * @return array<int,array<string,mixed>>
     */
    public function snapshotsForPreparation(
        int $supplierId,
        array $employeeIds,
        int $reportYear,
    ): array {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException(
                'Zmrazení negativních ročních skutečností JMHZ vyžaduje aktivní transakci.',
            );
        }
        $taxYear = $reportYear - 1;
        $withoutTaxYearEmployment = $this->yearCoveredByPayroll($supplierId, $taxYear)
            ? array_diff(
                array_values(array_unique($employeeIds)),
                $this->employeesEmployedInYear($supplierId, $employeeIds, $taxYear),
            )
            : [];
        // Úhrn měsíčních bonusů za minulý rok (10577) vykazuje až verze 2027.
        $withBonusTotal = JmhzSpecVersion::forPeriod($reportYear, 1) === JmhzSpecVersion::V2027;
        $snapshots = [];
        foreach (array_values(array_unique($employeeIds)) as $employeeId) {
            if ($employeeId <= 0) {
                throw new \InvalidArgumentException('Identita zaměstnance pro roční údaje JMHZ není platná.');
            }
            $request = $this->settlements->findRequest(
                $supplierId,
                $employeeId,
                $taxYear,
                true,
            );
            $outcome = $this->settlements->findOutcome(
                $supplierId,
                $employeeId,
                $taxYear,
                true,
            );
            if ($outcome === null
                && $this->documents->latest(
                    $supplierId,
                    $employeeId,
                    $taxYear,
                    AnnualSettlementSnapshotBuilder::PURPOSE,
                ) !== null
            ) {
                throw new \DomainException(
                    'Neměnná revize ročního zúčtování nemá odpovídající rejstříkový výsledek.',
                );
            }
            $settlement = $this->settlementSnapshot(
                $supplierId,
                $employeeId,
                $taxYear,
                $outcome,
            );
            $withholding = $this->certificateSnapshot(
                $supplierId,
                $employeeId,
                $taxYear,
            );
            $snapshots[$employeeId] = [
                'tax_year' => $taxYear,
                'request' => $request === null ? null : [
                    'id' => $this->positiveInt($request, 'id'),
                    'row_version' => $this->positiveInt($request, 'row_version'),
                    'status' => $this->text($request, 'request_status'),
                    'requested_on' => $this->nullableText($request['requested_on'] ?? null),
                    'annual_claims' => $this->text($request, 'annual_claims'),
                    'evidence_sha256' => $this->requestEvidenceHash(
                        $request['request_evidence_reference'] ?? null,
                        $supplierId,
                    ),
                ],
                'request_evidence' => [
                    'present' => $request !== null,
                    'proof' => match (true) {
                        $request !== null => 'verified_request_row_under_unique_key_lock',
                        in_array($employeeId, $withoutTaxYearEmployment, true)
                            => self::PROOF_NO_EMPLOYMENT_IN_TAX_YEAR,
                        default => 'request_absent_under_unique_key_lock',
                    },
                    'supplier_id' => $supplierId,
                    'employee_id' => $employeeId,
                    'tax_year' => $taxYear,
                ],
                'settlement' => $settlement,
                'settlement_evidence' => $outcome === null
                    ? [
                        'performed' => false,
                        'proof' => 'outcome_absent_under_unique_key_lock',
                        'supplier_id' => $supplierId,
                        'employee_id' => $employeeId,
                        'tax_year' => $taxYear,
                    ]
                    : [
                        'performed' => true,
                        'proof' => 'verified_annual_outcome_and_document_revision',
                        'supplier_id' => $supplierId,
                        'employee_id' => $employeeId,
                        'tax_year' => $taxYear,
                    ],
                'withholding_certificate' => $withholding,
                'withholding_certificate_evidence' => [
                    'present' => $withholding !== null,
                    'proof' => $withholding === null
                        ? 'annual_revision_absent_under_unique_key_lock'
                        : 'verified_annual_document_revision',
                    'supplier_id' => $supplierId,
                    'employee_id' => $employeeId,
                    'tax_year' => $taxYear,
                ],
                ...($withBonusTotal
                    ? ['monthly_bonus_total_minor_units' => $this->monthlyBonusTotal($supplierId, $employeeId, $taxYear)]
                    : []),
            ];
        }
        ksort($snapshots, SORT_NUMERIC);

        return $snapshots;
    }

    /**
     * Úhrn měsíčních daňových bonusů za zdaňovací rok (JMHZ 10577, jen leden
     * verze 2027) z roční zákonné kumulace daně: počáteční stav (převzatá část
     * roku) plus schválené měsíce. Je to týž úhrn, který roční zúčtování bere
     * jako vyplacené měsíční bonusy (`total_monthly_tax_bonus_minor_units`).
     * Bez kumulace roku (chybí počáteční stav) `null`; resolver pak hlášení
     * zastaví, pokud osoba v roce u firmy pracovala.
     */
    private function monthlyBonusTotal(int $supplierId, int $employeeId, int $taxYear): ?int
    {
        try {
            $state = $this->accumulators->stateForYear($supplierId, $employeeId, $taxYear, 'income_tax');
        } catch (PayrollStatutoryAccumulatorUnavailableException) {
            return null;
        }
        $total = $state['totals']['tax_bonus_minor_units'] ?? null;

        return is_int($total) ? $total : null;
    }

    /**
     * Mzdy v aplikaci pokrývají celý zdaňovací rok: agenda se vede nejpozději
     * od 1. ledna, nebo je od ledna převzatá historie z předchozího programu.
     */
    private function yearCoveredByPayroll(int $supplierId, int $taxYear): bool
    {
        $yearStart = sprintf('%04d-01-01', $taxYear);
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (
                        SELECT 1 FROM payroll_module_state
                         WHERE supplier_id = ? AND start_period <= ?
                    ) OR EXISTS (
                        SELECT 1 FROM payroll_takeover_runs
                         WHERE supplier_id = ? AND period_start = ?
                    )',
        );
        $stmt->execute([$supplierId, $yearStart, $supplierId, $yearStart]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Osoby, které měly ve zdaňovacím roce aspoň jeden den pracovního vztahu.
     *
     * @param list<int> $employeeIds
     * @return list<int>
     */
    private function employeesEmployedInYear(int $supplierId, array $employeeIds, int $taxYear): array
    {
        $ids = array_values(array_unique(array_filter($employeeIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT employee_id
               FROM payroll_employments
              WHERE supplier_id = ?
                AND employee_id IN (' . $placeholders . ')
                AND LEAST(start_date, COALESCE(actual_start_date, start_date)) <= ?
                AND (end_date IS NULL OR end_date >= ?)',
        );
        $stmt->execute([
            $supplierId,
            ...$ids,
            sprintf('%04d-12-31', $taxYear),
            sprintf('%04d-01-01', $taxYear),
        ]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string,mixed>|null $outcome
     * @return array<string,mixed>|null
     */
    private function settlementSnapshot(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        ?array $outcome,
    ): ?array {
        if ($outcome === null) {
            return null;
        }
        $revisionId = $this->positiveInt($outcome, 'annual_revision_id');
        $revision = $this->documents->find($supplierId, $revisionId);
        if ($revision === null
            || ($revision['employee_id'] ?? null) !== $employeeId
            || ($revision['tax_year'] ?? null) !== $taxYear
            || ($revision['purpose'] ?? null) !== AnnualSettlementSnapshotBuilder::PURPOSE
        ) {
            throw new \DomainException('Výsledek ročního zúčtování neodkazuje na platnou neměnnou revizi.');
        }
        $snapshot = $this->decrypt(
            $revision,
            AnnualSettlementSnapshotBuilder::SNAPSHOT_FINGERPRINT_DOMAIN,
        );
        if (($snapshot['schema_version'] ?? null) !== AnnualSettlementSnapshotBuilder::SCHEMA_VERSION
            || ($snapshot['tax_year'] ?? null) !== $taxYear
        ) {
            throw new \DomainException('Neměnná revize ročního zúčtování má nepodporovaný obsah.');
        }
        $result = $this->object($snapshot['result'] ?? null, 'result');
        if (($result['performed'] ?? null) !== true) {
            throw new \DomainException('Rejstřík provedeného zúčtování odkazuje na neprovedený výsledek.');
        }
        foreach ([
            'tax_difference_minor' => 'tax_difference_minor_units',
            'bonus_difference_minor' => 'bonus_difference_minor_units',
            'settlement_difference_minor' => 'settlement_difference_minor_units',
        ] as $outcomeKey => $resultKey) {
            if (!is_int($outcome[$outcomeKey] ?? null)
                || ($result[$resultKey] ?? null) !== $outcome[$outcomeKey]
            ) {
                throw new \DomainException('Rejstřík a neměnná revize ročního zúčtování si odporují.');
            }
        }
        $settledOn = $this->text($snapshot, 'settled_on');
        if ($settledOn !== $this->text($outcome, 'settled_on')) {
            throw new \DomainException('Datum v rejstříku a revizi ročního zúčtování si odporuje.');
        }

        return [
            'revision_id' => $revisionId,
            'snapshot_hash' => $this->hash($revision, 'snapshot_hash'),
            'settled_on' => $settledOn,
            'performed' => true,
            'tax_difference_minor_units' => $result['tax_difference_minor_units'],
            'bonus_difference_minor_units' => $result['bonus_difference_minor_units'],
            'settlement_difference_minor_units' => $result['settlement_difference_minor_units'],
            'credit_rows' => $this->rows($snapshot['credit_rows'] ?? null, 'credit_rows'),
            'child_rows' => $this->rows($snapshot['child_rows'] ?? null, 'child_rows'),
            // Sleva na manžela (10420, blok slevaNaPartnera). Revize bez ní
            // klíč nemají — `null` je „neuplatněna", ne „nevíme".
            'spouse_row' => isset($snapshot['spouse_row'])
                ? $this->object($snapshot['spouse_row'], 'spouse_row')
                : null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function certificateSnapshot(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): ?array {
        $purpose = PayrollDocumentKind::TaxableIncomeWithholdingCertificate->value;
        $revision = $this->documents->latest($supplierId, $employeeId, $taxYear, $purpose);
        if ($revision === null) {
            return null;
        }
        $snapshot = $this->decrypt($revision, 'annual-tax-certificate-snapshot-v1');
        if (($snapshot['schema_version'] ?? null) !== AnnualTaxCertificateSnapshotBuilder::SCHEMA_VERSION
            || ($snapshot['mapping_version'] ?? null) !== AnnualTaxCertificateSnapshotBuilder::MAPPING_VERSION
            || ($snapshot['purpose'] ?? null) !== $purpose
            || ($snapshot['tax_year'] ?? null) !== $taxYear
        ) {
            throw new \DomainException('Neměnná revize potvrzení o srážkové dani má nepodporovaný obsah.');
        }

        return [
            'revision_id' => $this->positiveInt($revision, 'id'),
            'snapshot_hash' => $this->hash($revision, 'snapshot_hash'),
            'paid_income_minor_units' => $this->nonNegativeInt(
                $snapshot,
                'paid_income_minor_units',
            ),
            'withholding_tax_minor_units' => $this->nonNegativeInt(
                $snapshot,
                'withholding_tax_minor_units',
            ),
        ];
    }

    /** @param array<string,mixed> $revision
     *  @return array<string,mixed>
     */
    private function decrypt(array $revision, string $fingerprintDomain): array
    {
        $supplierId = $this->positiveInt($revision, 'supplier_id');
        $json = $this->encryption->decryptFor(
            $this->text($revision, 'snapshot_ciphertext'),
            implode(':', [
                'payroll-annual-document',
                (string) $supplierId,
                (string) $this->positiveInt($revision, 'employee_id'),
                (string) $this->positiveInt($revision, 'tax_year'),
                $this->text($revision, 'purpose'),
                $this->hash($revision, 'source_manifest_hash'),
            ]),
        );
        if (!hash_equals(
            $this->hash($revision, 'snapshot_hash'),
            $this->sensitiveData->keyedFingerprint($json, $fingerprintDomain, $supplierId),
        )) {
            throw new \DomainException('Otisk neměnného ročního zdroje JMHZ nesouhlasí.');
        }
        $snapshot = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || array_is_list($snapshot)) {
            throw new \DomainException('Neměnný roční zdroj JMHZ není objekt.');
        }

        return $snapshot;
    }

    private function requestEvidenceHash(mixed $value, int $supplierId): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \DomainException('Odkaz důkazu žádosti o roční zúčtování není platný.');
        }

        return $this->sensitiveData->keyedFingerprint(
            $value,
            'jmhz-annual-request-evidence-v1',
            $supplierId,
        );
    }

    /** @param array<string,mixed> $source */
    private function positiveInt(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!is_int($value) || $value <= 0) {
            throw new \DomainException("Roční zdroj JMHZ nemá platné pole {$key}.");
        }

        return $value;
    }

    /** @param array<string,mixed> $source */
    private function nonNegativeInt(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new \DomainException("Roční zdroj JMHZ nemá platné pole {$key}.");
        }

        return $value;
    }

    /** @param array<string,mixed> $source */
    private function text(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException("Roční zdroj JMHZ nemá platné pole {$key}.");
        }

        return $value;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException('Roční zdroj JMHZ má neplatné volitelné textové pole.');
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("Roční zdroj JMHZ nemá platný objekt {$field}.");
        }

        return $value;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException("Roční zdroj JMHZ nemá platný seznam {$field}.");
        }
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \DomainException("Roční zdroj JMHZ nemá platný řádek {$field}.");
            }
        }

        return $value;
    }

    /** @param array<string,mixed> $source */
    private function hash(array $source, string $key): string
    {
        $value = $this->text($source, $key);
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \DomainException("Roční zdroj JMHZ nemá platný otisk {$key}.");
        }

        return $value;
    }
}
