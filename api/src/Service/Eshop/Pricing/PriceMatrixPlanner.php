<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Repository\StockItemPriceRepository;

final class PriceMatrixPlanner
{
    public function __construct(
        private readonly StockItemPriceRepository $prices,
        private readonly PriceWriteService $writer,
        private readonly PriceCalculationPlanner $calculation,
    ) {}

    /** @return array{before:array<string,mixed>,after:array<string,mixed>,changed:bool} */
    public function preview(int $supplierId, array $item, array $request, PricingSnapshot $snapshot): array
    {
        $rows = $this->prices->listForItem($supplierId, (int) $item['id']);
        $byCurrency = [];
        foreach ($rows as $row) {
            $byCurrency[(string) $row['currency_code']] = $row;
        }
        $upserts = [];
        $deletes = [];
        foreach ($request['currencies'] as $currency) {
            $current = $byCurrency[$currency] ?? null;
            $override = $request['overrides'][(int) $item['id'] . ':' . $currency] ?? null;
            if ($override !== null) {
                $operation = $override['operation'];
                if ($operation === 'delete') {
                    if ($current !== null) {
                        $deletes[] = $currency;
                        unset($byCurrency[$currency]);
                    }
                    continue;
                }
                $definition = match ($operation) {
                    'lock_current' => $this->lockCurrent($current),
                    'set_fixed' => [
                        'currency_code' => $currency,
                        'price_mode' => 'fixed',
                        'markup_pct' => null,
                        'fixed_price' => $override['fixed_price'],
                        'rounding' => $override['rounding'] ?? ($current['rounding'] ?? 'none'),
                        'is_manual_override' => true,
                        'use_pricing_rules' => false,
                    ],
                    'unlock_to_rules' => $this->rulesDefinition($currency, $current),
                    'upsert' => ['currency_code' => $currency] + $override['definition'],
                    default => throw new \InvalidArgumentException('Neplatná operace cenové výjimky.'),
                };
                $upserts[$currency] = $definition;
                $byCurrency[$currency] = $this->prospectiveRow($current, $definition, $item);
                continue;
            }
            if ($current === null && $request['ensure_missing']) {
                $definition = $this->rulesDefinition($currency, null);
                $upserts[$currency] = $definition;
                $byCurrency[$currency] = $this->prospectiveRow(null, $definition, $item);
            }
        }

        $calculateCurrencies = $request['reprice'] ? $request['currencies'] : array_keys($upserts);
        $calculateRows = array_intersect_key($byCurrency, array_flip($calculateCurrencies));
        $plans = $this->calculation->plan($supplierId, $item, array_values($calculateRows), $snapshot->onDate, $snapshot);
        $planned = [];
        foreach ($plans as $plan) {
            $planned[(string) $plan['currency_code']] = $plan;
        }
        $beforeCells = [];
        $afterCells = [];
        foreach ($request['currencies'] as $currency) {
            $current = null;
            foreach ($rows as $row) {
                if ($row['currency_code'] === $currency) {
                    $current = $row;
                    break;
                }
            }
            $beforeCells[$currency] = $this->cell($current);
            $hasWrite = isset($upserts[$currency]) || in_array($currency, $deletes, true);
            $afterCells[$currency] = in_array($currency, $deletes, true)
                ? null
                : $this->cell(!$hasWrite && !$request['reprice'] ? $current : ($planned[$currency] ?? null));
        }
        $writeRows = array_values($this->writer->normalizeRows(array_values($upserts)));
        foreach ($writeRows as $index => $row) {
            $row['currency_code'] = array_keys($upserts)[$index];
            $writeRows[$index] = $row;
        }
        $before = $this->state($item, $beforeCells);
        $after = $this->state($item, $afterCells);
        $issues = [];
        foreach ($request['currencies'] as $currency) {
            $beforeCell = $beforeCells[$currency];
            $afterCell = $afterCells[$currency];
            $cellIssues = [];
            if ($afterCell === null || $afterCell['price'] === null) {
                $cellIssues[] = 'missing_price';
            }
            if (($afterCell['is_manual_override'] ?? false) === true) {
                $cellIssues[] = 'manual_override';
            }
            $delta = $this->deviation($beforeCell['price'] ?? null, $afterCell['price'] ?? null);
            if ($afterCell !== null) {
                $after['cells'][$currency]['deviation_pct'] = $delta;
            }
            if ($delta !== null && bccomp($delta, $request['deviation_threshold_pct'], 3) >= 0) {
                $cellIssues[] = 'deviation';
            }
            if ($cellIssues !== []) {
                $issues[$currency] = $cellIssues;
            }
        }
        $after['issues'] = $issues;
        $after['write'] = ['rows' => $writeRows, 'delete_currencies' => $deletes];
        return ['before' => $before, 'after' => $after, 'changed' => $beforeCells !== $afterCells];
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $currencies */
    public function stateFromRows(array $item, array $rows, array $currencies): array
    {
        $byCurrency = [];
        foreach ($rows as $row) {
            $byCurrency[(string) $row['currency_code']] = $row;
        }
        $cells = [];
        foreach ($currencies as $currency) {
            $cells[$currency] = $this->cell($byCurrency[$currency] ?? null);
        }
        return $this->state($item, $cells);
    }

    /** @param list<string> $currencies */
    public function currentState(int $supplierId, array $item, array $currencies): array
    {
        return $this->stateFromRows(
            $item,
            $this->prices->listForItem($supplierId, (int) $item['id']),
            $currencies,
        );
    }

    private function rulesDefinition(string $currency, ?array $current): array
    {
        return [
            'currency_code' => $currency,
            'price_mode' => $current['price_mode'] ?? 'markup',
            'markup_pct' => $current['markup_pct'] ?? '0',
            'fixed_price' => null,
            'rounding' => $current['rounding'] ?? 'none',
            'is_manual_override' => false,
            'use_pricing_rules' => true,
        ];
    }

    private function lockCurrent(?array $current): array
    {
        if ($current === null || ($current['computed_price'] ?? $current['fixed_price'] ?? null) === null) {
            throw new PricingInputException('missing_current_price');
        }
        return [
            'currency_code' => (string) $current['currency_code'],
            'price_mode' => 'fixed',
            'markup_pct' => null,
            'fixed_price' => (string) ($current['computed_price'] ?? $current['fixed_price']),
            'rounding' => (string) $current['rounding'],
            'is_manual_override' => true,
            'use_pricing_rules' => false,
        ];
    }

    private function prospectiveRow(?array $current, array $definition, array $item): array
    {
        return array_replace([
            'id' => 0,
            'supplier_id' => (int) $item['supplier_id'],
            'stock_item_id' => (int) $item['id'],
            'computed_price' => null,
            'computed_base' => null,
            'computed_rate' => null,
        ], $current ?? [], $definition);
    }

    private function state(array $item, array $cells): array
    {
        return [
            'id' => (int) $item['id'],
            'sku' => (string) $item['sku'],
            'name' => (string) $item['name'],
            'row_version' => (int) $item['row_version'],
            'cells' => $cells,
        ];
    }

    private function cell(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $details = $row['details'] ?? null;
        $context = $details['context'] ?? $row['computed_context'] ?? [];
        $cost = $context['cost'] ?? null;
        $price = $row['computed_price'] ?? null;
        $rate = $row['computed_rate'] ?? ($row['currency_code'] === 'CZK' ? '1' : null);
        $base = $row['computed_base'] ?? ($cost['base_czk'] ?? null);
        $margin = null;
        if ($price !== null && $rate !== null && $base !== null) {
            $saleCzk = bcmul((string) $price, (string) $rate, 8);
            if (bccomp($saleCzk, '0', 8) > 0) {
                $margin = bcdiv(bcmul(bcsub($saleCzk, (string) $base, 8), '100', 8), $saleCzk, 3);
            }
        }
        return [
            'currency_code' => (string) $row['currency_code'],
            'price_mode' => (string) $row['price_mode'],
            'markup_pct' => $row['markup_pct'],
            'fixed_price' => $row['fixed_price'],
            'rounding' => (string) $row['rounding'],
            'is_manual_override' => (bool) $row['is_manual_override'],
            'use_pricing_rules' => (bool) $row['use_pricing_rules'],
            'price' => $price,
            'cost_czk' => $base,
            'margin_pct' => $margin,
            'rate' => $rate,
            'rate_date' => is_array($details) ? ($details['rate_date'] ?? null) : ($row['computed_rate_date'] ?? null),
            'rate_source' => is_array($details) ? ($details['rate_source'] ?? null) : ($row['computed_rate_source'] ?? null),
            'profile_id' => is_array($details) ? ($details['profile_id'] ?? null) : ($row['computed_profile_id'] ?? null),
            'rule_id' => is_array($details) ? ($details['rule_id'] ?? null) : ($row['computed_rule_id'] ?? null),
            'cost_source' => is_array($details) ? ($details['cost_source'] ?? null) : ($row['computed_cost_source'] ?? null),
        ];
    }

    private function deviation(?string $before, ?string $after): ?string
    {
        if ($before === null || $after === null) {
            return null;
        }
        if (bccomp($before, '0', 8) === 0) {
            return bccomp($after, '0', 8) === 0 ? '0.000' : '100.000';
        }
        $delta = bcdiv(bcmul(bcsub($after, $before, 8), '100', 8), $before, 3);
        return str_starts_with($delta, '-') ? substr($delta, 1) : $delta;
    }
}
