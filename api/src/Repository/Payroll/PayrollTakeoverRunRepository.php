<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

/**
 * Zápis a čtení převzatého mzdového běhu.
 *
 * Převzatý výsledek NELEŽÍ v `payroll_run_revisions`, a je to jediné důležité
 * rozhodnutí celé téhle třídy. Revize je vstupenka do ročního zúčtování, ELDP,
 * JMHZ, mzdových listů, výplatních pásek i platebního ledgeru a všichni tihle
 * čtenáři se ptají jen na `status = 'approved'`. Převzatý výsledek v revizi by
 * se tedy jedním sloupcem stal zdrojem zákonného tiskopisu — a navíc by ho
 * srovnávací sestava převodu započetla podruhé, protože
 * `PayrollMigrationReconciliationRepository::calculatedPeriods()` se na status
 * neptá vůbec (spojuje aktuální revizi s `payroll_run_persons`).
 *
 * Proto má převzatý běh vlastní tabulku, žádnou revizi a žádný výsledek osob.
 * Kdo převzatá čísla potřebuje, čte je přiznaně přes `PayrollTakeoverReader`.
 */
final class PayrollTakeoverRunRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Běh za období bez rozlišení účtárny; převzatý běh se zakládá jen bez ní.
     * Živý běh má přednost před zrušeným převzatým obalem, který vedle něj
     * smí zůstat (`period_slot`).
     */
    public function runForPeriod(int $supplierId, string $periodStart): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_runs
              WHERE supplier_id = ? AND period_start = ? AND office_scope_id = 0
              ORDER BY period_slot IS NULL, id DESC
              LIMIT 1
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $periodStart]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Založí prázdný obal převzatého běhu rovnou ve stavu `closed`.
     *
     * Převzatý běh nemá co projít: jeho výsledek je hotový v okamžiku vzniku.
     * `closed` je jediný stav, ze kterého workflow nenabízí nic než vyžádání
     * opravy, a to je u převzatého běhu zakázané zvlášť.
     */
    public function insertRun(
        int $supplierId,
        string $periodStart,
        string $paymentDate,
        ?int $actorUserId,
    ): int {
        $statement = $this->db->pdo()->prepare(
            "INSERT INTO payroll_runs
                (supplier_id, office_id, period_start, payment_date, status,
                 run_kind, created_by, updated_by)
             VALUES (?, NULL, ?, ?, 'closed', 'takeover', ?, ?)",
        );
        $statement->execute([
            $supplierId,
            $periodStart,
            $paymentDate,
            $actorUserId,
            $actorUserId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Vrátí zrušený obal zpátky do hry; `run_kind` se nemění, a ani nesmí. */
    public function reviveRun(
        int $supplierId,
        int $runId,
        string $paymentDate,
        ?int $actorUserId,
    ): void {
        $statement = $this->db->pdo()->prepare(
            "UPDATE payroll_runs
                SET status = 'closed',
                    payment_date = ?,
                    updated_by = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND run_kind = 'takeover'",
        );
        $statement->execute([$paymentDate, $actorUserId, $supplierId, $runId]);
    }

    public function cancelRun(int $supplierId, int $runId, ?int $actorUserId): void
    {
        $statement = $this->db->pdo()->prepare(
            "UPDATE payroll_runs
                SET status = 'cancelled',
                    updated_by = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND run_kind = 'takeover'",
        );
        $statement->execute([$actorUserId, $supplierId, $runId]);
    }

    /**
     * @param array<string,mixed> $inputSnapshot
     * @param array<string,mixed> $resultSnapshot
     * @param list<string> $sources
     */
    public function insertTakeover(
        int $supplierId,
        int $runId,
        string $periodStart,
        array $sources,
        array $inputSnapshot,
        string $inputSnapshotHash,
        array $resultSnapshot,
        string $resultSnapshotHash,
        int $employeeCount,
        int $relationshipCount,
        ?int $actorUserId,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_takeover_runs
                (supplier_id, run_id, period_start, takeover_sources,
                 input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash,
                 employee_count, relationship_count, built_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $runId,
            $periodStart,
            implode(',', $sources),
            CanonicalJson::encode($inputSnapshot),
            $inputSnapshotHash,
            CanonicalJson::encode($resultSnapshot),
            $resultSnapshotHash,
            $employeeCount,
            $relationshipCount,
            $actorUserId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param list<array{
     *   evidence_kind:string,
     *   certainty:string,
     *   employee_id:?int,
     *   external_person_ref:string,
     *   amount_minor:int,
     *   paid_on:?string
     * }> $evidence
     */
    public function insertEvidence(
        int $supplierId,
        int $runId,
        array $evidence,
        ?int $actorUserId,
    ): int {
        if ($evidence === []) {
            return 0;
        }
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_takeover_payment_evidence
                (supplier_id, run_id, evidence_kind, certainty, employee_id,
                 external_person_ref, amount_minor, paid_on, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($evidence as $row) {
            $statement->execute([
                $supplierId,
                $runId,
                $row['evidence_kind'],
                $row['certainty'],
                $row['employee_id'],
                $row['external_person_ref'],
                $row['amount_minor'],
                $row['paid_on'],
                $actorUserId,
            ]);
        }

        return count($evidence);
    }

    /** @return array<string,mixed>|null */
    public function takeover(int $supplierId, int $runId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_takeover_runs
              WHERE supplier_id = ? AND run_id = ?',
        );
        $statement->execute([$supplierId, $runId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'run_id' => (int) $row['run_id'],
            'period_start' => (string) $row['period_start'],
            'sources' => $row['takeover_sources'] === ''
                ? []
                : explode(',', (string) $row['takeover_sources']),
            'result_snapshot' => json_decode(
                (string) $row['result_snapshot_json'],
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            'result_snapshot_hash' => (string) $row['result_snapshot_hash'],
            'input_snapshot_hash' => (string) $row['input_snapshot_hash'],
            'employee_count' => (int) $row['employee_count'],
            'relationship_count' => (int) $row['relationship_count'],
            'built_at' => (string) $row['built_at'],
        ];
    }

    /**
     * Doložení plateb převzatého měsíce.
     *
     * Jméno zaměstnance se dotahuje jen pro zobrazení; osoba, která se do
     * MyÚčta nepřevedla, zůstane pod identitou z původního systému.
     *
     * @return list<array<string,mixed>>
     */
    public function evidence(int $supplierId, int $runId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT evidence.id, evidence.evidence_kind, evidence.certainty,
                    evidence.employee_id, evidence.external_person_ref,
                    evidence.amount_minor, evidence.currency_code,
                    evidence.paid_on, employee.full_name
               FROM payroll_takeover_payment_evidence evidence
          LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = evidence.supplier_id
                AND employee.id = evidence.employee_id
              WHERE evidence.supplier_id = ? AND evidence.run_id = ?
              ORDER BY evidence.evidence_kind, evidence.person_scope,
                       evidence.external_person_ref, evidence.id',
        );
        $statement->execute([$supplierId, $runId]);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'evidence_kind' => (string) $row['evidence_kind'],
                'certainty' => (string) $row['certainty'],
                'employee_id' => $row['employee_id'] === null
                    ? null
                    : (int) $row['employee_id'],
                'external_person_ref' => (string) $row['external_person_ref'],
                'employee_name' => $row['full_name'] === null
                    ? null
                    : (string) $row['full_name'],
                'amount_minor' => (int) $row['amount_minor'],
                'currency_code' => (string) $row['currency_code'],
                'paid_on' => $row['paid_on'] === null
                    ? null
                    : (string) $row['paid_on'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * Zahodí zrcadlo, ať se dá převzít znovu.
     *
     * Mazat se smí PRÁVĚ TADY a právě proto, že převzatý běh není doklad:
     * nevydal ho tenhle systém a nic se o něj neopírá — ani revize, ani účetní
     * dávka, ani platební závazek, protože ty nad převzatým během vzniknout
     * nemohou (triggery migrace 1853).
     */
    public function deleteTakeover(int $supplierId, int $runId): void
    {
        $evidence = $this->db->pdo()->prepare(
            'DELETE FROM payroll_takeover_payment_evidence
              WHERE supplier_id = ? AND run_id = ?',
        );
        $evidence->execute([$supplierId, $runId]);

        $takeover = $this->db->pdo()->prepare(
            'DELETE FROM payroll_takeover_runs
              WHERE supplier_id = ? AND run_id = ?',
        );
        $takeover->execute([$supplierId, $runId]);
    }

    /**
     * Období roku, která už mají převzatý běh, i s jeho `run_id`.
     *
     * @return array<string,int> `YYYY-MM` => id běhu
     */
    public function builtRuns(int $supplierId, int $year): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT DATE_FORMAT(takeover.period_start, "%Y-%m") AS period,
                    takeover.run_id
               FROM payroll_takeover_runs takeover
               JOIN payroll_runs run
                 ON run.supplier_id = takeover.supplier_id
                AND run.id = takeover.run_id
              WHERE takeover.supplier_id = ?
                AND takeover.period_start >= ?
                AND takeover.period_start < ?
                AND run.status <> "cancelled"
              ORDER BY takeover.period_start',
        );
        $statement->execute([
            $supplierId,
            sprintf('%04d-01-01', $year),
            sprintf('%04d-01-01', $year + 1),
        ]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['period']] = (int) $row['run_id'];
        }

        return $result;
    }
}
