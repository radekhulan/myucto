<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

/**
 * Návrhy registračních povinností, které vznikly z detekce změny údajů.
 *
 * Repozitář vrací HOLÁ DATA. O tom, co je změna a co je jen jiný zápis téže
 * hodnoty, rozhoduje doména
 * ({@see \MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetector}),
 * protože porovnání běží nad dešifrovaným obsahem a v SQL ho udělat nelze.
 */
final readonly class PayrollRegistrationChangeProposalRepository
{
    /**
     * Vodoznak zdrojů hlásitelných údajů jednoho vztahu, počítaný v SQL.
     *
     * Musí být JEDEN výraz použitý na obou místech (sweep i jedno ověření),
     * jinak by se hromadná a jednotlivá detekce mohly rozejít a vztah by se
     * buď porovnával pořád dokola, nebo naopak nikdy.
     *
     * Očekává v dotazu alias `employment` nad `payroll_employments`.
     *
     * `CONVERT(... USING ascii)` tam není pro parádu: `SHA2()` vrací řetězec
     * v utf8mb4, kdežto uložený vodoznak je `ascii_bin`, a porovnání obou by
     * skončilo chybou 1267 (illegal mix of collations).
     */
    private const WATERMARK_EXPRESSION = 'CONVERT(SHA2(CONCAT_WS(\'|\',
        (SELECT CONCAT(COALESCE(MAX(a.id), 0), \':\',
                       COALESCE(SUM(a.row_version), 0))
           FROM payroll_registration_a1_profiles a
          WHERE a.supplier_id = employment.supplier_id
            AND a.employment_id = employment.id),
        (SELECT CONCAT(COALESCE(MAX(h.id), 0), \':\',
                       COALESCE(SUM(h.row_version), 0))
           FROM payroll_person_identity_history h
          WHERE h.supplier_id = employment.supplier_id
            AND h.employee_id = employment.employee_id),
        (SELECT CONCAT(COALESCE(MAX(i.id), 0), \':\',
                       COALESCE(SUM(i.row_version), 0))
           FROM payroll_person_identifiers i
          WHERE i.supplier_id = employment.supplier_id
            AND i.employee_id = employment.employee_id),
        (SELECT CONCAT(COALESCE(MAX(t.id), 0), \':\',
                       COALESCE(SUM(t.row_version), 0))
           FROM payroll_employment_terms t
          WHERE t.supplier_id = employment.supplier_id
            AND t.employment_id = employment.id)
      ), 256) USING ascii)';

    public function __construct(private Connection $db) {}

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            return $callback();
        }
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return $result;
    }

    /**
     * Rozsah jednoho vztahu pro detekci: kdo to je, jaký má druh vztahu
     * a jaké podmínky platí k rozhodnému dni.
     *
     * `activity_code` a `jmhz_relationship_detail_code` se čtou ŽIVĚ
     * z podmínek vztahu, ne ze zmrazeného profilu A1 — druh výdělečné činnosti
     * se po přihlášení mění právě tam a profil o tom neví.
     *
     * @return array<string,mixed>|null
     */
    public function employmentContext(
        int $supplierId,
        int $employmentId,
        string $onDate,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.employee_id, employment.status,
                    employment.relation_type, employment.start_date,
                    employment.actual_start_date, employment.end_date,
                    employee.full_name,
                    terms.id AS terms_id,
                    terms.activity_code,
                    terms.jmhz_relationship_detail_code
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               LEFT JOIN payroll_employment_terms terms
                 ON terms.supplier_id = employment.supplier_id
                AND terms.employment_id = employment.id
                AND terms.effective_from <= ?
                AND (terms.effective_to IS NULL OR terms.effective_to >= ?)
              WHERE employment.supplier_id = ? AND employment.id = ?
              ORDER BY terms.effective_from DESC, terms.id DESC
              LIMIT 1'
        );
        $statement->execute([$onDate, $onDate, $supplierId, $employmentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Vztahy, u kterých se od posledního porovnání pohnul některý ZDROJ
     * hlásitelných údajů.
     *
     * Vodoznak se počítá čistě v SQL, bez dešifrování; teprve vztahy, jejichž
     * vodoznak se liší od uloženého, se opravdu porovnávají. Firma s pěti sty
     * zaměstnanci tak zaplatí jeden dotaz místo pěti set dešifrování.
     *
     * @return list<array{employment_id:int,employee_id:int,watermark:string}>
     */
    public function staleEmployments(
        int $supplierId,
        string $environment,
        int $limit,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT candidate.employment_id, candidate.employee_id,
                    candidate.watermark
               FROM (
                 SELECT employment.id AS employment_id,
                        employment.employee_id,
                        ' . self::WATERMARK_EXPRESSION . ' AS watermark,
                        scan.source_watermark
                   FROM payroll_employments employment
                   LEFT JOIN payroll_registration_change_scans scan
                     ON scan.supplier_id = employment.supplier_id
                    AND scan.environment = ?
                    AND scan.employment_id = employment.id
                  WHERE employment.supplier_id = ?
                    AND employment.status NOT IN (\'no_show\', \'archived\')
                    AND EXISTS (
                          SELECT 1
                            FROM payroll_registration_identity_snapshots snapshot
                            JOIN payroll_submissions submission
                              ON submission.supplier_id = snapshot.supplier_id
                             AND submission.environment = snapshot.environment
                             AND submission.id = snapshot.submission_id
                           WHERE snapshot.supplier_id = employment.supplier_id
                             AND snapshot.environment = ?
                             AND snapshot.employment_id = employment.id
                             AND snapshot.agenda_code = \'REGZEC25\'
                             AND submission.status IN (
                                   \'submitted\', \'processing\', \'accepted\',
                                   \'partially_accepted\'
                                 )
                        )
               ) candidate
              WHERE candidate.source_watermark IS NULL
                 OR candidate.source_watermark <> candidate.watermark
              ORDER BY candidate.employment_id
              LIMIT ' . max(1, min(1000, $limit))
        );
        $statement->execute([$environment, $supplierId, $environment]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'employment_id' => (int) $row['employment_id'],
                'employee_id' => (int) $row['employee_id'],
                'watermark' => (string) $row['watermark'],
            ];
        }

        return $result;
    }

    /** Vodoznak jednoho vztahu (tentýž výpočet jako {@see self::staleEmployments()}). */
    public function watermark(int $supplierId, int $employmentId): string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT ' . self::WATERMARK_EXPRESSION . ' AS watermark
               FROM payroll_employments employment
              WHERE employment.supplier_id = ? AND employment.id = ?'
        );
        $statement->execute([$supplierId, $employmentId]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : str_repeat('0', 64);
    }

    public function rememberScan(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $watermark,
        ?int $baselineSnapshotId,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_registration_change_scans
                (supplier_id, environment, employment_id, source_watermark,
                 baseline_snapshot_id, scanned_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               source_watermark = VALUES(source_watermark),
               baseline_snapshot_id = VALUES(baseline_snapshot_id),
               scanned_at = VALUES(scanned_at)'
        )->execute([
            $supplierId, $environment, $employmentId,
            $watermark, $baselineSnapshotId,
        ]);
    }

    /**
     * Otevřené návrhy jednoho vztahu, uzamčené pro zápis.
     *
     * @return list<array<string,mixed>>
     */
    public function openForEmployment(
        int $supplierId,
        string $environment,
        int $employmentId,
        bool $forUpdate = false,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_registration_change_proposals
              WHERE supplier_id = ? AND environment = ?
                AND employment_id = ? AND status = \'open\'
              ORDER BY duty_kind, id'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$supplierId, $environment, $employmentId]);

        return array_values(array_filter(
            $statement->fetchAll(PDO::FETCH_ASSOC),
            'is_array',
        ));
    }

    /** @return list<array<string,mixed>> */
    public function listForEmployment(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $limit = 50,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_registration_change_proposals
              WHERE supplier_id = ? AND environment = ? AND employment_id = ?
              ORDER BY (status = \'open\') DESC, due_on, id
              LIMIT ' . max(1, min(200, $limit))
        );
        $statement->execute([$supplierId, $environment, $employmentId]);

        return array_values(array_filter(
            $statement->fetchAll(PDO::FETCH_ASSOC),
            'is_array',
        ));
    }

    /** @return array<string,mixed>|null */
    public function find(
        int $supplierId,
        string $environment,
        int $proposalId,
        bool $forUpdate = false,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_registration_change_proposals
              WHERE supplier_id = ? AND environment = ? AND id = ?'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$supplierId, $environment, $proposalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Nesplněné povinnosti z detekce pro přehled termínů.
     *
     * @return list<array<string,mixed>>
     */
    public function openDeadlines(
        int $supplierId,
        string $environment,
        string $from,
        string $to,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT proposal.id AS proposal_id, proposal.employment_id,
                    proposal.employee_id, proposal.duty_kind,
                    proposal.action_code, proposal.due_on,
                    proposal.detected_on, proposal.deadline_ruleset_id,
                    proposal.deadline_source, proposal.findings_json,
                    employee.full_name
               FROM payroll_registration_change_proposals proposal
               JOIN payroll_employments employment
                 ON employment.supplier_id = proposal.supplier_id
                AND employment.id = proposal.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE proposal.supplier_id = ? AND proposal.environment = ?
                AND proposal.status = \'open\'
                AND proposal.due_on >= ? AND proposal.due_on <= ?
                AND employment.status NOT IN (\'no_show\', \'archived\')
              ORDER BY proposal.due_on, proposal.id'
        );
        $statement->execute([$supplierId, $environment, $from, $to]);

        return array_values(array_filter(
            $statement->fetchAll(PDO::FETCH_ASSOC),
            'is_array',
        ));
    }

    /**
     * Vloží návrh; když tentýž rozešlý stav už návrh má, vrátí ten původní.
     *
     * @param array<string,mixed> $record
     * @return array{row:array<string,mixed>,created:bool}
     */
    public function insert(array $record): array
    {
        $existing = $this->findByState($record);
        if ($existing !== null) {
            return ['row' => $existing, 'created' => false];
        }
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_registration_change_proposals
                (supplier_id, employee_id, employment_id, environment,
                 duty_kind, action_code, baseline_fingerprint,
                 current_fingerprint, detected_on, due_on,
                 deadline_ruleset_id, deadline_source, findings_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        try {
            $statement->execute([
                $record['supplier_id'], $record['employee_id'],
                $record['employment_id'], $record['environment'],
                $record['duty_kind'], $record['action_code'],
                $record['baseline_fingerprint'], $record['current_fingerprint'],
                $record['detected_on'], $record['due_on'],
                $record['deadline_ruleset_id'], $record['deadline_source'],
                $record['findings_json'],
            ]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            $existing = $this->findByState($record);
            if ($existing === null) {
                throw $exception;
            }

            return ['row' => $existing, 'created' => false];
        }
        $stored = $this->find(
            (int) $record['supplier_id'],
            (string) $record['environment'],
            (int) $this->db->pdo()->lastInsertId(),
        );
        if ($stored === null) {
            throw new \RuntimeException('Návrh registrační povinnosti nelze načíst.');
        }

        return ['row' => $stored, 'created' => true];
    }

    /**
     * Návrh, který už neodpovídá aktuálnímu stavu, se uzavírá jako
     * `superseded`, ne maže: lhůta, která existovala, má zůstat dohledatelná.
     */
    public function supersede(int $supplierId, string $environment, int $proposalId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_registration_change_proposals
                SET status = \'superseded\', row_version = row_version + 1
              WHERE supplier_id = ? AND environment = ? AND id = ?
                AND status = \'open\''
        )->execute([$supplierId, $environment, $proposalId]);
    }

    public function resolve(
        int $supplierId,
        string $environment,
        int $proposalId,
        string $status,
        ?int $eventId,
        ?int $userId,
        ?string $note,
    ): bool {
        if (!in_array($status, ['filed', 'dismissed'], true)) {
            throw new \InvalidArgumentException(
                'Návrh registrační povinnosti lze uzavřít jen jako podaný nebo vyřízený ručně.',
            );
        }
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_registration_change_proposals
                SET status = ?, resolved_event_id = ?, resolved_by = ?,
                    resolution_note = ?, resolved_at = UTC_TIMESTAMP(),
                    row_version = row_version + 1
              WHERE supplier_id = ? AND environment = ? AND id = ?
                AND status = \'open\''
        );
        $statement->execute([
            $status, $eventId, $userId, $note,
            $supplierId, $environment, $proposalId,
        ]);

        return $statement->rowCount() === 1;
    }

    /** @param array<string,mixed> $record @return array<string,mixed>|null */
    private function findByState(array $record): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_registration_change_proposals
              WHERE supplier_id = ? AND environment = ? AND employment_id = ?
                AND duty_kind = ? AND current_fingerprint = ?'
        );
        $statement->execute([
            $record['supplier_id'], $record['environment'],
            $record['employment_id'], $record['duty_kind'],
            $record['current_fingerprint'],
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
