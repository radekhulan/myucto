<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Sets;

use MyInvoice\Service\Eshop\EshopException;

final class ProductSetQuoteCalculator
{
    public function calculate(array $tree, array $definitions, array $prices, string $currency): array
    {
        $calculate = function (array $node) use (&$calculate, $definitions, $prices, $currency): array {
            $id = $node['item_id'];
            if ($node['children'] === []) {
                $price = $prices[$id] ?? null;
                if ($price === null) throw new EshopException('set_price_missing', 'Komponenta nemá platnou cenu ve zvolené měně.', 422, ['item_id' => $id, 'currency_code' => $currency]);
                $amount = self::round(bcmul(ProductSetDefinition::money($price), $node['quantity'], 5));
                return ['amount' => $amount, 'components' => [['item_id' => $id, 'quantity' => $node['quantity'], 'unit_price' => $price, 'amount' => $amount]]];
            }
            $components = [];
            $subtotal = '0.00';
            foreach ($node['children'] as $child) {
                $calculated = $calculate($child);
                $subtotal = bcadd($subtotal, $calculated['amount'], 2);
                array_push($components, ...$calculated['components']);
            }
            $policy = $definitions[$id]['prices'][$currency] ?? ['mode' => 'sum'];
            $surcharge = $node['surcharges'][$currency] ?? '0';
            $amount = match ($policy['mode']) {
                'fixed' => self::round(bcadd(bcmul($policy['fixed_price'], $node['quantity'], 5), $surcharge, 5)),
                'discount' => self::round(bcdiv(bcmul(bcadd($subtotal, $surcharge, 5), bcsub('100', $policy['discount_pct'], 3), 8), '100', 8)),
                default => self::round(bcadd($subtotal, $surcharge, 5)),
            };
            $weights = array_column($components, 'amount');
            if (bccomp(array_reduce($weights, static fn (string $sum, string $value): string => bcadd($sum, $value, 2), '0'), '0', 2) === 0) $weights = array_column($components, 'quantity');
            $allocated = self::allocate($amount, $weights);
            foreach ($components as $index => &$component) $component['amount'] = $allocated[$index];
            unset($component);
            return ['amount' => $amount, 'components' => $components];
        };
        return $calculate($tree);
    }

    public static function allocate(string $amount, array $weights): array
    {
        $total = array_reduce($weights, static fn (string $sum, string $value): string => bcadd($sum, $value, 6), '0');
        if (bccomp($total, '0', 6) <= 0) throw new EshopException('set_allocation_invalid', 'Cenu setu nelze rozdělit mezi komponenty.', 422);
        $cents = bcmul($amount, '100', 0);
        $remaining = $cents;
        $shares = [];
        $remainders = [];
        foreach ($weights as $index => $weight) {
            $numerator = bcmul($cents, $weight, 6);
            $shares[$index] = bcdiv($numerator, $total, 0);
            $remaining = bcsub($remaining, $shares[$index], 0);
            $remainders[$index] = bcsub($numerator, bcmul($shares[$index], $total, 6), 6);
        }
        uksort($remainders, static fn (int $a, int $b): int => bccomp($remainders[$b], $remainders[$a], 6) ?: ($a <=> $b));
        foreach (array_keys($remainders) as $index) {
            if (bccomp($remaining, '0', 0) <= 0) break;
            $shares[$index] = bcadd($shares[$index], '1', 0);
            $remaining = bcsub($remaining, '1', 0);
        }
        return array_map(static fn (string $share): string => bcdiv($share, '100', 2), $shares);
    }

    private static function round(string $amount): string
    {
        if (bccomp($amount, '999999999999.99', 8) > 0 || bccomp($amount, '0', 8) < 0) throw new EshopException('set_price_invalid', 'Cena setu překračuje podporovaný rozsah.', 422);
        return bcdiv(bcadd(bcmul($amount, '100', 8), '0.5', 0), '100', 2);
    }
}
