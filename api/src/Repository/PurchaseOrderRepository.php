<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro purchase_orders + purchase_order_lines + purchase_order_invoice_links
 * (Epic SKLAD „na cestě", fáze 1). Surové PDO, tenant predikát supplier_id na
 * KAŽDÉM dotazu (architektonický vzor, TenantPredicateTest).
 *
 * `order_number` se přiděluje až při send() z řady OBJ — draft ho má NULL, takže
 * UNIQUE (supplier_id, order_number) nekoliduje ani u desítek rozpracovaných
 * objednávek (NULL se v MySQL v unikátním indexu neporovnává).
 *
 * „Přijaté množství" tady NENÍ sloupec: autoritou je stock_document_lines
 * (viz {@see receivedByOrder()}), protože příjemka je po zaúčtování neměnná,
 * kdežto řádky faktury se při editaci přepisují celé.
 */
final class PurchaseOrderRepository
{
    private const COLUMNS =
        'id, supplier_id, vendor_id, order_number, vendor_reference, order_date, expected_date,
         warehouse_id, currency_id, exchange_rate, state, total_without_vat, total_with_vat,
         note, internal_note, sent_at, confirmed_at, confirmed_by, closed_at, closed_by,
         close_reason, cancelled_at, cancelled_by, cancel_reason, created_by, created_at, updated_at';

    private const LINE_COLUMNS =
        'id, order_id, supplier_id, line_no, stock_item_id, warehouse_id, vendor_sku, description,
         unit, qty_ordered, qty_confirmed, qty_cancelled, unit_price, vat_rate_id, expected_date,
         has_over_delivery, note';

    public function __construct(private readonly Connection $db) {}

    public function orderIdsForLines(int $supplierId, array $lineIds): array
    {
        $lineIds = self::positiveIds($lineIds);
        if ($lineIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare('SELECT DISTINCT order_id FROM purchase_order_lines WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($lineIds), '?')) . ') ORDER BY order_id');
        $stmt->execute([$supplierId, ...$lineIds]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM purchase_orders WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::cast($row);
    }

    /**
     * Hlavička pod zámkem — volat VÝHRADNĚ v otevřené transakci (send/confirm/
     * close/cancel/recompute). Chrání proti dvojímu přidělení čísla řady.
     *
     * @return array<string,mixed>|null
     */
    public function lockForUpdate(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM purchase_orders
              WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::cast($row);
    }

    /** @return array<string,mixed>|null */
    public function findWithLines(int $supplierId, int $id): ?array
    {
        $order = $this->find($supplierId, $id);
        if ($order === null) {
            return null;
        }
        $order['lines'] = $this->lines($supplierId, $id);

        return $order;
    }

    /**
     * Řádky obohacené o kartu (sku/name) — pořadí line_no, id.
     *
     * @return list<array<string,mixed>>
     */
    public function lines(int $supplierId, int $orderId): array
    {
        $cols = implode(', ', array_map(
            static fn (string $c): string => 'l.' . trim($c),
            explode(',', self::LINE_COLUMNS),
        ));
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $cols . ', si.sku, si.name AS item_name, w.code AS warehouse_code
               FROM purchase_order_lines l
          LEFT JOIN stock_items si ON si.id = l.stock_item_id AND si.supplier_id = l.supplier_id
          LEFT JOIN warehouses w ON w.id = l.warehouse_id AND w.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.order_id = ?
              ORDER BY l.line_no ASC, l.id ASC'
        );
        $stmt->execute([$supplierId, $orderId]);

        return array_map([self::class, 'castLine'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Seznam s filtry (state, vendor_id, warehouse_id, from, to, expected_to, q).
     *
     * @param array<string,mixed> $filters
     * @return array{0:list<array<string,mixed>>, 1:int}
     */
    public function listPaged(int $supplierId, array $filters, int $limit, int $offset): array
    {
        $where  = ['o.supplier_id = ?'];
        $params = [$supplierId];

        if (!empty($filters['state'])) {
            $states = is_array($filters['state']) ? $filters['state'] : [(string) $filters['state']];
            $states = array_values(array_filter(array_map('strval', $states)));
            if ($states !== []) {
                $where[] = 'o.state IN (' . implode(',', array_fill(0, count($states), '?')) . ')';
                foreach ($states as $s) {
                    $params[] = $s;
                }
            }
        }
        if (!empty($filters['vendor_id'])) {
            $where[]  = 'o.vendor_id = ?';
            $params[] = (int) $filters['vendor_id'];
        }
        if (!empty($filters['warehouse_id'])) {
            $where[]  = 'o.warehouse_id = ?';
            $params[] = (int) $filters['warehouse_id'];
        }
        if (!empty($filters['from'])) {
            $where[]  = 'o.order_date >= ?';
            $params[] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[]  = 'o.order_date <= ?';
            $params[] = (string) $filters['to'];
        }
        if (!empty($filters['expected_to'])) {
            $where[]  = 'o.expected_date IS NOT NULL AND o.expected_date <= ?';
            $params[] = (string) $filters['expected_to'];
        }
        if (!empty($filters['stock_item_id'])) {
            $where[]  = 'EXISTS (SELECT 1 FROM purchase_order_lines pl
                                  WHERE pl.order_id = o.id AND pl.supplier_id = o.supplier_id
                                    AND pl.stock_item_id = ?)';
            $params[] = (int) $filters['stock_item_id'];
        }
        if (!empty($filters['q'])) {
            $q        = addcslashes((string) $filters['q'], '%_\\');
            $where[]  = '(o.order_number LIKE ? OR o.vendor_reference LIKE ? OR c.company_name LIKE ?)';
            $params[] = $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $cols = implode(', ', array_map(
            static fn (string $c): string => 'o.' . trim($c),
            explode(',', self::COLUMNS),
        ));
        $sql = 'SELECT ' . $cols . ', COUNT(*) OVER() AS total_rows,
                       c.company_name AS vendor_name,
                       w.code AS warehouse_code, w.name AS warehouse_name,
                       cur.code AS currency_code
                  FROM purchase_orders o
             LEFT JOIN clients c ON c.id = o.vendor_id AND c.supplier_id = o.supplier_id
             LEFT JOIN warehouses w ON w.id = o.warehouse_id AND w.supplier_id = o.supplier_id
             LEFT JOIN currencies cur ON cur.id = o.currency_id AND cur.supplier_id = o.supplier_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY o.order_date DESC, o.id DESC
                 LIMIT ? OFFSET ?';

        $stmt = $this->db->pdo()->prepare($sql);
        $idx  = 1;
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        $stmt->bindValue($idx++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows  = [];
        $total = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $total = (int) $row['total_rows'];
            $extra = [
                'vendor_name'    => $row['vendor_name'] !== null ? (string) $row['vendor_name'] : null,
                'warehouse_code' => $row['warehouse_code'] !== null ? (string) $row['warehouse_code'] : null,
                'warehouse_name' => $row['warehouse_name'] !== null ? (string) $row['warehouse_name'] : null,
                'currency_code'  => $row['currency_code'] !== null ? (string) $row['currency_code'] : null,
            ];
            unset($row['total_rows'], $row['vendor_name'], $row['warehouse_code'],
                  $row['warehouse_name'], $row['currency_code']);
            $rows[] = array_merge(self::cast($row), $extra);
        }

        return [$rows, $total];
    }

    /**
     * Souhrn objednáno / potvrzeno / stornováno per objednávka — jen řádky
     * se skladovou kartou (doprava a služby se do plnění nepočítají).
     *
     * @return array<int,array{ordered:string, effective:string, cancelled:string}>
     */
    public function quantitySummary(int $supplierId, array $orderIds): array
    {
        $ids = self::positiveIds($orderIds);
        if ($ids === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = $this->db->pdo()->prepare(
            "SELECT order_id,
                    SUM(qty_ordered) AS ordered,
                    SUM(COALESCE(qty_confirmed, qty_ordered) - qty_cancelled) AS effective,
                    SUM(qty_cancelled) AS cancelled
               FROM purchase_order_lines
              WHERE supplier_id = ? AND stock_item_id IS NOT NULL AND order_id IN ($place)
              GROUP BY order_id"
        );
        $stmt->execute([$supplierId, ...$ids]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['order_id']] = [
                'ordered'   => (string) $r['ordered'],
                'effective' => (string) $r['effective'],
                'cancelled' => (string) $r['cancelled'],
            ];
        }

        return $out;
    }

    /**
     * Přijaté množství per řádek objednávky — ZDROJ PRAVDY o plnění.
     *
     * Čte se z příjemek, ne z řádků faktury: `purchase_invoice_items` je
     * replace-all a vazba by při editaci faktury osiřela, kdežto zaúčtovaná
     * příjemka se už nemění. Storno příjemky vytvoří protidoklad `issue`
     * se stejným `purchase_order_line_id`, který se tady ODEČTE — díky tomu
     * se zboží vrátí „na cestu" bez jakéhokoli dalšího zásahu.
     *
     * @param list<int> $orderIds
     * @return array<int,string> purchase_order_line_id => čisté přijaté množství
     */
    public function receivedByOrder(int $supplierId, array $orderIds): array
    {
        $ids = self::positiveIds($orderIds);
        if ($ids === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = $this->db->pdo()->prepare(
            "SELECT l.purchase_order_line_id AS pol_id,
                    SUM(CASE WHEN d.doc_type = 'receipt' THEN l.qty ELSE -l.qty END) AS qty
               FROM stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
               JOIN purchase_order_lines pol ON pol.id = l.purchase_order_line_id
                    AND pol.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND d.status IN ('posted','reversed')
                AND l.purchase_order_line_id IS NOT NULL
                AND pol.order_id IN ($place)
              GROUP BY l.purchase_order_line_id"
        );
        $stmt->execute([$supplierId, ...$ids]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['pol_id']] = (string) $r['qty'];
        }

        return $out;
    }

    /**
     * Přijaté množství agregované per OBJEDNÁVKA — pro seznam, kde stačí součet
     * a nesmí se kvůli němu dělat N+1 dotaz na řádky. Stejná `CASE` logika jako
     * {@see receivedByOrder()}: protidoklad storna se odečítá.
     *
     * @param list<int> $orderIds
     * @return array<int,string> order_id => čisté přijaté množství
     */
    public function receivedTotalsByOrder(int $supplierId, array $orderIds): array
    {
        $ids = self::positiveIds($orderIds);
        if ($ids === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = $this->db->pdo()->prepare(
            "SELECT pol.order_id,
                    SUM(CASE WHEN d.doc_type = 'receipt' THEN l.qty ELSE -l.qty END) AS qty
               FROM stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
               JOIN purchase_order_lines pol ON pol.id = l.purchase_order_line_id
                    AND pol.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND d.status IN ('posted','reversed')
                AND l.purchase_order_line_id IS NOT NULL
                AND pol.stock_item_id IS NOT NULL
                AND pol.order_id IN ($place)
              GROUP BY pol.order_id"
        );
        $stmt->execute([$supplierId, ...$ids]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['order_id']] = (string) $r['qty'];
        }

        return $out;
    }

    /**
     * Insert hlavičky (draft; order_number NULL — přiděluje až send()).
     *
     * @param array<string,mixed> $data
     */
    public function insertHeader(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_orders
                (supplier_id, vendor_id, order_number, vendor_reference, order_date, expected_date,
                 warehouse_id, currency_id, exchange_rate, state, note, internal_note, created_by)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (int) $data['vendor_id'],
            $data['vendor_reference'] ?? null,
            (string) $data['order_date'],
            $data['expected_date'] ?? null,
            (int) $data['warehouse_id'],
            (int) $data['currency_id'],
            $data['exchange_rate'] ?? null,
            (string) ($data['state'] ?? 'draft'),
            $data['note'] ?? null,
            $data['internal_note'] ?? null,
            isset($data['created_by']) && (int) $data['created_by'] > 0 ? (int) $data['created_by'] : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Přepis editovatelné části hlavičky. Predikát na stavech, ve kterých se
     * hlavička ještě smí měnit, drží service (tady jen SQL).
     *
     * @param array<string,mixed> $data
     */
    public function updateHeader(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE purchase_orders SET
                vendor_id = ?, vendor_reference = ?, order_date = ?, expected_date = ?,
                warehouse_id = ?, currency_id = ?, exchange_rate = ?, note = ?, internal_note = ?
              WHERE id = ? AND supplier_id = ? AND state = \'draft\''
        );
        $stmt->execute([
            (int) $data['vendor_id'],
            $data['vendor_reference'] ?? null,
            (string) $data['order_date'],
            $data['expected_date'] ?? null,
            (int) $data['warehouse_id'],
            (int) $data['currency_id'],
            $data['exchange_rate'] ?? null,
            $data['note'] ?? null,
            $data['internal_note'] ?? null,
            $id,
            $supplierId,
        ]);

        return $stmt->rowCount() > 0 || ($this->find($supplierId, $id)['state'] ?? null) === 'draft';
    }

    /** @param list<array<string,mixed>> $lines */
    public function replaceLines(int $supplierId, int $orderId, array $lines): void
    {
        $existing = [];
        foreach ($this->lines($supplierId, $orderId) as $line) {
            $existing[(int) $line['id']] = $line;
        }
        $used = [];
        $resolved = [];
        foreach ($lines as $line) {
            $id = (int) ($line['id'] ?? 0);
            if ($id === 0) {
                foreach ($existing as $candidateId => $candidate) {
                    if (!isset($used[$candidateId]) && (int) $candidate['line_no'] === (int) $line['line_no']
                        && $candidate['stock_item_id'] === $line['stock_item_id']) {
                        $id = $candidateId;
                        break;
                    }
                }
            }
            if ($id > 0) {
                if (!isset($existing[$id]) || isset($used[$id])) {
                    throw new \MyInvoice\Service\Stock\StockException('invalid_order', 'Neplatná nebo duplicitní identita řádku objednávky.', 422);
                }
                if ($existing[$id]['stock_item_id'] !== $line['stock_item_id'] && $this->lineHasReferences($supplierId, $id)) {
                    throw new \MyInvoice\Service\Stock\StockException('order_line_linked', 'Navázaný řádek nemůže změnit skladovou kartu.', 409);
                }
                $used[$id] = true;
            }
            $line['id'] = $id;
            $resolved[] = $line;
        }
        foreach ($existing as $id => $line) {
            if (isset($used[$id])) {
                continue;
            }
            if ($this->lineHasReferences($supplierId, $id)) {
                throw new \MyInvoice\Service\Stock\StockException('order_line_linked', 'Navázaný řádek objednávky nelze odstranit.', 409);
            }
            $this->db->pdo()->prepare('DELETE FROM purchase_order_lines WHERE supplier_id = ? AND order_id = ? AND id = ?')
                ->execute([$supplierId, $orderId, $id]);
        }
        if ($existing !== []) {
            $offset = max(count($lines), max(array_column($existing, 'line_no'))) + 1;
            $this->db->pdo()->prepare('UPDATE purchase_order_lines SET line_no = line_no + ? WHERE supplier_id = ? AND order_id = ? ORDER BY line_no DESC')
                ->execute([$offset, $supplierId, $orderId]);
        }
        $fields = ['line_no', 'stock_item_id', 'warehouse_id', 'vendor_sku', 'description', 'unit',
            'qty_ordered', 'unit_price', 'vat_rate_id', 'expected_date', 'note'];
        foreach ($resolved as $line) {
            $id = (int) $line['id'];
            if ($id > 0) {
                $values = array_map(static fn (string $field): mixed => $line[$field] ?? null, $fields);
                $stmt = $this->db->pdo()->prepare('UPDATE purchase_order_lines SET '
                    . implode(', ', array_map(static fn (string $field): string => $field . ' = ?', $fields))
                    . ' WHERE supplier_id = ? AND order_id = ? AND id = ?');
                $stmt->execute([...$values, $supplierId, $orderId, $id]);
            } else {
                $line['order_id'] = $orderId;
                $this->insertLine($supplierId, $line);
            }
        }
    }

    private function lineHasReferences(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT EXISTS(SELECT 1 FROM stock_document_lines WHERE supplier_id = ? AND purchase_order_line_id = ?)
            OR EXISTS(SELECT 1 FROM purchase_invoice_items i JOIN purchase_invoices p ON p.id = i.purchase_invoice_id WHERE p.supplier_id = ? AND i.purchase_order_line_id = ?)');
        $stmt->execute([$supplierId, $id, $supplierId, $id]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $data */
    public function insertLine(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_order_lines
                (order_id, supplier_id, line_no, stock_item_id, warehouse_id, vendor_sku,
                 description, unit, qty_ordered, qty_confirmed, qty_cancelled, unit_price,
                 vat_rate_id, expected_date, has_over_delivery, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $data['order_id'],
            $supplierId,
            (int) ($data['line_no'] ?? 0),
            isset($data['stock_item_id']) && (int) $data['stock_item_id'] > 0 ? (int) $data['stock_item_id'] : null,
            isset($data['warehouse_id']) && (int) $data['warehouse_id'] > 0 ? (int) $data['warehouse_id'] : null,
            $data['vendor_sku'] ?? null,
            (string) $data['description'],
            (string) ($data['unit'] ?? 'ks'),
            (string) $data['qty_ordered'],
            $data['qty_confirmed'] ?? null,
            (string) ($data['qty_cancelled'] ?? '0'),
            (string) ($data['unit_price'] ?? '0'),
            isset($data['vat_rate_id']) && (int) $data['vat_rate_id'] > 0 ? (int) $data['vat_rate_id'] : null,
            $data['expected_date'] ?? null,
            !empty($data['has_over_delivery']) ? 1 : 0,
            $data['note'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function updateTotals(int $supplierId, int $id, string $withoutVat, string $withVat): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_orders SET total_without_vat = ?, total_with_vat = ?
              WHERE id = ? AND supplier_id = ?'
        )->execute([$withoutVat, $withVat, $id, $supplierId]);
    }

    /**
     * Přechod stavu s predikátem na očekávaný výchozí stav — souběžný přechod
     * tak skončí rowCount()=0 a volající vyhodí 409 místo tichého přepsání.
     *
     * @param list<string>        $fromStates
     * @param array<string,mixed> $set        další sloupce (sent_at, closed_by…)
     */
    public function transition(int $supplierId, int $id, array $fromStates, string $toState, array $set = []): bool
    {
        $assign = ['state = ?'];
        $params = [$toState];
        foreach ($set as $column => $value) {
            // Sloupce pocházejí výhradně z volajícího service, nikdy z requestu.
            $assign[] = '`' . $column . '` = ' . ($value === '__NOW__' ? 'NOW()' : '?');
            if ($value !== '__NOW__') {
                $params[] = $value;
            }
        }
        $place = implode(',', array_fill(0, count($fromStates), '?'));
        $stmt  = $this->db->pdo()->prepare(
            'UPDATE purchase_orders SET ' . implode(', ', $assign) . "
              WHERE id = ? AND supplier_id = ? AND state IN ($place)"
        );
        $stmt->execute([...$params, $id, $supplierId, ...$fromStates]);

        return $stmt->rowCount() > 0;
    }

    public function assignNumber(int $supplierId, int $id, string $orderNumber): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE purchase_orders SET order_number = ?
              WHERE id = ? AND supplier_id = ? AND order_number IS NULL'
        );
        $stmt->execute([$orderNumber, $id, $supplierId]);

        return $stmt->rowCount() > 0;
    }

    public function setLineConfirmed(int $supplierId, int $lineId, ?string $qtyConfirmed, ?string $expectedDate): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_order_lines SET qty_confirmed = ?, expected_date = COALESCE(?, expected_date)
              WHERE id = ? AND supplier_id = ?'
        )->execute([$qtyConfirmed, $expectedDate, $lineId, $supplierId]);
    }

    public function setLineCancelled(int $supplierId, int $lineId, string $qtyCancelled): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_order_lines SET qty_cancelled = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$qtyCancelled, $lineId, $supplierId]);
    }

    public function markLineOverDelivery(int $supplierId, int $lineId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_order_lines SET has_over_delivery = 1 WHERE id = ? AND supplier_id = ?'
        )->execute([$lineId, $supplierId]);
    }

    public function delete(int $supplierId, int $id): bool
    {
        // ON DELETE CASCADE smaže i řádky a vazby na faktury.
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM purchase_orders WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);

        return $stmt->rowCount() > 0;
    }

    // ── vazba na přijaté faktury ─────────────────────────────────────────────
    //
    // ⚠ NEPOUŽITO — tabulka `purchase_order_invoice_links` (migrace 1330) je
    // zatím jen podloží pro párování objednávka ↔ přijatá faktura (§ 5.2 plánu).
    // `invoiceLinks()` se sice volá z `PurchaseOrderService::withFulfilment()`,
    // ale `linkInvoice()`/`unlinkInvoice()` NEMÁ ŽÁDNÉHO VOLAJÍCÍHO, takže
    // tabulka je v praxi vždycky prázdná a `invoice_links` v detailu objednávky
    // je vždy `[]`. Chybí k tomu celá horní polovina funkce: čtyři endpointy
    // (`GET /purchase-orders/{id}/invoice-match`,
    // `POST|DELETE /purchase-orders/{id}/invoice-links` a protisměrné dvojče
    // pod `/purchase-invoices/{id}`), služba `PurchaseOrderInvoiceMatcher`
    // a obrazovka `OrderInvoiceMatchModal.vue`.
    //
    // Se stejným osudem čeká `supplier.stock_order_price_tolerance_pct`
    // (migrace 1331): práh cenové odchylky faktura vs. objednávka podle
    // rozhodnutí #8 („jen varuje, nikdy neblokuje"). Nikdo ho nečte — varování
    // nemá kde vzniknout, dokud se faktura nedá k objednávce připnout, a nemá
    // kde se zobrazit, dokud neexistuje ta obrazovka. Zapojit půlku (tiché
    // varování v API, které nikdo neukáže) by bylo horší než nezapojit nic.
    //
    // Až se § 5.2 dělat bude, tohle jsou hotové stavební kameny — NEMAZAT.

    /** @return list<array<string,mixed>> */
    public function invoiceLinks(int $supplierId, int $orderId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT k.id, k.order_id, k.purchase_invoice_id, k.linked_at, k.match_source,
                    pi.varsymbol, pi.vendor_invoice_number, pi.total_with_vat
               FROM purchase_order_invoice_links k
               JOIN purchase_invoices pi ON pi.id = k.purchase_invoice_id AND pi.supplier_id = k.supplier_id
              WHERE k.supplier_id = ? AND k.order_id = ?
              ORDER BY k.linked_at ASC, k.id ASC'
        );
        $stmt->execute([$supplierId, $orderId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function linkInvoice(int $supplierId, int $orderId, int $purchaseInvoiceId, ?int $userId, string $source = 'manual'): void
    {
        $this->db->pdo()->prepare(
            'INSERT IGNORE INTO purchase_order_invoice_links
                (supplier_id, order_id, purchase_invoice_id, linked_by, match_source)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$supplierId, $orderId, $purchaseInvoiceId, $userId, $source]);
    }

    public function unlinkInvoice(int $supplierId, int $orderId, int $purchaseInvoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM purchase_order_invoice_links
              WHERE supplier_id = ? AND order_id = ? AND purchase_invoice_id = ?'
        );
        $stmt->execute([$supplierId, $orderId, $purchaseInvoiceId]);

        return $stmt->rowCount() > 0;
    }

    /** Příjemky vystavené k objednávce (i draft — uživatel je chce vidět). */
    public function receiptDocuments(int $supplierId, int $orderId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT d.id, d.doc_type, d.doc_number, d.doc_date, d.status, d.description
               FROM stock_documents d
              WHERE d.supplier_id = ? AND d.purchase_order_id = ?
              ORDER BY d.doc_date DESC, d.id DESC'
        );
        $stmt->execute([$supplierId, $orderId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /**
     * @param array<int|string> $ids
     * @return list<int>
     */
    private static function positiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $v): bool => $v > 0,
        )));
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id']           = (int) $r['id'];
        $r['supplier_id']  = (int) $r['supplier_id'];
        $r['vendor_id']    = (int) $r['vendor_id'];
        $r['warehouse_id'] = (int) $r['warehouse_id'];
        $r['currency_id']  = (int) $r['currency_id'];
        foreach (['confirmed_by', 'closed_by', 'cancelled_by', 'created_by'] as $col) {
            $r[$col] = $r[$col] !== null ? (int) $r[$col] : null;
        }

        return $r;
    }

    /** @return array<string,mixed> */
    private static function castLine(array $r): array
    {
        $r['id']                = (int) $r['id'];
        $r['order_id']          = (int) $r['order_id'];
        $r['supplier_id']       = (int) $r['supplier_id'];
        $r['line_no']           = (int) $r['line_no'];
        $r['stock_item_id']     = $r['stock_item_id'] !== null ? (int) $r['stock_item_id'] : null;
        $r['warehouse_id']      = $r['warehouse_id'] !== null ? (int) $r['warehouse_id'] : null;
        $r['vat_rate_id']       = $r['vat_rate_id'] !== null ? (int) $r['vat_rate_id'] : null;
        $r['has_over_delivery'] = (bool) $r['has_over_delivery'];

        return $r;
    }
}
