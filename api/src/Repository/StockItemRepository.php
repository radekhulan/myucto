<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use PDO;

/**
 * Repository pro stock_items — skladové karty (Epic SKLAD).
 * Per tenant (supplier_id); UNIQUE (supplier_id, sku). Peněžní/množstevní
 * DECIMAL sloupce (sale_price_without_vat, min_qty) se drží jako string —
 * žádné floatování, přesnost řeší volající vrstva (money-safe vzor).
 */
final class StockItemRepository
{
    /**
     * Služba zásobu nevede, návratem na jiný typ se evidence zase zapne. Parametr = nový typ;
     * v UPDATE musí stát před `item_type`, přiřazení se vyhodnocují zleva.
     */
    private const IS_STOCKED_BY_TYPE_SQL = "CASE WHEN ? = 'service' THEN 0 WHEN item_type = 'service' THEN 1 ELSE is_stocked END";

    public const SORT_FIELDS = ['sku', 'name', 'type', 'qty', 'value'];
    public const AVAILABILITY_FILTERS = ['in_stock', 'out_of_stock', 'below_min'];
    public const MISSING_FIELDS = ['manufacturer', 'category', 'image', 'price', 'ean'];

    /**
     * Od kolika znaků hledání prochází i identifikátory kusů (sériová čísla, šarže)
     * a textové parametry karty. Kratší text by prohledával všechny kusy skladu
     * a vracel šum; VIN, výrobní číslo nebo kód šarže má vždy víc znaků.
     */
    public const IDENTIFIER_SEARCH_MIN_LENGTH = 3;

    /** Tři placeholdery `%text%`: sériové číslo, kód šarže, hodnota textového parametru. */
    private const IDENTIFIER_SEARCH_SQL =
        'EXISTS (SELECT 1 FROM stock_tracking_units stu
                  WHERE stu.supplier_id = si.supplier_id AND stu.stock_item_id = si.id
                    AND (stu.serial_number LIKE ? OR stu.lot_code LIKE ?))
         OR EXISTS (SELECT 1 FROM stock_item_attribute_values siav
                      JOIN stock_attributes sa ON sa.id = siav.attribute_id AND sa.supplier_id = siav.supplier_id
                     WHERE siav.supplier_id = si.supplier_id AND siav.stock_item_id = si.id
                       AND sa.data_type = \'text\' AND siav.value_text LIKE ?)';

    /**
     * Hodnota jednoho parametru karty pro volitelný sloupec seznamu (jeden placeholder
     * = id parametru), formát {@see StockAttributeDisplayValue}.
     */
    private const DISPLAY_ATTRIBUTE_SQL =
        '(SELECT ' . StockAttributeDisplayValue::AGGREGATE_SQL . '
            FROM stock_item_attribute_values siav' . StockAttributeDisplayValue::JOINS_SQL . '
           WHERE siav.supplier_id = si.supplier_id AND siav.stock_item_id = si.id AND siav.attribute_id = ?)';

    private const COLUMNS =
        'id, supplier_id, sku, name, item_type, eet_item_kind, manufacturer_id, unit, default_sale_unit, tracking_mode, ean, vat_rate_id,
         sale_price_without_vat, min_qty, warranty_months, delivery_days, export_eshop,
         is_stocked, weight_g, intrastat_cn8_code, intrastat_country_of_origin, intrastat_net_mass_kg,
         intrastat_supplementary_unit, intrastat_supplementary_unit_coefficient,
         pricing_base, revenue_account_code, expense_account_code,
         is_active, lifecycle_status, retired_at, note, row_version, created_at, updated_at';

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
        $displayAttributeId = (int) ($filters['display_attribute_id'] ?? 0);
        $displayColumn = $displayAttributeId > 0 ? ', ' . self::DISPLAY_ATTRIBUTE_SQL . ' AS display_attribute_value' : '';
        $sql = 'SELECT ' . $cols . ', ' . $this->aggregateColumns() . $displayColumn
             . ' FROM stock_items si' . $parts['join']
             . ' WHERE ' . $parts['where']
             . ' ORDER BY ' . $this->orderBy($filters)
             . ' LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, $offset);

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($displayAttributeId > 0 ? [$displayAttributeId, ...$parts['params']] : $parts['params']);
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
            $identifiers = self::identifierSearchable((string) $filters['q']);
            $where[] = '(si.sku LIKE ? OR si.name LIKE ? OR si.ean LIKE ?'
                . ($identifiers ? ' OR ' . self::IDENTIFIER_SEARCH_SQL : '') . ')';
            $whereParams[] = '%' . $q . '%';
            $whereParams[] = '%' . $q . '%';
            $whereParams[] = '%' . $q . '%';
            if ($identifiers) {
                array_push($whereParams, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
            }
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
            $where[] = self::categorySubtreeSql('si');
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
     * Karta `$itemAlias` leží v kategorii `?` nebo v kterékoli její podkategorii
     * (jeden placeholder = id kategorie). Stejné pravidlo pro seznam karet i sestavy.
     */
    public static function categorySubtreeSql(string $itemAlias): string
    {
        return "EXISTS (
            SELECT 1
              FROM stock_item_categories sic
              JOIN stock_categories sc
                ON sc.id = sic.category_id AND sc.supplier_id = sic.supplier_id
              JOIN stock_categories root
                ON root.id = ? AND root.supplier_id = sic.supplier_id
             WHERE sic.supplier_id = {$itemAlias}.supplier_id AND sic.stock_item_id = {$itemAlias}.id
               AND sc.path LIKE CONCAT(root.path, '%')
        )";
    }

    public static function identifierSearchable(string $q): bool
    {
        return mb_strlen(trim($q)) >= self::IDENTIFIER_SEARCH_MIN_LENGTH;
    }

    /**
     * Proč karta vyhověla hledání, když ne podle kódu, názvu ani EAN: první sériové
     * číslo, kód šarže nebo textový parametr obsahující text. Seznam tak u karty
     * rovnou ukáže, který kus (VIN, výrobní číslo) uživatel hledal. Karty, které
     * vyhověly už kódem, názvem nebo EAN (stejným LIKE a kolací jako hledání),
     * se vynechají, protože shodu uživatel vidí sám.
     *
     * @param list<int> $itemIds
     * @return array<int,array{kind:string,value:string,attribute:?string}>
     */
    public function searchMatches(int $supplierId, array $itemIds, string $q): array
    {
        $q = trim($q);
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
        if ($itemIds === [] || !self::identifierSearchable($q)) {
            return [];
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT stock_item_id, kind, value, attribute FROM (
                SELECT stu.stock_item_id,
                       CASE WHEN stu.serial_number LIKE ? THEN \'serial\' ELSE \'lot\' END AS kind,
                       CASE WHEN stu.serial_number LIKE ? THEN stu.serial_number ELSE stu.lot_code END AS value,
                       NULL AS attribute, 0 AS source_order, stu.id AS source_id
                  FROM stock_tracking_units stu
                 WHERE stu.supplier_id = ? AND stu.stock_item_id IN (' . $in . ')
                   AND (stu.serial_number LIKE ? OR stu.lot_code LIKE ?)
                UNION ALL
                SELECT siav.stock_item_id, \'attribute\', siav.value_text, sa.name, 1, siav.id
                  FROM stock_item_attribute_values siav
                  JOIN stock_attributes sa ON sa.id = siav.attribute_id AND sa.supplier_id = siav.supplier_id
                 WHERE siav.supplier_id = ? AND siav.stock_item_id IN (' . $in . ')
                   AND sa.data_type = \'text\' AND siav.value_text LIKE ?
             ) m
             JOIN stock_items si ON si.id = m.stock_item_id AND si.supplier_id = ?
            WHERE NOT (si.sku LIKE ? OR si.name LIKE ? OR COALESCE(si.ean, \'\') LIKE ?)
            ORDER BY stock_item_id, source_order, source_id'
        );
        $stmt->execute([$like, $like, $supplierId, ...$itemIds, $like, $like, $supplierId, ...$itemIds, $like, $supplierId, $like, $like, $like]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['stock_item_id']] ??= [
                'kind'      => (string) $row['kind'],
                'value'     => (string) $row['value'],
                'attribute' => $row['attribute'] !== null ? (string) $row['attribute'] : null,
            ];
        }
        return $out;
    }

    /**
     * Autocomplete — aktivní karty dle sku/name/ean, přesného EAN balení (issue #17)
     * a od {@see IDENTIFIER_SEARCH_MIN_LENGTH} znaků i dle sériových čísel, šarží
     * a textových parametrů.
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

        $identifiers = self::identifierSearchable($q);
        $stmt = $this->db->pdo()->prepare(
            'SELECT si.id, si.sku, si.name, si.unit, si.default_sale_unit, si.tracking_mode, si.vat_rate_id, si.sale_price_without_vat, si.is_stocked, si.item_type
               FROM stock_items si
              WHERE si.supplier_id = ? AND si.is_active = 1 AND si.lifecycle_status = \'ready\'
                AND (si.sku LIKE ? OR si.name LIKE ? OR si.ean LIKE ?
                     OR EXISTS (SELECT 1 FROM stock_item_units u
                                 WHERE u.supplier_id = si.supplier_id AND u.stock_item_id = si.id AND u.ean = ?)'
                     . ($identifiers ? ' OR ' . self::IDENTIFIER_SEARCH_SQL : '') . ')
              ORDER BY si.name ASC
              LIMIT ' . $lim
        );
        $stmt->execute([$supplierId, $like, $like, $like, $q, ...($identifiers ? [$like, $like, $like] : [])]);
        return array_map(static function (array $r): array {
            return [
                'id'                     => (int) $r['id'],
                'sku'                    => (string) $r['sku'],
                'name'                   => (string) $r['name'],
                'unit'                   => (string) $r['unit'],
                'default_sale_unit'      => $r['default_sale_unit'] !== null ? (string) $r['default_sale_unit'] : null,
                'tracking_mode'          => (string) $r['tracking_mode'],
                'vat_rate_id'            => $r['vat_rate_id'] !== null ? (int) $r['vat_rate_id'] : null,
                'sale_price_without_vat' => UnitPricePrecision::toDecimalString($r['sale_price_without_vat']),
                'is_stocked'             => (bool) $r['is_stocked'],
                'item_type'              => (string) $r['item_type'],
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
                (supplier_id, sku, name, item_type, unit, tracking_mode, ean, vat_rate_id,
                 sale_price_without_vat, min_qty, intrastat_cn8_code, intrastat_country_of_origin,
                 intrastat_net_mass_kg, intrastat_supplementary_unit,
                 intrastat_supplementary_unit_coefficient, is_active, note, is_stocked)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (string) $data['sku'],
            (string) $data['name'],
            (string) ($data['item_type'] ?? 'goods'),
            (string) ($data['unit'] ?? 'ks'),
            (string) ($data['tracking_mode'] ?? 'none'),
            $data['ean'] ?? null,
            isset($data['vat_rate_id']) ? (int) $data['vat_rate_id'] : null,
            isset($data['sale_price_without_vat']) ? (string) $data['sale_price_without_vat'] : null,
            isset($data['min_qty']) ? (string) $data['min_qty'] : null,
            $data['intrastat_cn8_code'] ?? null,
            $data['intrastat_country_of_origin'] ?? null,
            isset($data['intrastat_net_mass_kg']) ? (string) $data['intrastat_net_mass_kg'] : null,
            $data['intrastat_supplementary_unit'] ?? null,
            isset($data['intrastat_supplementary_unit_coefficient'])
                ? (string) $data['intrastat_supplementary_unit_coefficient'] : null,
            (int) ($data['is_active'] ?? true),
            $data['note'] ?? null,
            (int) (($data['item_type'] ?? 'goods') !== 'service'),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{sku:string, name:string, item_type?:string, unit?:string, ean?:?string,
     *              vat_rate_id?:?int, sale_price_without_vat?:?string, min_qty?:?string,
     *              is_active?:bool, note?:?string} $data
     */
    public function update(int $supplierId, int $id, array $data, ?int $expectedVersion = null): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_items SET
                is_stocked = ' . self::IS_STOCKED_BY_TYPE_SQL . ',
                sku = ?, name = ?, item_type = ?, unit = ?, tracking_mode = COALESCE(?, tracking_mode), ean = ?, vat_rate_id = ?,
                sale_price_without_vat = ?, min_qty = ?,
                intrastat_cn8_code = CASE WHEN ? THEN ? ELSE intrastat_cn8_code END,
                intrastat_country_of_origin = CASE WHEN ? THEN ? ELSE intrastat_country_of_origin END,
                intrastat_net_mass_kg = CASE WHEN ? THEN ? ELSE intrastat_net_mass_kg END,
                intrastat_supplementary_unit = CASE WHEN ? THEN ? ELSE intrastat_supplementary_unit END,
                intrastat_supplementary_unit_coefficient = CASE WHEN ? THEN ? ELSE intrastat_supplementary_unit_coefficient END,
                is_active = CASE WHEN lifecycle_status = \'ready\' THEN ? ELSE 0 END, note = ?,
                row_version = row_version + 1
              WHERE id = ? AND supplier_id = ?' . ($expectedVersion === null ? '' : ' AND row_version = ?')
        );
        $stmt->execute([
            (string) ($data['item_type'] ?? 'goods'),
            (string) $data['sku'],
            (string) $data['name'],
            (string) ($data['item_type'] ?? 'goods'),
            (string) ($data['unit'] ?? 'ks'),
            isset($data['tracking_mode']) ? (string) $data['tracking_mode'] : null,
            $data['ean'] ?? null,
            isset($data['vat_rate_id']) ? (int) $data['vat_rate_id'] : null,
            isset($data['sale_price_without_vat']) ? (string) $data['sale_price_without_vat'] : null,
            isset($data['min_qty']) ? (string) $data['min_qty'] : null,
            (int) array_key_exists('intrastat_cn8_code', $data),
            $data['intrastat_cn8_code'] ?? null,
            (int) array_key_exists('intrastat_country_of_origin', $data),
            $data['intrastat_country_of_origin'] ?? null,
            (int) array_key_exists('intrastat_net_mass_kg', $data),
            isset($data['intrastat_net_mass_kg']) ? (string) $data['intrastat_net_mass_kg'] : null,
            (int) array_key_exists('intrastat_supplementary_unit', $data),
            $data['intrastat_supplementary_unit'] ?? null,
            (int) array_key_exists('intrastat_supplementary_unit_coefficient', $data),
            isset($data['intrastat_supplementary_unit_coefficient'])
                ? (string) $data['intrastat_supplementary_unit_coefficient'] : null,
            (int) ($data['is_active'] ?? true),
            $data['note'] ?? null,
            $id,
            $supplierId,
            ...($expectedVersion === null ? [] : [$expectedVersion]),
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Výchozí účet výnosů a nákladů karty (F1, migrace 1948). Mění jen sloupce,
     * které volající poslal — úprava karty bez nich účty nemaže.
     *
     * @param array{revenue_account_code?:?string, expense_account_code?:?string} $accounts už ověřené
     */
    public function updatePostingAccounts(int $supplierId, int $id, array $accounts): void
    {
        $sets = [];
        $params = [];
        foreach (['revenue_account_code', 'expense_account_code'] as $column) {
            if (array_key_exists($column, $accounts)) {
                $sets[] = "{$column} = ?";
                $params[] = $accounts[$column];
            }
        }
        if ($sets === []) {
            return;
        }
        $this->db->pdo()->prepare('UPDATE stock_items SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?')
            ->execute([...$params, $id, $supplierId]);
    }

    /**
     * Druh položky pro EET ({@see \MyInvoice\Service\Eet\EetStoredValueCalculator::ITEM_KINDS},
     * migrace 2185). Samostatně od úpravy karty, hodnotu ověřuje volající.
     */
    public function updateEetItemKind(int $supplierId, int $id, string $kind): void
    {
        $this->db->pdo()->prepare('UPDATE stock_items SET eet_item_kind = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$kind, $id, $supplierId]);
    }

    /** @param array<string,mixed> $data */
    public function updateVersioned(int $supplierId, int $id, int $expectedVersion, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_items SET
                is_stocked = ' . self::IS_STOCKED_BY_TYPE_SQL . ',
                sku = ?, name = ?, item_type = ?, unit = ?, tracking_mode = COALESCE(?, tracking_mode), ean = ?, vat_rate_id = ?,
                sale_price_without_vat = ?, min_qty = ?,
                intrastat_cn8_code = CASE WHEN ? THEN ? ELSE intrastat_cn8_code END,
                intrastat_country_of_origin = CASE WHEN ? THEN ? ELSE intrastat_country_of_origin END,
                intrastat_net_mass_kg = CASE WHEN ? THEN ? ELSE intrastat_net_mass_kg END,
                intrastat_supplementary_unit = CASE WHEN ? THEN ? ELSE intrastat_supplementary_unit END,
                intrastat_supplementary_unit_coefficient = CASE WHEN ? THEN ? ELSE intrastat_supplementary_unit_coefficient END,
                is_active = CASE WHEN lifecycle_status = \'ready\' THEN ? ELSE 0 END, note = ?,
                row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND row_version = ?'
        );
        $stmt->execute([
            (string) ($data['item_type'] ?? 'goods'),
            (string) $data['sku'],
            (string) $data['name'],
            (string) ($data['item_type'] ?? 'goods'),
            (string) ($data['unit'] ?? 'ks'),
            isset($data['tracking_mode']) ? (string) $data['tracking_mode'] : null,
            $data['ean'] ?? null,
            isset($data['vat_rate_id']) ? (int) $data['vat_rate_id'] : null,
            isset($data['sale_price_without_vat']) ? (string) $data['sale_price_without_vat'] : null,
            isset($data['min_qty']) ? (string) $data['min_qty'] : null,
            (int) array_key_exists('intrastat_cn8_code', $data),
            $data['intrastat_cn8_code'] ?? null,
            (int) array_key_exists('intrastat_country_of_origin', $data),
            $data['intrastat_country_of_origin'] ?? null,
            (int) array_key_exists('intrastat_net_mass_kg', $data),
            isset($data['intrastat_net_mass_kg']) ? (string) $data['intrastat_net_mass_kg'] : null,
            (int) array_key_exists('intrastat_supplementary_unit', $data),
            $data['intrastat_supplementary_unit'] ?? null,
            (int) array_key_exists('intrastat_supplementary_unit_coefficient', $data),
            isset($data['intrastat_supplementary_unit_coefficient'])
                ? (string) $data['intrastat_supplementary_unit_coefficient'] : null,
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
                is_stocked = CASE WHEN item_type = \'service\' THEN 0 ELSE ? END, weight_g = ?, pricing_base = ?,
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
            $set[] = match ($field) {
                'export_eshop' => "export_eshop = CASE WHEN lifecycle_status = 'ready' THEN ? ELSE 0 END",
                'is_stocked' => "is_stocked = CASE WHEN item_type = 'service' THEN 0 ELSE ? END",
                default => $field . ' = ?',
            };
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

    /**
     * Hard delete — volající MUSÍ nejdřív ověřit hasMovements() === false a volat v transakci.
     * Odkaz na řádcích objednávek se uvolní, ostatní vazby zastaví cizí klíč.
     */
    public function delete(int $supplierId, int $id): bool
    {
        foreach (['purchase_order_lines', 'sales_order_lines'] as $table) {
            $this->db->pdo()->prepare("UPDATE {$table} SET stock_item_id = NULL WHERE supplier_id = ? AND stock_item_id = ?")
                ->execute([$supplierId, $id]);
        }
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
        if (array_key_exists('sale_price_without_vat', $r)) {
            $r['sale_price_without_vat'] = UnitPricePrecision::toDecimalString($r['sale_price_without_vat']);
        }
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

    /**
     * Doplní Intrastat z dokladu jen tam, kde na kartě chybí; existující hodnota se nikdy nepřepíše.
     * Neznámou zemi nezapíše: StockItemIntrastatValidator by pak odmítl každé uložení karty.
     *
     * @return array<string,array{old:?string,new:string}> co se změnilo, podle sloupce
     */
    public function fillMissingIntrastat(int $supplierId, int $id, ?string $cn8Code, ?string $originCountry): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT intrastat_cn8_code, intrastat_country_of_origin, (SELECT iso2 FROM countries WHERE iso2 = ?) AS known_country
               FROM stock_items WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$originCountry, $supplierId, $id]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($card === false) {
            return [];
        }
        $changes = [];
        foreach (['intrastat_cn8_code' => $cn8Code, 'intrastat_country_of_origin' => $card['known_country']] as $column => $value) {
            if ($value !== null && (string) $card[$column] === '') {
                $changes[$column] = ['old' => $card[$column], 'new' => (string) $value];
            }
        }
        if ($changes === []) {
            return [];
        }
        $set = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($changes)));
        $this->db->pdo()->prepare("UPDATE stock_items SET {$set}, row_version = row_version + 1 WHERE supplier_id = ? AND id = ?")
            ->execute([...array_column($changes, 'new'), $supplierId, $id]);
        return $changes;
    }
}
