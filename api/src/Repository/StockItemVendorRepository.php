<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use PDO;

/**
 * Repository pro stock_item_vendors — dodavatelé zboží M:N na clients (is_vendor)
 * (Epic ESHOP). Per tenant (supplier_id). purchase_price je money → string.
 * Skladovost/lhůta u dodavatele slouží i neskladovému zboží (is_stocked=0, E5).
 *
 * Táž řádka je zároveň NABÍDKA DODAVATELE („u dodavatele", Epic SKLAD fáze 3,
 * migrace 1329): dostupnost, minimální objednávka, balení, platnost ceny, zdroj
 * dat. Záměrně jedna tabulka — nabídka není jiná entita než vazba
 * produkt↔dodavatel, dvě tabulky by znamenaly dva zdroje pravdy o nákupní ceně.
 */
final class StockItemVendorRepository
{
    private const COLUMNS =
        'id, supplier_id, stock_item_id, client_id, vendor_sku, purchase_price,
         currency_code, delivery_days, stock_qty, is_preferred, note, updated_at';

    /** Sloupce nabídky (1329) — samostatně, ať je vidět, co přibylo nad 1028. */
    private const OFFER_COLUMNS =
        'v.availability_state, v.stock_qty_updated_at, v.min_order_qty, v.package_qty,
         v.price_valid_to, v.data_source, v.is_active';

    /** Pole nabídky, která smí přijít v partial updatu (PATCH) i importu. */
    public const OFFER_FIELDS = [
        'vendor_sku', 'purchase_price', 'currency_code', 'delivery_days', 'stock_qty',
        'availability_state', 'stock_qty_updated_at', 'min_order_qty', 'package_qty',
        'price_valid_to', 'data_source', 'is_active', 'is_preferred', 'note',
    ];

    public function __construct(private readonly Connection $db) {}

    /** Dodavatelé karty vč. názvu klienta. @return list<array<string,mixed>> */
    public function listForItem(int $supplierId, int $stockItemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT v.id, v.supplier_id, v.stock_item_id, v.client_id, v.vendor_sku,
                    v.purchase_price, v.currency_code, v.delivery_days, v.stock_qty,
                    v.is_preferred, v.note, v.updated_at, ' . self::OFFER_COLUMNS . ',
                    c.company_name AS client_name
               FROM stock_item_vendors v
               JOIN clients c ON c.id = v.client_id AND c.supplier_id = v.supplier_id
              WHERE v.supplier_id = ? AND v.stock_item_id = ?
              ORDER BY v.is_preferred DESC, c.company_name ASC'
        );
        $stmt->execute([$supplierId, $stockItemId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Preferovaný dodavatel (pro pricing_base=manual): purchase_price + měna.
     * Preferuje is_preferred=1, jinak nejlevnější s cenou. Null bez ceny.
     * @return array{purchase_price:string, currency_code:string}|null
     */
    public function preferredPurchase(int $supplierId, int $stockItemId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT purchase_price, currency_code FROM stock_item_vendors
              WHERE supplier_id = ? AND stock_item_id = ? AND purchase_price IS NOT NULL
              ORDER BY is_preferred DESC, purchase_price ASC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $stockItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'purchase_price' => (string) UnitPricePrecision::toDecimalString($row['purchase_price']),
            'currency_code'  => (string) $row['currency_code'],
        ];
    }

    public function deleteForItem(int $supplierId, int $stockItemId): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM stock_item_vendors WHERE supplier_id = ? AND stock_item_id = ?'
        )->execute([$supplierId, $stockItemId]);
    }

    /**
     * @param array{client_id:int, vendor_sku?:?string, purchase_price?:?string,
     *              currency_code?:string, delivery_days?:?int, stock_qty?:?string,
     *              is_preferred?:bool, note?:?string, availability_state?:string,
     *              stock_qty_updated_at?:?string, min_order_qty?:?string,
     *              package_qty?:?string, price_valid_to?:?string,
     *              data_source?:string, is_active?:bool} $data
     */
    public function add(int $supplierId, int $stockItemId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_item_vendors
                (supplier_id, stock_item_id, client_id, vendor_sku, purchase_price,
                 currency_code, delivery_days, stock_qty, is_preferred, note,
                 availability_state, stock_qty_updated_at, min_order_qty, package_qty,
                 price_valid_to, data_source, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $stockItemId,
            (int) $data['client_id'],
            $data['vendor_sku'] ?? null,
            isset($data['purchase_price']) && $data['purchase_price'] !== null ? (string) $data['purchase_price'] : null,
            (string) ($data['currency_code'] ?? 'CZK'),
            isset($data['delivery_days']) && $data['delivery_days'] !== null ? (int) $data['delivery_days'] : null,
            isset($data['stock_qty']) && $data['stock_qty'] !== null ? (string) $data['stock_qty'] : null,
            (int) ($data['is_preferred'] ?? false),
            $data['note'] ?? null,
            (string) ($data['availability_state'] ?? 'unknown'),
            $data['stock_qty_updated_at'] ?? null,
            isset($data['min_order_qty']) && $data['min_order_qty'] !== null ? (string) $data['min_order_qty'] : null,
            isset($data['package_qty']) && $data['package_qty'] !== null ? (string) $data['package_qty'] : null,
            $data['price_valid_to'] ?? null,
            (string) ($data['data_source'] ?? 'manual'),
            (int) ($data['is_active'] ?? true),
        ]);
        return (int) $pdo->lastInsertId();
    }

    // ── Nabídky dodavatelů („u dodavatele", fáze 3) ───────────────────────────

    /**
     * Stránkovaný seznam nabídek napříč kartami.
     *
     * `on_hand` je poddotaz s COALESCE → 0, ne JOIN: karta bez jediného pohybu
     * NEMÁ řádek ve `stock_levels` (rozhodnutí #12) a INNER JOIN by ji z výpisu
     * vyhodil — přesně tu kartu, kvůli které se nabídky evidují.
     *
     * @param array{stock_item_id?:?int, client_id?:?int, q?:?string,
     *              availability_state?:?string, active?:?bool, preferred?:?bool,
     *              limit?:int, offset?:int} $filters
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function listOffers(int $supplierId, array $filters = []): array
    {
        [$where, $params] = $this->offerFilters($supplierId, $filters);
        $limit  = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $countStmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM stock_item_vendors v
               JOIN stock_items i ON i.id = v.stock_item_id AND i.supplier_id = v.supplier_id
               JOIN clients c ON c.id = v.client_id AND c.supplier_id = v.supplier_id
              WHERE ' . $where
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            'SELECT v.id, v.supplier_id, v.stock_item_id, v.client_id, v.vendor_sku,
                    v.purchase_price, v.currency_code, v.delivery_days, v.stock_qty,
                    v.is_preferred, v.note, v.updated_at, ' . self::OFFER_COLUMNS . ',
                    c.company_name AS client_name,
                    i.sku AS sku, i.name AS item_name, i.unit AS unit,
                    COALESCE((SELECT SUM(l.qty) FROM stock_levels l
                               WHERE l.supplier_id = v.supplier_id
                                 AND l.stock_item_id = v.stock_item_id), 0) AS on_hand
               FROM stock_item_vendors v
               JOIN stock_items i ON i.id = v.stock_item_id AND i.supplier_id = v.supplier_id
               JOIN clients c ON c.id = v.client_id AND c.supplier_id = v.supplier_id
              WHERE ' . $where . '
              ORDER BY i.sku ASC, v.is_preferred DESC, c.company_name ASC, v.id ASC
              LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        return [
            'items' => array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []),
            'total' => $total,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string, 1:list<mixed>}
     */
    private function offerFilters(int $supplierId, array $filters): array
    {
        $where = ['v.supplier_id = ?'];
        $params = [$supplierId];

        if (!empty($filters['stock_item_id'])) {
            $where[] = 'v.stock_item_id = ?';
            $params[] = (int) $filters['stock_item_id'];
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'v.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (isset($filters['availability_state']) && $filters['availability_state'] !== null && $filters['availability_state'] !== '') {
            $where[] = 'v.availability_state = ?';
            $params[] = (string) $filters['availability_state'];
        }
        if (isset($filters['active']) && $filters['active'] !== null) {
            $where[] = 'v.is_active = ?';
            $params[] = $filters['active'] ? 1 : 0;
        }
        if (!empty($filters['preferred'])) {
            $where[] = 'v.is_preferred = 1';
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(i.sku LIKE ? OR i.name LIKE ? OR v.vendor_sku LIKE ? OR c.company_name LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            array_push($params, $like, $like, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }

    /** Jedna nabídka vč. karty a dodavatele. @return array<string,mixed>|null */
    public function findOffer(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT v.id, v.supplier_id, v.stock_item_id, v.client_id, v.vendor_sku,
                    v.purchase_price, v.currency_code, v.delivery_days, v.stock_qty,
                    v.is_preferred, v.note, v.updated_at, ' . self::OFFER_COLUMNS . ',
                    c.company_name AS client_name,
                    i.sku AS sku, i.name AS item_name, i.unit AS unit,
                    COALESCE((SELECT SUM(l.qty) FROM stock_levels l
                               WHERE l.supplier_id = v.supplier_id
                                 AND l.stock_item_id = v.stock_item_id), 0) AS on_hand
               FROM stock_item_vendors v
               JOIN stock_items i ON i.id = v.stock_item_id AND i.supplier_id = v.supplier_id
               JOIN clients c ON c.id = v.client_id AND c.supplier_id = v.supplier_id
              WHERE v.supplier_id = ? AND v.id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** Existující nabídka pro dvojici karta+dodavatel (UNIQUE uq_siv_item_client). */
    public function findByItemAndClient(int $supplierId, int $stockItemId, int $clientId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM stock_item_vendors
              WHERE supplier_id = ? AND stock_item_id = ? AND client_id = ?'
        );
        $stmt->execute([$supplierId, $stockItemId, $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->findOffer($supplierId, (int) $row['id']);
    }

    /**
     * Partial update — zapíše JEN předané klíče (MCP-friendly PATCH). Klíč, který
     * v poli není, se nemění; `null` u nullable sloupce hodnotu maže.
     *
     * @param array<string,mixed> $changes podmnožina self::OFFER_FIELDS
     */
    public function updateOffer(int $supplierId, int $id, array $changes): void
    {
        $set = [];
        $params = [];
        foreach (self::OFFER_FIELDS as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }
            $set[] = $field . ' = ?';
            $value = $changes[$field];
            $params[] = match ($field) {
                'is_preferred', 'is_active' => (int) (bool) $value,
                'delivery_days'             => $value === null ? null : (int) $value,
                default                     => $value === null ? null : (string) $value,
            };
        }
        if ($set === []) {
            return;
        }
        $params[] = $supplierId;
        $params[] = $id;
        $this->db->pdo()->prepare(
            'UPDATE stock_item_vendors SET ' . implode(', ', $set)
            . ' WHERE supplier_id = ? AND id = ?'
        )->execute($params);
    }

    public function deleteOffer(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM stock_item_vendors WHERE supplier_id = ? AND id = ?'
        )->execute([$supplierId, $id]);
    }

    /**
     * Odznačí hlavního dodavatele u ostatních nabídek karty. Volá se před
     * nastavením `is_preferred=1`, aby zůstal nejvýš jeden (ProductVendorAction
     * to řeší validací celé dávky, tady jde o jednu řádku, takže se přepíná).
     */
    public function clearPreferredForItem(int $supplierId, int $stockItemId, int $exceptId = 0): void
    {
        $this->db->pdo()->prepare(
            'UPDATE stock_item_vendors SET is_preferred = 0
              WHERE supplier_id = ? AND stock_item_id = ? AND id <> ?'
        )->execute([$supplierId, $stockItemId, $exceptId]);
    }

    /**
     * Klienti (id) patřící tenantovi a označení is_vendor=1 — guard proti
     * cross-tenant / ne-dodavatelské vazbě.
     * @param list<int> $clientIds
     * @return list<int>
     */
    public function filterOwnedVendors(int $supplierId, array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $clientIds)));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM clients
              WHERE supplier_id = ? AND is_vendor = 1 AND id IN (' . $ph . ')'
        );
        $stmt->execute(array_merge([$supplierId], $ids));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['stock_item_id'] = (int) $r['stock_item_id'];
        $r['client_id'] = (int) $r['client_id'];
        $r['delivery_days'] = $r['delivery_days'] !== null ? (int) $r['delivery_days'] : null;
        $r['is_preferred'] = (bool) $r['is_preferred'];
        if (array_key_exists('is_active', $r)) {
            $r['is_active'] = (bool) $r['is_active'];
        }
        // purchase_price, stock_qty, min_order_qty, package_qty i on_hand zůstávají
        // string (money/qty-safe, žádné floatování).
        if (array_key_exists('purchase_price', $r)) {
            $r['purchase_price'] = UnitPricePrecision::toDecimalString($r['purchase_price']);
        }
        return $r;
    }
}
