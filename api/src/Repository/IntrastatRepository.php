<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Support\ExchangeRateDate;
use PDO;

final class IntrastatRepository implements IntrastatDataSource
{
    public function __construct(private readonly Connection $db) {}

    public function declarant(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.dic AS vat_id, UPPER(c.iso2) AS country_iso2
               FROM supplier s
               JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'vat_id' => $row['vat_id'] !== null ? (string) $row['vat_id'] : null,
            'country_iso2' => strtoupper((string) $row['country_iso2']),
        ];
    }

    public function euCountryCodes(): array
    {
        $stmt = $this->db->pdo()->query('SELECT UPPER(iso2) FROM countries WHERE is_eu = 1 ORDER BY iso2');
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    public function movementRows(int $supplierId, string $from, string $toExclusive, string $direction): array
    {
        $docType = $direction === 'arrival' ? 'receipt' : 'issue';
        $sourceFilter = $direction === 'arrival'
            ? "i.id IS NULL
                AND pi.id IS NOT NULL
                AND pi.document_kind IN ('invoice', 'receipt')
                AND (pii.id IS NULL OR (pii.quantity > 0 AND pii.total_without_vat > 0))"
            : "i.id IS NOT NULL
                AND pi.id IS NULL
                AND i.invoice_type = 'invoice'
                AND (ii.id IS NULL OR (ii.quantity > 0 AND ii.total_without_vat > 0))";
        $purchaseRateDate = ExchangeRateDate::purchaseSql('pi');
        $stmt = $this->db->pdo()->prepare(
            "SELECT d.id AS document_id, d.doc_number, d.doc_date, d.doc_type, d.origin,
                    l.id AS source_line_id, l.qty, l.invoice_item_id, l.purchase_invoice_item_id,
                    si.sku, si.name AS stock_item_name, si.unit,
                    si.intrastat_cn8_code, si.intrastat_country_of_origin,
                    si.intrastat_net_mass_kg, si.intrastat_supplementary_unit,
                    si.intrastat_supplementary_unit_coefficient,
                    COALESCE(ii.description, pii.description, l.source_description, si.name) AS item_description,
                    COALESCE(" . \MyInvoice\Service\Stock\StockUnitConverter::sqlToBase('ii.quantity', 'ii_pk') . ", " . \MyInvoice\Service\Stock\StockUnitConverter::sqlToBase('pii.quantity', 'pii_pk') . ") AS invoice_item_quantity,
                    COALESCE(ii.total_without_vat, pii.total_without_vat) AS invoice_item_value,
                    CASE WHEN i.id IS NOT NULL THEN i.total_without_vat ELSE pi.total_without_vat END AS invoice_total_value,
                    CASE WHEN i.id IS NOT NULL THEN (
                        SELECT SUM(ii_all.total_without_vat)
                          FROM invoice_items ii_all
                         WHERE ii_all.invoice_id = i.id
                           AND (ii_all.stock_item_id IS NOT NULL OR EXISTS (
                               SELECT 1
                                 FROM stock_document_lines mapped_l
                                 JOIN stock_documents mapped_d
                                   ON mapped_d.id = mapped_l.document_id
                                  AND mapped_d.supplier_id = mapped_l.supplier_id
                                WHERE mapped_l.supplier_id = d.supplier_id
                                  AND mapped_l.invoice_item_id = ii_all.id
                                  AND mapped_d.invoice_id = i.id
                           ))
                           AND ii_all.quantity > 0
                           AND ii_all.total_without_vat > 0
                    ) ELSE (
                        SELECT SUM(pii_all.total_without_vat)
                          FROM purchase_invoice_items pii_all
                         WHERE pii_all.purchase_invoice_id = pi.id
                           AND (pii_all.stock_item_id IS NOT NULL OR EXISTS (
                               SELECT 1
                                 FROM stock_document_lines mapped_l
                                 JOIN stock_documents mapped_d
                                   ON mapped_d.id = mapped_l.document_id
                                  AND mapped_d.supplier_id = mapped_l.supplier_id
                                WHERE mapped_l.supplier_id = d.supplier_id
                                  AND mapped_l.purchase_invoice_item_id = pii_all.id
                                  AND mapped_d.purchase_invoice_id = pi.id
                           ))
                           AND pii_all.quantity > 0
                           AND pii_all.total_without_vat > 0
                    ) END AS invoice_goods_value,
                    CASE WHEN i.id IS NOT NULL THEN i.total_without_vat - COALESCE((
                        SELECT SUM(ii_all.total_without_vat)
                          FROM invoice_items ii_all
                         WHERE ii_all.invoice_id = i.id
                           AND (ii_all.stock_item_id IS NOT NULL OR EXISTS (
                               SELECT 1
                                 FROM stock_document_lines mapped_l
                                 JOIN stock_documents mapped_d
                                   ON mapped_d.id = mapped_l.document_id
                                  AND mapped_d.supplier_id = mapped_l.supplier_id
                                WHERE mapped_l.supplier_id = d.supplier_id
                                  AND mapped_l.invoice_item_id = ii_all.id
                                  AND mapped_d.invoice_id = i.id
                           ))
                    ), 0) ELSE pi.total_without_vat - COALESCE((
                        SELECT SUM(pii_all.total_without_vat)
                          FROM purchase_invoice_items pii_all
                         WHERE pii_all.purchase_invoice_id = pi.id
                           AND (pii_all.stock_item_id IS NOT NULL OR EXISTS (
                               SELECT 1
                                 FROM stock_document_lines mapped_l
                                 JOIN stock_documents mapped_d
                                   ON mapped_d.id = mapped_l.document_id
                                  AND mapped_d.supplier_id = mapped_l.supplier_id
                                WHERE mapped_l.supplier_id = d.supplier_id
                                  AND mapped_l.purchase_invoice_item_id = pii_all.id
                                  AND mapped_d.purchase_invoice_id = pi.id
                           ))
                    ), 0) END AS invoice_unmapped_value,
                    CASE WHEN i.id IS NOT NULL THEN (
                        SELECT COUNT(*)
                          FROM invoice_items ii_unmapped
                         WHERE ii_unmapped.invoice_id = i.id
                           AND ii_unmapped.total_without_vat <> 0
                           AND NOT (ii_unmapped.stock_item_id IS NOT NULL OR EXISTS (
                               SELECT 1
                                 FROM stock_document_lines mapped_l
                                 JOIN stock_documents mapped_d
                                   ON mapped_d.id = mapped_l.document_id
                                  AND mapped_d.supplier_id = mapped_l.supplier_id
                                WHERE mapped_l.supplier_id = d.supplier_id
                                  AND mapped_l.invoice_item_id = ii_unmapped.id
                                  AND mapped_d.invoice_id = i.id
                           ))
                    ) ELSE (
                        SELECT COUNT(*)
                          FROM purchase_invoice_items pii_unmapped
                         WHERE pii_unmapped.purchase_invoice_id = pi.id
                           AND pii_unmapped.total_without_vat <> 0
                           AND NOT (pii_unmapped.stock_item_id IS NOT NULL OR EXISTS (
                               SELECT 1
                                 FROM stock_document_lines mapped_l
                                 JOIN stock_documents mapped_d
                                   ON mapped_d.id = mapped_l.document_id
                                  AND mapped_d.supplier_id = mapped_l.supplier_id
                                WHERE mapped_l.supplier_id = d.supplier_id
                                  AND mapped_l.purchase_invoice_item_id = pii_unmapped.id
                                  AND mapped_d.purchase_invoice_id = pi.id
                           ))
                    ) END AS invoice_unmapped_line_count,
                    CASE WHEN i.id IS NOT NULL THEN i.effective_tax_date ELSE {$purchaseRateDate} END AS invoice_rate_date,
                    CASE WHEN i.id IS NOT NULL THEN i.exchange_rate ELSE pi.exchange_rate END AS exchange_rate,
                    COALESCE(ic.code, pic.code) AS currency_code,
                    CASE WHEN i.id IS NOT NULL THEN i.client_snapshot ELSE pi.vendor_snapshot END AS partner_snapshot,
                    COALESCE(invoice_partner.company_name, purchase_partner.company_name, d.partner_name) AS partner_name,
                    COALESCE(invoice_partner.dic, purchase_partner.dic) AS partner_vat_id,
                    COALESCE(invoice_country.iso2, purchase_country.iso2) AS partner_country_iso2
               FROM stock_documents d
               JOIN stock_document_lines l
                 ON l.document_id = d.id AND l.supplier_id = d.supplier_id
               JOIN stock_items si
                 ON si.id = l.stock_item_id AND si.supplier_id = l.supplier_id
          LEFT JOIN invoices i
                 ON i.id = d.invoice_id AND i.supplier_id = d.supplier_id
          LEFT JOIN invoice_items ii
                 ON ii.id = l.invoice_item_id AND ii.invoice_id = i.id
          LEFT JOIN currencies ic ON ic.id = i.currency_id
          LEFT JOIN clients invoice_partner
                 ON invoice_partner.id = i.client_id AND invoice_partner.supplier_id = d.supplier_id
          LEFT JOIN countries invoice_country ON invoice_country.id = invoice_partner.country_id
          LEFT JOIN purchase_invoices pi
                 ON pi.id = d.purchase_invoice_id AND pi.supplier_id = d.supplier_id
          LEFT JOIN purchase_invoice_items pii
                 ON pii.id = l.purchase_invoice_item_id AND pii.purchase_invoice_id = pi.id
          LEFT JOIN currencies pic ON pic.id = pi.currency_id
          " . \MyInvoice\Service\Stock\StockUnitConverter::sqlUnitJoin('ii_pk', 'd.supplier_id', 'ii.stock_item_id', 'ii.unit') . "
          " . \MyInvoice\Service\Stock\StockUnitConverter::sqlUnitJoin('pii_pk', 'd.supplier_id', 'pii.stock_item_id', 'pii.unit') . "
          LEFT JOIN clients purchase_partner
                 ON purchase_partner.id = pi.vendor_id AND purchase_partner.supplier_id = d.supplier_id
          LEFT JOIN countries purchase_country ON purchase_country.id = purchase_partner.country_id
              WHERE d.supplier_id = ?
                AND d.doc_type = ?
                AND d.status = 'posted'
                AND d.doc_date >= ? AND d.doc_date < ?
                AND d.history_import_job_id IS NULL
                AND (d.invoice_id IS NOT NULL OR d.purchase_invoice_id IS NOT NULL)
                AND ({$sourceFilter})
                AND NOT EXISTS (
                    SELECT 1
                      FROM stock_documents original
                     WHERE original.supplier_id = d.supplier_id
                       AND original.reversal_document_id = d.id
                )
              ORDER BY d.doc_date, d.id, l.line_no, l.id"
        );
        $stmt->execute([$supplierId, $docType, $from, $toExclusive]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
