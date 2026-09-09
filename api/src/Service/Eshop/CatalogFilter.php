<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Repository\StockItemRepository;

final class CatalogFilter
{
    private const FILTER_KEYS = ['type', 'active', 'q', 'only_below_min', 'warehouse_id', 'manufacturer_id', 'category_id', 'vendor_id', 'tag_ids', 'missing', 'availability', 'qty_min', 'qty_max', 'attribute_filters', 'sort', 'direction', 'export_eshop'];
    private const TRANSPORT_KEYS = ['page', 'per_page', 'limit', 'offset', 'supplier_id'];

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function normalize(array $input): array
    {
        self::rejectUnknown($input);
        $out = [];
        if (self::nonEmpty($input['type'] ?? null)) {
            $type = self::string($input['type'], 'type');
            if (!in_array($type, ['material', 'goods', 'product'], true)) {
                throw new \InvalidArgumentException('Neznámý typ skladové karty.');
            }
            $out['type'] = $type;
        }
        if (self::nonEmpty($input['q'] ?? null)) {
            $out['q'] = self::string($input['q'], 'q');
        }
        foreach (['active', 'export_eshop'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== '') {
                $out[$key] = self::bool($input[$key], $key);
            }
        }
        if (self::nonEmpty($input['only_below_min'] ?? null)) {
            $out['only_below_min'] = self::bool($input['only_below_min'], 'only_below_min');
        }
        foreach (['warehouse_id', 'manufacturer_id', 'category_id', 'vendor_id'] as $key) {
            if (self::nonEmpty($input[$key] ?? null)) {
                $out[$key] = self::positiveInt($input[$key], $key);
            }
        }
        if (self::nonEmpty($input['tag_ids'] ?? null)) {
            $out['tag_ids'] = self::ids($input['tag_ids']);
        }
        if (self::nonEmpty($input['missing'] ?? null)) {
            $missing = self::strings($input['missing'], 'missing');
            if (array_diff($missing, StockItemRepository::MISSING_FIELDS) !== []) { throw new \InvalidArgumentException('Neznámý filtr chybějících údajů.'); }
            $out['missing'] = $missing;
        }
        if (self::nonEmpty($input['availability'] ?? null)) {
            $availability = self::string($input['availability'], 'availability');
            if (!in_array($availability, StockItemRepository::AVAILABILITY_FILTERS, true)) { throw new \InvalidArgumentException('Neznámý filtr dostupnosti.'); }
            $out['availability'] = $availability;
        }
        foreach (['qty_min', 'qty_max'] as $key) { if (self::nonEmpty($input[$key] ?? null)) { if (!is_scalar($input[$key]) || !is_numeric($input[$key])) { throw new \InvalidArgumentException('Množstevní filtr musí být číslo.'); } $out[$key] = (string) $input[$key]; } }
        if (self::nonEmpty($input['attribute_filters'] ?? null)) { $out['attribute_filters'] = self::attributes($input['attribute_filters']); }
        if (self::nonEmpty($input['sort'] ?? null)) { $sort = self::string($input['sort'], 'sort'); if (!in_array($sort, StockItemRepository::SORT_FIELDS, true)) { throw new \InvalidArgumentException('Neznámý sloupec řazení.'); } $out['sort'] = $sort; }
        if (self::nonEmpty($input['direction'] ?? null)) { $direction = strtolower(self::string($input['direction'], 'direction')); if (!in_array($direction, ['asc', 'desc'], true)) { throw new \InvalidArgumentException('Neznámý směr řazení.'); } $out['direction'] = $direction; }
        return $out;
    }

    /** @param array<string,mixed> $input */
    private static function rejectUnknown(array $input): void
    {
        foreach ($input as $key => $value) {
            if (!in_array($key, self::FILTER_KEYS, true)
                && !in_array($key, self::TRANSPORT_KEYS, true)
                && self::nonEmpty($value)) {
                throw new \InvalidArgumentException('Neznámý filtr katalogu.');
            }
        }
    }
    private static function nonEmpty(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    private static function string(mixed $value, string $key): string
    {
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException("Filtr {$key} musí být řetězec.");
        }
        return (string) $value;
    }

    private static function bool(mixed $value, string $key): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ((is_int($value) && ($value === 0 || $value === 1))
            || (is_string($value) && in_array($value, ['0', '1'], true))) {
            return (bool) $value;
        }
        throw new \InvalidArgumentException("Filtr {$key} musí být boolean.");
    }

    private static function positiveInt(mixed $value, string $key): int
    {
        if (!(is_int($value) || is_string($value)) || !ctype_digit((string) $value)) {
            throw new \InvalidArgumentException("Filtr {$key} musí být kladné celé číslo.");
        }
        $integer = filter_var((string) $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($integer === false) {
            throw new \InvalidArgumentException("Filtr {$key} musí být kladné celé číslo.");
        }
        return $integer;
    }
    /** @return list<int> */
    private static function ids(mixed $value): array
    {
        $values = is_string($value) ? explode(',', $value) : $value;
        if (!is_array($values) || !array_is_list($values)) {
            throw new \InvalidArgumentException('Identifikátory štítků musí být kladná celá čísla.');
        }
        $ids = [];
        foreach ($values as $id) {
            $ids[] = self::positiveInt(is_string($id) ? trim($id) : $id, 'tag_ids');
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > 100) {
            throw new \InvalidArgumentException('Filtr štítků smí obsahovat nejvýše 100 identifikátorů.');
        }
        return $ids;
    }
    /** @return list<string> */
    private static function strings(mixed $value, string $key): array
    {
        $values = is_string($value) ? explode(',', $value) : $value;
        if (!is_array($values) || !array_is_list($values)) {
            throw new \InvalidArgumentException("Filtr {$key} musí být seznam.");
        }
        $out = [];
        foreach ($values as $item) {
            $item = trim(self::string($item, $key));
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return list<array<string,mixed>> */
    private static function attributes(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \InvalidArgumentException('Filtr atributů nemá platný JSON formát.');
            }
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
            throw new \InvalidArgumentException('Filtr atributů má neplatnou strukturu.');
        }
        $allowed = ['attribute_id', 'option_id', 'value_text', 'value_bool', 'value_num_min', 'value_num_max'];
        foreach ($value as $filter) {
            if (!is_array($filter) || array_diff(array_keys($filter), $allowed) !== [] || !array_key_exists('attribute_id', $filter)) {
                throw new \InvalidArgumentException('Filtr atributů má neplatnou strukturu.');
            }
            self::positiveInt($filter['attribute_id'], 'attribute_id');
            if (isset($filter['option_id'])) {
                self::positiveInt($filter['option_id'], 'option_id');
            }
            if (array_key_exists('value_text', $filter) && !is_scalar($filter['value_text'])) {
                throw new \InvalidArgumentException('Filtr atributů má neplatnou strukturu.');
            }
            foreach (['value_num_min', 'value_num_max'] as $key) {
                if (isset($filter[$key]) && (!is_scalar($filter[$key]) || !is_numeric($filter[$key]))) {
                    throw new \InvalidArgumentException('Filtr atributů má neplatnou strukturu.');
                }
            }
            if (array_key_exists('value_bool', $filter) && !is_bool($filter['value_bool'])) {
                throw new \InvalidArgumentException('Filtr atributů má neplatnou strukturu.');
            }
        }
        return $value;
    }
}
