<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemRepository;

final class PriceCalculationService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockItemPriceRepository $prices,
        private readonly StockItemRepository $items,
        private readonly PriceCalculationPlanner $planner,
    ) {}

    /** @return list<array<string,mixed>> */
    public function recompute(
        int $supplierId,
        int $stockItemId,
        ?string $onDate = null,
        ?string $now = null,
        ?PricingSnapshot $snapshot = null,
        ?int $expectedRowVersion = null,
        ?array $onlyCurrencies = null,
    ): array {
        $onDate = $snapshot?->onDate ?? $onDate ?? date('Y-m-d');
        $now ??= date('Y-m-d H:i:s');
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
                throw new PricingInputException('stale_price_input', [
                    'expected_version' => $expectedRowVersion,
                    'actual_version' => (int) $lockedVersion,
                ]);
            }

            $rows = $this->prices->listForItem($supplierId, $stockItemId);
            if ($onlyCurrencies !== null) {
                $selected = array_fill_keys(array_map(static fn (string $currency): string => strtoupper($currency), $onlyCurrencies), true);
                $rows = array_values(array_filter($rows, static fn (array $row): bool => isset($selected[$row['currency_code']])));
            }
            $plans = $this->planner->plan($supplierId, $item, $rows, $onDate, $snapshot);
            $czkComputed = null;
            $hasCzkRow = false;
            foreach ($plans as $plan) {
                if ($plan['persist'] && (int) ($plan['id'] ?? 0) > 0) {
                    $this->prices->updateComputed(
                        $supplierId,
                        (int) $plan['id'],
                        $plan['computed_price'],
                        $plan['computed_base'],
                        $plan['computed_rate'],
                        $now,
                        $plan['details'],
                    );
                }
                if (strtoupper((string) $plan['currency_code']) === 'CZK') {
                    $hasCzkRow = true;
                    $czkComputed = $plan['computed_price'] === null ? null : (string) $plan['computed_price'];
                }
            }

            if ($hasCzkRow) {
                $this->items->setSalePrice($supplierId, $stockItemId, $czkComputed);
            } elseif ($rows !== []) {
                $pdo->prepare('UPDATE stock_items SET row_version = row_version + 1 WHERE supplier_id = ? AND id = ?')
                    ->execute([$supplierId, $stockItemId]);
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $this->prices->listForItem($supplierId, $stockItemId);
    }
}
