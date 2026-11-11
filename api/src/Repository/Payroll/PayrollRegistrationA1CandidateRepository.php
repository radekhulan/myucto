<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Podklady pro hromadnou přípravu přihlášek REGZEC A1.
 *
 * Dotaz jen sbírá fakta o vztazích firmy (ID PPV, živé základní podání,
 * registrace předchozím programem, stav profilu A1, souběžná DPP s ID PPV).
 * O tom, jestli vztah přihlášku potřebuje, rozhoduje
 * {@see \MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1BatchService::exclusionReason()}
 * — jedno pravidlo pro seznam i pro hromadnou přípravu.
 *
 * Živé základní podání se hledá stejně jako u karty vztahu
 * ({@see PayrollRegistrationSubmissionRepository::liveBaseRegistration()}):
 * část podání vázaná na vztah, agenda PREZEC26/REGZEC25, stav mimo
 * {@see PayrollRegistrationSubmissionRepository::BASE_REGISTRATION_CLOSED_STATUSES}.
 */
final class PayrollRegistrationA1CandidateRepository
{
    /** Stavy vztahu, které se jako souběžná otevřená DPP nepočítají. */
    public const CLOSED_EMPLOYMENT_STATUSES = ['ended', 'archived', 'no_show'];

    /**
     * Stavy vztahu, za které se přihláška nepodává. Skončený vztah mezi nimi
     * není: když skončil dřív, než byl přihlášen (typicky brigáda na DPP,
     * kterou noční úloha po sjednaném konci ukončila), A1 se podává i tak,
     * s datem skončení. Přihlášený vztah (ID PPV) vyřadí exclusionReason().
     */
    public const NOT_REGISTERABLE_EMPLOYMENT_STATUSES = ['archived', 'no_show'];

    public function __construct(private readonly Connection $db) {}

    /**
     * Vztahy firmy, které v období od–do trvají (nebo teprve začnou), případně
     * jen vybrané vztahy bez ohledu na období.
     *
     * @param list<int>|null $employmentIds
     * @return list<array{
     *   employment_id:int,employee_id:int,employee_name:string,code:string,
     *   relation_type:string,status:string,start_date:?string,end_date:?string,
     *   has_id_ppv:bool,profile_status:?string,
     *   live_submission_id:?int,live_agenda_code:?string,live_status:?string,
     *   predecessor_submission_id:?int,
     *   duplicate_employment_id:?int,duplicate_code:?string
     * }>
     */
    public function rows(
        int $supplierId,
        string $environment,
        ?string $from,
        ?string $to,
        ?array $employmentIds = null,
    ): array {
        $closed = PayrollRegistrationSubmissionRepository::BASE_REGISTRATION_CLOSED_STATUSES;
        $closedPlaceholders = implode(',', array_fill(0, count($closed), '?'));
        $statusPlaceholders = implode(',', array_fill(0, count(self::CLOSED_EMPLOYMENT_STATUSES), '?'));
        $baseStatusPlaceholders = implode(',', array_fill(0, count(self::NOT_REGISTERABLE_EMPLOYMENT_STATUSES), '?'));
        $where = '';
        $filter = [];
        if ($employmentIds !== null) {
            if ($employmentIds === []) {
                return [];
            }
            $where .= ' AND employment.id IN (' . implode(',', array_fill(0, count($employmentIds), '?')) . ')';
            array_push($filter, ...$employmentIds);
        }
        if ($to !== null) {
            $where .= ' AND COALESCE(employment.actual_start_date, employment.start_date) <= ?';
            $filter[] = $to;
        }
        if ($from !== null) {
            $where .= ' AND (employment.end_date IS NULL OR employment.end_date >= ?)';
            $filter[] = $from;
        }
        $statement = $this->db->pdo()->prepare(
            'WITH latest_profile AS (
                SELECT profile.employment_id, profile.status,
                       ROW_NUMBER() OVER (
                           PARTITION BY profile.employment_id
                           ORDER BY profile.row_version DESC, profile.id DESC
                       ) AS rn
                  FROM payroll_registration_a1_profiles profile
                 WHERE profile.supplier_id = ?
            ),
            predecessor AS (
                SELECT form.employment_id, MAX(submission.id) AS submission_id
                  FROM payroll_external_jmhz_submission_forms form
                  JOIN payroll_external_jmhz_submissions submission
                    ON submission.supplier_id = form.supplier_id
                   AND submission.id = form.submission_id
                 WHERE form.supplier_id = ?
                   AND submission.environment = ?
                   AND submission.document_kind = "registration"
                   AND submission.status = "sent"
                   AND form.employment_id IS NOT NULL
                   AND form.form_type = "start"
                 GROUP BY form.employment_id
            ),
            base AS (
                SELECT employment.id AS employment_id, employment.employee_id,
                       employee.full_name AS employee_name, employment.code,
                       employment.relation_type, employment.status,
                       COALESCE(employment.actual_start_date, employment.start_date)
                           AS start_date,
                       employment.end_date,
                       EXISTS (
                           SELECT 1
                             FROM payroll_employment_external_ids external_id
                            WHERE external_id.supplier_id = employment.supplier_id
                              AND external_id.environment = ?
                              AND external_id.employment_id = employment.id
                              AND external_id.identifier_type = "id_ppv"
                              AND external_id.valid_to IS NULL
                       ) AS has_id_ppv,
                       (
                           SELECT live.id
                             FROM payroll_submission_parts live_part
                             JOIN payroll_submissions live
                               ON live.supplier_id = live_part.supplier_id
                              AND live.environment = live_part.environment
                              AND live.id = live_part.submission_id
                            WHERE live_part.supplier_id = employment.supplier_id
                              AND live_part.environment = ?
                              AND live_part.subject_reference =
                                  CONCAT("payroll_employment:", employment.id)
                              AND live_part.agenda_code IN ("PREZEC26", "REGZEC25")
                              AND live_part.source_entity_type = "payroll_employment"
                              AND live.status NOT IN (' . $closedPlaceholders . ')
                            ORDER BY live_part.submission_id DESC
                            LIMIT 1
                       ) AS live_submission_id,
                       (
                           SELECT other.id
                             FROM payroll_employments other
                             JOIN payroll_employment_external_ids other_id
                               ON other_id.supplier_id = other.supplier_id
                              AND other_id.environment = ?
                              AND other_id.employment_id = other.id
                              AND other_id.identifier_type = "id_ppv"
                              AND other_id.valid_to IS NULL
                            WHERE other.supplier_id = employment.supplier_id
                              AND other.employee_id = employment.employee_id
                              AND other.id <> employment.id
                              AND other.relation_type = "dpp"
                              AND employment.relation_type = "dpp"
                              AND other.status NOT IN (' . $statusPlaceholders . ')
                              AND (other.end_date IS NULL
                                   OR other.end_date >= COALESCE(
                                       employment.actual_start_date,
                                       employment.start_date
                                   ))
                            ORDER BY other.id
                            LIMIT 1
                       ) AS duplicate_employment_id
                  FROM payroll_employments employment
                  JOIN payroll_employees employee
                    ON employee.supplier_id = employment.supplier_id
                   AND employee.id = employment.employee_id
                 WHERE employment.supplier_id = ?
                   AND employment.status NOT IN (' . $baseStatusPlaceholders . ')'
                   . $where . '
            )
            SELECT base.*,
                   latest_profile.status AS profile_status,
                   live.status AS live_status,
                   live_part.agenda_code AS live_agenda_code,
                   predecessor.submission_id AS predecessor_submission_id,
                   duplicate.code AS duplicate_code
              FROM base
              LEFT JOIN latest_profile
                ON latest_profile.employment_id = base.employment_id
               AND latest_profile.rn = 1
              LEFT JOIN predecessor
                ON predecessor.employment_id = base.employment_id
              LEFT JOIN payroll_submissions live
                ON live.supplier_id = ?
               AND live.environment = ?
               AND live.id = base.live_submission_id
              LEFT JOIN payroll_submission_parts live_part
                ON live_part.supplier_id = live.supplier_id
               AND live_part.environment = live.environment
               AND live_part.submission_id = live.id
               AND live_part.subject_reference =
                   CONCAT("payroll_employment:", base.employment_id)
              LEFT JOIN payroll_employments duplicate
                ON duplicate.supplier_id = ?
               AND duplicate.id = base.duplicate_employment_id
             ORDER BY base.start_date, base.employee_name, base.employment_id',
        );
        $statement->execute([
            $supplierId,
            $supplierId,
            $environment,
            $environment,
            $environment,
            ...$closed,
            $environment,
            ...self::CLOSED_EMPLOYMENT_STATUSES,
            $supplierId,
            ...self::NOT_REGISTERABLE_EMPLOYMENT_STATUSES,
            ...$filter,
            $supplierId,
            $environment,
            $supplierId,
        ]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'employment_id' => (int) $row['employment_id'],
                'employee_id' => (int) $row['employee_id'],
                'employee_name' => (string) $row['employee_name'],
                'code' => (string) $row['code'],
                'relation_type' => (string) $row['relation_type'],
                'status' => (string) $row['status'],
                'start_date' => self::nullable($row['start_date']),
                'end_date' => self::nullable($row['end_date']),
                'has_id_ppv' => (bool) $row['has_id_ppv'],
                'profile_status' => self::nullable($row['profile_status']),
                'live_submission_id' => self::nullableInt($row['live_submission_id']),
                'live_agenda_code' => self::nullable($row['live_agenda_code']),
                'live_status' => self::nullable($row['live_status']),
                'predecessor_submission_id' => self::nullableInt($row['predecessor_submission_id']),
                'duplicate_employment_id' => self::nullableInt($row['duplicate_employment_id']),
                'duplicate_code' => self::nullable($row['duplicate_code']),
            ];
        }

        return $rows;
    }

    public function startPeriod(int $supplierId): ?string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT start_period FROM payroll_module_state WHERE supplier_id = ?',
        );
        $statement->execute([$supplierId]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
