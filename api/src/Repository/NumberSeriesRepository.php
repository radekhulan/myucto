<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Číselník řad (`number_series`), počítadla nových řad (`number_series_counters`)
 * a výchozí řady uživatelů (`number_series_user_defaults`). Vše vždy v rámci firmy.
 */
final class NumberSeriesRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM number_series WHERE supplier_id = ? ORDER BY doc_kind, sort_order, id'
        );
        $stmt->execute([$supplierId]);
        return array_map(self::hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    public function listForKind(int $supplierId, string $kind): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM number_series WHERE supplier_id = ? AND doc_kind = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$supplierId, $kind]);
        return array_map(self::hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM number_series WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    /** @return array<string,mixed>|null */
    public function findByLegacyKey(int $supplierId, string $legacyKey): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM number_series WHERE supplier_id = ? AND legacy_key = ?');
        $stmt->execute([$supplierId, $legacyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    /** @return list<string> */
    public function legacyKeys(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT legacy_key FROM number_series WHERE supplier_id = ? AND legacy_key IS NOT NULL'
        );
        $stmt->execute([$supplierId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Založí převedenou řadu, pokud ji firma ještě nemá (idempotentní podle legacy_key).
     *
     * @param array<string,mixed> $row
     */
    public function insertLegacy(int $supplierId, array $row): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO number_series
                (supplier_id, doc_kind, scope_type, scope_id, storage, legacy_key, name, prefix, is_default, sort_order)
             VALUES (?, ?, ?, ?, 'legacy', ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id"
        )->execute([
            $supplierId,
            $row['doc_kind'],
            $row['scope_type'],
            (int) $row['scope_id'],
            $row['legacy_key'],
            mb_substr((string) $row['name'], 0, 100),
            $row['prefix'] ?? null,
            !empty($row['is_default']) ? 1 : 0,
            (int) ($row['sort_order'] ?? 0),
        ]);
    }

    /** @param array<string,mixed> $data */
    public function insertNative(int $supplierId, array $data, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO number_series
                (supplier_id, doc_kind, scope_type, scope_id, storage, name, prefix, template, period,
                 is_default, valid_to, sort_order, created_by)
             VALUES (?, ?, ?, ?, 'native', ?, ?, ?, ?, 0, ?, ?, ?)"
        )->execute([
            $supplierId,
            $data['doc_kind'],
            $data['scope_type'],
            (int) $data['scope_id'],
            $data['name'],
            $data['prefix'],
            $data['template'],
            $data['period'],
            $data['valid_to'],
            (int) $data['sort_order'],
            $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $patch sloupec => hodnota (jen povolené sloupce) */
    public function update(int $supplierId, int $id, array $patch): void
    {
        $allowed = ['name', 'prefix', 'template', 'period', 'valid_to', 'sort_order'];
        $sets = [];
        $params = [];
        foreach ($patch as $col => $value) {
            if (!in_array($col, $allowed, true)) {
                throw new \InvalidArgumentException("Sloupec {$col} řady nelze měnit.");
            }
            $sets[] = "{$col} = ?";
            $params[] = $value;
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $params[] = $supplierId;
        $this->db->pdo()->prepare('UPDATE number_series SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?')
            ->execute($params);
    }

    /** Nastaví výchozí řadu pro druh a rozsah (ostatní řady téhož rozsahu výchozí přestanou být). */
    public function setDefault(int $supplierId, array $series): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE number_series SET is_default = (id = ?)
              WHERE supplier_id = ? AND doc_kind = ? AND scope_type = ? AND scope_id = ?'
        )->execute([(int) $series['id'], $supplierId, $series['doc_kind'], $series['scope_type'], (int) $series['scope_id']]);
    }

    public function delete(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare("DELETE FROM number_series WHERE id = ? AND supplier_id = ? AND storage = 'native'")
            ->execute([$id, $supplierId]);
    }

    // ── počítadla nových řad ──────────────────────────────────────────────────

    /**
     * Posune počítadlo o jedna a vrátí nové číslo. Řádek počítadla zůstává zamčený
     * do konce transakce volajícího, rollback číslo vrátí.
     */
    public function bumpCounter(int $seriesId, string $periodKey): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO number_series_counters (series_id, period_key, last_number) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1'
        )->execute([$seriesId, $periodKey]);
        return $this->counter($seriesId, $periodKey)['last_number'];
    }

    /** @return array{last_number:int, floor_number:int, exists:bool} */
    public function counter(int $seriesId, string $periodKey): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT last_number, floor_number FROM number_series_counters WHERE series_id = ? AND period_key = ?'
        );
        $stmt->execute([$seriesId, $periodKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false
            ? ['last_number' => 0, 'floor_number' => 0, 'exists' => false]
            : ['last_number' => (int) $row['last_number'], 'floor_number' => (int) $row['floor_number'], 'exists' => true];
    }

    /** @return array<string,int> period_key => floor_number */
    public function floors(int $seriesId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT period_key, floor_number FROM number_series_counters WHERE series_id = ?');
        $stmt->execute([$seriesId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(string) $row['period_key']] = (int) $row['floor_number'];
        }
        return $out;
    }

    public function setCounter(int $seriesId, string $periodKey, int $lastNumber): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO number_series_counters (series_id, period_key, last_number, floor_number) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_number = VALUES(last_number), floor_number = VALUES(floor_number)'
        )->execute([$seriesId, $periodKey, $lastNumber, $lastNumber]);
    }

    public function decrementIfLast(int $seriesId, string $periodKey, int $current): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE number_series_counters SET last_number = last_number - 1
              WHERE series_id = ? AND period_key = ? AND last_number = ? AND last_number > floor_number'
        );
        $stmt->execute([$seriesId, $periodKey, $current]);
        return $stmt->rowCount() > 0;
    }

    public function hasCounters(int $seriesId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM number_series_counters WHERE series_id = ? AND last_number > 0 LIMIT 1');
        $stmt->execute([$seriesId]);
        return $stmt->fetchColumn() !== false;
    }

    // ── výchozí řady uživatelů ────────────────────────────────────────────────

    public function userDefault(int $supplierId, int $userId, string $kind, int $scopeId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT series_id FROM number_series_user_defaults
              WHERE supplier_id = ? AND user_id = ? AND doc_kind = ? AND scope_id = ?'
        );
        $stmt->execute([$supplierId, $userId, $kind, $scopeId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return array<string,int> "kind|scope" => series_id */
    public function userDefaults(int $supplierId, int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT doc_kind, scope_id, series_id FROM number_series_user_defaults WHERE supplier_id = ? AND user_id = ?'
        );
        $stmt->execute([$supplierId, $userId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[$row['doc_kind'] . '|' . (int) $row['scope_id']] = (int) $row['series_id'];
        }
        return $out;
    }

    public function setUserDefault(int $supplierId, int $userId, string $kind, int $scopeId, ?int $seriesId): void
    {
        $pdo = $this->db->pdo();
        if ($seriesId === null) {
            $pdo->prepare(
                'DELETE FROM number_series_user_defaults WHERE supplier_id = ? AND user_id = ? AND doc_kind = ? AND scope_id = ?'
            )->execute([$supplierId, $userId, $kind, $scopeId]);
            return;
        }
        $pdo->prepare(
            'INSERT INTO number_series_user_defaults (supplier_id, user_id, doc_kind, scope_id, series_id) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE series_id = VALUES(series_id)'
        )->execute([$supplierId, $userId, $kind, $scopeId, $seriesId]);
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): array
    {
        foreach (['id', 'supplier_id', 'scope_id', 'sort_order'] as $col) {
            $row[$col] = (int) $row[$col];
        }
        $row['is_default'] = (bool) $row['is_default'];
        $row['created_by'] = $row['created_by'] !== null ? (int) $row['created_by'] : null;
        return $row;
    }
}
