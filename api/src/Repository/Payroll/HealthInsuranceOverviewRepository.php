<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class HealthInsuranceOverviewRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollStatutoryResultRepository $statutoryResults,
    ) {}

    /**
     * @return array{
     *   revision:array<string,mixed>,
     *   statutory_result:array<string,mixed>
     * }|null
     */
    public function findApprovedHealthResult(
        int $supplierId,
        int $revisionId,
    ): ?array {
        if ($supplierId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a mzdová revize musí být kladná čísla.',
            );
        }

        $statement = $this->db->pdo()->prepare(
            'SELECT revision.id,
                    revision.run_id,
                    revision.revision_no,
                    revision.revision_kind,
                    revision.status AS revision_status,
                    run.period_start,
                    run.current_revision_no
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND revision.id = ?',
        );
        $statement->execute([$supplierId, $revisionId]);
        $revision = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($revision)) {
            return null;
        }

        $statutory = $this->statutoryResults->find(
            $supplierId,
            $revisionId,
            'health_insurance',
        );
        if ($statutory === null) {
            return null;
        }

        return [
            'revision' => [
                'id' => $this->dbPositiveInt($revision['id'] ?? null, 'id'),
                'run_id' => $this->dbPositiveInt(
                    $revision['run_id'] ?? null,
                    'run_id',
                ),
                'revision_no' => $this->dbPositiveInt(
                    $revision['revision_no'] ?? null,
                    'revision_no',
                ),
                'revision_kind' => $revision['revision_kind'] ?? null,
                'revision_status' => $revision['revision_status'] ?? null,
                'period_start' => $revision['period_start'] ?? null,
                'current_revision_no' => $this->dbPositiveInt(
                    $revision['current_revision_no'] ?? null,
                    'current_revision_no',
                ),
            ],
            'statutory_result' => $statutory,
        ];
    }

    private function dbPositiveInt(mixed $value, string $field): int
    {
        if ((is_int($value)
                || (is_string($value)
                    && preg_match('/^[1-9][0-9]*$/D', $value) === 1))
            && (int) $value > 0
        ) {
            return (int) $value;
        }

        throw new \UnexpectedValueException(
            "Databázové pole {$field} není kladné celé číslo.",
        );
    }
}
