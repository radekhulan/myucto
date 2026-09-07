<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollBankSubmissionSourceRepository
{
    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $batchId): ?array
    {
        $query = $this->db->pdo()->prepare('SELECT id, batch_reference, channel, direction, export_format,
            planned_payment_date, currency_code, declared_total_minor, declared_item_count,
            snapshot_ciphertext, snapshot_hash FROM payroll_payment_batches WHERE supplier_id = ? AND id = ?');
        $query->execute([$supplierId, $batchId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function hasSettlement(int $supplierId, int $batchId): bool
    {
        $query = $this->db->pdo()->prepare('WITH batch_liabilities AS (
            SELECT DISTINCT a.liability_id FROM payroll_payment_allocations a
            JOIN payroll_payment_items i ON i.supplier_id = a.supplier_id AND i.id = a.item_id
            WHERE i.supplier_id = ? AND i.batch_id = ?
        ), settled AS (
            SELECT a.liability_id, SUM(m.amount_minor) AS amount_minor
            FROM payroll_payment_allocations a
            JOIN batch_liabilities b ON b.liability_id = a.liability_id
            JOIN payroll_payment_matches m ON m.supplier_id = a.supplier_id AND m.allocation_id = a.id
            WHERE a.supplier_id = ? GROUP BY a.liability_id HAVING SUM(m.amount_minor) > 0
        ) SELECT EXISTS(SELECT 1 FROM settled) OR EXISTS(
            SELECT 1 FROM payroll_payment_settlement_signals s
            JOIN batch_liabilities b ON b.liability_id = s.liability_id
            WHERE s.supplier_id = ? AND s.resolved_at IS NULL
        )');
        $query->execute([$supplierId, $batchId, $supplierId, $supplierId]);
        return (bool) $query->fetchColumn();
    }
}
