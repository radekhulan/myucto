<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket;
use PDO;

/**
 * Evidence měsíčních příjmů ze stravovacích služeb a rozdělení osvobozeného
 * spropitného (§ 6 odst. 9 písm. g) ZDP, od 2027).
 *
 * Měsíc nese tržby ze stravovacích služeb a způsob dělení: rovným dílem mezi
 * zaměstnance, kteří spropitné obdrželi (`per_capita`, jejich počet zadává
 * účetní), nebo podle určení zaměstnavatele (`employer_determined`, strop
 * na osobu v `payroll_food_service_tip_allocations`).
 */
final class PayrollFoodServiceTipRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   id:int, period_start:string, revenue_minor:int, allocation_mode:string,
     *   recipients_count:?int, evidence_reference:?string, note:?string,
     *   allocations:list<array{employee_id:int,exempt_cap_minor:int}>
     * }|null
     */
    public function find(int $supplierId, string $periodStart): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, period_start, revenue_minor, allocation_mode, recipients_count,
                    evidence_reference, note
               FROM payroll_food_service_tip_months
              WHERE supplier_id = ? AND period_start = ?'
        );
        $statement->execute([$supplierId, $periodStart]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $allocations = $this->db->pdo()->prepare(
            'SELECT employee_id, exempt_cap_minor
               FROM payroll_food_service_tip_allocations
              WHERE supplier_id = ? AND tip_month_id = ?
              ORDER BY employee_id'
        );
        $allocations->execute([$supplierId, (int) $row['id']]);

        return [
            'id' => (int) $row['id'],
            'period_start' => substr((string) $row['period_start'], 0, 10),
            'revenue_minor' => (int) $row['revenue_minor'],
            'allocation_mode' => (string) $row['allocation_mode'],
            'recipients_count' => $row['recipients_count'] === null ? null : (int) $row['recipients_count'],
            'evidence_reference' => $row['evidence_reference'] === null ? null : (string) $row['evidence_reference'],
            'note' => $row['note'] === null ? null : (string) $row['note'],
            'allocations' => array_map(
                static fn (array $allocation): array => [
                    'employee_id' => (int) $allocation['employee_id'],
                    'exempt_cap_minor' => (int) $allocation['exempt_cap_minor'],
                ],
                array_values($allocations->fetchAll(PDO::FETCH_ASSOC)),
            ),
        ];
    }

    /**
     * Zaměstnanci, kteří v měsíci mají schválené (nestornované) spropitné.
     *
     * @return list<int>
     */
    public function approvedRecipients(int $supplierId, string $periodStart): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT DISTINCT accumulator.employee_id
               FROM payroll_benefit_accumulators accumulator
               JOIN payroll_inputs input
                 ON input.supplier_id = accumulator.supplier_id
                AND input.id = accumulator.input_id
               JOIN payroll_component_definitions component
                 ON component.supplier_id = accumulator.supplier_id
                AND component.id = accumulator.component_id
              WHERE accumulator.supplier_id = ?
                AND accumulator.status = "active"
                AND input.period_start = ?
                AND component.exemption_basket = ?
              ORDER BY accumulator.employee_id'
        );
        $statement->execute([
            $supplierId,
            $periodStart,
            PayrollBenefitExemptionBasket::FoodServiceTips->value,
        ]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Přepíše evidenci měsíce. Volá se v transakci volajícího.
     *
     * @param list<array{employee_id:int,exempt_cap_minor:int}> $allocations
     */
    public function save(
        int $supplierId,
        string $periodStart,
        int $revenueMinor,
        string $allocationMode,
        ?int $recipientsCount,
        array $allocations,
        ?string $evidenceReference,
        ?string $note,
        ?int $actorUserId,
    ): void {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Evidence spropitného se ukládá v transakci.');
        }
        $pdo->prepare(
            'INSERT INTO payroll_food_service_tip_months
                (supplier_id, period_start, revenue_minor, allocation_mode,
                 recipients_count, evidence_reference, note, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                revenue_minor = VALUES(revenue_minor),
                allocation_mode = VALUES(allocation_mode),
                recipients_count = VALUES(recipients_count),
                evidence_reference = VALUES(evidence_reference),
                note = VALUES(note),
                updated_by = VALUES(updated_by)'
        )->execute([
            $supplierId,
            $periodStart,
            $revenueMinor,
            $allocationMode,
            $recipientsCount,
            $evidenceReference,
            $note,
            $actorUserId,
            $actorUserId,
        ]);
        $id = $this->monthId($supplierId, $periodStart);
        $pdo->prepare(
            'DELETE FROM payroll_food_service_tip_allocations
              WHERE supplier_id = ? AND tip_month_id = ?'
        )->execute([$supplierId, $id]);
        if ($allocations === []) {
            return;
        }
        $insert = $pdo->prepare(
            'INSERT INTO payroll_food_service_tip_allocations
                (supplier_id, tip_month_id, employee_id, exempt_cap_minor)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($allocations as $allocation) {
            $insert->execute([
                $supplierId,
                $id,
                $allocation['employee_id'],
                $allocation['exempt_cap_minor'],
            ]);
        }
    }

    /**
     * Zaměstnanci firmy z daného seznamu, kteří existují (cizí ID se tváří jako
     * neexistující).
     *
     * @param list<int> $employeeIds
     * @return list<int>
     */
    public function existingEmployees(int $supplierId, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employees
              WHERE supplier_id = ? AND id IN ('
                . implode(', ', array_fill(0, count($employeeIds), '?')) . ')'
        );
        $statement->execute([$supplierId, ...$employeeIds]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function monthId(int $supplierId, string $periodStart): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_food_service_tip_months
              WHERE supplier_id = ? AND period_start = ?'
        );
        $statement->execute([$supplierId, $periodStart]);

        return (int) $statement->fetchColumn();
    }
}
