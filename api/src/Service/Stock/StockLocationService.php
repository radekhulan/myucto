<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Repository\StockLocationRepository;

final class StockLocationService
{
    public function __construct(private readonly StockLocationRepository $locations) {}

    public static function id(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if ($id === false || $id <= 0) {
            throw new StockException('stock_location_invalid', 'ID skladové lokace musí být kladné celé číslo.', 422);
        }
        return $id;
    }

    public function validateLines(int $supplierId, string $type, int $warehouse, ?int $destination, array $lines, bool $lock = false): void
    {
        $ids = array_values(array_unique(array_filter([...array_column($lines, 'location_id'), ...array_column($lines, 'location_to_id')])));
        $locations = $this->locations->locations($supplierId, $ids, $lock);
        foreach ($lines as $line) {
            $from = $line['location_id'];
            $to = $line['location_to_id'];
            if ($type === 'transfer' && $warehouse === $destination) {
                if ($from === null && $to === null) {
                    throw new StockException('invalid_document', 'Cílový sklad převodky musí být odlišný od zdrojového.');
                }
                if ($from === $to || $line['tracking_mode'] !== 'none') {
                    throw new StockException('stock_location_invalid', 'Přesun v rámci skladu vyžaduje dvě různá umístění karty bez sledování.', 422);
                }
            }
            if (($from !== null || $to !== null) && $line['tracking_mode'] !== 'none') {
                throw new StockException('stock_location_invalid', 'U sledované karty zadejte lokaci u šarže nebo sériového čísla.', 422);
            }
            if ($to !== null && $type !== 'transfer') {
                throw new StockException('stock_location_invalid', 'Cílová lokace patří jen na převodku.', 422);
            }
            if ($from !== null) {
                self::assertLocation($locations[$from] ?? null, $warehouse, $type === 'receipt', $from);
            }
            if ($to !== null) {
                self::assertLocation($locations[$to] ?? null, $destination, true, $to);
            }
        }
    }

    private static function assertLocation(?array $location, ?int $warehouse, bool $inbound, int $id): void
    {
        if ($location === null || (int) $location['warehouse_id'] !== $warehouse || ($inbound && !(bool) $location['is_active'])) {
            throw new StockException('stock_location_invalid', 'Lokace nepatří k vybranému skladu nebo je pro příjem neaktivní.', 422, ['location_id' => $id]);
        }
    }

    /** @return array<int, string> */
    public function quantitiesAt(int $supplierId, int $warehouseId, int $locationId, string $date): array
    {
        return $this->locations->quantitiesAt($supplierId, $warehouseId, $locationId, $date);
    }

    public function applyDocument(
        int $supplierId,
        string $type,
        int $warehouse,
        ?int $destination,
        string $date,
        array $lines,
        array $warehouseLevels,
        bool $reversal = false,
    ): void {
        $lines = array_values(array_filter($lines, static fn (array $line): bool => $line['tracking_mode'] === 'none'));
        if ($lines === []) {
            return;
        }
        $items = array_values(array_unique(array_column($lines, 'stock_item_id')));
        $warehouses = $destination === null || $destination === $warehouse ? [$warehouse] : [$warehouse, $destination];
        $balances = $this->locations->lockBalances($supplierId, $warehouses, $items);
        $locationInput = array_filter($lines, static fn (array $line): bool => $line['location_id'] !== null || $line['location_to_id'] !== null);
        if ($balances === [] && $locationInput === []) {
            return;
        }
        foreach ($warehouses as $warehouseId) {
            foreach ($items as $itemId) {
                $key = "$warehouseId:$itemId";
                $balances[$key][0] = $warehouseLevels[$key]['qtyT'] - array_sum($balances[$key] ?? []);
                ksort($balances[$key]);
            }
        }
        $floors = $type === 'receipt' ? $balances : $this->historicalFloors($supplierId, $date, $warehouse, $items, $balances);
        $original = $reversal ? $this->locations->originalMovements($supplierId, array_column($lines, 'original_line_id')) : [];
        if (!$reversal && $type !== 'receipt' && $warehouse !== $destination) {
            usort($lines, static fn (array $a, array $b): int => ($a['location_id'] === null) <=> ($b['location_id'] === null));
        }

        $movements = [];
        $levels = [];
        foreach ($lines as $line) {
            $itemId = $line['stock_item_id'];
            $qtyT = StockValuation::qtyToT((string) $line['qty']);
            $lineId = $line['id'];
            $legs = [];
            if ($reversal) {
                foreach ($original[$line['original_line_id']] ?? [] as $entry) {
                    $legs[(int) $entry['warehouse_id']][] = [(int) $entry['location_id'], -StockValuation::qtyToT($entry['quantity'])];
                }
            }
            if ($type !== 'receipt') {
                $key = "$warehouse:$itemId";
                $out = match (true) {
                    $reversal => self::reverseLegs($legs[$warehouse] ?? [], -$qtyT),
                    $line['location_id'] !== null || $warehouse === $destination => [[$line['location_id'] ?? 0, -$qtyT]],
                    default => self::allocate($floors[$key], $qtyT, $warehouse, $itemId),
                };
                $this->applyLegs($out, $warehouse, $itemId, $lineId, $balances, $floors, $movements, $levels);
            }
            if ($type !== 'issue') {
                $target = $destination ?? $warehouse;
                $location = $type === 'receipt' ? $line['location_id'] : $line['location_to_id'];
                $in = $reversal ? self::reverseLegs($legs[$target] ?? [], $qtyT) : [[$location ?? 0, $qtyT]];
                $this->applyLegs($in, $target, $itemId, $lineId, $balances, $floors, $movements, $levels);
            }
        }
        $this->locations->saveMovements($supplierId, $date, $movements, $levels);
    }

    private function historicalFloors(int $supplierId, string $date, int $warehouse, array $items, array $balances): array
    {
        $floors = $balances;
        foreach ($this->locations->futureNamedMovements($supplierId, $warehouse, $items, $date) as $row) {
            $key = $row['warehouse_id'] . ':' . $row['stock_item_id'];
            $location = (int) $row['location_id'];
            $qtyT = StockValuation::qtyToT($row['quantity']);
            $balances[$key][$location] -= $qtyT;
            $floors[$key][$location] = min($floors[$key][$location], $balances[$key][$location]);
        }
        foreach ($this->locations->futureUnassignedMovements($supplierId, $warehouse, $items, $date) as $row) {
            $key = "$warehouse:{$row['stock_item_id']}";
            $balances[$key][0] -= StockValuation::qtyToT($row['quantity']);
            $floors[$key][0] = min($floors[$key][0], $balances[$key][0]);
        }
        return $floors;
    }

    private static function allocate(array $available, int $qtyT, int $warehouse, int $item): array
    {
        $out = [];
        $remaining = $qtyT;
        foreach ($available as $location => $free) {
            $take = min($remaining, max(0, $free));
            if ($take > 0) {
                $out[] = [$location, -$take];
                $remaining -= $take;
            }
            if ($remaining === 0) {
                return $out;
            }
        }
        throw self::insufficient($warehouse, $item, null, $qtyT, $qtyT - $remaining);
    }

    private static function reverseLegs(array $legs, int $expected): array
    {
        $sameDirection = array_values(array_filter($legs, static fn (array $leg): bool => ($leg[1] < 0) === ($expected < 0)));
        $unnamed = $expected - array_sum(array_column($sameDirection, 1));
        if ($unnamed !== 0) {
            $sameDirection[] = [0, $unnamed];
        }
        return $sameDirection;
    }

    private function applyLegs(array $legs, int $warehouse, int $item, int $line, array &$balances, array &$floors, array &$movements, array &$levels): void
    {
        $key = "$warehouse:$item";
        foreach ($legs as [$location, $qtyT]) {
            $available = $floors[$key][$location] ?? 0;
            if ($qtyT < 0 && -$qtyT > $available) {
                throw self::insufficient($warehouse, $item, $location ?: null, -$qtyT, max(0, $available));
            }
            $current = ($balances[$key][$location] ?? 0) + $qtyT;
            $balances[$key][$location] = $current;
            $floors[$key][$location] = $available + $qtyT;
            if ($location !== 0) {
                $movements[] = [$warehouse, $item, $location, $line, $qtyT];
                $levels["$key:$location"] = [$warehouse, $item, $location, $current];
            }
        }
    }

    private static function insufficient(int $warehouse, int $item, ?int $location, int $requested, int $available): StockException
    {
        return new StockException('stock_location_insufficient', 'V umístění není dostatek zásoby k datu dokladu.', 409, [
            'warehouse_id' => $warehouse,
            'stock_item_id' => $item,
            'location_id' => $location,
            'requested' => StockValuation::tToDecimal($requested),
            'available' => StockValuation::tToDecimal($available),
        ]);
    }
}
