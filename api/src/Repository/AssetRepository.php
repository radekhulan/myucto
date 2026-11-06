<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro assets + asset_improvements — karty dlouhodobého majetku (Epic F3).
 * Per tenant (supplier_id VŽDY ve WHERE), UNIQUE (supplier_id, inventory_number).
 */
final class AssetRepository
{
    public function __construct(private readonly Connection $db) {}

    /** Sloupce povolené pro insert/update (whitelist proti mass-assignmentu). */
    private const COLUMNS = [
        'inventory_number', 'name', 'description', 'kind',
        'asset_account_code', 'accumulated_account_code', 'acquisition_account_code',
        'purchase_invoice_id', 'purchase_invoice_item_id',
        'input_price', 'acquisition_date', 'put_into_use_date',
        'disposal_date', 'disposal_type', 'disposal_price', 'sale_invoice_id', 'disposal_entry_id', 'status',
        'tax_method', 'tax_group', 'tax_first_year_increase',
        'is_first_owner', 'is_m1_vehicle', 'm1_limit_exception', 'is_zero_emission',
        // § 28 ZDP — právní důvod odpisování (+ podíl a doložení, které z něj plynou).
        'depreciator_ground', 'co_ownership_share', 'depreciator_note',
        'opening_tax_years', 'opening_tax_amount', 'opening_acc_months', 'opening_acc_amount',
        'acc_useful_life_months', 'acc_method', 'acc_residual_value', 'created_by',
        'summary_account_code', 'imported_depreciation_plan',
    ];

    /**
     * Seznam karet firmy s agregáty odpisů (Σ tax amount/full_amount, Σ acc amount)
     * a Σ TZ — z nich se počítá zvýšená VC a zůstatkové ceny.
     *
     * @param array{status?:?string, q?:?string, page?:int, per_page?:int} $filters
     * @return array{items: list<array<string,mixed>>, total:int, page:int, per_page:int}
     */
    public function list(int $supplierId, array $filters = []): array
    {
        $where = ['a.supplier_id = ?'];
        $params = [$supplierId];

        if (!empty($filters['status'])) {
            $where[] = 'a.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['q'])) {
            $q = addcslashes((string) $filters['q'], '%_\\');
            $where[] = '(a.inventory_number LIKE ? OR a.name LIKE ?)';
            $params[] = $q . '%';
            $params[] = '%' . $q . '%';
        }
        $whereSql = implode(' AND ', $where);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? 50);
        $perPage = $perPage > 0 ? min($perPage, 200) : 50;
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT a.id, a.supplier_id, a.inventory_number, a.name, a.description, a.kind,
                       a.asset_account_code, a.accumulated_account_code, a.acquisition_account_code,
                       a.purchase_invoice_id, a.purchase_invoice_item_id, a.input_price,
                       a.acquisition_date, a.put_into_use_date, a.disposal_date, a.disposal_type,
                       a.disposal_price, a.sale_invoice_id, a.disposal_entry_id, a.status,
                       a.tax_method, a.tax_group, a.tax_first_year_increase,
                       a.is_first_owner, a.is_m1_vehicle, a.m1_limit_exception, a.is_zero_emission,
                       a.depreciator_ground, a.co_ownership_share, a.depreciator_note,
                       a.opening_tax_years, a.opening_tax_amount, a.opening_acc_months, a.opening_acc_amount,
                       a.acc_useful_life_months, a.acc_method, a.acc_residual_value,
                       a.created_by, a.created_at, a.updated_at, a.summary_account_code,
                       COALESCE(de.tax_amount_sum, 0)  AS tax_amount_sum,
                       COALESCE(de.tax_full_sum, 0)    AS tax_full_sum,
                       COALESCE(de.acc_amount_sum, 0)  AS acc_amount_sum,
                       COALESCE(ai.improvements_total, 0) AS improvements_total,
                       COUNT(*) OVER() AS total_rows
                  FROM assets a
                  LEFT JOIN (
                        SELECT asset_id,
                               SUM(CASE WHEN kind = 'tax' THEN amount ELSE 0 END)        AS tax_amount_sum,
                               SUM(CASE WHEN kind = 'tax' THEN full_amount ELSE 0 END)   AS tax_full_sum,
                               SUM(CASE WHEN kind = 'accounting' THEN amount ELSE 0 END) AS acc_amount_sum
                          FROM depreciation_entries
                         WHERE supplier_id = ?
                         GROUP BY asset_id
                       ) de ON de.asset_id = a.id
                  LEFT JOIN (
                        SELECT asset_id, SUM(amount) AS improvements_total
                          FROM asset_improvements
                         WHERE supplier_id = ?
                         GROUP BY asset_id
                       ) ai ON ai.asset_id = a.id
                 WHERE {$whereSql}
                 ORDER BY COALESCE(a.put_into_use_date, a.acquisition_date) DESC, a.id DESC
                 LIMIT ? OFFSET ?";

        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        $stmt->bindValue($idx++, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $supplierId, PDO::PARAM_INT);
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = $rows === [] ? 0 : (int) $rows[0]['total_rows'];
        $items = [];
        foreach ($rows as $r) {
            unset($r['total_rows']);
            $r = $this->cast($r);
            $increased = round((float) $r['input_price'] + (float) $r['improvements_total'], 2);
            $r['increased_input_price'] = $increased;
            $r['tax_residual'] = round($increased - (float) $r['opening_tax_amount'] - (float) $r['tax_full_sum'], 2);
            $r['acc_residual'] = round($increased - (float) $r['opening_acc_amount'] - (float) $r['acc_amount_sum'], 2);
            $items[] = $r;
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Hromadné odpisové sestavy potřebují úplný plán pro výpočet; běžný seznam
     * jej kvůli objemu JSON vůbec nevybírá. Načítáme jen konkrétní karty po dávkách.
     *
     * @param list<int> $assetIds
     * @return array<int,array<string,mixed>>
     */
    public function importedPlansForAssets(int $supplierId, array $assetIds): array
    {
        $plans = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $assetIds))), 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT id, imported_depreciation_plan FROM assets
                  WHERE supplier_id = ? AND id IN ($placeholders) AND imported_depreciation_plan IS NOT NULL"
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $plans[(int) $row['id']] = json_decode($row['imported_depreciation_plan'], true, 512, JSON_THROW_ON_ERROR);
            }
        }
        return $plans;
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM assets WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** Čerstvá karta pod řádkovým zámkem; volat jen uvnitř transakce. */
    public function findForUpdate(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM assets WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * @param array<string,mixed> $data whitelist self::COLUMNS
     */
    public function insert(int $supplierId, array $data): int
    {
        $cols = ['supplier_id'];
        $params = [$supplierId];
        foreach (self::COLUMNS as $col) {
            if (array_key_exists($col, $data)) {
                $cols[] = $col;
                $params[] = $col === 'imported_depreciation_plan' && $data[$col] !== null
                    ? json_encode($data[$col], JSON_THROW_ON_ERROR) : $data[$col];
            }
        }
        $place = implode(', ', array_fill(0, count($cols), '?'));
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO assets (' . implode(', ', $cols) . ') VALUES (' . $place . ')'
        )->execute($params);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data whitelist self::COLUMNS
     */
    public function update(int $supplierId, int $id, array $data): void
    {
        $set = [];
        $params = [];
        foreach (self::COLUMNS as $col) {
            if (array_key_exists($col, $data)) {
                $set[] = $col . ' = ?';
                $params[] = $col === 'imported_depreciation_plan' && $data[$col] !== null
                    ? json_encode($data[$col], JSON_THROW_ON_ERROR) : $data[$col];
            }
        }
        if ($set === []) {
            return;
        }
        $params[] = $id;
        $params[] = $supplierId;
        $this->db->pdo()->prepare(
            'UPDATE assets SET ' . implode(', ', $set) . ' WHERE id = ? AND supplier_id = ?'
        )->execute($params);
    }

    public function delete(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM assets WHERE id = ? AND supplier_id = ?')
            ->execute([$id, $supplierId]);
        // Dimenze karty jsou polymorfní vazba bez FK — úklid ručně (F4).
        (new DimensionAssignmentRepository($this->db))->deleteDocument($supplierId, 'asset', $id);
    }

    /**
     * Návrh dalšího inventárního čísla řady M-000001 (jen default, uživatel může vlastní).
     */
    public function nextInventoryNumber(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT MAX(CAST(SUBSTRING(inventory_number, 3) AS UNSIGNED))
               FROM assets
              WHERE supplier_id = ? AND inventory_number REGEXP '^M-[0-9]+$'"
        );
        $stmt->execute([$supplierId]);
        $max = (int) $stmt->fetchColumn();
        return sprintf('M-%06d', $max + 1);
    }

    /**
     * Karty pro hromadné zaúčtování odpisů roku (bookYear §3.4): in_use zařazené
     * do konce roku + vyřazené v daném roce.
     *
     * @return list<array<string,mixed>>
     */
    public function listForBooking(int $supplierId, int $fiscalYear, ?string $startsOn = null, ?string $endsOn = null): array
    {
        // Hranice zdaňovacího období — reálné (hospodářský rok), fallback kalendářní.
        $yearEnd = $endsOn ?? sprintf('%04d-12-31', $fiscalYear);
        $yearStart = $startsOn ?? sprintf('%04d-01-01', $fiscalYear);
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM assets
              WHERE supplier_id = ?
                AND put_into_use_date IS NOT NULL AND put_into_use_date <= ?
                AND (status = 'in_use'
                     OR (status = 'disposed' AND disposal_date >= ? AND disposal_date <= ?))
              ORDER BY inventory_number ASC, id ASC"
        );
        $stmt->execute([$supplierId, $yearEnd, $yearStart, $yearEnd]);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── technická zhodnocení (§33, R15) ──────────────────────────────────────

    /**
     * TZ karty vzestupně dle data dokončení (tvar pro DepreciationContext).
     *
     * @return list<array<string,mixed>>
     */
    public function improvements(int $assetId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, asset_id, completed_on, amount, description, purchase_invoice_id, created_at
               FROM asset_improvements
              WHERE asset_id = ?
              ORDER BY completed_on ASC, id ASC'
        );
        $stmt->execute([$assetId]);
        return array_map(fn ($r) => $this->castImprovement($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array{completed_on:string, amount:float, description?:?string, purchase_invoice_id?:?int} $data
     */
    public function insertImprovement(int $supplierId, int $assetId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO asset_improvements (supplier_id, asset_id, completed_on, amount, description, purchase_invoice_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $assetId,
            (string) $data['completed_on'],
            round((float) $data['amount'], 2),
            $data['description'] ?? null,
            $data['purchase_invoice_id'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function findImprovement(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, asset_id, completed_on, amount, description, purchase_invoice_id, created_at
               FROM asset_improvements
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->castImprovement($row);
    }

    public function deleteImprovement(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM asset_improvements WHERE id = ? AND supplier_id = ?')
            ->execute([$id, $supplierId]);
    }

    // ── kandidáti z přijatých faktur (R25) ────────────────────────────────────

    /**
     * PF s příznakem majetku (hlavička nebo řádek), ze kterých lze založit kartu.
     * Víc karet na jednu PF je povoleno — has_asset je jen informativní flag.
     *
     * @return list<array<string,mixed>>
     */
    public function purchaseCandidates(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, c.company_name AS vendor,
                    pi.issue_date, pi.tax_date, pi.total_without_vat, pi.total_with_vat,
                    cur.code AS currency, pi.exchange_rate, pi.vat_deduction,
                    (SELECT pii.description FROM purchase_invoice_items pii
                      WHERE pii.purchase_invoice_id = pi.id
                      ORDER BY pii.is_fixed_asset DESC, pii.id LIMIT 1) AS description,
                    EXISTS (SELECT 1 FROM assets a WHERE a.purchase_invoice_id = pi.id) AS has_asset
               FROM purchase_invoices pi
               JOIN clients c    ON c.id   = pi.vendor_id
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.status IN ('received','booked','paid')
                AND (pi.is_fixed_asset = 1
                     OR EXISTS (SELECT 1 FROM purchase_invoice_items pii
                                 WHERE pii.purchase_invoice_id = pi.id AND pii.is_fixed_asset = 1))
              ORDER BY pi.issue_date DESC, pi.id DESC"
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['id'] = (int) $r['id'];
            $r['total_without_vat'] = (float) $r['total_without_vat'];
            $r['total_with_vat'] = (float) $r['total_with_vat'];
            $r['exchange_rate'] = $r['exchange_rate'] === null ? null : (float) $r['exchange_rate'];
            $r['has_asset'] = (bool) $r['has_asset'];
            $out[] = $r;
        }
        return $out;
    }

    // ── interní ───────────────────────────────────────────────────────────────

    private function cast(array $r): array
    {
        if (array_key_exists('imported_depreciation_plan', $r)) {
            $r['imported_depreciation_plan'] = $r['imported_depreciation_plan'] === null
                ? null : json_decode($r['imported_depreciation_plan'], true, 512, JSON_THROW_ON_ERROR);
        }
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['purchase_invoice_id'] = $r['purchase_invoice_id'] === null ? null : (int) $r['purchase_invoice_id'];
        $r['purchase_invoice_item_id'] = $r['purchase_invoice_item_id'] === null ? null : (int) $r['purchase_invoice_item_id'];
        $r['input_price'] = (float) $r['input_price'];
        $r['disposal_price'] = $r['disposal_price'] === null ? null : (float) $r['disposal_price'];
        $r['sale_invoice_id'] = ($r['sale_invoice_id'] ?? null) === null ? null : (int) $r['sale_invoice_id'];
        $r['disposal_entry_id'] = ($r['disposal_entry_id'] ?? null) === null ? null : (int) $r['disposal_entry_id'];
        $r['tax_group'] = $r['tax_group'] === null ? null : (int) $r['tax_group'];
        $r['is_first_owner'] = (bool) $r['is_first_owner'];
        $r['is_m1_vehicle'] = (bool) $r['is_m1_vehicle'];
        $r['m1_limit_exception'] = (bool) $r['m1_limit_exception'];
        $r['is_zero_emission'] = (bool) $r['is_zero_emission'];
        $r['opening_tax_years'] = (int) $r['opening_tax_years'];
        $r['opening_tax_amount'] = (float) $r['opening_tax_amount'];
        $r['opening_acc_months'] = (int) $r['opening_acc_months'];
        $r['opening_acc_amount'] = (float) $r['opening_acc_amount'];
        $r['acc_useful_life_months'] = $r['acc_useful_life_months'] === null ? null : (int) $r['acc_useful_life_months'];
        $r['acc_residual_value'] = (float) $r['acc_residual_value'];
        $r['created_by'] = $r['created_by'] === null ? null : (int) $r['created_by'];
        if (isset($r['tax_amount_sum'])) {
            $r['tax_amount_sum'] = (float) $r['tax_amount_sum'];
            $r['tax_full_sum'] = (float) $r['tax_full_sum'];
            $r['acc_amount_sum'] = (float) $r['acc_amount_sum'];
            $r['improvements_total'] = (float) $r['improvements_total'];
        }
        return $r;
    }

    private function castImprovement(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['asset_id'] = (int) $r['asset_id'];
        $r['amount'] = (float) $r['amount'];
        $r['purchase_invoice_id'] = $r['purchase_invoice_id'] === null ? null : (int) $r['purchase_invoice_id'];
        return $r;
    }

    /**
     * Karty v provozu s vazbou na oprávky (accumulated_account_code NOT NULL) —
     * podklad měsíční kontroly „0xx bez oprávek" (audit 2026-07, D8). Karty bez
     * odpisového účtu (§27 pozemky, opravné položky) se do kontroly nepočítají.
     *
     * @return list<array{id:int, inventory_number:string, name:string, put_into_use_date:?string,
     *                    input_price:float, asset_account_code:string, accumulated_account_code:string}>
     */
    public function listDepreciableForCheck(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, inventory_number, name, put_into_use_date, input_price,
                    asset_account_code, accumulated_account_code
               FROM assets
              WHERE supplier_id = ? AND status = 'in_use' AND accumulated_account_code IS NOT NULL
              ORDER BY asset_account_code, id"
        );
        $stmt->execute([$supplierId]);
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['input_price'] = (float) $r['input_price'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
