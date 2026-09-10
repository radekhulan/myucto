<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Sets;

use MyInvoice\Service\Eshop\EshopException;

final class ProductSetGraph
{
    public const MAX_DEPTH = 12;
    public const MAX_NODES = 1000;

    public function assertAcyclic(array $definitions): void
    {
        $finished = [];
        $visit = function (int $id, array $path) use (&$visit, &$finished, $definitions): int {
            if (isset($path[$id])) throw new EshopException('set_cycle', 'Složení setu vytváří cyklus.', 422);
            if (!isset($definitions[$id])) return 0;
            if (count($path) >= self::MAX_DEPTH) throw new EshopException('set_depth_exceeded', 'Příliš mnoho úrovní setu.', 422);
            if (isset($finished[$id])) {
                if (count($path) + $finished[$id] > self::MAX_DEPTH) throw new EshopException('set_depth_exceeded', 'Příliš mnoho úrovní setu.', 422);
                return $finished[$id];
            }
            $path[$id] = true;
            $depth = 1;
            foreach (ProductSetDefinition::references($definitions[$id]) as $child) $depth = max($depth, 1 + $visit($child, $path));
            return $finished[$id] = $depth;
        };
        foreach (array_keys($definitions) as $id) $visit((int) $id, []);
    }

    public function expand(int $root, string $quantity, array $definitions, array $selections = []): array
    {
        $quantity = ProductSetDefinition::quantity($quantity);
        $nodes = 0;
        $leaves = [];
        $usedSelections = [];
        $expand = function (int $id, string $qty, array $path) use (&$expand, &$nodes, &$leaves, &$usedSelections, $definitions, $selections): array {
            if (++$nodes > self::MAX_NODES) throw new EshopException('set_size_exceeded', 'Set obsahuje příliš mnoho komponent.', 422);
            if (isset($path[$id])) throw new EshopException('set_cycle', 'Složení setu vytváří cyklus.', 422);
            if (!isset($definitions[$id])) {
                $total = bcadd($leaves[$id] ?? '0', $qty, 3);
                if (bccomp($total, '99999999999.999', 3) > 0) throw new EshopException('set_quantity_unrepresentable', 'Množství komponenty je příliš velké.', 422);
                $leaves[$id] = $total;
                return ['item_id' => $id, 'quantity' => $qty, 'children' => []];
            }
            if (count($path) >= self::MAX_DEPTH) throw new EshopException('set_depth_exceeded', 'Příliš mnoho úrovní setu.', 422);
            $path[$id] = true;
            $definition = $definitions[$id];
            $chosen = $selections[$id] ?? [];
            if (!is_array($chosen)) throw new EshopException('set_selection_invalid', 'Neplatná konfigurace setu.', 422);
            $usedSelections[$id] = true;
            $components = $definition['components'];
            $surcharges = [];
            $groupCodes = [];
            foreach ($definition['groups'] as $group) {
                $groupCodes[] = $group['code'];
                $codes = $chosen[$group['code']] ?? [];
                if (!is_array($codes) || !array_is_list($codes) || count($codes) < $group['min'] || count($codes) > $group['max']) throw new EshopException('set_selection_required', 'Vyberte požadované možnosti setu.', 422);
                foreach ($codes as $code) if (!is_string($code)) throw new EshopException('set_selection_invalid', 'Neplatná možnost setu.', 422);
                if (count(array_unique($codes)) !== count($codes)) throw new EshopException('set_selection_invalid', 'Možnost setu je vybraná vícekrát.', 422);
                $available = array_column($group['options'], null, 'code');
                foreach ($codes as $code) {
                    if (!isset($available[$code])) throw new EshopException('set_selection_invalid', 'Neplatná možnost setu.', 422);
                    $option = $available[$code];
                    $components[] = $option;
                    foreach ($option['surcharges'] as $currency => $amount) $surcharges[$currency] = bcadd($surcharges[$currency] ?? '0', bcmul($amount, $qty, 5), 5);
                }
            }
            if (array_diff(array_keys($chosen), $groupCodes) !== []) throw new EshopException('set_selection_invalid', 'Neznámá skupina setu.', 422);
            $children = [];
            foreach ($components as $component) $children[] = $expand($component['item_id'], ProductSetDefinition::multiply($qty, $component['quantity']), $path);
            if ($children === []) throw new EshopException('set_empty', 'Vybraný set neobsahuje žádné zboží.', 422);
            return ['item_id' => $id, 'quantity' => $qty, 'children' => $children, 'surcharges' => $surcharges];
        };
        $tree = $expand($root, $quantity, []);
        if (array_diff(array_keys($selections), array_keys($usedSelections)) !== []) throw new EshopException('set_selection_invalid', 'Konfigurace odkazuje na nepoužitý set.', 422);
        ksort($leaves);
        return ['tree' => $tree, 'components' => $leaves, 'node_count' => $nodes];
    }
}
