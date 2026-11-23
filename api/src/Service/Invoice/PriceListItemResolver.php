<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use DateTimeImmutable;
use MyInvoice\Repository\PriceListItemRepository;
use MyInvoice\Service\Currency\CurrencyConversionService;

final class PriceListItemResolver
{
    public function __construct(
        private readonly PriceListItemRepository $items,
        private readonly CurrencyConversionService $converter,
    ) {}

    /**
     * @param list<int> $itemIds
     * @return array<int,array<string,mixed>> indexed by price-list item id
     */
    public function resolveMany(
        array $itemIds,
        int $supplierId,
        int $clientId,
        int $targetCurrencyId,
        bool $pricesIncludeVat,
        DateTimeImmutable $referenceDate,
    ): array {
        $currency = $this->items->currencyById($supplierId, $targetCurrencyId);
        if ($currency === null) {
            throw new PriceListResolutionException('currency_not_found', 'Měna dokladu nebyla nalezena.');
        }

        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        $rows = $this->items->pricingRows($supplierId, $ids, $clientId, $currency['code']);
        $resolved = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if ($row === null) {
                throw new PriceListResolutionException(
                    'price_list_item_not_found',
                    'Ceníková položka nebyla nalezena.',
                    $id,
                );
            }
            $resolved[$id] = $this->resolveRow(
                $row,
                $currency['code'],
                $pricesIncludeVat,
                $referenceDate,
            );
        }
        return $resolved;
    }

    /**
     * Vrátí pouze položky, které lze v daném kontextu skutečně použít.
     *
     * @param list<int> $itemIds
     * @return array<int,array<string,mixed>>
     */
    public function resolveAvailable(
        array $itemIds,
        int $supplierId,
        int $clientId,
        int $targetCurrencyId,
        bool $pricesIncludeVat,
        DateTimeImmutable $referenceDate,
    ): array {
        $currency = $this->items->currencyById($supplierId, $targetCurrencyId);
        if ($currency === null) return [];

        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        $rows = $this->items->pricingRows($supplierId, $ids, $clientId, $currency['code']);
        $resolved = [];
        foreach ($ids as $id) {
            if (!isset($rows[$id])) continue;
            try {
                $resolved[$id] = $this->resolveRow(
                    $rows[$id],
                    $currency['code'],
                    $pricesIncludeVat,
                    $referenceDate,
                );
            } catch (PriceListResolutionException) {
                // Seznam slouží jako nabídka použitelných položek; detailní chybu vrací /resolve.
            }
        }
        return $resolved;
    }

    /** @param array<string,mixed> $row */
    private function resolveRow(
        array $row,
        string $targetCurrency,
        bool $pricesIncludeVat,
        DateTimeImmutable $referenceDate,
    ): array {
        $id = (int) $row['id'];
        if (!empty($row['archived'])) {
            throw new PriceListResolutionException(
                'price_list_item_archived',
                'Ceníková položka je archivovaná.',
                $id,
            );
        }
        if ((bool) $row['prices_include_vat'] !== $pricesIncludeVat) {
            throw new PriceListResolutionException(
                'price_mode_mismatch',
                'Režim ceny ceníkové položky neodpovídá nastavení dokladu.',
                $id,
            );
        }
        if ($row['base_unit_price'] === null || !empty($row['base_price_archived'])) {
            throw new PriceListResolutionException(
                'base_price_unavailable',
                'Základní cena ceníkové položky není dostupná.',
                $id,
            );
        }

        $priceSource = null;
        $sourceCurrency = strtoupper((string) $row['base_currency_code']);
        $sourceUnitPrice = null;
        $targetUnitPrice = null;
        $exchangeRate = null;
        $exchangeRateDate = null;
        $rateFallbackUsed = false;
        $rateSource = null;

        if ($row['customer_target_unit_price'] !== null) {
            $priceSource = 'customer_explicit';
            $sourceCurrency = $targetCurrency;
            $sourceUnitPrice = (float) $row['customer_target_unit_price'];
            $targetUnitPrice = $sourceUnitPrice;
        } elseif ($row['target_unit_price'] !== null && empty($row['target_price_archived'])) {
            $priceSource = 'catalog_explicit';
            $sourceCurrency = $targetCurrency;
            $sourceUnitPrice = (float) $row['target_unit_price'];
            $targetUnitPrice = $sourceUnitPrice;
        } else {
            if (empty($row['allow_exchange_rate_conversion'])) {
                throw new PriceListResolutionException(
                    'price_unavailable',
                    "Cena pro měnu {$targetCurrency} není nastavena.",
                    $id,
                );
            }

            $sourceUnitPrice = $row['customer_base_unit_price'] !== null
                ? (float) $row['customer_base_unit_price']
                : (float) $row['base_unit_price'];
            $priceSource = $row['customer_base_unit_price'] !== null
                ? 'customer_base_converted'
                : 'catalog_base_converted';
            // Jednotková cena se převádí na šest míst jako cena řádku dokladu, ne na
            // haléře měny — levná položka (0,004 EUR) by se jinak vynulovala.
            $conversion = $this->converter->convertUnitPrice(
                $sourceUnitPrice,
                $sourceCurrency,
                $targetCurrency,
                $referenceDate,
            );
            if ($conversion === null) {
                throw new PriceListResolutionException(
                    'exchange_rate_unavailable',
                    "Kurz pro přepočet {$sourceCurrency}/{$targetCurrency} není dostupný.",
                    $id,
                );
            }
            $targetUnitPrice = (float) $conversion['amount'];
            $exchangeRate = (float) $conversion['cross_rate'];
            $exchangeRateDate = (string) $conversion['rate_date'];
            $rateFallbackUsed = (bool) $conversion['fallback_used'];
            $rateSource = (string) $conversion['source'];
        }

        return [
            'price_list_item_id' => $id,
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'unit' => (string) $row['unit'],
            'vat_rate_id' => (int) $row['vat_rate_id'],
            'vat_rate_percent' => (float) $row['vat_rate_percent'],
            'prices_include_vat' => (bool) $row['prices_include_vat'],
            'unit_price_without_vat' => $targetUnitPrice,
            'target_currency_code' => $targetCurrency,
            'catalog_price_source' => $priceSource,
            'catalog_source_currency_code' => $sourceCurrency,
            'catalog_source_unit_price' => $sourceUnitPrice,
            'catalog_exchange_rate' => $exchangeRate,
            'catalog_exchange_rate_date' => $exchangeRateDate,
            'catalog_rate_fallback_used' => $rateFallbackUsed,
            'catalog_rate_source' => $rateSource,
        ];
    }
}
