<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * StockClosingValuation — podklady uzávěrkového kroku „Zásoby" (SKLAD §3.4, způsob B).
 *
 * Počítá per stock_items.item_type (material/goods/product):
 *
 *  - `closing` / `closing_qty`: konečný stav k rozvahovému dni ze skladového
 *    LEDGERU (stock_document_lines posted dokladů s doc_date <= ends_on):
 *    příjemky +, výdejky −, převodky netto 0 (jen přesun mezi sklady téže firmy).
 *    Záměrně NE aktuální Σ stock_levels.value_total — uzávěrka typicky běží, až
 *    když nové období už žije a stock_levels obsahují i pohyby po rozvahovém dni;
 *    ledger k datu = „evidence k rozvahovému dni" (§3.4 krok 2). Bez pohybů po
 *    ends_on se obě čísla rovnají (invariant StockLevelService).
 *
 *  - `shortage` / `surplus`: inventurní rozdíly za období — doklady
 *    origin='inventory', doc_type='issue' (manko) / 'receipt' (přebytek),
 *    status='posted', doc_date uvnitř období.
 *
 * Peníze DECIMAL(15,2) ↔ float zaokr. na 2 des. místa; porovnání dělá volající
 * v haléřích (vzor PostingService::cents). Čistě čtecí třída (žádné mutace).
 */
final class StockClosingValuation
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *     closing: array{material: float, goods: float, product: float},
     *     closing_qty: array{material: float, goods: float, product: float},
     *     shortage: array{material: float, goods: float, product: float},
     *     surplus: array{material: float, goods: float, product: float}
     * }
     */
    public function totals(int $supplierId, string $startsOn, string $endsOn): array
    {
        $zero = ['material' => 0.0, 'goods' => 0.0, 'product' => 0.0];
        $out = ['closing' => $zero, 'closing_qty' => $zero, 'shortage' => $zero, 'surplus' => $zero];

        // Konečný stav k rozvahovému dni (ledger ≤ ends_on, transfer netto 0).
        // status IN ('posted','reversed') = kanonická sémantika skladové knihy
        // (stornovaný doklad neutralizuje jeho posted protidoklad → pár netto 0);
        // musí sedět se stock_levels/replay, jinak je závěrka po stornu chybná.
        $stmt = $this->db->pdo()->prepare(
            "SELECT si.item_type,
                    COALESCE(SUM(CASE d.doc_type WHEN 'receipt' THEN l.value_total
                                                 WHEN 'issue'   THEN -l.value_total
                                                 ELSE 0 END), 0) AS val,
                    COALESCE(SUM(CASE d.doc_type WHEN 'receipt' THEN l.qty
                                                 WHEN 'issue'   THEN -l.qty
                                                 ELSE 0 END), 0) AS qty
               FROM stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
               JOIN stock_items si    ON si.id = l.stock_item_id AND si.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND d.status IN ('posted', 'reversed') AND d.doc_date <= ?
              GROUP BY si.item_type"
        );
        $stmt->execute([$supplierId, $endsOn]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type = (string) $row['item_type'];
            if (!isset($zero[$type])) {
                continue;
            }
            $out['closing'][$type] = round((float) $row['val'], 2);
            $out['closing_qty'][$type] = round((float) $row['qty'], 3);
        }

        // Inventurní rozdíly za období: issue = manko, receipt = přebytek.
        $stmt = $this->db->pdo()->prepare(
            "SELECT si.item_type, d.doc_type, COALESCE(SUM(l.value_total), 0) AS val
               FROM stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
               JOIN stock_items si    ON si.id = l.stock_item_id AND si.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND d.origin = 'inventory' AND d.status = 'posted'
                AND NOT EXISTS (
                    SELECT 1 FROM stock_documents original
                     WHERE original.supplier_id = d.supplier_id
                       AND original.reversal_document_id = d.id
                )
                AND d.doc_type IN ('receipt','issue')
                AND d.doc_date BETWEEN ? AND ?
              GROUP BY si.item_type, d.doc_type"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type = (string) $row['item_type'];
            if (!isset($zero[$type])) {
                continue;
            }
            $bucket = ((string) $row['doc_type']) === 'issue' ? 'shortage' : 'surplus';
            $out[$bucket][$type] = round((float) $row['val'], 2);
        }

        return $out;
    }

    /**
     * Podklady k ručnímu doúčtování zásob způsobem B: příjemky bez PF
     * a PF se skladovými položkami bez příjemky k rozvahovému dni.
     * Převzatá historie skladu se nehlásí: její doúčtování proběhlo v původním systému.
     *
     * @return list<array<string,mixed>>
     */
    public function unmatchedDocuments(int $supplierId, string $endsOn): array
    {
        $warnings = [];

        $stmt = $this->db->pdo()->prepare(
            "SELECT d.id, d.doc_number, d.doc_date, d.partner_name,
                    ROUND(COALESCE(SUM(l.value_total), 0), 2) AS amount
               FROM stock_documents d
               JOIN stock_document_lines l ON l.document_id = d.id AND l.supplier_id = d.supplier_id
              WHERE d.supplier_id = ? AND d.doc_type = 'receipt' AND d.status = 'posted'
                AND d.doc_date <= ? AND d.purchase_invoice_id IS NULL
                AND d.origin NOT IN ('inventory','credit_note')
                AND d.history_import_job_id IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM stock_documents original
                     WHERE original.supplier_id = d.supplier_id
                       AND original.reversal_document_id = d.id
                )
              GROUP BY d.id, d.doc_number, d.doc_date, d.partner_name
              ORDER BY d.doc_date, d.id"
        );
        $stmt->execute([$supplierId, $endsOn]);
        $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($receipts !== []) {
            $warnings[] = [
                'key' => 'stock_unbilled_receipts',
                'message' => 'Příjemky bez vazby na přijatou fakturu vyžadují ověřit dohadnou položku pasivní (389).',
                'items' => $receipts,
            ];
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.varsymbol, pi.issue_date, c.company_name AS partner_name,
                    ROUND(pi.total_with_vat, 2) AS amount
               FROM purchase_invoices pi
               JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
              WHERE pi.supplier_id = ? AND pi.issue_date <= ?
                AND pi.status NOT IN ('draft','cancelled')
                AND EXISTS (
                    SELECT 1 FROM purchase_invoice_items pii
                     JOIN stock_items si ON si.id = pii.stock_item_id AND si.supplier_id = pi.supplier_id
                     WHERE pii.purchase_invoice_id = pi.id AND si.item_type <> 'service'
                )
                AND NOT EXISTS (
                    SELECT 1 FROM stock_documents d
                     WHERE d.supplier_id = pi.supplier_id AND d.purchase_invoice_id = pi.id
                       AND d.doc_type = 'receipt' AND d.status IN ('posted','reversed')
                       AND d.doc_date <= ?
                )
              ORDER BY pi.issue_date, pi.id"
        );
        $stmt->execute([$supplierId, $endsOn, $endsOn]);
        $inTransit = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($inTransit !== []) {
            $warnings[] = [
                'key' => 'stock_in_transit',
                'message' => 'Přijaté faktury se skladovými položkami bez příjemky vyžadují ověřit zásoby na cestě (119/139).',
                'items' => $inTransit,
            ];
        }

        return $warnings;
    }
}
