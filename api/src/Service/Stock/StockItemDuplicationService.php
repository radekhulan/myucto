<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Pricing\PriceWriteService;
use MyInvoice\Service\Eshop\Pricing\PricingInputException;
use MyInvoice\Service\Eshop\ProductCardService;
use MyInvoice\Service\Eshop\ProductVendorWriteService;

final class StockItemDuplicationService
{
    public const SECTIONS = ['core', 'product', 'i18n', 'categories', 'tags', 'attributes', 'fees', 'prices', 'vendors'];

    private const ITEM_FIELDS = [
        'core' => ['item_type', 'unit', 'vat_rate_id', 'min_qty', 'note'],
        'product' => ['manufacturer_id', 'warranty_months', 'delivery_days', 'is_stocked', 'weight_g', 'pricing_base'],
    ];

    private const RELATED_FIELDS = [
        'i18n' => ['stock_item_i18n', ['locale', 'name', 'short_desc', 'description', 'seo_title', 'seo_description']],
        'categories' => ['stock_item_categories', ['category_id', 'is_primary', 'display_order']],
        'tags' => ['stock_item_tags', ['tag_id']],
        'attributes' => ['stock_item_attribute_values', ['attribute_id', 'option_id', 'value_text', 'value_num', 'value_bool', 'display_order']],
        'fees' => ['stock_item_fees', ['fee_type_id', 'amount', 'currency_code', 'vat_included']],
        'prices' => ['stock_item_prices', ['currency_code', 'price_mode', 'markup_pct', 'fixed_price', 'rounding', 'is_manual_override', 'use_pricing_rules']],
        'vendors' => ['stock_item_vendors', ['client_id', 'purchase_price', 'currency_code', 'delivery_days', 'stock_qty', 'availability_state', 'stock_qty_updated_at', 'min_order_qty', 'package_qty', 'price_valid_to', 'data_source', 'is_active', 'is_preferred', 'note']],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemRepository $items,
        private readonly ProductCardService $productCards,
        private readonly ProductVendorWriteService $vendorWriter,
        private readonly PriceWriteService $priceWriter,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function duplicate(int $supplierId, int $sourceId, array $input): array
    {
        [$sku, $name] = $this->identity($input);
        $sections = $this->normalizeSections($input['sections'] ?? null);
        $expectedVersion = (int) ($input['row_version'] ?? 0);
        if ($expectedVersion <= 0) {
            throw new StockException('version_required', 'Pro duplikaci je nutná verze zdrojové karty.', 400);
        }

        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Duplikace skladové karty očekává vlastní transakci.');
        }
        $pdo->beginTransaction();
        try {
            $snapshot = $this->captureSnapshot($supplierId, $sourceId, $sections, $expectedVersion);
            $newId = $this->instantiateSnapshot($supplierId, $snapshot, $sku, $name);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->items->find($supplierId, $newId) ?? throw new \LogicException('Duplikovaná karta chybí.');
    }

    /**
     * Volající drží transakci. Snapshot obsahuje pouze explicitně vybrané sekce.
     *
     * @param list<string> $sections
     * @return array{version:int,sections:list<string>,item:array<string,mixed>,related:array<string,list<array<string,mixed>>>}
     */
    public function captureSnapshot(int $supplierId, int $sourceId, array $sections, int $expectedVersion): array
    {
        $sections = $this->normalizeSections($sections);
        $stmt = $this->db->pdo()->prepare('SELECT * FROM stock_items WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $stmt->execute([$supplierId, $sourceId]);
        $source = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($source === false) {
            throw new StockException('not_found', 'Zdrojová skladová karta nenalezena.', 404);
        }
        if ((int) $source['row_version'] !== $expectedVersion) {
            throw new StockException('version_conflict', 'Zdrojová karta se mezitím změnila.', 409);
        }

        $item = [];
        foreach (self::ITEM_FIELDS as $section => $fields) {
            if (!in_array($section, $sections, true)) {
                continue;
            }
            foreach ($fields as $field) {
                $item[$field] = $source[$field] ?? null;
            }
        }

        $related = [];
        foreach (self::RELATED_FIELDS as $section => [$table, $fields]) {
            if (!in_array($section, $sections, true)) {
                continue;
            }
            $query = $this->db->pdo()->prepare(
                'SELECT ' . implode(', ', $fields) . " FROM {$table} WHERE supplier_id = ? AND stock_item_id = ? ORDER BY " . $this->orderBy($section)
            );
            $query->execute([$supplierId, $sourceId]);
            $related[$section] = $query->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        return ['version' => 1, 'sections' => $sections, 'item' => $item, 'related' => $related];
    }

    /**
     * Volající drží transakci. Vytvořená karta je vždy nový nepublikovaný koncept.
     *
     * @param array<string,mixed> $snapshot
     */
    public function instantiateSnapshot(int $supplierId, array $snapshot, string $sku, string $name): int
    {
        if (($snapshot['version'] ?? null) !== 1 || !is_array($snapshot['item'] ?? null) || !is_array($snapshot['related'] ?? null)) {
            throw new StockException('invalid_template', 'Obsah šablony není podporovaný.', 422);
        }
        $sections = $this->normalizeSections($snapshot['sections'] ?? null);
        if ($this->items->findBySku($supplierId, $sku) !== null) {
            throw new StockException('sku_taken', 'Skladová karta s tímto SKU už existuje.', 409);
        }

        $item = (array) $snapshot['item'];
        $newId = $this->items->insert($supplierId, [
            'sku' => $sku,
            'name' => $name,
            'item_type' => in_array('core', $sections, true) ? ($item['item_type'] ?? 'goods') : 'goods',
            'unit' => in_array('core', $sections, true) ? ($item['unit'] ?? 'ks') : 'ks',
            'vat_rate_id' => in_array('core', $sections, true) ? ($item['vat_rate_id'] ?? null) : null,
            'sale_price_without_vat' => null,
            'min_qty' => in_array('core', $sections, true) ? ($item['min_qty'] ?? null) : null,
            'is_active' => false,
            'note' => in_array('core', $sections, true) ? ($item['note'] ?? null) : null,
        ]);
        $this->db->pdo()->prepare("UPDATE stock_items SET lifecycle_status = 'draft', export_eshop = 0 WHERE supplier_id = ? AND id = ?")
            ->execute([$supplierId, $newId]);

        $related = $snapshot['related'];
        $productPayload = [];
        if (in_array('product', $sections, true)) {
            foreach (self::ITEM_FIELDS['product'] as $field) {
                $productPayload[$field] = $item[$field] ?? null;
            }
        }
        foreach (['i18n', 'categories', 'attributes', 'fees'] as $section) {
            if (in_array($section, $sections, true)) {
                $productPayload[$section] = $this->snapshotRows($related, $section);
            }
        }
        if (in_array('tags', $sections, true)) {
            $productPayload['tag_ids'] = array_map(
                static fn (array $row): int => (int) ($row['tag_id'] ?? 0),
                $this->snapshotRows($related, 'tags'),
            );
        }

        try {
            if ($productPayload !== []) {
                $base = $this->items->find($supplierId, $newId)
                    ?? throw new \LogicException('Nová skladová karta chybí.');
                $this->productCards->updateForEditor($supplierId, $newId, $base, $productPayload);
            }
            if (in_array('vendors', $sections, true)) {
                $this->vendorWriter->saveSnapshot($supplierId, $newId, $this->snapshotRows($related, 'vendors'));
            }
            if (in_array('prices', $sections, true)) {
                $prices = $this->priceWriter->save(
                    $supplierId,
                    $newId,
                    $this->snapshotRows($related, 'prices'),
                    true,
                );
                foreach ($prices as $price) {
                    if (($price['computed_price'] ?? null) === null) {
                        throw new StockException(
                            'missing_purchase_cost',
                            'Cenovou definici nelze použít bez nákladové ceny nebo platného cenového pravidla.',
                            422,
                            ['currency_code' => $price['currency_code'] ?? null],
                        );
                    }
                }
            }
        } catch (PricingInputException $e) {
            throw new StockException($e->errorCode, $e->getMessage(), $e->httpStatus(), $e->details);
        } catch (EshopException $e) {
            throw new StockException($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        } catch (\InvalidArgumentException $e) {
            throw new StockException('validation_failed', $e->getMessage(), 422);
        }

        return $newId;
    }

    /** @param mixed $value @return list<string> */
    public function normalizeSections(mixed $value): array
    {
        if (!is_array($value)) {
            throw new StockException('validation_failed', 'Sekce musí být pole.', 422);
        }
        $sections = [];
        foreach ($value as $section) {
            if (!is_string($section)) {
                throw new StockException('validation_failed', 'Každá sekce musí být textový identifikátor.', 422);
            }
            if (!in_array($section, $sections, true)) {
                $sections[] = $section;
            }
        }
        foreach ($sections as $section) {
            if (!in_array($section, self::SECTIONS, true)) {
                throw new StockException('validation_failed', 'Neznámá sekce.', 422);
            }
        }
        return $sections;
    }

    /** @param array<string,mixed> $input @return array{0:string,1:string} */
    public function identity(array $input): array
    {
        if (!isset($input['sku'], $input['name']) || !is_string($input['sku']) || !is_string($input['name'])) {
            throw new StockException('validation_failed', 'Nová karta vyžaduje vlastní SKU a název.', 422);
        }
        $sku = trim($input['sku']);
        $name = trim($input['name']);
        if ($sku === '' || mb_strlen($sku) > 50 || $name === '' || mb_strlen($name) > 255) {
            throw new StockException('validation_failed', 'Nová karta vyžaduje vlastní SKU a název.', 422);
        }
        return [$sku, $name];
    }

    /**
     * @param array<string,mixed> $related
     * @return list<array<string,mixed>>
     */
    private function snapshotRows(array $related, string $section): array
    {
        $rows = $related[$section] ?? [];
        if (!is_array($rows)) {
            throw new StockException('invalid_template', 'Obsah šablony není podporovaný.', 422);
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new StockException('invalid_template', 'Obsah šablony není podporovaný.', 422);
            }
        }
        return array_values($rows);
    }

    private function orderBy(string $section): string
    {
        return match ($section) {
            'i18n' => 'locale, id',
            'categories' => 'display_order, category_id',
            'tags' => 'tag_id',
            'attributes' => 'display_order, id',
            'fees', 'prices', 'vendors' => 'id',
            default => 'stock_item_id',
        };
    }
}
