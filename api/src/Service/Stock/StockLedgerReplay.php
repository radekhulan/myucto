<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

final class StockLedgerReplay
{
    public static function advance(int $qtyT, int $valueC, array $line): array
    {
        $quantity = StockValuation::qtyToT((string) $line['qty']);
        if ($quantity <= 0) {
            throw new StockException('invalid_document', 'Skladová kniha obsahuje neplatné množství.', 422);
        }
        $lineValue = StockValuation::valueToC((string) $line['value_total']);
        if ((int) $line['direction'] === 1) {
            return StockValuation::receipt($qtyT, $valueC, $quantity, $lineValue);
        }
        if ($quantity > $qtyT) {
            throw new StockException('insufficient_stock', 'Přepočet by vytvořil záporný stav zásob.', 409);
        }
        if (!empty($line['is_reversal']) || $line['status'] === 'reversed') {
            if ($lineValue > $valueC) {
                throw new StockException('insufficient_stock', 'Přepočet by vytvořil zápornou hodnotu zásob.', 409);
            }
            return ['qtyT' => $qtyT - $quantity, 'valueC' => $valueC - $lineValue, 'lineValueC' => $lineValue,
                'lineUnitCostMicro' => StockValuation::avgUnitCostMicro($quantity, $lineValue)];
        }
        return StockValuation::issue($qtyT, $valueC, $quantity);
    }
}
