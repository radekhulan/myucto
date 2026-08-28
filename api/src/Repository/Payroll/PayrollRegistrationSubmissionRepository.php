<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Čtecí strana registrace pracovního vztahu u ČSSZ (PREZEC/REGZEC).
 *
 * Drží jen to, co registrace potřebuje navíc proti identitnímu snapshotu:
 * pracovní vztah, účtárnu, variabilní symbol a kód pracoviště ČSSZ. Osobní
 * údaje se odsud NEČTOU — ty má na starosti `PayrollRegistrationIdentityService`,
 * který je jediný umí dešifrovat a zmrazit.
 */
final class PayrollRegistrationSubmissionRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Kontext jednoho pracovního vztahu pro registraci.
     *
     * @return array{
     *   employment_id:int,employee_id:int,office_id:?int,status:string,
     *   relation_type:string,start_date:?string,actual_start_date:?string,
     *   end_date:?string,employer_name:string,
     *   employer_variable_symbol:?string,cssz_workplace_code:?string,
     *   is_first_employment:bool
     * }|null
     */
    public function findEmploymentContext(
        int $supplierId,
        int $employmentId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id,
                    employment.employee_id,
                    employment.office_id,
                    employment.status,
                    employment.relation_type,
                    employment.start_date,
                    employment.actual_start_date,
                    employment.end_date,
                    supplier.company_name,
                    office.social_security_variable_symbol,
                    settings.social_security_office_code
               FROM payroll_employments employment
               JOIN supplier
                 ON supplier.id = employment.supplier_id
               LEFT JOIN payroll_offices office
                 ON office.supplier_id = employment.supplier_id
                AND office.id = employment.office_id
               LEFT JOIN payroll_employer_settings settings
                 ON settings.supplier_id = employment.supplier_id
              WHERE employment.supplier_id = ?
                AND employment.id = ?'
        );
        $statement->execute([$supplierId, $employmentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'employment_id' => (int) $row['id'],
            'employee_id' => (int) $row['employee_id'],
            'office_id' => $row['office_id'] === null
                ? null
                : (int) $row['office_id'],
            'status' => (string) $row['status'],
            'relation_type' => (string) $row['relation_type'],
            'start_date' => $this->nullableString($row['start_date']),
            'actual_start_date' =>
                $this->nullableString($row['actual_start_date']),
            'end_date' => $this->nullableString($row['end_date']),
            'employer_name' => (string) $row['company_name'],
            'employer_variable_symbol' => $this->nullableString(
                $row['social_security_variable_symbol'],
            ),
            'cssz_workplace_code' => $this->nullableString(
                $row['social_security_office_code'],
            ),
            'is_first_employment' => $this->isFirstEmployment(
                $supplierId,
                $employmentId,
                $this->nullableString($row['start_date']),
            ),
        ];
    }

    /**
     * Byl pro tenhle vztah PŘIJAT PREZEC P1?
     *
     * Odpovídá se jen z vlastního ledgeru a jen stavem `accepted`. Podání ve
     * stavu `ready` nebo `submitted` znamená „odesláno", ne „přijato" — a
     * PREZEC P2 na neprokázanou předregistraci navázat nesmí. Prázdný výsledek
     * proto NENÍ důkaz, že P1 neexistuje; je to důkaz, že o jejím přijetí nic
     * nevíme, a v tom případě se P2 nepodává.
     */
    public function hasAcceptedPreRegistration(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $agendaCode,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_submission_parts part
               JOIN payroll_submissions submission
                 ON submission.supplier_id = part.supplier_id
                AND submission.environment = part.environment
                AND submission.id = part.submission_id
              WHERE part.supplier_id = ?
                AND part.environment = ?
                AND part.agenda_code = ?
                AND part.subject_reference = ?
                AND submission.status = \'accepted\'
              LIMIT 1'
        );
        $statement->execute([
            $supplierId,
            $environment,
            $agendaCode,
            self::employmentReference($employmentId),
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Nejnovější registrační podání pro vztah — podklad pro UI i pro rozhodnutí,
     * jestli se má vůbec něco zakládat.
     *
     * @return array{
     *   submission_id:int,agenda_code:string,status:string,
     *   created_at:string,artifact_sha256:?string
     * }|null
     */
    public function latestRegistration(
        int $supplierId,
        string $environment,
        int $employmentId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT submission.id,
                    part.agenda_code,
                    submission.status,
                    submission.created_at,
                    artifact.artifact_sha256
               FROM payroll_submission_parts part
               JOIN payroll_submissions submission
                 ON submission.supplier_id = part.supplier_id
                AND submission.environment = part.environment
                AND submission.id = part.submission_id
               LEFT JOIN payroll_submission_artifacts artifact
                 ON artifact.supplier_id = part.supplier_id
                AND artifact.part_id = part.id
                AND artifact.artifact_kind = \'outbound_xml\'
              WHERE part.supplier_id = ?
                AND part.environment = ?
                AND part.subject_reference = ?
                AND part.agenda_code IN (\'PREZEC26\', \'REGZEC25\')
              ORDER BY submission.id DESC
              LIMIT 1'
        );
        $statement->execute([
            $supplierId,
            $environment,
            self::employmentReference($employmentId),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'submission_id' => (int) $row['id'],
            'agenda_code' => (string) $row['agenda_code'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'artifact_sha256' =>
                $this->nullableString($row['artifact_sha256']),
        ];
    }

    /**
     * @return array{
     *   submission_id:int,agenda_code:string,status:string,
     *   created_at:string,artifact_sha256:?string
     * }|null
     */
    public function registrationBySubmission(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $submissionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT submission.id,
                    part.agenda_code,
                    submission.status,
                    submission.created_at,
                    artifact.artifact_sha256
               FROM payroll_submission_parts part
               JOIN payroll_submissions submission
                 ON submission.supplier_id = part.supplier_id
                AND submission.environment = part.environment
                AND submission.id = part.submission_id
               LEFT JOIN payroll_submission_artifacts artifact
                 ON artifact.supplier_id = part.supplier_id
                AND artifact.part_id = part.id
                AND artifact.artifact_kind = \'outbound_xml\'
              WHERE part.supplier_id = ?
                AND part.environment = ?
                AND part.submission_id = ?
                AND part.subject_reference = ?
                AND part.agenda_code IN (\'PREZEC26\', \'REGZEC25\')
              LIMIT 1'
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            self::employmentReference($employmentId),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'submission_id' => (int) $row['id'],
            'agenda_code' => (string) $row['agenda_code'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'artifact_sha256' => $this->nullableString($row['artifact_sha256']),
        ];
    }

    /**
     * @return list<array{
     *   receipt_id:int,employment_id:int,effective_on:string,
     *   form_guid:string,external_employment_reference:string
     * }>
     */
    public function acceptedVariableSymbolTransferOutcomes(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $receiptId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT receipt.id AS receipt_id, event.employment_id,
                    event.effective_on, outcome.form_guid,
                    outcome.external_employment_reference
               FROM payroll_submission_parts part
               JOIN payroll_submissions submission
                 ON submission.supplier_id = part.supplier_id
                AND submission.environment = part.environment
                AND submission.id = part.submission_id
               JOIN payroll_registration_event_snapshots event
                 ON event.supplier_id = part.supplier_id
                AND event.environment = part.environment
                AND part.source_entity_type = "payroll_registration_event"
                AND part.source_entity_reference =
                    CONCAT("payroll_registration_event:", event.id)
               JOIN payroll_submission_receipts receipt
                 ON receipt.supplier_id = submission.supplier_id
                AND receipt.environment = submission.environment
                AND receipt.submission_id = submission.id
                AND receipt.verification_status = "trusted"
                AND receipt.remote_status = "accepted"
               JOIN payroll_jmhz_protocol_form_outcomes outcome
                 ON outcome.supplier_id = receipt.supplier_id
                AND outcome.environment = receipt.environment
                AND outcome.submission_id = receipt.submission_id
                AND outcome.receipt_id = receipt.id
                AND (
                    outcome.part_id = part.id
                    OR (
                        outcome.part_id IS NULL
                        AND 1 = (
                            SELECT COUNT(*)
                              FROM payroll_submission_parts receipt_part
                             WHERE receipt_part.supplier_id = submission.supplier_id
                               AND receipt_part.environment = submission.environment
                               AND receipt_part.submission_id = submission.id
                        )
                    )
                )
              WHERE submission.supplier_id = ?
                AND submission.environment = ?
                AND submission.id = ?
                AND receipt.id = ?
                AND submission.status = "accepted"
                AND part.agenda_code = "REGZEC25"
                AND event.action_code = 5
                AND outcome.external_employment_reference IS NOT NULL
              ORDER BY receipt.id, outcome.id'
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $receiptId,
        ]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'receipt_id' => (int) $row['receipt_id'],
                'employment_id' => (int) $row['employment_id'],
                'effective_on' => (string) $row['effective_on'],
                'form_guid' => (string) $row['form_guid'],
                'external_employment_reference' =>
                    (string) $row['external_employment_reference'],
            ];
        }

        return $result;
    }

    /**
     * @return list<array{
     *   receipt_id:int,employee_id:int,employment_id:int,effective_on:string,
     *   form_guid:string,external_person_reference:string,
     *   external_employment_reference:string
     * }>
     */
    public function acceptedEmploymentRegistrationOutcomes(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $receiptId,
        string $registrationRulesetId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT receipt.id AS receipt_id, employment.employee_id,
                    employment.id AS employment_id,
                    obligation.period_end AS effective_on,
                    outcome.form_guid, outcome.external_person_reference,
                    outcome.external_employment_reference
               FROM payroll_submission_parts part
               JOIN payroll_submissions submission
                 ON submission.supplier_id = part.supplier_id
                AND submission.environment = part.environment
                AND submission.id = part.submission_id
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.environment = submission.environment
                AND obligation.id = submission.obligation_id
               JOIN payroll_submission_deadlines deadline
                 ON deadline.supplier_id = obligation.supplier_id
                AND deadline.environment = obligation.environment
                AND deadline.obligation_id = obligation.id
                AND deadline.deadline_kind = "regular"
               JOIN payroll_employments employment
                 ON employment.supplier_id = part.supplier_id
                AND part.source_entity_type = "payroll_employment"
                AND part.source_entity_reference =
                    CONCAT("payroll_employment_registration:", employment.id)
               JOIN payroll_submission_receipts receipt
                 ON receipt.supplier_id = submission.supplier_id
                AND receipt.environment = submission.environment
                AND receipt.submission_id = submission.id
                AND receipt.verification_status = "trusted"
                AND receipt.remote_status IN ("accepted", "partially_accepted")
               JOIN payroll_jmhz_protocol_form_outcomes outcome
                 ON outcome.supplier_id = receipt.supplier_id
                AND outcome.environment = receipt.environment
                AND outcome.submission_id = receipt.submission_id
                AND outcome.receipt_id = receipt.id
                AND (
                    outcome.part_id = part.id
                    OR (
                        outcome.part_id IS NULL
                        AND 1 = (
                            SELECT COUNT(*)
                              FROM payroll_submission_parts receipt_part
                             WHERE receipt_part.supplier_id = submission.supplier_id
                               AND receipt_part.environment = submission.environment
                               AND receipt_part.submission_id = submission.id
                        )
                    )
                )
              WHERE submission.supplier_id = ?
                AND submission.environment = ?
                AND submission.id = ?
                AND receipt.id = ?
                AND deadline.ruleset_id = ?
                AND submission.status IN ("accepted", "partially_accepted")
                AND submission.submission_kind = "regular"
                AND part.agenda_code IN ("REGZEC25", "PREZEC26")
                AND outcome.remote_status = "accepted"
                AND outcome.external_person_reference IS NOT NULL
                AND outcome.external_employment_reference IS NOT NULL
              ORDER BY receipt.id, outcome.id'
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $receiptId,
            $registrationRulesetId,
        ]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'receipt_id' => (int) $row['receipt_id'],
                'employee_id' => (int) $row['employee_id'],
                'employment_id' => (int) $row['employment_id'],
                'effective_on' => (string) $row['effective_on'],
                'form_guid' => (string) $row['form_guid'],
                'external_person_reference' =>
                    (string) $row['external_person_reference'],
                'external_employment_reference' =>
                    (string) $row['external_employment_reference'],
            ];
        }

        return $result;
    }

    /**
     * Termín položky checklistu se přepisuje na skutečnou zákonnou lhůtu.
     * Seed při založení vztahu dává všem položkám fáze stejné datum (den
     * nástupu) — u registrace je to o osm dnů vedle a obsluha by se řídila
     * datem, které se zákonem nesouvisí.
     */
    public function setChecklistDueDate(
        int $supplierId,
        int $employmentId,
        string $phase,
        string $itemKey,
        string $dueDate,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_employment_checklist_items
                SET due_date = ?
              WHERE supplier_id = ?
                AND employment_id = ?
                AND phase = ?
                AND item_key = ?'
        );
        $statement->execute([
            $dueDate,
            $supplierId,
            $employmentId,
            $phase,
            $itemKey,
        ]);
    }

    public static function employmentReference(int $employmentId): string
    {
        if ($employmentId <= 0) {
            throw new \InvalidArgumentException(
                'Pracovní vztah musí být kladné číslo.',
            );
        }

        return "payroll_employment:{$employmentId}";
    }

    private function isFirstEmployment(
        int $supplierId,
        int $employmentId,
        ?string $startDate,
    ): bool {
        if ($startDate === null) {
            return false;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_employments
              WHERE supplier_id = ?
                AND id <> ?
                AND start_date IS NOT NULL
                AND (start_date < ?
                     OR (start_date = ? AND id < ?))
              LIMIT 1'
        );
        $statement->execute([
            $supplierId,
            $employmentId,
            $startDate,
            $startDate,
            $employmentId,
        ]);

        return $statement->fetchColumn() === false;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
