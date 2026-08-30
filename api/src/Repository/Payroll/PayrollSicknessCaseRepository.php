<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Evidence případů dávek nemocenského pojištění (NEMPRI, HZUPN).
 *
 * Repozitář vrací HOLÁ FAKTA. Jestli se z případu smí sestavit datová věta
 * a do kdy se má podat, rozhodují {@see \MyInvoice\Service\Payroll\Submission\Sickness\SicknessXmlValidator}
 * a {@see \MyInvoice\Service\Payroll\Submission\Sickness\SicknessDeadlinePolicy}
 * — obojí musí jít otestovat bez databáze.
 */
final readonly class PayrollSicknessCaseRepository
{
    public function __construct(private Connection $db) {}

    /** @return array<string,mixed>|null */
    public function find(
        int $supplierId,
        string $environment,
        int $caseId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT sickness.*, employee.full_name
               FROM payroll_sickness_cases sickness
               JOIN payroll_employees employee
                 ON employee.supplier_id = sickness.supplier_id
                AND employee.id = sickness.employee_id
              WHERE sickness.supplier_id = ?
                AND sickness.environment = ?
                AND sickness.id = ?'
        );
        $statement->execute([$supplierId, $environment, $caseId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listForSupplier(
        int $supplierId,
        string $environment,
        ?int $employmentId = null,
    ): array {
        $sql =
            'SELECT sickness.*, employee.full_name,
                    employment.code AS employment_code,
                    employment.start_date AS employment_start_date,
                    employment.end_date AS employment_end_date
               FROM payroll_sickness_cases sickness
               JOIN payroll_employees employee
                 ON employee.supplier_id = sickness.supplier_id
                AND employee.id = sickness.employee_id
               JOIN payroll_employments employment
                 ON employment.supplier_id = sickness.supplier_id
                AND employment.id = sickness.employment_id
              WHERE sickness.supplier_id = ?
                AND sickness.environment = ?';
        $params = [$supplierId, $environment];
        if ($employmentId !== null) {
            $sql .= ' AND sickness.employment_id = ?';
            $params[] = $employmentId;
        }
        $sql .= ' ORDER BY sickness.incapacity_from DESC, sickness.id DESC';
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Fakta pracovního vztahu, ze kterých se datová věta sestaví.
     *
     * `activity_code` se čte z podmínek účinných ke dni vzniku sociální
     * události, ne z posledních platných: `zamestnani/druhCinnosti` má
     * vypovídat o vztahu v době, kdy událost nastala.
     *
     * @return array<string,mixed>|null
     */
    public function findEmploymentContext(
        int $supplierId,
        int $employmentId,
        string $onDate,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id AS employment_id,
                    employment.employee_id,
                    employment.relation_type,
                    employment.start_date,
                    employment.actual_start_date,
                    employment.end_date,
                    employment.effective_status,
                    employee.full_name,
                    terms.activity_code,
                    supplier.company_name AS employer_name,
                    supplier.ic AS employer_business_id,
                    supplier.cssz_vsdp AS employer_variable_symbol,
                    supplier.cssz_ossz_code AS employer_ossz_code
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               JOIN supplier
                 ON supplier.id = employment.supplier_id
          LEFT JOIN payroll_employment_terms terms
                 ON terms.supplier_id = employment.supplier_id
                AND terms.employment_id = employment.id
                AND terms.effective_from <= ?
                AND (terms.effective_to IS NULL OR terms.effective_to >= ?)
              WHERE employment.supplier_id = ?
                AND employment.id = ?
              ORDER BY terms.effective_from DESC, terms.id DESC
              LIMIT 1'
        );
        $statement->execute([$onDate, $onDate, $supplierId, $employmentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Případy téhož vztahu, které se s obdobím překrývají. Dvě neschopnosti
     * téhož druhu ve stejný den by znamenaly dvě podání za tutéž věc.
     *
     * @return list<array<string,mixed>>
     */
    public function overlappingForEmployment(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $benefitKind,
        string $incapacityFrom,
        ?string $incapacityTo,
        ?int $excludeCaseId = null,
    ): array {
        $sql =
            'SELECT id, benefit_kind, incapacity_from, incapacity_to, status
               FROM payroll_sickness_cases
              WHERE supplier_id = ?
                AND environment = ?
                AND employment_id = ?
                AND benefit_kind = ?
                AND status <> "cancelled"
                AND (incapacity_to IS NULL OR incapacity_to >= ?)
                AND (? IS NULL OR incapacity_from <= ?)';
        $params = [
            $supplierId,
            $environment,
            $employmentId,
            $benefitKind,
            $incapacityFrom,
            $incapacityTo,
            $incapacityTo,
        ];
        if ($excludeCaseId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeCaseId;
        }
        $sql .= ' ORDER BY incapacity_from, id';
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Nesplněné případy s termínem v okně — podklad hlídače termínů.
     *
     * Vrací i případy, ze kterých ještě nikdo podání nepřipravil. To je celý
     * smysl: lhůta podle § 97 odst. 2 běží od 15. dne neschopnosti bez ohledu
     * na to, jestli si toho někdo všiml.
     *
     * @return list<array<string,mixed>>
     */
    public function openCases(
        int $supplierId,
        string $environment,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT sickness.id AS case_id,
                    sickness.employee_id,
                    sickness.employment_id,
                    sickness.benefit_kind,
                    sickness.incapacity_from,
                    sickness.incapacity_to,
                    sickness.status,
                    sickness.nempri_submission_id,
                    sickness.hzupn_submission_id,
                    employee.full_name
               FROM payroll_sickness_cases sickness
               JOIN payroll_employees employee
                 ON employee.supplier_id = sickness.supplier_id
                AND employee.id = sickness.employee_id
              WHERE sickness.supplier_id = ?
                AND sickness.environment = ?
                AND sickness.status IN ("draft", "prepared", "submitted", "rejected")
              ORDER BY sickness.incapacity_from, sickness.id'
        );
        $statement->execute([$supplierId, $environment]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $data */
    public function insert(
        int $supplierId,
        string $environment,
        array $data,
    ): int {
        $columns = array_keys($data);
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_sickness_cases
                 (supplier_id, environment, ' . implode(', ', $columns) . ')
             VALUES (?, ?, ' . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $statement->execute([
            $supplierId,
            $environment,
            ...array_values($data),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Optimistický zápis. Bez `row_version` v podmínce by dva souběžné
     * požadavky mohly z odmítnutého případu udělat přijatý.
     *
     * @param array<string,mixed> $changes
     */
    public function update(
        int $supplierId,
        string $environment,
        int $caseId,
        int $rowVersion,
        array $changes,
    ): bool {
        $assignments = ['row_version = row_version + 1'];
        $params = [];
        foreach ($changes as $column => $value) {
            $assignments[] = $column . ' = ?';
            $params[] = $value;
        }
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_sickness_cases
                SET ' . implode(', ', $assignments) . '
              WHERE supplier_id = ?
                AND environment = ?
                AND id = ?
                AND row_version = ?'
        );
        $statement->execute([
            ...$params,
            $supplierId,
            $environment,
            $caseId,
            $rowVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    /** @return list<array{from:string,to:string}> */
    public function workDays(
        int $supplierId,
        string $environment,
        int $caseId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT worked_from, worked_to
               FROM payroll_sickness_case_work_days
              WHERE supplier_id = ?
                AND environment = ?
                AND case_id = ?
              ORDER BY worked_from'
        );
        $statement->execute([$supplierId, $environment, $caseId]);
        $intervals = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $intervals[] = [
                'from' => (string) $row['worked_from'],
                'to' => (string) $row['worked_to'],
            ];
        }

        return $intervals;
    }

    /**
     * Přepíše dny práce v době neschopnosti.
     *
     * Přepis, ne přírůstek: hlášení nese ÚPLNÝ seznam intervalů a doplňovat
     * je po jednom by znamenalo, že smazaný interval v hlášení zůstane.
     *
     * @param list<array{from:string,to:string}> $intervals
     */
    public function replaceWorkDays(
        int $supplierId,
        string $environment,
        int $caseId,
        array $intervals,
    ): void {
        $delete = $this->db->pdo()->prepare(
            'DELETE FROM payroll_sickness_case_work_days
              WHERE supplier_id = ?
                AND environment = ?
                AND case_id = ?'
        );
        $delete->execute([$supplierId, $environment, $caseId]);
        if ($intervals === []) {
            return;
        }
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_sickness_case_work_days
                 (supplier_id, environment, case_id, worked_from, worked_to)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($intervals as $interval) {
            $insert->execute([
                $supplierId,
                $environment,
                $caseId,
                $interval['from'],
                $interval['to'],
            ]);
        }
    }

    public function transaction(callable $work): mixed
    {
        return $this->db->transaction($work);
    }
}
