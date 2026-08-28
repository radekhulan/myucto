<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class ApprovedRevisionPayslipRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   period_start:string,
     *   result_snapshot_json:string,
     *   result_snapshot_hash:string,
     *   people:list<array<string,mixed>>
     * }|null
     */
    public function source(
        int $supplierId,
        int $runId,
        int $revisionId,
    ): ?array {
        return $this->loadSource($supplierId, $runId, $revisionId, false);
    }

    /**
     * @return array{
     *   period_start:string,
     *   result_snapshot_json:string,
     *   result_snapshot_hash:string,
     *   people:list<array<string,mixed>>
     * }|null
     */
    public function lockSource(
        int $supplierId,
        int $runId,
        int $revisionId,
    ): ?array {
        return $this->loadSource($supplierId, $runId, $revisionId, true);
    }

    /**
     * @return array{
     *   period_start:string,
     *   result_snapshot_json:string,
     *   result_snapshot_hash:string,
     *   people:list<array<string,mixed>>
     * }|null
     */
    private function loadSource(
        int $supplierId,
        int $runId,
        int $revisionId,
        bool $forUpdate,
    ): ?array {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        // Odsunutá (`superseded`) revize tu být NESMÍ ani v náhledu: z revize,
        // kterou nahradila opravná, se výplatní páska už netiskne. Novější
        // schválená revize proto zdroj rovnou vyřazuje — pojistka pro běhy
        // z doby, kdy se předchozí schválená revize ještě neodsouvala.
        //
        // `reviewed` je jen pro NÁHLED před schválením; zamčené čtení, ze
        // kterého se opravdu vystavuje, ho nepřijme.
        $statuses = $forUpdate
            ? '("approved")'
            : '("reviewed", "approved")';
        $revision = $this->db->pdo()->prepare(
            'SELECT run.period_start,
                    revision.result_snapshot_json,
                    revision.result_snapshot_hash
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND revision.run_id = ?
                AND revision.id = ?
                AND revision.status IN ' . $statuses . '
                AND revision.result_snapshot_json IS NOT NULL
                AND revision.result_snapshot_hash IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1
                      FROM payroll_run_revisions newer
                     WHERE newer.supplier_id = revision.supplier_id
                       AND newer.run_id = revision.run_id
                       AND newer.revision_no > revision.revision_no
                       AND newer.status = "approved"
                       AND newer.result_snapshot_hash IS NOT NULL
                )'
            . $lock
        );
        $revision->execute([$supplierId, $runId, $revisionId]);
        $row = $revision->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        foreach ([
            'period_start',
            'result_snapshot_json',
            'result_snapshot_hash',
        ] as $key) {
            if (!is_string($row[$key] ?? null)) {
                throw new \UnexpectedValueException(
                    "Zdroj výplatních pásek nemá textové pole {$key}.",
                );
            }
        }

        $people = $this->db->pdo()->prepare(
            'SELECT employee_id, status, result_json, result_hash
               FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id = ?
              ORDER BY employee_id'
            . $lock
        );
        $people->execute([$supplierId, $revisionId]);
        $personRows = [];
        foreach ($people->fetchAll(PDO::FETCH_ASSOC) as $person) {
            if (
                !is_array($person)
                || (
                    !is_int($person['employee_id'] ?? null)
                    && !is_string($person['employee_id'] ?? null)
                )
                || !is_string($person['status'] ?? null)
                || (
                    $person['result_json'] !== null
                    && !is_string($person['result_json'])
                )
                || (
                    $person['result_hash'] !== null
                    && !is_string($person['result_hash'])
                )
            ) {
                throw new \UnexpectedValueException(
                    'Zdroj výplatních pásek obsahuje neplatný výsledek osoby.',
                );
            }
            $personRows[] = [
                'employee_id' => $person['employee_id'],
                'status' => $person['status'],
                'result_json' => $person['result_json'],
                'result_hash' => $person['result_hash'],
            ];
        }

        return [
            'period_start' => $row['period_start'],
            'result_snapshot_json' => $row['result_snapshot_json'],
            'result_snapshot_hash' => $row['result_snapshot_hash'],
            'people' => $personRows,
        ];
    }
}
