<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockDocumentRepository;

final class StockIssueDraftFulfillmentSource implements FulfillmentSourceProvider
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockDocumentRepository $documents,
    ) {}

    public function type(): string
    {
        return 'stock_issue_draft';
    }

    public function loadForClaim(int $supplierId, string $sourceId): array
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Zdroj vychystání se musí převzít v transakci.');
        }
        if (!ctype_digit($sourceId) || (int) $sourceId <= 0) {
            throw new StockException('fulfillment_source_invalid', 'ID výdejního konceptu není platné.', 422);
        }
        $documentId = (int) $sourceId;
        $document = $this->documents->lockForPost($supplierId, $documentId);
        if ($document === null) {
            throw new StockException('fulfillment_source_not_found', 'Výdejní koncept nebyl nalezen.', 404);
        }
        if ((string) $document['doc_type'] !== 'issue' || (string) $document['status'] !== 'draft') {
            throw new StockException('fulfillment_source_invalid', 'Vychystání lze vytvořit jen z rozpracované výdejky.', 409);
        }
        $lines = $this->documents->lines($supplierId, $documentId);
        if ($lines === []) {
            throw new StockException('fulfillment_source_invalid', 'Výdejní koncept nemá žádné řádky.', 422);
        }

        $out = [];
        foreach ($lines as $line) {
            $qty = (string) $line['qty'];
            if (StockValuation::qtyToT($qty) <= 0) {
                throw new StockException('fulfillment_source_invalid', 'Množství zdrojového řádku musí být větší než nula.', 422);
            }
            $out[] = [
                'source_line_id' => (string) $line['id'],
                'stock_item_id' => (int) $line['stock_item_id'],
                'warehouse_id' => (int) $document['warehouse_id'],
                'expected_qty' => $qty,
                'component_snapshot' => [
                    'source_type' => $this->type(),
                    'source_id' => $sourceId,
                    'source_line_id' => (string) $line['id'],
                    'invoice_item_id' => $line['invoice_item_id'] ?? null,
                    'stock_item_id' => (int) $line['stock_item_id'],
                    'warehouse_id' => (int) $document['warehouse_id'],
                    'sku' => (string) $line['sku'],
                    'ean' => self::itemEan($this->db, $supplierId, (int) $line['stock_item_id']),
                    'name' => (string) $line['name'],
                    'unit' => (string) $line['unit'],
                    'quantity' => $qty,
                    'tracking_allocations' => array_values((array) ($line['tracking_allocations'] ?? [])),
                ],
            ];
        }

        return [
            'source_snapshot' => [
                'source_type' => $this->type(),
                'source_id' => $sourceId,
                'document_number' => $document['doc_number'],
                'invoice_id' => $document['invoice_id'] ?? null,
                'document_date' => (string) $document['doc_date'],
                'description' => (string) $document['description'],
            ],
            'lines' => $out,
            'claimed_stock_document_id' => $documentId,
        ];
    }

    private static function itemEan(Connection $db, int $supplierId, int $itemId): ?string
    {
        $stmt = $db->pdo()->prepare('SELECT ean FROM stock_items WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $itemId]);
        $ean = $stmt->fetchColumn();
        return $ean === false || $ean === null || trim((string) $ean) === '' ? null : (string) $ean;
    }
}
