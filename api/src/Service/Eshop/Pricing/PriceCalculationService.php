<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemRepository;

/**
 * Výpočet prodejní ceny karty per měna (Epic ESHOP, §3.2).
 *
 * Pro každý řádek stock_item_prices:
 *  - is_manual_override=1 → přeskoč (computed_price se nepřepisuje),
 *  - fixed → computed_price = round(fixed_price),
 *  - markup CZK → base_czk * (1+markup/100),
 *  - markup cizí měna → (base_czk / FX_rate) * (1+markup/100), ulož computed_rate,
 *  - pak zaokrouhlení (PriceRounding), ulož computed_base/rate/at.
 *
 * base_czk chybí (is_stocked=0 bez NC) → computed_price=NULL (badge „chybí NC").
 * CZK řádek se zrcadlí do stock_items.sale_price_without_vat (řádek FV beze změny).
 * Vše bcmath/string (money-safe, žádný float).
 */
final class PriceCalculationService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockItemPriceRepository $prices,
        private readonly StockItemRepository $items,
        private readonly PurchaseCostResolver $costResolver,
        private readonly FxRateProvider $fx,
        private readonly PricingRuleResolver $ruleResolver,
    ) {}

    /**
     * Přepočte všechny cenové řádky karty. Idempotentní, běží v transakci.
     * @return list<array<string,mixed>> aktualizované cenové řádky
     */
    public function recompute(int $supplierId, int $stockItemId, ?string $onDate = null, ?string $now = null, ?PricingSnapshot $snapshot = null, ?int $expectedRowVersion = null): array
    {
        $onDate = $snapshot !== null ? $snapshot->onDate : ($onDate ?? date('Y-m-d'));
        $now = $now ?? date('Y-m-d H:i:s');

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }
        try {
            $lock = $pdo->prepare('SELECT row_version FROM stock_items WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $lock->execute([$supplierId, $stockItemId]);
            $lockedVersion = $lock->fetchColumn();
            $item = $this->items->find($supplierId, $stockItemId);
            if ($item === null) {
                if ($ownTx) {
                    $pdo->commit();
                }
                return [];
            }
            if ($expectedRowVersion !== null && (int) $lockedVersion !== $expectedRowVersion) {
                throw new PricingInputException('stale_price_input', ['expected_version' => $expectedRowVersion, 'actual_version' => (int) $lockedVersion]);
            }
            $rows = $this->prices->listForItem($supplierId, $stockItemId);
            $costs = [];
            $czkComputed = null;
            $pricingContext = null;
            $outputAudit = [
                'calculation_date' => $onDate,
                'prices_include_vat' => false,
                'vat_rate_id' => $item['vat_rate_id'] ?? null,
            ];
            foreach ($rows as $row) {
                if ($row['is_manual_override']) {
                    // Ruční cena: markup se NEaplikuje. Zadaná fixed_price = manuální
                    // cena (settable, zaokrouhlená); jinak zachovej stávající computed_price.
                    // Tím se vyhneme „zamrzlé" zastaralé hodnotě bez cesty ji změnit.
                    $currency = strtoupper((string) $row['currency_code']);
                    $manual = $row['fixed_price'] !== null
                        ? PriceRounding::apply((string) $row['fixed_price'], (string) $row['rounding'])
                        : ($row['computed_price'] !== null ? (string) $row['computed_price'] : null);
                    if ($row['fixed_price'] !== null) {
                        $this->prices->updateComputed(
                            $supplierId,
                            (int) $row['id'],
                            $manual,
                            null,
                            null,
                            $now,
                            [
                                'calculation_mode' => 'fixed',
                                'context' => [
                                    'manual_override' => true,
                                    'output' => ['currency_code' => $currency] + $outputAudit,
                                ],
                            ],
                        );
                    }
                    if ($currency === 'CZK' && $manual !== null) {
                        $czkComputed = $manual;
                    }
                    continue;
                }

                $currency = strtoupper((string) $row['currency_code']);
                $mode = (string) $row['price_mode'];
                $rounding = (string) $row['rounding'];
                $percentage = $row['markup_pct'] !== null ? (string) $row['markup_pct'] : '0';
                $rule = null;
                $profile = null;
                $fxPolicy = null;

                if ($row['use_pricing_rules']) {
                    $pricingContext ??= $this->ruleResolver->itemContext($supplierId, $stockItemId);
                    $rule = $this->ruleResolver->resolve(
                        $supplierId,
                        $stockItemId,
                        $currency,
                        $snapshot,
                        $pricingContext,
                    );
                    if ($rule === null) {
                        throw new PricingInputException('missing_pricing_rule', [
                            'item_id' => $stockItemId,
                            'currency_code' => $currency,
                        ]);
                    }
                    $profile = $rule['profile'];
                    $mode = (string) $profile['calculation_mode'];
                    $rounding = (string) $profile['rounding'];
                    $percentage = (string) $profile['percentage'];
                    $fxPolicy = [
                        'source' => (string) $profile['fx_source'],
                        'max_age_days' => (int) $profile['max_rate_age_days'],
                    ];
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
                    $costKey = $fxPolicy === null
                        ? 'legacy'
                        : $fxPolicy['source'] . ':' . $fxPolicy['max_age_days'];
                    if (!array_key_exists($costKey, $costs)) {
                        $costs[$costKey] = $this->costResolver->resolve(
                            $supplierId,
                            $item,
                            $onDate,
                            $snapshot,
                            $fxPolicy,
                        );
                    }
                    $cost = $costs[$costKey];
                    $baseCzk = $cost['base_czk'] ?? null;
                    if (($snapshot !== null || $row['use_pricing_rules']) && $baseCzk === null) {
                        throw new PricingInputException('missing_purchase_cost', ['item_id' => $stockItemId]);
                    }
                    if ($baseCzk !== null) {
                        $computedBase = $baseCzk;
                        if ($currency === 'CZK') {
                            $raw = self::calculate($baseCzk, '1', $mode, $percentage);
                            $computedPrice = PriceRounding::apply($raw, $rounding);
                        } else {
                            if ($fxPolicy !== null) {
                                $sellingRate = $this->fx->businessRateFor(
                                    $supplierId,
                                    $currency,
                                    $onDate,
                                    $fxPolicy['source'],
                                    $fxPolicy['max_age_days'],
                                    $snapshot,
                                );
                                $rate = $sellingRate['rate'];
                            } else {
                                $rate = $this->fx->rateFor($currency, $onDate, $snapshot);
                            }
                            if ($rate !== null && bccomp($rate, '0', 6) > 0) {
                                $raw = self::calculate($baseCzk, $rate, $mode, $percentage);
                                $computedPrice = PriceRounding::apply($raw, $rounding);
                                $computedRate = $rate;
                            }
                        }
                    }
                }

                $rateAudit = $sellingRate ?? ($cost['rate'] ?? null);
                $details = [
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
                ];

                $this->prices->updateComputed(
                    $supplierId,
                    (int) $row['id'],
                    $computedPrice,
                    $computedBase,
                    $computedRate,
                    $now,
                    $details,
                );

                if ($currency === 'CZK' && $computedPrice !== null) {
                    $czkComputed = $computedPrice;
                }
            }

            // Zrcadlo CZK ceny do skladové karty (default do řádku FV).
            if ($czkComputed !== null) {
                $this->items->setSalePrice($supplierId, $stockItemId, $czkComputed);
            } elseif ($rows !== []) {
                $pdo->prepare('UPDATE stock_items SET row_version = row_version + 1 WHERE supplier_id = ? AND id = ?')
                    ->execute([$supplierId, $stockItemId]);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->prices->listForItem($supplierId, $stockItemId);
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
