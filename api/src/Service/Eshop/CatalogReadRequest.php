<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

final class CatalogReadRequest
{
    public const FIELDS = ['sku', 'name', 'item_type', 'unit', 'ean', 'manufacturer_id', 'vat_rate_id', 'is_active', 'is_stocked', 'export_eshop', 'min_qty', 'weight_g', 'warranty_months', 'delivery_days', 'i18n', 'categories', 'tag_ids', 'attributes', 'fees', 'media', 'prices', 'availability', 'costs', 'master', 'variant', 'effective', 'relations'];

    public static function products(array $body): array
    {
        self::keys($body, ['ids', 'fields', 'locales', 'currencies', 'warehouse_ids']);
        $ids = self::ids($body['ids'] ?? null, 500);
        $fields = self::strings($body['fields'] ?? ['sku', 'name', 'ean', 'is_active'], count(self::FIELDS));
        if (array_diff($fields, self::FIELDS) !== []) {
            throw new \InvalidArgumentException('Neznámá projekce katalogu.');
        }
        $locales = self::strings($body['locales'] ?? ['cs'], 20);
        foreach ($locales as $locale) {
            if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $locale)) {
                throw new \InvalidArgumentException('Neplatný jazyk.');
            }
        }
        $currencies = self::strings($body['currencies'] ?? ['CZK'], 10);
        foreach ($currencies as &$currency) {
            $currency = self::currency($currency);
        }
        unset($currency);
        return ['ids' => $ids, 'fields' => $fields, 'locales' => $locales, 'currencies' => array_values(array_unique($currencies)),
            'warehouse_ids' => isset($body['warehouse_ids']) ? self::ids($body['warehouse_ids'], 50) : []];
    }

    public static function prices(array $body): array
    {
        self::keys($body, ['items', 'currency', 'on_date']);
        $items = self::list($body['items'] ?? null, 500);
        $quantities = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Neplatná položka cenového dotazu.');
            }
            self::keys($item, ['id', 'qty']);
            $id = self::ids([$item['id'] ?? null], 1)[0];
            $qty = $item['qty'] ?? '1';
            if (!is_string($qty) || !preg_match('/^\d{1,11}(?:\.\d{1,3})?$/D', $qty) || bccomp($qty, '0', 3) <= 0 || isset($quantities[$id])) {
                throw new \InvalidArgumentException('Množství musí být kladné desetinné číslo a ID se nesmí opakovat.');
            }
            $quantities[$id] = bcadd($qty, '0', 3);
        }
        $date = $body['on_date'] ?? date('Y-m-d');
        if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new \InvalidArgumentException('Neplatné datum cenového dotazu.');
        }
        return ['quantities' => $quantities, 'currency' => self::currency($body['currency'] ?? 'CZK'), 'on_date' => $date];
    }

    private static function keys(array $body, array $allowed): void
    {
        if (array_diff(array_keys($body), $allowed) !== []) {
            throw new \InvalidArgumentException('Neznámé pole dávkového dotazu.');
        }
    }

    private static function list(mixed $value, int $maximum): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > $maximum) {
            throw new \InvalidArgumentException('Prázdná dávka nebo překročený limit ' . $maximum . '.');
        }
        return $value;
    }

    private static function ids(mixed $value, int $maximum): array
    {
        $ids = self::list($value, $maximum);
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('ID musí být kladné celé číslo.');
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new \InvalidArgumentException('ID se nesmí opakovat.');
        }
        return $ids;
    }

    private static function strings(mixed $value, int $maximum): array
    {
        $values = self::list($value, $maximum);
        foreach ($values as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new \InvalidArgumentException('Neplatný seznam hodnot.');
            }
        }
        return array_values(array_unique($values));
    }

    private static function currency(mixed $currency): string
    {
        if (!is_string($currency) || !preg_match('/^[A-Za-z]{3}$/D', $currency)) {
            throw new \InvalidArgumentException('Neplatná měna.');
        }
        return strtoupper($currency);
    }
}
