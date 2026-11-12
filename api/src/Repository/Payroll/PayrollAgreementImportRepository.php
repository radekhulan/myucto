<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Čtení pro import dohod: vztahy osob z evidence, podle kterých import pozná
 * dohodu, která už existuje (opakovaný import téhož souboru nic nezaloží).
 */
final class PayrollAgreementImportRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Nearchivované vztahy osob jedním dotazem.
     *
     * @param list<int> $employeeIds
     * @return array<int,list<array{employment_id:int,code:string,relation_type:string,status:string,start_on:?string,end_on:?string}>>
     */
    public function employmentsOf(int $supplierId, array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_filter($employeeIds, static fn (int $id): bool => $id > 0)));
        if ($employeeIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id, code, relation_type, status,
                    COALESCE(actual_start_date, start_date) AS start_on, end_date
               FROM payroll_employments
              WHERE supplier_id = ? AND is_legacy_projection = 0 AND status NOT IN ("archived", "no_show")
                AND employee_id IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ')
              ORDER BY employee_id, start_on, id'
        );
        $stmt->execute([$supplierId, ...$employeeIds]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employee_id']][] = [
                'employment_id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'relation_type' => (string) $row['relation_type'],
                'status' => (string) $row['status'],
                'start_on' => is_string($row['start_on']) ? $row['start_on'] : null,
                'end_on' => is_string($row['end_date']) ? $row['end_date'] : null,
            ];
        }

        return $result;
    }
}
