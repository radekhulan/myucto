<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Účetní profily druhů ostatních položek a vlastní kontace úhrad položky (migrace 2238).
 * Řádek profilu i vlastní kontace nese stejné sloupce: debit/credit_account_code (NULL =
 * účet položky, u úhrady na peněžní straně účet banky či pokladny), description_template
 * a dimensions_json.
 */
final class OtherItemPostingProfileRepository
{
    private const COLUMNS = 'id, event, debit_account_code, credit_account_code, description_template, dimensions_json';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> profily druhu (i vypnuté) */
    public function profilesForKind(int $supplierId, int $kindId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ', kind_id, is_active, updated_at FROM other_item_posting_profiles
              WHERE supplier_id = ? AND kind_id = ? ORDER BY id'
        );
        $stmt->execute([$supplierId, $kindId]);
        return array_map(self::cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int,list<array<string,mixed>>> profily všech druhů firmy podle kind_id */
    public function profilesBySupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ', kind_id, is_active, updated_at FROM other_item_posting_profiles
              WHERE supplier_id = ? ORDER BY kind_id, id'
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['kind_id']][] = self::cast($row);
        }
        return $out;
    }

    /** @return array<string,mixed>|null aktivní profil druhu pro událost */
    public function activeProfile(int $supplierId, int $kindId, string $event): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ', kind_id, is_active, updated_at FROM other_item_posting_profiles
              WHERE supplier_id = ? AND kind_id = ? AND event = ? AND is_active = 1'
        );
        $stmt->execute([$supplierId, $kindId, $event]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @param array<string,mixed> $rule */
    public function upsertProfile(int $supplierId, int $kindId, string $event, array $rule, bool $active, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO other_item_posting_profiles
               (supplier_id, kind_id, event, debit_account_code, credit_account_code, description_template,
                dimensions_json, is_active, created_by, updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE debit_account_code = VALUES(debit_account_code),
                credit_account_code = VALUES(credit_account_code), description_template = VALUES(description_template),
                dimensions_json = VALUES(dimensions_json), is_active = VALUES(is_active), updated_by = VALUES(updated_by)'
        )->execute([
            $supplierId, $kindId, $event, $rule['debit_account_code'], $rule['credit_account_code'],
            $rule['description_template'], self::encodeDimensions($rule['dimensions']), (int) $active, $userId, $userId,
        ]);
    }

    public function deleteProfile(int $supplierId, int $kindId, string $event): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM other_item_posting_profiles WHERE supplier_id = ? AND kind_id = ? AND event = ?'
        );
        $stmt->execute([$supplierId, $kindId, $event]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,array<string,mixed>> vlastní kontace položky podle události */
    public function overridesForItem(int $supplierId, int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM other_item_posting_overrides
              WHERE supplier_id = ? AND other_item_id = ? ORDER BY id'
        );
        $stmt->execute([$supplierId, $itemId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = self::cast($row);
            $out[(string) $row['event']] = $row;
        }
        return $out;
    }

    /** @param array<string,mixed> $rule */
    public function upsertOverride(int $supplierId, int $itemId, string $event, array $rule, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO other_item_posting_overrides
               (supplier_id, other_item_id, event, debit_account_code, credit_account_code, description_template,
                dimensions_json, created_by)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE debit_account_code = VALUES(debit_account_code),
                credit_account_code = VALUES(credit_account_code), description_template = VALUES(description_template),
                dimensions_json = VALUES(dimensions_json)'
        )->execute([
            $supplierId, $itemId, $event, $rule['debit_account_code'], $rule['credit_account_code'],
            $rule['description_template'], self::encodeDimensions($rule['dimensions']), $userId,
        ]);
    }

    public function deleteOverride(int $supplierId, int $itemId, string $event): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM other_item_posting_overrides WHERE supplier_id = ? AND other_item_id = ? AND event = ?'
        );
        $stmt->execute([$supplierId, $itemId, $event]);
        return $stmt->rowCount() > 0;
    }

    /** @param list<array{type_id:int,value_id:int}> $dimensions */
    private static function encodeDimensions(array $dimensions): ?string
    {
        return $dimensions === [] ? null : json_encode($dimensions, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        $dimensions = $row['dimensions_json'] !== null
            ? json_decode((string) $row['dimensions_json'], true, 16, JSON_THROW_ON_ERROR) : [];
        unset($row['dimensions_json']);
        $row['id'] = (int) $row['id'];
        if (array_key_exists('kind_id', $row)) $row['kind_id'] = (int) $row['kind_id'];
        if (array_key_exists('is_active', $row)) $row['is_active'] = (bool) $row['is_active'];
        $row['dimensions'] = is_array($dimensions) ? array_values(array_map(static fn (array $d): array => [
            'type_id' => (int) $d['type_id'], 'value_id' => (int) $d['value_id'],
        ], $dimensions)) : [];
        return $row;
    }
}
