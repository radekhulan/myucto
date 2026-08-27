<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * MZ-14-W08 — čtecí vrstva mezi exekučním ledgerem a platebním ledgerem MZ-17.
 *
 * Sražené, deponované a odeslané částky se nikdy nesčítají do jednoho čísla:
 * `withheld` a `held` pocházejí z neměnného exekučního ledgeru, „odesláno"
 * vzniká výhradně z platebních závazků a jejich potvrzených úhrad. Zaplacení
 * je proto jiná událost než sražení a snižuje zůstatek pohledávky až tehdy,
 * kdy je skutečně spárováno s bankou nebo pokladnou.
 */
final class PayrollEnforcementPaymentRepository
{
    public const LIABILITY_KIND = 'enforcement';

    public function __construct(private readonly Connection $db) {}

    public static function liabilityReference(int $caseId, int $claimId): string
    {
        if ($caseId <= 0 || $claimId <= 0) {
            throw new \InvalidArgumentException(
                'Reference exekučního závazku vyžaduje kladný případ a pohledávku.',
            );
        }

        return "enforcement:c{$caseId}:cl{$claimId}";
    }

    /**
     * Částky určené k odeslání za jednu revizi: `withheld` mínus `held` plus
     * doložené `released_for_remittance`. Depozitum tak do odchozí dávky
     * nepronikne dřív, než je append-only uvolněné konkrétním rozhodnutím.
     *
     * @return list<array{
     *   case_id:int,
     *   claim_id:int,
     *   remittable_minor:int,
     *   case_status:string,
     *   recipient_verified:bool,
     *   recipient_institution_id:?int,
     *   institution_type:?string,
     *   institution_code:?string,
     *   claim_category:string,
     *   claim_priority_date:?string,
     *   release_decision_event_id:?int,
     *   release_decision_document_id:?int,
     *   release_decision_evidence_hash:?string
     * }>
     */
    public function remittableForRevision(
        int $supplierId,
        int $revisionId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            "SELECT ledger.case_id, ledger.claim_id,
                    SUM(
                      CASE
                        WHEN ledger.entry_kind = 'withheld'
                          THEN ledger.amount_minor_units
                        WHEN ledger.entry_kind = 'held'
                          THEN -ledger.amount_minor_units
                        WHEN ledger.entry_kind = 'released_for_remittance'
                          THEN ledger.amount_minor_units
                        ELSE 0
                      END
                    ) AS remittable_minor,
                    enforcement_case.status AS case_status,
                    enforcement_case.recipient_verified,
                    enforcement_case.recipient_institution_id,
                    institution.institution_type,
                    institution.institution_code,
                    claim.category AS claim_category,
                    claim.priority_date AS claim_priority_date,
                    MAX(CASE WHEN ledger.entry_kind = 'released_for_remittance'
                      THEN ledger.decision_event_id END)
                        AS release_decision_event_id,
                    MAX(CASE WHEN ledger.entry_kind = 'released_for_remittance'
                      THEN decision_event.decision_document_id END)
                        AS release_decision_document_id,
                    MAX(CASE WHEN ledger.entry_kind = 'released_for_remittance'
                      THEN decision_event.decision_evidence_hash END)
                        AS release_decision_evidence_hash
               FROM payroll_enforcement_ledger ledger
               JOIN payroll_enforcement_month_results month_result
                 ON month_result.supplier_id = ledger.supplier_id
                AND month_result.id = ledger.month_result_id
               JOIN payroll_enforcement_cases enforcement_case
                 ON enforcement_case.supplier_id = ledger.supplier_id
                AND enforcement_case.id = ledger.case_id
               JOIN payroll_enforcement_claims claim
                 ON claim.supplier_id = ledger.supplier_id
                AND claim.id = ledger.claim_id
               LEFT JOIN payroll_institutions institution
                 ON institution.supplier_id = enforcement_case.supplier_id
                AND institution.id =
                    enforcement_case.recipient_institution_id
               LEFT JOIN payroll_enforcement_events decision_event
                 ON decision_event.supplier_id = ledger.supplier_id
                AND decision_event.id = ledger.decision_event_id
              WHERE ledger.supplier_id = ?
                AND month_result.revision_id = ?
                AND ledger.case_id IS NOT NULL
                AND ledger.claim_id IS NOT NULL
                AND ledger.entry_kind IN (
                  'withheld', 'held', 'released_for_remittance'
                )
              GROUP BY ledger.case_id, ledger.claim_id,
                       enforcement_case.status,
                       enforcement_case.recipient_verified,
                       enforcement_case.recipient_institution_id,
                       institution.institution_type,
                       institution.institution_code,
                       claim.category, claim.priority_date
              ORDER BY ledger.case_id, ledger.claim_id"
        );
        $statement->execute([$supplierId, $revisionId]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $value) {
            $row = self::row($value, 'exekuční ledger revize');
            $result[] = [
                'case_id' => self::integer($row, 'case_id'),
                'claim_id' => self::integer($row, 'claim_id'),
                'remittable_minor' => self::integer(
                    $row,
                    'remittable_minor',
                ),
                'case_status' => self::string($row, 'case_status'),
                'recipient_verified' => self::integer(
                    $row,
                    'recipient_verified',
                ) === 1,
                'recipient_institution_id' => self::nullableInteger(
                    $row,
                    'recipient_institution_id',
                ),
                'institution_type' => self::nullableString(
                    $row,
                    'institution_type',
                ),
                'institution_code' => self::nullableString(
                    $row,
                    'institution_code',
                ),
                'claim_category' => self::string($row, 'claim_category'),
                'claim_priority_date' => self::nullableString(
                    $row,
                    'claim_priority_date',
                ),
                'release_decision_event_id' => self::nullableInteger(
                    $row,
                    'release_decision_event_id',
                ),
                'release_decision_document_id' => self::nullableInteger(
                    $row,
                    'release_decision_document_id',
                ),
                'release_decision_evidence_hash' => self::nullableString(
                    $row,
                    'release_decision_evidence_hash',
                ),
            ];
        }

        return $result;
    }

    /**
     * The payment target is never inferred from the case's legacy institution
     * pointer. This returns only the latest documented recipient instruction
     * effective for the particular payroll payment date.
     *
     * @return array{recipient_party_id:int,payment_account_id:int,institution_type:string,
     *   institution_code:string,authority_current:bool,beneficiary_current:bool,
     *   recipient_party_current:bool,source_document_id:int,source_document_sha256:string}|null
     */
    public function documentedRecipientForPayment(
        int $supplierId,
        int $caseId,
        string $effectiveOn,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT instruction.recipient_party_id, instruction.payment_account_id,
                    institution.institution_type, institution.institution_code,
                    instruction.source_document_id,
                    instruction.source_document_sha256,
                    EXISTS(
                      SELECT 1 FROM payroll_enforcement_case_parties authority
                       WHERE authority.supplier_id = instruction.supplier_id
                         AND authority.case_id = instruction.case_id
                         AND authority.party_role IN ("court", "executor")
                         AND authority.effective_from <= ?
                    ) AS authority_current,
                    EXISTS(
                      SELECT 1 FROM payroll_enforcement_case_parties beneficiary
                       WHERE beneficiary.supplier_id = instruction.supplier_id
                         AND beneficiary.case_id = instruction.case_id
                         AND beneficiary.party_role = "beneficiary"
                         AND beneficiary.effective_from <= ?
                    ) AS beneficiary_current,
                    NOT EXISTS(
                      SELECT 1 FROM payroll_enforcement_case_parties newer
                       WHERE newer.supplier_id = party.supplier_id
                         AND newer.case_id = party.case_id
                         AND newer.party_role = party.party_role
                         AND newer.effective_from <= ?
                         AND newer.revision_no > party.revision_no
                    ) AS recipient_party_current
               FROM payroll_enforcement_recipient_instructions instruction
               JOIN payroll_enforcement_case_parties party
                 ON party.supplier_id = instruction.supplier_id
                AND party.id = instruction.recipient_party_id
               JOIN payroll_institution_accounts account
                 ON account.supplier_id = instruction.supplier_id
                AND account.id = instruction.payment_account_id
               JOIN payroll_institutions institution
                 ON institution.supplier_id = account.supplier_id
                AND institution.id = account.institution_id
              WHERE instruction.supplier_id = ? AND instruction.case_id = ?
                AND instruction.effective_from <= ?
              ORDER BY instruction.effective_from DESC, instruction.revision_no DESC
              LIMIT 1',
        );
        $stmt->execute([
            $effectiveOn,
            $effectiveOn,
            $effectiveOn,
            $supplierId,
            $caseId,
            $effectiveOn,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'recipient_party_id' => self::integer($row, 'recipient_party_id'),
            'payment_account_id' => self::integer($row, 'payment_account_id'),
            'institution_type' => self::string($row, 'institution_type'),
            'institution_code' => self::string($row, 'institution_code'),
            'authority_current' => self::integer($row, 'authority_current') === 1,
            'beneficiary_current' => self::integer($row, 'beneficiary_current') === 1,
            'recipient_party_current' => self::integer($row, 'recipient_party_current') === 1,
            'source_document_id' => self::integer($row, 'source_document_id'),
            'source_document_sha256' => self::string($row, 'source_document_sha256'),
        ];
    }

    /**
     * Rozpad případu na „sraženo / deponováno / odesláno / zbývá".
     *
     * @return list<array{
     *   claim_id:int,
     *   category:string,
     *   priority_date:?string,
     *   is_active:bool,
     *   original_minor:int,
     *   outstanding_minor:int,
     *   withheld_minor:int,
     *   held_minor:int,
     *   liability_minor:int,
     *   settled_minor:int,
     *   remaining_to_withhold_minor:int,
     *   remaining_minor:int
     * }>
     */
    public function settlementForCase(int $supplierId, int $caseId): array
    {
        $statement = $this->db->pdo()->prepare(
            "WITH ledger_totals AS (
                SELECT ledger.claim_id,
                       SUM(
                         CASE WHEN ledger.entry_kind = 'withheld'
                           THEN ledger.amount_minor_units ELSE 0 END
                       ) AS withheld_minor,
                       SUM(
                         CASE WHEN ledger.entry_kind = 'released_to_employee'
                           THEN ledger.amount_minor_units ELSE 0 END
                       ) AS returned_minor,
                       SUM(
                         CASE WHEN ledger.entry_kind = 'adjustment'
                           THEN ledger.amount_minor_units ELSE 0 END
                       ) AS adjustment_minor,
                       GREATEST(0,
                         SUM(CASE WHEN ledger.entry_kind = 'held'
                           THEN ledger.amount_minor_units ELSE 0 END)
                         - SUM(CASE WHEN ledger.entry_kind = 'released_to_employee'
                           THEN ledger.amount_minor_units ELSE 0 END)
                         - GREATEST(
                             SUM(CASE
                               WHEN ledger.entry_kind = 'released_for_remittance'
                               THEN ledger.amount_minor_units ELSE 0 END),
                             SUM(CASE WHEN ledger.entry_kind = 'remitted'
                               THEN ledger.amount_minor_units ELSE 0 END)
                           )
                       ) AS held_minor
                  FROM payroll_enforcement_ledger ledger
                  JOIN payroll_enforcement_month_results month_result
                    ON month_result.supplier_id = ledger.supplier_id
                   AND month_result.id = ledger.month_result_id
             LEFT JOIN payroll_run_revisions revision
                    ON revision.supplier_id = month_result.supplier_id
                   AND revision.id = month_result.revision_id
             LEFT JOIN payroll_runs run
                    ON run.supplier_id = revision.supplier_id
                   AND run.id = revision.run_id
                 WHERE ledger.supplier_id = ?
                   AND ledger.case_id = ?
                   AND ledger.claim_id IS NOT NULL
                   AND ledger.entry_kind IN (
                     'withheld', 'held', 'released_for_remittance',
                     'remitted', 'released_to_employee', 'adjustment'
                   )
                   AND (
                     month_result.revision_id IS NULL
                     OR revision.revision_no = run.current_revision_no
                   )
                 GROUP BY ledger.claim_id
             ),
             payment_totals AS (
                SELECT liability.liability_reference,
                       SUM(
                         CASE WHEN liability.direction = 'outgoing'
                           THEN liability.amount_minor
                           ELSE -liability.amount_minor END
                       ) AS liability_minor,
                       SUM(
                         CASE WHEN liability.direction = 'outgoing'
                           THEN liability.settled_minor
                           ELSE -liability.settled_minor END
                       ) AS settled_minor
                  FROM (
                    SELECT inner_liability.liability_reference,
                           inner_liability.direction,
                           inner_liability.amount_minor,
                           COALESCE((
                             SELECT SUM(payment_match.amount_minor)
                               FROM payroll_payment_allocations allocation
                               JOIN payroll_payment_matches payment_match
                                 ON payment_match.supplier_id =
                                    allocation.supplier_id
                                AND payment_match.allocation_id =
                                    allocation.id
                              WHERE allocation.supplier_id =
                                    inner_liability.supplier_id
                                AND allocation.liability_id =
                                    inner_liability.id
                           ), 0) AS settled_minor
                      FROM payroll_payment_liabilities inner_liability
                     WHERE inner_liability.supplier_id = ?
                       AND inner_liability.liability_kind = 'enforcement'
                  ) AS liability
                 GROUP BY liability.liability_reference
             )
             SELECT claim.id AS claim_id, claim.category, claim.priority_date,
                    claim.is_active, claim.outstanding_minor_units,
                    COALESCE(ledger_totals.withheld_minor, 0) AS withheld_minor,
                    COALESCE(ledger_totals.returned_minor, 0) AS returned_minor,
                    COALESCE(ledger_totals.adjustment_minor, 0) AS adjustment_minor,
                    COALESCE(ledger_totals.held_minor, 0) AS held_minor,
                    COALESCE(payment_totals.liability_minor, 0)
                      AS liability_minor,
                    COALESCE(payment_totals.settled_minor, 0) AS settled_minor
               FROM payroll_enforcement_claims claim
          LEFT JOIN ledger_totals
                 ON ledger_totals.claim_id = claim.id
          LEFT JOIN payment_totals
                 ON payment_totals.liability_reference =
                    CONCAT('enforcement:c', claim.case_id, ':cl', claim.id)
              WHERE claim.supplier_id = ? AND claim.case_id = ?
              ORDER BY claim.priority_date, claim.id"
        );
        $statement->execute([
            $supplierId,
            $caseId,
            $supplierId,
            $supplierId,
            $caseId,
        ]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $value) {
            $row = self::row($value, 'vyúčtování exekuční pohledávky');
            $outstanding = self::integer($row, 'outstanding_minor_units');
            $withheld = self::integer($row, 'withheld_minor');
            $returned = self::integer($row, 'returned_minor');
            $adjustment = self::integer($row, 'adjustment_minor');
            $settled = self::integer($row, 'settled_minor');
            $result[] = [
                'claim_id' => self::integer($row, 'claim_id'),
                'category' => self::string($row, 'category'),
                'priority_date' => self::nullableString(
                    $row,
                    'priority_date',
                ),
                'is_active' => self::integer($row, 'is_active') === 1,
                'original_minor' => $outstanding,
                'outstanding_minor' => $outstanding,
                'withheld_minor' => $withheld,
                'held_minor' => self::integer($row, 'held_minor'),
                'liability_minor' => self::integer($row, 'liability_minor'),
                'settled_minor' => $settled,
                'remaining_to_withhold_minor' => max(
                    0,
                    $outstanding - $withheld - $adjustment + $returned,
                ),
                'remaining_minor' => max(0, $outstanding - $settled),
            ];
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není celé číslo.",
            );
        }
        $normalized = filter_var($value, FILTER_VALIDATE_INT);
        if ($normalized === false) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není celé číslo.",
            );
        }

        return $normalized;
    }

    /** @param array<string,mixed> $row */
    private static function nullableInteger(array $row, string $field): ?int
    {
        return ($row[$field] ?? null) === null
            ? null
            : self::integer($row, $field);
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není neprázdný text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableString(array $row, string $field): ?string
    {
        return ($row[$field] ?? null) === null
            ? null
            : self::string($row, $field);
    }

    /** @return array<string,mixed> */
    private static function row(mixed $value, string $context): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatný {$context}.",
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    "Databázový {$context} nemá textové klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
