<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PriceListItemRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array{data:list<array<string,mixed>>,total:int,page:int,per_page:int} */
    public function listForSupplier(
        int $supplierId,
        string $query = '',
        bool $includeArchived = false,
        int $page = 1,
        int $perPage = 50,
        ?string $currencyCode = null,
        ?int $clientId = null,
        ?bool $pricesIncludeVat = null,
    ): array {
        $where = ['pli.supplier_id = ?'];
        $params = [$supplierId];
        if (!$includeArchived) $where[] = 'pli.archived = 0';
        if ($pricesIncludeVat !== null) {
            $where[] = 'pli.prices_include_vat = ?';
            $params[] = $pricesIncludeVat ? 1 : 0;
        }
        if ($query !== '') {
            $where[] = '(pli.code LIKE ? OR pli.name LIKE ? OR pli.description LIKE ?)';
            $needle = '%' . $query . '%';
            array_push($params, $needle, $needle, $needle);
        }
        if ($currencyCode !== null) {
            $currencyCode = strtoupper($currencyCode);
            $currencyWhere = [
                'pli.allow_exchange_rate_conversion = 1',
                'EXISTS (SELECT 1 FROM price_list_item_prices px
                          WHERE px.price_list_item_id = pli.id
                            AND px.currency_code = ? AND px.archived = 0)',
            ];
            $params[] = $currencyCode;
            if ($clientId !== null) {
                $currencyWhere[] = 'EXISTS (SELECT 1 FROM price_list_customer_overrides co
                                           WHERE co.price_list_item_id = pli.id
                                             AND co.client_id = ? AND co.currency_code = ?)';
                $params[] = $clientId;
                $params[] = $currencyCode;
            }
            $where[] = '(' . implode(' OR ', $currencyWhere) . ')';
        }
        $whereSql = implode(' AND ', $where);

        $count = $this->db->pdo()->prepare("SELECT COUNT(*) FROM price_list_items pli WHERE $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->pdo()->prepare(
            "SELECT pli.*, vr.code AS vat_code, vr.rate_percent AS vat_rate_percent,
                    bp.unit_price AS base_unit_price, bp.archived AS base_price_archived
               FROM price_list_items pli
               JOIN vat_rates vr ON vr.id = pli.vat_rate_id
          LEFT JOIN price_list_item_prices bp
                 ON bp.price_list_item_id = pli.id
                AND bp.currency_code = pli.base_currency_code
                AND bp.id = (SELECT MAX(bp_latest.id)
                               FROM price_list_item_prices bp_latest
                              WHERE bp_latest.supplier_id = pli.supplier_id
                                AND bp_latest.price_list_item_id = pli.id
                                AND bp_latest.currency_code = pli.base_currency_code)
              WHERE $whereSql
              ORDER BY pli.archived ASC, pli.name ASC, pli.code ASC
              LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = array_map([$this, 'castItem'], $stmt->fetchAll(PDO::FETCH_ASSOC));

        $ids = array_column($rows, 'id');
        $prices = $this->pricesForItems($supplierId, $ids, true);
        $usage = $this->usageForItems($supplierId, $ids);
        foreach ($rows as &$row) {
            $row['prices'] = $prices[$row['id']] ?? [];
            $row['usage'] = $usage[$row['id']] ?? [];
        }
        unset($row);

        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pli.*, vr.code AS vat_code, vr.rate_percent AS vat_rate_percent,
                    bp.unit_price AS base_unit_price, bp.archived AS base_price_archived
               FROM price_list_items pli
               JOIN vat_rates vr ON vr.id = pli.vat_rate_id
          LEFT JOIN price_list_item_prices bp
                 ON bp.price_list_item_id = pli.id
                AND bp.currency_code = pli.base_currency_code
                AND bp.id = (SELECT MAX(bp_latest.id)
                               FROM price_list_item_prices bp_latest
                              WHERE bp_latest.supplier_id = pli.supplier_id
                                AND bp_latest.price_list_item_id = pli.id
                                AND bp_latest.currency_code = pli.base_currency_code)
              WHERE pli.id = ? AND pli.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $item = $this->castItem($row);
        $item['prices'] = $this->pricesForItems($supplierId, [$id], true)[$id] ?? [];
        $item['usage'] = $this->usageForItems($supplierId, [$id])[$id] ?? [];
        return $item;
    }

    /** @param list<array<string,mixed>> $prices */
    public function create(int $supplierId, array $data, array $prices): int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO price_list_items
                    (supplier_id, code, name, description, unit, vat_rate_id,
                     prices_include_vat, base_currency_code,
                     allow_exchange_rate_conversion, archived)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                (string) $data['code'],
                (string) $data['name'],
                (string) $data['description'],
                (string) $data['unit'],
                (int) $data['vat_rate_id'],
                !empty($data['prices_include_vat']) ? 1 : 0,
                strtoupper((string) $data['base_currency_code']),
                !empty($data['allow_exchange_rate_conversion']) ? 1 : 0,
                !empty($data['archived']) ? 1 : 0,
            ]);
            $id = (int) $pdo->lastInsertId();
            $this->upsertPrices($supplierId, $id, $prices);
            $this->assertActiveBasePrice($supplierId, $id, (string) $data['base_currency_code']);
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @param list<array<string,mixed>> $prices */
    public function update(int $id, int $supplierId, array $data, array $prices = []): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $existingPriceCodes = array_column(
                $this->pricesForItems($supplierId, [$id], true)[$id] ?? [],
                'currency_code',
            );
            $stmt = $pdo->prepare(
                'UPDATE price_list_items
                    SET code = ?, name = ?, description = ?, unit = ?, vat_rate_id = ?,
                        prices_include_vat = ?, base_currency_code = ?,
                        allow_exchange_rate_conversion = ?, archived = ?
                  WHERE id = ? AND supplier_id = ?'
            );
            $stmt->execute([
                (string) $data['code'],
                (string) $data['name'],
                (string) $data['description'],
                (string) $data['unit'],
                (int) $data['vat_rate_id'],
                !empty($data['prices_include_vat']) ? 1 : 0,
                strtoupper((string) $data['base_currency_code']),
                !empty($data['allow_exchange_rate_conversion']) ? 1 : 0,
                !empty($data['archived']) ? 1 : 0,
                $id,
                $supplierId,
            ]);
            if ($prices !== []) {
                $this->upsertPrices($supplierId, $id, $prices);
                $submittedCodes = array_map(
                    static fn (array $price): string => strtoupper((string) $price['currency_code']),
                    $prices,
                );
                foreach (array_diff($existingPriceCodes, $submittedCodes) as $removedCode) {
                    if ($removedCode !== strtoupper((string) $data['base_currency_code'])) {
                        $this->deletePrice($supplierId, $id, $removedCode);
                    }
                }
            }
            $this->assertActiveBasePrice($supplierId, $id, (string) $data['base_currency_code']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function upsertPrice(
        int $supplierId,
        int $itemId,
        string $currencyCode,
        float $unitPrice,
        bool $archived = false,
    ): void {
        $this->upsertPrices($supplierId, $itemId, [[
            'currency_code' => $currencyCode,
            'unit_price' => $unitPrice,
            'archived' => $archived,
        ]]);
    }

    /** @return array{deleted:bool,archived:bool,usage_count?:int} */
    public function deletePrice(int $supplierId, int $itemId, string $currencyCode): array
    {
        $item = $this->find($itemId, $supplierId);
        if ($item === null) return ['deleted' => false, 'archived' => false];
        $code = strtoupper($currencyCode);
        if ($code === $item['base_currency_code']) {
            throw new \DomainException('Základní cenu nelze odstranit.');
        }

        $usage = $this->usageCount($supplierId, $itemId, $code);
        if ($usage > 0) {
            $this->db->pdo()->prepare(
                'UPDATE price_list_item_prices SET archived = 1
                  WHERE supplier_id = ? AND price_list_item_id = ? AND currency_code = ?'
            )->execute([$supplierId, $itemId, $code]);
            return ['deleted' => false, 'archived' => true, 'usage_count' => $usage];
        }

        $this->db->pdo()->prepare(
            'DELETE FROM price_list_item_prices
              WHERE supplier_id = ? AND price_list_item_id = ? AND currency_code = ?'
        )->execute([$supplierId, $itemId, $code]);
        return ['deleted' => true, 'archived' => false];
    }

    /** @return array{deleted:bool,archived:bool,usage_count?:int} */
    public function delete(int $id, int $supplierId): array
    {
        $usage = $this->usageCount($supplierId, $id, null);
        if ($usage > 0) {
            $this->db->pdo()->prepare(
                'UPDATE price_list_items SET archived = 1 WHERE id = ? AND supplier_id = ?'
            )->execute([$id, $supplierId]);
            return ['deleted' => false, 'archived' => true, 'usage_count' => $usage];
        }
        $this->db->pdo()->prepare(
            'DELETE FROM price_list_items WHERE id = ? AND supplier_id = ?'
        )->execute([$id, $supplierId]);
        return ['deleted' => true, 'archived' => false];
    }

    /** @return list<array<string,mixed>> */
    public function customerOverrides(int $supplierId, int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT co.id, co.price_list_item_id, co.client_id, co.currency_code,
                    co.unit_price, co.created_at, co.updated_at,
                    c.company_name AS client_name,
                    (SELECT COUNT(*)
                       FROM recurring_invoice_template_items ri
                       JOIN recurring_invoice_templates t ON t.id = ri.template_id
                      WHERE t.supplier_id = co.supplier_id
                        AND t.client_id = co.client_id
                        AND ri.price_list_item_id = co.price_list_item_id) AS affected_template_count
               FROM price_list_customer_overrides co
               JOIN clients c ON c.id = co.client_id AND c.supplier_id = co.supplier_id
              WHERE co.supplier_id = ? AND co.price_list_item_id = ?
              ORDER BY c.company_name, co.currency_code'
        );
        $stmt->execute([$supplierId, $itemId]);
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['price_list_item_id'] = (int) $row['price_list_item_id'];
            $row['client_id'] = (int) $row['client_id'];
            $row['unit_price'] = (float) $row['unit_price'];
            $row['affected_template_count'] = (int) $row['affected_template_count'];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function upsertCustomerOverride(
        int $supplierId,
        int $itemId,
        int $clientId,
        string $currencyCode,
        float $unitPrice,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO price_list_customer_overrides
                (supplier_id, price_list_item_id, client_id, currency_code, unit_price)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE unit_price = VALUES(unit_price), updated_at = NOW()'
        )->execute([$supplierId, $itemId, $clientId, strtoupper($currencyCode), $unitPrice]);
    }

    public function deleteCustomerOverride(
        int $supplierId,
        int $itemId,
        int $clientId,
        string $currencyCode,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM price_list_customer_overrides
              WHERE supplier_id = ? AND price_list_item_id = ?
                AND client_id = ? AND currency_code = ?'
        );
        $stmt->execute([$supplierId, $itemId, $clientId, strtoupper($currencyCode)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param list<int> $itemIds
     * @return array<int,array<string,mixed>>
     */
    public function pricingRows(
        int $supplierId,
        array $itemIds,
        int $clientId,
        string $targetCurrencyCode,
    ): array {
        if ($itemIds === []) return [];
        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $target = strtoupper($targetCurrencyCode);
        $stmt = $this->db->pdo()->prepare(
            "SELECT pli.id, pli.supplier_id, pli.code, pli.name, pli.description,
                    pli.unit, pli.vat_rate_id, pli.prices_include_vat,
                    pli.base_currency_code, pli.allow_exchange_rate_conversion,
                    pli.archived, vr.rate_percent AS vat_rate_percent,
                    bp.unit_price AS base_unit_price, bp.archived AS base_price_archived,
                    tp.unit_price AS target_unit_price, tp.archived AS target_price_archived,
                    cot.unit_price AS customer_target_unit_price,
                    cob.unit_price AS customer_base_unit_price
               FROM price_list_items pli
               JOIN vat_rates vr ON vr.id = pli.vat_rate_id
          LEFT JOIN price_list_item_prices bp
                 ON bp.price_list_item_id = pli.id
                AND bp.supplier_id = pli.supplier_id
                AND bp.currency_code = pli.base_currency_code
                AND bp.id = (SELECT MAX(bp_latest.id)
                               FROM price_list_item_prices bp_latest
                              WHERE bp_latest.supplier_id = pli.supplier_id
                                AND bp_latest.price_list_item_id = pli.id
                                AND bp_latest.currency_code = pli.base_currency_code)
          LEFT JOIN price_list_item_prices tp
                 ON tp.price_list_item_id = pli.id AND tp.supplier_id = pli.supplier_id
                AND tp.currency_code = ?
                AND tp.id = (SELECT MAX(tp_latest.id)
                               FROM price_list_item_prices tp_latest
                              WHERE tp_latest.supplier_id = pli.supplier_id
                                AND tp_latest.price_list_item_id = pli.id
                                AND tp_latest.currency_code = ?)
          LEFT JOIN price_list_customer_overrides cot
                 ON cot.price_list_item_id = pli.id AND cot.supplier_id = pli.supplier_id
                AND cot.client_id = ? AND cot.currency_code = ?
          LEFT JOIN price_list_customer_overrides cob
                 ON cob.price_list_item_id = pli.id AND cob.supplier_id = pli.supplier_id
                AND cob.client_id = ? AND cob.currency_code = pli.base_currency_code
              WHERE pli.supplier_id = ? AND pli.id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$target, $target, $clientId, $target, $clientId, $supplierId], $ids));

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = $this->castPricingRow($row);
        }
        return $out;
    }

    /** @return array{code:string,decimals:int}|null */
    public function currencyById(int $supplierId, int $currencyId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, decimals FROM currencies WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$currencyId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['code' => (string) $row['code'], 'decimals' => (int) $row['decimals']];
    }

    /** @return array{id:int,code:string,decimals:int}|null */
    public function activeCurrencyByCode(int $supplierId, string $currencyCode): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, decimals FROM currencies
              WHERE supplier_id = ? AND code = ? AND is_active = 1
              ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, strtoupper($currencyCode)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'decimals' => (int) $row['decimals'],
        ];
    }

    public function activeCurrencyExists(int $supplierId, string $currencyCode): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM currencies WHERE supplier_id = ? AND code = ? AND is_active = 1'
        );
        $stmt->execute([$supplierId, strtoupper($currencyCode)]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function clientExists(int $supplierId, int $clientId, bool $requireCustomer = true): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM clients
              WHERE supplier_id = ? AND id = ?"
                . ($requireCustomer ? ' AND is_customer = 1' : '')
        );
        $stmt->execute([$supplierId, $clientId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function unitExists(string $unit): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM units WHERE code = ?');
        $stmt->execute([$unit]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function vatRateExists(int $vatRateId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM vat_rates WHERE id = ?');
        $stmt->execute([$vatRateId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param list<array<string,mixed>> $prices */
    private function upsertPrices(int $supplierId, int $itemId, array $prices): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO price_list_item_prices
                (supplier_id, price_list_item_id, currency_code, unit_price, archived)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE unit_price = VALUES(unit_price),
                                     archived = VALUES(archived), updated_at = NOW()'
        );
        foreach ($prices as $price) {
            $stmt->execute([
                $supplierId,
                $itemId,
                strtoupper((string) ($price['currency_code'] ?? '')),
                (float) ($price['unit_price'] ?? 0),
                !empty($price['archived']) ? 1 : 0,
            ]);
        }
    }

    private function assertActiveBasePrice(int $supplierId, int $itemId, string $baseCurrencyCode): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM price_list_item_prices
              WHERE supplier_id = ? AND price_list_item_id = ?
                AND currency_code = ? AND archived = 0'
        );
        $stmt->execute([$supplierId, $itemId, strtoupper($baseCurrencyCode)]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new \DomainException('Základní cena musí být aktivní.');
        }
    }

    /**
     * @param list<int> $itemIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function pricesForItems(int $supplierId, array $itemIds, bool $includeArchived): array
    {
        if ($itemIds === []) return [];
        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id, price_list_item_id, currency_code, unit_price, archived,
                       created_at, updated_at
                  FROM (
                        SELECT p.*,
                               ROW_NUMBER() OVER (
                                   PARTITION BY p.supplier_id, p.price_list_item_id, p.currency_code
                                   ORDER BY p.id DESC
                               ) AS price_rank
                          FROM price_list_item_prices p
                         WHERE p.supplier_id = ? AND p.price_list_item_id IN ($placeholders)
                       ) ranked
                 WHERE price_rank = 1";
        if (!$includeArchived) $sql .= ' AND archived = 0';
        $sql .= ' ORDER BY currency_code';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_merge([$supplierId], $ids));

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int) $row['id'];
            $row['price_list_item_id'] = (int) $row['price_list_item_id'];
            $row['unit_price'] = (float) $row['unit_price'];
            $row['archived'] = (bool) $row['archived'];
            $out[$row['price_list_item_id']][] = $row;
        }
        return $out;
    }

    /**
     * @param list<int> $itemIds
     * @return array<int,list<array{currency_code:string,catalog_policy:string,count:int}>>
     */
    private function usageForItems(int $supplierId, array $itemIds): array
    {
        if ($itemIds === []) return [];
        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT ri.price_list_item_id, cur.code AS currency_code,
                    ri.catalog_policy, COUNT(*) AS usage_count
               FROM recurring_invoice_template_items ri
               JOIN recurring_invoice_templates t ON t.id = ri.template_id
               JOIN currencies cur ON cur.id = t.currency_id
              WHERE t.supplier_id = ? AND ri.price_list_item_id IN ($placeholders)
              GROUP BY ri.price_list_item_id, cur.code, ri.catalog_policy"
        );
        $stmt->execute(array_merge([$supplierId], $ids));

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['price_list_item_id'];
            $out[$id][] = [
                'currency_code' => (string) $row['currency_code'],
                'catalog_policy' => (string) $row['catalog_policy'],
                'count' => (int) $row['usage_count'],
            ];
        }
        return $out;
    }

    private function usageCount(int $supplierId, int $itemId, ?string $currencyCode): int
    {
        $sql = 'SELECT COUNT(*)
                  FROM recurring_invoice_template_items ri
                  JOIN recurring_invoice_templates t ON t.id = ri.template_id
                  JOIN currencies cur ON cur.id = t.currency_id
                 WHERE t.supplier_id = ? AND ri.price_list_item_id = ?';
        $params = [$supplierId, $itemId];
        if ($currencyCode !== null) {
            $sql .= ' AND cur.code = ?';
            $params[] = strtoupper($currencyCode);
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function castItem(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['vat_rate_id'] = (int) $row['vat_rate_id'];
        $row['vat_rate_percent'] = (float) $row['vat_rate_percent'];
        $row['prices_include_vat'] = (bool) $row['prices_include_vat'];
        $row['allow_exchange_rate_conversion'] = (bool) $row['allow_exchange_rate_conversion'];
        $row['archived'] = (bool) $row['archived'];
        $row['base_unit_price'] = $row['base_unit_price'] !== null ? (float) $row['base_unit_price'] : null;
        $row['base_price_archived'] = $row['base_price_archived'] !== null ? (bool) $row['base_price_archived'] : null;
        return $row;
    }

    private function castPricingRow(array $row): array
    {
        foreach (['id', 'supplier_id', 'vat_rate_id'] as $key) $row[$key] = (int) $row[$key];
        foreach (['prices_include_vat', 'allow_exchange_rate_conversion', 'archived'] as $key) {
            $row[$key] = (bool) $row[$key];
        }
        foreach (['base_unit_price', 'target_unit_price', 'customer_target_unit_price', 'customer_base_unit_price', 'vat_rate_percent'] as $key) {
            $row[$key] = $row[$key] !== null ? (float) $row[$key] : null;
        }
        foreach (['base_price_archived', 'target_price_archived'] as $key) {
            $row[$key] = $row[$key] !== null ? (bool) $row[$key] : null;
        }
        return $row;
    }
}
