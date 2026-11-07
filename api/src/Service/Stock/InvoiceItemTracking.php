<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

/**
 * Vybraná sériová čísla a šarže na řádku faktury (`invoice_items.tracking_json`,
 * migrace 2016).
 *
 * API řádku nese `tracking_allocations` ve stejném tvaru jako řádek skladového dokladu
 * ({@see TrackingAllocationService::normalizeDraftInput()}), ale ukládá se jen identita
 * kusu a množství v základní jednotce. Bez `stock_tracking_unit_id` a umístění: id se
 * při obnově archivu do jiné firmy mění a kus se mezitím může ve skladu přestěhovat,
 * umístění proto dohledá až výdej podle aktuální zásoby kusu.
 * Úplnost výběru (součet = množství řádku) a dostupnost kusů se kontroluje až při
 * vystavení, rozepsaný koncept smí mít výběr neúplný.
 *
 * Tři vstupy, tři metody: tělo požadavku → {@see normalizeItems()} (ověří), řádek načtený
 * z databáze → {@see storedJson()} / {@see fromStored()} (už ověřený), zápis v
 * `replaceItems()` → {@see payloadJson()} (jen klíč `tracking_json` z jedné z nich).
 */
final class InvoiceItemTracking
{
    private const STORED_KEYS = ['quantity', 'serial_number', 'lot_code', 'expires_on'];

    public function __construct(private readonly TrackingAllocationService $allocations) {}

    /**
     * Položky z těla požadavku: `tracking_allocations` → ověřený `tracking_json`.
     * `tracking_json` poslaný klientem se zahodí, jinak by obešel validaci.
     *
     * @param array<int,mixed> $items
     * @return array<int,mixed>
     * @throws StockException
     */
    public function normalizeItems(int $supplierId, array $items): array
    {
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $raw = $item['tracking_allocations'] ?? null;
            unset($item['tracking_allocations'], $item['tracking_json']);
            $item['tracking_json'] = $this->normalize($supplierId, $item, $raw, (int) $index);
            $items[$index] = $item;
        }
        return $items;
    }

    /**
     * Hodnota pro zápis řádku: jen `tracking_json` připravený {@see normalizeItems()} nebo
     * {@see fromStored()}. Surové `tracking_allocations` se tu nikdy nekódují, jinak by
     * budoucí volající `replaceItems()` uložil neověřený vstup.
     *
     * @param array<string,mixed> $item
     */
    public static function payloadJson(array $item): ?string
    {
        $json = $item['tracking_json'] ?? null;
        return is_string($json) && $json !== '' ? $json : null;
    }

    /**
     * Výběr řádku načteného z databáze (`tracking_allocations` z castItem), už ověřený.
     *
     * @param array<string,mixed> $storedItem
     */
    public static function storedJson(array $storedItem): ?string
    {
        $allocations = $storedItem['tracking_allocations'] ?? null;
        return is_array($allocations) && $allocations !== [] ? self::encode($allocations) : null;
    }

    /**
     * Uložené řádky připravené k novému zápisu (přehrání při změně slevy apod.).
     *
     * @param array<int,mixed> $items
     * @return array<int,mixed>
     */
    public static function fromStored(array $items): array
    {
        foreach ($items as $index => $item) {
            if (is_array($item)) {
                $item['tracking_json'] = self::storedJson($item);
                unset($item['tracking_allocations']);
                $items[$index] = $item;
            }
        }
        return $items;
    }

    /**
     * Otisk výběru kusů dokladu: karta + výběr u řádků, které nějaký výběr mají, seřazený.
     * Přijímá řádky z požadavku i z databáze. Ostatní skladové změny vystaveného dokladu
     * (karta, množství) hlídá jiná kontrola, tady jde jen o konkrétní kusy.
     *
     * @param array<int,mixed> $items
     * @return list<string>
     */
    public static function signature(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_numeric($item['stock_item_id'] ?? null) || (int) $item['stock_item_id'] <= 0) {
                continue;
            }
            $json = array_key_exists('tracking_json', $item) ? self::payloadJson($item) : self::storedJson($item);
            if ($json !== null) {
                $out[] = (int) $item['stock_item_id'] . '|' . $json;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * @return list<array{quantity:string, serial_number:?string, lot_code:?string, expires_on:?string}>
     */
    public static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'quantity' => (string) ($row['quantity'] ?? '0'),
                'serial_number' => isset($row['serial_number']) ? (string) $row['serial_number'] : null,
                'lot_code' => isset($row['lot_code']) ? (string) $row['lot_code'] : null,
                'expires_on' => isset($row['expires_on']) ? (string) $row['expires_on'] : null,
            ];
        }
        return $out;
    }

    /**
     * JSON výběru z kusů, které výdejka skutečně vydala
     * ({@see \MyInvoice\Repository\StockTrackingRepository::issuedUnitsByInvoiceItem()}).
     *
     * @param list<array<string,mixed>> $units
     */
    public static function fromIssuedUnits(array $units): string
    {
        return self::encode($units);
    }

    /** @param array<int,mixed> $allocations */
    private static function encode(array $allocations): string
    {
        $rows = [];
        foreach ($allocations as $row) {
            if (is_array($row)) {
                $rows[] = array_intersect_key($row, array_flip(self::STORED_KEYS));
            }
        }
        return json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,mixed> $item */
    private function normalize(int $supplierId, array $item, mixed $raw, int $index): ?string
    {
        if ($raw === null || $raw === []) {
            return null;
        }
        if (!is_array($raw)) {
            throw new StockException('invalid_tracking_allocations', 'Výběr sériových čísel nebo šarží musí být seznam.', 422, ['index' => $index]);
        }
        $stockItemId = is_numeric($item['stock_item_id'] ?? null) ? (int) $item['stock_item_id'] : 0;
        if ($stockItemId <= 0) {
            throw new StockException('tracking_not_enabled', 'Sériová čísla nebo šarže jde vybrat jen u řádku se skladovou kartou.', 422, ['index' => $index]);
        }
        try {
            $json = $this->allocations->normalizeDraftInput($supplierId, $stockItemId, $raw);
        } catch (StockException $e) {
            throw new StockException($e->errorCode, $e->getMessage(), $e->httpStatus, ['index' => $index] + $e->details);
        }
        return $json === null ? null : self::encode((array) json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }
}
