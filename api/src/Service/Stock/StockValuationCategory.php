<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Repository\StockCategoryRepository;
use MyInvoice\Repository\StockItemRepository;

final class StockValuationCategory
{
    public function __construct(private readonly StockCategoryRepository $categories) {}

    public function resolve(int $supplierId, ?int $categoryId): ?array
    {
        if ($categoryId === null) {
            return null;
        }
        $category = $this->categories->find($supplierId, $categoryId);
        if ($category === null) {
            throw new StockException('validation_failed', 'Kategorie nenalezena.', 422, ['field' => 'category_id']);
        }
        return ['id' => $categoryId, 'code' => (string) $category['code'], 'name' => (string) $category['name']];
    }

    public static function itemIdsSql(): string
    {
        return 'SELECT si.id FROM stock_items si WHERE si.supplier_id = ? AND ' . StockItemRepository::categorySubtreeSql('si');
    }
}
