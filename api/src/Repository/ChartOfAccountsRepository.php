<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro chart_of_accounts — účtová osnova per firma (Epic F1).
 * Per tenant (supplier_id), UNIQUE (supplier_id, account_code).
 */
final class ChartOfAccountsRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId, bool $includeInactive = false): array
    {
        $sql = 'SELECT id, supplier_id, account_code, name, account_type, normal_side,
                       is_synthetic, parent_id, is_active, created_at
                  FROM chart_of_accounts
                 WHERE supplier_id = ?';
        if (!$includeInactive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY account_code ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findByCode(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, account_code, name, account_type, normal_side,
                    is_synthetic, parent_id, is_active, created_at
               FROM chart_of_accounts
              WHERE supplier_id = ? AND account_code = ?'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Mapa account_code => {id, is_active, account_type} pro suppliera — používá
     * PostingService k překladu CODE z posting_rules na account_id a k validaci
     * účtu (aktivní / typ) přímo v resolveLines. Ostatní volající (existenční
     * kontrola přes isset) rozšíření hodnoty nevadí.
     *
     * @return array<string, array{id:int, is_active:bool, account_type:string}>
     */
    public function codeToIdMap(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_code, id, is_active, account_type FROM chart_of_accounts WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(string) $r['account_code']] = [
                'id'           => (int) $r['id'],
                'is_active'    => (bool) $r['is_active'],
                'account_type' => (string) $r['account_type'],
            ];
        }
        return $map;
    }

    public function count(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, account_code, name, account_type, normal_side,
                    is_synthetic, parent_id, is_active, created_at
               FROM chart_of_accounts
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Analytiky pod syntetickým účtem (přímé děti) — karta účtu (drill-through
     * syntetika → analytiky). Neaktivní se vrací taky, aby zůstatek z historie
     * nezmizel; příznak `is_active` si zobrazí volající.
     *
     * @return list<array<string,mixed>>
     */
    public function childrenOf(int $supplierId, int $parentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, account_code, name, account_type, normal_side,
                    is_synthetic, parent_id, is_active, created_at
               FROM chart_of_accounts
              WHERE supplier_id = ? AND parent_id = ?
              ORDER BY account_code ASC'
        );
        $stmt->execute([$supplierId, $parentId]);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Vloží účet (analytiku i syntetiku) do osnovy firmy. Vrací id.
     *
     * @param array{account_code:string, name:string, account_type:string,
     *              normal_side:?string, is_synthetic?:bool, parent_id?:?int, is_active?:bool} $data
     */
    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO chart_of_accounts
                (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $data['account_code'],
            $data['name'],
            $data['account_type'],
            $data['normal_side'],
            (int) ($data['is_synthetic'] ?? false),
            $data['parent_id'] ?? null,
            (int) ($data['is_active'] ?? true),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Přejmenuje / (de)aktivuje účet. Účty se NEmažou (§ auditní stopa), jen deaktivují.
     *
     * @param array{name?:string, is_active?:bool} $data
     */
    public function update(int $supplierId, int $id, array $data): bool
    {
        $sets = [];
        $params = [];
        if (array_key_exists('name', $data)) {
            $sets[] = 'name = ?';
            $params[] = $data['name'];
        }
        if (array_key_exists('is_active', $data)) {
            $sets[] = 'is_active = ?';
            $params[] = (int) $data['is_active'];
        }
        if ($sets === []) {
            return false;
        }
        $params[] = $id;
        $params[] = $supplierId;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE chart_of_accounts SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Smaže účet. Volající MUSÍ nejdřív ověřit, že na něm nic nevisí
     * ({@see \MyInvoice\Service\Accounting\ChartAccountUsage}) — repository
     * jen provede výmaz.
     */
    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM chart_of_accounts WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Účtová osnova jako strom: kořeny (parent_id NULL) s vnořenými `children`.
     *
     * @return list<array<string,mixed>>
     */
    public function tree(int $supplierId, bool $includeInactive = false): array
    {
        $flat = $this->listForTenant($supplierId, $includeInactive);
        $byId = [];
        foreach ($flat as $row) {
            $row['children'] = [];
            $byId[(int) $row['id']] = $row;
        }
        $roots = [];
        foreach ($byId as $id => $_) {
            $parentId = $byId[$id]['parent_id'];
            if ($parentId !== null && isset($byId[$parentId])) {
                $byId[$parentId]['children'][] = &$byId[$id];
            } else {
                $roots[] = &$byId[$id];
            }
        }
        return $roots;
    }

    /**
     * Mapa id => {code, name, account_type, tax_deductibility} pro obohacení řádků deníku
     * o kód/název účtu. Typ a daňová uznatelnost slouží
     * {@see \MyInvoice\Service\Accounting\TaxNeutralReclassification}.
     *
     * @return array<int,array{code:string,name:string,account_type:string,tax_deductibility:string}>
     */
    public function idToAccountMap(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, account_code, name, account_type, tax_deductibility
               FROM chart_of_accounts WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int) $r['id']] = [
                'code'              => (string) $r['account_code'],
                'name'              => (string) $r['name'],
                'account_type'      => (string) $r['account_type'],
                'tax_deductibility' => (string) $r['tax_deductibility'],
            ];
        }
        return $map;
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['is_synthetic'] = (bool) $r['is_synthetic'];
        $r['is_active'] = (bool) $r['is_active'];
        $r['parent_id'] = $r['parent_id'] === null ? null : (int) $r['parent_id'];
        return $r;
    }
}
