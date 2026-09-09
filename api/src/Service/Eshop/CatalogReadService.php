<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogReadRepository;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;

final class CatalogReadService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CatalogReadRepository $catalog,
        private readonly EffectivePriceResolver $prices,
    ) {}

    public function products(int $supplierId, array $body, bool $allowCosts = false): array
    {
        $request = CatalogReadRequest::products($body);
        if (!$allowCosts && in_array('costs', $request['fields'], true)) {
            throw new EshopException('forbidden_projection', 'Projekce nákladů vyžaduje oprávnění ke správě skladu.', 403);
        }
        return $this->snapshot(function () use ($supplierId, $request): array {
            $products = $this->catalog->products($supplierId, $request);
            if (in_array('prices', $request['fields'], true) && $products !== []) {
                foreach ($products as &$product) {
                    $product['prices'] = [];
                }
                unset($product);
                $date = date('Y-m-d');
                foreach ($request['currencies'] as $currency) {
                    foreach ($this->prices->resolveMany($supplierId, array_keys($products), $currency, '1', $date) as $id => $price) {
                        $products[$id]['prices'][] = $price + ['prices_include_vat' => false, 'on_date' => $date];
                    }
                }
            }
            return self::response($request['ids'], $products);
        });
    }

    public function prices(int $supplierId, array $body): array
    {
        $request = CatalogReadRequest::prices($body);
        return $this->snapshot(function () use ($supplierId, $request): array {
            $ids = array_keys($request['quantities']);
            $products = $this->catalog->products($supplierId, ['ids' => $ids, 'fields' => [], 'locales' => [], 'currencies' => [], 'warehouse_ids' => []]);
            $prices = $this->prices->resolveMany($supplierId, array_keys($products), $request['currency'], $request['quantities'], $request['on_date']);
            foreach ($prices as &$price) {
                $price += ['prices_include_vat' => false, 'on_date' => $request['on_date'], 'row_version' => $products[$price['stock_item_id']]['row_version']];
            }
            unset($price);
            return self::response($ids, $prices);
        });
    }

    private static function response(array $ids, array $products): array
    {
        $items = [];
        foreach ($ids as $id) {
            $items[] = ['id' => $id, 'status' => isset($products[$id]) ? 'ok' : 'unavailable', 'data' => $products[$id] ?? null];
        }
        return ['items' => $items, 'meta' => ['requested' => count($ids), 'found' => count($products)]];
    }

    private function snapshot(callable $read): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
        }
        try {
            $result = $read();
            if ($own) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($own) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
