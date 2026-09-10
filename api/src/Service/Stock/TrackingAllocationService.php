<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Repository\StockTrackingRepository;

final class TrackingAllocationService
{
    public function __construct(private readonly StockTrackingRepository $tracking) {}

    public function normalizeDraftInput(int $supplierId, int $itemId, array $raw): ?string
    {
        $item = $this->tracking->item($supplierId, $itemId);
        if ($item === null) {
            throw new StockException('invalid_document', 'Skladová karta nenalezena.', 422);
        }
        $mode = (string) $item['tracking_mode'];
        if ($mode === 'none') {
            if ($raw !== []) {
                throw new StockException('tracking_not_enabled', 'Karta nemá zapnuté sledování šarží ani sériových čísel.', 422);
            }
            return null;
        }
        if (!array_is_list($raw) || count($raw) > 10000) {
            throw new StockException('invalid_tracking_allocations', 'Alokace sledování musí být seznam nejvýše 10 000 položek.', 422);
        }
        $normalized = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                throw new StockException('invalid_tracking_allocations', 'Neplatná alokace sledování.', 422);
            }
            $quantity = trim((string) ($entry['quantity'] ?? ''));
            $unitCode = trim((string) ($entry['unit_code'] ?? ''));
            if ($unitCode !== '' && $unitCode !== (string) $item['unit']) {
                $ratio = $this->tracking->unitRatio($supplierId, $itemId, $unitCode);
                if ($ratio === null) {
                    throw new StockException('invalid_unit', 'Převodní jednotka pro kartu neexistuje.', 422, ['unit_code' => $unitCode]);
                }
                $qtyT = ExactUnitConversion::toBaseT($quantity, $ratio['numerator'], $ratio['denominator']);
            } else {
                $qtyT = ExactUnitConversion::toBaseT($quantity, 1, 1);
            }
            if ($qtyT <= 0 || ($mode === 'serial' && $qtyT !== 1000)) {
                throw new StockException('invalid_tracking_quantity', $mode === 'serial'
                    ? 'Sériové číslo představuje právě jeden nedělitelný kus.'
                    : 'Množství šarže musí být větší než nula.', 422);
            }
            $serial = self::nullable($entry['serial_number'] ?? null);
            $lot = self::nullable($entry['lot_code'] ?? null);
            $expires = self::nullable($entry['expires_on'] ?? null);
            if (($serial !== null && mb_strlen($serial) > 184) || ($lot !== null && mb_strlen($lot) > 100)) {
                throw new StockException('tracking_identity_too_long', 'Sériové číslo nebo kód šarže je příliš dlouhý.', 422);
            }
            if ($expires !== null && !self::isDate($expires)) {
                throw new StockException('invalid_expiry', 'Expirace musí být platné datum YYYY-MM-DD.', 422);
            }
            if (($mode === 'serial' && $serial === null) || ($mode === 'lot' && $lot === null)) {
                throw new StockException('tracking_identity_required', $mode === 'serial'
                    ? 'Sériové číslo je povinné.' : 'Kód šarže je povinný.', 422);
            }
            if (($mode === 'serial' && $lot !== null) || ($mode === 'lot' && $serial !== null)) {
                throw new StockException('tracking_mode_mismatch', 'Typ alokace neodpovídá režimu sledování karty.', 422);
            }
            $normalized[] = [
                'stock_tracking_unit_id' => isset($entry['stock_tracking_unit_id']) && (int) $entry['stock_tracking_unit_id'] > 0 ? (int) $entry['stock_tracking_unit_id'] : null,
                'quantity' => StockValuation::tToDecimal($qtyT),
                'serial_number' => $serial,
                'lot_code' => $lot,
                'expires_on' => $expires,
                'location_id' => isset($entry['location_id']) && (int) $entry['location_id'] > 0 ? (int) $entry['location_id'] : null,
                'location_to_id' => isset($entry['location_to_id']) && (int) $entry['location_to_id'] > 0 ? (int) $entry['location_to_id'] : null,
            ];
        }
        return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function postLine(int $supplierId, string $docType, int $warehouseId, ?int $warehouseToId, array $line): void
    {
        $mode = (string) ($line['tracking_mode'] ?? 'none');
        $allocations = $line['tracking_allocations'] ?? [];
        if ($mode === 'none') {
            if ($allocations !== []) throw new StockException('tracking_not_enabled', 'Karta nemá zapnuté sledování.', 422);
            return;
        }
        if ($allocations === []) {
            throw new StockException('tracking_allocations_required', 'Řádek sledované karty musí být úplně rozdělen mezi šarže nebo sériová čísla.', 422, ['line_id' => (int) $line['id']]);
        }
        $lineQtyT = StockValuation::qtyToT((string) $line['qty']);
        $allocatedT = 0;
        $seenSerial = [];
        $seenLeg = [];
        foreach ($allocations as $entry) {
            $qtyT = StockValuation::qtyToT((string) $entry['quantity']);
            $serial = self::nullable($entry['serial_number'] ?? null);
            $lot = self::nullable($entry['lot_code'] ?? null);
            if (($mode === 'serial' && ($qtyT !== 1000 || $serial === null || $lot !== null))
                || ($mode === 'lot' && ($lot === null || $serial !== null))) {
                throw new StockException('tracking_mode_mismatch', 'Uložená alokace neodpovídá aktuálnímu režimu sledování karty.', 422);
            }
            $allocatedT += $qtyT;
            $unit = $this->resolveUnit($supplierId, (int) $line['stock_item_id'], $mode, $entry, $docType === 'receipt');
            if ($mode === 'serial') {
                if (isset($seenSerial[$unit['id']])) throw new StockException('duplicate_serial_allocation', 'Sériové číslo je na řádku uvedeno vícekrát.', 422);
                $seenSerial[$unit['id']] = true;
            }
            $fromLocation = $this->requireLocation($supplierId, $entry['location_id'] ?? null, $warehouseId, $docType === 'receipt');
            $legKey = $unit['id'] . ':' . ($fromLocation ?? 0);
            if (isset($seenLeg[$legKey])) {
                throw new StockException('duplicate_tracking_allocation', 'Stejná šarže nebo série a lokace je na řádku uvedena vícekrát.', 422);
            }
            $seenLeg[$legKey] = true;
            if ($docType === 'receipt') {
                if ($mode === 'serial' && $this->tracking->balance($supplierId, $unit['id'], null, null, true) !== 0) {
                    throw new StockException('serial_already_in_stock', 'Sériové číslo už je skladem.', 409, ['serial_number' => $unit['serial_number']]);
                }
                $this->tracking->insertAllocation($supplierId, (int) $line['id'], $unit['id'], $warehouseId, $fromLocation, 'in', (string) $entry['quantity']);
                continue;
            }
            $this->assertAvailable($supplierId, $unit, $warehouseId, $fromLocation, $qtyT);
            $this->tracking->insertAllocation($supplierId, (int) $line['id'], $unit['id'], $warehouseId, $fromLocation, 'out', (string) $entry['quantity']);
            if ($docType === 'transfer') {
                if ($warehouseToId === null) throw new StockException('invalid_document', 'Převodka nemá cílový sklad.', 422);
                $toLocation = $this->requireLocation($supplierId, $entry['location_to_id'] ?? null, $warehouseToId, true);
                $this->tracking->insertAllocation($supplierId, (int) $line['id'], $unit['id'], $warehouseToId, $toLocation, 'in', (string) $entry['quantity']);
            }
        }
        if ($allocatedT !== $lineQtyT) {
            throw new StockException('tracking_allocation_mismatch', 'Součet alokací musí přesně odpovídat množství řádku.', 422, [
                'line_id' => (int) $line['id'],
                'line_quantity' => (string) $line['qty'],
                'allocated_quantity' => StockValuation::tToDecimal($allocatedT),
            ]);
        }
    }

    public function reverseLine(int $supplierId, int $originalLineId, int $counterLineId): void
    {
        $originals = $this->tracking->allocationsForLine($supplierId, $originalLineId);
        usort($originals, static fn (array $a, array $b): int => ($a['direction'] === 'in' ? 0 : 1) <=> ($b['direction'] === 'in' ? 0 : 1));
        foreach ($originals as $original) {
            $direction = $original['direction'] === 'in' ? 'out' : 'in';
            $unit = $this->tracking->findUnit($supplierId, (int) $original['stock_item_id'], (int) $original['stock_tracking_unit_id'], true);
            if ($unit === null) throw new StockException('tracking_not_found', 'Původní sledovaná jednotka nenalezena.', 409);
            if ($direction === 'out') {
                $this->assertAvailable($supplierId, $unit, (int) $original['warehouse_id'], $original['location_id'], StockValuation::qtyToT((string) $original['quantity']));
            } elseif ($unit['tracking_type'] === 'serial' && $this->tracking->balance($supplierId, (int) $unit['id'], null, null, true) !== 0) {
                throw new StockException('serial_already_in_stock', 'Sériové číslo už je skladem.', 409, ['serial_number' => $unit['serial_number']]);
            }
            $this->tracking->insertAllocation(
                $supplierId,
                $counterLineId,
                (int) $original['stock_tracking_unit_id'],
                (int) $original['warehouse_id'],
                $original['location_id'],
                $direction,
                (string) $original['quantity'],
                (int) $original['id'],
            );
        }
    }

    public function canonicalAllocationsForLine(int $supplierId, int $lineId, ?string $direction = null): array
    {
        if ($direction !== null && !in_array($direction, ['in', 'out'], true)) {
            throw new \InvalidArgumentException('Neplatný směr skladové alokace.');
        }
        $result = [];
        foreach ($this->tracking->allocationsForLine($supplierId, $lineId) as $allocation) {
            if ($direction !== null && $allocation['direction'] !== $direction) continue;
            $result[] = [
                'stock_tracking_unit_id' => (int) $allocation['stock_tracking_unit_id'],
                'quantity' => (string) $allocation['quantity'],
                'serial_number' => $allocation['serial_number'],
                'lot_code' => $allocation['lot_code'],
                'expires_on' => $allocation['expires_on'],
                'warehouse_id' => (int) $allocation['warehouse_id'],
                'location_id' => $allocation['location_id'],
                'direction' => $allocation['direction'],
            ];
        }
        return $result;
    }

    private function resolveUnit(int $supplierId, int $itemId, string $mode, array $entry, bool $allowCreate): array
    {
        $id = (int) ($entry['stock_tracking_unit_id'] ?? 0);
        if ($id > 0) {
            $unit = $this->tracking->findUnit($supplierId, $itemId, $id, true);
            if ($unit === null || $unit['tracking_type'] !== $mode) throw new StockException('tracking_not_found', 'Sledovaná jednotka nenalezena pro tuto kartu.', 422);
            return $unit;
        }
        $key = $mode === 'serial'
            ? 'serial:' . (string) $entry['serial_number']
            : 'lot:' . (string) $entry['lot_code'] . '|' . (string) ($entry['expires_on'] ?? '');
        $unit = $this->tracking->findUnitByKey($supplierId, $itemId, $key, true);
        if ($unit !== null) return $unit;
        if (!$allowCreate) throw new StockException('tracking_not_found', 'Sériové číslo nebo šarže nejsou na kartě evidovány.', 422);
        try {
            $newId = $this->tracking->insertUnit($supplierId, $itemId, $mode, $key, $entry['lot_code'], $entry['serial_number'], $entry['expires_on']);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') throw $e;
            $unit = $this->tracking->findUnitByKey($supplierId, $itemId, $key, true);
            if ($unit === null) throw $e;
            return $unit;
        }
        return $this->tracking->findUnit($supplierId, $itemId, $newId, true)
            ?? throw new StockException('tracking_not_found', 'Sledovanou jednotku se nepodařilo vytvořit.', 500);
    }

    private function requireLocation(int $supplierId, mixed $locationId, int $warehouseId, bool $requireActive): ?int
    {
        if ($locationId === null) return null;
        $location = $this->tracking->location($supplierId, (int) $locationId, true);
        if ($location === null || (int) $location['warehouse_id'] !== $warehouseId || ($requireActive && !$location['is_active'])) {
            throw new StockException('invalid_location', 'Lokace nepatří zvolenému skladu nebo není aktivní pro příjem.', 422, ['location_id' => (int) $locationId]);
        }
        return (int) $location['id'];
    }

    private function assertAvailable(int $supplierId, array $unit, int $warehouseId, ?int $locationId, int $requestedT): void
    {
        $availableT = $this->tracking->balance($supplierId, (int) $unit['id'], $warehouseId, $locationId);
        if ($availableT < $requestedT) {
            throw new StockException('tracking_insufficient_stock', 'Na zvolené šarži, sériovém čísle nebo lokaci není dostatečný stav.', 409, [
                'stock_tracking_unit_id' => (int) $unit['id'],
                'requested' => StockValuation::tToDecimal($requestedT),
                'available' => StockValuation::tToDecimal($availableT),
            ]);
        }
    }

    private static function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function isDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
