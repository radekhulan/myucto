<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Podklady a neměnné revize mzdového výměru (§ 136 ZP, migrace 1927).
 *
 * Podklady se čtou k jednomu dni účinnosti výměru: podmínky vztahu, opakující
 * se mzdové složky a zaměstnavatelská mzdová politika (termín výplaty) platné
 * k tomu dni. Revize jsou append-only; stejné podklady vrátí tutéž revizi.
 */
final class PayrollWageStatementRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function employment(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.employee_id, employment.relation_type,
                    employment.status, employment.start_date, employment.actual_start_date,
                    employment.end_date, employee.full_name, employee.birth_date
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ? AND employment.id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function termsOn(int $supplierId, int $employmentId, string $date): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, effective_from, effective_to, monthly_gross_minor, weekly_hours,
                    remuneration_type, hourly_rate_minor, remuneration_basis,
                    workload_basis_points, work_place, regular_workplace, row_version
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
                AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $date, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Nejpozdější začátek účinnosti podmínek vztahu, který není v budoucnu.
     */
    public function latestTermsStart(int $supplierId, int $employmentId, string $today): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MAX(effective_from)
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ? AND effective_from <= ?'
        );
        $stmt->execute([$supplierId, $employmentId, $today]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<array<string,mixed>> */
    public function recurringComponentsOn(int $supplierId, int $employmentId, string $date): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT recurring.id, recurring.calculation_kind, recurring.amount_minor,
                    recurring.rate_basis_points, recurring.allocation_rule,
                    recurring.row_version, component.code, component.name
               FROM payroll_recurring_components recurring
               JOIN payroll_component_definitions component
                 ON component.supplier_id = recurring.supplier_id
                AND component.id = recurring.component_id
              WHERE recurring.supplier_id = ? AND recurring.employment_id = ?
                AND recurring.is_active = 1
                AND recurring.valid_from <= ?
                AND (recurring.valid_to IS NULL OR recurring.valid_to >= ?)
              ORDER BY component.code, recurring.id'
        );
        $stmt->execute([$supplierId, $employmentId, $date, $date]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<string> druhy cílů aktivních pravidel výplaty (bank, cash, …) */
    public function payoutDestinations(int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT destination_kind
               FROM payroll_payout_rules
              WHERE supplier_id = ? AND employee_id = ? AND is_active = 1
              ORDER BY destination_kind'
        );
        $stmt->execute([$supplierId, $employeeId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed>|null */
    public function findBySourceManifest(int $supplierId, int $employmentId, string $manifestHash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_wage_statement_revisions
              WHERE supplier_id = ? AND employment_id = ? AND source_manifest_hash = ?'
        );
        $stmt->execute([$supplierId, $employmentId, $manifestHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function latest(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_wage_statement_revisions
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY revision_no DESC
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function insertApproved(array $record): array
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_wage_statement_revisions
                (supplier_id, employee_id, employment_id, effective_from, revision_no,
                 previous_revision_id, snapshot_json, snapshot_hash, source_manifest_hash,
                 approved_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $record['supplier_id'],
            $record['employee_id'],
            $record['employment_id'],
            $record['effective_from'],
            $record['revision_no'],
            $record['previous_revision_id'],
            $record['snapshot_json'],
            $record['snapshot_hash'],
            $record['source_manifest_hash'],
            $record['approved_by'],
        ]);

        return $this->find((int) $record['supplier_id'], (int) $this->db->pdo()->lastInsertId())
            ?? throw new \RuntimeException('Revizi mzdového výměru se nepodařilo načíst.');
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_wage_statement_revisions WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        foreach (['id', 'supplier_id', 'employee_id', 'employment_id', 'revision_no'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['previous_revision_id', 'approved_by'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }

        return $row;
    }
}
