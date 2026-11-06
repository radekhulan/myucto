<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;

final class DocumentPaymentLinks
{
    public function __construct(private readonly Connection $db) {}

    /** @param list<int> $documentIds */
    public function existsForDocuments(int $supplierId, string $sourceType, array $documentIds, bool $currentRead = false): bool
    {
        if (!in_array($sourceType, ['invoice', 'purchase_invoice'], true)) {
            throw new \InvalidArgumentException('Neplatný typ dokladu.');
        }
        $documentIds = array_values(array_unique(array_filter(array_map('intval', $documentIds), static fn (int $id): bool => $id > 0)));
        sort($documentIds, SORT_NUMERIC);
        foreach (array_chunk($documentIds, 500) as $ids) {
            [$sql, $params] = $this->query($supplierId, $sourceType, $ids, $currentRead);
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute($params);
            if ((bool) $stmt->fetchColumn()) {
                return true;
            }
        }
        return false;
    }

    private function query(int $supplierId, string $sourceType, array $ids, bool $currentRead = false): array
    {
        $place = implode(',', array_fill(0, count($ids), '?'));
        $checks = [];
        $params = [];
        $suffix = $currentRead ? ' FOR UPDATE' : '';
        $add = static function (string $sql, array $values) use (&$checks, &$params, $suffix): void {
            $checks[] = "EXISTS ($sql$suffix)";
            array_push($params, ...$values);
        };
        $column = $sourceType === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
        foreach (['payment_matches' => $sourceType === 'invoice' ? 'idx_pm_supplier_invoice' : 'idx_pm_supplier_purchase',
                  'cash_documents' => $sourceType === 'invoice' ? 'idx_cashdoc_invoice' : 'idx_cashdoc_purchase'] as $table => $index) {
            $add("SELECT 1 FROM $table FORCE INDEX ($index) WHERE supplier_id = ? AND $column IN ($place)", [$supplierId, ...$ids]);
        }
        $orderIndex = $sourceType === 'invoice' ? 'idx_poi_issued_invoice' : 'idx_poi_invoice';
        $add("SELECT 1 FROM payment_order_items poi FORCE INDEX ($orderIndex)
               JOIN payment_orders po FORCE INDEX (PRIMARY) ON po.id = poi.payment_order_id
              WHERE po.supplier_id = ? AND poi.$column IN ($place)", [$supplierId, ...$ids]);
        foreach (['invoice_settlements' => 'idx_is_doc', 'offset_agreement_items' => 'idx_oai_doc', 'journal_settlement_allocations' => 'ix_jsa_doc'] as $table => $index) {
            $add("SELECT 1 FROM $table FORCE INDEX ($index) WHERE supplier_id = ? AND doc_type = ? AND doc_id IN ($place)", [$supplierId, $sourceType, ...$ids]);
        }
        foreach (['invoice_id' => 'idx_cno_invoice', 'credit_note_id' => 'uq_cno_credit_note'] as $offsetColumn => $index) {
            $add("SELECT 1 FROM credit_note_offsets FORCE INDEX ($index) WHERE supplier_id = ? AND doc_type = ? AND $offsetColumn IN ($place)", [$supplierId, $sourceType, ...$ids]);
        }
        $deductions = $sourceType === 'invoice' ? 'invoice_advance_deductions' : 'purchase_advance_deductions';
        foreach (['final_invoice_id' => $sourceType === 'invoice' ? 'ix_iad_final' : 'uq_pad_pair',
                  'advance_invoice_id' => $sourceType === 'invoice' ? 'ix_iad_advance' : 'ix_pad_advance'] as $deductionColumn => $index) {
            $add("SELECT 1 FROM $deductions FORCE INDEX ($index) WHERE supplier_id = ? AND $deductionColumn IN ($place)", [$supplierId, ...$ids]);
        }
        if ($sourceType === 'invoice') {
            foreach (['invoice_id' => 'idx_invpay_invoice', 'tax_document_invoice_id' => 'idx_invpay_taxdoc'] as $paymentColumn => $index) {
                $add("SELECT 1 FROM invoice_payments FORCE INDEX ($index) WHERE supplier_id = ? AND $paymentColumn IN ($place)", [$supplierId, ...$ids]);
            }
            $add("SELECT 1 FROM bank_transactions bt FORCE INDEX (idx_bt_match) JOIN invoices i FORCE INDEX (PRIMARY) ON i.id = bt.matched_invoice_id
                   WHERE i.supplier_id = ? AND bt.matched_invoice_id IN ($place)", [$supplierId, ...$ids]);
        }
        return ['SELECT ' . implode(' OR ', $checks), $params];
    }
}
