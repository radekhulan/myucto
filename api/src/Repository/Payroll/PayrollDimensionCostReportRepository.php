<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Nákladové alokace účetního můstku mezd pro report „náklady na zaměstnance
 * po dimenzi".
 *
 * Čte CÍLOVÉ alokace účinné dávky každého běhu (poslední zaúčtovaná nebo
 * bezezměnová revize), ne deník: do deníku se po zaměstnanci neúčtuje
 * a alokace nesou pracovní vztah v klíči. Účinnou dávku vybírá stejné
 * pravidlo jako {@see PayrollPostingBatchRepository::latestEffectiveBefore()}.
 *
 * Nákladová alokace se pozná podle NÁKLADOVÉ STRANY (klíč `…:debit`)
 * a účtu třídy 5, ne podle znaménka: záporná složka (oprava minulého měsíce,
 * srážka z hrubé mzdy) nese zápornou částku na téže straně a náklad snižuje.
 * Filtr na kladné částky by ji zahodil a report by náklad nadhodnotil
 * oproti deníku.
 */
final class PayrollDimensionCostReportRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array{
     *   period_start:string,
     *   revision_id:int,
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   cost_center:?string,
     *   dimensions:?string
     * }>
     */
    public function costAllocations(int $supplierId, int $year): array
    {
        $statement = $this->db->pdo()->prepare(
            'WITH effective AS (
                SELECT batch.id AS batch_id,
                       batch.revision_id,
                       run.period_start,
                       ROW_NUMBER() OVER (
                           PARTITION BY batch.run_id
                           ORDER BY revision.revision_no DESC
                       ) AS position
                  FROM payroll_posting_batches batch
                  JOIN payroll_run_revisions revision
                    ON revision.supplier_id = batch.supplier_id
                   AND revision.id = batch.revision_id
                  JOIN payroll_runs run
                    ON run.supplier_id = batch.supplier_id
                   AND run.id = batch.run_id
                 WHERE batch.supplier_id = ?
                   AND batch.status IN ("posted", "no_change")
                   AND run.period_start BETWEEN ? AND ?
             )
             SELECT effective.period_start, effective.revision_id, allocation.allocation_key,
                    allocation.account_code, allocation.signed_minor,
                    allocation.cost_center, allocation.dimensions
               FROM effective
               JOIN payroll_posting_allocations allocation
                 ON allocation.supplier_id = ?
                AND allocation.batch_id = effective.batch_id
              WHERE effective.position = 1
                AND allocation.allocation_key LIKE "%:debit"
                AND allocation.account_code LIKE "5%"
              ORDER BY effective.period_start, allocation.allocation_key'
        );
        $statement->execute([
            $supplierId,
            sprintf('%04d-01-01', $year),
            sprintf('%04d-12-31', $year),
            $supplierId,
        ]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'period_start' => (string) $row['period_start'],
                'revision_id' => (int) $row['revision_id'],
                'allocation_key' => (string) $row['allocation_key'],
                'account_code' => (string) $row['account_code'],
                'signed_minor' => (int) $row['signed_minor'],
                'cost_center' => $row['cost_center'] === null ? null : (string) $row['cost_center'],
                'dimensions' => $row['dimensions'] === null ? null : (string) $row['dimensions'],
            ];
        }

        return $result;
    }

    /**
     * Zákonné pojištění odpovědnosti roku po čtvrtletích: součet řetězu závazků
     * čtvrtletí (předpis a jeho opravy se znaménkem), jak ho firma platí.
     *
     * Jde po indexu `(supplier_id, due_on, liability_kind)`: splatnost čtvrtletí
     * leží v jeho roce, u posledního čtvrtletí na začátku roku následujícího.
     * Rozsah splatností je záměrně širší, čtvrtletí samo určuje odkaz závazku.
     *
     * @return list<array{quarter_end_period:string,signed_minor:int}>
     */
    public function accidentInsuranceQuarters(int $supplierId, int $year): array
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT liability.liability_reference,
                    SUM(CASE liability.direction
                            WHEN 'outgoing' THEN CAST(liability.amount_minor AS SIGNED)
                            ELSE -CAST(liability.amount_minor AS SIGNED)
                        END) AS signed_minor
               FROM payroll_payment_liabilities liability
              WHERE liability.supplier_id = ?
                AND liability.due_on >= ?
                AND liability.due_on < ?
                AND liability.liability_kind = 'statutory_insurance'
                AND liability.liability_reference LIKE ?
              GROUP BY liability.liability_reference
              ORDER BY liability.liability_reference"
        );
        $statement->execute([
            $supplierId,
            sprintf('%04d-01-01', $year),
            sprintf('%04d-07-01', $year + 1),
            sprintf('accident-insurance:quarter:%04d-%%', $year),
        ]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (preg_match('/^accident-insurance:quarter:(\d{4})-(\d{2})$/D', (string) $row['liability_reference'], $match) !== 1) {
                continue;
            }
            $result[] = [
                'quarter_end_period' => sprintf('%04d-%02d-01', (int) $match[1], (int) $match[2] + 2),
                'signed_minor' => (int) $row['signed_minor'],
            ];
        }

        return $result;
    }

    /**
     * Má report smysl? Firma má zapnuté firemní dimenze, nebo vede aspoň jednu
     * mzdovou dimenzi (středisko, zakázku, činnost).
     */
    public function dimensionsInUse(int $supplierId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT firm.dimensions_enabled = 1
                    OR EXISTS (SELECT 1 FROM payroll_dimensions dimension
                                WHERE dimension.supplier_id = firm.id)
               FROM supplier firm
              WHERE firm.id = ?'
        );
        $statement->execute([$supplierId]);

        return (int) $statement->fetchColumn() === 1;
    }

    /** @return array<string,mixed>|null vstupní snapshot revize */
    public function revisionInputSnapshot(int $supplierId, int $revisionId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT input_snapshot_json
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $revisionId]);
        $json = $statement->fetchColumn();
        if (!is_string($json)) {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    /**
     * @param list<int> $employmentIds
     * @return array<int,array{employee_id:int,employee_name:string,employment_code:?string}>
     */
    public function employments(int $supplierId, array $employmentIds): array
    {
        $employmentIds = array_values(array_unique($employmentIds));
        if ($employmentIds === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.employee_id, employment.code,
                    employee.full_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ?
                AND employment.id IN (' . implode(',', array_fill(0, count($employmentIds), '?')) . ')'
        );
        $statement->execute([$supplierId, ...$employmentIds]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['id']] = [
                'employee_id' => (int) $row['employee_id'],
                'employee_name' => (string) $row['full_name'],
                'employment_code' => $row['code'] === null ? null : (string) $row['code'],
            ];
        }

        return $result;
    }

    /**
     * Názvy typů a hodnot firemních dimenzí — jen těch, které firma vidí.
     *
     * @param list<int> $valueIds
     * @return array<int,array{type_id:int,type_name:string,code:string,name:string}>
     */
    public function dimensionValues(int $supplierId, array $valueIds): array
    {
        $valueIds = array_values(array_unique($valueIds));
        if ($valueIds === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT value.id, value.type_id, type.name AS type_name,
                    value.code, value.name
               FROM dimension_values value
               JOIN dimension_types type ON type.id = value.type_id
               JOIN supplier firm ON firm.id = ?
              WHERE value.id IN (' . implode(',', array_fill(0, count($valueIds), '?')) . ')
                AND (value.supplier_id = firm.id
                     OR (value.supplier_group_id IS NOT NULL
                         AND value.supplier_group_id = firm.supplier_group_id))'
        );
        $statement->execute([$supplierId, ...$valueIds]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['id']] = [
                'type_id' => (int) $row['type_id'],
                'type_name' => (string) $row['type_name'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
            ];
        }

        return $result;
    }
}
