<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_items — skladové karty (Epic SKLAD).
 * Per tenant (supplier_id); UNIQUE (supplier_id, sku). Peněžní/množstevní
 * DECIMAL sloupce (sale_price_without_vat, min_qty) se drží jako string —
 * žádné floatování, přesnost řeší volající vrstva (money-safe vzor).
 */
final class StockItemRepository
{
    public const SORT_FIELDS = ['sku', 'name', 'type', 'qty', 'value'];
    public const AVAILABILITY_FILTERS = ['in_stock', 'out_of_stock', 'below_min'];
    public const MISSING_FIELDS = ['manufacturer', 'category', 'image', 'price', 'ean'];

    private const COLUMNS =
        'id, supplier_id, sku, name, item_type, manufacturer_id, unit, ean, vat_rate_id,
         sale_price_without_vat, min_qty, warranty_months, delivery_days, export_eshop,
         is_stocked, weight_g, pricing_base, is_active, lifecycle_status, retired_at, note, row_version, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_items WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function findBySku(int $supplierId, string $sku): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_items WHERE supplier_id = ? AND sku = ?'
        );
        $stmt->execute([$supplierId, $sku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId, array $filters = []): array
    {
        $parts = $this->buildListQueryParts($supplierId, $filters);
        $cols = implode(', ', array_map(
            static fn (string $c): string => 'si.' . trim($c),
            explode(',', self::COLUMNS)
        ));

        $sql = 'SELECT ' . $cols . ', ' . $this->aggregateColumns()
             . ' FROM stock_items si' . $parts['join']
             . ' WHERE ' . $parts['where']
             . ' ORDER BY ' . $this->orderBy($filters);

        // LIMIT/OFFSET inlinujeme jako validované inty (vzor DocumentRepository::search) —
        // native prepared statements neumí LIMIT/OFFSET s parametrem typu string.
        if (isset($filters['limit'])) {
            $sql .= ' LIMIT ' . max(1, (int) $filters['limit']);
            if (isset($filters['offset'])) {
                $sql .= ' OFFSET ' . max(0, (int) $filters['offset']);
            }
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($parts['params']);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Stránkovaná verze list() pro API (Support\Pagination kontrakt) — vrací
     * i celkový počet (COUNT přes stejné WHERE/JOIN, bez LIMIT).
     *
     * @param array<string,mixed> $filters
     * @return array{0:list<array<string,mixed>>, 1:int}
     */
    public function listPaged(int $supplierId, array $filters, int $perPage, int $offset): array
    {
        $parts = $this->buildListQueryParts($supplierId, $filters);

        $countStmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM stock_items si' . $parts['join'] . ' WHERE ' . $parts['where']
        );
        $countStmt->execute($parts['params']);
        $total = (int) $countStmt->fetchColumn();

        $cols = implode(', ', array_map(
            static fn (string $c): string => 'si.' . trim($c),
            explode(',', self::COLUMNS)
        ));
        $sql = 'SELECT ' . $cols . ', ' . $this->aggregateColumns()
             . ' FROM stock_items si' . $parts['join']
             . ' WHERE ' . $parts['where']
             . ' ORDER BY ' . $this->orderBy($filters)
             . ' LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, $offset);

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($parts['params']);
        $rows = array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        return [$rows, $total];
    }

    public function neighbors(int $supplierId, int $itemId, array $filters, ?array $ids = null, array $excludedIds = []): array
    {
        $parts = $this->buildListQueryParts($supplierId, $filters);
        $where = $parts['where'];
        $params = $parts['params'];
        foreach ([[$ids, false], [$excludedIds, true]] as [$selection, $exclude]) {
            if ($selection === null || ($exclude && $selection === [])) {
                continue;
            }
            $where .= ' AND si.id ' . ($exclude ? 'NOT IN' : 'IN')
                . " (SELECT selected.id FROM JSON_TABLE(?, '$[*]' COLUMNS(id BIGINT PATH '$')) selected)";
            $params[] = json_encode($selection, JSON_THROW_ON_ERROR);
        }
        $order = $this->orderBy($filters);
        $statement = $this->db->pdo()->prepare(
            'WITH ordered AS (
                SELECT si.id, LAG(si.id) OVER (ORDER BY ' . $order . ') AS previous_id,
                       LEAD(si.id) OVER (ORDER BY ' . $order . ') AS next_id,
                       ROW_NUMBER() OVER (ORDER BY ' . $order . ') AS position,
                       COUNT(*) OVER () AS total
                  FROM stock_items si' . $parts['join'] . ' WHERE ' . $where . '
            )
            SELECT current.previous_id, current.next_id, current.position, COALESCE(summary.total, 0) AS total
              FROM (SELECT MAX(total) AS total FROM ordered) summary
              LEFT JOIN ordered current ON current.id = ?'
        );
        $statement->execute([...$params, $itemId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return array_map(static fn ($value): ?int => $value === null ? null : (int) $value, $row);
    }

    public function snapshotCatalogSelection(int $supplierId, int $jobId, array $filters, array $excludedIds = [], int $maximum = 30000): int
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Výběr musí být součástí transakce úlohy.');
        }
        $parent = $pdo->prepare("SELECT id FROM catalog_jobs WHERE supplier_id = ? AND id = ? AND status = 'queued' FOR UPDATE");
        $parent->execute([$supplierId, $jobId]);
        if ($parent->fetchColumn() === false) {
            throw new \OutOfBoundsException('Úloha nenalezena.');
        }
        $parts = $this->buildListQueryParts($supplierId, $filters);
        if ($excludedIds !== []) {
            $parts['where'] .= ' AND si.id NOT IN (' . implode(',', array_fill(0, count($excludedIds), '?')) . ')';
            array_push($parts['params'], ...$excludedIds);
        }
        $stmt = $pdo->prepare('INSERT INTO catalog_job_items (supplier_id, job_id, ordinal, stock_item_id, expected_version, input_json)
            SELECT ?, ?, ROW_NUMBER() OVER (ORDER BY si.id), si.id, si.row_version, JSON_OBJECT()
            FROM stock_items si' . $parts['join'] . ' WHERE ' . $parts['where'] . ' ORDER BY si.id LIMIT ' . ($maximum + 1));
        $stmt->execute([$supplierId, $jobId, ...$parts['params']]);
        $count = $stmt->rowCount();
        if ($count > $maximum) {
            throw new \InvalidArgumentException('Výběr obsahuje více než ' . $maximum . ' položek.');
        }
        return $count;
    }

    public function versionsForIds(int $supplierId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare('SELECT id, row_version FROM stock_items WHERE supplier_id = ? AND id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute([$supplierId, ...$ids]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    public function facets(int $supplierId, array $filters, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $parts = $this->buildListQueryParts($supplierId, $filters);
        $base = ' FROM stock_items si' . $parts['join'];
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) AS total,
            COALESCE(SUM(COALESCE(stock.qty, 0) > 0), 0) AS in_stock,
            COALESCE(SUM(COALESCE(stock.qty, 0) <= 0), 0) AS out_of_stock,
            COALESCE(SUM(si.min_qty IS NOT NULL AND COALESCE(stock.qty, 0) < si.min_qty), 0) AS below_min'
            . $base . ' WHERE ' . $parts['where']);
        $stmt->execute($parts['params']);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC);
        $result = ['total' => (int) $counts['total'], 'availability' => []];
        foreach (self::AVAILABILITY_FILTERS as $value) {
            $result['availability'][] = ['value' => $value, 'count' => (int) $counts[$value]];
        }
        $definitions = [
            'manufacturers' => ['JOIN manufacturers f ON f.supplier_id = si.supplier_id AND f.id = si.manufacturer_id', 'f.name'],
            'vendors' => ['JOIN stock_item_vendors v ON v.supplier_id = si.supplier_id AND v.stock_item_id = si.id AND v.is_active = 1
                JOIN clients f ON f.supplier_id = v.supplier_id AND f.id = v.client_id', 'f.company_name'],
            'categories' => ['JOIN stock_item_categories ic ON ic.supplier_id = si.supplier_id AND ic.stock_item_id = si.id
                JOIN stock_categories c ON c.supplier_id = ic.supplier_id AND c.id = ic.category_id
                JOIN stock_categories f ON f.supplier_id = c.supplier_id AND c.path LIKE CONCAT(f.path, \'%\')', 'f.name'],
            'tags' => ['JOIN stock_item_tags it ON it.supplier_id = si.supplier_id AND it.stock_item_id = si.id
                JOIN stock_tags f ON f.supplier_id = it.supplier_id AND f.id = it.tag_id', 'f.name'],
        ];
        foreach ($definitions as $name => [$join, $label]) {
            $stmt = $this->db->pdo()->prepare('SELECT f.id, ' . $label . ' AS name, COUNT(DISTINCT si.id) AS count'
                . $base . ' ' . $join . ' WHERE ' . $parts['where']
                . ' GROUP BY f.id, ' . $label . ' ORDER BY count DESC, f.id LIMIT ' . ($limit + 1));
            $stmt->execute($parts['params']);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result[$name] = ['truncated' => count($rows) > $limit, 'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'name' => $row['name'], 'count' => (int) $row['count'],
            ], array_slice($rows, 0, $limit))];
        }
        return $result;
    }

    /**
     * Sestaví sdílené WHERE/JOIN/params pro list()/listPaged() (stejné filtry,
     * bez LIMIT/OFFSET) — aby COUNT(*) a datový dotaz vždy zůstaly konzistentní.
     *
     * @param array<string,mixed> $filters
     * @return array{where:string, join:string, params:array<int,mixed>}
     */
    private function buildListQueryParts(int $supplierId, array $filters): array
    {
        $where = ['si.supplier_id = ?'];
        $whereParams = [$supplierId];

        if (!empty($filters['type'])) {
            $where[] = 'si.item_type = ?';
            $whereParams[] = (string) $filters['type'];
        }
        if (array_key_exists('active', $filters)) {
            $where[] = 'si.is_active = ?';
            $whereParams[] = (int) $filters['active'];
        }
        if (array_key_exists('export_eshop', $filters)) {
            $where[] = 'si.export_eshop = ?';
            $whereParams[] = (int) $filters['export_eshop'];
        }
        if (!empty($filters['q'])) {
            $q = addcslashes((string) $filters['q'], '%_\\');
            $where[] = '(si.sku LIKE ? OR si.name LIKE ? OR si.ean LIKE ?)';
            $whereParams[] = '%' . $q . '%';
            $whereParams[] = '%' . $q . '%';
            $whereParams[] = '%' . $q . '%';
        }

        if (!empty($filters['manufacturer_id'])) {
            $where[] = 'si.manufacturer_id = ?';
            $whereParams[] = (int) $filters['manufacturer_id'];
        }
        if (!empty($filters['vendor_id'])) {
            $where[] = 'EXISTS (
                SELECT 1 FROM stock_item_vendors siv
                 WHERE siv.supplier_id = si.supplier_id AND siv.stock_item_id = si.id
                   AND siv.client_id = ? AND siv.is_active = 1
            )';
            $whereParams[] = (int) $filters['vendor_id'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'EXISTS (
                SELECT 1
                  FROM stock_item_categories sic
                  JOIN stock_categories sc
                    ON sc.id = sic.category_id AND sc.supplier_id = sic.supplier_id
                  JOIN stock_categories root
                    ON root.id = ? AND root.supplier_id = sic.supplier_id
                 WHERE sic.supplier_id = si.supplier_id AND sic.stock_item_id = si.id
                   AND sc.path LIKE CONCAT(root.path, "%")
            )';
            $whereParams[] = (int) $filters['category_id'];
        }
        foreach (array_values(array_unique(array_filter(
            array_map('intval', (array) ($filters['tag_ids'] ?? [])),
            static fn (int $id): bool => $id > 0,
        ))) as $tagId) {
            $where[] = 'EXISTS (
                SELECT 1 FROM stock_item_tags sit
                 WHERE sit.supplier_id = si.supplier_id AND sit.stock_item_id = si.id
                   AND sit.tag_id = ?
            )';
            $whereParams[] = $tagId;
        }
        foreach ((array) ($filters['attribute_filters'] ?? []) as $attribute) {
            if (!is_array($attribute) || (int) ($attribute['attribute_id'] ?? 0) <= 0) {
                throw new \InvalidArgumentException('Invalid stock attribute filter.');
            }
            $predicate = [
                'siav.supplier_id = si.supplier_id',
                'siav.stock_item_id = si.id',
                'siav.attribute_id = ?',
            ];
            $attributeParams = [(int) $attribute['attribute_id']];
            if (isset($attribute['option_id']) && (int) $attribute['option_id'] > 0) {
                $predicate[] = 'siav.option_id = ?';
                $attributeParams[] = (int) $attribute['option_id'];
            }
            if (array_key_exists('value_text', $attribute)) {
                $predicate[] = 'siav.value_text = ?';
                $attributeParams[] = (string) $attribute['value_text'];
            }
            if (array_key_exists('value_bool', $attribute)) {
                $predicate[] = 'siav.value_bool = ?';
                $attributeParams[] = (int) (bool) $attribute['value_bool'];
            }
            if (array_key_exists('value_num_min', $attribute)) {
                $predicate[] = 'siav.value_num >= ?';
                $attributeParams[] = (string) $attribute['value_num_min'];
            }
            if (array_key_exists('value_num_max', $attribute)) {
                $predicate[] = 'siav.value_num <= ?';
                $attributeParams[] = (string) $attribute['value_num_max'];
            }
            $where[] = 'EXISTS (
                SELECT 1
                  FROM stock_item_attribute_values siav
                  JOIN stock_attributes sa
                    ON sa.id = siav.attribute_id AND sa.supplier_id = siav.supplier_id
                 WHERE ' . implode(' AND ', $predicate) . '
            )';
            array_push($whereParams, ...$attributeParams);
        }

        foreach (array_values(array_unique((array) ($filters['missing'] ?? []))) as $missing) {
            $where[] = match ($missing) {
                'manufacturer' => 'si.manufacturer_id IS NULL',
                'category' => 'NOT EXISTS (
                    SELECT 1 FROM stock_item_categories sic
                     WHERE sic.supplier_id = si.supplier_id AND sic.stock_item_id = si.id
                )',
                'image' => 'NOT EXISTS (
                    SELECT 1 FROM stock_media sm
                     WHERE sm.supplier_id = si.supplier_id AND sm.stock_item_id = si.id
                       AND sm.media_type = "image"
                )',
                'price' => 'si.sale_price_without_vat IS NULL AND NOT EXISTS (
                    SELECT 1 FROM stock_item_prices sip
                     WHERE sip.supplier_id = si.supplier_id AND sip.stock_item_id = si.id
                       AND COALESCE(sip.computed_price, sip.fixed_price) IS NOT NULL
                )',
                'ean' => '(si.ean IS NULL OR si.ean = "")',
                default => throw new \InvalidArgumentException('Unknown missing stock field.'),
            };
        }

        $availability = !empty($filters['only_below_min'])
            ? 'below_min'
            : (string) ($filters['availability'] ?? '');
        if ($availability === 'below_min') {
            $where[] = 'si.min_qty IS NOT NULL AND COALESCE(stock.qty, 0) < si.min_qty';
        } elseif ($availability === 'in_stock') {
            $where[] = 'COALESCE(stock.qty, 0) > 0';
        } elseif ($availability === 'out_of_stock') {
            $where[] = 'COALESCE(stock.qty, 0) <= 0';
        }
        if (array_key_exists('qty_min', $filters)) {
            $where[] = 'COALESCE(stock.qty, 0) >= ?';
            $whereParams[] = (string) $filters['qty_min'];
        }
        if (array_key_exists('qty_max', $filters)) {
            $where[] = 'COALESCE(stock.qty, 0) <= ?';
            $whereParams[] = (string) $filters['qty_max'];
        }

        $join = ' LEFT JOIN (
                    SELECT stock_item_id, SUM(qty) AS qty, SUM(value_total) AS value_total
                      FROM stock_levels
                     WHERE supplier_id = ?';
        $params = [$supplierId];
        if (!empty($filters['warehouse_id'])) {
            $join .= ' AND warehouse_id = ?';
            $params[] = (int) $filters['warehouse_id'];
        }
        $join .= ' GROUP BY stock_item_id
                  ) stock ON stock.stock_item_id = si.id';
        $params = array_merge($params, $whereParams);

        return ['where' => implode(' AND ', $where), 'join' => $join, 'params' => $params];
    }

    private function aggregateColumns(): string
    {
        return 'COALESCE(stock.qty, 0) AS qty,
                COALESCE(stock.value_total, 0) AS value_total,
                CASE WHEN COALESCE(stock.qty, 0) = 0 THEN 0
                     ELSE stock.value_total / stock.qty END AS avg_unit_cost';
    }

    /** @param array<string,mixed> $filters */
    private function orderBy(array $filters): string
    {
        $sort = in_array($filters['sort'] ?? '', self::SORT_FIELDS, true)
            ? (string) $filters['sort'] : 'name';
        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $expression = match ($sort) {
            'sku' => 'si.sku',
            'type' => 'si.item_type',
            'qty' => 'COALESCE(stock.qty, 0)',
            'value' => 'COALESCE(stock.value_total, 0)',
            default => 'si.name',
        };

        return $expression . ' ' . $direction . ', si.id ASC';
    }

    /**
     * Autocomplete — aktivní karty dle sku/name/ean.
     * @return list<array<string,mixed>>
     */
    public function search(int $supplierId, string $q, int $limit = 50): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $lim = max(1, min(200, $limit));
        $like = '%' . addcslashes($q, '%_\\') . '%';

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, sku, name, unit, vat_rate_id, sale_price_without_vat
               FROM stock_items
              WHERE supplier_id = ? AND is_active = 1 AND lifecycle_status = \'ready\'
                AND (sku LIKE ? OR name LIKE ? OR ean LIKE ?)
              ORDER BY name ASC
              LIMIT ' . $lim
        );
        $stmt->execute([$supplierId, $like, $like, $like]);
        return array_map(static function (array $r): array {
            return [
                'id'                     => (int) $r['id'],
                'sku'                    => (string) $r['sku'],
                'name'                   => (string) $r['name'],
                'unit'                   => (string) $r['unit'],
                'vat_rate_id'            => $r['vat_rate_id'] !== null ? (int) $r['vat_rate_id'] : null,
                'sale_price_without_vat' => $r['sale_price_without_vat'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param list<int> $itemIds
     * @return list<int>
     */
    public function replenishmentCandidateIds(int $supplierId, array $itemIds = []): array
    {
        $params = [$supplierId];
        $sql = 'SELECT id FROM stock_items
                 WHERE supplier_id = ? AND is_active = 1 AND min_qty IS NOT NULL';
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $itemIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids !== []) {
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        $sql .= ' ORDER BY id ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @param array{sku:string, name:string, item_type?:string, unit?:string, ean?:?string,
     *              vat_rate_id?:?int, sale_price_without_vat?:?string, min_qty?:?string,
     *              is_active?:bool, note?:?string} $data
     */
    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_items
                (supplier_id, sku, name, item_type, unit, ean, vat_rate_id,
                 sale_price_without_vat, min_qty, is_active, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (string) $data['sku'],
            (string) $data['name'],
            (string) ($data['item_type'] ?? 'goods'),
            (string) ($data['unit'] ?? 'ks'),
            $data['ean'] ?? null,
            isset($data['vat_rate_id']) ? (int) $data['vat_rate_id'] : null,
            isset($data['sale_price_without_vat']) ? (string) $data['sale_price_without_vat'] : null,
            isset($data['min_qty']) ? (string) $data['min_qty'] : null,
            (int) ($data['is_active'] ?? true),
            $data['note'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{sku:string, name:string, item_type?:string, unit?:string, ean?:?string,
     *              vat_rate_id?:?int, sale_price_without_vat?:?string, min_qty?:?string,
     *              is_active?:bool, note?:?string} $data
     */
    public function update(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_items SET
                sku = ?, name = ?, item_type = ?, unit = ?, ean = ?, vat_rate_id = ?,
                sale_price_without_vat = ?, min_qty = ?,
                is_active = CASE WHEN lifecycle_status = \'ready\' THEN ? ELSE 0 END, note = ?,
                row_version = row_version + 1
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            (string) $data['sku'],
            (string) $data['name'],
            (string) ($data['item_type'] ?? 'goods'),
            (string) ($data['unit'] ?? 'ks'),
            $data['ean'] ?? null,
            isset($data['vat_rate_id']) ? (int) $data['vat_rate_id'] : null,
            isset($data['sale_price_without_vat']) ? (string) $data['sale_price_without_vat'] : null,
            isset($data['min_qty']) ? (string) $data['min_qty'] : null,
            (int) ($data['is_active'] ?? true),
            $data['note'] ?? null,
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** @param array<string,mixed> $data */
    public function updateVersioned(int $supplierId, int $id, int $expectedVersion, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_items SET
                sku = ?, name = ?, item_type = ?, unit = ?, ean = ?, vat_rate_id = ?,
                sale_price_without_vat = ?, min_qty = ?,
                is_active = CASE WHEN lifecycle_status = \'ready\' THEN ? ELSE 0 END, note = ?,
                row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND row_version = ?'
        );
        $stmt->execute([
            (string) $data['sku'],
            (string) $data['name'],
            (string) ($data['item_type'] ?? 'goods'),
            (string) ($data['unit'] ?? 'ks'),
            $data['ean'] ?? null,
            isset($data['vat_rate_id']) ? (int) $data['vat_rate_id'] : null,
            isset($data['sale_price_without_vat']) ? (string) $data['sale_price_without_vat'] : null,
            isset($data['min_qty']) ? (string) $data['min_qty'] : null,
            (int) ($data['is_active'] ?? true),
            $data['note'] ?? null,
            $id,
            $supplierId,
            $expectedVersion,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Aktualizace eshopových sloupců karty (Epic ESHOP) — bez zásahu do
     * skladové identity (sku/name/vat/cena řeší update()).
     * @param array{manufacturer_id?:?int, warranty_months?:?int, delivery_days?:?int,
     *              export_eshop?:bool, is_stocked?:bool, weight_g?:?int, pricing_base?:string} $data
     */
    public function updateEshopFields(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_items SET
                manufacturer_id = ?, warranty_months = ?, delivery_days = ?,
                export_eshop = CASE WHEN lifecycle_status = \'ready\' THEN ? ELSE 0 END,
                is_stocked = ?, weight_g = ?, pricing_base = ?,
                row_version = row_version + 1
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            isset($data['manufacturer_id']) && $data['manufacturer_id'] !== null ? (int) $data['manufacturer_id'] : null,
            isset($data['warranty_months']) && $data['warranty_months'] !== null ? (int) $data['warranty_months'] : null,
            isset($data['delivery_days']) && $data['delivery_days'] !== null ? (int) $data['delivery_days'] : null,
            (int) ($data['export_eshop'] ?? false),
            (int) ($data['is_stocked'] ?? true),
            isset($data['weight_g']) && $data['weight_g'] !== null ? (int) $data['weight_g'] : null,
            (string) ($data['pricing_base'] ?? 'weighted_avg'),
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** @param array<string,mixed> $data */
    public function updateEshopFieldsVersioned(
        int $supplierId,
        int $id,
        int $expectedVersion,
        array $data,
    ): bool {
        $casts = [
            'manufacturer_id' => static fn (mixed $value): ?int => $value === null ? null : (int) $value,
            'warranty_months' => static fn (mixed $value): ?int => $value === null ? null : (int) $value,
            'delivery_days' => static fn (mixed $value): ?int => $value === null ? null : (int) $value,
            'export_eshop' => static fn (mixed $value): int => (int) (bool) $value,
            'is_stocked' => static fn (mixed $value): int => (int) (bool) $value,
            'weight_g' => static fn (mixed $value): ?int => $value === null ? null : (int) $value,
            'pricing_base' => static fn (mixed $value): string => (string) $value,
        ];
        $set = [];
        $params = [];
        foreach ($casts as $field => $cast) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $set[] = $field === 'export_eshop'
                ? "export_eshop = CASE WHEN lifecycle_status = 'ready' THEN ? ELSE 0 END"
                : $field . ' = ?';
            $params[] = $cast($data[$field]);
        }
        $set[] = 'row_version = row_version + 1';
        array_push($params, $id, $supplierId, $expectedVersion);

        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_items SET ' . implode(', ', $set)
            . ' WHERE id = ? AND supplier_id = ? AND row_version = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Zrcadlo prodejní ceny (Epic ESHOP cenotvorba) — zapíše CZK computed_price
     * do sale_price_without_vat (default do řádku FV). Money string.
     */
    public function setSalePrice(int $supplierId, int $id, ?string $price): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_items SET sale_price_without_vat = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$price !== null ? (string) $price : null, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Patří manufacturer_id témuž tenantovi? (guard proti cross-tenant vazbě) */
    public function manufacturerOwned(int $supplierId, int $manufacturerId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM manufacturers WHERE supplier_id = ? AND id = ?)'
        );
        $stmt->execute([$supplierId, $manufacturerId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Existuje aspoň jeden pohyb (stock_document_lines) na této kartě? */
    public function hasMovements(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM stock_document_lines WHERE supplier_id = ? AND stock_item_id = ?
             )'
        );
        $stmt->execute([$supplierId, $id]);
        return (bool) $stmt->fetchColumn();
    }

    public function deactivate(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_items SET is_active = 0, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Hard delete — volající MUSÍ nejdřív ověřit hasMovements() === false. */
    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_items WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['vat_rate_id'] = $r['vat_rate_id'] !== null ? (int) $r['vat_rate_id'] : null;
        $r['is_active'] = (bool) $r['is_active'];
        if (array_key_exists('row_version', $r)) {
            $r['row_version'] = (int) $r['row_version'];
        }
        if (array_key_exists('lifecycle_status', $r)) {
            $r['lifecycle_status'] = (string) $r['lifecycle_status'];
        }
        if (array_key_exists('qty', $r)) {
            $r['qty'] = (string) $r['qty'];
            $r['value_total'] = (string) $r['value_total'];
            $r['avg_unit_cost'] = (string) $r['avg_unit_cost'];
        }
        // Eshop rozšíření (1028) — sloupce mohou chybět u projekcí bez COLUMNS.
        if (array_key_exists('manufacturer_id', $r)) {
            $r['manufacturer_id'] = $r['manufacturer_id'] !== null ? (int) $r['manufacturer_id'] : null;
            $r['warranty_months'] = $r['warranty_months'] !== null ? (int) $r['warranty_months'] : null;
            $r['delivery_days'] = $r['delivery_days'] !== null ? (int) $r['delivery_days'] : null;
            $r['export_eshop'] = (bool) $r['export_eshop'];
            $r['is_stocked'] = (bool) $r['is_stocked'];
            $r['weight_g'] = $r['weight_g'] !== null ? (int) $r['weight_g'] : null;
        }
        return $r;
    }
}
