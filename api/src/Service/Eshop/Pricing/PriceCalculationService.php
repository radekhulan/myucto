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
    ) {}

    /**
     * Přepočte všechny cenové řádky karty. Idempotentní, běží v transakci.
     * @return list<array<string,mixed>> aktualizované cenové řádky
     */
    public function recompute(int $supplierId, int $stockItemId, ?string $onDate = null, ?string $now = null, ?PricingSnapshot $snapshot = null, ?int $expectedRowVersion = null): array
    {
        $onDate = $snapshot?->onDate ?? $onDate ?? date('Y-m-d');
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
            $needsCost = array_filter($rows, static fn (array $row): bool => !$row['is_manual_override'] && $row['price_mode'] === 'markup') !== [];
            $cost = $needsCost ? $this->costResolver->resolve($supplierId, $item, $onDate, $snapshot) : null;
            $baseCzk = $cost['base_czk'] ?? null;
            if ($snapshot !== null && $needsCost && $baseCzk === null) {
                throw new PricingInputException('missing_purchase_cost');
            }
            $czkComputed = null;
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
                        $this->prices->updateComputed($supplierId, (int) $row['id'], $manual, null, null, $now);
                    }
                    if ($currency === 'CZK' && $manual !== null) {
                        $czkComputed = $manual;
                    }
                    continue;
                }

                $currency = strtoupper((string) $row['currency_code']);
                $mode = (string) $row['price_mode'];
                $rounding = (string) $row['rounding'];

                $computedPrice = null;
                $computedBase = null;
                $computedRate = null;

                if ($mode === 'fixed') {
                    if ($row['fixed_price'] !== null) {
                        $computedPrice = PriceRounding::apply((string) $row['fixed_price'], $rounding);
                    }
                } else { // markup
                    $markup = $row['markup_pct'] !== null ? (string) $row['markup_pct'] : '0';
                    if ($baseCzk !== null) {
                        $computedBase = $baseCzk;
                        $factor = bcdiv(bcadd('100', $markup, 6), '100', 8); // (1 + markup/100)
                        if ($currency === 'CZK') {
                            $raw = bcmul($baseCzk, $factor, 6);
                            $computedPrice = PriceRounding::apply($raw, $rounding);
                        } else {
                            $rate = $this->fx->rateFor($currency, $onDate, $snapshot);
                            if ($rate !== null && bccomp($rate, '0', 6) > 0) {
                                $baseCcy = bcdiv($baseCzk, $rate, 6);
                                $raw = bcmul($baseCcy, $factor, 6);
                                $computedPrice = PriceRounding::apply($raw, $rounding);
                                $computedRate = $rate;
                            }
                            // kurz chybí → computedPrice zůstává null (badge „chybí kurz")
                        }
                    }
                    // baseCzk null → computedPrice null (badge „chybí NC")
                }

                $this->prices->updateComputed(
                    $supplierId,
                    (int) $row['id'],
                    $computedPrice,
                    $computedBase,
                    $computedRate,
                    $now,
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
}
