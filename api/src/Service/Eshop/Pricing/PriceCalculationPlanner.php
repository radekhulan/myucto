<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

final class PriceCalculationPlanner
{
    public function __construct(
        private readonly PurchaseCostResolver $costResolver,
        private readonly FxRateProvider $fx,
        private readonly PricingRuleResolver $ruleResolver,
    ) {}

    /** @return list<array<string,mixed>> */
    public function plan(
        int $supplierId,
        array $item,
        array $rows,
        string $onDate,
        ?PricingSnapshot $snapshot = null,
    ): array {
        $itemId = (int) $item['id'];
        $costs = [];
        $pricingContext = null;
        $plans = [];
        $outputAudit = [
            'calculation_date' => $onDate,
            'prices_include_vat' => false,
            'vat_rate_id' => $item['vat_rate_id'] ?? null,
        ];
        foreach ($rows as $row) {
            $currency = strtoupper((string) $row['currency_code']);
            if ($row['is_manual_override']) {
                $manual = $row['fixed_price'] !== null
                    ? PriceRounding::apply((string) $row['fixed_price'], (string) $row['rounding'])
                    : ($row['computed_price'] ?? null);
                $plans[] = array_replace($row, [
                    'persist' => $row['fixed_price'] !== null,
                    'computed_price' => $manual,
                    'computed_base' => null,
                    'computed_rate' => null,
                    'details' => [
                        'calculation_mode' => 'fixed',
                        'context' => [
                            'manual_override' => true,
                            'output' => ['currency_code' => $currency] + $outputAudit,
                        ],
                    ],
                ]);
                continue;
            }
            $mode = (string) $row['price_mode'];
            $rounding = (string) $row['rounding'];
            $percentage = $row['markup_pct'] !== null ? (string) $row['markup_pct'] : '0';
            $rule = null;
            $profile = null;
            $fxPolicy = null;
            if ($row['use_pricing_rules']) {
                $pricingContext ??= $this->ruleResolver->itemContext($supplierId, $itemId);
                $rule = $this->ruleResolver->resolve($supplierId, $itemId, $currency, $snapshot, $pricingContext);
                if ($rule === null) {
                    throw new PricingInputException('missing_pricing_rule', [
                        'item_id' => $itemId,
                        'currency_code' => $currency,
                    ]);
                }
                $profile = $rule['profile'];
                $mode = (string) $profile['calculation_mode'];
                $rounding = (string) $profile['rounding'];
                $percentage = (string) $profile['percentage'];
                $fxPolicy = ['source' => (string) $profile['fx_source'], 'max_age_days' => (int) $profile['max_rate_age_days']];
            }
            $computedPrice = null;
            $computedBase = null;
            $computedRate = null;
            $sellingRate = null;
            $cost = null;
            if ($mode === 'fixed' && !$row['use_pricing_rules']) {
                if ($row['fixed_price'] !== null) {
                    $computedPrice = PriceRounding::apply((string) $row['fixed_price'], $rounding);
                }
            } else {
                $costKey = $fxPolicy === null ? 'legacy' : $fxPolicy['source'] . ':' . $fxPolicy['max_age_days'];
                if (!array_key_exists($costKey, $costs)) {
                    $costs[$costKey] = $this->costResolver->resolve($supplierId, $item, $onDate, $snapshot, $fxPolicy);
                }
                $cost = $costs[$costKey];
                $baseCzk = $cost['base_czk'] ?? null;
                if (($snapshot !== null || $row['use_pricing_rules']) && $baseCzk === null) {
                    throw new PricingInputException('missing_purchase_cost', ['item_id' => $itemId]);
                }
                if ($baseCzk !== null) {
                    $computedBase = $baseCzk;
                    if ($currency === 'CZK') {
                        $computedPrice = PriceRounding::apply(self::calculate($baseCzk, '1', $mode, $percentage), $rounding);
                    } else {
                        if ($fxPolicy !== null) {
                            $sellingRate = $this->fx->businessRateFor($supplierId, $currency, $onDate, $fxPolicy['source'], $fxPolicy['max_age_days'], $snapshot);
                            $rate = $sellingRate['rate'];
                        } else {
                            $rate = $this->fx->rateFor($currency, $onDate, $snapshot);
                        }
                        if ($rate !== null && bccomp($rate, '0', 6) > 0) {
                            $computedPrice = PriceRounding::apply(self::calculate($baseCzk, $rate, $mode, $percentage), $rounding);
                            $computedRate = $rate;
                        }
                    }
                }
            }
            $rateAudit = $sellingRate ?? ($cost['rate'] ?? null);
            $plans[] = array_replace($row, [
                'persist' => true,
                'computed_price' => $computedPrice,
                'computed_base' => $computedBase,
                'computed_rate' => $computedRate,
                'details' => [
                    'profile_id' => $profile['id'] ?? null,
                    'rule_id' => $rule['id'] ?? null,
                    'rate_date' => $rateAudit['rate_date'] ?? null,
                    'rate_source' => $rateAudit['source'] ?? null,
                    'cost_source' => $cost['source'] ?? null,
                    'calculation_mode' => $mode,
                    'percentage' => in_array($mode, ['markup', 'target_margin'], true) ? $percentage : null,
                    'context' => [
                        'output' => ['currency_code' => $currency] + $outputAudit,
                        'rule' => $rule === null ? null : [
                            'id' => (int) $rule['id'],
                            'match_type' => (string) $rule['match_type'],
                            'match_id' => $rule['match_id'],
                            'priority' => (int) $rule['priority'],
                        ],
                        'cost' => $cost,
                        'selling_rate' => $sellingRate,
                    ],
                ],
            ]);
        }
        return $plans;
    }

    private static function calculate(string $baseCzk, string $rate, string $mode, string $percentage): string
    {
        if ($mode === 'target_margin') {
            if (bccomp($percentage, '0', 6) < 0 || bccomp($percentage, '100', 6) >= 0) {
                throw new PricingInputException('invalid_target_margin', ['percentage' => $percentage]);
            }
            return bcdiv(bcmul($baseCzk, '100', 12), bcmul($rate, bcsub('100', $percentage, 6), 12), 12);
        }
        return bcdiv(bcmul($baseCzk, bcadd('100', $percentage, 6), 12), bcmul($rate, '100', 12), 12);
    }
}
