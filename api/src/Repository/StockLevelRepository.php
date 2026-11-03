<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use PDO;

/**
 * READ-ONLY repository nad stock_levels + skladovou knihou (Epic SKLAD).
 * VÝHRADNĚ SELECTy — mutace stock_levels žijí jedině
 * v {@see \MyInvoice\Service\Stock\StockLevelService} (architektonický test).
 * Tenant predikát supplier_id na každém dotazu.
 */
final class StockLevelRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Stavy zásob s kartou a skladem (list Stav skladu).
     * Filtry: warehouse_id, item_type, below_min (bool), active (bool), q, item_ids (list<int>),
     * category_id (kategorie včetně podkategorií).
     *
     * Volitelné údaje karty pro sestavu Stav zásob (bez nich je řádek beze změny):
     * `sale_price` a `note` přidají prodejní cenu bez DPH a poznámku karty,
     * `$attribute` (id + data_type) přidá `attribute_value` = hodnoty parametru karty
     * spojené čárkou v pořadí karty. Hodnoty se předpočítají jedním seskupeným
     * dotazem pro celý parametr a připojí JOINem, ne dotazem na každý řádek.
     *
     * @param array<string,mixed> $filters
     * @param list<string> $extraColumns podmnožina ['sale_price', 'note']
     * @param array{id:int,data_type:string}|null $attribute
     * @return list<array<string,mixed>>
     */
    public function levels(int $supplierId, array $filters, array $extraColumns = [], ?array $attribute = null): array
    {
        [$where, $params] = $this->buildLevelsWhereClause($supplierId, $filters);

        $select = '';
        $join = '';
        $joinParams = [];
        if (in_array('sale_price', $extraColumns, true)) {
            $select .= ', si.sale_price_without_vat AS sale_price';
        }
        if (in_array('note', $extraColumns, true)) {
            $select .= ', si.note';
        }
        if ($attribute !== null) {
            $select .= ', av.attribute_value';
            $join = ' LEFT JOIN (
                    SELECT siav.stock_item_id, ' . StockAttributeDisplayValue::AGGREGATE_SQL . ' AS attribute_value
                      FROM stock_item_attribute_values siav' . StockAttributeDisplayValue::JOINS_SQL . '
                     WHERE siav.supplier_id = ? AND siav.attribute_id = ?
                     GROUP BY siav.stock_item_id
               ) av ON av.stock_item_id = sl.stock_item_id';
            $joinParams = [$supplierId, (int) $attribute['id']];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT sl.warehouse_id, sl.stock_item_id, sl.qty, sl.value_total, sl.avg_unit_cost,
                    si.sku, si.name, si.unit, si.item_type, si.min_qty, si.is_active,
                    w.code AS warehouse_code, w.name AS warehouse_name' . $select . '
               FROM stock_levels sl
               JOIN stock_items si ON si.id = sl.stock_item_id AND si.supplier_id = sl.supplier_id
               JOIN warehouses w ON w.id = sl.warehouse_id AND w.supplier_id = sl.supplier_id' . $join . '
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY w.code ASC, si.sku ASC'
        );
        $stmt->execute([...$joinParams, ...$params]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $row = self::castLevel($r);
            if (in_array('sale_price', $extraColumns, true)) {
                $row['sale_price'] = $r['sale_price'] !== null ? (string) UnitPricePrecision::toDecimalString($r['sale_price']) : null;
            }
            if (in_array('note', $extraColumns, true)) {
                $row['note'] = $r['note'] !== null && $r['note'] !== '' ? (string) $r['note'] : null;
            }
            if ($attribute !== null) {
                $row['attribute_value'] = $r['attribute_value'] !== null && $r['attribute_value'] !== '' ? (string) $r['attribute_value'] : null;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Stránkovaná verze levels() pro API (Support\Pagination kontrakt) — vrací
     * i celkový počet (COUNT přes stejné WHERE, bez LIMIT). Stejné filtry jako levels().
     *
     * @param array<string,mixed> $filters
     * @return array{0:list<array<string,mixed>>, 1:int}
     */
    public function levelsPaged(int $supplierId, array $filters, int $perPage, int $offset): array
    {
        [$where, $params] = $this->buildLevelsWhereClause($supplierId, $filters);
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM stock_levels sl
               JOIN stock_items si ON si.id = sl.stock_item_id AND si.supplier_id = sl.supplier_id
               JOIN warehouses w ON w.id = sl.warehouse_id AND w.supplier_id = sl.supplier_id
              WHERE ' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            'SELECT sl.warehouse_id, sl.stock_item_id, sl.qty, sl.value_total, sl.avg_unit_cost,
                    si.sku, si.name, si.unit, si.item_type, si.min_qty, si.is_active,
                    w.code AS warehouse_code, w.name AS warehouse_name
               FROM stock_levels sl
               JOIN stock_items si ON si.id = sl.stock_item_id AND si.supplier_id = sl.supplier_id
               JOIN warehouses w ON w.id = sl.warehouse_id AND w.supplier_id = sl.supplier_id
              WHERE ' . $whereSql . '
              ORDER BY w.code ASC, si.sku ASC
              LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);
        $rows = array_map([self::class, 'castLevel'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        return [$rows, $total];
    }

    /**
     * Sestaví sdílené WHERE/params pro levels()/levelsPaged() — aby COUNT(*)
     * a datový dotaz vždy zůstaly konzistentní.
     *
     * @param array<string,mixed> $filters
     * @return array{0:list<string>, 1:array<int,mixed>}
     */
    private function buildLevelsWhereClause(int $supplierId, array $filters): array
    {
        $where  = ['sl.supplier_id = ?'];
        $params = [$supplierId];
        if (!empty($filters['warehouse_id'])) {
            $where[]  = 'sl.warehouse_id = ?';
            $params[] = (int) $filters['warehouse_id'];
        }
        if (!empty($filters['item_type'])) {
            $where[]  = 'si.item_type = ?';
            $params[] = (string) $filters['item_type'];
        }
        if (!empty($filters['below_min'])) {
            $where[] = 'si.min_qty IS NOT NULL AND sl.qty < si.min_qty';
        }
        if (array_key_exists('active', $filters) && $filters['active'] !== null && $filters['active'] !== '') {
            $where[]  = 'si.is_active = ?';
            $params[] = (int) (bool) $filters['active'];
        }
        if (!empty($filters['q'])) {
            $q        = addcslashes((string) $filters['q'], '%_\\');
            $where[]  = '(si.sku LIKE ? OR si.name LIKE ?)';
            $params[] = $q . '%';
            $params[] = '%' . $q . '%';
        }
        if (!empty($filters['category_id'])) {
            $where[]  = StockItemRepository::categorySubtreeSql('si');
            $params[] = (int) $filters['category_id'];
        }
        if (!empty($filters['item_ids'])) {
            $ids = array_values(array_unique(array_map('intval', (array) $filters['item_ids'])));
            if ($ids !== []) {
                $where[]  = 'sl.stock_item_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                foreach ($ids as $id) {
                    $params[] = $id;
                }
            }
        }

        return [$where, $params];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function castLevel(array $r): array
    {
        return [
            'warehouse_id'   => (int) $r['warehouse_id'],
            'stock_item_id'  => (int) $r['stock_item_id'],
            'qty'            => (string) $r['qty'],
            'value_total'    => (string) $r['value_total'],
            'avg_unit_cost'  => (string) $r['avg_unit_cost'],
            'sku'            => (string) $r['sku'],
            'name'           => (string) $r['name'],
            'unit'           => (string) $r['unit'],
            'item_type'      => (string) $r['item_type'],
            'min_qty'        => $r['min_qty'] !== null ? (string) $r['min_qty'] : null,
            'is_active'      => (bool) $r['is_active'],
            'warehouse_code' => (string) $r['warehouse_code'],
            'warehouse_name' => (string) $r['warehouse_name'],
        ];
    }

    /** Celková hodnota zásob skladu (DECIMAL string, '0.00' bez řádků). */
    public function warehouseValue(int $supplierId, int $warehouseId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(value_total), 0) FROM stock_levels
              WHERE supplier_id = ? AND warehouse_id = ?'
        );
        $stmt->execute([$supplierId, $warehouseId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? '0.00' : (string) $v;
    }

    /**
     * Dávková dostupnost karet pro badge v editoru FV. Bez skladu = součet přes
     * aktivní prodejné sklady. Karty bez řádku stavu v mapě chybí (= 0).
     *
     * @param list<int> $itemIds
     * @return array<int,string> stock_item_id => dostupné qty (DECIMAL string)
     */
    public function availability(int $supplierId, array $itemIds, ?int $warehouseId): array
    {
        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        if ($ids === []) {
            return [];
        }
        $place  = implode(',', array_fill(0, count($ids), '?'));
        $sql    = "SELECT stock_item_id, SUM(qty) AS available
                     FROM stock_levels
                    WHERE supplier_id = ? AND stock_item_id IN ($place)
                      AND warehouse_id IN (SELECT id FROM warehouses WHERE is_active = 1 AND is_sellable = 1)";
        $params = array_merge([$supplierId], $ids);
        if ($warehouseId !== null) {
            $sql     .= ' AND warehouse_id = ?';
            $params[] = $warehouseId;
        }
        $sql .= ' GROUP BY stock_item_id';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['stock_item_id']] = (string) $r['available'];
        }
        return $out;
    }

    /**
     * Vážený průměr nákupní ceny karty v CZK (Epic ESHOP cenotvorba) =
     * SUM(value_total)/SUM(qty) napříč sklady. Null když stav = 0 (nelze
     * vydělit) nebo karta nemá řádky — volající spadne na jiný zdroj (E5).
     */
    public function weightedAvgCost(int $supplierId, int $stockItemId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT SUM(value_total) AS v, SUM(qty) AS q FROM stock_levels
              WHERE supplier_id = ? AND stock_item_id = ?'
        );
        $stmt->execute([$supplierId, $stockItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['q'] === null) {
            return null;
        }
        $qty = (string) $row['q'];
        if (bccomp($qty, '0', 6) <= 0) {
            return null;
        }
        return bcdiv((string) $row['v'], $qty, 6);
    }

    /**
     * Poslední nákupní cena z příjemky (Epic ESHOP cenotvorba) — unit_cost
     * nejnovějšího posted příjmového řádku. Null bez příjemky.
     */
    public function lastPurchaseCost(int $supplierId, int $stockItemId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.unit_cost
               FROM stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.stock_item_id = ?
                AND d.doc_type = 'receipt' AND d.status = 'posted'
              ORDER BY l.doc_date DESC, l.document_id DESC, l.line_no DESC
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $stockItemId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    /**
     * Skladová kniha karty napříč sklady (pohyby z posted i reversed dokladů —
     * reversed pohyb reálně proběhl, neutralizuje ho protidoklad). Převodka se
     * rozpadá na dvě nohy (výdej ze zdroje −qty, příjem na cíl +qty).
     *
     * @param array{warehouse_id?:int|null, from?:string|null, to?:string|null,
     *              limit?:int, offset?:int} $opts
     * @return list<array<string,mixed>>
     */
    public function ledgerForItem(int $supplierId, int $stockItemId, array $opts = []): array
    {
        $limit  = max(1, min(500, (int) ($opts['limit'] ?? 100)));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $params = [];
        $sql = $this->ledgerSql($supplierId, $stockItemId, $opts, true, $params)
             . ' ORDER BY doc_date ASC, document_id ASC, line_no ASC, line_id ASC, leg ASC'
             . ' LIMIT ? OFFSET ?';

        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        $stmt->bindValue($idx++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $r): array => [
            'line_id'        => (int) $r['line_id'],
            'document_id'    => (int) $r['document_id'],
            'doc_number'     => $r['doc_number'] !== null ? (string) $r['doc_number'] : null,
            'doc_type'       => (string) $r['doc_type'],
            'origin'         => (string) $r['origin'],
            'status'         => (string) $r['status'],
            'doc_date'       => (string) $r['doc_date'],
            'line_no'        => (int) $r['line_no'],
            'warehouse_id'   => (int) $r['warehouse_id'],
            'warehouse_code' => (string) $r['warehouse_code'],
            'qty_signed'     => (string) $r['qty_signed'],
            'qty'            => (string) $r['qty'],
            'unit_cost'      => (string) $r['unit_cost'],
            'value_total'    => (string) $r['value_total'],
            'note'           => $r['note'] !== null ? (string) $r['note'] : null,
            'imported'       => (bool) $r['imported'],
            'invoice_id'     => $r['invoice_id'] !== null ? (int) $r['invoice_id'] : null,
            'invoice_number' => $r['invoice_number'] !== null ? (string) $r['invoice_number'] : null,
            'invoice_type'   => $r['invoice_type'] !== null ? (string) $r['invoice_type'] : null,
            'purchase_invoice_id'     => $r['purchase_invoice_id'] !== null ? (int) $r['purchase_invoice_id'] : null,
            'purchase_invoice_number' => $r['purchase_invoice_number'] !== null ? (string) $r['purchase_invoice_number'] : null,
            'partner'        => self::ledgerPartner($r),
            'sale_unit_price' => $r['sale_unit_price'] !== null ? (string) $r['sale_unit_price'] : null,
            'sale_unit'       => $r['sale_unit'] !== null ? (string) $r['sale_unit'] : null,
            'sale_currency'   => $r['sale_currency'] !== null ? (string) $r['sale_currency'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Součet pohybů PŘED stránkou (prvních `$offset` řádků ve stejném pořadí a se
     * stejnými filtry) = počáteční bilance stránky. Bez stropu 500 řádků a bez
     * připojování dokladů, protože potřebuje jen množství.
     *
     * @param array{warehouse_id?:int|null, from?:string|null, to?:string|null} $opts
     */
    public function ledgerQtyBefore(int $supplierId, int $stockItemId, array $opts, int $offset): string
    {
        if ($offset <= 0) {
            return '0.000';
        }
        $params = [];
        $sql = 'SELECT COALESCE(SUM(qty_signed), 0) FROM ('
             . $this->ledgerSql($supplierId, $stockItemId, $opts, false, $params)
             . ' ORDER BY doc_date ASC, document_id ASC, line_no ASC, line_id ASC, leg ASC LIMIT ?) first_rows';
        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        $stmt->bindValue($idx, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return bcadd((string) $stmt->fetchColumn(), '0', 3);
    }

    /**
     * Jednotková prodejní cena řádku faktury bez DPH. U faktury s cenami s DPH
     * (`prices_include_vat`) drží `unit_price_without_vat` cenu S DPH (InvoiceMath),
     * netto cena se proto odvodí ze základu řádku.
     */
    public static function netUnitPriceSql(string $invoiceAlias, string $itemAlias): string
    {
        return "CASE WHEN {$invoiceAlias}.prices_include_vat = 1 AND {$itemAlias}.quantity <> 0"
            . " THEN ROUND({$itemAlias}.total_without_vat / {$itemAlias}.quantity, 6)"
            . " ELSE {$itemAlias}.unit_price_without_vat END";
    }

    /**
     * Jméno protistrany ze snapshotu dokladu (JSON sloupec): firma, jinak jméno
     * a příjmení, jinak `name` (starší a převzaté snapshoty). Stav k datu dokladu,
     * ne dnešní název v adresáři.
     */
    public static function snapshotNameSql(string $jsonColumn): string
    {
        return "COALESCE(NULLIF(JSON_VALUE({$jsonColumn}, '$.company_name'), ''),"
            . " NULLIF(TRIM(CONCAT_WS(' ', JSON_VALUE({$jsonColumn}, '$.first_name'), JSON_VALUE({$jsonColumn}, '$.last_name'))), ''),"
            . " NULLIF(JSON_VALUE({$jsonColumn}, '$.name'), ''))";
    }

    /**
     * Obě nohy skladové knihy (výdejová a příjmová noha převodky) jako UNION ALL
     * bez ORDER/LIMIT. `$withDocuments` připojí fakturu, řádek faktury a přijatou
     * fakturu, které pohyb vyvolaly (číslo, protistrana, prodejní cena).
     *
     * @param array{warehouse_id?:int|null, from?:string|null, to?:string|null} $opts
     * @param list<mixed> $params plní se v pořadí placeholderů
     */
    private function ledgerSql(int $supplierId, int $stockItemId, array $opts, bool $withDocuments, array &$params): string
    {
        $warehouseId = isset($opts['warehouse_id']) && (int) $opts['warehouse_id'] > 0
            ? (int) $opts['warehouse_id'] : null;
        $clientName = self::snapshotNameSql('inv.client_snapshot');
        $vendorName = self::snapshotNameSql('pinv.vendor_snapshot');

        $leg = function (bool $receiptLeg) use ($supplierId, $stockItemId, $warehouseId, $opts, $withDocuments, $clientName, $vendorName, &$params): string {
            // Výdejová noha: receipt/issue/transfer-out na d.warehouse_id;
            // příjmová noha převodky: d.warehouse_to_id.
            $warehouseExpr = $receiptLeg ? 'd.warehouse_to_id' : 'd.warehouse_id';
            $signedQty = $receiptLeg
                ? 'l.qty'
                : "CASE WHEN d.doc_type = 'receipt' THEN l.qty ELSE -l.qty END";
            $typeCond = $receiptLeg
                ? "d.doc_type = 'transfer'"
                : "d.doc_type IN ('receipt','issue','transfer')";
            $documentCols = $withDocuments
                ? ", inv.id AS invoice_id, inv.varsymbol AS invoice_number, inv.invoice_type, inv.client_id,
                   {$clientName} AS client_name,
                   " . self::netUnitPriceSql('inv', 'ii') . " AS sale_unit_price, ii.unit AS sale_unit, cur.code AS sale_currency,
                   pinv.id AS purchase_invoice_id,
                   COALESCE(NULLIF(pinv.vendor_invoice_number, ''), pinv.varsymbol) AS purchase_invoice_number,
                   pinv.vendor_id, {$vendorName} AS vendor_name, d.partner_name"
                : '';
            $documentJoins = $withDocuments
                ? ' LEFT JOIN invoices inv ON inv.id = d.invoice_id AND inv.supplier_id = d.supplier_id
                    LEFT JOIN invoice_items ii ON ii.id = l.invoice_item_id AND ii.invoice_id = inv.id
                    LEFT JOIN currencies cur ON cur.id = inv.currency_id
                    LEFT JOIN purchase_invoices pinv ON pinv.id = d.purchase_invoice_id AND pinv.supplier_id = d.supplier_id'
                : '';
            $sql = "SELECT l.id AS line_id, l.document_id, d.doc_number, d.doc_type, d.origin,
                           d.status, l.doc_date, l.line_no, {$warehouseExpr} AS warehouse_id,
                           w.code AS warehouse_code, {$signedQty} AS qty_signed,
                           l.qty, l.unit_cost, l.value_total, l.note, d.history_import_job_id IS NOT NULL AS imported,
                           " . ($receiptLeg ? 1 : 0) . " AS leg{$documentCols}
                      FROM stock_document_lines l
                      JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
                      JOIN warehouses w ON w.id = {$warehouseExpr} AND w.supplier_id = l.supplier_id{$documentJoins}
                     WHERE l.supplier_id = ? AND l.stock_item_id = ?
                       AND d.status IN ('posted','reversed')
                       AND {$typeCond}";
            $params[] = $supplierId;
            $params[] = $stockItemId;
            if ($warehouseId !== null) {
                $sql     .= $receiptLeg ? ' AND d.warehouse_to_id = ?' : ' AND d.warehouse_id = ?';
                $params[] = $warehouseId;
            }
            if (!empty($opts['from'])) {
                $sql     .= ' AND l.doc_date >= ?';
                $params[] = (string) $opts['from'];
            }
            if (!empty($opts['to'])) {
                $sql     .= ' AND l.doc_date <= ?';
                $params[] = (string) $opts['to'];
            }
            return $sql;
        };

        return '(' . $leg(false) . ') UNION ALL (' . $leg(true) . ')';
    }

    /**
     * Protistrana pohybu: odběratel u výdeje k FV a vratky k dobropisu, dodavatel
     * u příjmu z PF, jinak volný text ručního dokladu (bez odkazu do adresáře).
     *
     * @param array<string,mixed> $r
     * @return array{kind:string,id:?int,name:string}|null
     */
    private static function ledgerPartner(array $r): ?array
    {
        if ($r['invoice_id'] !== null) {
            $name = (string) ($r['client_name'] ?? '');
            return $name === '' && $r['client_id'] === null ? null
                : ['kind' => 'client', 'id' => $r['client_id'] !== null ? (int) $r['client_id'] : null, 'name' => $name];
        }
        if ($r['purchase_invoice_id'] !== null) {
            $name = (string) ($r['vendor_name'] ?? '');
            return $name === '' && $r['vendor_id'] === null ? null
                : ['kind' => 'vendor', 'id' => $r['vendor_id'] !== null ? (int) $r['vendor_id'] : null, 'name' => $name];
        }
        $name = trim((string) ($r['partner_name'] ?? ''));
        return $name === '' ? null : ['kind' => 'text', 'id' => null, 'name' => $name];
    }
}
