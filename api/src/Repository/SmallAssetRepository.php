<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro small_assets — karty evidence drobného majetku (§DM, migrace 1094).
 * Kontrakt i styl shodný s {@see ExpenseClassificationRuleRepository}: tenant predikát
 * napsaný přímo v každém SQL (žádný skládaný WHERE, ať TenantPredicateTest nemusí
 * repozitář whitelistovat), volitelné filtry jen jako přívěsek.
 */
final class SmallAssetRepository
{
    private const COLS = 'id, supplier_id, purchase_invoice_id, purchase_invoice_item_id, cash_document_id,
        document_ref, name, inventory_number, vendor_client_id, vendor_name, acquisition_date,
        put_into_use_date, useful_months, quantity, unit_price, price, location, responsible_person, status,
        disposed_at, disposal_reason, sale_invoice_id, sold_at, sale_price, notes, created_by,
        created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM small_assets WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * @param array{status?:?string, q?:?string, location?:?string} $filters
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function paginateForTenant(int $supplierId, array $filters, int $limit, int $offset): array
    {
        [$filter, $params] = $this->filters($supplierId, $filters);
        $pdo = $this->db->pdo();

        $count = $pdo->prepare('SELECT COUNT(*) FROM small_assets a WHERE a.supplier_id = ?' . $filter);
        $count->execute($params);

        $stmt = $pdo->prepare(
            'SELECT ' . $this->prefixedCols() . ', c.company_name AS vendor_client_name
               FROM small_assets a
               LEFT JOIN clients c ON c.id = a.vendor_client_id AND c.supplier_id = a.supplier_id
              WHERE a.supplier_id = ?' . $filter . '
              ORDER BY a.acquisition_date DESC, a.id DESC
              LIMIT ? OFFSET ?'
        );
        foreach ($params as $index => $value) {
            $stmt->bindValue($index + 1, $value);
        }
        $stmt->bindValue(count($params) + 1, max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /**
     * Soupis k datu (§28/5 ZoÚ — podklad k inventarizaci).
     *
     * Karta patří do soupisu, pokud už byla k datu pořízená a JEŠTĚ nebyla vyřazená ani
     * prodaná. `disposed_at/sold_at > ?` (ne `>=`) záměrně: majetek vyřazený/prodaný přesně
     * k rozhodnému dni už na soupisu k tomu dni být nemá — inventarizuje se stav ke konci dne.
     *
     * @return list<array<string,mixed>>
     */
    public function inventoryAsOf(int $supplierId, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $this->prefixedCols() . ', c.company_name AS vendor_client_name
               FROM small_assets a
               LEFT JOIN clients c ON c.id = a.vendor_client_id AND c.supplier_id = a.supplier_id
              WHERE a.supplier_id = ?
                AND a.acquisition_date <= ?
                AND (a.disposed_at IS NULL OR a.disposed_at > ?)
                AND (a.sold_at IS NULL OR a.sold_at > ?)
              ORDER BY a.location IS NULL, a.location, a.acquisition_date, a.id'
        );
        $stmt->execute([$supplierId, $asOf, $asOf, $asOf]);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Přírůstky za období — karty pořízené v rozsahu.
     *
     * @return list<array<string,mixed>>
     */
    public function additionsBetween(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $this->prefixedCols() . ', c.company_name AS vendor_client_name
               FROM small_assets a
               LEFT JOIN clients c ON c.id = a.vendor_client_id AND c.supplier_id = a.supplier_id
              WHERE a.supplier_id = ? AND a.acquisition_date BETWEEN ? AND ?
              ORDER BY a.acquisition_date, a.id'
        );
        $stmt->execute([$supplierId, $from, $to]);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Karty, jejichž cena je nákladem rozsahu: pořízené v rozsahu, bez karet vrácených
     * dodavateli dobropisem zaúčtovaným ve STEJNÉM rozsahu (karta vyřazená v rozsahu,
     * k jejíž faktuře existuje dobropis s datem plnění v rozsahu). Takový nákup a vratka
     * se v obratu 501 vyruší, karta zůstává jen evidencí. Karta vyřazená jinak (rozbitá,
     * prodaná, zlikvidovaná) svůj náklad v roce pořízení měla, proto v sadě zůstává.
     * Stejnou sadu bere kontrola úplnosti evidence i náhled časového rozlišení drobného
     * majetku, aby se jejich součty karet neliší.
     *
     * @return list<array<string,mixed>>
     */
    public function costAdditionsBetween(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT " . $this->prefixedCols() . ", c.company_name AS vendor_client_name
               FROM small_assets a
               LEFT JOIN clients c ON c.id = a.vendor_client_id AND c.supplier_id = a.supplier_id
              WHERE a.supplier_id = ? AND a.acquisition_date BETWEEN ? AND ?
                AND NOT (a.status = 'disposed' AND a.disposed_at BETWEEN ? AND ? AND a.purchase_invoice_id IS NOT NULL
                         AND EXISTS (SELECT 1 FROM purchase_invoices cn
                                      WHERE cn.supplier_id = a.supplier_id AND cn.parent_purchase_invoice_id = a.purchase_invoice_id
                                        AND cn.document_kind = 'credit_note' AND cn.status NOT IN ('cancelled', 'draft')
                                        AND COALESCE(cn.tax_date, cn.issue_date) BETWEEN ? AND ?))
              ORDER BY a.acquisition_date, a.id"
        );
        $stmt->execute([$supplierId, $from, $to, $from, $to, $from, $to]);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Úbytky za období — karty vyřazené v rozsahu.
     *
     * @return list<array<string,mixed>>
     */
    public function disposalsBetween(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $this->prefixedCols() . ', c.company_name AS vendor_client_name
               FROM small_assets a
               LEFT JOIN clients c ON c.id = a.vendor_client_id AND c.supplier_id = a.supplier_id
              WHERE a.supplier_id = ? AND a.disposed_at BETWEEN ? AND ?
              ORDER BY a.disposed_at, a.id'
        );
        $stmt->execute([$supplierId, $from, $to]);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Karty už založené z dokladu — vstup pro idempotenci generování.
     *
     * Klíčem NENÍ id řádku faktury: replaceItems() ho při každé editaci dokladu vynuluje
     * (viz 1094). Přirozený klíč „ta samá věc na tom samém dokladu" je doklad + název +
     * cena, proto se vrací celé karty a porovnání dělá služba.
     *
     * @return list<array<string,mixed>>
     */
    public function forPurchaseInvoice(int $supplierId, int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM small_assets
              WHERE supplier_id = ? AND purchase_invoice_id = ?
              ORDER BY id'
        );
        $stmt->execute([$supplierId, $purchaseInvoiceId]);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string,mixed> $data normalizovaná data karty
     */
    public function insert(int $supplierId, array $data, ?int $createdBy): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO small_assets
                (supplier_id, purchase_invoice_id, purchase_invoice_item_id, cash_document_id, document_ref,
                 name, inventory_number, vendor_client_id, vendor_name, acquisition_date, put_into_use_date,
                 quantity, unit_price, price, location, responsible_person, status, disposed_at,
                 disposal_reason, notes, created_by, asset_kind)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $data['purchase_invoice_id'] ?? null,
            $data['purchase_invoice_item_id'] ?? null,
            $data['cash_document_id'] ?? null,
            $data['document_ref'] ?? null,
            $data['name'],
            $data['inventory_number'] ?? null,
            $data['vendor_client_id'] ?? null,
            $data['vendor_name'] ?? null,
            $data['acquisition_date'],
            $data['put_into_use_date'] ?? null,
            $data['quantity'] ?? 1,
            $data['unit_price'] ?? 0,
            $data['price'],
            $data['location'] ?? null,
            $data['responsible_person'] ?? null,
            $data['status'] ?? 'in_use',
            $data['disposed_at'] ?? null,
            $data['disposal_reason'] ?? null,
            $data['notes'] ?? null,
            $createdBy,
            // DDHM je default: karty založené před zavedením DDNM i ruční karty bez
            // uvedeného druhu jsou hmotné, protože nic jiného systém do teď neuměl.
            ($data['asset_kind'] ?? null) === 'intangible' ? 'intangible' : 'tangible',
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Částečný update — jen předané klíče. Vrací true při zásahu do řádku tenanta.
     *
     * @param array<string,mixed> $fields
     */
    public function update(int $supplierId, int $id, array $fields): bool
    {
        $allowed = [
            'name', 'inventory_number', 'vendor_client_id', 'vendor_name', 'acquisition_date',
            'put_into_use_date', 'quantity', 'unit_price', 'price', 'location', 'responsible_person',
            'status', 'disposed_at', 'disposal_reason', 'sale_invoice_id', 'sold_at', 'sale_price', 'notes',
        ];
        $sets = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "{$col} = ?";
                $params[] = $fields[$col];
            }
        }
        if ($sets === []) {
            return false;
        }
        $params[] = $id;
        $params[] = $supplierId;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE small_assets SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM small_assets WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Volitelné filtry jako PŘÍVĚSEK k pevnému `WHERE a.supplier_id = ?` — viz komentář
     * u ExpenseClassificationRuleRepository::filters().
     *
     * @param array{status?:?string, q?:?string, location?:?string} $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function filters(int $supplierId, array $filters): array
    {
        $sql = '';
        $params = [$supplierId];
        if (($filters['status'] ?? null) !== null) {
            $sql .= ' AND a.status = ?';
            $params[] = $filters['status'];
        }
        if (($filters['location'] ?? null) !== null) {
            $sql .= ' AND a.location = ?';
            $params[] = $filters['location'];
        }
        if (($filters['year'] ?? null) !== null) {
            $sql .= ' AND YEAR(a.acquisition_date) = ?';
            $params[] = (int) $filters['year'];
        }
        if (($filters['q'] ?? null) !== null) {
            $sql .= ' AND (a.name LIKE ? OR a.inventory_number LIKE ? OR a.document_ref LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        return [$sql, $params];
    }

    /** Roky pořízení, ve kterých tenant má karty — do filtru na stránce. @return list<int> */
    public function acquisitionYears(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT YEAR(acquisition_date) AS y FROM small_assets
              WHERE supplier_id = ? AND acquisition_date IS NOT NULL
              ORDER BY y DESC'
        );
        $stmt->execute([$supplierId]);
        return array_map(static fn (mixed $v): int => (int) $v, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Odlišná umístění tenanta — našeptávač ve filtru soupisu. @return list<string> */
    public function locations(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT location FROM small_assets
              WHERE supplier_id = ? AND location IS NOT NULL AND location <> \'\'
              ORDER BY location'
        );
        $stmt->execute([$supplierId]);
        return array_map(static fn (mixed $v): string => (string) $v, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function prefixedCols(): string
    {
        return implode(', ', array_map(
            static fn (string $c): string => 'a.' . trim($c),
            explode(',', preg_replace('/\s+/', ' ', self::COLS) ?? self::COLS),
        ));
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        foreach (['purchase_invoice_id', 'purchase_invoice_item_id', 'cash_document_id', 'vendor_client_id', 'sale_invoice_id', 'created_by'] as $col) {
            $r[$col] = $r[$col] === null ? null : (int) $r[$col];
        }
        if (array_key_exists('useful_months', $r)) {
            $r['useful_months'] = $r['useful_months'] === null ? null : (int) $r['useful_months'];
        }
        $r['quantity'] = (float) $r['quantity'];
        $r['unit_price'] = (float) $r['unit_price'];
        $r['price'] = (float) $r['price'];
        $r['sale_price'] = $r['sale_price'] === null ? null : (float) $r['sale_price'];
        if (array_key_exists('vendor_client_name', $r)) {
            $r['vendor_client_name'] = $r['vendor_client_name'] === null ? null : (string) $r['vendor_client_name'];
        }
        return $r;
    }
}
