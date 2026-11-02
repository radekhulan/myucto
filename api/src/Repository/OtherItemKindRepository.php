<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Druhy ostatních pohledávek a závazků (migrace 2228): vlastní druhy firmy a nastavení
 * vestavěných druhů (`builtin_code`), které má řádek, až když se liší od výchozího.
 */
final class OtherItemKindRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT k.id, k.side, k.builtin_code, k.name, k.own_series, k.is_active, k.created_at, k.updated_at,
                    (SELECT s.prefix FROM accounting_document_series s
                      WHERE s.supplier_id = k.supplier_id AND s.register_id = k.id
                        AND s.series_code = IF(k.side = 'receivable', 'other_receivable', 'other_payable')
                      ORDER BY s.fiscal_year DESC LIMIT 1) AS series_prefix
               FROM other_item_kinds k WHERE k.supplier_id = ? ORDER BY k.side, k.builtin_code IS NULL, k.name"
        );
        $stmt->execute([$supplierId]);
        return array_map(self::cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, side, builtin_code, name, own_series, is_active, created_at, updated_at
               FROM other_item_kinds WHERE supplier_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return array<string,mixed>|null nastavení vestavěného druhu, null = výchozí stav */
    public function findBuiltin(int $supplierId, string $side, string $code, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, side, builtin_code, name, own_series, is_active, created_at, updated_at
               FROM other_item_kinds WHERE supplier_id = ? AND side = ? AND builtin_code = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $side, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function insert(int $supplierId, string $side, string $name, bool $ownSeries, ?int $userId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO other_item_kinds (supplier_id, side, name, own_series, created_by) VALUES (?,?,?,?,?)'
        )->execute([$supplierId, $side, $name, (int) $ownSeries, $userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Založí řádek nastavení vestavěného druhu ve výchozím stavu; existující nechá. */
    public function ensureBuiltin(int $supplierId, string $side, string $code, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO other_item_kinds (supplier_id, side, builtin_code, created_by) VALUES (?,?,?,?)
              ON DUPLICATE KEY UPDATE id = id'
        )->execute([$supplierId, $side, $code, $userId]);
    }

    public function update(int $supplierId, int $id, ?string $name, bool $active, bool $ownSeries): void
    {
        $this->db->pdo()->prepare(
            'UPDATE other_item_kinds SET name = ?, is_active = ?, own_series = ? WHERE supplier_id = ? AND id = ?'
        )->execute([$name, (int) $active, (int) $ownSeries, $supplierId, $id]);
    }

    /** Smaže druh; smazané koncepty se od něj odpojí přes FK (ON DELETE SET NULL). */
    public function delete(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM other_item_kinds WHERE supplier_id = ? AND id = ?')
            ->execute([$supplierId, $id]);
    }

    /** Prefix vlastní řady druhu z nejnovějšího roku; null, dokud ji druh neměl. */
    public function seriesPrefix(int $supplierId, int $id, string $seriesCode): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT prefix FROM accounting_document_series
              WHERE supplier_id = ? AND series_code = ? AND register_id = ? ORDER BY fiscal_year DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $seriesCode, $id]);
        $prefix = $stmt->fetchColumn();
        return $prefix === false ? null : (string) $prefix;
    }

    /** Nese už nějaké číslo ostatní položky firmy tento prefix? */
    public function hasDocumentNumberPrefix(int $supplierId, string $prefix): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM other_items WHERE supplier_id = ? AND document_no LIKE ?)'
        );
        $stmt->execute([$supplierId, addcslashes($prefix, '%_\\') . '%']);
        return (bool) $stmt->fetchColumn();
    }

    /** Používá druh nějaká nesmazaná položka? */
    public function hasItems(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM other_items WHERE kind_id = ? AND supplier_id = ? AND deleted_at IS NULL)'
        );
        $stmt->execute([$id, $supplierId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Rozvrh opakování (i pozastavený), jehož šablona nese druh a po obnovení z něj vydá doklady. */
    public function hasSchedule(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT EXISTS (SELECT 1 FROM other_item_schedules
                             WHERE supplier_id = ? AND JSON_VALUE(template_json, '$.kind_id') = ?)"
        );
        $stmt->execute([$supplierId, $id]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $row */
    private static function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['builtin'] = $row['builtin_code'] !== null;
        $row['own_series'] = (bool) $row['own_series'];
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }
}
