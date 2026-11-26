<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;

final class NonprofitActivityDefault
{
    public function __construct(private readonly Connection $db) {}

    /** @param list<int> $periodIds */
    public function assign(
        int $supplierId,
        array $periodIds,
        string $date,
        ?int $actor,
        string $originRef,
        ImportProtocol $protocol,
        string $step,
        string $sourceMessage,
        int $alreadyAssigned = 0,
    ): void {
        if ($periodIds === []) {
            return;
        }
        $activity = (new SystemDimensionCatalog($this->db))->ensureForFramework($supplierId, $date, $actor)['activity'] ?? null;
        if ($activity === null) {
            return;
        }
        $periodFilter = implode(',', array_fill(0, count($periodIds), '?'));
        $moneyEntries = $originRef === 'money_s3_import' ? "
               AND (EXISTS (SELECT 1 FROM money_s3_import_map m
                            WHERE m.supplier_id = e.supplier_id AND m.kind = 'journal_entry' AND m.target_id = e.id)
                 OR (e.source_type IN ('invoice', 'purchase_invoice', 'cash', 'bank')
                    AND EXISTS (SELECT 1 FROM money_s3_import_map m
                                 WHERE m.supplier_id = e.supplier_id AND m.target_id = e.source_id
                                   AND m.kind = CASE e.source_type WHEN 'cash' THEN 'cash_document'
                                                 WHEN 'bank' THEN 'bank_transaction' ELSE e.source_type END)))" : '';
        $stmt = $this->db->pdo()->prepare("INSERT IGNORE INTO journal_entry_line_dimensions
                (line_id, dimension_type_id, supplier_id, dimension_value_id, assignment_origin, assignment_origin_ref)
            SELECT l.id, ?, l.supplier_id, ?, 'explicit', ?
              FROM journal_entries e
              JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
              JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
             WHERE e.supplier_id = ? AND e.period_id IN ({$periodFilter}) AND e.posted_at IS NOT NULL
               AND a.account_code REGEXP '^[56]'{$moneyEntries}
               AND NOT EXISTS (SELECT 1 FROM journal_entry_line_dimension_splits sp WHERE sp.line_id = l.id AND sp.dimension_type_id = ?)");
        $stmt->execute([$activity['id'], $activity['values']['main'], $originRef, $supplierId, ...$periodIds, $activity['id']]);
        $lines = $stmt->rowCount() + $alreadyAssigned;
        if ($lines > 0) {
            $protocol->warn($step, 'nonprofit_activity_default', sprintf(
                '%d řádkům nákladů a výnosů převod nastavil hlavní činnost, %s. Účetní přeřadí hospodářskou činnost podle skutečnosti, ovlivňuje to výkazy i daň z příjmů.',
                $lines, $sourceMessage,
            ), ['lines' => $lines]);
        }
    }
}
