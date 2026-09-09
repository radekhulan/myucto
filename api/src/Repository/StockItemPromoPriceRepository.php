<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_item_promo_prices — akční (promoční) ceny karty
 * (migrace 1328). Per tenant (supplier_id). Peněžní a množstevní pole se drží
 * jako string (money-safe vzor, žádný float).
 *
 * Množstevní strop akce má tři režimy:
 *  - 'stock'     … živý stav skladu ({@see stockQty()}), NEODEČÍTÁ se,
 *  - 'limited'   … pevný rozpočet `qty_limit` mínus {@see consumedQty()},
 *  - 'unlimited' … bez stropu.
 */
final class StockItemPromoPriceRepository
{
    private const COLUMNS =
        'id, supplier_id, stock_item_id, currency_code, promo_price, label,
         valid_from, valid_to, qty_mode, qty_limit, is_active, note,
         created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    /** Všechny akce karty (i neaktivní a prošlé) — editor karty. @return list<array<string,mixed>> */
    public function listForItem(int $supplierId, int $stockItemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_item_promo_prices
              WHERE supplier_id = ? AND stock_item_id = ?
              ORDER BY currency_code ASC, COALESCE(valid_from, "0000-01-01") ASC, id ASC'
        );
        $stmt->execute([$supplierId, $stockItemId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_item_promo_prices WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * Kandidáti pro rozhodnutí o ceně: aktivní akce daných karet a měny, které
     * k `$onDate` spadají do svého časového okna. Množstevní strop se řeší až
     * v {@see \MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver} (potřebuje
     * stav skladu / čerpání). Řazení = pořadí přednosti: nejnižší cena, při
     * shodě novější záznam.
     *
     * @param list<int> $stockItemIds
     * @return array<int,list<array<string,mixed>>> stock_item_id => akce
     */
    public function activeForItems(int $supplierId, array $stockItemIds, string $currency, string $onDate): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $stockItemIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_item_promo_prices
              WHERE supplier_id = ? AND currency_code = ? AND is_active = 1
                AND stock_item_id IN (' . $in . ')
                AND (valid_from IS NULL OR valid_from <= ?)
                AND (valid_to   IS NULL OR valid_to   >= ?)
              ORDER BY stock_item_id ASC, promo_price ASC, id DESC'
        );
        $stmt->execute(array_merge([$supplierId, strtoupper($currency)], $ids, [$onDate, $onDate]));

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $row = self::cast($r);
            $out[(int) $row['stock_item_id']][] = $row;
        }
        return $out;
    }

    /**
     * Součet skladových stavů karty přes všechny sklady firmy (režim 'stock').
     * Vrací money-safe string; karta bez pohybů → '0.000'.
     *
     * @param list<int> $stockItemIds
     * @return array<int,string> stock_item_id => qty
     */
    public function stockQty(int $supplierId, array $stockItemIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $stockItemIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT stock_item_id, SUM(qty) AS total FROM stock_levels
              WHERE supplier_id = ? AND stock_item_id IN (' . $in . ')
              GROUP BY stock_item_id'
        );
        $stmt->execute(array_merge([$supplierId], $ids));
        $out = array_fill_keys($ids, '0.000');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['stock_item_id']] = (string) $r['total'];
        }
        return $out;
    }

    /**
     * Vyčerpané množství akce (režim 'limited') — DOPOČÍTANÉ z vystavených faktur,
     * ne z čítače (zdůvodnění v migraci 1328).
     *
     * Do rozpočtu se počítá řádek faktury, který:
     *  - patří téže kartě a téže měně dokladu jako akce,
     *  - má datum plnění uvnitř okna čerpání — od COALESCE(valid_from, DATE(created_at))
     *    do valid_to (bez `valid_from` by se jinak započítaly prodeje z doby PŘED
     *    založením akce),
     *  - je na dokladu, který skutečně vznikl (status ≠ draft/cancelled) a je typu
     *    `invoice` nebo `credit_note`,
     *  - byl prodaný za akční cenu NEBO LEVNĚJI (`unit_price_without_vat <= promo_price`);
     *    dražší řádek akci nečerpá, protože zákazník slevu nedostal.
     *
     * Dobropis se odečítá (vrácené zboží rozpočet uvolní). Storno a smazání faktury
     * rozpočet uvolní samy — řádky prostě zmizí, resp. doklad je `cancelled`.
     *
     * @param array<string,mixed> $promo řádek akce
     */
    public function consumedQty(int $supplierId, array $promo): string
    {
        return $this->consumedMany($supplierId, [$promo])[0];
    }

    public function consumedMany(int $supplierId, array $promos): array
    {
        if ($promos === []) {
            return [];
        }
        $input = array_map(static fn (array $promo): array => [
            'stock_item_id' => (int) $promo['stock_item_id'],
            'currency' => strtoupper((string) $promo['currency_code']),
            'from_date' => $promo['valid_from'] ?? substr((string) $promo['created_at'], 0, 10),
            'to_date' => $promo['valid_to'],
            'price' => (string) $promo['promo_price'],
        ], array_values($promos));
        $stmt = $this->db->pdo()->prepare("SELECT p.position,
                SUM(CASE WHEN i.invoice_type = 'credit_note' THEN -ii.quantity ELSE ii.quantity END) AS used
            FROM JSON_TABLE(?, '$[*]' COLUMNS (
                position FOR ORDINALITY,
                stock_item_id BIGINT PATH '$.stock_item_id',
                currency VARCHAR(3) PATH '$.currency',
                from_date DATE PATH '$.from_date',
                to_date DATE PATH '$.to_date' NULL ON EMPTY,
                price DECIMAL(18,6) PATH '$.price'
            )) p
            JOIN invoice_items ii ON ii.stock_item_id = p.stock_item_id
            JOIN invoices i ON i.id = ii.invoice_id AND i.supplier_id = ?
            JOIN currencies c ON c.id = i.currency_id AND c.code = p.currency
            WHERE i.invoice_type IN ('invoice', 'credit_note')
              AND i.status NOT IN ('draft', 'cancelled')
              AND COALESCE(i.tax_date, i.issue_date) >= p.from_date
              AND (p.to_date IS NULL OR COALESCE(i.tax_date, i.issue_date) <= p.to_date)
              AND ii.unit_price_without_vat <= p.price
            GROUP BY p.position");
        $stmt->execute([json_encode($input, JSON_THROW_ON_ERROR), $supplierId]);
        $out = array_fill(0, count($input), '0.000');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['position'] - 1] = bccomp((string) $row['used'], '0', 3) < 0
                ? '0.000' : bcadd((string) $row['used'], '0', 3);
        }
        return $out;
    }

    /**
     * @param array{currency_code:string, promo_price:string, label:?string,
     *              valid_from:?string, valid_to:?string, qty_mode:string,
     *              qty_limit:?string, is_active:bool, note:?string} $data
     */
    public function insert(int $supplierId, int $stockItemId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_item_promo_prices
                (supplier_id, stock_item_id, currency_code, promo_price, label,
                 valid_from, valid_to, qty_mode, qty_limit, is_active, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(self::bind($supplierId, $stockItemId, $data));
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{currency_code:string, promo_price:string, label:?string,
     *              valid_from:?string, valid_to:?string, qty_mode:string,
     *              qty_limit:?string, is_active:bool, note:?string} $data
     */
    public function update(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_item_promo_prices SET
                currency_code = ?, promo_price = ?, label = ?, valid_from = ?, valid_to = ?,
                qty_mode = ?, qty_limit = ?, is_active = ?, note = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            strtoupper((string) $data['currency_code']),
            (string) $data['promo_price'],
            $data['label'],
            $data['valid_from'],
            $data['valid_to'],
            (string) $data['qty_mode'],
            $data['qty_limit'],
            (int) $data['is_active'],
            $data['note'],
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() >= 0;
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_item_promo_prices WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        return $stmt->rowCount() > 0;
    }

    /** Smaže akce karty, které nejsou v seznamu ponechaných id (bulk replace). @param list<int> $keepIds */
    public function deleteForItemExcept(int $supplierId, int $stockItemId, array $keepIds): void
    {
        $keep = array_values(array_unique(array_filter(array_map('intval', $keepIds), static fn (int $i): bool => $i > 0)));
        $sql = 'DELETE FROM stock_item_promo_prices WHERE supplier_id = ? AND stock_item_id = ?';
        $params = [$supplierId, $stockItemId];
        if ($keep !== []) {
            $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')';
            $params = array_merge($params, $keep);
        }
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    /** @return list<mixed> */
    private static function bind(int $supplierId, int $stockItemId, array $data): array
    {
        return [
            $supplierId,
            $stockItemId,
            strtoupper((string) $data['currency_code']),
            (string) $data['promo_price'],
            $data['label'],
            $data['valid_from'],
            $data['valid_to'],
            (string) $data['qty_mode'],
            $data['qty_limit'],
            (int) $data['is_active'],
            $data['note'],
        ];
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['stock_item_id'] = (int) $r['stock_item_id'];
        $r['is_active'] = (bool) $r['is_active'];
        $r['currency_code'] = strtoupper((string) $r['currency_code']);
        // promo_price, qty_limit zůstávají string (money-safe).
        return $r;
    }
}
