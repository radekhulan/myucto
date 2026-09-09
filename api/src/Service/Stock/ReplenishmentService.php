<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Repository\StockItemRepository;

/**
 * „Co objednat" (Epic SKLAD „na cestě", §5.6).
 *
 *     suggested_qty = max(0, min_qty × koeficient − on_hand + reserved − in_transit)
 *
 * ODEČTENÍ `in_transit` JE CELÝ SMYSL EPICU. Bez něj se objednává dvakrát:
 * zboží je na cestě, karta je pořád pod minimem a obrazovka ho navrhne znovu.
 * `reserved` se naopak PŘIČÍTÁ — ty kusy sice fyzicky leží ve skladu, ale jsou
 * už fakticky pryč (drží je vystavená faktura), takže na pokrytí minima nejsou.
 *
 * Výsledek se nejdřív dorovná na `min_order_qty` preferovaného dodavatele a pak
 * zaokrouhlí nahoru na násobek `package_qty` — objednat 3 kusy tam, kde se prodává
 * po deseti, je návrh, který dodavatel odmítne.
 */
final class ReplenishmentService
{
    /** Bezpečnostní koeficient nad minimální zásobou (doobjednává se s rezervou). */
    private const DEFAULT_COEFFICIENT = 1.0;

    public function __construct(
        private readonly StockItemRepository $items,
        private readonly InTransitService $quantities,
    ) {}

    /**
     * @param array<string,mixed> $filters {warehouse_id?, below_min?, item_ids?, limit?, offset?}
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function suggest(int $supplierId, array $filters = []): array
    {
        $warehouseId = isset($filters['warehouse_id']) && (int) $filters['warehouse_id'] > 0
            ? (int) $filters['warehouse_id'] : null;
        $itemIds     = is_array($filters['item_ids'] ?? null) ? array_map('intval', $filters['item_ids']) : [];
        $belowMin    = !empty($filters['below_min']);
        $coefficient = isset($filters['coefficient']) && (float) $filters['coefficient'] > 0
            ? (float) $filters['coefficient'] : self::DEFAULT_COEFFICIENT;

        $suggestions = [];
        $candidateIds = $this->items->replenishmentCandidateIds($supplierId, $itemIds);
        foreach (array_chunk($candidateIds, 500) as $chunk) {
            $rows = $this->quantities->quantities($supplierId, $chunk, $warehouseId, count($chunk));
            foreach ($rows as $row) {
                $this->appendSuggestion($suggestions, $row, $warehouseId, $coefficient, $belowMin);
            }
        }

        $total  = count($suggestions);
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $limit  = max(1, min(500, (int) ($filters['limit'] ?? 100)));

        return ['items' => array_slice($suggestions, $offset, $limit), 'total' => $total];
    }

    /**
     * @param list<array<string,mixed>> $suggestions
     * @param array<string,mixed> $row
     */
    private function appendSuggestion(
        array &$suggestions,
        array $row,
        ?int $warehouseId,
        float $coefficient,
        bool $belowMin,
    ): void {
        $minT       = StockValuation::qtyToT((string) $row['min_qty']);
        $onHandT    = StockValuation::qtyToT($row['on_hand']);
        $reservedT  = StockValuation::qtyToT($row['reserved']);
        $inTransitT = StockValuation::qtyToT($row['in_transit']);

        $targetT = (int) ceil($minT * $coefficient);
        $needT   = $targetT - $onHandT + $reservedT - $inTransitT;
        if ($needT <= 0 || ($belowMin && $onHandT - $reservedT >= $minT)) {
            return;
        }

        $offers = is_array($row['vendor_offers'] ?? null) ? $row['vendor_offers'] : [];
        $vendor = $this->preferredVendor($offers, (int) $row['stock_item_id']);
        $suggestedT = $this->roundToPackage($needT, $vendor);

        $suggestions[] = [
            'stock_item_id'    => $row['stock_item_id'],
            'sku'              => $row['sku'],
            'name'             => $row['name'],
            'unit'             => $row['unit'],
            'warehouse_id'     => $warehouseId,
            'on_hand'          => $row['on_hand'],
            'reserved'         => $row['reserved'],
            'in_transit'       => $row['in_transit'],
            'sellable'         => $row['sellable'],
            'min_qty'          => $row['min_qty'],
            'shortfall'        => StockValuation::tToDecimal($needT),
            'suggested_qty'    => StockValuation::tToDecimal($suggestedT),
            'preferred_vendor' => $vendor,
        ];
    }

    /**
     * Zaokrouhlení nahoru na balení a podlahování minimem odběru. Bez balení
     * i bez minima zůstává potřeba beze změny.
     *
     * @param array<string,mixed>|null $vendor
     */
    private function roundToPackage(int $needT, ?array $vendor): int
    {
        if ($vendor === null) {
            return $needT;
        }
        $minOrderT = $vendor['min_order_qty'] !== null ? StockValuation::qtyToT((string) $vendor['min_order_qty']) : 0;
        $needT = max($needT, $minOrderT);
        $packageT = $vendor['package_qty'] !== null ? StockValuation::qtyToT((string) $vendor['package_qty']) : 0;
        if ($packageT > 0) {
            $needT = (int) (ceil($needT / $packageT) * $packageT);
        }

        return $needT;
    }

    /**
     * Preferovaný dodavatel karty. `vendorOffersForItems()` už řadí
     * `is_preferred DESC, purchase_price ASC`, takže stačí první shoda.
     *
     * @param list<array<string,mixed>> $offers
     * @return array<string,mixed>|null
     */
    private function preferredVendor(array $offers, int $itemId): ?array
    {
        foreach ($offers as $offer) {
            if ($offer['stock_item_id'] === $itemId) {
                return $offer;
            }
        }

        return null;
    }
}
