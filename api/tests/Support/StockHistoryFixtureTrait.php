<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

trait StockHistoryFixtureTrait
{
    protected function syntheticEmptyCards(int $supplierId, int $warehouseId, int $cards): void
    {
        if ($cards < 1 || $cards > 30000) {
            throw new \InvalidArgumentException('Invalid synthetic card count.');
        }
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO stock_items (supplier_id, sku, name)
            SELECT ?, CONCAT('SYNTHETIC-BATCH-', seq), CONCAT('Synthetic batch card ', seq) FROM seq_1_to_" . $cards)
            ->execute([$supplierId]);
        $pdo->prepare("INSERT INTO stock_levels (supplier_id, warehouse_id, stock_item_id)
            SELECT supplier_id, ?, id FROM stock_items WHERE supplier_id = ? AND sku LIKE 'SYNTHETIC-BATCH-%'")
            ->execute([$warehouseId, $supplierId]);
    }

    protected function syntheticHistory(int $supplierId, int $warehouseId, int $itemId, int $movements): void
    {
        if ($movements < 1 || $movements > 1000000) {
            throw new \InvalidArgumentException('Invalid synthetic history size.');
        }
        $receipt = $this->receiveStock($supplierId, $warehouseId, $itemId, (string) $movements, 1, '2099-01-01');
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE stock_document_lines SET qty = 1, value_total = 1 WHERE supplier_id = ? AND document_id = ?')
            ->execute([$supplierId, $receipt['id']]);
        if ($movements > 1) {
            $pdo->prepare('INSERT INTO stock_document_lines
                (document_id, supplier_id, stock_item_id, doc_date, qty, unit_cost, value_total, extra_cost, line_no, ledger_booked_at)
                SELECT ?, ?, ?, ?, 1, 1, 1, 0, seq, ? FROM seq_1_to_' . ($movements - 1))
                ->execute([$receipt['id'], $supplierId, $itemId, '2099-01-01', $receipt['booked_at']]);
        }
    }
}
