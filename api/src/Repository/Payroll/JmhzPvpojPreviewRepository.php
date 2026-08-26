<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class JmhzPvpojPreviewRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollStatutoryResultRepository $statutoryResults,
    ) {}

    /**
     * @return array{
     *   revision:array<string,mixed>,
     *   statutory_result:array<string,mixed>,
     *   offices:list<array<string,mixed>>
     * }|null
     */
    public function findSource(int $supplierId, int $revisionId): ?array
    {
        if ($supplierId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a mzdová revize musí být kladná čísla.',
            );
        }

        $statement = $this->db->pdo()->prepare(
            'SELECT revision.id, revision.run_id, revision.revision_no,
                    revision.status AS revision_status,
                    revision.input_snapshot_json,
                    revision.input_snapshot_hash,
                    run.period_start, run.current_revision_no
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND revision.id = ?',
        );
        $statement->execute([$supplierId, $revisionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $statutory = $this->statutoryResults->find(
            $supplierId,
            $revisionId,
            'social_insurance',
        );
        if ($statutory === null) {
            return null;
        }

        /*
         * Registrace u OSSZ je na účtárně, takže přehled potřebuje kód, název
         * a variabilní symbol každé účtárny běhu. Číselník je malý a vztah na
         * účtárnu je až ve zmrazeném vstupu, proto se načte celý pro firmu
         * a výběr účtárny si udělá builder.
         */
        $officeStatement = $this->db->pdo()->prepare(
            'SELECT id, code, name, social_security_variable_symbol, is_active
               FROM payroll_offices
              WHERE supplier_id = ?
              ORDER BY id',
        );
        $officeStatement->execute([$supplierId]);
        $offices = [];
        foreach ($officeStatement->fetchAll(PDO::FETCH_ASSOC) as $office) {
            if (!is_array($office)) {
                throw new \UnexpectedValueException(
                    'Databáze vrátila neplatnou mzdovou účtárnu.',
                );
            }
            $variableSymbol = $office['social_security_variable_symbol'] ?? null;
            $offices[] = [
                'id' => $this->dbPositiveInt($office['id'] ?? null, 'office.id'),
                'code' => $this->dbString($office['code'] ?? null, 'office.code'),
                'name' => $this->dbString($office['name'] ?? null, 'office.name'),
                'social_security_variable_symbol' => $variableSymbol === null
                    ? null
                    : $this->dbString(
                        $variableSymbol,
                        'office.social_security_variable_symbol',
                    ),
                'is_active' => (int) ($office['is_active'] ?? 0) === 1,
            ];
        }

        return [
            'revision' => [
                'id' => $this->dbPositiveInt($row['id'] ?? null, 'revision.id'),
                'run_id' => $this->dbPositiveInt(
                    $row['run_id'] ?? null,
                    'revision.run_id',
                ),
                'revision_no' => $this->dbPositiveInt(
                    $row['revision_no'] ?? null,
                    'revision.revision_no',
                ),
                'revision_status' => $this->dbString(
                    $row['revision_status'] ?? null,
                    'revision.revision_status',
                ),
                'current_revision_no' => $this->dbPositiveInt(
                    $row['current_revision_no'] ?? null,
                    'revision.current_revision_no',
                ),
                'period_start' => $this->dbString(
                    $row['period_start'] ?? null,
                    'revision.period_start',
                ),
                'input_snapshot_json' => $this->dbString(
                    $row['input_snapshot_json'] ?? null,
                    'revision.input_snapshot_json',
                ),
                'input_snapshot_hash' => $this->dbHash(
                    $row['input_snapshot_hash'] ?? null,
                    'revision.input_snapshot_hash',
                ),
            ],
            'statutory_result' => $statutory,
            'offices' => $offices,
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

    private function dbString(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Databázové pole {$field} není neprázdný text.",
            );
        }

        return $value;
    }

    private function dbHash(mixed $value, string $field): string
    {
        $hash = $this->dbString($value, $field);
        if (preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
            throw new \UnexpectedValueException(
                "Databázové pole {$field} není platný SHA-256.",
            );
        }

        return $hash;
    }
}
