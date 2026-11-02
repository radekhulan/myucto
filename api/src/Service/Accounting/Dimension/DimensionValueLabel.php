<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

/**
 * Popisek hodnoty dimenze ve výstupech serveru (štítky seznamů, uzávěrka, AI návrh).
 *
 * Systémová hodnota (má `semantic_key`) nese v kódu interní identifikátor
 * (`economic`, `unit:12`), proto se u ní ukazuje jen název. Jednotky a služby
 * registru domů ale mohou mít stejný název (jednotka 1 ve dvou domech). V seznamu
 * hodnot se proto systémové hodnotě se stejným popiskem jako jiná hodnota přidá
 * za název kód v závorce, jinak by výběry i sloupce exportů splynuly.
 * Stejné pravidlo drží frontend v `web/src/utils/dimensionValueLabel.ts`.
 */
final class DimensionValueLabel
{
    /** @param array{code?: mixed, name?: mixed, semantic_key?: mixed} $value Krátký popisek: kód, bez kódu název. */
    public static function short(array $value): string
    {
        $code = trim((string) ($value['code'] ?? ''));
        return self::isSystem($value) || $code === '' ? (string) ($value['name'] ?? '') : $code;
    }

    /** @param array{code?: mixed, name?: mixed, semantic_key?: mixed} $value Kód a název. */
    public static function full(array $value): string
    {
        $name = (string) ($value['name'] ?? '');
        $code = trim((string) ($value['code'] ?? ''));
        return self::isSystem($value) || $code === '' ? $name : trim($code . ' ' . $name);
    }

    /**
     * Krátké popisky hodnot jednoho seznamu (jednoho typu), klíče zachované.
     *
     * @template K of array-key
     * @param array<K, array{code?: mixed, name?: mixed, semantic_key?: mixed}> $values
     * @return array<K, string>
     */
    public static function shortAll(array $values): array
    {
        return self::distinct($values, array_map(self::short(...), $values));
    }

    /**
     * Popisky „kód název" hodnot jednoho seznamu (jednoho typu), klíče zachované.
     *
     * @template K of array-key
     * @param array<K, array{code?: mixed, name?: mixed, semantic_key?: mixed}> $values
     * @return array<K, string>
     */
    public static function fullAll(array $values): array
    {
        return self::distinct($values, array_map(self::full(...), $values));
    }

    /**
     * @param array<array-key, array{code?: mixed, name?: mixed, semantic_key?: mixed}> $values
     * @param array<array-key, string> $labels
     * @return array<array-key, string>
     */
    private static function distinct(array $values, array $labels): array
    {
        $counts = array_count_values(array_map('strval', $labels));
        foreach ($labels as $key => $label) {
            $code = trim((string) ($values[$key]['code'] ?? ''));
            if ($counts[$label] > 1 && $code !== '' && self::isSystem($values[$key])) {
                $labels[$key] = trim($label . ' (' . $code . ')');
            }
        }
        return $labels;
    }

    private static function isSystem(array $value): bool
    {
        return ($value['semantic_key'] ?? null) !== null && $value['semantic_key'] !== '';
    }
}
