<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_documents + stock_document_lines (Epic SKLAD).
 * Surové PDO, tenant predikát supplier_id na KAŽDÉM dotazu (architektonický vzor).
 *
 * Lifecycle draft → posted → reversed (vzor cash_documents); doc_number se
 * přiděluje až při post() (B3 — číslo řady se nesmí propálit při 409).
 *
 * DŮLEŽITÁ SÉMANTIKA STAVŮ pro ledger/replay: doklad se stavem 'reversed'
 * NENÍ neplatný pohyb — jeho pohyby reálně proběhly a neutralizuje je
 * protidoklad (status 'posted'). Ledger/replay proto čte stavy
 * ('posted','reversed'); jen 'draft' je mimo skladovou knihu.
 */
final class StockDocumentRepository
{
    private const COLUMNS =
        'id, supplier_id, doc_type, origin, warehouse_id, warehouse_to_id, doc_number, doc_date,
         description, partner_name, invoice_id, purchase_invoice_id, purchase_order_id, stock_take_id,
         journal_entry_id, reversal_document_id, status, booked_at, booked_by,
         created_by, created_at, updated_at, allow_over_delivery';

    /** Stavy, jejichž pohyby jsou součástí skladové knihy (viz class docblock). */
    private const LEDGER_STATUSES = "('posted','reversed')";

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_documents WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function findWithLines(int $supplierId, int $id): ?array
    {
        $doc = $this->find($supplierId, $id);
        if ($doc === null) {
            return null;
        }
        $doc['lines'] = $this->lines($supplierId, $id);
        return $doc;
    }

    /**
     * Řádky dokladu obohacené o kartu (sku/name/unit) — pořadí line_no, id.
     *
     * @return list<array<string,mixed>>
     */
    public function lines(int $supplierId, int $documentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.document_id, l.supplier_id, l.stock_item_id, l.doc_date, l.qty,
                    l.unit_cost, l.value_total, l.extra_cost, l.invoice_item_id,
                    l.purchase_invoice_item_id, l.purchase_order_line_id,
                    l.source_description, l.source_qty,
                    l.line_no, l.note,
                    si.sku, si.name, si.unit
               FROM stock_document_lines l
               JOIN stock_items si ON si.id = l.stock_item_id AND si.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.document_id = ?
              ORDER BY l.line_no ASC, l.id ASC'
        );
        $stmt->execute([$supplierId, $documentId]);
        return array_map([self::class, 'castLine'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Hlavička pod zámkem pro post/reverse — volat VÝHRADNĚ v otevřené transakci. */
    public function lockForPost(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_documents
              WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * Seznam dokladů s filtry (doc_type, status, origin, warehouse_id, q, limit, offset).
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId, array $filters): array
    {
        $where  = ['d.supplier_id = ?'];
        $params = [$supplierId];
        if (!empty($filters['doc_type'])) {
            $where[]  = 'd.doc_type = ?';
            $params[] = (string) $filters['doc_type'];
        }
        if (!empty($filters['status'])) {
            $where[]  = 'd.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['origin'])) {
            $where[]  = 'd.origin = ?';
            $params[] = (string) $filters['origin'];
        }
        if (!empty($filters['warehouse_id'])) {
            $where[]  = '(d.warehouse_id = ? OR d.warehouse_to_id = ?)';
            $params[] = (int) $filters['warehouse_id'];
            $params[] = (int) $filters['warehouse_id'];
        }
        if (!empty($filters['from'])) {
            $where[]  = 'd.doc_date >= ?';
            $params[] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[]  = 'd.doc_date <= ?';
            $params[] = (string) $filters['to'];
        }
        if (!empty($filters['q'])) {
            $q        = addcslashes((string) $filters['q'], '%_\\');
            $where[]  = '(d.doc_number LIKE ? OR d.description LIKE ? OR d.partner_name LIKE ?)';
            $params[] = $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $limit  = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $cols = implode(', ', array_map(
            static fn (string $c): string => 'd.' . trim($c),
            explode(',', self::COLUMNS)
        ));
        $sql = 'SELECT ' . $cols . ', COUNT(*) OVER() AS total_rows,
                       w.code AS warehouse_code, w.name AS warehouse_name,
                       wt.code AS warehouse_to_code, wt.name AS warehouse_to_name
                  FROM stock_documents d
                  JOIN warehouses w ON w.id = d.warehouse_id AND w.supplier_id = d.supplier_id
             LEFT JOIN warehouses wt ON wt.id = d.warehouse_to_id AND wt.supplier_id = d.supplier_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY d.doc_date DESC, d.id DESC
                 LIMIT ? OFFSET ?';

        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        $stmt->bindValue($idx++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $total = (int) $row['total_rows'];
            $whCode = $row['warehouse_code'] ?? null;
            $whName = $row['warehouse_name'] ?? null;
            $whToCode = $row['warehouse_to_code'] ?? null;
            $whToName = $row['warehouse_to_name'] ?? null;
            unset($row['total_rows'], $row['warehouse_code'], $row['warehouse_name'],
                  $row['warehouse_to_code'], $row['warehouse_to_name']);
            $doc = self::cast($row);
            $doc['warehouse_code']    = $whCode !== null ? (string) $whCode : null;
            $doc['warehouse_name']    = $whName !== null ? (string) $whName : null;
            $doc['warehouse_to_code'] = $whToCode !== null ? (string) $whToCode : null;
            $doc['warehouse_to_name'] = $whToName !== null ? (string) $whToName : null;
            $doc['total_rows']        = $total;
            $out[] = $doc;
        }
        return $out;
    }

    /**
     * Vloží hlavičku (draft; doc_number NULL — přidělí až post()). Vrací id.
     *
     * @param array<string,mixed> $data
     */
    public function insertHeader(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_documents
                (supplier_id, doc_type, origin, warehouse_id, warehouse_to_id, doc_number,
                 doc_date, description, partner_name, invoice_id, purchase_invoice_id,
                 purchase_order_id, stock_take_id, status, created_by, allow_over_delivery)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (string) $data['doc_type'],
            (string) ($data['origin'] ?? 'manual'),
            (int) $data['warehouse_id'],
            isset($data['warehouse_to_id']) && (int) $data['warehouse_to_id'] > 0 ? (int) $data['warehouse_to_id'] : null,
            (string) $data['doc_date'],
            (string) $data['description'],
            $data['partner_name'] ?? null,
            isset($data['invoice_id']) && (int) $data['invoice_id'] > 0 ? (int) $data['invoice_id'] : null,
            isset($data['purchase_invoice_id']) && (int) $data['purchase_invoice_id'] > 0 ? (int) $data['purchase_invoice_id'] : null,
            isset($data['purchase_order_id']) && (int) $data['purchase_order_id'] > 0 ? (int) $data['purchase_order_id'] : null,
            isset($data['stock_take_id']) && (int) $data['stock_take_id'] > 0 ? (int) $data['stock_take_id'] : null,
            (string) ($data['status'] ?? 'draft'),
            isset($data['created_by']) && (int) $data['created_by'] > 0 ? (int) $data['created_by'] : null,
            !empty($data['allow_over_delivery']) ? 1 : 0,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function insertLine(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_document_lines
                (document_id, supplier_id, stock_item_id, doc_date, qty, unit_cost, value_total,
                 extra_cost, invoice_item_id, purchase_invoice_item_id, purchase_order_line_id,
                 source_description, source_qty, line_no, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $data['document_id'],
            $supplierId,
            (int) $data['stock_item_id'],
            $data['doc_date'] ?? null,
            (string) $data['qty'],
            (string) ($data['unit_cost'] ?? '0'),
            (string) ($data['value_total'] ?? '0'),
            (string) ($data['extra_cost'] ?? '0'),
            isset($data['invoice_item_id']) && (int) $data['invoice_item_id'] > 0 ? (int) $data['invoice_item_id'] : null,
            isset($data['purchase_invoice_item_id']) && (int) $data['purchase_invoice_item_id'] > 0 ? (int) $data['purchase_invoice_item_id'] : null,
            isset($data['purchase_order_line_id']) && (int) $data['purchase_order_line_id'] > 0 ? (int) $data['purchase_order_line_id'] : null,
            $data['source_description'] ?? null,
            $data['source_qty'] ?? null,
            (int) ($data['line_no'] ?? 0),
            $data['note'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Přepis hlavičky draftu (jen editovatelná pole; status predikát draft).
     *
     * @param array<string,mixed> $data
     */
    public function updateDraftHeader(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE stock_documents SET
                doc_type = ?, origin = ?, warehouse_id = ?, warehouse_to_id = ?, doc_date = ?,
                description = ?, partner_name = ?, invoice_id = ?, purchase_invoice_id = ?,
                purchase_order_id = ?, stock_take_id = ?, allow_over_delivery = ?
              WHERE id = ? AND supplier_id = ? AND status = 'draft'"
        );
        $stmt->execute([
            (string) $data['doc_type'],
            (string) ($data['origin'] ?? 'manual'),
            (int) $data['warehouse_id'],
            isset($data['warehouse_to_id']) && (int) $data['warehouse_to_id'] > 0 ? (int) $data['warehouse_to_id'] : null,
            (string) $data['doc_date'],
            (string) $data['description'],
            $data['partner_name'] ?? null,
            isset($data['invoice_id']) && (int) $data['invoice_id'] > 0 ? (int) $data['invoice_id'] : null,
            isset($data['purchase_invoice_id']) && (int) $data['purchase_invoice_id'] > 0 ? (int) $data['purchase_invoice_id'] : null,
            isset($data['purchase_order_id']) && (int) $data['purchase_order_id'] > 0 ? (int) $data['purchase_order_id'] : null,
            isset($data['stock_take_id']) && (int) $data['stock_take_id'] > 0 ? (int) $data['stock_take_id'] : null,
            !empty($data['allow_over_delivery']) ? 1 : 0,
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0 || ($this->find($supplierId, $id)['status'] ?? null) === 'draft';
    }

    /**
     * Nahradí řádky dokladu (DELETE + INSERT) — jen editace draftu.
     *
     * @param list<array<string,mixed>> $lines
     */
    public function replaceLines(int $supplierId, int $documentId, array $lines): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM stock_document_lines WHERE document_id = ? AND supplier_id = ?'
        )->execute([$documentId, $supplierId]);

        foreach ($lines as $line) {
            $line['document_id'] = $documentId;
            $this->insertLine($supplierId, $line);
        }
    }

    /**
     * Přepis vypočteného ocenění řádku — volá post() a replay ledgeru
     * (StockRecomputeService). Tenant predikát přes supplier_id řádku.
     */
    public function updateLineValuation(
        int $supplierId,
        int $lineId,
        string $unitCost,
        string $valueTotal,
        string $extraCost,
        string $docDate,
    ): void {
        $this->db->pdo()->prepare(
            'UPDATE stock_document_lines
                SET unit_cost = ?, value_total = ?, extra_cost = ?, doc_date = ?
              WHERE id = ? AND supplier_id = ?'
        )->execute([$unitCost, $valueTotal, $extraCost, $docDate, $lineId, $supplierId]);
    }

    public function deleteDraft(int $supplierId, int $id): bool
    {
        // ON DELETE CASCADE smaže i řádky.
        $stmt = $this->db->pdo()->prepare(
            "DELETE FROM stock_documents WHERE id = ? AND supplier_id = ? AND status = 'draft'"
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Zaúčtování: status='posted', doc_number, booked_at=NOW(), booked_by;
     * současně zkopíruje doc_date hlavičky do řádků (index pro replay, B8).
     */
    public function markPosted(int $supplierId, int $id, string $docNumber, ?int $bookedBy): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "UPDATE stock_documents
                SET status = 'posted', doc_number = ?, booked_at = NOW(), booked_by = ?
              WHERE id = ? AND supplier_id = ? AND status = 'draft'"
        );
        $stmt->execute([$docNumber, $bookedBy, $id, $supplierId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $pdo->prepare(
            'UPDATE stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
                SET l.doc_date = d.doc_date, l.ledger_booked_at = d.booked_at
              WHERE l.document_id = ? AND l.supplier_id = ?'
        )->execute([$id, $supplierId]);
        return true;
    }

    public function markReversed(int $supplierId, int $id, int $reversalDocumentId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE stock_documents SET status = 'reversed', reversal_document_id = ?
              WHERE id = ? AND supplier_id = ? AND status = 'posted'"
        );
        $stmt->execute([$reversalDocumentId, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Idempotence auto-výdeje (B4): existuje posted výdejka k FV? Reversed se
     * nepočítá (po stornu smí vzniknout nová výdejka). Volat pod zámkem levels.
     */
    public function findPostedIssueByInvoice(int $supplierId, int $invoiceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT " . self::COLUMNS . " FROM stock_documents
              WHERE supplier_id = ? AND invoice_id = ? AND doc_type = 'issue' AND status = 'posted'
              ORDER BY id DESC
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return list<array<string,mixed>> */
    public function listByInvoice(int $supplierId, int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_documents
              WHERE supplier_id = ? AND invoice_id = ?
              ORDER BY doc_date DESC, id DESC'
        );
        $stmt->execute([$supplierId, $invoiceId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    public function listByPurchaseInvoice(int $supplierId, int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_documents
              WHERE supplier_id = ? AND purchase_invoice_id = ?
              ORDER BY doc_date DESC, id DESC'
        );
        $stmt->execute([$supplierId, $purchaseInvoiceId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Poslední efektivní jednotková hodnota pohybu per karta na skladu k datu.
     * Stornované originály i jejich posted protidoklady jsou vyloučené.
     *
     * @return array<int,string> stock_item_id => unit cost
     */
    public function lastKnownUnitCosts(int $supplierId, int $warehouseId, string $date): array
    {
        $stmt = $this->db->pdo()->prepare(
            "WITH ranked AS (
                SELECT l.stock_item_id,
                       l.value_total / NULLIF(l.qty, 0) AS unit_cost,
                       ROW_NUMBER() OVER (
                           PARTITION BY l.stock_item_id
                           ORDER BY d.doc_date DESC, d.booked_at DESC, d.id DESC, l.line_no DESC, l.id DESC
                       ) AS rn
                  FROM stock_document_lines l
                  JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
                 WHERE l.supplier_id = ? AND d.warehouse_id = ? AND d.doc_date <= ?
                   AND d.status = 'posted' AND l.qty > 0 AND l.value_total > 0
                   AND NOT EXISTS (
                       SELECT 1 FROM stock_documents original
                        WHERE original.supplier_id = d.supplier_id
                          AND original.reversal_document_id = d.id
                   )
            )
            SELECT stock_item_id, unit_cost FROM ranked WHERE rn = 1"
        );
        $stmt->execute([$supplierId, $warehouseId, $date]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['stock_item_id']] = number_format((float) $row['unit_cost'], 6, '.', '');
        }
        return $out;
    }

    public function lastKnownUnitCost(int $supplierId, int $warehouseId, int $stockItemId, string $date): ?string
    {
        $stmt = $this->db->pdo()->prepare("SELECT l.value_total / NULLIF(l.qty, 0)
            FROM stock_document_lines l FORCE INDEX (idx_sdl_valuation_cursor)
            STRAIGHT_JOIN stock_documents d FORCE INDEX (PRIMARY) ON d.id = l.document_id AND d.supplier_id = l.supplier_id
            WHERE l.supplier_id = ? AND l.stock_item_id = ? AND l.doc_date <= ? AND d.warehouse_id = ?
              AND d.status = 'posted' AND l.qty > 0 AND l.value_total > 0
              AND NOT EXISTS (SELECT 1 FROM stock_documents original
                WHERE original.supplier_id = d.supplier_id AND original.reversal_document_id = d.id)
            ORDER BY l.doc_date DESC, l.ledger_booked_at DESC, l.document_id DESC, l.line_no DESC, l.id DESC LIMIT 1");
        $stmt->execute([$supplierId, $stockItemId, $date, $warehouseId]);
        $cost = $stmt->fetchColumn();
        return $cost === false ? null : number_format((float) $cost, 6, '.', '');
    }

    /**
     * Už přijaté množství per řádek PF (párování „zbývá přijmout"). Jen posted
     * příjemky — reversed příjemka se nepočítá (její pohyb neutralizoval protidoklad).
     *
     * @return array<int,string> purchase_invoice_item_id => SUM(qty) (DECIMAL string)
     */
    public function receivedQtyByPurchaseInvoiceItem(int $supplierId, int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.purchase_invoice_item_id, SUM(l.qty) AS received
               FROM stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND d.purchase_invoice_id = ?
                AND d.doc_type = 'receipt' AND d.status = 'posted'
                AND l.purchase_invoice_item_id IS NOT NULL
              GROUP BY l.purchase_invoice_item_id"
        );
        $stmt->execute([$supplierId, $purchaseInvoiceId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['purchase_invoice_item_id']] = (string) $r['received'];
        }
        return $out;
    }

    /**
     * Datum posledního pohybu karty na skladu (detekce backdatingu, §3.2).
     * Stavy ('posted','reversed') — viz class docblock.
     */
    public function lastPostedDocDateForItem(int $supplierId, int $warehouseId, int $stockItemId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT MAX(l.doc_date)
               FROM stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.stock_item_id = ?
                AND d.status IN " . self::LEDGER_STATUSES . "
                AND ((d.doc_type IN ('receipt','issue') AND d.warehouse_id = ?)
                     OR (d.doc_type = 'transfer' AND (d.warehouse_id = ? OR d.warehouse_to_id = ?)))"
        );
        $stmt->execute([$supplierId, $stockItemId, $warehouseId, $warehouseId, $warehouseId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    /**
     * Pohyby karty na skladu pro replay ledgeru (§3.2) v chronologickém pořadí
     * (doc_date, document_id, line_no, id). Stavy ('posted','reversed').
     *
     * Pohyby karty na daném skladu = doklady, kde
     *   (doc_type IN receipt/issue AND warehouse_id = ?)
     *   OR (doc_type = 'transfer' AND (warehouse_id = ? [výdejová noha]
     *                                   OR warehouse_to_id = ? [příjmová noha])).
     *
     * `direction` = efektivní směr vůči TOMUTO skladu (+1 příjem / -1 výdej).
     * `is_reversal` = doklad je protidoklad storna (existuje originál
     * s reversal_document_id = d.id). `status` = stav dokladu — výdejové nohy
     * dokladu, který je SÁM stornovaný (status='reversed') NEBO je protidokladem
     * storna, se při replayi NEPŘECEŇUJÍ (storno-pár je hodnotově neutrální a
     * plně transparentní vůči replayi — §4.4, review CRITICAL 2).
     *
     * Řazení `(doc_date, booked_at, document_id, line_no, id)` = SKUTEČNÉ pořadí
     * zaúčtování (booked_at), aby replay dával stejný výsledek jako inkrementální
     * aplikace i u dokladů se stejným datem zaúčtovaných v jiném pořadí, než byly
     * založeny (review MEDIUM 4).
     *
     * @return list<array{line_id:int, document_id:int, doc_type:string, origin:string,
     *   doc_date:string, line_no:int, qty:string, unit_cost:string, value_total:string,
     *   extra_cost:string, direction:int, is_reversal:bool, status:string, sku:string, name:string}>
     */
    public function postedLinesForItemFrom(int $supplierId, int $warehouseId, int $stockItemId, string $fromDate): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.id AS line_id, l.document_id, d.doc_type, d.origin, d.status,
                    l.doc_date, l.line_no, l.qty, l.unit_cost, l.value_total, l.extra_cost,
                    CASE
                        WHEN d.doc_type = 'receipt' THEN 1
                        WHEN d.doc_type = 'issue' THEN -1
                        WHEN d.warehouse_id = ? THEN -1
                        ELSE 1
                    END AS direction,
                    EXISTS (
                        SELECT 1 FROM stock_documents o
                         WHERE o.supplier_id = d.supplier_id AND o.reversal_document_id = d.id
                    ) AS is_reversal,
                    si.sku, si.name
               FROM stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
               JOIN stock_items si ON si.id = l.stock_item_id AND si.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.stock_item_id = ?
                AND d.status IN " . self::LEDGER_STATUSES . "
                AND l.doc_date >= ?
                AND ((d.doc_type IN ('receipt','issue') AND d.warehouse_id = ?)
                     OR (d.doc_type = 'transfer' AND (d.warehouse_id = ? OR d.warehouse_to_id = ?)))
              ORDER BY l.doc_date ASC, d.booked_at ASC, l.document_id ASC, l.line_no ASC, l.id ASC"
        );
        $stmt->execute([
            $warehouseId,
            $supplierId,
            $stockItemId,
            $fromDate,
            $warehouseId,
            $warehouseId,
            $warehouseId,
        ]);

        return array_map(static fn (array $r): array => [
            'line_id'     => (int) $r['line_id'],
            'document_id' => (int) $r['document_id'],
            'doc_type'    => (string) $r['doc_type'],
            'origin'      => (string) $r['origin'],
            'status'      => (string) $r['status'],
            'doc_date'    => (string) $r['doc_date'],
            'line_no'     => (int) $r['line_no'],
            'qty'         => (string) $r['qty'],
            'unit_cost'   => (string) $r['unit_cost'],
            'value_total' => (string) $r['value_total'],
            'extra_cost'  => (string) $r['extra_cost'],
            'direction'   => (int) $r['direction'],
            'is_reversal' => (bool) $r['is_reversal'],
            'sku'         => (string) $r['sku'],
            'name'        => (string) $r['name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['allow_over_delivery'] = (bool) $r['allow_over_delivery'];
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['warehouse_id'] = (int) $r['warehouse_id'];
        $r['warehouse_to_id'] = $r['warehouse_to_id'] !== null ? (int) $r['warehouse_to_id'] : null;
        $r['invoice_id'] = $r['invoice_id'] !== null ? (int) $r['invoice_id'] : null;
        $r['purchase_invoice_id'] = $r['purchase_invoice_id'] !== null ? (int) $r['purchase_invoice_id'] : null;
        $r['purchase_order_id'] = $r['purchase_order_id'] !== null ? (int) $r['purchase_order_id'] : null;
        $r['stock_take_id'] = $r['stock_take_id'] !== null ? (int) $r['stock_take_id'] : null;
        $r['journal_entry_id'] = $r['journal_entry_id'] !== null ? (int) $r['journal_entry_id'] : null;
        $r['reversal_document_id'] = $r['reversal_document_id'] !== null ? (int) $r['reversal_document_id'] : null;
        $r['booked_by'] = $r['booked_by'] !== null ? (int) $r['booked_by'] : null;
        $r['created_by'] = $r['created_by'] !== null ? (int) $r['created_by'] : null;
        return $r;
    }

    /** @return array<string,mixed> */
    private static function castLine(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['document_id'] = (int) $r['document_id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['stock_item_id'] = (int) $r['stock_item_id'];
        $r['invoice_item_id'] = $r['invoice_item_id'] !== null ? (int) $r['invoice_item_id'] : null;
        $r['purchase_invoice_item_id'] = $r['purchase_invoice_item_id'] !== null ? (int) $r['purchase_invoice_item_id'] : null;
        $r['purchase_order_line_id'] = $r['purchase_order_line_id'] !== null ? (int) $r['purchase_order_line_id'] : null;
        $r['line_no'] = (int) $r['line_no'];
        return $r;
    }
}
