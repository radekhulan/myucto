<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Sets;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;
use PDO;

final class ProductSetService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ProductSetGraph $graph,
        private readonly ProductSetQuoteCalculator $calculator,
        private readonly EffectivePriceResolver $prices,
    ) {}

    public function get(int $supplierId, int $itemId): ?array
    {
        $query = $this->db->pdo()->prepare('SELECT * FROM product_sets WHERE supplier_id = ? AND stock_item_id = ?');
        $query->execute([$supplierId, $itemId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->present($row);
    }

    public function save(int $supplierId, int $itemId, int $expectedVersion, array $definition): array
    {
        $definition = ProductSetDefinition::normalize($definition);
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $tenant = $pdo->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE');
            $tenant->execute([$supplierId]);
            if (!$tenant->fetchColumn()) throw new EshopException('not_found', 'Firma nenalezena.', 404);
            $existing = $this->get($supplierId, $itemId);
            if (($existing['row_version'] ?? 0) !== $expectedVersion) throw new EshopException('version_conflict', 'Set mezitím změnil jiný uživatel.', 409);
            $definitions = $this->definitions($supplierId);
            $definitions[$itemId] = $definition;
            $this->graph->assertAcyclic($definitions);
            $ids = array_values(array_unique([$itemId, ...ProductSetDefinition::references($definition)]));
            sort($ids, SORT_NUMERIC);
            $cards = $this->cards($supplierId, $ids, true);
            $root = $cards[$itemId];
            if ((bool) $root['is_stocked']) throw new EshopException('set_stocked_card', 'Virtuální set musí používat kartu bez vlastní skladové zásoby.', 422);
            if (!(bool) $root['is_active']) throw new EshopException('set_inactive_card', 'Set musí používat aktivní kartu.', 422);
            foreach ($ids as $id) {
                if (!(bool) $cards[$id]['is_active']) throw new EshopException('set_inactive_component', 'Komponenta setu není aktivní.', 422);
            }
            $version = $expectedVersion + 1;
            $json = json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $query = $pdo->prepare('INSERT INTO product_sets (supplier_id, stock_item_id, row_version, definition_json) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE row_version = VALUES(row_version), definition_json = VALUES(definition_json)');
            $query->execute([$supplierId, $itemId, $version, $json]);
            $query = $pdo->prepare('INSERT INTO product_set_revisions (supplier_id, stock_item_id, row_version, definition_json) VALUES (?, ?, ?, ?)');
            $query->execute([$supplierId, $itemId, $version, $json]);
            $query = $pdo->prepare('UPDATE stock_items SET row_version = row_version + 1 WHERE supplier_id = ? AND id = ?');
            $query->execute([$supplierId, $itemId]);
            $result = $this->get($supplierId, $itemId);
            if ($own) $pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public function configuration(int $supplierId, int $itemId): array
    {
        $set = $this->get($supplierId, $itemId);
        $all = $this->definitions($supplierId);
        $definitions = [];
        $ids = [];
        $visit = function (int $id) use (&$visit, &$ids, &$definitions, $all): void {
            if (isset($ids[$id])) return;
            if (count($ids) >= 5000) throw new EshopException('set_size_exceeded', 'Set obsahuje příliš mnoho komponent.', 422);
            $ids[$id] = true;
            if (!isset($all[$id])) return;
            $definitions[$id] = $all[$id];
            foreach (ProductSetDefinition::references($all[$id]) as $child) $visit($child);
        };
        $visit($itemId);
        return ['set' => $set, 'definitions' => $definitions, 'cards' => array_values($this->cards($supplierId, array_keys($ids)))];
    }

    public function definitions(int $supplierId): array
    {
        $query = $this->db->pdo()->prepare('SELECT stock_item_id, definition_json FROM product_sets WHERE supplier_id = ? ORDER BY stock_item_id LIMIT 5001');
        $query->execute([$supplierId]);
        $result = [];
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            if (count($result) >= 5000) throw new EshopException('set_catalog_limit', 'Katalog překročil limit definic setů.', 422);
            $result[(int) $row['stock_item_id']] = json_decode($row['definition_json'], true, 512, JSON_THROW_ON_ERROR);
        }
        return $result;
    }

    public function quote(int $supplierId, int $itemId, string $currency, string $quantity, array $selections = []): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $result = $this->quoteSnapshot($supplierId, $itemId, $currency, $quantity, $selections);
            if ($own) $pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private function quoteSnapshot(int $supplierId, int $itemId, string $currency, string $quantity, array $selections): array
    {
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) throw new EshopException('currency_invalid', 'Neplatná měna.', 422);
        $set = $this->get($supplierId, $itemId);
        if ($set === null) throw new EshopException('not_found', 'Set nenalezen.', 404);
        $definitions = $this->definitions($supplierId);
        $expanded = $this->graph->expand($itemId, $quantity, $definitions, $selections);
        $usedDefinitions = [];
        $visit = function (array $node) use (&$visit, &$usedDefinitions, $definitions): void {
            if (isset($definitions[$node['item_id']])) $usedDefinitions[$node['item_id']] = $definitions[$node['item_id']];
            foreach ($node['children'] as $child) $visit($child);
        };
        $visit($expanded['tree']);
        foreach ($this->cards($supplierId, array_keys($usedDefinitions)) as $card) {
            if (!(bool) $card['is_active'] || (bool) $card['is_stocked']) throw new EshopException('set_invalid_card', 'Set vyžaduje aktivní kartu bez vlastní skladové zásoby.', 422);
        }
        $cards = $this->cards($supplierId, array_keys($expanded['components']));
        $vatRates = [];
        foreach ($cards as $card) {
            if (!(bool) $card['is_active']) throw new EshopException('set_inactive_component', 'Komponenta setu není aktivní.', 422);
            $vatRates[(string) $card['vat_rate_id']] = true;
        }
        if (count($vatRates) !== 1) throw new EshopException('set_mixed_vat', 'Set s rozdílnými sazbami DPH zatím nelze ocenit.', 422);
        $resolved = $this->prices->resolveMany($supplierId, array_keys($cards), $currency, $expanded['components']);
        $prices = [];
        foreach ($cards as $id => $card) {
            $price = $resolved[$id]['unit_price'] ?? null;
            if ($price === null) throw new EshopException('set_price_missing', 'Komponenta nemá platnou cenu ve zvolené měně.', 422);
            $prices[$id] = $price;
        }
        $quote = $this->calculator->calculate($expanded['tree'], $definitions, $prices, $currency);
        return ['stock_item_id' => $itemId, 'row_version' => $set['row_version'], 'currency_code' => $currency, 'quantity' => ProductSetDefinition::quantity($quantity), 'prices_include_vat' => false, 'vat_rate_id' => (int) array_key_first($vatRates), 'selections' => $selections, 'definition_snapshot' => $usedDefinitions, 'component_snapshot' => array_values($cards), 'quote' => $quote];
    }

    private function cards(int $supplierId, array $ids, bool $lock = false): array
    {
        if ($ids === []) throw new EshopException('set_empty', 'Set nemá komponenty.', 422);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $query = $this->db->pdo()->prepare("SELECT id, sku, ean, name, unit, vat_rate_id, is_active, is_stocked, row_version FROM stock_items WHERE supplier_id = ? AND id IN ({$placeholders}) ORDER BY id" . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$supplierId, ...$ids]);
        $result = [];
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) $result[(int) $row['id']] = $row;
        if (count($result) !== count($ids)) throw new EshopException('set_component_not_found', 'Karta nebo komponenta setu nenalezena.', 404);
        return $result;
    }

    private function present(array $row): array
    {
        $row['stock_item_id'] = (int) $row['stock_item_id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['row_version'] = (int) $row['row_version'];
        $row['definition'] = json_decode($row['definition_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['definition_json']);
        return $row;
    }
}
