<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use PDO;

final class PurchaseItemReferences
{
    /** @param list<int> $unmatchedIds */
    public static function guardReceipts(PDO $pdo, int $supplierId, array $unmatchedIds): void
    {
        if ($unmatchedIds === []) {
            return;
        }
        $check = $pdo->prepare(
            "SELECT d.status
               FROM JSON_TABLE(?, '$[*]' COLUMNS(id BIGINT UNSIGNED PATH '$')) old_items
               JOIN stock_document_lines l ON l.purchase_invoice_item_id = old_items.id
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND d.doc_type = 'receipt' AND d.status IN ('posted', 'draft')
              ORDER BY d.status = 'posted' DESC
              LIMIT 1 FOR UPDATE"
        );
        $check->execute([json_encode($unmatchedIds, JSON_THROW_ON_ERROR), $supplierId]);
        $status = $check->fetchColumn();
        if ($status === 'posted') {
            throw new PurchaseItemReferenceException(
                PurchaseItemReferenceException::RECEIVED,
                'Řádek je přijatý na sklad. Nejdřív stornujte příjemku.',
            );
        }
        if ($status === 'draft') {
            throw new PurchaseItemReferenceException(
                PurchaseItemReferenceException::DRAFT_RECEIPT,
                'Řádek je na rozpracované příjemce. Nejdřív ho z příjemky odeberte.',
            );
        }
    }

    /** @param array<int,int> $ids staré ID => nové ID */
    public static function remap(PDO $pdo, int $supplierId, int $purchaseInvoiceId, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $pairs = [];
        foreach ($ids as $oldId => $newId) {
            $pairs[] = [$oldId, $newId];
        }
        $payload = json_encode($pairs, JSON_THROW_ON_ERROR);
        foreach ([
            ['stock_document_lines', 'purchase_invoice_item_id', ''],
            ['stock_landed_costs', 'purchase_invoice_item_id', ''],
            ['small_assets', 'purchase_invoice_item_id', ''],
            ['assets', 'purchase_invoice_item_id', ''],
            ['accrual_items', 'source_item_id', " AND r.source_type = 'purchase_invoice' AND r.source_id = ?"],
            ['fuelings', 'source_item_id', ' AND r.source_purchase_invoice_id = ?'],
        ] as [$table, $column, $sourceFilter]) {
            $stmt = $pdo->prepare(
                "UPDATE {$table} r
                   JOIN JSON_TABLE(?, '$[*]' COLUMNS(
                       old_id BIGINT UNSIGNED PATH '$[0]', new_id BIGINT UNSIGNED PATH '$[1]'
                   )) item_map ON r.{$column} = item_map.old_id
                    SET r.{$column} = item_map.new_id
                  WHERE r.supplier_id = ?{$sourceFilter}"
            );
            $params = [$payload, $supplierId];
            if ($sourceFilter !== '') {
                $params[] = $purchaseInvoiceId;
            }
            $stmt->execute($params);
        }
    }
}
