<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Zápisy na skladové karty, které si příjemka z přijaté faktury vyžádala volbami na řádcích.
 * Volá je {@see StockDocumentService::post()} až po zaúčtování: koncept kartu nemění.
 */
final class ReceiptCardUpdater
{
    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseLineStockMatcher $matcher,
        private readonly ActivityLogger $logger,
        private readonly StockItemRepository $items,
    ) {}

    /**
     * Uloží kód z řádku faktury ke kartě u řádků s volbou `remember_seller_code`. Kód se bere
     * ze zdrojového řádku faktury, ne od volajícího. Audit jde k cílové kartě i ke kartám,
     * kterým se kód odebral.
     *
     * @return list<string> varování pro uživatele
     */
    public function rememberSellerCodes(int $supplierId, int $documentId, bool $canWriteCards, ?int $userId, ?string $ip = null, ?string $userAgent = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.stock_item_id, l.purchase_invoice_item_id, pii.seller_item_code, pi.vendor_id, c.code AS currency_code
               FROM stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
               JOIN purchase_invoice_items pii ON pii.id = l.purchase_invoice_item_id
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id AND pi.supplier_id = l.supplier_id
                AND pi.id = d.purchase_invoice_id
               JOIN currencies c ON c.id = pi.currency_id AND c.supplier_id = pi.supplier_id
              WHERE l.supplier_id = ? AND l.document_id = ? AND l.remember_seller_code = 1
                AND d.doc_type = 'receipt' AND d.status = 'posted' AND pii.seller_item_code IS NOT NULL
              ORDER BY l.line_no"
        );
        $stmt->execute([$supplierId, $documentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }
        if (!$canWriteCards) {
            return ['Kód dodavatele se ke kartě neuložil: nemáte právo upravovat skladové karty.'];
        }

        $cardsByCode = [];
        foreach ($rows as $row) {
            $cardsByCode[mb_strtolower((string) $row['seller_item_code'])][(int) $row['stock_item_id']] = true;
        }

        $warnings = [];
        $done = [];
        foreach ($rows as $row) {
            $code = (string) $row['seller_item_code'];
            $key = mb_strtolower($code);
            if (isset($done[$key])) {
                continue;
            }
            $done[$key] = true;
            // Koncept mohl dostat stejný kód u dvou karet až úpravou po založení.
            if (count($cardsByCode[$key]) > 1) {
                $warnings[] = sprintf('Kód %s se ke kartě neuložil: na příjemce je u více různých karet.', $code);
                continue;
            }
            $this->rememberOne($supplierId, $documentId, $row, $code, $userId, $ip, $userAgent);
        }
        return $warnings;
    }

    /**
     * Doplní na karty Intrastat ze zdrojových řádků přijaté faktury, jen u řádků s volbou
     * `fill_intrastat` a jen do prázdných polí.
     *
     * @return list<string> varování pro uživatele
     */
    public function fillIntrastat(int $supplierId, int $documentId, bool $canWriteCards, ?int $userId, ?string $ip = null, ?string $userAgent = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.stock_item_id, l.purchase_invoice_item_id, pii.intrastat_cn8_code, pii.intrastat_country_of_origin
               FROM stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
               JOIN purchase_invoice_items pii ON pii.id = l.purchase_invoice_item_id
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id AND pi.supplier_id = l.supplier_id
                AND pi.id = d.purchase_invoice_id
              WHERE l.supplier_id = ? AND l.document_id = ? AND l.fill_intrastat = 1
                AND d.doc_type = 'receipt' AND d.status = 'posted'
                AND (pii.intrastat_cn8_code IS NOT NULL OR pii.intrastat_country_of_origin IS NOT NULL)
              ORDER BY l.line_no"
        );
        $stmt->execute([$supplierId, $documentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }
        if (!$canWriteCards) {
            return ['Údaje pro Intrastat se na kartu nedoplnily: nemáte právo upravovat skladové karty.'];
        }
        foreach ($rows as $row) {
            $changes = $this->items->fillMissingIntrastat(
                $supplierId,
                (int) $row['stock_item_id'],
                $row['intrastat_cn8_code'],
                $row['intrastat_country_of_origin'],
            );
            if ($changes !== []) {
                $this->logger->log('stock.item_intrastat_filled', $userId, 'stock_item', (int) $row['stock_item_id'], [
                    'changes'                  => $changes,
                    'purchase_invoice_item_id' => (int) $row['purchase_invoice_item_id'],
                    'stock_document_id'        => $documentId,
                ], $ip, $userAgent, $supplierId);
            }
        }
        return [];
    }

    /** @param array<string,mixed> $row */
    private function rememberOne(int $supplierId, int $documentId, array $row, string $code, ?int $userId, ?string $ip, ?string $userAgent): void
    {
        $stockItemId = (int) $row['stock_item_id'];
        $vendorId = (int) $row['vendor_id'];
        $before = $this->matcher->remember($supplierId, $vendorId, $stockItemId, $code, (string) $row['currency_code']);
        $context = [
            'vendor_id'                => $vendorId,
            'purchase_invoice_item_id' => (int) $row['purchase_invoice_item_id'],
            'stock_document_id'        => $documentId,
        ];
        $this->logger->log('stock.item_vendor_code_remembered', $userId, 'stock_item', $stockItemId, $before + $context + [
            'new_vendor_sku' => $code,
        ], $ip, $userAgent, $supplierId);
        foreach ($before['moved_from_stock_item_ids'] as $movedFrom) {
            $this->logger->log('stock.item_vendor_code_removed', $userId, 'stock_item', $movedFrom, $context + [
                'old_vendor_sku'      => $code,
                'moved_to_stock_item_id' => $stockItemId,
            ], $ip, $userAgent, $supplierId);
        }
    }
}
