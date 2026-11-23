<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Souhrn ocenění zásob podle kategorií (sestava Ocenění k datu).
 *
 * Skupiny jsou přímé podkategorie zvolené kategorie (bez ní kategorie nejvyšší
 * úrovně), dále „přímo ve zvolené kategorii“ a „ostatní“ (karta bez kategorie
 * nebo jen mimo zvolený strom). Karta ve více kategoriích patří do jediné skupiny:
 * rozhoduje její primární kategorie, jinak první kategorie v pořadí karty, vždy
 * jen mezi kategoriemi zvoleného stromu. Každý řádek ocenění tak padne právě do
 * jedné skupiny a součet skupin se rovná celkovému ocenění (počítá se v haléřích).
 *
 * Zařazení vychází z dnešních kategorií karet, ne z kategorií k datu ocenění.
 * Výpočet je jeden dotaz na zařazení karet (okenní funkce, bez dotazu na řádek)
 * a součet v PHP nad řádky ocenění, proto funguje stejně pro úlohu na pozadí
 * i pro okamžité ocenění.
 */
final class StockValuationCategoryGroups
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Parametry seskupení z query stringu: `group_by=category` zapne seskupení,
     * `group_parent_id` zvolí kategorii, jejíž přímé podkategorie tvoří skupiny.
     *
     * @param array<string,mixed> $q
     * @return array{parent_id:?int}|null null = bez seskupení
     */
    public static function normalize(array $q): ?array
    {
        $groupBy = trim((string) ($q['group_by'] ?? ''));
        if ($groupBy === '' || $groupBy === 'none') {
            return null;
        }
        if ($groupBy !== 'category') {
            throw new StockException('validation_failed', 'Neznámé seskupení.', 422, ['field' => 'group_by']);
        }
        $parent = $q['group_parent_id'] ?? null;
        if ($parent === null || $parent === '') {
            return ['parent_id' => null];
        }
        $id = filter_var($parent, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new StockException('validation_failed', 'Neplatná kategorie seskupení.', 422, ['field' => 'group_parent_id']);
        }
        return ['parent_id' => $id];
    }

    /**
     * @param iterable<array{stock_item_id:int|string, value_total:string}> $rows řádky ocenění (sklad × karta)
     * @return array{parent:?array{id:int,code:string,name:string}, groups:list<array{kind:string,category_id:?int,code:string,name:string,count:int,value_total:string}>, value_total:string, count:int}
     */
    public function summarize(int $supplierId, ?int $parentId, iterable $rows): array
    {
        $plan = $this->plan($supplierId, $parentId);
        $sums = [];
        $counts = [];
        $totalC = 0;
        $total = 0;
        foreach ($rows as $row) {
            $key = $plan['groupOf'][(int) $row['stock_item_id']] ?? 'uncategorized';
            $valueC = StockValuation::valueToC((string) $row['value_total']);
            $sums[$key] = ($sums[$key] ?? 0) + $valueC;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $totalC += $valueC;
            $total++;
        }

        return [
            'parent' => $plan['parent'] !== null ? ['id' => $plan['parent']['id'], 'code' => $plan['parent']['code'], 'name' => $plan['parent']['name']] : null,
            'groups' => $this->build($plan, $counts, $sums),
            'value_total' => StockValuation::cToDecimal($totalC),
            'count' => $total,
        ];
    }

    /**
     * Zařazení karet do skupin předem (jeden dotaz), aby šlo řádky sčítat průběžně;
     * `$itemIds` zúží zařazení na dávku karet (prázdné pole = jen strom kategorií).
     *
     * @return array{parent:?array<string,mixed>, prefix:string, categories:array<int,array<string,mixed>>, groupOf:array<int,int|string>}
     */
    public function plan(int $supplierId, ?int $parentId, ?array $itemIds = null): array
    {
        $categories = $this->categories($supplierId);
        $parent = null;
        $prefix = '/';
        if ($parentId !== null) {
            if (!isset($categories[$parentId])) {
                throw new StockException('validation_failed', 'Kategorie seskupení nenalezena.', 422, ['field' => 'group_parent_id']);
            }
            $parent = $categories[$parentId];
            $prefix = $parent['path'];
        }

        // Skupina každé karty: id přímé podkategorie, 'parent' nebo (chybí) ostatní.
        $groupOf = [];
        foreach ($this->assignedCategories($supplierId, $prefix, $itemIds) as $itemId => $categoryId) {
            if ($categoryId === $parentId) {
                $groupOf[$itemId] = 'parent';
                continue;
            }
            $childId = (int) strtok(substr($categories[$categoryId]['path'] ?? '', strlen($prefix)), '/');
            // Neplatná cesta by řádek vyřadila ze všech skupin; součet musí sedět vždy.
            if (isset($categories[$childId]) && $categories[$childId]['parent_id'] === $parentId) {
                $groupOf[$itemId] = $childId;
            }
        }
        return ['parent' => $parent, 'prefix' => $prefix, 'categories' => $categories, 'groupOf' => $groupOf];
    }

    /**
     * Skupiny v pořadí stromu, „přímo v kategorii“ a „ostatní“ na konci.
     *
     * @param array<string,mixed> $plan výstup {@see plan()}
     * @param array<int|string,int> $counts
     * @param array<int|string,int> $sums haléře
     * @return list<array{kind:string,category_id:?int,code:string,name:string,count:int,value_total:string}>
     */
    public function build(array $plan, array $counts, array $sums): array
    {
        $parentId = $plan['parent']['id'] ?? null;
        $groups = [];
        foreach ($plan['categories'] as $id => $category) {
            if ($category['parent_id'] !== $parentId || ($category['archived'] && !isset($counts[$id]))) {
                continue;
            }
            $groups[] = self::group('category', $category, $counts[$id] ?? 0, $sums[$id] ?? 0);
        }
        if (isset($counts['parent'])) {
            $groups[] = self::group('parent', $plan['parent'], $counts['parent'], $sums['parent']);
        }
        if (isset($counts['uncategorized'])) {
            $groups[] = self::group('uncategorized', null, $counts['uncategorized'], $sums['uncategorized']);
        }
        return $groups;
    }

    /**
     * @param array{id:int,code:string,name:string}|null $category
     * @return array{kind:string,category_id:?int,code:string,name:string,count:int,value_total:string}
     */
    private static function group(string $kind, ?array $category, int $count, int $valueC): array
    {
        return [
            'kind' => $kind,
            'category_id' => $category['id'] ?? null,
            'code' => $category['code'] ?? '',
            'name' => $category['name'] ?? '',
            'count' => $count,
            'value_total' => StockValuation::cToDecimal($valueC),
        ];
    }

    /**
     * Strom kategorií firmy v pořadí zobrazení (display_order, název).
     *
     * @return array<int,array{id:int,parent_id:?int,code:string,name:string,path:string,archived:bool}>
     */
    private function categories(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, parent_id, code, name, path, archived FROM stock_categories
            WHERE supplier_id = ? ORDER BY display_order, name, id');
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'],
                'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
                'code' => (string) $r['code'],
                'name' => (string) $r['name'],
                'path' => (string) $r['path'],
                'archived' => (bool) $r['archived'],
            ];
        }
        return $out;
    }

    /**
     * Jedna kategorie na kartu ze stromu pod `$prefix`: primární, jinak první
     * v pořadí karty (při shodě nižší id).
     *
     * @return array<int,int> stock_item_id => category_id
     */
    private function assignedCategories(int $supplierId, string $prefix, ?array $itemIds = null): array
    {
        if ($itemIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare("SELECT stock_item_id, category_id FROM (
                SELECT sic.stock_item_id, sic.category_id,
                       ROW_NUMBER() OVER (PARTITION BY sic.stock_item_id
                                          ORDER BY sic.is_primary DESC, sic.display_order, sic.category_id) AS pick
                  FROM stock_item_categories sic
                  JOIN stock_categories sc ON sc.id = sic.category_id AND sc.supplier_id = sic.supplier_id
                 WHERE sic.supplier_id = ? AND sc.path LIKE ?" . ($itemIds !== null ? ' AND sic.stock_item_id IN (' . implode(',', array_map('intval', $itemIds)) . ')' : '') . "
            ) ranked WHERE pick = 1");
        $stmt->execute([$supplierId, addcslashes($prefix, '%_\\') . '%']);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) ?: [] as [$itemId, $categoryId]) {
            $out[(int) $itemId] = (int) $categoryId;
        }
        return $out;
    }
}
