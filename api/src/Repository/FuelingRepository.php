<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\Card\CardNumberMask;
use PDO;

/**
 * Repository pro fuelings — tankování (ruční / z přijatých faktur / Axigon parser).
 * Per tenant (supplier_id). Idempotentní sken přes UNIQUE(supplier_id, dedup_hash).
 */
final class FuelingRepository
{
    private const SOURCES = ['manual', 'invoice', 'axigon', 'axigon_ai', 'import', 'cash'];

    /** Způsob přiřazení vozidla (migrace 1808) — method z VehicleResolver::resolve() bez „none". */
    public const CAR_METHODS = ['explicit', 'plate', 'text', 'card', 'default'];

    /** Sloupce vazby na doklad, kterým bylo tankování zaplaceno. */
    private const LINK_KEYS = ['source_purchase_invoice_id', 'source_cash_document_id', 'source_bank_transaction_id', 'source_journal_entry_id'];

    /**
     * Vazby na doklad, kterým bylo tankování zaplaceno (migrace 1801) — pokladní doklad,
     * bankovní pohyb, účetní zápis. Každý JOIN nese tenant predikát (vazby hlídá
     * i trigger, ale read-back nesmí spoléhat jen na něj).
     */
    private const LINK_COLUMNS = ',
                       lcd.doc_number AS source_cash_document_number,
                       lbt.statement_id AS source_bank_statement_id, lbt.posted_at AS source_bank_posted_at,
                       lbt.amount AS source_bank_amount,
                       lje.document_no AS source_journal_entry_number';
    private const LINK_JOINS = '
             LEFT JOIN cash_documents lcd ON lcd.id = f.source_cash_document_id AND lcd.supplier_id = f.supplier_id
             LEFT JOIN bank_transactions lbt ON lbt.id = f.source_bank_transaction_id
                   AND lbt.statement_id IN (SELECT lbs.id FROM bank_statements lbs WHERE lbs.supplier_id = f.supplier_id)
             LEFT JOIN journal_entries lje ON lje.id = f.source_journal_entry_id AND lje.supplier_id = f.supplier_id';

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array{car_id?:int, source?:string, vendor_id?:int, year?:int, month?:int, date_from?:string, date_to?:string, unassigned?:bool} $filters
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($supplierId, $filters);
        $sql = 'SELECT f.*, c.registration AS car_registration, c.name AS car_name,
                       cl.company_name AS vendor_name,
                       pi.vendor_invoice_number AS source_invoice_number' . self::LINK_COLUMNS . '
                  FROM fuelings f
             LEFT JOIN cars c     ON c.id  = f.car_id AND c.supplier_id = f.supplier_id
             LEFT JOIN clients cl ON cl.id = f.vendor_id AND cl.supplier_id = f.supplier_id
             LEFT JOIN purchase_invoices pi ON pi.id = f.source_purchase_invoice_id AND pi.supplier_id = f.supplier_id' . self::LINK_JOINS . '
                 WHERE ' . implode(' AND ', $where) . '
              ORDER BY f.fueled_date DESC, f.fueled_time DESC, f.id DESC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Stránkovaná varianta pro list endpoint — COUNT(*) bez LIMIT + data s LIMIT/OFFSET.
     *
     * @param array{car_id?:int, source?:string, vendor_id?:int, year?:int, month?:int, date_from?:string, date_to?:string, unassigned?:bool} $filters
     * @return array{0:list<array<string,mixed>>, 1:int} [rows, total]
     */
    public function listPaged(int $supplierId, array $filters, int $perPage, int $offset): array
    {
        [$where, $params] = $this->buildWhere($supplierId, $filters);
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM fuelings f WHERE ' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // LIMIT/OFFSET inlinujeme jako validované inty (vzor StockItemRepository::list /
        // DocumentRepository::search) — native prepared statements neumí LIMIT/OFFSET jako parametr.
        $sql = 'SELECT f.*, c.registration AS car_registration, c.name AS car_name,
                       cl.company_name AS vendor_name,
                       pi.vendor_invoice_number AS source_invoice_number' . self::LINK_COLUMNS . '
                  FROM fuelings f
             LEFT JOIN cars c     ON c.id  = f.car_id AND c.supplier_id = f.supplier_id
             LEFT JOIN clients cl ON cl.id = f.vendor_id AND cl.supplier_id = f.supplier_id
             LEFT JOIN purchase_invoices pi ON pi.id = f.source_purchase_invoice_id AND pi.supplier_id = f.supplier_id' . self::LINK_JOINS . '
                 WHERE ' . $whereSql . '
              ORDER BY f.fueled_date DESC, f.fueled_time DESC, f.id DESC
                 LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, $offset);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
        return [$rows, $total];
    }

    /**
     * Distinct roky tankování pro dropdown filtru (scope = supplier [+ auto], NE aktuální
     * rok/měsíc filtr — ať dropdown při výběru roku nezkolabuje na jednu položku).
     *
     * @return list<int>
     */
    public function distinctYears(int $supplierId, ?int $carId = null): array
    {
        $where = ['supplier_id = ?'];
        $params = [$supplierId];
        if ($carId !== null) { $where[] = 'car_id = ?'; $params[] = $carId; }
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT YEAR(fueled_date) AS y FROM fuelings WHERE ' . implode(' AND ', $where) . ' ORDER BY y DESC'
        );
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array{car_id?:int, source?:string, vendor_id?:int, year?:int, month?:int, date_from?:string, date_to?:string, unassigned?:bool} $filters
     * @return array{0:list<string>, 1:list<mixed>} [where, params]
     */
    private function buildWhere(int $supplierId, array $filters): array
    {
        $where = ['f.supplier_id = ?'];
        $params = [$supplierId];
        if (!empty($filters['car_id']))    { $where[] = 'f.car_id = ?';    $params[] = (int) $filters['car_id']; }
        if (!empty($filters['vendor_id'])) { $where[] = 'f.vendor_id = ?'; $params[] = (int) $filters['vendor_id']; }
        if (!empty($filters['source']))    { $where[] = 'f.source = ?';    $params[] = (string) $filters['source']; }
        if (!empty($filters['year']))      { $y = (int) $filters['year']; $where[] = 'f.fueled_date >= ? AND f.fueled_date < ?'; $params[] = sprintf('%04d-01-01', $y); $params[] = sprintf('%04d-01-01', $y + 1); }
        if (!empty($filters['month']))     { $where[] = 'MONTH(f.fueled_date) = ?'; $params[] = (int) $filters['month']; }
        if (!empty($filters['date_from'])) { $where[] = 'f.fueled_date >= ?'; $params[] = (string) $filters['date_from']; }
        if (!empty($filters['date_to']))   { $where[] = 'f.fueled_date <= ?'; $params[] = (string) $filters['date_to']; }
        if (!empty($filters['unassigned'])) { $where[] = 'f.car_id IS NULL'; }
        return [$where, $params];
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT f.*, c.registration AS car_registration, c.name AS car_name, cl.company_name AS vendor_name,
                    pi.vendor_invoice_number AS source_invoice_number' . self::LINK_COLUMNS . '
               FROM fuelings f
          LEFT JOIN cars c     ON c.id  = f.car_id AND c.supplier_id = f.supplier_id
          LEFT JOIN clients cl ON cl.id = f.vendor_id AND cl.supplier_id = f.supplier_id
          LEFT JOIN purchase_invoices pi ON pi.id = f.source_purchase_invoice_id AND pi.supplier_id = f.supplier_id' . self::LINK_JOINS . '
              WHERE f.id = ? AND f.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function create(int $supplierId, array $data, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare($this->insertSql())->execute($this->bind($supplierId, $data, $userId));
        return (int) $pdo->lastInsertId();
    }

    /**
     * Insert z parseru faktury — idempotentní (UNIQUE(supplier_id, dedup_hash)). Při duplicitě
     * DOPLNÍ dříve chybějící litry / jednotkovou cenu (re-sken doplní starší záznamy bez množství),
     * ale NIKDY nepřepíše už vyplněné hodnoty (COALESCE drží existující).
     *
     * Vrací: >0 = id nově vloženého; -1 = doplněn existující; 0 = beze změny (true duplicate).
     */
    public function insertScanned(int $supplierId, array $data, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $sql = $this->insertSql()
            . ' ON DUPLICATE KEY UPDATE
                  quantity   = COALESCE(quantity, VALUES(quantity)),
                  unit_price = COALESCE(unit_price, VALUES(unit_price)),
                  odometer   = COALESCE(odometer, VALUES(odometer)),
                  car_assigned_by = IF(car_id IS NULL AND VALUES(car_id) IS NOT NULL, VALUES(car_assigned_by), car_assigned_by),
                  car_id     = COALESCE(car_id, VALUES(car_id)),
                  card_last4 = COALESCE(card_last4, VALUES(card_last4)),
                  source_cash_document_id    = COALESCE(source_cash_document_id, VALUES(source_cash_document_id)),
                  source_bank_transaction_id = COALESCE(source_bank_transaction_id, VALUES(source_bank_transaction_id)),
                  source_journal_entry_id    = COALESCE(source_journal_entry_id, VALUES(source_journal_entry_id))';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($this->bind($supplierId, $data, $userId));
        $rc = $stmt->rowCount();
        // MariaDB affected-rows: 1 = nový insert, 2 = update existujícího, 0 = beze změny.
        if ($rc === 1) return (int) $pdo->lastInsertId();
        return $rc >= 2 ? -1 : 0;
    }

    public function update(int $id, int $supplierId, array $data): bool
    {
        $b = $this->bind($supplierId, $data, null);
        // bind() pořadí viz insertSql(); pro UPDATE vynecháme supplier_id (idx 0), created_by (poslední),
        // a NEpřepisujeme source/dedup_hash/source_* (ruční editace nemění provenienci).
        $stmt = $this->db->pdo()->prepare(
            'UPDATE fuelings
                SET car_id = ?, fueled_date = ?, fueled_time = ?, fuel_type = ?, quantity = ?, unit = ?,
                    unit_price = ?, amount_without_vat = ?, amount_vat = ?, amount_with_vat = ?, currency = ?,
                    odometer = ?, station = ?, vendor_id = ?, receipt_number = ?, note = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            $b[1], $b[2], $b[3], $b[4], $b[5], $b[6], $b[7], $b[8], $b[9], $b[10], $b[11],
            $b[12], $b[13], $b[14], $b[18], $b[20],
            $id, $supplierId,
        ]);
        return $stmt->rowCount() >= 0;
    }

    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM fuelings WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Přiřadí všechna tankování dané faktury na auto (NULL = bez přiřazení). Vrací počet. */
    public function reassignByInvoice(int $supplierId, int $purchaseInvoiceId, ?int $carId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE fuelings SET car_id = ?, car_assigned_by = IF(? IS NULL, NULL, 'explicit')
              WHERE supplier_id = ? AND source_purchase_invoice_id = ?"
        );
        $stmt->execute([$carId, $carId, $supplierId, $purchaseInvoiceId]);
        return $stmt->rowCount();
    }

    /** Přiřadí tankování z pokladního dokladu na auto (NULL = bez přiřazení). Vrací počet. */
    public function reassignByCashDocument(int $supplierId, int $cashDocumentId, ?int $carId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE fuelings SET car_id = ?, car_assigned_by = IF(? IS NULL, NULL, 'explicit')
              WHERE supplier_id = ? AND source_cash_document_id = ?"
        );
        $stmt->execute([$carId, $carId, $supplierId, $cashDocumentId]);
        return $stmt->rowCount();
    }

    /**
     * Způsob přiřazení vozidla po ruční úpravě / založení (method z VehicleResolver,
     * NULL = bez vozidla). Koncovku karty mění, jen když ji volající zná.
     */
    public function setCarAssignment(int $id, int $supplierId, ?int $carId, ?string $method, ?string $cardLast4 = null): void
    {
        $method = $carId !== null && in_array($method, self::CAR_METHODS, true) ? $method : null;
        $this->db->pdo()->prepare(
            'UPDATE fuelings SET car_id = ?, car_assigned_by = ?, card_last4 = COALESCE(?, card_last4)
              WHERE id = ? AND supplier_id = ?'
        )->execute([$carId, $method, $this->nullableStr($cardLast4, 4), $id, $supplierId]);
    }

    /**
     * Tankování navázaná na doklad (vazba = sloupec z whitelistu). Nejvýš pár řádků
     * — účtenka dá jedno tankování, výpis od stanice desítky.
     *
     * @return list<array{id:int, fueled_date:string, amount_with_vat:float, car_id:int|null, dedup_hash:string|null}>
     */
    public function findByDocumentLink(int $supplierId, string $column, int $documentId): array
    {
        if (!in_array($column, self::LINK_KEYS, true)) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, fueled_date, amount_with_vat, car_id, dedup_hash FROM fuelings
              WHERE supplier_id = ? AND ' . $column . ' = ?
              ORDER BY id LIMIT 200'
        );
        $stmt->execute([$supplierId, $documentId]);
        return array_map(static fn (array $r): array => [
            'id'              => (int) $r['id'],
            'fueled_date'     => (string) $r['fueled_date'],
            'amount_with_vat' => (float) $r['amount_with_vat'],
            'car_id'          => $r['car_id'] !== null ? (int) $r['car_id'] : null,
            'dedup_hash'      => $r['dedup_hash'] !== null ? (string) $r['dedup_hash'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Doplní u existujícího tankování jen CHYBĚJÍCÍ údaje — vyplněné hodnoty nikdy
     * nepřepíše (stejné pravidlo jako insertScanned při duplicitě). Vrací true, když
     * se něco doplnilo.
     *
     * @param array<string,mixed> $data klíče jako pro insertScanned()
     */
    public function fillMissing(int $id, int $supplierId, array $data): bool
    {
        $b = $this->bind($supplierId, $data, null);
        $carMethod = $this->nullableStr($data['car_assigned_by'] ?? null);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE fuelings
                SET fueled_time        = COALESCE(fueled_time, ?),
                    fuel_type          = COALESCE(fuel_type, ?),
                    quantity           = COALESCE(quantity, ?),
                    unit_price         = COALESCE(unit_price, ?),
                    amount_without_vat = COALESCE(amount_without_vat, ?),
                    amount_vat         = COALESCE(amount_vat, ?),
                    odometer           = COALESCE(odometer, ?),
                    station            = COALESCE(station, ?),
                    vendor_id          = COALESCE(vendor_id, ?),
                    receipt_number     = COALESCE(receipt_number, ?),
                    car_assigned_by    = IF(car_id IS NULL AND ? IS NOT NULL, ?, car_assigned_by),
                    car_id             = COALESCE(car_id, ?),
                    card_last4         = COALESCE(card_last4, ?),
                    source_purchase_invoice_id = COALESCE(source_purchase_invoice_id, ?),
                    source_cash_document_id    = COALESCE(source_cash_document_id, ?),
                    source_bank_transaction_id = COALESCE(source_bank_transaction_id, ?),
                    source_journal_entry_id    = COALESCE(source_journal_entry_id, ?)
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            $b[3], $b[4], $b[5], $b[7], $b[8], $b[9], $b[12], $b[13], $b[14], $b[18],
            $b[1], in_array($carMethod, self::CAR_METHODS, true) ? $carMethod : null,
            $b[1], $b[27],
            $b[16], $b[23], $b[24], $b[25],
            $id, $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Koncovka karty a datum bankovního pohybu firmy (vlastníka určuje výpis).
     *
     * @return array{card_last4:string|null, posted_at:string}|null
     */
    public function bankTransactionCard(int $supplierId, int $transactionId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.card_last4, bt.posted_at FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id AND bs.supplier_id = ?
              WHERE bt.id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $transactionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return [
            'card_last4' => $row['card_last4'] !== null ? (string) $row['card_last4'] : null,
            'posted_at'  => substr((string) $row['posted_at'], 0, 10),
        ];
    }

    /**
     * Přepíše vazby na doklad — jen klíče, které v $links jsou (null = zrušit vazbu).
     * Vlastnictví dokladů ověřuje volající (TenantReferenceGuard) i trigger 1802.
     *
     * @param array{source_purchase_invoice_id?:int|null, source_cash_document_id?:int|null,
     *              source_bank_transaction_id?:int|null, source_journal_entry_id?:int|null} $links
     */
    public function setLinks(int $id, int $supplierId, array $links): void
    {
        $set = [];
        $params = [];
        foreach (['source_purchase_invoice_id', 'source_cash_document_id', 'source_bank_transaction_id', 'source_journal_entry_id'] as $col) {
            if (array_key_exists($col, $links)) {
                $set[] = $col . ' = ?';
                $params[] = $this->nullableInt($links[$col]);
            }
        }
        if ($set === []) return;
        $params[] = $id;
        $params[] = $supplierId;
        $this->db->pdo()->prepare('UPDATE fuelings SET ' . implode(', ', $set) . ' WHERE id = ? AND supplier_id = ?')
            ->execute($params);
    }

    /** Existuje už tankování s tímto dedup otiskem? (náhled importu) */
    public function existsByDedup(int $supplierId, string $dedupHash): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM fuelings WHERE supplier_id = ? AND dedup_hash = ? LIMIT 1');
        $stmt->execute([$supplierId, $dedupHash]);
        return $stmt->fetchColumn() !== false;
    }

    /** Patří bankovní pohyb firmě? (bank_transactions nemá supplier_id — vlastníka určuje výpis) */
    public function bankTransactionBelongs(int $supplierId, int $transactionId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id AND bs.supplier_id = ?
              WHERE bt.id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $transactionId]);
        return $stmt->fetchColumn() !== false;
    }

    private function insertSql(): string
    {
        return 'INSERT INTO fuelings
                  (supplier_id, car_id, fueled_date, fueled_time, fuel_type, quantity, unit, unit_price,
                   amount_without_vat, amount_vat, amount_with_vat, currency, odometer, station, vendor_id,
                   source, source_purchase_invoice_id, source_item_id, receipt_number, raw_text, dedup_hash,
                   note, created_by, source_cash_document_id, source_bank_transaction_id, source_journal_entry_id,
                   car_assigned_by, card_last4)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    }

    /** @return list<mixed> Pořadí přesně dle insertSql(). */
    private function bind(int $supplierId, array $data, ?int $userId): array
    {
        $source = $data['source'] ?? 'manual';
        if (!in_array($source, self::SOURCES, true)) $source = 'manual';
        return [
            $supplierId,                                              // 0
            $this->nullableInt($data['car_id'] ?? null),             // 1
            (string) ($data['fueled_date'] ?? ''),                   // 2
            $this->nullableStr($data['fueled_time'] ?? null),        // 3
            $this->nullableStr($data['fuel_type'] ?? null, 60),      // 4
            $this->nullableFloat($data['quantity'] ?? null),         // 5
            (string) ($data['unit'] ?? 'l'),                         // 6
            $this->nullableFloat($data['unit_price'] ?? null),       // 7
            $this->nullableFloat($data['amount_without_vat'] ?? null), // 8
            $this->nullableFloat($data['amount_vat'] ?? null),       // 9
            (float) ($data['amount_with_vat'] ?? 0),                 // 10
            strtoupper((string) ($data['currency'] ?? 'CZK')),       // 11
            $this->nullableInt($data['odometer'] ?? null),           // 12
            $this->nullableStr($data['station'] ?? null, 150),       // 13
            $this->nullableInt($data['vendor_id'] ?? null),          // 14
            $source,                                                 // 15
            $this->nullableInt($data['source_purchase_invoice_id'] ?? null), // 16
            $this->nullableInt($data['source_item_id'] ?? null),     // 17
            $this->nullableStr($data['receipt_number'] ?? null, 40), // 18
            $this->nullableStr($data['raw_text'] ?? null, 500),      // 19
            $this->nullableStr($data['dedup_hash'] ?? null, 64),     // 20
            $this->nullableStr($data['note'] ?? null),               // 21
            $userId,                                                 // 22
            $this->nullableInt($data['source_cash_document_id'] ?? null),    // 23
            $this->nullableInt($data['source_bank_transaction_id'] ?? null), // 24
            $this->nullableInt($data['source_journal_entry_id'] ?? null),    // 25
            // 26 — způsob přiřazení má smysl jen u přiřazeného vozidla
            $this->nullableInt($data['car_id'] ?? null) !== null && in_array($data['car_assigned_by'] ?? null, self::CAR_METHODS, true)
                ? (string) $data['car_assigned_by'] : null,
            CardNumberMask::isValidLast4(isset($data['card_last4']) ? (string) $data['card_last4'] : null)
                ? (string) $data['card_last4'] : null,                // 27
        ];
    }

    private function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        return (int) $v;
    }

    private function nullableFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        return (float) $v;
    }

    private function nullableStr(mixed $v, ?int $max = null): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') return null;
        return $max !== null ? mb_substr($s, 0, $max) : $s;
    }

    private function cast(array $r): array
    {
        return [
            'id'                         => (int) $r['id'],
            'supplier_id'                => (int) $r['supplier_id'],
            'car_id'                     => $r['car_id'] !== null ? (int) $r['car_id'] : null,
            'car_registration'           => isset($r['car_registration']) && $r['car_registration'] !== null ? (string) $r['car_registration'] : null,
            'car_name'                   => isset($r['car_name']) && $r['car_name'] !== null ? (string) $r['car_name'] : null,
            'car_assigned_by'            => isset($r['car_assigned_by']) ? (string) $r['car_assigned_by'] : null,
            'card_last4'                 => isset($r['card_last4']) ? (string) $r['card_last4'] : null,
            'fueled_date'                => (string) $r['fueled_date'],
            'fueled_time'                => $r['fueled_time'] !== null ? substr((string) $r['fueled_time'], 0, 5) : null,
            'fuel_type'                  => $r['fuel_type'] !== null ? (string) $r['fuel_type'] : null,
            'quantity'                   => $r['quantity'] !== null ? (float) $r['quantity'] : null,
            'unit'                       => (string) $r['unit'],
            'unit_price'                 => $r['unit_price'] !== null ? (float) $r['unit_price'] : null,
            'amount_without_vat'         => $r['amount_without_vat'] !== null ? (float) $r['amount_without_vat'] : null,
            'amount_vat'                 => $r['amount_vat'] !== null ? (float) $r['amount_vat'] : null,
            'amount_with_vat'            => (float) $r['amount_with_vat'],
            'currency'                   => (string) $r['currency'],
            'odometer'                   => $r['odometer'] !== null ? (int) $r['odometer'] : null,
            'odometer_estimated'         => isset($r['odometer_estimated']) && $r['odometer_estimated'] !== null ? (int) $r['odometer_estimated'] : null,
            'station'                    => $r['station'] !== null ? (string) $r['station'] : null,
            'vendor_id'                  => $r['vendor_id'] !== null ? (int) $r['vendor_id'] : null,
            'vendor_name'                => isset($r['vendor_name']) && $r['vendor_name'] !== null ? (string) $r['vendor_name'] : null,
            'source'                     => (string) $r['source'],
            'source_purchase_invoice_id' => $r['source_purchase_invoice_id'] !== null ? (int) $r['source_purchase_invoice_id'] : null,
            'source_invoice_number'      => isset($r['source_invoice_number']) && $r['source_invoice_number'] !== null ? (string) $r['source_invoice_number'] : null,
            'source_cash_document_id'     => isset($r['source_cash_document_id']) ? (int) $r['source_cash_document_id'] : null,
            'source_cash_document_number' => isset($r['source_cash_document_number']) ? (string) $r['source_cash_document_number'] : null,
            'source_bank_transaction_id'  => isset($r['source_bank_transaction_id']) ? (int) $r['source_bank_transaction_id'] : null,
            'source_bank_statement_id'    => isset($r['source_bank_statement_id']) ? (int) $r['source_bank_statement_id'] : null,
            'source_bank_posted_at'       => isset($r['source_bank_posted_at']) ? (string) $r['source_bank_posted_at'] : null,
            'source_bank_amount'          => isset($r['source_bank_amount']) ? (float) $r['source_bank_amount'] : null,
            'source_journal_entry_id'     => isset($r['source_journal_entry_id']) ? (int) $r['source_journal_entry_id'] : null,
            'source_journal_entry_number' => isset($r['source_journal_entry_number']) ? (string) $r['source_journal_entry_number'] : null,
            'receipt_number'             => $r['receipt_number'] !== null ? (string) $r['receipt_number'] : null,
            'raw_text'                   => $r['raw_text'] !== null ? (string) $r['raw_text'] : null,
            'note'                       => $r['note'] !== null ? (string) $r['note'] : null,
            'created_at'                 => (string) $r['created_at'],
        ];
    }
}
