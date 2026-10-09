<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Sets;

use MyInvoice\I18n\ErrorCatalog;
use MyInvoice\I18n\Locale;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Service\Eshop\EshopException;
use PDO;

/**
 * Sady (virtuální sety) pro veřejné API `/api/v1/stock/sets` (issue #138).
 *
 * Definici ukládá výhradně {@see ProductSetService::save()}, tedy stejná cesta
 * jako editor setu v aplikaci: normalizace, kontrola cyklů a hloubky, komponenty
 * jen z vlastní firmy, aktivní karty a optimistická verze `row_version`. Tahle
 * vrstva přidává jen to, co API potřebuje navíc: založení karty sady spolu
 * s definicí, odkaz na komponentu přes SKU a dávkový upsert podle SKU.
 */
final class ProductSetApiService
{
    public const BULK_LIMIT = 200;

    private const CARD_FIELDS = ['sku', 'name', 'unit', 'vat_rate_id', 'ean', 'note', 'is_active', 'item_type'];

    public function __construct(
        private readonly Connection $db,
        private readonly ProductSetService $sets,
        private readonly StockItemRepository $items,
    ) {}

    /**
     * @param array{q?:string, sku?:string, active?:bool, updated_since?:string} $filters
     * @return array{0:list<array<string,mixed>>, 1:int}
     */
    public function list(int $supplierId, array $filters, int $perPage, int $offset): array
    {
        $where = ['ps.supplier_id = ?'];
        $params = [$supplierId];
        if (isset($filters['q']) && $filters['q'] !== '') {
            $where[] = '(si.sku LIKE ? OR si.name LIKE ?)';
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            array_push($params, $like, $like);
        }
        if (isset($filters['sku'])) {
            $where[] = 'si.sku = ?';
            $params[] = $filters['sku'];
        }
        if (isset($filters['active'])) {
            $where[] = 'si.is_active = ?';
            $params[] = $filters['active'] ? 1 : 0;
        }
        if (isset($filters['updated_since'])) {
            $where[] = 'ps.updated_at >= ?';
            $params[] = $filters['updated_since'];
        }
        $from = ' FROM product_sets ps JOIN stock_items si ON si.id = ps.stock_item_id AND si.supplier_id = ps.supplier_id WHERE ' . implode(' AND ', $where);
        $count = $this->db->pdo()->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $query = $this->db->pdo()->prepare('SELECT ' . $this->columns() . $from
            . ' ORDER BY ps.stock_item_id LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, $offset));
        $query->execute($params);
        return [array_map([$this, 'present'], $query->fetchAll(PDO::FETCH_ASSOC) ?: []), $total];
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $itemId): ?array
    {
        $query = $this->db->pdo()->prepare('SELECT ' . $this->columns()
            . ' FROM product_sets ps JOIN stock_items si ON si.id = ps.stock_item_id AND si.supplier_id = ps.supplier_id'
            . ' WHERE ps.supplier_id = ? AND ps.stock_item_id = ?');
        $query->execute([$supplierId, $itemId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->present($row);
    }

    /**
     * Založí kartu sady (bez skladové zásoby) a její definici v jedné transakci.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $input): array
    {
        $card = $this->card($input, true);
        $definition = $this->definitionInput($input);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            if ($this->items->findBySku($supplierId, $card['sku']) !== null) {
                throw new EshopException('sku_taken', 'Skladová karta s tímto SKU už existuje.', 409);
            }
            $id = $this->insertCard($supplierId, $card);
            $this->sets->save($supplierId, $id, 0, $this->resolve($supplierId, $definition));
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof \PDOException && (string) $error->getCode() === '23000') {
                throw new EshopException('sku_taken', 'Skladová karta s tímto SKU už existuje.', 409);
            }
            throw $error;
        }
        return $this->find($supplierId, $id) ?? throw new EshopException('not_found', 'Sada nenalezena.', 404);
    }

    /**
     * Nová definice existující karty. Karta bez definice se stane sadou s verzí 0,
     * stejně jako při prvním uložení v editoru.
     *
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    public function update(int $supplierId, int $itemId, int $expectedVersion, array $definition): array
    {
        if ($this->items->find($supplierId, $itemId) === null) {
            throw new EshopException('not_found', 'Skladová karta nenalezena.', 404);
        }
        $this->sets->save($supplierId, $itemId, $expectedVersion, $this->resolve($supplierId, $definition));
        return $this->find($supplierId, $itemId) ?? throw new EshopException('not_found', 'Sada nenalezena.', 404);
    }

    /**
     * Dávkový upsert podle SKU. Každá položka běží ve vlastní transakci, chyba
     * jedné položky ostatní neovlivní. Položka, jejíž uložená definice se shoduje
     * s požadovanou, se nemění (`unchanged`), takže opakované odeslání téže
     * dávky je bezpečné.
     *
     * @param list<mixed> $rows
     * @return array{summary:array<string,int>, results:list<array<string,mixed>>}
     */
    public function bulk(int $supplierId, array $rows): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
        $results = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $sku = is_array($row) && is_string($row['sku'] ?? null) ? trim($row['sku']) : null;
            try {
                if (!is_array($row) || array_is_list($row)) {
                    throw new EshopException('validation_failed', 'Položka dávky musí být objekt.', 422);
                }
                if ($sku !== null && isset($seen[mb_strtolower($sku)])) {
                    throw new EshopException('duplicate_in_batch', 'SKU se v dávce opakuje.', 422);
                }
                if ($sku !== null) $seen[mb_strtolower($sku)] = true;
                $result = $this->upsert($supplierId, $row);
            } catch (EshopException $error) {
                $result = ['status' => 'error', 'stock_item_id' => null, 'row_version' => null, 'card_created' => false,
                    'error' => ['code' => $error->errorCode, 'message' => ErrorCatalog::lookup($error->getMessage(), Locale::current())] + $error->details];
            }
            $summary[$result['status'] === 'error' ? 'failed' : $result['status']]++;
            $results[] = ['index' => $index, 'sku' => $sku] + $result;
        }
        return ['summary' => $summary, 'results' => $results];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function upsert(int $supplierId, array $row): array
    {
        $card = $this->card($row, false);
        $definition = $this->definitionInput($row);
        $expected = $row['row_version'] ?? null;
        if ($expected !== null && (!is_int($expected) || $expected < 0)) {
            throw new EshopException('validation_failed', 'row_version musí být nezáporné celé číslo.', 422);
        }
        $existing = $this->items->findBySku($supplierId, $card['sku']);
        if ($existing === null) {
            if (!isset($card['name'])) throw new EshopException('validation_failed', 'Nová sada potřebuje název (name).', 422);
            $set = $this->create($supplierId, $card + ['definition' => $definition]);
            return ['status' => 'created', 'stock_item_id' => $set['stock_item_id'], 'row_version' => $set['row_version'], 'card_created' => true];
        }
        $itemId = (int) $existing['id'];
        $resolved = $this->resolve($supplierId, $definition);
        $current = $this->sets->get($supplierId, $itemId);
        if ($current !== null && $current['definition'] === ProductSetDefinition::normalize($resolved)) {
            return ['status' => 'unchanged', 'stock_item_id' => $itemId, 'row_version' => $current['row_version'], 'card_created' => false];
        }
        $version = $expected ?? (int) ($current['row_version'] ?? 0);
        $saved = $this->sets->save($supplierId, $itemId, $version, $resolved);
        return ['status' => $current === null ? 'created' : 'updated', 'stock_item_id' => $itemId, 'row_version' => $saved['row_version'], 'card_created' => false];
    }

    /**
     * Komponenta i volba skupiny smí místo `item_id` nést `sku` karty. SKU se
     * hledá jen ve firmě požadavku, cizí ani neexistující karta neprojde.
     *
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    public function resolve(int $supplierId, array $definition): array
    {
        $skus = [];
        $collect = static function (mixed $row) use (&$skus): void {
            if (is_array($row) && array_key_exists('sku', $row)) {
                if (array_key_exists('item_id', $row) || !is_string($row['sku']) || trim($row['sku']) === '') {
                    throw new EshopException('set_component_invalid', 'Komponenta setu má mít buď item_id, nebo sku.', 422);
                }
                $skus[trim($row['sku'])] = true;
            }
        };
        foreach ((array) ($definition['components'] ?? []) as $row) $collect($row);
        foreach ((array) ($definition['groups'] ?? []) as $group) {
            if (is_array($group)) foreach ((array) ($group['options'] ?? []) as $option) $collect($option);
        }
        if ($skus === []) return $definition;
        if (count($skus) > 1000) throw new EshopException('set_definition_invalid', 'Neplatná definice setu.', 422);
        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        $query = $this->db->pdo()->prepare("SELECT sku, id FROM stock_items WHERE supplier_id = ? AND sku IN ({$placeholders})");
        $query->execute([$supplierId, ...array_map('strval', array_keys($skus))]);
        $ids = [];
        foreach ($query->fetchAll(PDO::FETCH_KEY_PAIR) as $sku => $id) $ids[mb_strtolower((string) $sku)] = (int) $id;
        $missing = array_values(array_filter(array_map('strval', array_keys($skus)), static fn (string $sku): bool => !isset($ids[mb_strtolower($sku)])));
        if ($missing !== []) {
            throw new EshopException('set_component_not_found', 'Karta nebo komponenta setu nenalezena.', 404, ['skus' => $missing]);
        }
        $replace = static function (mixed $row) use ($ids): mixed {
            if (!is_array($row) || !array_key_exists('sku', $row)) return $row;
            $sku = mb_strtolower(trim($row['sku']));
            unset($row['sku']);
            return ['item_id' => $ids[$sku]] + $row;
        };
        if (is_array($definition['components'] ?? null)) $definition['components'] = array_map($replace, $definition['components']);
        if (is_array($definition['groups'] ?? null)) {
            foreach ($definition['groups'] as $key => $group) {
                if (is_array($group) && is_array($group['options'] ?? null)) $definition['groups'][$key]['options'] = array_map($replace, $group['options']);
            }
        }
        return $definition;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function definitionInput(array $input): array
    {
        $definition = $input['definition'] ?? null;
        if (!is_array($definition) || ($definition !== [] && array_is_list($definition))) {
            throw new EshopException('validation_failed', 'Zadejte definici sady (definition).', 422);
        }
        return $definition;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function card(array $input, bool $requireName): array
    {
        $sku = is_string($input['sku'] ?? null) ? trim($input['sku']) : '';
        if ($sku === '' || mb_strlen($sku) > 50) throw new EshopException('validation_failed', 'SKU sady je povinné (max 50 znaků).', 422);
        $card = ['sku' => $sku];
        if (array_key_exists('name', $input)) {
            $name = is_string($input['name']) ? trim($input['name']) : '';
            if ($name === '' || mb_strlen($name) > 255) throw new EshopException('validation_failed', 'Název sady je povinný (max 255 znaků).', 422);
            $card['name'] = $name;
        } elseif ($requireName) {
            throw new EshopException('validation_failed', 'Název sady je povinný (max 255 znaků).', 422);
        }
        if (array_key_exists('unit', $input)) {
            $unit = is_string($input['unit']) ? trim($input['unit']) : '';
            if ($unit === '' || mb_strlen($unit) > 20) throw new EshopException('validation_failed', 'Neplatná jednotka sady.', 422);
            $card['unit'] = $unit;
        }
        if (array_key_exists('item_type', $input)) {
            if (!in_array($input['item_type'], ['goods', 'product'], true)) throw new EshopException('validation_failed', "item_type sady musí být 'goods' nebo 'product'.", 422);
            $card['item_type'] = $input['item_type'];
        }
        if (array_key_exists('vat_rate_id', $input) && $input['vat_rate_id'] !== null) {
            if (!is_int($input['vat_rate_id'])) throw new EshopException('validation_failed', 'Neplatná sazba DPH.', 422);
            $query = $this->db->pdo()->prepare('SELECT 1 FROM vat_rates WHERE id = ?');
            $query->execute([$input['vat_rate_id']]);
            if ($query->fetchColumn() === false) throw new EshopException('validation_failed', 'Neplatná sazba DPH.', 422);
            $card['vat_rate_id'] = $input['vat_rate_id'];
        }
        foreach (['ean' => 50, 'note' => 65535] as $field => $max) {
            if (!array_key_exists($field, $input) || $input[$field] === null) continue;
            if (!is_string($input[$field]) || mb_strlen($input[$field]) > $max) throw new EshopException('validation_failed', "Neplatná hodnota {$field}.", 422);
            $card[$field] = trim($input[$field]) === '' ? null : trim($input[$field]);
        }
        if (array_key_exists('is_active', $input)) {
            if (!is_bool($input['is_active'])) throw new EshopException('validation_failed', 'is_active musí být boolean.', 422);
            $card['is_active'] = $input['is_active'];
        }
        return array_intersect_key($card, array_flip(self::CARD_FIELDS));
    }

    /** @param array<string,mixed> $card */
    private function insertCard(int $supplierId, array $card): int
    {
        $id = $this->items->insert($supplierId, $card + ['item_type' => 'goods', 'unit' => 'ks', 'tracking_mode' => 'none']);
        $this->db->pdo()->prepare('UPDATE stock_items SET is_stocked = 0 WHERE supplier_id = ? AND id = ?')->execute([$supplierId, $id]);
        return $id;
    }

    private function columns(): string
    {
        return 'ps.stock_item_id, ps.row_version, ps.definition_json, ps.created_at, ps.updated_at,
                si.sku, si.name, si.unit, si.item_type, si.vat_rate_id, si.ean, si.is_active, si.lifecycle_status';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        return [
            'stock_item_id' => (int) $row['stock_item_id'],
            'sku' => (string) $row['sku'],
            'name' => (string) $row['name'],
            'unit' => (string) $row['unit'],
            'item_type' => (string) $row['item_type'],
            'vat_rate_id' => $row['vat_rate_id'] === null ? null : (int) $row['vat_rate_id'],
            'ean' => $row['ean'],
            'is_active' => (bool) $row['is_active'],
            'lifecycle_status' => (string) $row['lifecycle_status'],
            'row_version' => (int) $row['row_version'],
            'definition' => json_decode((string) $row['definition_json'], true, 512, JSON_THROW_ON_ERROR),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
