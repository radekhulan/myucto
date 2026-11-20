<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Repository\StockLevelRepository;
use PDO;

/**
 * Sestava Prodeje skladových karet: kdo, kdy, co a za kolik koupil.
 *
 * Zdrojem jsou řádky vydaných faktur a dobropisů navázané na skladovou kartu
 * (`invoice_items.stock_item_id`), protože jen tam je prodejní cena a odběratel. Skladová
 * kniha by dala množství, ale ne cenu, a nezachytila by prodej bez automatické
 * výdejky. Proforma, daňový doklad k platbě a storno sklad neprodávají, koncept
 * a stornovaný doklad se nepočítají.
 *
 * Dobropis snižuje tržbu vždy (částka záporně bez ohledu na uložené znaménko). Prodané
 * množství snižuje jen o zboží, které se vrátilo na sklad (zaúčtovaná vratka k řádku
 * dobropisu); dobropis bez vratky, typicky dodatečná sleva, množství nemění. Období se
 * určuje podle DUZP (bez něj podle data vystavení), stejně jako prodej v přehledech tržeb.
 *
 * Částky jsou v měně dokladu a sčítají se po měnách; přepočet kurzem sestava
 * nedělá, aby se nemíchaly kurzy různých dnů.
 *
 * Volba `include_imported` přidá řádky importovaných faktur (`import_batch_id`), které
 * kartu nemají, ale kód položky (`seller_item_code`) se shoduje s kódem karty. Typicky
 * historie faktur z předchozího systému: zboží odešlo tam, výdejka v MyÚčtu nevznikla
 * a vznikat nesmí, proto se karta do řádku neukládá a páruje se až tady. Takový řádek
 * má `matched_by_code`, dobropis odečítá množství z řádku. Když se kód karty později
 * změní, staré řádky s ní spárovat nejde.
 */
final class StockSalesReportService
{
    public const GROUP_BY = ['none', 'client', 'item'];
    public const MAX_EXPORT_ROWS = 20000;
    private const MAX_GROUPS = 1000;

    private const FROM = ' FROM invoice_items ii
        JOIN invoices i ON i.id = ii.invoice_id
        JOIN stock_items si ON si.id = ii.stock_item_id AND si.supplier_id = i.supplier_id
        LEFT JOIN currencies cur ON cur.id = i.currency_id';

    /** Totéž včetně řádků importovaných faktur spárovaných s kartou podle kódu položky. */
    private const FROM_WITH_IMPORTED = ' FROM invoice_items ii
        JOIN invoices i ON i.id = ii.invoice_id
        LEFT JOIN invoices ip ON ip.id = i.parent_invoice_id AND ip.supplier_id = i.supplier_id
        LEFT JOIN stock_items sic ON ii.stock_item_id IS NULL
             AND (i.import_batch_id IS NOT NULL OR ip.import_batch_id IS NOT NULL)
             AND sic.supplier_id = i.supplier_id AND sic.sku = ii.seller_item_code
        JOIN stock_items si ON si.id = COALESCE(ii.stock_item_id, sic.id) AND si.supplier_id = i.supplier_id
        LEFT JOIN currencies cur ON cur.id = i.currency_id';

    /**
     * Platný skladový doklad k řádku faktury: zaúčtovaný a ne protidoklad storna.
     * Storno původní doklad přepne na `reversed` a zaúčtuje protidoklad se stejnou
     * vazbou na řádek faktury; bez druhé podmínky by se vrácený kus hlásil jako prodaný.
     */
    private const LIVE_DOCUMENT = "d.status = 'posted'
        AND NOT EXISTS (SELECT 1 FROM stock_documents orig
                         WHERE orig.supplier_id = d.supplier_id AND orig.reversal_document_id = d.id)";

    /** Kusy (sériová čísla, šarže) vydané nebo vrácené k řádku faktury `ii`. */
    private const LINE_UNITS_FROM = ' FROM stock_document_lines l
        JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id AND ' . self::LIVE_DOCUMENT . '
        JOIN stock_tracking_allocations a ON a.stock_document_line_id = l.id AND a.supplier_id = l.supplier_id
        JOIN stock_tracking_units stu ON stu.id = a.stock_tracking_unit_id AND stu.supplier_id = a.supplier_id';

    /** Částka řádku bez DPH; dobropis vždy záporně. */
    private const TOTAL = "CASE WHEN i.invoice_type = 'credit_note' THEN -ABS(ii.total_without_vat) ELSE ii.total_without_vat END";

    /** Prodané množství; dobropis jen za zboží vrácené na sklad, u řádku bez karty (import) vždy. */
    private const QTY = "CASE WHEN i.invoice_type <> 'credit_note' THEN ii.quantity
        WHEN ii.stock_item_id IS NULL THEN -ABS(ii.quantity)
        WHEN EXISTS (SELECT 1 FROM stock_document_lines rl
                       JOIN stock_documents d ON d.id = rl.document_id AND d.supplier_id = rl.supplier_id
                            AND d.doc_type = 'receipt' AND " . self::LIVE_DOCUMENT . "
                      WHERE rl.supplier_id = i.supplier_id AND rl.invoice_item_id = ii.id)
        THEN -ABS(ii.quantity) ELSE 0 END";

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $input query parametry požadavku
     * @return array<string,mixed>
     */
    public static function normalizeFilters(array $input, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $from = trim((string) ($input['date_from'] ?? ''));
        $to = trim((string) ($input['date_to'] ?? ''));
        $from = $from === '' ? $today->format('Y') . '-01-01' : $from;
        $to = $to === '' ? $today->format('Y-m-d') : $to;
        foreach (['date_from' => $from, 'date_to' => $to] as $field => $value) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($d === false || $d->format('Y-m-d') !== $value) {
                throw new StockException('validation_failed', 'Neplatné datum (formát RRRR-MM-DD).', 422, ['field' => $field]);
            }
        }
        if ($from > $to) {
            throw new StockException('validation_failed', 'Začátek období je po jeho konci.', 422, ['field' => 'date_from']);
        }
        $filters = ['date_from' => $from, 'date_to' => $to];
        foreach (['client_id', 'category_id', 'warehouse_id', 'stock_item_id'] as $key) {
            if (!isset($input[$key]) || $input[$key] === '' || $input[$key] === null) {
                continue;
            }
            $id = filter_var($input[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new StockException('validation_failed', 'Neplatný identifikátor filtru.', 422, ['field' => $key]);
            }
            $filters[$key] = $id;
        }
        $q = trim((string) ($input['q'] ?? ''));
        if ($q !== '') {
            $filters['q'] = mb_substr($q, 0, 100);
        }
        if (filter_var($input['include_imported'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $filters['include_imported'] = true;
        }
        $groupBy = (string) ($input['group_by'] ?? 'none');
        if (!in_array($groupBy, self::GROUP_BY, true)) {
            throw new StockException('validation_failed', 'Neznámé seskupení.', 422, ['field' => 'group_by']);
        }
        $filters['group_by'] = $groupBy;
        return $filters;
    }

    /**
     * @param array<string,mixed> $filters výstup {@see normalizeFilters()}
     * @return array<string,mixed>
     */
    public function report(int $supplierId, array $filters, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        [$where, $params, $from] = $this->where($supplierId, $filters);

        $totals = $this->totals($from, $where, $params);
        $rows = $this->rows($supplierId, $from, $where, $params, $perPage, ($page - 1) * $perPage);
        $groupBy = (string) ($filters['group_by'] ?? 'none');

        return [
            'filters'    => $filters,
            'items'      => $rows,
            'groups'     => $groupBy === 'none' ? [] : $this->groups($groupBy, $from, $where, $params),
            'totals'     => $totals,
            'pagination' => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $totals['lines'],
                'pages'    => max(1, (int) ceil($totals['lines'] / $perPage)),
            ],
        ];
    }

    /**
     * Všechny řádky pro export (bez stránkování), nejvýše {@see MAX_EXPORT_ROWS}.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function export(int $supplierId, array $filters): array
    {
        [$where, $params, $from] = $this->where($supplierId, $filters);
        $totals = $this->totals($from, $where, $params);
        if ($totals['lines'] > self::MAX_EXPORT_ROWS) {
            throw new StockException('too_many_rows', 'Sestava má víc než ' . self::MAX_EXPORT_ROWS
                . ' řádků. Zužte období nebo filtry.', 422, ['lines' => $totals['lines']]);
        }
        $groupBy = (string) ($filters['group_by'] ?? 'none');
        return [
            'filters' => $filters,
            'items'   => $this->rows($supplierId, $from, $where, $params, max(1, $totals['lines']), 0),
            'groups'  => $groupBy === 'none' ? [] : $this->groups($groupBy, $from, $where, $params),
            'totals'  => $totals,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:list<mixed>,2:string} podmínka, parametry, FROM
     */
    private function where(int $supplierId, array $filters): array
    {
        $where = [
            'i.supplier_id = ?',
            "i.invoice_type IN ('invoice','credit_note')",
            "i.status NOT IN ('draft','cancelled')",
            'i.effective_tax_date BETWEEN ? AND ?',
        ];
        $params = [$supplierId, (string) $filters['date_from'], (string) $filters['date_to']];

        if (!empty($filters['client_id'])) {
            $where[] = 'i.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (!empty($filters['warehouse_id'])) {
            // Řádek bez skladu vydává automatická výdejka z výchozího skladu firmy.
            $where[] = '(ii.warehouse_id = ? OR (ii.warehouse_id IS NULL AND EXISTS (
                SELECT 1 FROM warehouses dw WHERE dw.id = ? AND dw.supplier_id = i.supplier_id AND dw.is_default = 1)))';
            $params[] = (int) $filters['warehouse_id'];
            $params[] = (int) $filters['warehouse_id'];
        }
        if (!empty($filters['stock_item_id'])) {
            $where[] = 'si.id = ?';
            $params[] = (int) $filters['stock_item_id'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = StockItemRepository::categorySubtreeSql('si');
            $params[] = (int) $filters['category_id'];
        }
        if (!empty($filters['q'])) {
            $q = (string) $filters['q'];
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $or = ['si.sku LIKE ?', 'si.name LIKE ?', 'ii.description LIKE ?'];
            array_push($params, $like, $like, $like);
            if (StockItemRepository::identifierSearchable($q)) {
                // Konkrétní kus prodaný na tomto řádku (výdejka k faktuře)…
                $or[] = 'EXISTS (SELECT 1' . self::LINE_UNITS_FROM . '
                           WHERE l.supplier_id = i.supplier_id AND l.invoice_item_id = ii.id
                             AND (stu.serial_number LIKE ? OR stu.lot_code LIKE ?))';
                // …nebo textový parametr karty (karta = jeden kus, např. VIN v parametru).
                $or[] = "EXISTS (SELECT 1 FROM stock_item_attribute_values siav
                            JOIN stock_attributes sa ON sa.id = siav.attribute_id AND sa.supplier_id = siav.supplier_id
                           WHERE siav.supplier_id = si.supplier_id AND siav.stock_item_id = si.id
                             AND sa.data_type = 'text' AND siav.value_text LIKE ?)";
                array_push($params, $like, $like, $like);
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        return [implode(' AND ', $where), $params, empty($filters['include_imported']) ? self::FROM : self::FROM_WITH_IMPORTED];
    }

    /**
     * @param list<mixed> $params
     * @return array{lines:int, qty:string, units:list<string>, amounts:list<array{currency:string,total_without_vat:string}>}
     */
    private function totals(string $from, string $where, array $params): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT cur.code AS currency, ii.unit, COUNT(*) AS line_count,
                    SUM(' . self::QTY . ') AS qty,
                    SUM(' . self::TOTAL . ') AS total'
            . $from . ' WHERE ' . $where . ' GROUP BY cur.code, ii.unit ORDER BY cur.code, ii.unit'
        );
        $stmt->execute($params);
        $lines = 0;
        $qty = '0';
        $units = [];
        $amounts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $lines += (int) $r['line_count'];
            $qty = bcadd($qty, (string) $r['qty'], 3);
            $units[(string) $r['unit']] = true;
            $currency = (string) ($r['currency'] ?? '');
            $amounts[$currency] = bcadd($amounts[$currency] ?? '0', (string) $r['total'], 2);
        }
        $out = [];
        foreach ($amounts as $currency => $total) {
            $out[] = ['currency' => $currency, 'total_without_vat' => $total];
        }
        return ['lines' => $lines, 'qty' => $qty, 'units' => array_keys($units), 'amounts' => $out];
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function rows(int $supplierId, string $from, string $where, array $params, int $limit, int $offset): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ii.id AS invoice_item_id, i.id AS invoice_id, i.varsymbol AS invoice_number, i.invoice_type,
                    i.issue_date, i.effective_tax_date AS tax_date, i.client_id,
                    ' . StockLevelRepository::snapshotNameSql('i.client_snapshot') . ' AS client_name,
                    si.id AS stock_item_id, si.sku, si.name, ii.description, ii.warehouse_id,
                    ' . self::QTY . ' AS qty, ii.unit,
                    ' . StockLevelRepository::netUnitPriceSql('i', 'ii') . ' AS unit_price,
                    ' . self::TOTAL . ' AS total_without_vat,
                    cur.code AS currency, ii.stock_item_id IS NULL AS matched_by_code'
            . $from . ' WHERE ' . $where
            . ' ORDER BY tax_date DESC, i.id DESC, ii.order_index ASC, ii.id ASC'
            . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $identifiers = $this->identifiersForLines($supplierId, array_map(static fn (array $r): int => (int) $r['invoice_item_id'], $rows));

        return array_map(static fn (array $r): array => [
            'invoice_item_id'   => (int) $r['invoice_item_id'],
            'invoice_id'        => (int) $r['invoice_id'],
            'invoice_number'    => $r['invoice_number'] !== null ? (string) $r['invoice_number'] : null,
            'invoice_type'      => (string) $r['invoice_type'],
            'issue_date'        => (string) $r['issue_date'],
            'tax_date'          => (string) $r['tax_date'],
            'client_id'         => $r['client_id'] !== null ? (int) $r['client_id'] : null,
            'client_name'       => (string) ($r['client_name'] ?? ''),
            'stock_item_id'     => (int) $r['stock_item_id'],
            'sku'               => (string) $r['sku'],
            'name'              => (string) $r['name'],
            'description'       => (string) ($r['description'] ?? ''),
            'warehouse_id'      => $r['warehouse_id'] !== null ? (int) $r['warehouse_id'] : null,
            'qty'               => (string) $r['qty'],
            'unit'              => (string) ($r['unit'] ?? ''),
            'unit_price'        => (string) $r['unit_price'],
            'total_without_vat' => (string) $r['total_without_vat'],
            'currency'          => (string) ($r['currency'] ?? ''),
            'identifiers'       => $identifiers[(int) $r['invoice_item_id']] ?? [],
            'matched_by_code'   => (bool) $r['matched_by_code'],
        ], $rows);
    }

    /**
     * Sériová čísla a šarže, které odešly na řádku faktury (přes zaúčtovanou výdejku).
     *
     * @param list<int> $invoiceItemIds
     * @return array<int,list<string>>
     */
    private function identifiersForLines(int $supplierId, array $invoiceItemIds): array
    {
        if ($invoiceItemIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($invoiceItemIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT l.invoice_item_id, COALESCE(stu.serial_number, stu.lot_code) AS identifier'
            . self::LINE_UNITS_FROM . '
              WHERE l.supplier_id = ? AND l.invoice_item_id IN (' . $in . ')
              ORDER BY l.invoice_item_id, identifier'
        );
        $stmt->execute([$supplierId, ...$invoiceItemIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if ($r['identifier'] !== null) {
                $out[(int) $r['invoice_item_id']][] = (string) $r['identifier'];
            }
        }
        return $out;
    }

    /**
     * Souhrn po odběratelích nebo kartách; částky po měnách.
     *
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function groups(string $groupBy, string $from, string $where, array $params): array
    {
        [$key, $label, $extra] = $groupBy === 'client'
            ? ['COALESCE(i.client_id, 0)', 'MAX(' . StockLevelRepository::snapshotNameSql('i.client_snapshot') . ')', "'' "]
            : ['si.id', 'MAX(si.name)', 'MAX(si.sku) '];
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $key . ' AS group_id, ' . $label . ' AS label, ' . $extra . 'AS code,
                    cur.code AS currency, COUNT(*) AS line_count, COUNT(DISTINCT i.id) AS documents,
                    SUM(' . self::QTY . ') AS qty,
                    SUM(' . self::TOTAL . ') AS total'
            . $from . ' WHERE ' . $where
            . ' GROUP BY ' . $key . ', cur.code'
        );
        $stmt->execute($params);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $id = (int) $r['group_id'];
            $groups[$id] ??= [
                'id'        => $id > 0 ? $id : null,
                'label'     => (string) ($r['label'] ?? ''),
                'code'      => (string) ($r['code'] ?? ''),
                'lines'     => 0,
                'documents' => 0,
                'qty'       => '0',
                'amounts'   => [],
            ];
            $groups[$id]['lines'] += (int) $r['line_count'];
            // Doklad v jedné měně → počty dokladů přes měny nekolidují.
            $groups[$id]['documents'] += (int) $r['documents'];
            $groups[$id]['qty'] = bcadd($groups[$id]['qty'], (string) $r['qty'], 3);
            $groups[$id]['amounts'][] = ['currency' => (string) ($r['currency'] ?? ''), 'total_without_vat' => bcadd((string) $r['total'], '0', 2)];
        }
        $groups = array_values($groups);
        // Největší odběratel/karta nahoře: podle množství, pak podle názvu.
        usort($groups, static fn (array $a, array $b): int => bccomp($b['qty'], $a['qty'], 3) ?: strcmp($a['label'], $b['label']));
        return array_slice($groups, 0, self::MAX_GROUPS);
    }
}
