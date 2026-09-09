<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemVendorRepository;
use MyInvoice\Service\Eshop\Pricing\PriceRecomputeDispatcher;

final class ProductVendorWriteService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockItemVendorRepository $vendors,
        private readonly PriceRecomputeDispatcher $priceDispatcher,
    ) {}

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function save(int $supplierId, int $itemId, array $rows): array
    {
        return $this->persist($supplierId, $itemId, $rows, false);
    }

    public function saveSnapshot(int $supplierId, int $itemId, array $rows): array
    {
        return $this->persist($supplierId, $itemId, $rows, true);
    }

    private function persist(int $supplierId, int $itemId, array $rows, bool $restoreOffer): array
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }
        try {
            $lock = $pdo->prepare('SELECT id FROM stock_items WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $lock->execute([$supplierId, $itemId]);
            if ($lock->fetchColumn() === false) {
                throw new EshopException('not_found', 'Karta zboží nenalezena.', 404);
            }

            $before = [];
            foreach ($this->vendors->listForItem($supplierId, $itemId) as $previous) {
                $before[(int) $previous['client_id']] = $previous;
            }

            $prepared = [];
            $clientIds = [];
            $preferredCount = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new EshopException('validation_failed', 'Řádek dodavatele musí být objekt.', 400);
                }
                $clientId = (int) ($row['client_id'] ?? 0);
                if ($clientId <= 0) {
                    throw new EshopException('validation_failed', 'Dodavatel je povinný.', 400);
                }
                if (isset($clientIds[$clientId])) {
                    throw new EshopException('validation_failed', 'Dodavatel je v seznamu vícekrát.', 400);
                }
                $clientIds[$clientId] = true;
                $preferred = (bool) ($row['is_preferred'] ?? false);
                if ($preferred) {
                    $preferredCount++;
                }
                $stockQty = $this->numberOrNull($row['stock_qty'] ?? null);
                $previous = $restoreOffer ? $this->snapshotOffer($row) : ($before[$clientId] ?? null);
                $prepared[] = [
                    'client_id'            => $clientId,
                    'vendor_sku'           => $this->stringOrNull($row['vendor_sku'] ?? null),
                    'purchase_price'       => $this->numberOrNull($row['purchase_price'] ?? null),
                    'currency_code'        => $this->currency($row['currency_code'] ?? 'CZK'),
                    'delivery_days'        => $this->intOrNull($row['delivery_days'] ?? null),
                    'stock_qty'            => $stockQty,
                    'is_preferred'         => $preferred,
                    'note'                 => $this->stringOrNull($row['note'] ?? null),
                    'availability_state'   => (string) ($previous['availability_state'] ?? 'unknown'),
                    'stock_qty_updated_at' => $this->stockQtyStamp($previous, $stockQty),
                    'min_order_qty'        => $previous['min_order_qty'] ?? null,
                    'package_qty'          => $previous['package_qty'] ?? null,
                    'price_valid_to'       => $previous['price_valid_to'] ?? null,
                    'data_source'          => (string) ($previous['data_source'] ?? 'manual'),
                    'is_active'            => $previous === null ? true : (bool) $previous['is_active'],
                ];
            }
            if ($preferredCount > 1) {
                throw new EshopException(
                    'multiple_preferred_vendors',
                    'Zboží může mít nejvýše jednoho preferovaného dodavatele.',
                    422,
                );
            }

            $owned = array_flip($this->vendors->filterOwnedVendors($supplierId, array_keys($clientIds)));
            foreach (array_keys($clientIds) as $clientId) {
                if (!isset($owned[$clientId])) {
                    throw new EshopException(
                        'vendor_invalid',
                        'Zvolený dodavatel neexistuje nebo není označen jako dodavatel.',
                        422,
                        ['client_id' => $clientId],
                    );
                }
            }

            $this->vendors->deleteForItem($supplierId, $itemId);
            foreach ($prepared as $vendor) {
                $this->vendors->add($supplierId, $itemId, $vendor);
            }
            $this->priceDispatcher->recomputeItem($supplierId, $itemId);
            $pdo->prepare('UPDATE stock_items SET row_version = row_version + 1 WHERE supplier_id = ? AND id = ?')
                ->execute([$supplierId, $itemId]);
            $saved = $this->vendors->listForItem($supplierId, $itemId);
            if ($owns) {
                $pdo->commit();
            }
            return $saved;
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed>|null $previous */
    private function stockQtyStamp(?array $previous, ?string $stockQty): ?string
    {
        if ($stockQty !== null && (string) ($previous['stock_qty'] ?? null) !== $stockQty) {
            return date('Y-m-d H:i:s');
        }
        return $previous['stock_qty_updated_at'] ?? null;
    }

    private function snapshotOffer(array $row): array
    {
        foreach (['availability_state' => ['in_stock', 'on_order', 'unavailable', 'unknown'], 'data_source' => ['manual', 'import', 'feed']] as $field => $allowed) {
            if (isset($row[$field]) && !in_array($row[$field], $allowed, true)) {
                throw new EshopException('validation_failed', 'Neplatná metadata nabídky dodavatele.', 422);
            }
        }
        foreach (['min_order_qty', 'package_qty'] as $field) {
            $value = $row[$field] ?? null;
            if ($value !== null && (!is_scalar($value) || !preg_match('/^\d{1,11}(?:\.\d{1,3})?$/', (string) $value) || (float) $value <= 0)) {
                throw new EshopException('validation_failed', 'Neplatné množství nabídky dodavatele.', 422);
            }
        }
        foreach (['price_valid_to' => 'Y-m-d', 'stock_qty_updated_at' => 'Y-m-d H:i:s'] as $field => $format) {
            $value = $row[$field] ?? null;
            if ($value !== null && (!is_string($value) || (($parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value)) === false) || $parsed->format($format) !== $value)) {
                throw new EshopException('validation_failed', 'Neplatné datum nabídky dodavatele.', 422);
            }
        }
        if (isset($row['is_active']) && !in_array($row['is_active'], [true, false, 0, 1, '0', '1'], true)) {
            throw new EshopException('validation_failed', 'Neplatný stav nabídky dodavatele.', 422);
        }
        return $row + ['is_active' => true];
    }

    private function numberOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = str_replace(',', '.', (string) $value);
        if (!is_numeric($number)) {
            throw new EshopException('validation_failed', 'Číselná hodnota dodavatele není platná.', 400);
        }
        return $number;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);
        return $string === '' ? null : $string;
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper(trim((string) $value));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new EshopException('validation_failed', 'Neplatný kód měny dodavatele.', 400);
        }
        return $currency;
    }
}
