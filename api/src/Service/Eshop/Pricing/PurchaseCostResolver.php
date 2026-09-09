<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Repository\StockItemVendorRepository;
use MyInvoice\Repository\StockLevelRepository;

/**
 * Zjištění nákupní ceny karty v CZK pro cenotvorbu (Epic ESHOP, §3.1/§3.4).
 *
 * Zdroj dle stock_items.pricing_base:
 *  - weighted_avg : SUM(value_total)/SUM(qty) ze stock_levels (konzistentní s oceněním skladu)
 *  - last_purchase: unit_cost poslední posted příjemky
 *  - manual       : purchase_price preferovaného dodavatele (přepočtený do CZK)
 *
 * Fallback řetěz (E5 — zboží bez skladu): zvolený zdroj → last_purchase →
 * vendor. Vrací null, když nákupní cenu nelze zjistit (badge „chybí NC"),
 * což pokrývá is_stocked=0 karty bez příjemky. Vše bcmath/string (money-safe).
 */
final class PurchaseCostResolver
{
    public function __construct(
        private readonly StockLevelRepository $levels,
        private readonly StockItemVendorRepository $vendors,
        private readonly FxRateProvider $fx,
    ) {}

    /**
     * @param array<string,mixed> $item řádek stock_items (potřebuje id, pricing_base)
     * @return array{base_czk:string, source:string}|null
     */
    public function resolve(int $supplierId, array $item, string $onDate, ?PricingSnapshot $snapshot = null): ?array
    {
        $itemId = (int) $item['id'];
        $base = (string) ($item['pricing_base'] ?? 'weighted_avg');

        // Primární zdroj dle pricing_base.
        $missingRate = null;
        try {
            $primary = match ($base) {
                'weighted_avg'  => $this->pair($this->levels->weightedAvgCost($supplierId, $itemId), 'weighted_avg'),
                'last_purchase' => $this->pair($this->levels->lastPurchaseCost($supplierId, $itemId), 'last_purchase'),
                'manual'        => $this->vendorCzk($supplierId, $itemId, $onDate, $snapshot),
                default         => null,
            };
        } catch (PricingInputException $e) {
            $missingRate = $e;
            $primary = null;
        }
        if ($primary !== null) {
            return $primary;
        }

        // Fallback řetěz (§3.4): last_purchase → vendor.
        $fallbackLast = $this->pair($this->levels->lastPurchaseCost($supplierId, $itemId), 'last_purchase');
        if ($fallbackLast !== null) {
            return $fallbackLast;
        }
        if ($missingRate !== null) {
            throw $missingRate;
        }
        return $this->vendorCzk($supplierId, $itemId, $onDate, $snapshot);
    }

    /** @return array{base_czk:string, source:string}|null */
    private function pair(?string $value, string $source): ?array
    {
        if ($value === null) {
            return null;
        }
        // Nezáporná, nenulová nákupní cena (0 nedává smysl pro přirážku).
        if (bccomp($value, '0', 6) <= 0) {
            return null;
        }
        return ['base_czk' => $value, 'source' => $source];
    }

    /** Preferovaný dodavatel → CZK (přes FX, když je v cizí měně). */
    private function vendorCzk(int $supplierId, int $itemId, string $onDate, ?PricingSnapshot $snapshot = null): ?array
    {
        $vendor = $this->vendors->preferredPurchase($supplierId, $itemId);
        if ($vendor === null) {
            return null;
        }
        $price = $vendor['purchase_price'];
        $currency = strtoupper($vendor['currency_code']);
        if ($currency === 'CZK') {
            return $this->pair($price, 'vendor');
        }
        $czk = $this->fx->toCzk($price, $currency, $onDate, $snapshot);
        if ($czk === null) {
            return null; // kurz chybí → nelze převést
        }
        return $this->pair($czk, 'vendor');
    }
}
