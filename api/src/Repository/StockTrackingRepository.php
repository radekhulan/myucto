<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class StockTrackingRepository
{
    public function __construct(private readonly Connection $db) {}

    public function item(int $supplierId, int $itemId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, supplier_id, sku, name, unit, tracking_mode FROM stock_items WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function location(int $supplierId, int $locationId, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, supplier_id, warehouse_id, code, name, is_active FROM warehouse_locations WHERE supplier_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $locationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->castLocation($row);
    }

    public function locations(int $supplierId, ?int $warehouseId = null): array
    {
        $sql = 'SELECT id, supplier_id, warehouse_id, code, name, is_active FROM warehouse_locations WHERE supplier_id = ?';
        $params = [$supplierId];
        if ($warehouseId !== null) {
            $sql .= ' AND warehouse_id = ?';
            $params[] = $warehouseId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY warehouse_id, code, id');
        $stmt->execute($params);
        return array_map($this->castLocation(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function saveLocation(int $supplierId, int $warehouseId, ?int $id, string $code, string $name, bool $active): int
    {
        if ($id === null) {
            $stmt = $this->db->pdo()->prepare('INSERT INTO warehouse_locations (supplier_id, warehouse_id, code, name, is_active) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$supplierId, $warehouseId, $code, $name, (int) $active]);
            return (int) $this->db->pdo()->lastInsertId();
        }
        $stmt = $this->db->pdo()->prepare('UPDATE warehouse_locations SET code = ?, name = ?, is_active = ? WHERE supplier_id = ? AND warehouse_id = ? AND id = ?');
        $stmt->execute([$code, $name, (int) $active, $supplierId, $warehouseId, $id]);
        // Beze změny vrací UPDATE rowCount 0 — existenci proto ověříme zvlášť.
        return $stmt->rowCount() === 1 || $this->location($supplierId, $id) !== null ? $id : 0;
    }

    /**
     * Převodní jednotky karty. `$salesUnits` = true jen balení (issue #17),
     * false jen převodní jednotky šarží, null obojí (alokace šarží berou všechny).
     */
    public function units(int $supplierId, int $itemId, ?bool $salesUnits = null): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, supplier_id, stock_item_id, unit_code, numerator, denominator, ean, is_sales_unit FROM stock_item_units WHERE supplier_id = ? AND stock_item_id = ?' . ($salesUnits === null ? '' : ' AND is_sales_unit = ' . ($salesUnits ? '1' : '0')) . ' ORDER BY unit_code');
        $stmt->execute([$supplierId, $itemId]);
        return array_map(static function (array $row): array {
            foreach (['id', 'supplier_id', 'stock_item_id', 'numerator', 'denominator'] as $key) $row[$key] = (int) $row[$key];
            $row['is_sales_unit'] = (bool) $row['is_sales_unit'];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Balení karet pro seznam (našeptávač) — jeden dotaz pro všechny karty; jen
     * `is_sales_unit = 1`, převodní jednotky šarží ven nesmí (editor dokladu by
     * podle nich přepočítával).
     *
     * @param list<int> $itemIds
     * @return array<int, list<array<string,mixed>>> stock_item_id => balení
     */
    public function unitsForItems(int $supplierId, array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) return [];
        $stmt = $this->db->pdo()->prepare('SELECT stock_item_id, unit_code, numerator, denominator, ean FROM stock_item_units WHERE supplier_id = ? AND is_sales_unit = 1 AND stock_item_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY stock_item_id, unit_code');
        $stmt->execute(array_merge([$supplierId], $ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['stock_item_id']][] = ['unit_code' => (string) $row['unit_code'], 'numerator' => (int) $row['numerator'], 'denominator' => (int) $row['denominator'], 'ean' => $row['ean']];
        }
        return $out;
    }

    /**
     * Převodní jednotky ŠARŽÍ (editor sledování) — spravuje jen řádky
     * `is_sales_unit = 0`; balení karty (issue #17) nechává být. Kolizi kódu
     * s balením hlídá volající (unikátní klíč by jinak shodil INSERT).
     */
    public function replaceUnits(int $supplierId, int $itemId, array $units): void
    {
        $this->db->pdo()->prepare('DELETE FROM stock_item_units WHERE supplier_id = ? AND stock_item_id = ? AND is_sales_unit = 0')->execute([$supplierId, $itemId]);
        $stmt = $this->db->pdo()->prepare('INSERT INTO stock_item_units (supplier_id, stock_item_id, unit_code, numerator, denominator) VALUES (?, ?, ?, ?, ?)');
        foreach ($units as $unit) $stmt->execute([$supplierId, $itemId, $unit['unit_code'], $unit['numerator'], $unit['denominator']]);
    }

    /**
     * Balení karty (issue #17) — spravuje jen řádky `is_sales_unit = 1`, UPSERTEM,
     * aby řádky, které zůstávají, držely id. Převodní jednotky šarží nechává být;
     * kolizi kódu s nimi hlídá volající.
     *
     * @param list<array{unit_code:string, numerator:int, denominator:int, ean:?string}> $units
     */
    public function replaceSalesUnits(int $supplierId, int $itemId, array $units): void
    {
        $codes = array_map(static fn (array $u): string => (string) $u['unit_code'], $units);
        $sql = 'DELETE FROM stock_item_units WHERE supplier_id = ? AND stock_item_id = ? AND is_sales_unit = 1';
        $params = [$supplierId, $itemId];
        if ($codes !== []) {
            $sql .= ' AND unit_code NOT IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
            $params = array_merge($params, $codes);
        }
        $this->db->pdo()->prepare($sql)->execute($params);
        $stmt = $this->db->pdo()->prepare('INSERT INTO stock_item_units (supplier_id, stock_item_id, unit_code, numerator, denominator, ean, is_sales_unit) VALUES (?, ?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE unit_code = VALUES(unit_code), numerator = VALUES(numerator), denominator = VALUES(denominator), ean = VALUES(ean)');
        foreach ($units as $unit) $stmt->execute([$supplierId, $itemId, $unit['unit_code'], $unit['numerator'], $unit['denominator'], $unit['ean'] ?? null]);
    }

    public function unitRatio(int $supplierId, int $itemId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT numerator, denominator FROM stock_item_units WHERE supplier_id = ? AND stock_item_id = ? AND unit_code = ?');
        $stmt->execute([$supplierId, $itemId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['numerator' => (int) $row['numerator'], 'denominator' => (int) $row['denominator']];
    }

    public function findUnit(int $supplierId, int $itemId, int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM stock_tracking_units WHERE supplier_id = ? AND stock_item_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $itemId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->castUnit($row);
    }

    public function findUnitByKey(int $supplierId, int $itemId, string $key, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM stock_tracking_units WHERE supplier_id = ? AND stock_item_id = ? AND tracking_key = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $itemId, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->castUnit($row);
    }

    public function insertUnit(int $supplierId, int $itemId, string $type, string $key, ?string $lot, ?string $serial, ?string $expires): int
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO stock_tracking_units (supplier_id, stock_item_id, tracking_type, tracking_key, lot_code, serial_number, expires_on) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$supplierId, $itemId, $type, $key, $lot, $serial, $expires]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertAllocation(int $supplierId, int $lineId, int $unitId, int $warehouseId, ?int $locationId, string $direction, string $qty, ?int $originalId = null): int
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO stock_tracking_allocations (supplier_id, stock_document_line_id, stock_tracking_unit_id, warehouse_id, location_id, direction, quantity, original_allocation_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$supplierId, $lineId, $unitId, $warehouseId, $locationId, $direction, $qty, $originalId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function allocationsForLine(int $supplierId, int $lineId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT a.*, u.stock_item_id, u.tracking_type, u.lot_code, u.serial_number, u.expires_on FROM stock_tracking_allocations a JOIN stock_tracking_units u ON u.id = a.stock_tracking_unit_id AND u.supplier_id = a.supplier_id WHERE a.supplier_id = ? AND a.stock_document_line_id = ? ORDER BY a.id');
        $stmt->execute([$supplierId, $lineId]);
        return array_map($this->castAllocation(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function balance(int $supplierId, int $unitId, ?int $warehouseId = null, ?int $locationId = null, bool $allLocations = false): int
    {
        $sql = "SELECT COALESCE(SUM(CASE WHEN a.direction = 'in' THEN a.quantity ELSE -a.quantity END), 0) FROM stock_tracking_allocations a JOIN stock_document_lines l ON l.id = a.stock_document_line_id AND l.supplier_id = a.supplier_id JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id WHERE a.supplier_id = ? AND a.stock_tracking_unit_id = ? AND d.status IN ('draft','posted','reversed')";
        $params = [$supplierId, $unitId];
        if ($warehouseId !== null) { $sql .= ' AND a.warehouse_id = ?'; $params[] = $warehouseId; }
        if (!$allLocations) { $sql .= ' AND a.location_id <=> ?'; $params[] = $locationId; }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return \MyInvoice\Service\Stock\StockValuation::qtyToT((string) $stmt->fetchColumn());
    }

    public function history(int $supplierId, int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT a.id, a.stock_document_line_id, a.stock_tracking_unit_id, a.warehouse_id, a.location_id, a.direction, a.quantity, a.original_allocation_id, u.tracking_type, u.lot_code, u.serial_number, u.expires_on, d.id AS document_id, d.doc_number, d.doc_date, d.doc_type, d.status, w.code AS warehouse_code, wl.code AS location_code FROM stock_tracking_allocations a JOIN stock_tracking_units u ON u.id = a.stock_tracking_unit_id AND u.supplier_id = a.supplier_id JOIN stock_document_lines l ON l.id = a.stock_document_line_id AND l.supplier_id = a.supplier_id JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = a.supplier_id JOIN warehouses w ON w.id = a.warehouse_id AND w.supplier_id = a.supplier_id LEFT JOIN warehouse_locations wl ON wl.id = a.location_id AND wl.supplier_id = a.supplier_id WHERE a.supplier_id = ? AND u.stock_item_id = ? AND d.status IN ('posted','reversed') ORDER BY d.doc_date DESC, a.id DESC");
        $stmt->execute([$supplierId, $itemId]);
        return array_map($this->castAllocation(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function inventory(int $supplierId, int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT u.id AS stock_tracking_unit_id, u.tracking_type, u.lot_code, u.serial_number, u.expires_on, a.warehouse_id, a.location_id, w.code AS warehouse_code, wl.code AS location_code, SUM(CASE WHEN a.direction = 'in' THEN a.quantity ELSE -a.quantity END) AS quantity FROM stock_tracking_units u JOIN stock_tracking_allocations a ON a.supplier_id = u.supplier_id AND a.stock_tracking_unit_id = u.id JOIN stock_document_lines l ON l.id = a.stock_document_line_id AND l.supplier_id = a.supplier_id JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id JOIN warehouses w ON w.id = a.warehouse_id AND w.supplier_id = a.supplier_id LEFT JOIN warehouse_locations wl ON wl.id = a.location_id AND wl.supplier_id = a.supplier_id WHERE u.supplier_id = ? AND u.stock_item_id = ? AND d.status IN ('posted','reversed') GROUP BY u.id, u.tracking_type, u.lot_code, u.serial_number, u.expires_on, a.warehouse_id, a.location_id, w.code, wl.code HAVING quantity <> 0 ORDER BY u.expires_on IS NULL, u.expires_on, u.id");
        $stmt->execute([$supplierId, $itemId]);
        return array_map(static function (array $row): array {
            foreach (['stock_tracking_unit_id', 'warehouse_id'] as $key) $row[$key] = (int) $row[$key];
            $row['location_id'] = $row['location_id'] === null ? null : (int) $row['location_id'];
            $row['quantity'] = (string) $row['quantity'];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Zásoba konkrétních kusů karty ve skladu po umístěních (jen posted/reversed doklady),
     * pro klíče `serial:…` / `lot:…|…` (porovnání podle collation sloupce, tedy bez ohledu
     * na velikost písmen). Pořadí je stálé: umístění podle kódu, bez umístění nakonec.
     *
     * @param list<string> $keys
     * @return list<array{tracking_key:string, location_id:?int, location_code:?string, quantity:string}>
     */
    public function stockByKeys(int $supplierId, int $itemId, int $warehouseId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT u.tracking_key, a.location_id, wl.code AS location_code, SUM(CASE WHEN a.direction = 'in' THEN a.quantity ELSE -a.quantity END) AS quantity FROM stock_tracking_units u JOIN stock_tracking_allocations a ON a.supplier_id = u.supplier_id AND a.stock_tracking_unit_id = u.id JOIN stock_document_lines l ON l.id = a.stock_document_line_id AND l.supplier_id = a.supplier_id JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id LEFT JOIN warehouse_locations wl ON wl.id = a.location_id AND wl.supplier_id = a.supplier_id WHERE u.supplier_id = ? AND u.stock_item_id = ? AND a.warehouse_id = ? AND u.tracking_key IN ($in) AND d.status IN ('posted','reversed') GROUP BY u.tracking_key, a.location_id, wl.code HAVING quantity > 0 ORDER BY wl.code IS NULL, wl.code, a.location_id");
        $stmt->execute([$supplierId, $itemId, $warehouseId, ...$keys]);
        return array_map(static fn (array $row): array => [
            'tracking_key' => (string) $row['tracking_key'],
            'location_id' => $row['location_id'] === null ? null : (int) $row['location_id'],
            'location_code' => $row['location_code'] === null ? null : (string) $row['location_code'],
            'quantity' => (string) $row['quantity'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Množství po kusech karty, které doklady faktury pohnuly daným směrem: výdej
     * (`out`) u výdejky k faktuře, příjem (`in`) u vratek k jejím dobropisům. Jen posted.
     *
     * @param list<int> $invoiceIds
     * @return array<string,int> klíč kusu (malými písmeny) => množství v tisícinách
     */
    public function movedByInvoices(int $supplierId, int $itemId, array $invoiceIds, string $docType, string $direction): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($invoiceIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT u.tracking_key, SUM(a.quantity) AS quantity FROM stock_tracking_allocations a JOIN stock_tracking_units u ON u.id = a.stock_tracking_unit_id AND u.supplier_id = a.supplier_id JOIN stock_document_lines l ON l.id = a.stock_document_line_id AND l.supplier_id = a.supplier_id JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id WHERE a.supplier_id = ? AND u.stock_item_id = ? AND d.invoice_id IN ($in) AND d.doc_type = ? AND d.status = 'posted' AND a.direction = ? GROUP BY u.tracking_key");
        $stmt->execute([$supplierId, $itemId, ...$invoiceIds, $docType, $direction]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = mb_strtolower((string) $row['tracking_key']);
            $out[$key] = ($out[$key] ?? 0) + \MyInvoice\Service\Stock\StockValuation::qtyToT((string) $row['quantity']);
        }
        return $out;
    }

    /**
     * Kusy karty skladem ve skladu, sečtené přes umístění (nabídka výběru na faktuře;
     * faktura umístění neukládá). Nejvýš `$limit` kusů, šarže podle expirace.
     *
     * @return list<array{serial_number:?string, lot_code:?string, expires_on:?string, quantity:string}>
     */
    public function inStockByUnit(int $supplierId, int $itemId, int $warehouseId, int $limit = 2000): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT u.serial_number, u.lot_code, u.expires_on, SUM(CASE WHEN a.direction = 'in' THEN a.quantity ELSE -a.quantity END) AS quantity FROM stock_tracking_units u JOIN stock_tracking_allocations a ON a.supplier_id = u.supplier_id AND a.stock_tracking_unit_id = u.id JOIN stock_document_lines l ON l.id = a.stock_document_line_id AND l.supplier_id = a.supplier_id JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id WHERE u.supplier_id = ? AND u.stock_item_id = ? AND a.warehouse_id = ? AND d.status IN ('posted','reversed') GROUP BY u.id, u.serial_number, u.lot_code, u.expires_on HAVING quantity > 0 ORDER BY u.expires_on IS NULL, u.expires_on, u.lot_code, u.serial_number LIMIT " . max(1, $limit));
        $stmt->execute([$supplierId, $itemId, $warehouseId]);
        return array_map(static fn (array $row): array => [
            'serial_number' => $row['serial_number'] === null ? null : (string) $row['serial_number'],
            'lot_code' => $row['lot_code'] === null ? null : (string) $row['lot_code'],
            'expires_on' => $row['expires_on'] === null ? null : (string) $row['expires_on'],
            'quantity' => \MyInvoice\Service\Stock\StockValuation::tToDecimal(\MyInvoice\Service\Stock\StockValuation::qtyToT((string) $row['quantity'])),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Kusy karty, které doklady faktur pohnuly daným směrem, s identitou (pro nabídku
     * vratky na dobropisu). Viz {@see movedByInvoices()}.
     *
     * @param list<int> $invoiceIds
     * @return array<string,array{serial_number:?string, lot_code:?string, expires_on:?string, qtyT:int}> klíč malými písmeny
     */
    public function movedUnitsByInvoices(int $supplierId, int $itemId, array $invoiceIds, string $docType, string $direction): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($invoiceIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT u.tracking_key, u.serial_number, u.lot_code, u.expires_on, SUM(a.quantity) AS quantity FROM stock_tracking_allocations a JOIN stock_tracking_units u ON u.id = a.stock_tracking_unit_id AND u.supplier_id = a.supplier_id JOIN stock_document_lines l ON l.id = a.stock_document_line_id AND l.supplier_id = a.supplier_id JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id WHERE a.supplier_id = ? AND u.stock_item_id = ? AND d.invoice_id IN ($in) AND d.doc_type = ? AND d.status = 'posted' AND a.direction = ? GROUP BY u.tracking_key, u.serial_number, u.lot_code, u.expires_on ORDER BY u.expires_on IS NULL, u.expires_on, u.lot_code, u.serial_number");
        $stmt->execute([$supplierId, $itemId, ...$invoiceIds, $docType, $direction]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = mb_strtolower((string) $row['tracking_key']);
            $out[$key] = [
                'serial_number' => $row['serial_number'] === null ? null : (string) $row['serial_number'],
                'lot_code' => $row['lot_code'] === null ? null : (string) $row['lot_code'],
                'expires_on' => $row['expires_on'] === null ? null : (string) $row['expires_on'],
                'qtyT' => ($out[$key]['qtyT'] ?? 0) + \MyInvoice\Service\Stock\StockValuation::qtyToT((string) $row['quantity']),
            ];
        }
        return $out;
    }

    /**
     * Kusy, které výdejka faktury skutečně vydala, po řádcích faktury (`invoice_item_id`)
     * a s AKTUÁLNÍM textem kmene šarže. `$documentStatus` je `posted` (platná výdejka,
     * vstup dobropisu) nebo `reversed` (výdejka vrácená stornem, vstup obnovení faktury).
     * U řádku se bere jen jeho poslední výdejní řádek, takže opakované vydání a storno
     * kusy nezdvojí.
     *
     * @return array<int, list<array{quantity:string, serial_number:?string, lot_code:?string, expires_on:?string}>> invoice_item_id => kusy
     */
    public function issuedUnitsByInvoiceItem(int $supplierId, int $invoiceId, string $documentStatus = 'posted'): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT l.invoice_item_id, u.serial_number, u.lot_code, u.expires_on, SUM(a.quantity) AS quantity FROM stock_documents d JOIN stock_document_lines l ON l.document_id = d.id AND l.supplier_id = d.supplier_id JOIN stock_tracking_allocations a ON a.stock_document_line_id = l.id AND a.supplier_id = l.supplier_id JOIN stock_tracking_units u ON u.id = a.stock_tracking_unit_id AND u.supplier_id = a.supplier_id WHERE d.supplier_id = ? AND d.invoice_id = ? AND d.doc_type = 'issue' AND d.status = ? AND a.direction = 'out' AND l.invoice_item_id IS NOT NULL AND l.id = (SELECT MAX(l2.id) FROM stock_documents d2 JOIN stock_document_lines l2 ON l2.document_id = d2.id WHERE d2.supplier_id = d.supplier_id AND d2.invoice_id = d.invoice_id AND d2.doc_type = 'issue' AND d2.status = d.status AND l2.invoice_item_id = l.invoice_item_id) GROUP BY l.invoice_item_id, u.id, u.serial_number, u.lot_code, u.expires_on ORDER BY l.invoice_item_id, u.id");
        $stmt->execute([$supplierId, $invoiceId, $documentStatus]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['invoice_item_id']][] = [
                'quantity' => (string) $row['quantity'],
                'serial_number' => $row['serial_number'] === null ? null : (string) $row['serial_number'],
                'lot_code' => $row['lot_code'] === null ? null : (string) $row['lot_code'],
                'expires_on' => $row['expires_on'] === null ? null : (string) $row['expires_on'],
            ];
        }
        return $out;
    }

    /** Oprava kódu a expirace šarže; klíč odvozuje volající ({@see \MyInvoice\Service\Stock\TrackingAllocationService::trackingKey()}). */
    public function updateUnitIdentity(int $supplierId, int $itemId, int $unitId, string $key, string $lot, ?string $expires): void
    {
        $stmt = $this->db->pdo()->prepare("UPDATE stock_tracking_units SET tracking_key = ?, lot_code = ?, expires_on = ? WHERE supplier_id = ? AND stock_item_id = ? AND id = ? AND tracking_type = 'lot'");
        $stmt->execute([$key, $lot, $expires, $supplierId, $itemId, $unitId]);
    }

    /**
     * Řádky konceptů faktur karty s výběrem kusů (`tracking_json`), zamčené k přepisu.
     * Vystavené a stornované faktury sem nepatří: jejich výběr je doklad, který odešel,
     * a obnovení stornované faktury bere kusy z vrácené výdejky.
     *
     * @return list<array{id:int, invoice_id:int, json:string}>
     */
    public function lockDraftInvoiceTracking(int $supplierId, int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT ii.id, ii.invoice_id, ii.tracking_json AS json FROM invoices i STRAIGHT_JOIN invoice_items ii ON ii.invoice_id = i.id WHERE i.supplier_id = ? AND i.status = 'draft' AND ii.stock_item_id = ? AND ii.tracking_json IS NOT NULL FOR UPDATE");
        $stmt->execute([$supplierId, $itemId]);
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'invoice_id' => (int) $row['invoice_id'], 'json' => (string) $row['json']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * Řádky vystavených zálohových faktur karty s výběrem kusů, ze kterých ještě nevznikla
     * finální faktura (nemá potomka `invoice_type = 'invoice'` přes `parent_invoice_id`),
     * zamčené k přepisu. Finální faktura výběr z proformy kopíruje, takže po opravě šarže
     * musí kopírovat už nový text; PDF proformy zůstává (odešlo zákazníkovi). Vyúčtovaná
     * proforma se nepřepisuje.
     *
     * @return list<array{id:int, invoice_id:int, json:string}>
     */
    public function lockUnsettledProformaTracking(int $supplierId, int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT ii.id, ii.invoice_id, ii.tracking_json AS json FROM invoices i STRAIGHT_JOIN invoice_items ii ON ii.invoice_id = i.id WHERE i.supplier_id = ? AND i.status IN ('issued','sent','reminded','paid') AND i.invoice_type = 'proforma' AND ii.stock_item_id = ? AND ii.tracking_json IS NOT NULL AND NOT EXISTS (SELECT 1 FROM invoices f WHERE f.supplier_id = i.supplier_id AND f.parent_invoice_id = i.id AND f.invoice_type = 'invoice') FOR UPDATE");
        $stmt->execute([$supplierId, $itemId]);
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'invoice_id' => (int) $row['invoice_id'], 'json' => (string) $row['json']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function updateInvoiceItemTracking(int $itemRowId, string $json): void
    {
        $this->db->pdo()->prepare('UPDATE invoice_items SET tracking_json = ? WHERE id = ?')->execute([$json, $itemRowId]);
    }

    /**
     * Řádky nezaúčtovaných skladových dokladů karty s výběrem kusů, zamčené k přepisu.
     *
     * @return list<array{id:int, json:string}>
     */
    public function lockDraftDocumentLineTracking(int $supplierId, int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT l.id, l.tracking_input_json AS json FROM stock_documents d JOIN stock_document_lines l ON l.document_id = d.id WHERE d.supplier_id = ? AND d.status = 'draft' AND l.supplier_id = d.supplier_id AND l.stock_item_id = ? AND l.tracking_input_json IS NOT NULL FOR UPDATE");
        $stmt->execute([$supplierId, $itemId]);
        return $this->castJsonRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Další dávka pohybů šarže po kurzoru `$afterAllocationId`: kurzor a řádky dokladů,
     * kterých se pohyby týkají (zaúčtované a stornované doklady, protidoklad storna).
     * Index je vynucený, aby `ORDER BY a.id LIMIT` nešel po primárním klíči přes pohyby všech šarží.
     *
     * @return array{cursor:int, rows:int, line_ids:list<int>}
     */
    public function allocationLineBatch(int $supplierId, int $unitId, int $afterAllocationId, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT a.id, a.stock_document_line_id FROM stock_tracking_allocations a FORCE INDEX (ix_tracking_history) WHERE a.supplier_id = ? AND a.stock_tracking_unit_id = ? AND a.id > ? ORDER BY a.id LIMIT ' . max(1, $limit));
        $stmt->execute([$supplierId, $unitId, $afterAllocationId]);
        $cursor = $afterAllocationId;
        $lineIds = [];
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $cursor = (int) $row['id'];
            $lineIds[(int) $row['stock_document_line_id']] = true;
        }
        return ['cursor' => $cursor, 'rows' => count($rows), 'line_ids' => array_keys($lineIds)];
    }

    /**
     * Zamkne řádky skladových dokladů a vrátí jejich výběr kusů (jen řádky, které ho mají).
     *
     * @param list<int> $lineIds
     * @return list<array{id:int, json:string}>
     */
    public function lockLineTracking(int $supplierId, array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($lineIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT l.id, l.tracking_input_json AS json FROM stock_document_lines l WHERE l.supplier_id = ? AND l.id IN ($in) AND l.tracking_input_json IS NOT NULL ORDER BY l.id FOR UPDATE");
        $stmt->execute([$supplierId, ...$lineIds]);
        return $this->castJsonRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Zapíše výběr kusů více řádků skladových dokladů jedním UPDATE.
     *
     * @param array<int,string> $jsonByLineId id řádku => nový JSON
     */
    public function updateLinesTracking(int $supplierId, array $jsonByLineId): void
    {
        if ($jsonByLineId === []) {
            return;
        }
        $when = implode(' ', array_fill(0, count($jsonByLineId), 'WHEN ? THEN ?'));
        $in = implode(',', array_fill(0, count($jsonByLineId), '?'));
        $params = [];
        foreach ($jsonByLineId as $lineId => $json) {
            array_push($params, $lineId, $json);
        }
        $this->db->pdo()->prepare("UPDATE stock_document_lines SET tracking_input_json = CASE id $when END WHERE supplier_id = ? AND id IN ($in)")
            ->execute([...$params, $supplierId, ...array_keys($jsonByLineId)]);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{id:int, json:string}>
     */
    private function castJsonRows(array $rows): array
    {
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'json' => (string) $row['json']], $rows);
    }

    private function castLocation(array $row): array
    {
        foreach (['id', 'supplier_id', 'warehouse_id'] as $key) $row[$key] = (int) $row[$key];
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }

    private function castUnit(array $row): array
    {
        foreach (['id', 'supplier_id', 'stock_item_id'] as $key) $row[$key] = (int) $row[$key];
        return $row;
    }

    private function castAllocation(array $row): array
    {
        foreach (['id', 'stock_document_line_id', 'stock_tracking_unit_id', 'warehouse_id'] as $key) $row[$key] = (int) $row[$key];
        foreach (['location_id', 'original_allocation_id', 'stock_item_id', 'document_id'] as $key) if (array_key_exists($key, $row)) $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        $row['quantity'] = (string) $row['quantity'];
        return $row;
    }
}
