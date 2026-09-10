<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\ProductCardService;
use MyInvoice\Service\Eshop\Pricing\PriceWriteService;
use MyInvoice\Service\Eshop\Pricing\PriceCalculationService;
use MyInvoice\Repository\ProductMasterRepository;
use MyInvoice\Service\Eshop\ProductMasterService;
use MyInvoice\Service\Eshop\ProductRelationService;

final class CatalogImportWriter
{
    private const CORE = ['sku', 'name', 'unit', 'ean', 'item_type', 'vat_rate_id', 'min_qty', 'is_active', 'note'];
    private const PRODUCT = ['manufacturer_id', 'warranty_months', 'delivery_days', 'weight_g', 'export_eshop',
        'is_stocked', 'pricing_base', 'categories', 'tag_ids', 'i18n', 'attributes', 'fees'];

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemRepository $items,
        private readonly ProductCardService $cards,
        private readonly PriceWriteService $prices,
        private readonly StockItemPriceRepository $priceRows,
        private readonly PriceCalculationService $calculation,
        private readonly ProductMasterRepository $masters,
        private readonly ProductMasterService $masterService,
        private readonly ProductRelationService $relations,
    ) {}

    public function identify(int $supplierId, array $profile, array $values): ?array
    {
        if ($profile['identity'] === 'sku') {
            return $this->items->findBySku($supplierId, $values['sku']);
        }
        if ($profile['identity'] === 'id') {
            $item = $this->items->find($supplierId, $values['id']);
            if ($item === null) {
                throw new EshopException('unavailable', 'Karta není dostupná.', 422);
            }
            return $item;
        }
        $stmt = $this->db->pdo()->prepare("SELECT internal_id FROM external_entity_map
            WHERE supplier_id = ? AND source_key = ? AND entity_type = 'stock_item' AND external_id = ?");
        $stmt->execute([$supplierId, $profile['source_key'], $values['external_id']]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }
        $item = $this->items->find($supplierId, (int) $id);
        if ($item === null) {
            throw new EshopException('external_identity_unavailable', 'Externí identita odkazuje na nedostupnou kartu.', 422);
        }
        return $item;
    }

    public function state(int $supplierId, int $itemId): array
    {
        $card = $this->cards->get($supplierId, $itemId);
        if ($card === null) {
            throw new EshopException('unavailable', 'Karta není dostupná.', 422);
        }
        $fields = array_merge(self::CORE, self::PRODUCT, ['id', 'row_version', 'sale_price_without_vat']);
        $state = array_intersect_key($card, array_flip($fields));
        $state['prices'] = $this->priceRows->listForItem($supplierId, $itemId);
        $context = $this->masters->variantContext($supplierId, $itemId);
        $state['master_id'] = $context['master_id'] ?? null;
        $state['variant_options'] = $card['variant']['options'] ?? [];
        $state['inheritance'] = $card['variant']['inheritance'] ?? [];
        $state['relations'] = $this->relations->get($supplierId, $itemId)['items'];
        return $state;
    }

    public function write(int $supplierId, array $profile, array $values, ?int $expectedId, ?int $expectedVersion): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Import requires an active item transaction.');
        }
        $current = $this->identify($supplierId, $profile, $values);
        if (($current === null ? null : (int) $current['id']) !== $expectedId
            || ($current !== null && (int) $current['row_version'] !== $expectedVersion)) {
            throw new EshopException('version_conflict', 'Karta byla od náhledu změněna.', 409);
        }
        if (($profile['mode'] === 'create' && $current !== null) || ($profile['mode'] === 'update' && $current === null)) {
            throw new EshopException('import_mode_conflict', 'Řádek neodpovídá zvolenému režimu importu.', 422);
        }
        if (array_key_exists('manufacturer_code', $values) && $values['manufacturer_code'] === null) {
            $values['manufacturer_id'] = null;
        } elseif (isset($values['manufacturer_code'])) {
            $stmt = $pdo->prepare('SELECT id FROM manufacturers WHERE supplier_id = ? AND code = ?');
            $stmt->execute([$supplierId, $values['manufacturer_code']]);
            $manufacturer = $stmt->fetchColumn();
            if ($manufacturer === false || (isset($values['manufacturer_id']) && $values['manufacturer_id'] !== (int) $manufacturer)) {
                throw new EshopException('manufacturer_invalid', 'Výrobce není dostupný nebo mapování není jednoznačné.', 422);
            }
            $values['manufacturer_id'] = (int) $manufacturer;
        }
        if (isset($values['master_external_id'])) {
            if (!is_string($profile['source_key'] ?? null) || $profile['source_key'] === '') {
                throw new EshopException('import_source_key_required', 'Externí master vyžaduje source_key.', 422);
            }
            $stmt = $pdo->prepare("SELECT internal_id FROM external_entity_map
                WHERE supplier_id = ? AND source_key = ? AND entity_type = 'product_master' AND external_id = ?");
            $stmt->execute([$supplierId, $profile['source_key'], $values['master_external_id']]);
            $masterId = $stmt->fetchColumn();
            if ($masterId === false || (isset($values['master_id']) && $values['master_id'] !== (int) $masterId)) {
                throw new EshopException('master_invalid', 'Master produktu není dostupný nebo mapování není jednoznačné.', 422);
            }
            $values['master_id'] = (int) $masterId;
        }
        $base = array_replace($current ?? ['sku' => '', 'name' => '', 'item_type' => 'goods', 'unit' => 'ks', 'is_active' => true],
            array_intersect_key($values, array_flip(self::CORE)));
        if (($base['sku'] ?? '') === '' || ($base['name'] ?? '') === '') {
            throw new EshopException('import_required_value', 'Nová karta vyžaduje SKU a název.', 422);
        }
        if (isset($base['vat_rate_id'])) {
            $stmt = $pdo->prepare('SELECT id FROM vat_rates WHERE id = ?');
            $stmt->execute([$base['vat_rate_id']]);
            if ($stmt->fetchColumn() === false) {
                throw new EshopException('vat_rate_invalid', 'Sazba DPH není dostupná.', 422);
            }
        }
        $sameSku = $this->items->findBySku($supplierId, $base['sku']);
        if ($sameSku !== null && (int) $sameSku['id'] !== $expectedId) {
            throw new EshopException('sku_taken', 'SKU patří jiné kartě.', 422);
        }
        if ($current === null) {
            $id = $this->items->insert($supplierId, $base);
        } else {
            $id = $expectedId;
            if (!$this->items->updateVersioned($supplierId, $id, $expectedVersion, $base)) {
                throw new EshopException('version_conflict', 'Karta byla od náhledu změněna.', 409);
            }
        }
        $product = array_intersect_key($values, array_flip(self::PRODUCT));
        if ($product !== []) {
            $this->cards->updateForEditor($supplierId, $id, $current ?? $base, $product);
        }
        if (array_key_exists('prices', $values)) {
            if (array_key_exists('price', $values)) {
                throw new EshopException('import_price_mapping_conflict', 'Mapujte cenu v CZK nebo měnové ceny, ne obě pole současně.', 422);
            }
            if ($values['prices'] === []) {
                foreach ($this->priceRows->listForItem($supplierId, $id) as $price) {
                    $this->prices->delete($supplierId, $id, $price['currency_code']);
                }
            } else {
                $this->prices->save($supplierId, $id, $values['prices'], false);
            }
        } elseif (array_key_exists('price', $values)) {
            if ($values['price'] === null) {
                $this->prices->delete($supplierId, $id, 'CZK');
            } else {
                $this->prices->save($supplierId, $id, [['currency_code' => 'CZK', 'price_mode' => 'fixed',
                    'fixed_price' => $values['price'], 'rounding' => 'none', 'is_manual_override' => false, 'use_pricing_rules' => false]], false);
            }
        } elseif (array_intersect(array_keys($product), ['manufacturer_id', 'categories', 'pricing_base']) !== []) {
            $this->calculation->recompute($supplierId, $id);
        }
        if ($profile['identity'] === 'external_id' && $current === null) {
            $pdo->prepare("INSERT INTO external_entity_map (supplier_id, source_key, entity_type, external_id, internal_id)
                VALUES (?, ?, 'stock_item', ?, ?)")->execute([$supplierId, $profile['source_key'], $values['external_id'], $id]);
        }
        if (array_intersect(['master_id', 'variant_options', 'inheritance'], array_keys($values)) !== []) {
            $this->writeVariant($supplierId, $id, $values);
        }
        if (array_key_exists('relations', $values)) {
            $card = $this->cards->get($supplierId, $id)
                ?? throw new EshopException('unavailable', 'Karta není dostupná.', 422);
            $this->relations->replace($supplierId, $id, ['row_version' => $card['row_version'], 'items' => $values['relations']]);
        }
        return $this->state($supplierId, $id);
    }

    public function comparable(array $state): array
    {
        unset($state['id'], $state['row_version']);
        foreach (['i18n', 'categories', 'attributes', 'fees', 'prices', 'variant_options', 'relations'] as $section) {
            foreach ($state[$section] ?? [] as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach (['id', 'stock_item_id', 'supplier_id', 'created_at', 'updated_at', 'computed_at'] as $key) {
                    unset($row[$key]);
                }
                if ($section === 'prices') {
                    unset($row['computed_context']['output']['calculation_date']);
                }
                ksort($row);
                $state[$section][$index] = $row;
            }
        }
        ksort($state);
        return $state;
    }

    private function writeVariant(int $supplierId, int $itemId, array $values): void
    {
        $context = $this->masters->variantContext($supplierId, $itemId);
        $targetMasterId = array_key_exists('master_id', $values) ? $values['master_id'] : ($context['master_id'] ?? null);
        if ($targetMasterId === null) {
            if ($context !== null) {
                $preview = $this->masterService->detachPreview($supplierId, $context['master_id'], $itemId);
                $this->masterService->detach($supplierId, $context['master_id'], $itemId, [
                    'row_version' => $preview['row_version'], 'link_row_version' => $preview['link_row_version'],
                ]);
            } elseif (array_intersect(['variant_options', 'inheritance'], array_keys($values)) !== []) {
                throw new EshopException('master_required', 'Volby a dědění vyžadují master produktu.', 422);
            }
            return;
        }
        if (!is_int($targetMasterId) || $targetMasterId < 1) {
            throw new EshopException('master_invalid', 'Neplatný master produktu.', 422);
        }
        if ($context !== null && $context['master_id'] !== $targetMasterId) {
            throw new EshopException('master_change_requires_detach', 'Změna masteru vyžaduje nejprve odpojení varianty.', 409);
        }
        $card = $this->cards->get($supplierId, $itemId)
            ?? throw new EshopException('unavailable', 'Karta není dostupná.', 422);
        $options = $values['variant_options'] ?? ($card['variant']['options'] ?? []);
        $inheritance = $values['inheritance'] ?? ($card['variant']['inheritance'] ?? []);
        if ($context === null) {
            $master = $this->masters->find($supplierId, $targetMasterId)
                ?? throw new EshopException('master_invalid', 'Master produktu není dostupný.', 422);
            $this->masterService->attach($supplierId, $targetMasterId, [
                'master_row_version' => $master['row_version'],
                'variants' => [['stock_item_id' => $itemId, 'row_version' => $card['row_version'],
                    'options' => $options, 'inheritance' => $inheritance]],
            ]);
            return;
        }
        $this->masterService->updateVariant($supplierId, $targetMasterId, $itemId, [
            'row_version' => $card['row_version'], 'link_row_version' => $context['link_row_version'],
            'options' => $options, 'inheritance' => $inheritance,
        ]);
    }
}
