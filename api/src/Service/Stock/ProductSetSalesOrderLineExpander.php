<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Service\Eshop\Sets\ProductSetService;

/** Adapter aktivovaný po integraci SK-09. Běžné karty deleguje na základní expander. */
final class ProductSetSalesOrderLineExpander implements SalesOrderLineExpander
{
    public function __construct(
        private readonly ProductSetService $sets,
        private readonly StockItemSalesOrderLineExpander $stockItems,
    ) {}

    public function expand(int $supplierId, array $line, string $currencyCode): array
    {
        $itemId = (int) ($line['stock_item_id'] ?? 0);
        if ($itemId < 1 || $this->sets->get($supplierId, $itemId) === null) {
            return $this->stockItems->expand($supplierId, $line, $currencyCode);
        }

        $quote = $this->sets->quote(
            $supplierId,
            $itemId,
            $currencyCode,
            (string) ($line['quantity'] ?? '0'),
            is_array($line['set_selections'] ?? null) ? $line['set_selections'] : [],
        );
        $cards = [];
        foreach ($quote['component_snapshot'] as $card) {
            $cards[(int) $card['id']] = $card;
        }
        $components = [];
        foreach ($quote['quote']['components'] as $component) {
            $card = $cards[(int) $component['item_id']]
                ?? throw new \LogicException('Snapshot komponenty setu není úplný.');
            $components[] = [
                'stock_item_id' => (int) $component['item_id'],
                'quantity' => (string) $component['quantity'],
                'allocated_amount' => (string) $component['amount'],
                'unit_price' => (string) $component['unit_price'],
                'sku' => (string) $card['sku'],
                'ean' => $card['ean'] !== null ? (string) $card['ean'] : null,
                'name' => (string) $card['name'],
                'unit' => (string) $card['unit'],
            ];
        }

        return [
            'product_snapshot' => [
                'kind' => 'product_set',
                'stock_item_id' => $itemId,
                'row_version' => (int) $quote['row_version'],
                'currency_code' => (string) $quote['currency_code'],
                'prices_include_vat' => (bool) $quote['prices_include_vat'],
                'vat_rate_id' => (int) $quote['vat_rate_id'],
                'selections' => $quote['selections'],
                'definition_snapshot' => $quote['definition_snapshot'],
                'component_cards' => $quote['component_snapshot'],
                'quoted_amount' => (string) $quote['quote']['amount'],
            ],
            'component_snapshot' => $components,
        ];
    }
}
