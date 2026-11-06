<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro cash_registers — číselník pokladen (mini-epic POKLADNA #14).
 * Per tenant (supplier_id); UNIQUE (supplier_id, name) i (supplier_id, account_code).
 * Analytika 211 je nosičem zůstatku (R6) — dvě pokladny nesmí sdílet účet.
 */
final class CashRegisterRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId, bool $includeInactive = false): array
    {
        $sql = 'SELECT id, supplier_id, name, currency_code, account_code, is_default, own_series, is_active,
                    opening_foreign_amount, opening_foreign_date,
                       created_at, updated_at
                  FROM cash_registers
                 WHERE supplier_id = ?';
        if (!$includeInactive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY is_default DESC, name ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, name, currency_code, account_code, is_default, own_series, is_active,
                    opening_foreign_amount, opening_foreign_date,
                    created_at, updated_at
               FROM cash_registers
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function findByAccountCode(int $supplierId, string $accountCode): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, name, currency_code, account_code, is_default, own_series, is_active,
                    opening_foreign_amount, opening_foreign_date,
                    created_at, updated_at
               FROM cash_registers
              WHERE supplier_id = ? AND account_code = ?'
        );
        $stmt->execute([$supplierId, $accountCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * @param array{name:string, currency_code:string, account_code:string, is_default?:bool, own_series?:bool, is_active?:bool} $data
     */
    public function create(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO cash_registers (supplier_id, name, currency_code, account_code, is_default, own_series, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $data['name'],
            $data['currency_code'],
            $data['account_code'],
            (int) ($data['is_default'] ?? false),
            (int) ($data['own_series'] ?? false),
            (int) ($data['is_active'] ?? true),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Aktualizuje povolená pole (name/account_code/is_default/own_series/is_active).
     *
     * @param array{name?:string, account_code?:string, is_default?:bool, own_series?:bool, is_active?:bool, opening_foreign_amount?:?float, opening_foreign_date?:?string} $data
     */
    public function update(int $supplierId, int $id, array $data): bool
    {
        $sets = [];
        $params = [];
        foreach (['name' => 'name', 'account_code' => 'account_code'] as $key => $col) {
            if (array_key_exists($key, $data)) {
                $sets[] = "$col = ?";
                $params[] = $data[$key];
            }
        }
        foreach (['is_default', 'own_series', 'is_active'] as $key) {
            if (array_key_exists($key, $data)) {
                $sets[] = "$key = ?";
                $params[] = (int) $data[$key];
            }
        }
        foreach (['opening_foreign_amount', 'opening_foreign_date'] as $key) {
            if (array_key_exists($key, $data)) {
                $sets[] = "$key = ?";
                $params[] = $data[$key];
            }
        }
        if ($sets === []) {
            return false;
        }
        $params[] = $id;
        $params[] = $supplierId;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE cash_registers SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() >= 0;
    }

    /** Shodí is_default u všech pokladen firmy (volat v transakci před nastavením nové výchozí). */
    public function clearDefault(int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE cash_registers SET is_default = 0 WHERE supplier_id = ?')
            ->execute([$supplierId]);
    }

    public function delete(int $supplierId, int $id): void
    {
        $this->db->pdo()
            ->prepare('DELETE FROM cash_registers WHERE id = ? AND supplier_id = ?')
            ->execute([$id, $supplierId]);
    }

    /** Počet dokladů (jakéhokoli stavu) navázaných na pokladnu. */
    public function documentsCount(int $supplierId, int $registerId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM cash_documents WHERE supplier_id = ? AND register_id = ?'
        );
        $stmt->execute([$supplierId, $registerId]);
        return (int) $stmt->fetchColumn();
    }

    /** Existuje na pokladně zaúčtovaný/stornovaný doklad (→ zámek změny account_code). */
    public function hasPostedDocuments(int $supplierId, int $registerId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT EXISTS (SELECT 1 FROM cash_documents
                             WHERE supplier_id = ? AND register_id = ? AND status <> 'draft')"
        );
        $stmt->execute([$supplierId, $registerId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Mapa počtu dokladů per registr pro seznam (bez N+1).
     *
     * @param list<int> $registerIds
     * @return array<int,int>
     */
    public function documentsCountMap(int $supplierId, array $registerIds): array
    {
        if ($registerIds === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($registerIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT register_id, COUNT(*) AS cnt FROM cash_documents
              WHERE supplier_id = ? AND register_id IN ($place)
              GROUP BY register_id"
        );
        $stmt->execute(array_merge([$supplierId], array_map('intval', $registerIds)));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['register_id']] = (int) $r['cnt'];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['is_default'] = (bool) $r['is_default'];
        $r['own_series'] = (bool) ($r['own_series'] ?? false);
        $r['is_active'] = (bool) $r['is_active'];
        $r['opening_foreign_amount'] = isset($r['opening_foreign_amount']) ? (float) $r['opening_foreign_amount'] : null;
        $r['opening_foreign_date'] = isset($r['opening_foreign_date']) ? substr((string) $r['opening_foreign_date'], 0, 10) : null;
        return $r;
    }
}
