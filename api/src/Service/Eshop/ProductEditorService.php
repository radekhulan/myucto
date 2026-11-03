<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemPromoPriceRepository;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Repository\StockItemVendorRepository;
use MyInvoice\Service\Eshop\Pricing\PriceWriteService;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use MyInvoice\Service\Stock\StockItemIntrastatValidator;
use MyInvoice\Support\Slugifier;

final class ProductEditorService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockItemRepository $items,
        private readonly ProductCardService $cards,
        private readonly PriceWriteService $prices,
        private readonly ProductPromoPriceWriteService $promos,
        private readonly ProductVendorWriteService $vendors,
        private readonly StockItemPriceRepository $priceRepository,
        private readonly StockItemPromoPriceRepository $promoRepository,
        private readonly StockItemVendorRepository $vendorRepository,
        private readonly StockItemIntrastatValidator $intrastat,
    ) {}

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function save(int $supplierId, int $itemId, array $payload): array
    {
        $expectedVersion = (int) ($payload['row_version'] ?? 0);
        if ($expectedVersion <= 0) {
            throw new EshopException('version_required', 'Pro uložení je nutná verze karty.', 400);
        }
        $base = $this->items->find($supplierId, $itemId);
        if ($base === null) {
            throw new EshopException('not_found', 'Karta zboží nenalezena.', 404);
        }

        // Vynechaná sekce = beze změny; přítomná kolekce (prices, promo_prices, vendors) nahrazuje celek.
        $item = $this->prepareItem($supplierId, $itemId, array_key_exists('item', $payload) ? $payload['item'] : [], $base);
        $product = array_key_exists('product', $payload) ? $this->arraySection($payload, 'product') : [];
        $prices = array_key_exists('prices', $payload) ? $this->listSection($payload, 'prices') : null;
        $promos = array_key_exists('promo_prices', $payload) ? $this->listSection($payload, 'promo_prices') : null;
        $vendors = array_key_exists('vendors', $payload) ? $this->listSection($payload, 'vendors') : null;

        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Editor produktu očekává vlastní transakci.');
        }
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            if (!$this->items->updateVersioned($supplierId, $itemId, $expectedVersion, $item)) {
                throw new EshopException(
                    'version_conflict',
                    'Kartu mezitím změnil jiný uživatel. Načtěte aktuální data.',
                    409,
                );
            }
            $this->cards->updateForEditor($supplierId, $itemId, $base, $product);
            if ($vendors !== null) {
                $this->vendors->save($supplierId, $itemId, $vendors);
            }
            if ($promos !== null) {
                $this->promos->save($supplierId, $itemId, $promos);
            }
            if ($prices !== null) {
                $this->prices->save($supplierId, $itemId, $prices, true, true);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $card = $this->cards->get($supplierId, $itemId) ?? [];
        $card['prices'] = $this->priceRepository->listForItem($supplierId, $itemId);
        $card['promo_prices'] = $this->promoRepository->listForItem($supplierId, $itemId);
        $card['vendors'] = $this->vendorRepository->listForItem($supplierId, $itemId);
        return $card;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function arraySection(array $payload, string $key): array
    {
        if (!array_key_exists($key, $payload) || !is_array($payload[$key])) {
            throw new EshopException('validation_failed', "Sekce {$key} musí být objekt.", 400);
        }
        return $payload[$key];
    }

    /** @param array<string,mixed> $payload @return list<array<string,mixed>> */
    private function listSection(array $payload, string $key): array
    {
        if (!array_key_exists($key, $payload) || !is_array($payload[$key])) {
            throw new EshopException('validation_failed', "Sekce {$key} musí být pole.", 400);
        }
        return array_values($payload[$key]);
    }

    /**
     * @param mixed $input
     * @param array<string,mixed> $existing
     * @return array<string,mixed>
     */
    private function prepareItem(int $supplierId, int $itemId, mixed $input, array $existing): array
    {
        if (!is_array($input)) {
            throw new EshopException('validation_failed', 'Sekce item musí být objekt.', 400);
        }
        $pick = static fn (string $key): mixed => array_key_exists($key, $input) ? $input[$key] : ($existing[$key] ?? null);
        $name = trim((string) $pick('name'));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new EshopException('validation_failed', 'Název karty je povinný (max 255 znaků).', 400);
        }
        // Prázdné SKU znamená „vygenerovat z názvu“, vynechané SKU ponechá uložené.
        $sku = trim((string) $pick('sku'));
        if ($sku === '') {
            $sku = Slugifier::slug($name, '-', 'upper', 50);
        }
        if ($sku === '' || mb_strlen($sku) > 50) {
            throw new EshopException('validation_failed', 'SKU je povinné (max 50 znaků).', 400);
        }
        $bySku = $this->items->findBySku($supplierId, $sku);
        if ($bySku !== null && (int) $bySku['id'] !== $itemId) {
            throw new EshopException('sku_taken', 'Skladová karta s tímto SKU už existuje.', 409);
        }
        $itemType = (string) (array_key_exists('item_type', $input) ? ($input['item_type'] ?? 'goods') : ($existing['item_type'] ?? 'goods'));
        if (!in_array($itemType, ['material', 'goods', 'product', 'service'], true)) {
            throw new EshopException('validation_failed', 'Neplatný typ skladové karty.', 400);
        }
        if ($itemType === 'service' && ($existing['item_type'] ?? '') !== 'service' && $itemId > 0
            && $this->items->hasMovements($supplierId, $itemId)) {
            throw new EshopException('item_has_movements', 'Kartu se skladovými pohyby nelze změnit na službu.', 409);
        }
        $unit = trim((string) (array_key_exists('unit', $input) ? ($input['unit'] ?? 'ks') : ($existing['unit'] ?? 'ks')));
        $trackingMode = (string) ($input['tracking_mode'] ?? $existing['tracking_mode'] ?? 'none');
        if (!in_array($trackingMode, ['none', 'lot', 'serial'], true)) {
            throw new EshopException('validation_failed', 'Neplatný režim sledování skladové karty.', 400);
        }
        if ($itemType === 'service') {
            $trackingMode = 'none';
        }
        if ($trackingMode !== ($existing['tracking_mode'] ?? 'none')) {
            $stmt = $this->db->pdo()->prepare('SELECT 1 FROM stock_tracking_units WHERE supplier_id = ? AND stock_item_id = ? LIMIT 1');
            $stmt->execute([$supplierId, $itemId]);
            if ($stmt->fetchColumn() !== false) {
                throw new EshopException('tracking_mode_in_use', 'Režim sledování nelze změnit po prvním pohybu.', 409);
            }
            $stmt = $this->db->pdo()->prepare('SELECT 1 FROM stock_levels WHERE supplier_id = ? AND stock_item_id = ? AND qty <> 0 LIMIT 1');
            $stmt->execute([$supplierId, $itemId]);
            if ($stmt->fetchColumn() !== false) {
                throw new EshopException('tracking_mode_stock_exists', 'Režim sledování lze zapnout jen při nulovém stavu karty.', 409);
            }
        }

        return [
            'sku' => $sku,
            'name' => $name,
            'item_type' => $itemType,
            'unit' => $unit === '' ? 'ks' : $unit,
            'tracking_mode' => $trackingMode,
            'ean' => $this->stringOrNull($pick('ean')),
            'vat_rate_id' => $this->intOrNull($pick('vat_rate_id')),
            'sale_price_without_vat' => $this->unitPriceOrNull($pick('sale_price_without_vat')),
            'min_qty' => $this->decimalOrNull($pick('min_qty')),
            ...$this->intrastat->normalize($input, $existing),
            'is_active' => (bool) (array_key_exists('is_active', $input) ? ($input['is_active'] ?? true) : ($existing['is_active'] ?? true)),
            'note' => $this->stringOrNull($pick('note')),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);
        return $string === '' ? null : $string;
    }

    private function unitPriceOrNull(mixed $value): ?string
    {
        $number = $this->decimalOrNull($value);
        if ($number !== null && !UnitPricePrecision::isWithinPrecision($number)) {
            throw new EshopException('validation_failed', 'Prodejní cena smí mít nejvýše šest desetinných míst.', 400);
        }
        return $number;
    }

    private function decimalOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($number)) {
            throw new EshopException('validation_failed', 'Neplatná číselná hodnota karty.', 400);
        }
        return $number;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
