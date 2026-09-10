<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Kanonické dotazy pro ODVOZENÁ množství — „na cestě" a „rezervováno"
 * (Epic SKLAD „na cestě", §3.4 a §11.2).
 *
 * ## Proč se nic z toho neukládá
 *
 * `stock_levels` existuje kvůli OCEŇOVÁNÍ: haléřová aritmetika, klouzavý průměr,
 * `FOR UPDATE`, replay ledgeru, architektonický guard proti mutaci odjinud.
 * Objednané ani rezervované zboží z toho nepotřebuje nic — nemá pořizovací cenu
 * a nevstupuje do rozvahy, dokud nepřejde vlastnictví. Materializovaný čítač by
 * musel mít háček v odeslání i stornu objednávky, ve vystavení, stornu, dobropisu
 * i smazání faktury a v každé příjemce a výdejce — přesně v místech, kde se to
 * tiše rozejde. Odvození ze zdroje pravdy se naopak samo hojí.
 *
 * ## Proč `stock_document_lines`, a ne řádek faktury
 *
 * Obojí čte pohyby jako `SUM(receipt) − SUM(issue)` přes vazební sloupec:
 * storno vytvoří protidoklad se STEJNÝM vazebním id a opačným `doc_type`, takže
 * se sám odečte a množství se vrátí. Podmínka je, že
 * {@see \MyInvoice\Service\Stock\StockDocumentService::reverse()} ty sloupce
 * do protidokladu kopíruje (R-1) — jinak tahle třída vrací tiše špatná čísla.
 *
 * ## Proč `status IN ('posted','reversed')`, a ne jen `posted`
 *
 * Stornovaný doklad NENÍ neplatný pohyb: jeho pohyb reálně proběhl a neutralizuje
 * ho protidoklad, který je `posted` (viz docblock {@see StockDocumentRepository}).
 * Kdyby se čítalo jen `posted`, po stornu by v součtu zůstal SAMOTNÝ protidoklad
 * se záporným znaménkem a „na cestě" by vyskočilo NAD objednané množství — přesně
 * naopak, než jak se ta chyba intuitivně čeká.
 *
 * VŠECHNY dotazy jsou psané tak, aby karta bez jediného pohybu vrátila NULU,
 * ne prázdný výsledek (rozhodnutí #12): agregáty se počítají zvlášť a skládají
 * se na kartu v service vrstvě, nikdy JOINem, který by kartu zahodil.
 */
final class InTransitRepository
{
    /**
     * Stavy objednávky, ze kterých se počítá „na cestě" (rozhodnutí #2).
     * `draft` ne (ještě není závazek), `received`/`closed`/`cancelled` taky ne.
     *
     * @var array<string, list<string>>
     */
    private const IN_TRANSIT_STATES = [
        'sent'      => ['sent', 'confirmed', 'partially_received'],
        'confirmed' => ['confirmed', 'partially_received'],
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * Od kterého stavu daná firma počítá zboží „na cestě".
     *
     * @return list<string>
     */
    public function inTransitStates(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT stock_in_transit_from FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $from = (string) ($stmt->fetchColumn() ?: 'sent');

        return self::IN_TRANSIT_STATES[$from] ?? self::IN_TRANSIT_STATES['sent'];
    }

    /**
     * „Na cestě" per karta × sklad — kanonický dotaz §3.4.
     *
     * `received` CTE odečítá už přijaté množství přes
     * `stock_document_lines.purchase_order_line_id`; protidoklad storna (issue
     * se stejnou vazbou) přijde se znaménkem mínus, takže se zboží vrátí na cestu.
     *
     * @param list<int> $itemIds prázdné = všechny karty firmy
     * @return list<array{stock_item_id:int, warehouse_id:int, qty_in_transit:string, earliest_expected_date:?string}>
     */
    public function forItems(int $supplierId, array $itemIds = [], ?int $warehouseId = null): array
    {
        $states = $this->inTransitStates($supplierId);
        $params = [$supplierId, $supplierId];

        $itemFilter = '';
        if ($itemIds !== []) {
            $itemFilter = ' AND l.stock_item_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')';
        }
        $statePlace = implode(',', array_fill(0, count($states), '?'));

        $sql = "WITH received AS (
                    SELECT sl.purchase_order_line_id AS pol_id,
                           SUM(CASE WHEN sd.doc_type = 'receipt' THEN sl.qty ELSE -sl.qty END) AS qty
                      FROM stock_document_lines sl
                      JOIN stock_documents sd ON sd.id = sl.document_id AND sd.supplier_id = sl.supplier_id
                     WHERE sl.supplier_id = ? AND sd.status IN ('posted','reversed')
                       AND sl.purchase_order_line_id IS NOT NULL
                     GROUP BY sl.purchase_order_line_id
                )
                SELECT l.stock_item_id,
                       COALESCE(l.warehouse_id, o.warehouse_id) AS warehouse_id,
                       SUM(GREATEST(COALESCE(l.qty_confirmed, l.qty_ordered)
                                    - l.qty_cancelled - COALESCE(r.qty, 0), 0)) AS qty_in_transit,
                       MIN(COALESCE(l.expected_date, o.expected_date)) AS earliest_expected_date
                  FROM purchase_order_lines l
                  JOIN purchase_orders o ON o.id = l.order_id AND o.supplier_id = l.supplier_id
             LEFT JOIN received r ON r.pol_id = l.id
                 WHERE l.supplier_id = ? AND l.stock_item_id IS NOT NULL
                   AND o.state IN ($statePlace)" . $itemFilter;

        foreach ($states as $state) {
            $params[] = $state;
        }
        foreach ($itemIds as $id) {
            $params[] = (int) $id;
        }
        if ($warehouseId !== null) {
            $sql     .= ' AND COALESCE(l.warehouse_id, o.warehouse_id) = ?';
            $params[] = $warehouseId;
        }
        $sql .= ' GROUP BY l.stock_item_id, COALESCE(l.warehouse_id, o.warehouse_id)
                  HAVING qty_in_transit > 0';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn (array $r): array => [
            'stock_item_id'          => (int) $r['stock_item_id'],
            'warehouse_id'           => (int) $r['warehouse_id'],
            'qty_in_transit'         => (string) $r['qty_in_transit'],
            'earliest_expected_date' => $r['earliest_expected_date'] !== null ? (string) $r['earliest_expected_date'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Rozpad „na cestě" na konkrétní objednávky — aby šlo dohledat, kdo to veze.
     *
     * @param list<int> $itemIds
     * @return list<array<string,mixed>>
     */
    public function ordersForItems(int $supplierId, array $itemIds = [], ?int $warehouseId = null): array
    {
        $states = $this->inTransitStates($supplierId);
        $params = [$supplierId, $supplierId];

        $itemFilter = '';
        if ($itemIds !== []) {
            $itemFilter = ' AND l.stock_item_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')';
        }
        $statePlace = implode(',', array_fill(0, count($states), '?'));

        $sql = "WITH received AS (
                    SELECT sl.purchase_order_line_id AS pol_id,
                           SUM(CASE WHEN sd.doc_type = 'receipt' THEN sl.qty ELSE -sl.qty END) AS qty
                      FROM stock_document_lines sl
                      JOIN stock_documents sd ON sd.id = sl.document_id AND sd.supplier_id = sl.supplier_id
                     WHERE sl.supplier_id = ? AND sd.status IN ('posted','reversed')
                       AND sl.purchase_order_line_id IS NOT NULL
                     GROUP BY sl.purchase_order_line_id
                )
                SELECT l.stock_item_id, l.id AS purchase_order_line_id,
                       o.id AS order_id, o.order_number, o.state,
                       COALESCE(l.warehouse_id, o.warehouse_id) AS warehouse_id,
                       COALESCE(l.expected_date, o.expected_date) AS expected_date,
                       c.company_name AS vendor_name,
                       GREATEST(COALESCE(l.qty_confirmed, l.qty_ordered)
                                - l.qty_cancelled - COALESCE(r.qty, 0), 0) AS qty
                  FROM purchase_order_lines l
                  JOIN purchase_orders o ON o.id = l.order_id AND o.supplier_id = l.supplier_id
             LEFT JOIN clients c ON c.id = o.vendor_id AND c.supplier_id = o.supplier_id
             LEFT JOIN received r ON r.pol_id = l.id
                 WHERE l.supplier_id = ? AND l.stock_item_id IS NOT NULL
                   AND o.state IN ($statePlace)" . $itemFilter;

        foreach ($states as $state) {
            $params[] = $state;
        }
        foreach ($itemIds as $id) {
            $params[] = (int) $id;
        }
        if ($warehouseId !== null) {
            $sql     .= ' AND COALESCE(l.warehouse_id, o.warehouse_id) = ?';
            $params[] = $warehouseId;
        }
        // Celý výraz, ne alias: `expected_date` existuje na obou JOINovaných
        // tabulkách, takže by ho MariaDB v ORDER BY označila za nejednoznačný.
        $sql .= ' HAVING qty > 0
                  ORDER BY COALESCE(l.expected_date, o.expected_date) IS NULL,
                           COALESCE(l.expected_date, o.expected_date) ASC, o.id ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn (array $r): array => [
            'stock_item_id'          => (int) $r['stock_item_id'],
            'purchase_order_line_id' => (int) $r['purchase_order_line_id'],
            'order_id'               => (int) $r['order_id'],
            'order_number'           => $r['order_number'] !== null ? (string) $r['order_number'] : null,
            'state'                  => (string) $r['state'],
            'warehouse_id'           => (int) $r['warehouse_id'],
            'expected_date'          => $r['expected_date'] !== null ? (string) $r['expected_date'] : null,
            'vendor_name'            => $r['vendor_name'] !== null ? (string) $r['vendor_name'] : null,
            'qty'                    => (string) $r['qty'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * „Rezervováno" per karta — §11.2.
     *
     * Aplikace nemá samostatný modul objednávek zákazníka; objednávka e-shopu JE
     * faktura. Rezervace tedy = položky vydaných faktur se `stock_item_id`, které
     * ještě nemají zaúčtovaný výdej. Proforma nerezervuje (není závazek dodat),
     * draft a storno taky ne.
     *
     * Firmy se `stock_auto_issue = 1` (výdejka vzniká rovnou při vystavení) mají
     * rezervace trvale nulové — a to je správně, žádné okno mezi závazkem
     * a výdejem u nich neexistuje.
     *
     * @param list<int> $itemIds
     * @return list<array{stock_item_id:int, warehouse_id:?int, qty_reserved:string}>
     */
    public function reservedForItems(int $supplierId, array $itemIds = [], ?int $warehouseId = null): array
    {
        $params = [$supplierId, $supplierId];

        $itemFilter = '';
        if ($itemIds !== []) {
            $itemFilter = ' AND ii.stock_item_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')';
        }

        $sql = "WITH issued AS (
                    SELECT sl.invoice_item_id AS ii_id,
                           SUM(CASE WHEN sd.doc_type = 'issue' THEN sl.qty ELSE -sl.qty END) AS qty
                      FROM stock_document_lines sl
                      JOIN stock_documents sd ON sd.id = sl.document_id AND sd.supplier_id = sl.supplier_id
                     WHERE sl.supplier_id = ? AND sd.status IN ('posted','reversed')
                       AND sl.invoice_item_id IS NOT NULL
                     GROUP BY sl.invoice_item_id
                )
                SELECT ii.stock_item_id, ii.warehouse_id,
                       SUM(GREATEST(ii.quantity - COALESCE(s.qty, 0), 0)) AS qty_reserved
                  FROM invoice_items ii
                  JOIN invoices i ON i.id = ii.invoice_id AND i.supplier_id = ?
             LEFT JOIN issued s ON s.ii_id = ii.id
                 WHERE ii.stock_item_id IS NOT NULL
                   AND i.invoice_type = 'invoice'
                   AND i.status NOT IN ('draft', 'cancelled')
                   AND i.cancelled_at IS NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM sales_order_invoice_links soil
                        WHERE soil.supplier_id = i.supplier_id AND soil.invoice_id = i.id
                   )" . $itemFilter;

        foreach ($itemIds as $id) {
            $params[] = (int) $id;
        }
        if ($warehouseId !== null) {
            // Řádek bez skladu se počítá do libovolného filtru — historická data
            // warehouse_id nemají a zamlčet je by rezervaci schovalo.
            $sql     .= ' AND (ii.warehouse_id = ? OR ii.warehouse_id IS NULL)';
            $params[] = $warehouseId;
        }
        $sql .= ' GROUP BY ii.stock_item_id, ii.warehouse_id HAVING qty_reserved > 0';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn (array $r): array => [
            'stock_item_id' => (int) $r['stock_item_id'],
            'warehouse_id'  => $r['warehouse_id'] !== null ? (int) $r['warehouse_id'] : null,
            'qty_reserved'  => (string) $r['qty_reserved'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Rozpad rezervací na konkrétní faktury — „co ten kus drží".
     *
     * @param list<int> $itemIds
     * @return list<array<string,mixed>>
     */
    public function reservationInvoices(int $supplierId, array $itemIds = [], ?int $warehouseId = null): array
    {
        $params = [$supplierId, $supplierId];

        $itemFilter = '';
        if ($itemIds !== []) {
            $itemFilter = ' AND ii.stock_item_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')';
        }

        $sql = "WITH issued AS (
                    SELECT sl.invoice_item_id AS ii_id,
                           SUM(CASE WHEN sd.doc_type = 'issue' THEN sl.qty ELSE -sl.qty END) AS qty
                      FROM stock_document_lines sl
                      JOIN stock_documents sd ON sd.id = sl.document_id AND sd.supplier_id = sl.supplier_id
                     WHERE sl.supplier_id = ? AND sd.status IN ('posted','reversed')
                       AND sl.invoice_item_id IS NOT NULL
                     GROUP BY sl.invoice_item_id
                )
                SELECT ii.stock_item_id, ii.warehouse_id, ii.id AS invoice_item_id,
                       i.id AS invoice_id, i.varsymbol AS invoice_number, i.issue_date, i.due_date,
                       c.company_name AS client_name,
                       GREATEST(ii.quantity - COALESCE(s.qty, 0), 0) AS qty
                  FROM invoice_items ii
                  JOIN invoices i ON i.id = ii.invoice_id AND i.supplier_id = ?
             LEFT JOIN clients c ON c.id = i.client_id AND c.supplier_id = i.supplier_id
             LEFT JOIN issued s ON s.ii_id = ii.id
                 WHERE ii.stock_item_id IS NOT NULL
                   AND i.invoice_type = 'invoice'
                   AND i.status NOT IN ('draft', 'cancelled')
                   AND i.cancelled_at IS NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM sales_order_invoice_links soil
                        WHERE soil.supplier_id = i.supplier_id AND soil.invoice_id = i.id
                   )" . $itemFilter;

        foreach ($itemIds as $id) {
            $params[] = (int) $id;
        }
        if ($warehouseId !== null) {
            $sql     .= ' AND (ii.warehouse_id = ? OR ii.warehouse_id IS NULL)';
            $params[] = $warehouseId;
        }
        $sql .= ' HAVING qty > 0 ORDER BY i.issue_date ASC, i.id ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn (array $r): array => [
            'stock_item_id'   => (int) $r['stock_item_id'],
            'warehouse_id'    => $r['warehouse_id'] !== null ? (int) $r['warehouse_id'] : null,
            'invoice_item_id' => (int) $r['invoice_item_id'],
            'invoice_id'      => (int) $r['invoice_id'],
            'invoice_number'  => $r['invoice_number'] !== null ? (string) $r['invoice_number'] : null,
            'client_name'     => $r['client_name'] !== null ? (string) $r['client_name'] : null,
            'issue_date'      => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'due_date'        => $r['due_date'] !== null ? (string) $r['due_date'] : null,
            'qty'             => (string) $r['qty'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Tvrdé rezervace prodejních objednávek. Order-linked faktury jsou z legacy
     * dotazů výše vyloučené, takže se stejný kus započte právě jednou.
     *
     * @param list<int> $itemIds
     * @return list<array{stock_item_id:int,warehouse_id:int,qty_reserved:string}>
     */
    public function salesOrderReservedForItems(int $supplierId, array $itemIds = [], ?int $warehouseId = null): array
    {
        $params = [$supplierId];
        $sql = "SELECT stock_item_id, warehouse_id,
                       SUM(GREATEST(qty_reserved - qty_consumed - qty_released, 0)) qty_reserved
                  FROM sales_order_reservations
                 WHERE supplier_id = ? AND status = 'active'";
        if ($itemIds !== []) {
            $sql .= ' AND stock_item_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')';
            array_push($params, ...array_map('intval', $itemIds));
        }
        if ($warehouseId !== null) {
            $sql .= ' AND warehouse_id = ?';
            $params[] = $warehouseId;
        }
        $sql .= ' GROUP BY stock_item_id, warehouse_id HAVING qty_reserved > 0';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(static fn (array $row): array => [
            'stock_item_id' => (int) $row['stock_item_id'],
            'warehouse_id' => (int) $row['warehouse_id'],
            'qty_reserved' => (string) $row['qty_reserved'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param list<int> $itemIds @return list<array<string,mixed>> */
    public function reservationSalesOrders(int $supplierId, array $itemIds = [], ?int $warehouseId = null): array
    {
        $params = [$supplierId];
        $sql = "SELECT r.stock_item_id, r.warehouse_id, o.id AS order_id, o.order_uuid,
                       o.order_number, o.reservation_expires_at, c.company_name AS client_name,
                       SUM(GREATEST(r.qty_reserved - r.qty_consumed - r.qty_released, 0)) qty
                  FROM sales_order_reservations r
                  JOIN sales_orders o ON o.id = r.order_id AND o.supplier_id = r.supplier_id
                  JOIN clients c ON c.id = o.client_id AND c.supplier_id = o.supplier_id
                 WHERE r.supplier_id = ? AND r.status = 'active'";
        if ($itemIds !== []) {
            $sql .= ' AND r.stock_item_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')';
            array_push($params, ...array_map('intval', $itemIds));
        }
        if ($warehouseId !== null) {
            $sql .= ' AND r.warehouse_id = ?';
            $params[] = $warehouseId;
        }
        $sql .= ' GROUP BY r.stock_item_id, r.warehouse_id, o.id HAVING qty > 0 ORDER BY o.created_at, o.id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(static fn (array $row): array => [
            'stock_item_id' => (int) $row['stock_item_id'],
            'warehouse_id' => (int) $row['warehouse_id'],
            'order_id' => (int) $row['order_id'],
            'order_uuid' => (string) $row['order_uuid'],
            'order_number' => (string) $row['order_number'],
            'client_name' => (string) $row['client_name'],
            'reservation_expires_at' => $row['reservation_expires_at'] !== null ? (string) $row['reservation_expires_at'] : null,
            'qty' => (string) $row['qty'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Fyzický stav per karta × sklad. LEFT JOIN ze `stock_items`, aby karta bez
     * jediného pohybu (a tedy bez řádku ve `stock_levels`) vrátila 0, ne nic —
     * to je celé rozhodnutí #12.
     *
     * @param list<int> $itemIds
     * @return list<array<string,mixed>>
     */
    public function onHandForItems(int $supplierId, array $itemIds = [], ?int $warehouseId = null): array
    {
        // Filtr na sklad patří do ON podmínky LEFT JOINu, ne do WHERE — ve WHERE
        // by z LEFT JOINu udělal INNER a karta bez pohybu na tom skladu by
        // z výsledku vypadla úplně (rozhodnutí #12).
        $sql  = 'SELECT si.id AS stock_item_id, sl.warehouse_id, w.code AS warehouse_code,
                       w.name AS warehouse_name, COALESCE(sl.qty, 0) AS on_hand
                  FROM stock_items si
             LEFT JOIN stock_levels sl ON sl.stock_item_id = si.id AND sl.supplier_id = si.supplier_id';
        $bind = [];
        if ($warehouseId !== null) {
            $sql   .= ' AND sl.warehouse_id = ?';
            $bind[] = $warehouseId;
        }
        $sql   .= ' LEFT JOIN warehouses w ON w.id = sl.warehouse_id AND w.supplier_id = si.supplier_id
                 WHERE si.supplier_id = ?';
        $bind[] = $supplierId;

        if ($itemIds !== []) {
            $sql .= ' AND si.id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')';
            foreach ($itemIds as $id) {
                $bind[] = (int) $id;
            }
        }
        $sql .= ' ORDER BY si.id ASC, sl.warehouse_id ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($bind);

        return array_map(static fn (array $r): array => [
            'stock_item_id'  => (int) $r['stock_item_id'],
            'warehouse_id'   => $r['warehouse_id'] !== null ? (int) $r['warehouse_id'] : null,
            'warehouse_code' => $r['warehouse_code'] !== null ? (string) $r['warehouse_code'] : null,
            'warehouse_name' => $r['warehouse_name'] !== null ? (string) $r['warehouse_name'] : null,
            'on_hand'        => (string) $r['on_hand'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Nabídky dodavatelů („u dodavatele") pro dané karty. Sloupce z migrace 1329
     * jsou volitelné jen v tom smyslu, že je smí být NULL — tabulka existuje vždy.
     *
     * @param list<int> $itemIds
     * @return list<array<string,mixed>>
     */
    public function vendorOffersForItems(int $supplierId, array $itemIds = []): array
    {
        $params = [$supplierId];
        $sql    = 'SELECT v.stock_item_id, v.client_id, v.vendor_sku, v.purchase_price, v.currency_code,
                          v.delivery_days, v.stock_qty, v.availability_state, v.stock_qty_updated_at,
                          v.min_order_qty, v.package_qty, v.is_preferred,
                          c.company_name AS vendor_name
                     FROM stock_item_vendors v
                LEFT JOIN clients c ON c.id = v.client_id AND c.supplier_id = v.supplier_id
                    WHERE v.supplier_id = ? AND v.is_active = 1';
        if ($itemIds !== []) {
            $sql .= ' AND v.stock_item_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')';
            foreach ($itemIds as $id) {
                $params[] = (int) $id;
            }
        }
        $sql .= ' ORDER BY v.stock_item_id ASC, v.is_preferred DESC, v.purchase_price ASC, v.id ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn (array $r): array => [
            'stock_item_id'        => (int) $r['stock_item_id'],
            'client_id'            => (int) $r['client_id'],
            'vendor_name'          => $r['vendor_name'] !== null ? (string) $r['vendor_name'] : null,
            'vendor_sku'           => $r['vendor_sku'] !== null ? (string) $r['vendor_sku'] : null,
            'purchase_price'       => $r['purchase_price'] !== null ? (string) $r['purchase_price'] : null,
            'currency_code'        => (string) $r['currency_code'],
            'delivery_days'        => $r['delivery_days'] !== null ? (int) $r['delivery_days'] : null,
            'stock_qty'            => $r['stock_qty'] !== null ? (string) $r['stock_qty'] : null,
            'availability_state'   => (string) $r['availability_state'],
            'stock_qty_updated_at' => $r['stock_qty_updated_at'] !== null ? (string) $r['stock_qty_updated_at'] : null,
            'min_order_qty'        => $r['min_order_qty'] !== null ? (string) $r['min_order_qty'] : null,
            'package_qty'          => $r['package_qty'] !== null ? (string) $r['package_qty'] : null,
            'is_preferred'         => (bool) $r['is_preferred'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Základní údaje karet (i těch bez jediného pohybu) — kostra odpovědi
     * `/quantities`, na kterou se ostatní agregáty jen nalepí.
     *
     * @param list<int> $itemIds
     * @return list<array<string,mixed>>
     */
    public function itemsBasics(int $supplierId, array $itemIds = [], bool $activeOnly = false, int $limit = 500): array
    {
        $params = [$supplierId];
        $sql    = 'SELECT id, sku, name, unit, min_qty, is_active, is_stocked
                     FROM stock_items WHERE supplier_id = ?';
        if ($itemIds !== []) {
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')';
            foreach ($itemIds as $id) {
                $params[] = (int) $id;
            }
        }
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY id ASC LIMIT ' . max(1, min(2000, $limit));

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn (array $r): array => [
            'stock_item_id' => (int) $r['id'],
            'sku'           => (string) $r['sku'],
            'name'          => (string) $r['name'],
            'unit'          => (string) $r['unit'],
            'min_qty'       => $r['min_qty'] !== null ? (string) $r['min_qty'] : null,
            'is_active'     => (bool) $r['is_active'],
            'is_stocked'    => (bool) $r['is_stocked'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
}
