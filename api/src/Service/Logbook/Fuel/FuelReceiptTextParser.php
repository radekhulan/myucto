<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\Fuel;

/**
 * Vytáhne údaje o tankování z volného textu účtenky nebo popisu pokladního dokladu
 * („Nafta 42,5 l á 38,90, tach. 123 456, 1AB 2345").
 *
 * Nic nedomýšlí: co v textu není, zůstane null. Vytěžená účtenka (AI) dodá tatáž pole
 * strukturovaně; tenhle parser je záloha pro doklady, které mají jen popis.
 */
final class FuelReceiptTextParser
{
    /** Kanonický popis paliva podle klíčového slova (pořadí = priorita). */
    private const FUEL_LABELS = [
        'adblue'          => 'AdBlue',
        'ad blue'         => 'AdBlue',
        'lpg'             => 'LPG',
        'cng'             => 'CNG',
        'premiova nafta'  => 'Nafta',
        'motorova nafta'  => 'Nafta',
        'nafta'           => 'Nafta',
        'diesel'          => 'Nafta',
        'natural'         => 'Benzín',
        'benzin'          => 'Benzín',
        'super'           => 'Benzín',
        'nabijeni'        => 'Elektřina',
        'dobijeni'        => 'Elektřina',
        'kwh'             => 'Elektřina',
    ];

    /**
     * @return array{quantity:float|null, unit:string, unit_price:float|null, fuel_type:string|null,
     *               plate:string|null, odometer:int|null}
     */
    public static function parse(string $text): array
    {
        $raw = trim($text);
        $norm = FuelKeywords::normalize($raw);

        $unit = FuelKeywords::canonicalUnit(null, $raw);
        $quantity = null;
        if ($unit === 'kWh' && preg_match('/(\d{1,4}(?:[.,]\d{1,3})?)\s*kwh\b/i', $norm, $m)) {
            $quantity = self::number($m[1]);
        } elseif (preg_match('/(\d{1,4}(?:[.,]\d{1,3})?)\s*(?:l|lt|litr[uy]?|litru)\b(?!\s*\/)/u', $norm, $m)) {
            $quantity = self::number($m[1]);
        }

        $unitPrice = null;
        if (preg_match('/(\d{1,3}[.,]\d{1,4})\s*(?:kc|czk|eur)?\s*\/\s*(?:l|kwh)\b/i', $norm, $m)
            || preg_match('/\ba\s*(\d{1,3}[.,]\d{1,4})\b/', $norm, $m)
            || preg_match('/cena\s*(?:za)?\s*(?:l|litr|jednotku)\D{0,3}(\d{1,3}[.,]\d{1,4})/', $norm, $m)
        ) {
            $unitPrice = self::number($m[1]);
        }

        $odometer = null;
        if (preg_match('/(?:tach\w*|stav\s*km|km\s*stav|odometer|najeto)\D{0,4}(\d{1,3}(?:[ .\x{00A0}]?\d{3}){1,2})/u', $norm, $m)) {
            $odometer = (int) preg_replace('/\D/', '', $m[1]);
            if ($odometer <= 0) $odometer = null;
        }

        $plate = null;
        // Česká RZ: číslice + písmeno + (znak) + 4 číslice („1AB 2345", „2Z3 4567").
        if (preg_match('/\b(\d[A-Z][A-Z0-9])\s?-?(\d{4})\b/', strtoupper($raw), $m)) {
            $plate = $m[1] . ' ' . $m[2];
        }

        $fuelType = null;
        foreach (self::FUEL_LABELS as $keyword => $label) {
            if (preg_match('/(?:^|[^a-z])' . preg_quote($keyword, '/') . '/', $norm) === 1) {
                $fuelType = $label;
                break;
            }
        }

        return [
            'quantity'   => $quantity,
            'unit'       => $unit,
            'unit_price' => $unitPrice,
            'fuel_type'  => $fuelType,
            'plate'      => $plate,
            'odometer'   => $odometer,
        ];
    }

    private static function number(string $s): ?float
    {
        $v = str_replace(',', '.', $s);
        return is_numeric($v) ? (float) $v : null;
    }
}
