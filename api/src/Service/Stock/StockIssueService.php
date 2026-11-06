<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockDocumentRepository;
use MyInvoice\Repository\WarehouseRepository;
use MyInvoice\Service\License\CommercialFeatureAccess;
use PDO;

/**
 * Auto-výdejka k faktuře (Epic SKLAD §5) — JEDINÉ místo, které zná pravidla
 * automatického výdeje/vratky při vystavení dokladu. Tři issue cesty
 * (IssueInvoiceAction, AutoIssueAndSendService, RecurringInvoiceGenerator)
 * a interní storno (CancelInvoiceAction) volají jen tyto metody.
 *
 * KONTRAKT VOLÁNÍ: metody pracující s doklady (issueForInvoice,
 * returnForCreditNote, reverseForInvoice) se volají VÝHRADNĚ uvnitř už
 * otevřené transakce volajícího (flip statusu faktury + skladový pohyb =
 * jedna atomická transakce). Transakce volajícího MUSÍ běžet v READ
 * COMMITTED (`SET TRANSACTION ISOLATION LEVEL READ COMMITTED` těsně před
 * beginTransaction) — jinak by FOR UPDATE zámky StockDocumentService četly
 * stale REPEATABLE-READ snapshot a obešly zámkový návrh B3.
 *
 * Pravidla (§5.4): proforma/tax_document/cancellation = no-op; credit_note =
 * vratka (příjemka v PŮVODNÍ ceně výdeje z parent faktury); regular/final =
 * výdejka. Idempotence B4: existující posted výdejka k faktuře → no-op.
 * StockException('insufficient_stock', …, 409) propaguje nahoru — volající
 * odrolluje celou transakci (faktura zůstává draft).
 */
final class StockIssueService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockDocumentService $documents,
        private readonly StockDocumentRepository $docsRepo,
        private readonly WarehouseRepository $warehouses,
        private readonly StockLevelService $levels,
        private readonly CommercialFeatureAccess $commercialFeatures,
        private readonly StockCommitmentService $commitments,
        private readonly StockUnitConverter $units,
        private readonly \MyInvoice\Repository\StockTrackingRepository $tracking,
    ) {}

    /**
     * Předběžná kontrola dostupnosti PŘED vystavením faktury (než se přidělí
     * varsymbol) — deterministický nedostatek → 409 insufficient_stock, aniž by
     * se propálilo číslo řady FV (spec §5.1, test #3). Autoritativní kontrolu
     * pod zámky dělá až issueForInvoice v transakci; tohle je jen levný předstih
     * pro běžný (nesouběžný) případ. No-op pro vypnutý sklad / no-stock fakturu /
     * proformu / dobropis (dobropis přidává zásobu).
     *
     * @param array<string,mixed> $invoice
     */
    public function assertAvailableForInvoice(int $supplierId, array $invoice): void
    {
        $flags = $this->supplierFlags($supplierId);
        if (!$flags['enabled'] || !$flags['autoIssue']) {
            return;
        }
        $type = (string) ($invoice['invoice_type'] ?? '');
        if ($type === 'credit_note') {
            // Vratka z dobropisu zásobu nepotřebuje, výběr vracených kusů ale ano —
            // chybějící výběr se má ohlásit dřív, než dobropis dostane číslo.
            $parentId = (int) ($invoice['parent_invoice_id'] ?? 0);
            if ($parentId === 0 || !$this->commitments->isInvoiceStockManaged($supplierId, $parentId)) {
                $rows = $this->stockItemsOfInvoice($supplierId, (int) $invoice['id']);
                $this->assertTrackingSelection($supplierId, $rows);
                if ($parentId > 0) {
                    $this->assertReturnMatchesParent($supplierId, $parentId, (int) $invoice['id'], $rows);
                }
            }
            return;
        }
        if (in_array($type, ['proforma', 'tax_document', 'cancellation'], true)) {
            return;
        }
        $invoiceId = (int) $invoice['id'];
        if ($this->commitments->isInvoiceStockManaged($supplierId, $invoiceId)) return;
        if ($this->docsRepo->findPostedIssueByInvoice($supplierId, $invoiceId) !== null) {
            return; // už vydáno — žádný nový nárok na zásobu
        }
        $rows = $this->stockItemsOfInvoice($supplierId, $invoiceId);
        if ($rows === []) {
            return;
        }
        $this->assertTrackingSelection($supplierId, $rows);
        $byWarehouse = $this->groupLinesByWarehouse($supplierId, $rows, []);

        $shortages = [];
        foreach ($byWarehouse as $warehouseId => $lines) {
            $need = [];
            foreach ($lines as $l) {
                $itemId = (int) $l['stock_item_id'];
                $need[$itemId] = ($need[$itemId] ?? 0) + StockValuation::qtyToT((string) $l['qty']);
            }
            $meta = $this->itemsMeta($supplierId, array_keys($need));
            $inWarehouse = [];
            foreach ($need as $itemId => $qtyT) {
                $cur = $this->levels->current($supplierId, (int) $warehouseId, (int) $itemId);
                if ($qtyT > $cur['qtyT']) {
                    $inWarehouse[] = [
                        'stock_item_id' => (int) $itemId,
                        'sku'           => $meta[$itemId]['sku'] ?? null,
                        'name'          => $meta[$itemId]['name'] ?? null,
                        'requested'     => StockValuation::tToDecimal($qtyT),
                        'available'     => StockValuation::tToDecimal($cur['qtyT']),
                    ];
                }
            }
            array_push($shortages, ...StockShortageDetails::enrich($this->db->pdo(), $supplierId, (int) $warehouseId, $inWarehouse));
        }
        if ($shortages !== []) {
            throw new StockException('insufficient_stock', 'Nedostatek zásob pro vystavení faktury.', 409, $shortages);
        }
    }

    /**
     * Gate pro volající: má supplier zapnutý sklad? Rozhoduje, jestli issue
     * cesta obalí flip statusu transakcí (stock_enabled=0 → cesta zůstává
     * byte-for-byte původní, BEZ transakce).
     */
    public function isStockEnabled(int $supplierId): bool
    {
        return $this->supplierFlags($supplierId)['enabled'];
    }

    // ── auto-výdejka (§5.2) ──────────────────────────────────────────────────────

    /**
     * Hook vystavení faktury — volat UVNITŘ otevřené transakce (viz class
     * docblock). No-op pro vypnutý sklad/auto-výdej, proformu, daňový doklad
     * k platbě, storno a faktury bez skladových položek.
     *
     * @param array<string,mixed> $invoice řádek faktury (id, supplier_id,
     *   invoice_type, parent_invoice_id, issue_date, varsymbol)
     */
    public function issueForInvoice(int $supplierId, array $invoice, ?int $userId = null): void
    {
        $flags = $this->supplierFlags($supplierId);
        if (!$flags['enabled'] || !$flags['autoIssue']) {
            return;
        }

        $type = (string) ($invoice['invoice_type'] ?? '');
        // §5.4: proforma a daňový doklad k platbě nehýbou skladem (sklad hýbe
        // až finál); cancellation řeší interní storno (reverseForInvoice).
        if (in_array($type, ['proforma', 'tax_document', 'cancellation'], true)) {
            return;
        }
        if ($type === 'credit_note') {
            $this->returnForCreditNote($supplierId, $invoice, $userId);
            return;
        }

        $this->assertInTransaction();

        $invoiceId = (int) $invoice['id'];
        if ($this->commitments->isInvoiceStockManaged($supplierId, $invoiceId)) return;
        $rows = $this->stockItemsOfInvoice($supplierId, $invoiceId);
        if ($rows === []) {
            return;
        }
        $hasPostedIssue = false;
        $hasPostedReturn = false;
        foreach ($this->docsRepo->listByInvoice($supplierId, $invoiceId) as $existing) {
            if (!self::coversInvoice($existing, 'invoice')) {
                continue;
            }
            $hasPostedIssue = $hasPostedIssue || (string) $existing['doc_type'] === 'issue';
            $hasPostedReturn = $hasPostedReturn || (string) $existing['doc_type'] === 'receipt';
        }

        $issueRows = [];
        $returnRows = [];
        foreach ($rows as $row) {
            $qty = (float) $row['qty'];
            $row['qty'] = number_format(abs($qty), 3, '.', '');
            if ($qty > 0.0) {
                $issueRows[] = $row;
            } elseif ($qty < 0.0) {
                $returnRows[] = $row;
            }
        }

        // I pro bezobslužné cesty (opakovaná faktura, automatické vystavení, obnovení
        // po stornu), které předběžnou kontrolou akce vystavení neprochází. Jen řádky,
        // které se opravdu zaúčtují (už vydaný doklad se znovu nevydává).
        $this->assertTrackingSelection($supplierId, [
            ...($hasPostedIssue ? [] : $issueRows),
            ...($hasPostedReturn ? [] : $returnRows),
        ]);

        $number = trim((string) ($invoice['varsymbol'] ?? ''));
        $description = 'Výdej k faktuře ' . ($number !== '' ? $number : ('#' . $invoiceId));

        // Řádky přes víc skladů → jedna výdejka per sklad (doklad má jeden sklad).
        $docDate = (string) (($invoice['tax_date'] ?? null) ?: $invoice['issue_date']);
        if (!$hasPostedIssue) {
            foreach ($this->groupLinesByWarehouse($supplierId, $issueRows, []) as $warehouseId => $lines) {
                $lines = $this->locateTracking($supplierId, (int) $warehouseId, $lines);
                $doc = $this->documents->create($supplierId, [
                    'doc_type'     => 'issue',
                    'origin'       => 'invoice',
                    'warehouse_id' => $warehouseId,
                    'doc_date'     => $docDate,
                    'description'  => $description,
                    'invoice_id'   => $invoiceId,
                    'lines'        => $lines,
                ], $userId, false); // B10: deaktivovaná karta neblokuje výdej k FV
                $this->documents->post($supplierId, (int) $doc['id'], $userId);
            }
        }

        if (!$hasPostedReturn) {
            foreach ($this->groupLinesByWarehouse($supplierId, $returnRows, []) as $warehouseId => $lines) {
                $lastCosts = $this->docsRepo->lastKnownUnitCosts($supplierId, $warehouseId, $docDate);
                foreach ($lines as &$line) {
                    $itemId = (int) $line['stock_item_id'];
                    $unitCost = $this->currentAvgCost($supplierId, $warehouseId, $itemId);
                    if ($unitCost <= 0.0) {
                        $unitCost = (float) ($lastCosts[$itemId] ?? 0.0);
                    }
                    if ($unitCost <= 0.0) {
                        throw new StockException(
                            'return_unit_cost_missing',
                            'Vratku nelze ocenit — pro skladovou kartu #' . $itemId . ' není známá nenulová pořizovací cena.',
                            422,
                        );
                    }
                    $line['unit_cost'] = number_format(
                        $unitCost,
                        6,
                        '.',
                        '',
                    );
                    $line['note'] = 'Vratka záporného řádku faktury oceněná aktuální průměrnou cenou.';
                }
                unset($line);
                $doc = $this->documents->create($supplierId, [
                    'doc_type'     => 'receipt',
                    'origin'       => 'invoice',
                    'warehouse_id' => $warehouseId,
                    'doc_date'     => $docDate,
                    'description'  => 'Vratka k faktuře ' . ($number !== '' ? $number : ('#' . $invoiceId)),
                    'invoice_id'   => $invoiceId,
                    'lines'        => $lines,
                ], $userId, false);
                $this->documents->post($supplierId, (int) $doc['id'], $userId);
            }
        }
    }

    // ── vratka k dobropisu (§5.3) ────────────────────────────────────────────────

    /**
     * Vystavení dobropisu → PŘÍJEMKA (origin='credit_note') v PŮVODNÍ ceně
     * výdeje k parent faktuře (vážený průměr posted výdejových řádků parenta
     * per karta; částečná vratka poměrně přes unit_cost). Bez dohledatelného
     * původního výdeje se řádek ocení aktuální průměrnou skladovou cenou
     * (poznámka na řádku). Idempotence: posted příjemka origin='credit_note'
     * k tomuto dobropisu už existuje → no-op.
     *
     * @param array<string,mixed> $creditNote řádek dobropisu (id, parent_invoice_id, issue_date, varsymbol)
     */
    public function returnForCreditNote(int $supplierId, array $creditNote, ?int $userId = null): void
    {
        $this->assertInTransaction();
        $parentId = (int) ($creditNote['parent_invoice_id'] ?? 0);
        if ($parentId > 0 && $this->commitments->isInvoiceStockManaged($supplierId, $parentId)) return;

        $creditNoteId = (int) $creditNote['id'];
        foreach ($this->docsRepo->listByInvoice($supplierId, $creditNoteId) as $doc) {
            if ((string) $doc['doc_type'] === 'receipt' && self::coversInvoice($doc, 'credit_note')) {
                return; // idempotence — vratka už proběhla
            }
        }

        $rows = $this->stockItemsOfInvoice($supplierId, $creditNoteId);
        if ($rows === []) {
            return;
        }
        $this->assertTrackingSelection($supplierId, $rows);
        if ($parentId > 0) {
            $this->assertReturnMatchesParent($supplierId, $parentId, $creditNoteId, $rows);
        }

        $costs = $parentId > 0 ? $this->parentIssueCosts($supplierId, $parentId) : [];

        foreach ($rows as &$row) {
            $row['qty'] = number_format(abs((float) $row['qty']), 3, '.', '');
        }
        unset($row);
        $byWarehouse = $this->groupLinesByWarehouse($supplierId, $rows, $costs);
        if ($byWarehouse === []) {
            return;
        }

        $number = trim((string) ($creditNote['varsymbol'] ?? ''));
        $description = 'Vratka k dobropisu ' . ($number !== '' ? $number : ('#' . $creditNoteId));

        foreach ($byWarehouse as $warehouseId => $lines) {
            $lastCosts = $this->docsRepo->lastKnownUnitCosts(
                $supplierId,
                $warehouseId,
                (string) (($creditNote['tax_date'] ?? null) ?: $creditNote['issue_date']),
            );
            foreach ($lines as &$line) {
                $itemId = (int) $line['stock_item_id'];
                if (isset($costs[$itemId])) {
                    // Původní cena výdeje parenta — hodnotová návaznost §5.3.
                    $line['unit_cost'] = number_format($costs[$itemId]['unit_cost'], 6, '.', '');
                } else {
                    // Fallback: aktuální průměrná skladová cena + poznámka (warning).
                    $unitCost = $this->currentAvgCost($supplierId, $warehouseId, $itemId);
                    if ($unitCost <= 0.0) {
                        $unitCost = (float) ($lastCosts[$itemId] ?? 0.0);
                    }
                    if ($unitCost <= 0.0) {
                        throw new StockException(
                            'return_unit_cost_missing',
                            'Vratku nelze ocenit — pro skladovou kartu #' . $itemId . ' není známá nenulová pořizovací cena.',
                            422,
                        );
                    }
                    $line['unit_cost'] = number_format(
                        $unitCost,
                        6,
                        '.',
                        '',
                    );
                    $line['note'] = 'Ocenění aktuální průměrnou cenou — původní výdej k faktuře nenalezen.';
                }
            }
            unset($line);

            $doc = $this->documents->create($supplierId, [
                'doc_type'     => 'receipt',
                'origin'       => 'credit_note',
                'warehouse_id' => $warehouseId,
                'doc_date'     => (string) (($creditNote['tax_date'] ?? null) ?: $creditNote['issue_date']),
                'description'  => $description,
                'invoice_id'   => $creditNoteId,
                'lines'        => $lines,
            ], $userId, false); // vratka smí přijmout i na deaktivovanou kartu
            $this->documents->post($supplierId, (int) $doc['id'], $userId);
        }
    }

    // ── interní storno (§5.3) ────────────────────────────────────────────────────

    /**
     * Interní storno faktury → storno (protidoklad v PŮVODNÍ ceně, hodnotově
     * neutrální) VŠECH posted auto-dokladů k faktuře (výdejky origin='invoice'
     * i vratky origin='credit_note' u interního storna dobropisu). No-op bez
     * dokladů; idempotentní (stornovaný doklad už není posted; reverse() navíc
     * hlídá already_reversed). Volat uvnitř transakce interního storna.
     */
    public function reverseForInvoice(int $supplierId, int $invoiceId, ?int $userId): void
    {
        $this->assertInTransaction();

        foreach ($this->docsRepo->listByInvoice($supplierId, $invoiceId) as $doc) {
            if ((string) $doc['status'] !== 'posted') {
                continue;
            }
            // Jen automaticky vzniklé doklady — ručně založený doklad s vazbou
            // na fakturu (origin='manual') interní storno nechává být.
            if (!in_array((string) $doc['origin'], ['invoice', 'credit_note'], true)) {
                continue;
            }
            $this->documents->reverse(
                $supplierId,
                (int) $doc['id'],
                ['reason' => 'Interní storno dokladu'],
                $userId,
            );
        }
    }

    // ── interní ──────────────────────────────────────────────────────────────────

    /**
     * Zaúčtovaný doklad, který už pokrývá výdej faktury (vratku dobropisu): automatický,
     * nebo převzatý z historie skladu (import z jiného programu, původ 'manual'). Bez
     * převzatého by obnovení stornované faktury či dobropisu hýbalo zbožím podruhé.
     *
     * @param array<string,mixed> $doc
     */
    private static function coversInvoice(array $doc, string $origin): bool
    {
        return (string) $doc['status'] === 'posted'
            && ((string) $doc['origin'] === $origin || $doc['history_import_job_id'] !== null);
    }

    /** @return array{enabled:bool, autoIssue:bool} */
    private function supplierFlags(int $supplierId): array
    {
        if (!$this->commercialFeatures->isAvailable()) {
            return ['enabled' => false, 'autoIssue' => false];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT stock_enabled, stock_auto_issue FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'enabled'   => $row !== false && (int) $row['stock_enabled'] === 1,
            'autoIssue' => $row !== false && (int) $row['stock_auto_issue'] === 1,
        ];
    }

    /**
     * Skladové řádky faktury (stock_item_id IS NOT NULL, nenulové množství).
     *
     * `qty` je v ZÁKLADNÍ jednotce karty — řádek fakturovaný v balení (10 KT,
     * 1 KT = 8 ks) vydá 80 ks. Převod dělá jen {@see StockUnitConverter}; výdej,
     * předběžná kontrola dostupnosti i vratka z dobropisu čtou řádky odsud.
     *
     * @return list<array{id:int, stock_item_id:int, warehouse_id:?int, description:string, qty:string}>
     */
    private function stockItemsOfInvoice(int $supplierId, int $invoiceId): array
    {
        // Tenant predikát přes JOIN na invoices (invoice_items nemá supplier_id) —
        // konzistence se zbytkem modulu (LOW-1 security audit; downstream se stejně
        // re-filtruje, tohle je defense-in-depth).
        // Výběr sériových čísel / šarží řádku (2016) a režim sledování karty: výdejka
        // i vratka je potřebují, bez nich `TrackingAllocationService::postLine` skončí
        // `tracking_allocations_required`.
        $trackingColumn = $this->db->hasColumn('invoice_items', 'tracking_json') ? 'ii.tracking_json' : 'NULL';
        $stmt = $this->db->pdo()->prepare(
            'SELECT ii.id, ii.stock_item_id, ii.warehouse_id, ii.description, ii.quantity, ii.unit,
                    ' . $trackingColumn . ' AS tracking_json, COALESCE(si.tracking_mode, \'none\') AS tracking_mode
               FROM invoice_items ii
               JOIN invoices i ON i.id = ii.invoice_id AND i.supplier_id = ?
          LEFT JOIN stock_items si ON si.id = ii.stock_item_id AND si.supplier_id = i.supplier_id
              WHERE ii.invoice_id = ? AND ii.stock_item_id IS NOT NULL
                AND (si.item_type IS NULL OR si.item_type <> \'service\')
              ORDER BY ii.order_index, ii.id'
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $ratios = $this->units->ratios($supplierId, array_map(
            static fn (array $r): array => ['stock_item_id' => (int) $r['stock_item_id'], 'unit' => $r['unit']],
            $rows,
        ));

        $out = [];
        foreach ($rows as $index => $r) {
            $ratio = $ratios[$index];
            $qty = StockUnitConverter::applyRatio((string) $r['quantity'], $ratio['numerator'], $ratio['denominator']);
            if (bccomp($qty, '0', 3) === 0) {
                continue;
            }
            $out[] = [
                'id'            => (int) $r['id'],
                'stock_item_id' => (int) $r['stock_item_id'],
                'warehouse_id'  => $r['warehouse_id'] !== null ? (int) $r['warehouse_id'] : null,
                'description'   => (string) $r['description'],
                'qty'           => $qty,
                'tracking_mode' => (string) $r['tracking_mode'],
                'tracking'      => InvoiceItemTracking::decode($r['tracking_json'] !== null ? (string) $r['tracking_json'] : null),
            ];
        }
        return $out;
    }

    /**
     * Rozdělí skladové řádky faktury per sklad: warehouse_id řádku → sklad
     * původního výdeje parenta (jen u vratky) → výchozí sklad. Bez výchozího
     * skladu → StockException 422 (volající odrolluje, faktura zůstává draft).
     *
     * @param list<array{id:int, stock_item_id:int, warehouse_id:?int, description:string, qty:string}> $rows
     * @param array<int,array{qty:float, value:float, unit_cost:float, warehouse_id:int}> $parentCosts
     * @return array<int,list<array<string,mixed>>> warehouse_id => řádky pro StockDocumentService::create
     */
    private function groupLinesByWarehouse(int $supplierId, array $rows, array $parentCosts): array
    {
        $defaultId = null;
        $defaultLoaded = false;

        $byWarehouse = [];
        foreach ($rows as $row) {
            $warehouseId = $row['warehouse_id'];
            if ($warehouseId === null && isset($parentCosts[$row['stock_item_id']])) {
                $warehouseId = $parentCosts[$row['stock_item_id']]['warehouse_id'];
            }
            if ($warehouseId === null) {
                if (!$defaultLoaded) {
                    $default = $this->warehouses->getDefault($supplierId);
                    $defaultId = $default !== null ? (int) $default['id'] : null;
                    $defaultLoaded = true;
                }
                if ($defaultId === null) {
                    throw new StockException(
                        'stock_no_default_warehouse',
                        'Není nastaven výchozí sklad — založte/aktivujte sklad v nastavení skladu.',
                        422,
                    );
                }
                $warehouseId = $defaultId;
            }
            $line = [
                'stock_item_id'      => $row['stock_item_id'],
                'qty'                => $row['qty'],
                'invoice_item_id'    => $row['id'],
                'source_description' => $row['description'],
            ];
            if (($row['tracking_mode'] ?? 'none') !== 'none') {
                $line['tracking_allocations'] = $row['tracking'] ?? [];
            }
            $byWarehouse[$warehouseId][] = $line;
        }
        return $byWarehouse;
    }

    /**
     * Faktura umístění ve skladu neukládá (obnova archivu, přesuny kusů), výdejka ho ale
     * potřebuje: vybraný kus se vydá z umístění, kde podle zásoby leží, šarže postupně
     * z umístění seřazených podle kódu. Stejný kus uvedený na řádku vícekrát se sečte,
     * více řádků téže karty čerpá ze společné zásoby. Kus, který ve skladu v potřebném
     * množství není, skončí srozumitelnou chybou se sériovým číslem nebo šarží.
     *
     * @param list<array<string,mixed>> $lines řádky výdejky jednoho skladu
     * @return list<array<string,mixed>>
     * @throws StockException tracking_insufficient_stock (409)
     */
    private function locateTracking(int $supplierId, int $warehouseId, array $lines): array
    {
        /** @var array<int,array<string,list<array{location_id:?int, qtyT:int}>>> $stock */
        $stock = [];
        foreach ($lines as &$line) {
            $allocations = $line['tracking_allocations'] ?? [];
            if ($allocations === []) {
                continue;
            }
            $itemId = (int) $line['stock_item_id'];
            $wanted = [];
            foreach ($allocations as $allocation) {
                $key = self::trackingKey($allocation);
                $lower = mb_strtolower($key);
                $wanted[$lower] ??= ['key' => $key, 'allocation' => $allocation, 'qtyT' => 0];
                $wanted[$lower]['qtyT'] += StockValuation::qtyToT((string) $allocation['quantity']);
            }
            $missing = array_values(array_filter(
                array_column($wanted, 'key'),
                static fn (string $key): bool => !isset($stock[$itemId][mb_strtolower($key)]),
            ));
            foreach ($missing as $key) {
                $stock[$itemId][mb_strtolower($key)] = [];
            }
            foreach ($this->tracking->stockByKeys($supplierId, $itemId, $warehouseId, $missing) as $row) {
                $stock[$itemId][mb_strtolower($row['tracking_key'])][] = [
                    'location_id' => $row['location_id'],
                    'qtyT' => StockValuation::qtyToT($row['quantity']),
                ];
            }
            $located = [];
            foreach ($wanted as $lower => $want) {
                $availableT = array_sum(array_column($stock[$itemId][$lower], 'qtyT'));
                if ($availableT < $want['qtyT']) {
                    $a = $want['allocation'];
                    $label = $a['serial_number'] !== null
                        ? 'Sériové číslo ' . $a['serial_number']
                        : 'Šarže ' . $a['lot_code'] . ($a['expires_on'] !== null ? ' (exp. ' . $a['expires_on'] . ')' : '');
                    throw new StockException(
                        'tracking_insufficient_stock',
                        $label . ' není ve skladu v potřebném množství: požadováno '
                            . StockValuation::tToDecimal($want['qtyT']) . ', k dispozici ' . StockValuation::tToDecimal($availableT) . '.',
                        409,
                        [[
                            'stock_item_id' => $itemId,
                            'serial_number' => $a['serial_number'],
                            'lot_code' => $a['lot_code'],
                            'expires_on' => $a['expires_on'],
                            'requested' => StockValuation::tToDecimal($want['qtyT']),
                            'available' => StockValuation::tToDecimal($availableT),
                        ]],
                    );
                }
                $remainingT = $want['qtyT'];
                foreach ($stock[$itemId][$lower] as &$slot) {
                    if ($remainingT <= 0) {
                        break;
                    }
                    $takeT = min($remainingT, $slot['qtyT']);
                    if ($takeT <= 0) {
                        continue;
                    }
                    $located[] = ['quantity' => StockValuation::tToDecimal($takeT), 'location_id' => $slot['location_id']] + $want['allocation'];
                    $slot['qtyT'] -= $takeT;
                    $remainingT -= $takeT;
                }
                unset($slot);
            }
            $line['tracking_allocations'] = $located;
        }
        unset($line);
        return $lines;
    }

    /**
     * Dobropis vrací jen kusy, které původní faktura opravdu vydala, a nejvýš v množství,
     * které ještě nevrátily jiné dobropisy. Jinak by vratka naskladnila kus prodaný jinou
     * fakturou (VIN by byl zároveň prodaný i skladem) nebo šarži, která neodešla.
     * Karta, kterou původní výdejka vydala bez výběru (sledování zapnuté až po prodeji),
     * se nekontroluje: není s čím porovnat.
     *
     * @param list<array<string,mixed>> $rows řádky dobropisu ({@see stockItemsOfInvoice()})
     * @throws StockException tracking_not_on_parent (422)
     */
    private function assertReturnMatchesParent(int $supplierId, int $parentId, int $creditNoteId, array $rows): void
    {
        $byItem = [];
        foreach ($rows as $row) {
            if (($row['tracking_mode'] ?? 'none') === 'none' || ($row['tracking'] ?? []) === []) {
                continue;
            }
            foreach ($row['tracking'] as $allocation) {
                $lower = mb_strtolower(self::trackingKey($allocation));
                $byItem[(int) $row['stock_item_id']][$lower] = ($byItem[(int) $row['stock_item_id']][$lower] ?? 0)
                    + StockValuation::qtyToT((string) $allocation['quantity']);
            }
        }
        if ($byItem === []) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM invoices WHERE supplier_id = ? AND parent_invoice_id = ? AND id <> ?'
        );
        $stmt->execute([$supplierId, $parentId, $creditNoteId]);
        $siblings = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $problems = [];
        foreach ($byItem as $itemId => $wanted) {
            $issued = $this->tracking->movedByInvoices($supplierId, $itemId, [$parentId], 'issue', 'out');
            if ($issued === []) {
                continue;
            }
            $returned = $this->tracking->movedByInvoices($supplierId, $itemId, $siblings, 'receipt', 'in');
            foreach ($wanted as $lower => $qtyT) {
                $leftT = ($issued[$lower] ?? 0) - ($returned[$lower] ?? 0);
                if ($qtyT > $leftT) {
                    $problems[] = substr($lower, (int) strpos($lower, ':') + 1) . ' (vrací se ' . StockValuation::tToDecimal($qtyT)
                        . ', faktura vydala a dosud nevráceno ' . StockValuation::tToDecimal(max(0, $leftT)) . ')';
                }
            }
        }
        if ($problems !== []) {
            throw new StockException(
                'tracking_not_on_parent',
                'Dobropis vrací kusy, které původní faktura nevydala nebo už se vrátily: ' . implode(', ', $problems) . '.',
                422,
            );
        }
    }

    /** Klíč kusu shodný s `TrackingAllocationService::resolveUnit()`. */
    private static function trackingKey(array $allocation): string
    {
        return ($allocation['serial_number'] ?? null) !== null
            ? 'serial:' . $allocation['serial_number']
            : 'lot:' . ($allocation['lot_code'] ?? '') . '|' . ($allocation['expires_on'] ?? '');
    }

    /**
     * Řádky sledovaných karet musí mít vybraná sériová čísla nebo šarže v plném množství
     * řádku (v základní jednotce). Dostupnost konkrétních kusů ověří až zaúčtování výdejky.
     *
     * @param list<array<string,mixed>> $rows {@see stockItemsOfInvoice()}
     * @throws StockException tracking_selection_required (422, details = řádky s chybějícím výběrem)
     */
    private function assertTrackingSelection(int $supplierId, array $rows): void
    {
        $missing = [];
        foreach ($rows as $row) {
            if (($row['tracking_mode'] ?? 'none') === 'none') {
                continue;
            }
            $selectedT = 0;
            foreach ((array) ($row['tracking'] ?? []) as $allocation) {
                $selectedT += StockValuation::qtyToT((string) $allocation['quantity']);
            }
            $requiredT = abs(StockValuation::qtyToT((string) $row['qty']));
            if ($selectedT !== $requiredT) {
                $missing[] = [
                    'invoice_item_id' => (int) $row['id'],
                    'stock_item_id'   => (int) $row['stock_item_id'],
                    'required'        => StockValuation::tToDecimal($requiredT),
                    'selected'        => StockValuation::tToDecimal($selectedT),
                ];
            }
        }
        if ($missing === []) {
            return;
        }
        $meta = $this->itemsMeta($supplierId, array_column($missing, 'stock_item_id'));
        $labels = [];
        foreach ($missing as &$m) {
            $m['sku'] = $meta[$m['stock_item_id']]['sku'] ?? null;
            $m['name'] = $meta[$m['stock_item_id']]['name'] ?? null;
            $labels[] = trim(($m['sku'] ?? '') . ' ' . ($m['name'] ?? '')) . ' (vybráno ' . $m['selected'] . ' z ' . $m['required'] . ')';
        }
        unset($m);
        throw new StockException(
            'tracking_selection_required',
            'U zboží se sledováním vyberte sériová čísla nebo šarže v plném množství řádku: ' . implode(', ', $labels) . '.',
            422,
            $missing,
        );
    }

    /**
     * Původní ceny výdeje k parent faktuře: vážený průměr POSTED výdejových
     * řádků parenta per karta (unit_cost = Σ value_total / Σ qty). Sklad =
     * sklad prvního nalezeného výdeje (víceskladový parent: karta se typicky
     * vydává z jednoho skladu; jinak rozhoduje warehouse_id řádku dobropisu).
     *
     * @return array<int,array{qty:float, value:float, unit_cost:float, warehouse_id:int}>
     */
    private function parentIssueCosts(int $supplierId, int $parentInvoiceId): array
    {
        $costs = [];
        foreach ($this->docsRepo->listByInvoice($supplierId, $parentInvoiceId) as $doc) {
            if ((string) $doc['doc_type'] !== 'issue' || (string) $doc['status'] !== 'posted') {
                continue;
            }
            foreach ($this->docsRepo->lines($supplierId, (int) $doc['id']) as $line) {
                $itemId = (int) $line['stock_item_id'];
                if (!isset($costs[$itemId])) {
                    $costs[$itemId] = [
                        'qty'          => 0.0,
                        'value'        => 0.0,
                        'unit_cost'    => 0.0,
                        'warehouse_id' => (int) $doc['warehouse_id'],
                    ];
                }
                $costs[$itemId]['qty']   += (float) $line['qty'];
                $costs[$itemId]['value'] += (float) $line['value_total'];
            }
        }
        foreach ($costs as $itemId => $c) {
            $costs[$itemId]['unit_cost'] = $c['qty'] > 0 ? round($c['value'] / $c['qty'], 6) : 0.0;
        }
        return $costs;
    }

    /**
     * sku/name karet pro obohacení payloadu insufficient_stock v předběžné kontrole.
     *
     * @param list<int> $itemIds
     * @return array<int,array{sku:string,name:string}>
     */
    private function itemsMeta(int $supplierId, array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, sku, name FROM stock_items WHERE supplier_id = ? AND id IN ($place)"
        );
        $stmt->execute(array_merge([$supplierId], $ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = ['sku' => (string) $r['sku'], 'name' => (string) $r['name']];
        }
        return $out;
    }

    /** Aktuální průměrná skladová cena karty na skladu (fallback ocenění vratky). */
    private function currentAvgCost(int $supplierId, int $warehouseId, int $stockItemId): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT avg_unit_cost FROM stock_levels
              WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ?'
        );
        $stmt->execute([$supplierId, $warehouseId, $stockItemId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? 0.0 : (float) $v;
    }

    /**
     * Pojistka kontraktu: skladový pohyb musí být atomický s flipem statusu
     * faktury — bez otevřené transakce volajícího by create/post commitovaly
     * samostatně a selhání by nechalo nekonzistentní stav.
     */
    private function assertInTransaction(): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException(
                'StockIssueService musí běžet uvnitř otevřené transakce volajícího (READ COMMITTED).'
            );
        }
    }
}
