<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Repository\InTransitRepository;

/**
 * Skládá tři dimenze množství do jedné odpovědi (Epic SKLAD „na cestě", §5.4).
 *
 * | veličina               | vzorec                             | kde se používá            |
 * |------------------------|------------------------------------|---------------------------|
 * | `on_hand`              | `stock_levels.qty`                 | ocenění, rozvaha          |
 * | `reserved`             | dotaz §11.2                        | —                         |
 * | `sellable`             | `on_hand − reserved`               | **e-shop, zákazník**      |
 * | `in_transit`           | dotaz §3.4                         | doplnění zásob            |
 * | `available_to_promise` | `on_hand − reserved + in_transit`  | interní karta, plánování  |
 *
 * Rozhodnutí #1: „na cestě" NEOVLIVŇUJE prodejní dostupnost — e-shop bere
 * `sellable`, ATP je jen pro interní obrazovky. Rozhodnutí #12: karta bez
 * jediného pohybu MUSÍ vrátit řádek s nulami, ne prázdno; proto se odpověď
 * staví ze seznamu karet a agregáty se na ni jen nalepují.
 *
 * `sellable` se interně NEPODLAHUJE nulou. Záporná hodnota (prodalo se víc,
 * než je skladem) je signál k urgentnímu doobjednání a schovat ji by znamenalo
 * schovat právě ten případ, kvůli kterému se na obrazovku kouká.
 */
final class InTransitService
{
    public function __construct(private readonly InTransitRepository $repo) {}

    /**
     * @param list<int> $itemIds prázdné = celý katalog (limitovaně)
     * @return list<array<string,mixed>>
     */
    public function quantities(int $supplierId, array $itemIds = [], ?int $warehouseId = null, int $limit = 500): array
    {
        $items = $this->repo->itemsBasics($supplierId, $itemIds, false, $limit);
        if ($items === []) {
            return [];
        }
        $ids = array_map(static fn (array $i): int => $i['stock_item_id'], $items);

        $onHand    = $this->repo->onHandForItems($supplierId, $ids, $warehouseId);
        $inTransit = $this->repo->forItems($supplierId, $ids, $warehouseId);
        $reserved  = [
            ...$this->repo->reservedForItems($supplierId, $ids, $warehouseId),
            ...$this->repo->salesOrderReservedForItems($supplierId, $ids, $warehouseId),
        ];
        $orders    = $this->repo->ordersForItems($supplierId, $ids, $warehouseId);
        $offers    = $this->repo->vendorOffersForItems($supplierId, $ids);

        $out = [];
        foreach ($items as $item) {
            $id = $item['stock_item_id'];

            $warehouses    = [];
            $onHandT       = 0;
            foreach ($onHand as $row) {
                if ($row['stock_item_id'] !== $id || $row['warehouse_id'] === null) {
                    continue;
                }
                $onHandT += StockValuation::qtyToT($row['on_hand']);
                $warehouses[$row['warehouse_id']] = [
                    'warehouse_id'   => $row['warehouse_id'],
                    'warehouse_code' => $row['warehouse_code'],
                    'warehouse_name' => $row['warehouse_name'],
                    'on_hand'        => $row['on_hand'],
                    'in_transit'     => '0.000',
                ];
            }

            $inTransitT = 0;
            $earliest   = null;
            foreach ($inTransit as $row) {
                if ($row['stock_item_id'] !== $id) {
                    continue;
                }
                $inTransitT += StockValuation::qtyToT($row['qty_in_transit']);
                if ($row['earliest_expected_date'] !== null
                    && ($earliest === null || $row['earliest_expected_date'] < $earliest)) {
                    $earliest = $row['earliest_expected_date'];
                }
                $wh = $row['warehouse_id'];
                if (!isset($warehouses[$wh])) {
                    // Objednáno na sklad, kde karta ještě nikdy neležela.
                    $warehouses[$wh] = [
                        'warehouse_id'   => $wh,
                        'warehouse_code' => null,
                        'warehouse_name' => null,
                        'on_hand'        => '0.000',
                        'in_transit'     => '0.000',
                    ];
                }
                $warehouses[$wh]['in_transit'] = StockValuation::tToDecimal(
                    StockValuation::qtyToT($warehouses[$wh]['in_transit'])
                    + StockValuation::qtyToT($row['qty_in_transit']),
                );
            }

            $reservedT = 0;
            foreach ($reserved as $row) {
                if ($row['stock_item_id'] === $id) {
                    $reservedT += StockValuation::qtyToT($row['qty_reserved']);
                }
            }

            $atVendorT   = 0;
            $itemOffers  = [];
            foreach ($offers as $offer) {
                if ($offer['stock_item_id'] !== $id) {
                    continue;
                }
                $itemOffers[] = $offer;
                if ($offer['stock_qty'] !== null) {
                    $atVendorT += StockValuation::qtyToT($offer['stock_qty']);
                }
            }

            $itemOrders = array_values(array_filter(
                $orders,
                static fn (array $o): bool => $o['stock_item_id'] === $id,
            ));

            $out[] = [
                'stock_item_id'          => $id,
                'sku'                    => $item['sku'],
                'name'                   => $item['name'],
                'unit'                   => $item['unit'],
                'min_qty'                => $item['min_qty'],
                'is_active'              => $item['is_active'],
                'on_hand'                => StockValuation::tToDecimal($onHandT),
                'reserved'               => StockValuation::tToDecimal($reservedT),
                'sellable'               => StockValuation::tToDecimal($onHandT - $reservedT),
                'in_transit'             => StockValuation::tToDecimal($inTransitT),
                'at_vendor'              => StockValuation::tToDecimal($atVendorT),
                'available_to_promise'   => StockValuation::tToDecimal($onHandT - $reservedT + $inTransitT),
                'earliest_expected_date' => $earliest,
                'warehouses'             => array_values($warehouses),
                'in_transit_orders'      => $itemOrders,
                'vendor_offers'          => $itemOffers,
            ];
        }

        return $out;
    }

    /**
     * Samostatné „na cestě" s rozpadem na objednávky (GET /api/stock/in-transit).
     *
     * @param list<int> $itemIds
     * @return list<array<string,mixed>>
     */
    public function inTransit(int $supplierId, array $itemIds = [], ?int $warehouseId = null): array
    {
        $rows   = $this->repo->forItems($supplierId, $itemIds, $warehouseId);
        $orders = $this->repo->ordersForItems($supplierId, $itemIds, $warehouseId);
        if ($rows === []) {
            return [];
        }

        $basics = [];
        foreach ($this->repo->itemsBasics($supplierId, array_values(array_unique(array_map(
            static fn (array $r): int => $r['stock_item_id'],
            $rows,
        )))) as $item) {
            $basics[$item['stock_item_id']] = $item;
        }

        $out = [];
        foreach ($rows as $row) {
            $id  = $row['stock_item_id'];
            $out[] = [
                'stock_item_id'          => $id,
                'sku'                    => $basics[$id]['sku'] ?? null,
                'name'                   => $basics[$id]['name'] ?? null,
                'unit'                   => $basics[$id]['unit'] ?? null,
                'warehouse_id'           => $row['warehouse_id'],
                'qty_in_transit'         => $row['qty_in_transit'],
                'earliest_expected_date' => $row['earliest_expected_date'],
                'orders'                 => array_values(array_filter(
                    $orders,
                    static fn (array $o): bool => $o['stock_item_id'] === $id && $o['warehouse_id'] === $row['warehouse_id'],
                )),
            ];
        }

        return $out;
    }

    /**
     * Rezervace s rozpadem na faktury (GET /api/stock/reservations).
     *
     * @param list<int> $itemIds
     * @return list<array<string,mixed>>
     */
    public function reservations(int $supplierId, array $itemIds = [], ?int $warehouseId = null): array
    {
        $rows = [
            ...$this->repo->reservedForItems($supplierId, $itemIds, $warehouseId),
            ...$this->repo->salesOrderReservedForItems($supplierId, $itemIds, $warehouseId),
        ];
        if ($rows === []) {
            return [];
        }
        $invoices = $this->repo->reservationInvoices($supplierId, $itemIds, $warehouseId);
        $orders = $this->repo->reservationSalesOrders($supplierId, $itemIds, $warehouseId);

        // Rezervace se v odpovědi agregují per KARTA (sklad je jen filtr) — uživatel
        // se ptá „co drží tenhle kus", ne „co ho drží na tomhle regálu".
        /** @var array<int,int> $byItem stock_item_id => množství v tisícinách */
        $byItem = [];
        foreach ($rows as $row) {
            $id          = $row['stock_item_id'];
            $byItem[$id] = ($byItem[$id] ?? 0) + StockValuation::qtyToT($row['qty_reserved']);
        }

        $basics = [];
        foreach ($this->repo->itemsBasics($supplierId, array_keys($byItem)) as $item) {
            $basics[$item['stock_item_id']] = $item;
        }

        $out = [];
        foreach ($byItem as $id => $qtyT) {
            $out[] = [
                'stock_item_id' => (int) $id,
                'sku'           => $basics[$id]['sku'] ?? null,
                'name'          => $basics[$id]['name'] ?? null,
                'unit'          => $basics[$id]['unit'] ?? null,
                'qty_reserved'  => StockValuation::tToDecimal($qtyT),
                'invoices'      => array_values(array_filter(
                    $invoices,
                    static fn (array $i): bool => $i['stock_item_id'] === (int) $id,
                )),
                'sales_orders'  => array_values(array_filter(
                    $orders,
                    static fn (array $order): bool => $order['stock_item_id'] === (int) $id,
                )),
            ];
        }

        return $out;
    }
}
