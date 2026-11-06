<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\Invoice\TimeBilling;
use MyInvoice\Service\Invoice\UnitPricePrecision;

final class TimeBillingPdfPresenter
{
    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function purchaseInvoiceItems(array $items, bool $pricesIncludeVat): array
    {
        $items = array_map(static function (array $item) use ($pricesIncludeVat): array {
            $quantity = (float) ($item['quantity'] ?? 1);
            $base = (float) ($item['total_without_vat'] ?? 0);
            $gross = (float) ($item['total_with_vat'] ?? 0);
            $rawUnitPrice = (float) ($item['unit_price_without_vat'] ?? 0);

            return [
                'description' => $item['description'] ?? '',
                'stock_item_id' => isset($item['stock_item_id']) ? (int) $item['stock_item_id'] : null,
                'quantity' => $quantity,
                'duration_minutes' => array_key_exists('duration_minutes', $item) && $item['duration_minutes'] !== null
                    ? (int) $item['duration_minutes']
                    : null,
                'unit' => $item['unit'] ?? 'ks',
                'unit_price_without_vat' => $rawUnitPrice,
                'vat_rate' => (float) ($item['vat_rate_snapshot'] ?? $item['vat_rate'] ?? 0),
                'total_without_vat' => $base,
                'total_with_vat' => $gross,
                'line_total' => $pricesIncludeVat ? $gross : $base,
            ];
        }, $items);

        return self::invoiceItems($items, $pricesIncludeVat);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function invoiceItems(array $items, bool $pricesIncludeVat): array
    {
        foreach ($items as &$item) {
            $minutes = TimeBilling::durationMinutes($item);
            $quantity = $minutes === null
                ? (float) ($item['quantity'] ?? 0)
                : $minutes / 60;
            $rawUnitPrice = (float) ($item['unit_price_without_vat'] ?? 0);
            $isPrecise = $minutes !== null || UnitPricePrecision::hasSubCentPrecision($rawUnitPrice);
            $unitPrice = $rawUnitPrice;
            if ($pricesIncludeVat && $quantity != 0.0) {
                $unitPrice = round(
                    (float) ($item['total_without_vat'] ?? 0) / $quantity,
                    $isPrecise ? UnitPricePrecision::DECIMALS : UnitPricePrecision::MIN_DISPLAY_DECIMALS,
                );
            }

            $item['duration_display'] = $minutes === null ? null : self::duration($minutes);
            $item['pdf_unit_price'] = $unitPrice;
            $item['unit_price_decimals'] = $isPrecise
                ? UnitPricePrecision::decimalPlaces($unitPrice)
                : UnitPricePrecision::MIN_DISPLAY_DECIMALS;
        }
        unset($item);

        return $items;
    }

    /** @param array<string,mixed> $report */
    public static function workReport(array $report): array
    {
        $items = (array) ($report['items'] ?? []);
        $allExact = $items !== [];
        $totalMinutes = 0;

        foreach ($items as &$item) {
            $minutes = TimeBilling::durationMinutes($item);
            $item['duration_display'] = $minutes === null ? null : self::duration($minutes);
            $rate = (float) ($item['rate'] ?? 0);
            if (abs($rate - round($rate, 2)) > 0.0000001) {
                $item['rate_decimals'] = UnitPricePrecision::decimalPlaces($rate);
            } else {
                unset($item['rate_decimals']);
            }
            if ($minutes === null) {
                $allExact = false;
            } else {
                $totalMinutes += $minutes;
            }
        }
        unset($item);

        $report['items'] = $items;
        $report['total_duration_display'] = $allExact ? self::duration($totalMinutes) : null;
        $report['material_price_decimals'] = self::materialPriceDecimals((array) ($report['materials'] ?? []));
        return $report;
    }

    /**
     * Míst u ceny za jednotku materiálu: celé ceny bez desetin, jinak dvě a víc jen
     * tehdy, když některá cena skutečně má víc míst. Součty materiálu se řídí zvlášť.
     *
     * @param list<array<string,mixed>> $materials
     */
    private static function materialPriceDecimals(array $materials): int
    {
        $decimals = 0;
        foreach ($materials as $material) {
            $price = (float) ($material['unit_price'] ?? 0);
            if ($price != round($price)) {
                $decimals = max($decimals, UnitPricePrecision::decimalPlaces($price));
            }
        }
        return $decimals;
    }

    private static function duration(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $absolute = abs($minutes);
        return sprintf('%s%d:%02d', $sign, intdiv($absolute, 60), $absolute % 60);
    }
}
