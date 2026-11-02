<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

/**
 * Popisek hodnoty dimenze ve výstupech serveru (štítky seznamů, uzávěrka, AI návrh).
 *
 * Systémová hodnota (má `semantic_key`) nese v kódu interní identifikátor
 * (`economic`, `unit:12`), proto se u ní ukazuje jen název. Stejné pravidlo drží
 * frontend v `web/src/utils/dimensionValueLabel.ts`.
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

    private static function isSystem(array $value): bool
    {
        return ($value['semantic_key'] ?? null) !== null && $value['semantic_key'] !== '';
    }
}
