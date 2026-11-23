<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Osoby k pracovním vztahům, které jsou předmětem mzdové povinnosti
 * (`subject_reference` tvaru `payroll_employment:{id}` nebo `employment:{id}`).
 */
final class PayrollObligationSubjectRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<int> $employmentIds
     * @return array<int,array{full_name:string,employee_id:int}> `employment_id` → osoba
     */
    public function employmentPeople(int $supplierId, array $employmentIds): array
    {
        $employmentIds = array_values(array_unique(array_filter(
            $employmentIds,
            static fn (int $id): bool => $id > 0,
        )));
        if ($employmentIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($employmentIds), '?'));
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id, employee.id AS employee_id, employee.full_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ?
                AND employment.id IN (' . $placeholders . ')',
        );
        $statement->execute([$supplierId, ...$employmentIds]);

        $people = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) $row['full_name']);
            if ($name !== '') {
                $people[(int) $row['id']] = [
                    'full_name' => $name,
                    'employee_id' => (int) $row['employee_id'],
                ];
            }
        }

        return $people;
    }

    /**
     * SMAZANÉ pracovní vztahy podle auditního záznamu o smazání
     * (`payroll.employment.deleted` nese `employee_id`) a jejich osoba, je-li
     * dál v evidenci; jinak `null`. Jméno smazané osoby se z auditní stopy
     * zpátky do přehledů nevytahuje. Vztah bez záznamu o smazání ve výsledku
     * není: neznámý odkaz se za smazaný vztah nevydává.
     *
     * @param list<int> $employmentIds
     * @return array<int,array{full_name:string,employee_id:int}|null> `employment_id` → osoba
     */
    public function deletedEmployments(int $supplierId, array $employmentIds): array
    {
        $employmentIds = array_values(array_unique(array_filter(
            $employmentIds,
            static fn (int $id): bool => $id > 0,
        )));
        if ($employmentIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($employmentIds), '?'));
        // Jde po indexu `idx_al_entity` (druh entity, id entity); firma je
        // podmínka navíc, aby se cizí záznam o smazání nepřiznal.
        $statement = $this->db->pdo()->prepare(
            'SELECT log.entity_id, employee.id AS employee_id, employee.full_name
               FROM activity_log log
               LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = log.supplier_id
                AND employee.id = JSON_VALUE(log.payload, "$.employee_id")
              WHERE log.entity_type = "payroll_employment"
                AND log.entity_id IN (' . $placeholders . ')
                AND log.action = "payroll.employment.deleted"
                AND log.supplier_id = ?',
        );
        $statement->execute([...$employmentIds, $supplierId]);

        $deleted = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['full_name'] ?? ''));
            $deleted[(int) $row['entity_id']] = $name === '' || $row['employee_id'] === null
                ? null
                : ['full_name' => $name, 'employee_id' => (int) $row['employee_id']];
        }

        return $deleted;
    }
}
