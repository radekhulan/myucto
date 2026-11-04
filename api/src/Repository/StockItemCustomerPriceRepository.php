<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use PDO;

/**
 * Individuální ceny zákazníků na skladové kartě (migrace 1832, issue #17) —
 * pevná cena nebo sleva v % pro konkrétního odběratele a měnu, vždy za ZÁKLADNÍ
 * jednotku a bez DPH. O použití rozhoduje jen
 * {@see \MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver}.
 *
 * Proč vedle `price_list_customer_overrides` (1121): ten patří ceníku firem BEZ
 * skladu (route admin/price-list, requiresNoStock) a na skladovou kartu nevidí.
 * Firma se skladem má jediný zdroj ceny, kartu, a její zákaznické ceny žijí tady.
 */
final class StockItemCustomerPriceRepository
{
    private const COLUMNS =
        'p.id, p.supplier_id, p.stock_item_id, p.client_id, p.currency_code, p.price_type,
         p.fixed_price, p.discount_pct, p.valid_from, p.valid_to, p.note';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForItem(int $supplierId, int $stockItemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ', c.company_name AS client_name
               FROM stock_item_customer_prices p
               JOIN clients c ON c.id = p.client_id AND c.supplier_id = p.supplier_id
              WHERE p.supplier_id = ? AND p.stock_item_id = ?
              ORDER BY c.company_name ASC, p.currency_code ASC, p.id ASC'
        );
        $stmt->execute([$supplierId, $stockItemId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Zákaznické ceny platné k datu pro daného odběratele a měnu.
     *
     * @param list<int> $stockItemIds
     * @return array<int, array<string,mixed>> stock_item_id => řádek
     */
    public function activeFor(int $supplierId, int $clientId, string $currency, array $stockItemIds, string $onDate): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $stockItemIds), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || $clientId <= 0) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_item_customer_prices p
              WHERE p.supplier_id = ? AND p.client_id = ? AND p.currency_code = ?
                AND p.stock_item_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                AND (p.valid_from IS NULL OR p.valid_from <= ?)
                AND (p.valid_to   IS NULL OR p.valid_to   >= ?)'
        );
        $stmt->execute(array_merge([$supplierId, $clientId, strtoupper($currency)], $ids, [$onDate, $onDate]));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $row = self::cast($r);
            $out[$row['stock_item_id']] = $row;
        }
        return $out;
    }

    /**
     * Nahradí celou sadu zákaznických cen karty.
     *
     * @param list<array{client_id:int, currency_code:string, price_type:string, fixed_price:?string,
     *                   discount_pct:?string, valid_from:?string, valid_to:?string, note:?string}> $rows
     */
    public function replaceForItem(int $supplierId, int $stockItemId, array $rows): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM stock_item_customer_prices WHERE supplier_id = ? AND stock_item_id = ?')
            ->execute([$supplierId, $stockItemId]);
        $stmt = $pdo->prepare(
            'INSERT INTO stock_item_customer_prices
                (supplier_id, stock_item_id, client_id, currency_code, price_type, fixed_price, discount_pct,
                 valid_from, valid_to, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $r) {
            $stmt->execute([
                $supplierId,
                $stockItemId,
                $r['client_id'],
                strtoupper($r['currency_code']),
                $r['price_type'],
                $r['fixed_price'],
                $r['discount_pct'],
                $r['valid_from'],
                $r['valid_to'],
                $r['note'],
            ]);
        }
    }

    /**
     * Které z karet mají aspoň jednu zákaznickou cenu (bez ohledu na klienta
     * a platnost) — jeden dotaz pro celý seznam.
     *
     * @param list<int> $stockItemIds
     * @return array<int,true>
     */
    public function itemsWithPrices(int $supplierId, array $stockItemIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $stockItemIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT stock_item_id FROM stock_item_customer_prices
              WHERE supplier_id = ? AND stock_item_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute(array_merge([$supplierId], $ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $out[(int) $id] = true;
        }
        return $out;
    }

    /**
     * Které z daných klientů patří firmě.
     *
     * @param list<int> $clientIds
     * @return array<int, string> client_id => company_name
     */
    public function clientsOfSupplier(int $supplierId, array $clientIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $clientIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, company_name FROM clients WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute(array_merge([$supplierId], $ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = (string) $r['company_name'];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['stock_item_id'] = (int) $r['stock_item_id'];
        $r['client_id'] = (int) $r['client_id'];
        $r['currency_code'] = strtoupper((string) $r['currency_code']);
        // fixed_price, discount_pct zůstávají string (money-safe).
        $r['fixed_price'] = UnitPricePrecision::toDecimalString($r['fixed_price']);
        return $r;
    }
}
