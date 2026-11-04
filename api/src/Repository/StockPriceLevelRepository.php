<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use PDO;

/**
 * Cenové hladiny odběratelů (migrace 1833) — číselník hladin firmy
 * (`stock_price_levels`) a jejich pravidla (`stock_price_level_rules`).
 * O použití rozhoduje jen {@see \MyInvoice\Service\Eshop\Pricing\PriceLevelResolver}.
 *
 * `client_count` = odběratelé s přiřazenou hladinou, `rule_count` = pravidla hladiny.
 */
final class StockPriceLevelRepository
{
    private const SELECT =
        'SELECT l.id, l.code, l.name, l.default_discount_pct, l.is_active, l.display_order,
                (SELECT COUNT(*) FROM clients c WHERE c.supplier_id = l.supplier_id AND c.price_level_id = l.id) AS client_count,
                (SELECT COUNT(*) FROM stock_price_level_rules r WHERE r.supplier_id = l.supplier_id AND r.price_level_id = l.id) AS rule_count
           FROM stock_price_levels l';

    /** Pravidla s popiskem cíle shody (název karty, kategorie nebo výrobce). */
    private const RULE_SELECT =
        "SELECT r.id, r.price_level_id, r.match_type, r.match_id, r.rule_type, r.discount_pct, r.fixed_price,
                r.currency_code, r.priority,
                COALESCE(si.name, sc.name, m.name) AS match_label,
                COALESCE(si.sku, sc.code, m.code) AS match_code
           FROM stock_price_level_rules r
      LEFT JOIN stock_items si ON r.match_type = 'product' AND si.supplier_id = r.supplier_id AND si.id = r.match_id
      LEFT JOIN stock_categories sc ON r.match_type = 'category' AND sc.supplier_id = r.supplier_id AND sc.id = r.match_id
      LEFT JOIN manufacturers m ON r.match_type = 'manufacturer' AND m.supplier_id = r.supplier_id AND m.id = r.match_id";

    private const RULE_ORDER =
        " ORDER BY FIELD(r.match_type, 'product', 'category', 'manufacturer'), r.priority DESC, r.id ASC";

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, bool $activeOnly = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            self::SELECT . ' WHERE l.supplier_id = ?' . ($activeOnly ? ' AND l.is_active = 1' : '')
            . ' ORDER BY l.display_order ASC, l.name ASC, l.id ASC'
        );
        $stmt->execute([$supplierId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE l.supplier_id = ? AND l.id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function findByCode(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE l.supplier_id = ? AND l.code = ?');
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @param array{code:string, name:string, default_discount_pct:string, is_active:bool, display_order:int} $data */
    public function insert(int $supplierId, array $data): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO stock_price_levels (supplier_id, code, name, default_discount_pct, is_active, display_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId, $data['code'], $data['name'], $data['default_discount_pct'],
            (int) $data['is_active'], (int) $data['display_order'],
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array{code:string, name:string, default_discount_pct:string, is_active:bool, display_order:int} $data */
    public function update(int $supplierId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE stock_price_levels SET code = ?, name = ?, default_discount_pct = ?, is_active = ?, display_order = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([
            $data['code'], $data['name'], $data['default_discount_pct'], (int) $data['is_active'],
            (int) $data['display_order'], $supplierId, $id,
        ]);
    }

    /**
     * Smaže hladinu i s pravidly (kaskáda). Odběratele odpojí v téže transakci —
     * `clients.price_level_id` nemá cizí klíč a nesmí zůstat viset.
     * `invoices.price_level_id` se záměrně nemaže: je to záznam, jakou hladinou byl
     * doklad naceněný (detail ukáže `#id`, editor konceptu ji při načtení zruší).
     */
    public function delete(int $supplierId, int $id): void
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare('UPDATE clients SET price_level_id = NULL WHERE supplier_id = ? AND price_level_id = ?')
                ->execute([$supplierId, $id]);
            $pdo->prepare('DELETE FROM stock_price_levels WHERE supplier_id = ? AND id = ?')
                ->execute([$supplierId, $id]);
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Aktivní hladina odběratele. Null, když odběratel hladinu nemá, hladina je
     * neaktivní nebo patří jiné firmě, anebo firma nemá zapnutý sklad.
     *
     * @return array{id:int, code:string, name:string, default_discount_pct:string}|null
     */
    public function activeLevelForClient(int $supplierId, int $clientId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.code, l.name, l.default_discount_pct
               FROM clients c
               JOIN supplier s ON s.id = c.supplier_id AND s.stock_enabled = 1
               JOIN stock_price_levels l ON l.supplier_id = c.supplier_id AND l.id = c.price_level_id AND l.is_active = 1
              WHERE c.supplier_id = ? AND c.id = ?'
        );
        $stmt->execute([$supplierId, $clientId]);
        return self::pricingLevel($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * Aktivní hladina firmy podle id (hladina zvolená na dokladu). Null, když hladina
     * neexistuje, je neaktivní, patří jiné firmě, anebo firma nemá zapnutý sklad;
     * stejné podmínky jako {@see activeLevelForClient()}.
     *
     * @return array{id:int, code:string, name:string, default_discount_pct:string}|null
     */
    public function activeLevel(int $supplierId, int $levelId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.code, l.name, l.default_discount_pct
               FROM stock_price_levels l
               JOIN supplier s ON s.id = l.supplier_id AND s.stock_enabled = 1
              WHERE l.supplier_id = ? AND l.id = ? AND l.is_active = 1'
        );
        $stmt->execute([$supplierId, $levelId]);
        return self::pricingLevel($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string,mixed>|false $row
     * @return array{id:int, code:string, name:string, default_discount_pct:string}|null
     */
    private static function pricingLevel(array|false $row): ?array
    {
        if ($row === false) {
            return null;
        }
        return [
            'id'                   => (int) $row['id'],
            'code'                 => (string) $row['code'],
            'name'                 => (string) $row['name'],
            'default_discount_pct' => (string) $row['default_discount_pct'],
        ];
    }

    /**
     * Pravidla hladiny, která mohou platit pro dané karty a měnu: produktová jen
     * pro tyto karty, kategorie a výrobci všechna; bez měny nebo v měně dokladu.
     *
     * @param list<int> $stockItemIds
     * @return list<array<string,mixed>>
     */
    public function applicableRules(int $supplierId, int $levelId, array $stockItemIds, string $currency): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $stockItemIds), static fn (int $i): bool => $i > 0)));
        $productFilter = $ids === []
            ? "r.match_type <> 'product'"
            : "(r.match_type <> 'product' OR r.match_id IN (" . implode(',', array_fill(0, count($ids), '?')) . '))';
        $stmt = $this->db->pdo()->prepare(
            self::RULE_SELECT . ' WHERE r.supplier_id = ? AND r.price_level_id = ?
                AND (r.currency_code IS NULL OR r.currency_code = ?) AND ' . $productFilter . self::RULE_ORDER
        );
        $stmt->execute(array_merge([$supplierId, $levelId, strtoupper($currency)], $ids));
        return array_map([self::class, 'castRule'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    public function rulesForLevel(int $supplierId, int $levelId): array
    {
        $stmt = $this->db->pdo()->prepare(self::RULE_SELECT . ' WHERE r.supplier_id = ? AND r.price_level_id = ?' . self::RULE_ORDER);
        $stmt->execute([$supplierId, $levelId]);
        return array_map([self::class, 'castRule'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Nahradí celou sadu pravidel hladiny.
     *
     * @param list<array{match_type:string, match_id:int, rule_type:string, discount_pct:?string,
     *                   fixed_price:?string, currency_code:?string, priority:int}> $rows
     */
    public function replaceRules(int $supplierId, int $levelId, array $rows): void
    {
        $this->db->pdo()->prepare('DELETE FROM stock_price_level_rules WHERE supplier_id = ? AND price_level_id = ?')
            ->execute([$supplierId, $levelId]);
        foreach ($rows as $row) {
            $this->insertRule($supplierId, $levelId, $row);
        }
    }

    /**
     * Produktová pravidla karty napříč hladinami.
     *
     * @return list<array<string,mixed>>
     */
    public function productRulesForItem(int $supplierId, int $stockItemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            self::RULE_SELECT . " WHERE r.supplier_id = ? AND r.match_type = 'product' AND r.match_id = ?" . self::RULE_ORDER
        );
        $stmt->execute([$supplierId, $stockItemId]);
        return array_map([self::class, 'castRule'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Smaže produktová pravidla karty v hladině — v jedné měně (NULL = pravidlo
     * bez měny), nebo se `$allCurrencies` všechna.
     */
    public function deleteProductRules(int $supplierId, int $levelId, int $stockItemId, ?string $currency, bool $allCurrencies = false): void
    {
        $sql = "DELETE FROM stock_price_level_rules
                 WHERE supplier_id = ? AND price_level_id = ? AND match_type = 'product' AND match_id = ?";
        $params = [$supplierId, $levelId, $stockItemId];
        if (!$allCurrencies) {
            $sql .= ' AND currency_code <=> ?';
            $params[] = $currency;
        }
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    /**
     * @param array{match_type:string, match_id:int, rule_type:string, discount_pct:?string,
     *              fixed_price:?string, currency_code:?string, priority:int} $row
     */
    public function insertRule(int $supplierId, int $levelId, array $row): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO stock_price_level_rules
                (supplier_id, price_level_id, match_type, match_id, rule_type, discount_pct, fixed_price, currency_code, priority)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId, $levelId, $row['match_type'], $row['match_id'], $row['rule_type'],
            $row['discount_pct'], $row['fixed_price'], $row['currency_code'], $row['priority'],
        ]);
    }

    /**
     * Která z id cílů shody patří firmě (karta, kategorie, výrobce).
     *
     * @param list<int> $ids
     * @return array<int,true>
     */
    public function existingMatchIds(int $supplierId, string $matchType, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = match ($matchType) {
            'product'      => 'SELECT id FROM stock_items WHERE supplier_id = ? AND id IN (' . $in . ')',
            'category'     => 'SELECT id FROM stock_categories WHERE supplier_id = ? AND id IN (' . $in . ')',
            'manufacturer' => 'SELECT id FROM manufacturers WHERE supplier_id = ? AND id IN (' . $in . ')',
            default        => null,
        };
        if ($sql === null) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_merge([$supplierId], $ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $out[(int) $id] = true;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        return [
            'id'                   => (int) $r['id'],
            'code'                 => (string) $r['code'],
            'name'                 => (string) $r['name'],
            'default_discount_pct' => (string) $r['default_discount_pct'],
            'is_active'            => (bool) $r['is_active'],
            'display_order'        => (int) $r['display_order'],
            'client_count'         => (int) $r['client_count'],
            'rule_count'           => (int) $r['rule_count'],
        ];
    }

    /** @return array<string,mixed> */
    private static function castRule(array $r): array
    {
        return [
            'id'             => (int) $r['id'],
            'price_level_id' => (int) $r['price_level_id'],
            'match_type'     => (string) $r['match_type'],
            'match_id'       => (int) $r['match_id'],
            'match_label'    => $r['match_label'] !== null ? (string) $r['match_label'] : null,
            'match_code'     => $r['match_code'] !== null ? (string) $r['match_code'] : null,
            'rule_type'      => (string) $r['rule_type'],
            // discount_pct, fixed_price zůstávají string (money-safe).
            'discount_pct'   => $r['discount_pct'],
            'fixed_price'    => UnitPricePrecision::toDecimalString($r['fixed_price']),
            'currency_code'  => $r['currency_code'] !== null ? strtoupper((string) $r['currency_code']) : null,
            'priority'       => (int) $r['priority'],
        ];
    }
}
