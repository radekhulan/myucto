<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Repository\StockItemRepository;

final class CatalogSelectionService
{
    public const MAXIMUM = 30000;

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly CatalogJobItemRepository $items,
        private readonly StockItemRepository $stock,
    ) {}

    public function enqueue(int $supplierId, string $kind, array $input, array $selection, ?int $createdBy = null): int
    {
        $selection = self::normalize($selection);
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Vytvoření výběru vyžaduje samostatnou transakci.');
        }
        $pdo->beginTransaction();
        try {
            $id = $this->jobs->enqueue($supplierId, $kind, $input + ['selection' => $selection], createdBy: $createdBy);
            if ($selection['all_matching']) {
                $total = $this->stock->snapshotCatalogSelection($supplierId, $id, $selection['filters'], $selection['excluded_ids'], self::MAXIMUM);
            } else {
                $total = 0;
                foreach (array_chunk($selection['ids'], 500) as $ids) {
                    $versions = $this->stock->versionsForIds($supplierId, $ids);
                    $rows = [];
                    foreach ($ids as $itemId) {
                        $rows[] = ['ordinal' => ++$total, 'stock_item_id' => $itemId, 'expected_version' => $versions[$itemId] ?? null];
                    }
                    $this->items->append($supplierId, $id, $rows);
                    foreach ($rows as $row) {
                        if ($row['expected_version'] === null) {
                            $this->items->finish($supplierId, $id, $row['ordinal'], 'failed', errorCode: 'unavailable');
                        }
                    }
                }
            }
            $pdo->prepare('UPDATE catalog_jobs SET total = ? WHERE supplier_id = ? AND id = ?')->execute([$total, $supplierId, $id]);
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function normalize(array $selection): array
    {
        if (array_diff(array_keys($selection), ['all_matching', 'ids', 'filters', 'excluded_ids']) !== []) {
            throw new \InvalidArgumentException('Neznámé pole výběru.');
        }
        $all = $selection['all_matching'] ?? false;
        if (!is_bool($all)) {
            throw new \InvalidArgumentException('Neplatný režim výběru.');
        }
        if ($all) {
            if (array_key_exists('ids', $selection) || !is_array($selection['filters'] ?? null)) {
                throw new \InvalidArgumentException('Výběr všech výsledků vyžaduje filtry a nesmí obsahovat ID.');
            }
            return ['all_matching' => true, 'filters' => CatalogFilter::normalize($selection['filters']),
                'excluded_ids' => self::ids($selection['excluded_ids'] ?? [])];
        }
        if (array_key_exists('filters', $selection) || array_key_exists('excluded_ids', $selection)) {
            throw new \InvalidArgumentException('Výběr ID nesmí obsahovat filtry ani výjimky.');
        }
        $ids = self::ids($selection['ids'] ?? null);
        if ($ids === []) {
            throw new \InvalidArgumentException('Vyberte alespoň jednu položku.');
        }
        return ['all_matching' => false, 'ids' => $ids];
    }

    private static function ids(mixed $ids): array
    {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > self::MAXIMUM) {
            throw new \InvalidArgumentException('Neplatný seznam ID nebo překročený limit výběru.');
        }
        $result = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('ID musí být kladné celé číslo.');
            }
            $result[$id] = $id;
        }
        return array_values($result);
    }
}
