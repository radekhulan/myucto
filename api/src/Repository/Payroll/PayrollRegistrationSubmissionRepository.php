<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEmployerName;
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
     * `employer_variable_symbol` je symbol platný pro prostředí
     * ({@see PayrollEmployerIdentifierSql::resolveVariableSymbol()}): do testu
     * ČSSZ odchází testovací VS účtárny, ne ostrý.
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
        string $environment,
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
                    supplier.city AS company_city,'
                    . PayrollEmployerIdentifierSql::SELECT . '
               FROM payroll_employments employment
               JOIN supplier
                 ON supplier.id = employment.supplier_id'
                . PayrollEmployerIdentifierSql::JOINS . '
              WHERE employment.supplier_id = ?
                AND employment.id = ?'
        );
        $statement->execute([$supplierId, $employmentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row = PayrollEmployerIdentifierSql::resolveVariableSymbol($row, $environment);

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
            'employer_name' => PayrollRegistrationEmployerName::forSubmission(
                (string) $row['company_name'],
                $this->nullableString($row['company_city']),
            ),
            'employer_variable_symbol' => $this->nullableString(
                $row['employer_variable_symbol'],
            ),
            'cssz_workplace_code' => $this->nullableString(
                $row['employer_ossz_code'],
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
     * GUID formuláře přijaté PREZEC P1, na který se PREZEC P2 odkazuje
     * (atribut 10012; bez správné reference ČSSZ ukončení nezpracuje).
     *
     * Bere se z doloženého výsledku formuláře v důvěryhodném protokolu, tedy
     * z toho, co ČSSZ skutečně přijala, ne z čerstvě vygenerované hodnoty.
     * Hledá se NEJSTARŠÍ přijatý PREZEC vztahu: P2 vzniká vždy až po přijaté
     * P1 a druhou P1 resolver nepustí, takže pozdější přijaté podání téhož
     * vztahu je už samo ukončení. Prázdný výsledek znamená „GUID nedoložen"
     * a P2 se v tom případě nepodává.
     */
    public function acceptedPreRegistrationGuid(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $agendaCode,
    ): ?string {
        $statement = $this->db->pdo()->prepare(
            'SELECT outcome.form_guid
               FROM payroll_submission_parts part
               JOIN payroll_submissions submission
                 ON submission.supplier_id = part.supplier_id
                AND submission.environment = part.environment
                AND submission.id = part.submission_id
               JOIN payroll_submission_receipts receipt
                 ON receipt.supplier_id = submission.supplier_id
                AND receipt.environment = submission.environment
                AND receipt.submission_id = submission.id
                AND receipt.verification_status = \'trusted\'
                AND receipt.remote_status = \'accepted\'
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
              WHERE part.supplier_id = ?
                AND part.environment = ?
                AND part.agenda_code = ?
                AND part.subject_reference = ?
                AND submission.status = \'accepted\'
                AND outcome.remote_status = \'accepted\'
              ORDER BY submission.id ASC, outcome.id ASC
              LIMIT 1'
        );
        $statement->execute([
            $supplierId,
            $environment,
            $agendaCode,
            self::employmentReference($employmentId),
        ]);
        $guid = $statement->fetchColumn();

        return is_string($guid) && $guid !== '' ? $guid : null;
    }

    /**
     * Archivovaný odeslaný soubor NEJSTARŠÍHO přijatého podání dané agendy
     * (u PREZEC je to P1). Z něj se čte, co ČSSZ skutečně přijala:
     * předpokládaný den nástupu a variabilní symbol, na které se navazuje
     * ukončení předregistrace i plná registrace.
     */
    public function acceptedFilingArtifactId(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $agendaCode,
    ): ?int {
        $statement = $this->db->pdo()->prepare(
            'SELECT artifact.id
               FROM payroll_submission_parts part
               JOIN payroll_submissions submission
                 ON submission.supplier_id = part.supplier_id
                AND submission.environment = part.environment
                AND submission.id = part.submission_id
               JOIN payroll_submission_artifacts artifact
                 ON artifact.supplier_id = part.supplier_id
                AND artifact.environment = part.environment
                AND artifact.submission_id = part.submission_id
                AND artifact.part_id = part.id
                AND artifact.artifact_kind = "outbound_xml"
                AND artifact.direction = "outbound"
              WHERE part.supplier_id = ?
                AND part.environment = ?
                AND part.agenda_code = ?
                AND part.subject_reference = ?
                AND submission.status = "accepted"
              ORDER BY submission.id ASC, artifact.id ASC
              LIMIT 1'
        );
        $statement->execute([
            $supplierId,
            $environment,
            $agendaCode,
            self::employmentReference($employmentId),
        ]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Pracovní vztahy, u kterých ČSSZ v daném okně přijala změnu údajů
     * (REGZEC A3). Klíčem je id vztahu, hodnotou čas rozhodnutí v UTC.
     *
     * @param list<int> $employmentIds
     * @return array<int,string>
     */
    public function employmentsWithAcceptedChangeSince(
        int $supplierId,
        string $environment,
        array $employmentIds,
        string $decidedNotBeforeUtc,
    ): array {
        $employmentIds = array_values(array_unique(array_filter(
            $employmentIds,
            static fn (int $id): bool => $id > 0,
        )));
        if ($employmentIds === []) {
            return [];
        }
        $references = array_map(self::employmentReference(...), $employmentIds);
        $placeholders = implode(', ', array_fill(0, count($references), '?'));
        $statement = $this->db->pdo()->prepare(
            'SELECT event.employment_id, MAX(submission.decided_at) AS decided_at
               FROM payroll_submission_parts part
               JOIN payroll_submissions submission
                 ON submission.supplier_id = part.supplier_id
                AND submission.environment = part.environment
                AND submission.id = part.submission_id
               JOIN payroll_registration_event_snapshots event
                 ON event.supplier_id = part.supplier_id
                AND event.environment = part.environment
                AND CONCAT("payroll_registration_event:", event.id)
                    = part.source_entity_reference
                AND event.action_code = 3
              WHERE part.supplier_id = ?
                AND part.environment = ?
                AND part.agenda_code = "REGZEC25"
                AND part.source_entity_type = "payroll_registration_event"
                AND submission.status = "accepted"
                AND submission.decided_at >= ?
                AND part.subject_reference IN (' . $placeholders . ')
              GROUP BY event.employment_id'
        );
        $statement->execute([
            $supplierId,
            $environment,
            $decidedNotBeforeUtc,
            ...$references,
        ]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']] = (string) $row['decided_at'];
        }

        return $result;
    }

    /** Počet přijatých podání dané agendy u vztahu (P1 a P2 jsou dvě). */
    public function acceptedFilingCount(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $agendaCode,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(DISTINCT submission.id)
               FROM payroll_submission_parts part
               JOIN payroll_submissions submission
                 ON submission.supplier_id = part.supplier_id
                AND submission.environment = part.environment
                AND submission.id = part.submission_id
              WHERE part.supplier_id = ?
                AND part.environment = ?
                AND part.agenda_code = ?
                AND part.subject_reference = ?
                AND submission.status = "accepted"'
        );
        $statement->execute([
            $supplierId,
            $environment,
            $agendaCode,
            self::employmentReference($employmentId),
        ]);

        return (int) $statement->fetchColumn();
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
     * Stavy základního podání (P1/A1), po kterých už další základní podání
     * k témuž vztahu nevzniká: nové je přípustné jen po zamítnutí, nahrazení
     * nebo včasném zrušení předchozího.
     */
    public const BASE_REGISTRATION_CLOSED_STATUSES = [
        'rejected',
        'superseded',
        'cancelled_in_time',
    ];

    /**
     * Nejnovější UZAVŘENÉ základní registrační podání vztahu (P1/A1
     * odmítnuté, nahrazené nebo včas zrušené), po kterém přichází nové řádné
     * podání. `null` = žádné takové není.
     *
     * Nová přihláška po odmítnutí potřebuje vlastní povinnost i vlastní
     * idempotenční klíč: na jednu povinnost připadá jediné řádné podání
     * (uq_payroll_submissions_regular) a stejný klíč by vrátil odmítnuté
     * podání místo nového.
     */
    public function latestClosedBaseRegistrationId(
        int $supplierId,
        string $environment,
        int $employmentId,
    ): ?int {
        $closed = self::BASE_REGISTRATION_CLOSED_STATUSES;
        $statement = $this->db->pdo()->prepare(
            'SELECT MAX(submission.id)
               FROM payroll_submission_parts part
               JOIN payroll_submissions submission
                 ON submission.supplier_id = part.supplier_id
                AND submission.environment = part.environment
                AND submission.id = part.submission_id
              WHERE part.supplier_id = ?
                AND part.environment = ?
                AND part.subject_reference = ?
                AND part.source_entity_type = \'payroll_employment\'
                AND part.agenda_code IN (\'PREZEC26\', \'REGZEC25\')
                AND submission.status IN ('
                . implode(',', array_fill(0, count($closed), '?')) . ')',
        );
        $statement->execute([
            $supplierId,
            $environment,
            self::employmentReference($employmentId),
            ...$closed,
        ]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }

    /**
     * Nejnovější ŽIVÉ základní registrační podání vztahu (bez podání z událostí
     * A2–A8): připravené ve frontě, odeslané nebo přijaté. Podklad pro kartu
     * vztahu po načtení i pro zákaz druhé přihlášky téhož vztahu.
     *
     * @return array{
     *   submission_id:int,agenda_code:string,status:string,created_at:string,
     *   submitted_at:?string,source_snapshot_hash:string
     * }|null
     */
    public function liveBaseRegistration(
        int $supplierId,
        string $environment,
        int $employmentId,
        ?string $agendaCode = null,
    ): ?array {
        $closed = self::BASE_REGISTRATION_CLOSED_STATUSES;
        $parameters = [
            $supplierId,
            $environment,
            self::employmentReference($employmentId),
            ...$closed,
        ];
        $agendaFilter = '';
        if ($agendaCode !== null) {
            $agendaFilter = ' AND part.agenda_code = ?';
            $parameters[] = $agendaCode;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT submission.id,
                    part.agenda_code,
                    submission.status,
                    submission.created_at,
                    submission.submitted_at,
                    submission.source_snapshot_hash
               FROM payroll_submission_parts part
               JOIN payroll_submissions submission
                 ON submission.supplier_id = part.supplier_id
                AND submission.environment = part.environment
                AND submission.id = part.submission_id
              WHERE part.supplier_id = ?
                AND part.environment = ?
                AND part.subject_reference = ?
                AND part.source_entity_type = \'payroll_employment\'
                AND part.agenda_code IN (\'PREZEC26\', \'REGZEC25\')
                AND submission.status NOT IN ('
                . implode(',', array_fill(0, count($closed), '?')) . ')'
                . $agendaFilter . '
              ORDER BY submission.id DESC
              LIMIT 1'
        );
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'submission_id' => (int) $row['id'],
            'agenda_code' => (string) $row['agenda_code'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'submitted_at' => $this->nullableString($row['submitted_at']),
            'source_snapshot_hash' => (string) $row['source_snapshot_hash'],
        ];
    }

    /**
     * `xsd_version` je formulář zmrazené věty (`REGZEC25`, `REGZEC27`,
     * `PREZEC26`), `source_snapshot_hash` otisk události, ze které vznikla.
     *
     * @return array{
     *   submission_id:int,agenda_code:string,status:string,
     *   created_at:string,artifact_sha256:?string,xsd_version:?string,
     *   source_snapshot_hash:string,row_version:int
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
                    submission.source_snapshot_hash,
                    submission.row_version,
                    artifact.artifact_sha256,
                    artifact.xsd_version
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
            'xsd_version' => $this->nullableString($row['xsd_version']),
            'source_snapshot_hash' => (string) $row['source_snapshot_hash'],
            'row_version' => (int) $row['row_version'],
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
