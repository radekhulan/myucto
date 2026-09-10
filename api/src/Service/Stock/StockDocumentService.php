<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\StockDocumentRepository;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Repository\StockLandedCostRepository;
use MyInvoice\Repository\StockTakeRepository;
use MyInvoice\Repository\WarehouseRepository;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use PDO;

/**
 * Skladové doklady PRI/VYD/PRE (Epic SKLAD) — lifecycle draft → posted → reversed
 * (vzor CashDocumentService). Způsob B: post/reverse NEGENERUJE deníkový zápis,
 * jen skladovou evidenci (stock_levels + skladová kniha).
 *
 * Souběh (B3, ZÁVAZNÉ pořadí zámků): hlavička dokladu FOR UPDATE → řádky
 * stock_levels přes StockLevelService::lockLevels (deterministicky
 * (warehouse_id, stock_item_id) ASC; u převodky OBA sklady v jednom zámku)
 * → číselná řada (DocumentSeriesService FOR UPDATE) až PO kontrole zásob
 * (číslo řady se při 409 nesmí propálit). Deadlock 1213 → jeden retry celé
 * transakce (jen když transakci vlastníme).
 *
 * Storno (§4.4) = protidoklad v PŮVODNÍ ceně (hodnotově neutrální) se stejným
 * doc_date; mutace stavů při stornu probíhá výhradně replayem ledgeru
 * (StockRecomputeService), který storno-výdeje drží ve fixní původní hodnotě.
 *
 * Activity log neřeší service, ale volající Action (vzor CashDocumentService).
 */
final class StockDocumentService
{
    private const DOC_TYPES = ['receipt', 'issue', 'transfer'];
    private const ORIGINS   = ['manual', 'invoice', 'credit_note', 'purchase_invoice', 'inventory', 'purchase_order'];

    /** doc_type → kód číselné řady (PRI/VYD/PRE). */
    private const SERIES = [
        'receipt'  => 'stock_in',
        'issue'    => 'stock_out',
        'transfer' => 'stock_transfer',
    ];

    /** doc_type protidokladu při stornu. */
    private const REVERSE_TYPE = [
        'receipt'  => 'issue',
        'issue'    => 'receipt',
        'transfer' => 'transfer',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly StockLevelService $levels,
        private readonly StockDocumentRepository $docs,
        private readonly DocumentSeriesService $series,
        private readonly AccountingPeriodRepository $periods,
        private readonly StockRecomputeService $recompute,
        private readonly \MyInvoice\Repository\StockValuationSnapshotRepository $snapshots,
        private readonly WarehouseRepository $warehouses,
        private readonly StockItemRepository $items,
        private readonly StockTakeRepository $takes,
        private readonly StockLandedCostRepository $landedCosts,
        private readonly StockReferenceGuard $references,
        private readonly PurchaseOrderStateService $orderStates,
        private readonly \MyInvoice\Service\Eshop\Pricing\CatalogPriceJobService $priceJobs,
        private readonly FulfillmentSourceClaimGuard $fulfillmentClaims,
        private readonly TrackingAllocationService $tracking,
        private readonly StockCommitmentService $commitments,
    ) {}

    // ── CRUD draftu ──────────────────────────────────────────────────────────────

    /**
     * Založí draft dokladu (hlavička + řádky). B10: neaktivní karta blokuje
     * NOVÉ řádky draftu (422) — post existujícího draftu neblokuje.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed> doklad vč. řádků
     */
    public function create(int $supplierId, array $body, ?int $userId, bool $blockInactiveItems = true): array
    {
        // blockInactiveItems=false: auto-výdejka k FV (B10/§5.7 — deaktivovaná karta
        // ve starém draftu vystavení NEblokuje, jen post vrátí warning).
        [$header, $lines] = $this->validateBody($supplierId, $body, blockInactiveItems: $blockInactiveItems);
        $header['created_by'] = $userId;

        return $this->runInTransaction(function () use ($supplierId, $header, $lines): array {
            $id = $this->docs->insertHeader($supplierId, $header);
            foreach ($lines as $line) {
                $line['document_id'] = $id;
                $this->docs->insertLine($supplierId, $line);
            }
            $doc = $this->docs->findWithLines($supplierId, $id);
            if ($doc === null) {
                throw new StockException('not_found', 'Skladový doklad se nepodařilo založit.', 500);
            }
            return $doc;
        });
    }

    /**
     * Úprava draftu (jen status=draft) — přepis hlavičky + replace řádků.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function updateDraft(int $supplierId, int $id, array $body, ?int $userId): array
    {
        return $this->runInTransaction(function () use ($supplierId, $id, $body): array {
            $existing = $this->docs->lockForPost($supplierId, $id);
            if ($existing === null) {
                throw new StockException('not_found', 'Skladový doklad nenalezen.', 404);
            }
            $this->fulfillmentClaims->assertMutable($supplierId, $id);
            if ($existing['status'] !== 'draft') {
                throw new StockException('not_draft', 'Upravovat lze jen rozpracovaný (draft) doklad.', 409);
            }
            $body['allow_over_delivery'] ??= $existing['allow_over_delivery'];
            [$header, $lines] = $this->validateBody($supplierId, $body, blockInactiveItems: true);
            $persistedCosts = $this->landedCosts->listForDocument($supplierId, $id);
            if (!$this->docs->updateDraftHeader($supplierId, $id, $header)) {
                throw new StockException('not_draft', 'Doklad se během úpravy změnil.', 409);
            }
            $this->docs->replaceLines($supplierId, $id, $lines);
            if ($persistedCosts !== []) {
                $this->reallocateLandedCosts($supplierId, $id, (string) $header['doc_date'], $persistedCosts);
            }
            $doc = $this->docs->findWithLines($supplierId, $id);
            if ($doc === null) {
                throw new StockException('not_found', 'Skladový doklad nenalezen.', 404);
            }
            return $doc;
        });
    }

    /** @param list<array<string,mixed>> $costs */
    private function reallocateLandedCosts(int $supplierId, int $documentId, string $docDate, array $costs): void
    {
        $lines = $this->docs->lines($supplierId, $documentId);
        if ($lines === []) {
            return;
        }
        $allocLines = array_map(static function (array $line): array {
            $valueC = StockValuation::valueToC((string) $line['value_total'])
                - StockValuation::valueToC((string) $line['extra_cost']);
            return [
                'value' => $valueC,
                'qty' => StockValuation::qtyToT((string) $line['qty']),
            ];
        }, $lines);
        $allocCosts = array_map(static fn (array $cost): array => [
            'amount' => StockValuation::valueToC((string) $cost['amount']),
            'allocation' => (string) $cost['allocation'],
        ], $costs);
        $extraPerLine = LandedCostAllocator::allocate($allocLines, $allocCosts);
        foreach ($lines as $index => $line) {
            $baseC = $allocLines[$index]['value'];
            $extraC = $extraPerLine[$index] ?? 0;
            $this->docs->updateLineValuation(
                $supplierId,
                (int) $line['id'],
                (string) $line['unit_cost'],
                StockValuation::cToDecimal($baseC + $extraC),
                StockValuation::cToDecimal($extraC),
                $docDate,
            );
        }
    }

    public function deleteDraft(int $supplierId, int $id, ?int $userId): bool
    {
        return $this->runInTransaction(function () use ($supplierId, $id): bool {
            $existing = $this->docs->lockForPost($supplierId, $id);
            if ($existing === null) {
                throw new StockException('not_found', 'Skladový doklad nenalezen.', 404);
            }
            $this->fulfillmentClaims->assertMutable($supplierId, $id);
            if ($existing['status'] !== 'draft') {
                throw new StockException('not_draft', 'Smazat lze jen rozpracovaný (draft) doklad.');
            }
            return $this->docs->deleteDraft($supplierId, $id);
        });
    }

    // ── post (§4.3) ──────────────────────────────────────────────────────────────

    /**
     * Zaúčtuje draft: guardy → zámky levels → případný replay (backdating §3.2)
     * → kontrola dostupnosti → aplikace pohybů → číslo řady → posted. Idempotence
     * dvojkliku (B2): už posted doklad se vrátí beze změny.
     *
     * @return array<string,mixed> doklad vč. řádků + warnings
     */
    public function post(int $supplierId, int $id, ?int $userId): array
    {
        return $this->runInTransaction(fn (): array => $this->doPost($supplierId, $id, $userId));
    }

    public function postFulfillmentShipment(int $supplierId, int $id, int $shipmentId, ?int $userId): array
    {
        return $this->runInTransaction(function () use ($supplierId, $id, $shipmentId, $userId): array {
            $allowance = $this->fulfillmentClaims->bindShipmentDocument($supplierId, $shipmentId, $id);
            return $this->doPost($supplierId, $id, $userId, $allowance);
        });
    }

    /** @return array<string,mixed> */
    private function doPost(int $supplierId, int $id, ?int $userId, array $shipmentAllowance = []): array
    {
        $doc = $this->docs->lockForPost($supplierId, $id);
        if ($doc === null) {
            throw new StockException('not_found', 'Skladový doklad nenalezen.', 404);
        }
        $this->fulfillmentClaims->assertMutable($supplierId, $id);

        // Idempotence dvojkliku (B2) — posted doklad se NIKDY nepřeúčtovává.
        if ($doc['status'] === 'posted') {
            $posted = $this->docs->findWithLines($supplierId, $id) ?? $doc;
            $posted['warnings'] = [];
            return $posted;
        }
        if ($doc['status'] === 'reversed') {
            throw new StockException('already_reversed', 'Stornovaný doklad nelze zaúčtovat.');
        }
        if ($doc['status'] !== 'draft') {
            throw new StockException('not_draft', 'Zaúčtovat lze jen rozpracovaný (draft) doklad.');
        }

        $docType = (string) $doc['doc_type'];
        if (!in_array($docType, self::DOC_TYPES, true)) {
            throw new StockException('invalid_document', 'Neznámý typ skladového dokladu.');
        }
        $warehouseId   = (int) $doc['warehouse_id'];
        $warehouseToId = $doc['warehouse_to_id'] !== null ? (int) $doc['warehouse_to_id'] : null;
        $docDate       = (string) $doc['doc_date'];

        $lines = $this->docs->lines($supplierId, $id);
        if ($lines === []) {
            throw new StockException('invalid_document', 'Doklad nemá žádné řádky.');
        }

        $lockedOrders = $this->orderStates->lockForLines($supplierId, $lines);
        if ($docType === 'receipt') {
            $this->orderStates->assertReceiptAllowed($supplierId, $doc, $lines, $lockedOrders);
        }

        // 2a) Sklady musí být platné i v okamžiku post (mohly být deaktivovány po draftu).
        $this->requireActiveWarehouse($supplierId, $warehouseId);
        if ($docType === 'transfer') {
            if ($warehouseToId === null || $warehouseToId === $warehouseId) {
                throw new StockException('invalid_document', 'Převodka vyžaduje odlišný cílový sklad.');
            }
            $this->requireActiveWarehouse($supplierId, $warehouseToId);
        }

        // 2b) Zámek účetního období (jen double_entry; tax_evidence řeší doc-lock jinde).
        $this->guardPeriodOpen($supplierId, $docDate);

        // 2c) Otevřená inventura na dotčených skladech blokuje pohyby (A13).
        $this->warehouses->lockForStockOperation($supplierId, array_filter([$warehouseId, $warehouseToId]));
        $this->guardNoOpenStockTake($supplierId, $warehouseId, $warehouseToId);

        // 3) Zámky VŠECH dotčených stavů jedním lockLevels (převodka: oba sklady).
        $pairs = $this->buildPairs($docType, $warehouseId, $warehouseToId, $lines);
        $this->levels->lockLevels($supplierId, $pairs);
        $this->snapshots->invalidate($supplierId, $pairs, $docDate);

        // 4) Backdating (§3.2): starší doc_date než poslední pohyb karty → replay
        //    NEJDŘÍV (normalizace stavů + guard uzavřených období), pod drženými zámky.
        $backdated = [];
        foreach ($pairs as $pair) {
            $last = $this->docs->lastPostedDocDateForItem($supplierId, $pair['warehouse_id'], $pair['stock_item_id']);
            if ($last !== null && $docDate < $last) {
                $backdated[] = $pair;
            }
        }
        foreach ($backdated as $pair) {
            $this->recompute->replay($supplierId, $pair['warehouse_id'], $pair['stock_item_id'], $docDate);
        }

        // 5) Kontrola dostupnosti VŠECH výdejových noh najednou — PŘED výdejem
        //    čísla řady (B3), s úplným výčtem chybějících položek (A3).
        $this->assertAvailability($supplierId, $docType, $warehouseId, $lines, $shipmentAllowance);

        foreach ($lines as $line) {
            $this->tracking->postLine($supplierId, $docType, $warehouseId, $warehouseToId, $line);
        }

        // 6) Aplikace pohybů v deterministickém pořadí řádků (line_no, id).
        foreach ($lines as $line) {
            $this->applyLine($supplierId, $docType, $warehouseId, $warehouseToId, $docDate, $line);
        }

        // 7) Číslo řady až PO kontrolách (B3) + přepnutí do posted.
        $docNumber = $this->series->next($supplierId, self::SERIES[$docType], (int) substr($docDate, 0, 4));
        if (!$this->docs->markPosted($supplierId, $id, $docNumber, $userId)) {
            throw new StockException('not_draft', 'Doklad se nepodařilo zaúčtovat (souběžná změna stavu).', 409);
        }

        // 4b) Backdated doklad je teď součástí skladové knihy → finální replay
        //     zařadí jeho pohyby chronologicky, přecení pozdější výdeje (§3.2)
        //     a zvaliduje non-negative v každém historickém kroku. Selhání →
        //     rollback celé transakce vč. čísla řady.
        foreach ($backdated as $pair) {
            $this->recompute->replay($supplierId, $pair['warehouse_id'], $pair['stock_item_id'], $docDate);
        }

        // 7b) Stav objednávek dotčených tímhle pohybem — UVNITŘ téže transakce.
        //     Kdyby se přepočet dělal až po commitu, existovalo by okno, ve kterém
        //     zboží na skladě leží, ale objednávka pořád tvrdí, že je na cestě.
        $this->recomputeTouchedOrders($supplierId, $lines);
        $this->priceJobs->enqueue($supplierId, array_column($lines, 'stock_item_id'));

        // 8) Způsob B — žádný deníkový zápis.
        $posted = $this->docs->findWithLines($supplierId, $id);
        if ($posted === null) {
            throw new StockException('not_found', 'Skladový doklad nenalezen.', 404);
        }
        $posted['warnings'] = $this->collectPostWarnings($supplierId, $lines);
        return $posted;
    }

    // ── reverse (§4.4) ───────────────────────────────────────────────────────────

    /**
     * Storno = protidoklad v PŮVODNÍ ceně: opačný doc_type (převodka s prohozenými
     * sklady), stejné řádky/hodnoty, doc_date původního dokladu. Stavy přepočte
     * replay (storno-výdeje fixně, bez přecenění). Storno v uzavřeném období → 409;
     * storno příjemky s už vydaným zbožím → 409 insufficient_stock.
     *
     * @param array<string,mixed> $meta volitelně ['reason' => string]
     * @return array{original:array<string,mixed>, reversal:array<string,mixed>}
     */
    public function reverse(int $supplierId, int $id, array $meta, ?int $userId, bool $assemblyOperation = false): array
    {
        return $this->runInTransaction(function () use ($supplierId, $id, $meta, $userId, $assemblyOperation): array {
            $orig = $this->docs->lockForPost($supplierId, $id);
            if ($orig === null) {
                throw new StockException('not_found', 'Skladový doklad nenalezen.', 404);
            }
            $this->fulfillmentClaims->assertReversible($supplierId, $id);
            if (!$assemblyOperation) {
                $assembly = $this->db->pdo()->prepare('SELECT id FROM product_assemblies WHERE supplier_id = ? AND (issue_document_id = ? OR receipt_document_id = ?) LIMIT 1');
                $assembly->execute([$supplierId, $id, $id]);
                if ($assembly->fetchColumn() !== false) throw new StockException('assembly_reversal_required', 'Doklady kompletace se stornují společně z jejího detailu.', 409);
            }
            if ($orig['status'] === 'reversed') {
                throw new StockException('already_reversed', 'Doklad už byl stornován.');
            }
            if ($orig['status'] !== 'posted') {
                throw new StockException('not_posted', 'Stornovat lze jen zaúčtovaný doklad.');
            }

            $origLines = $this->docs->lines($supplierId, $id);
            if ($origLines === []) {
                throw new StockException('invalid_document', 'Doklad nemá žádné řádky.');
            }

            $origType    = (string) $orig['doc_type'];
            $this->orderStates->lockForLines($supplierId, $origLines);
            $counterType = self::REVERSE_TYPE[$origType]
                ?? throw new StockException('invalid_document', 'Neznámý typ skladového dokladu.');
            $docDate = (string) $orig['doc_date'];

            // Protidoklad: receipt↔issue na témž skladu; převodka s prohozenými sklady.
            if ($origType === 'transfer') {
                $counterWh   = (int) $orig['warehouse_to_id'];
                $counterWhTo = (int) $orig['warehouse_id'];
                if ($counterWh <= 0) {
                    throw new StockException('invalid_document', 'Převodka nemá cílový sklad.');
                }
            } else {
                $counterWh   = (int) $orig['warehouse_id'];
                $counterWhTo = null;
            }

            // Guardy shodné s post: období (storno v uzavřeném období → 409),
            // otevřená inventura na dotčených skladech.
            $this->guardPeriodOpen($supplierId, $docDate);
            $this->warehouses->lockForStockOperation($supplierId, array_filter([$counterWh, $counterWhTo]));
            $this->guardNoOpenStockTake($supplierId, $counterWh, $counterWhTo);

            // Zámky všech dotčených stavů (u převodky oba sklady dohromady).
            $pairs = $this->buildPairs($counterType, $counterWh, $counterWhTo, $origLines);
            $this->levels->lockLevels($supplierId, $pairs);
            $this->snapshots->invalidate($supplierId, $pairs, $docDate);

            // Kontrola dostupnosti výdejových noh protidokladu — storno příjemky
            // po výdejích nesmí vytvořit minus; PŘED výdejem čísla řady (B3).
            $this->assertAvailability($supplierId, $counterType, $counterWh, $origLines);

            $reason      = trim((string) ($meta['reason'] ?? ''));
            $description = 'Storno ' . (string) ($orig['doc_number'] ?? ('#' . $id))
                . ($reason !== '' ? ' — ' . $reason : '');

            $counterId = $this->docs->insertHeader($supplierId, [
                'doc_type'            => $counterType,
                'origin'              => (string) $orig['origin'],
                'warehouse_id'        => $counterWh,
                'warehouse_to_id'     => $counterWhTo,
                'doc_date'            => $docDate,
                'description'         => mb_substr($description, 0, 255),
                'partner_name'        => $orig['partner_name'],
                'invoice_id'          => $orig['invoice_id'],
                'purchase_invoice_id' => $orig['purchase_invoice_id'],
                // Protidoklad musí nést i vazbu na objednávku, jinak se storno
                // ztratí ze seznamu příjemek objednávky (§6, R-1).
                'purchase_order_id'   => $orig['purchase_order_id'],
                'stock_take_id'       => $orig['stock_take_id'],
                'created_by'          => $userId,
            ]);
            foreach ($origLines as $line) {
                // Stejné hodnoty (hodnotová neutralita §4.4) — NEpřeceňuje se.
                //
                // KRITICKÉ: `purchase_order_line_id` a `invoice_item_id` se MUSÍ
                // zkopírovat do protidokladu. Obojí „na cestě" i „rezervováno" je
                // ODVOZENÉ ze `stock_document_lines` vzorcem
                // SUM(receipt) − SUM(issue) přes tuhle vazbu, takže bez ní se
                // protidoklad nemá k čemu přiřadit a zboží se TIŠE nevrátí ani na
                // cestu, ani do rezervací — bez chyby, bez varování, jen jiné číslo
                // na kartě. Regresi hlídá InTransitTest (R-1 + zrcadlový případ
                // výdejky), který bez těchhle dvou řádků padá.
                $counterLineId = $this->docs->insertLine($supplierId, [
                    'document_id'              => $counterId,
                    'stock_item_id'            => (int) $line['stock_item_id'],
                    'qty'                      => (string) $line['qty'],
                    'unit_cost'                => (string) $line['unit_cost'],
                    'value_total'              => (string) $line['value_total'],
                    'extra_cost'               => (string) $line['extra_cost'],
                    'invoice_item_id'          => $line['invoice_item_id'],
                    'purchase_invoice_item_id' => $line['purchase_invoice_item_id'],
                    'purchase_order_line_id'   => $line['purchase_order_line_id'],
                    'source_description'       => $line['source_description'],
                    'source_qty'               => $line['source_qty'],
                    'line_no'                  => (int) $line['line_no'],
                    'note'                     => $line['note'],
                    'tracking_input_json'      => $line['tracking_input_json'] ?? null,
                ]);
                $this->tracking->reverseLine($supplierId, (int) $line['id'], $counterLineId);
            }

            $docNumber = $this->series->next($supplierId, self::SERIES[$counterType], (int) substr($docDate, 0, 4));
            if (!$this->docs->markPosted($supplierId, $counterId, $docNumber, $userId)) {
                throw new StockException('invalid_document', 'Protidoklad se nepodařilo zaúčtovat.', 500);
            }
            if (!$this->docs->markReversed($supplierId, $id, $counterId)) {
                throw new StockException('not_posted', 'Doklad se nepodařilo označit jako stornovaný (souběžná změna stavu).', 409);
            }

            // Mutace stavů VÝHRADNĚ replayem: markReversed už ukazuje na protidoklad
            // (is_reversal detekce), takže storno-výdeje jdou ve FIXNÍ původní ceně;
            // validace non-negative + uzavřených období v každém kroku. Selhání →
            // rollback celé transakce (vč. čísla řady i statusů).
            foreach ($pairs as $pair) {
                $this->recompute->replay($supplierId, $pair['warehouse_id'], $pair['stock_item_id'], $docDate);
            }

            // Storno příjemky vrací zboží „na cestu" → stav objednávky se musí
            // vrátit z received/partially_received zpátky, ve stejné transakci.
            $this->recomputeTouchedOrders($supplierId, $origLines);
            $this->priceJobs->enqueue($supplierId, array_column($origLines, 'stock_item_id'));

            $original = $this->docs->findWithLines($supplierId, $id);
            $reversal = $this->docs->findWithLines($supplierId, $counterId);
            if ($original === null || $reversal === null) {
                throw new StockException('not_found', 'Skladový doklad nenalezen.', 500);
            }
            return ['original' => $original, 'reversal' => $reversal];
        });
    }

    /**
     * Přepočte stav objednávek, na jejichž řádky doklad odkazuje.
     *
     * `partially_received` / `received` nesmí nastavit nikdo jiný než
     * {@see PurchaseOrderStateService} — proto se sem jen deleguje. Volá se
     * z `post()` i `reverse()` a VŽDY uvnitř transakce, která hýbe skladem.
     *
     * @param list<array<string,mixed>> $lines
     */
    private function recomputeTouchedOrders(int $supplierId, array $lines): void
    {
        $this->orderStates->recomputeForLines($supplierId, $lines);
    }

    /**
     * Dvojice (sklad, karta) všech noh dokladu — převodka přidává cílový sklad
     * KAŽDÉHO řádku (zámky obou skladů v jednom lockLevels, lock-order B3).
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array{warehouse_id:int, stock_item_id:int}>
     */
    private function buildPairs(string $docType, int $warehouseId, ?int $warehouseToId, array $lines): array
    {
        $pairs = [];
        foreach ($lines as $line) {
            $itemId = (int) $line['stock_item_id'];
            $pairs[$warehouseId . ':' . $itemId] = [
                'warehouse_id'  => $warehouseId,
                'stock_item_id' => $itemId,
            ];
            if ($docType === 'transfer' && $warehouseToId !== null) {
                $pairs[$warehouseToId . ':' . $itemId] = [
                    'warehouse_id'  => $warehouseToId,
                    'stock_item_id' => $itemId,
                ];
            }
        }
        return array_values($pairs);
    }

    /**
     * Agregovaná kontrola dostupnosti výdejových noh per (sklad, karta) —
     * VŠECHNY nedostatky najednou v jedné 409 (A3). Volat pod zámkem levels
     * (a po případném normalizačním replayi).
     *
     * @param list<array<string,mixed>> $lines
     */
    private function assertAvailability(int $supplierId, string $docType, int $sourceWarehouseId, array $lines, array $shipmentAllowance = []): void
    {
        if ($docType === 'receipt') {
            return;
        }

        /** @var array<int,array{qtyT:int, sku:string, name:string}> $need */
        $need = [];
        foreach ($lines as $line) {
            $itemId = (int) $line['stock_item_id'];
            $qtyT   = StockValuation::qtyToT((string) $line['qty']);
            if (!isset($need[$itemId])) {
                $need[$itemId] = [
                    'qtyT' => 0,
                    'sku'  => (string) ($line['sku'] ?? ''),
                    'name' => (string) ($line['name'] ?? ''),
                ];
            }
            $need[$itemId]['qtyT'] += $qtyT;
        }

        $pairs = array_map(static fn (int $itemId): array => ['warehouse_id' => $sourceWarehouseId, 'stock_item_id' => $itemId], array_keys($need));
        $committed = $this->commitments->committedForPairs($supplierId, $pairs);
        $shortages = [];
        foreach ($need as $itemId => $n) {
            // Locking read — čerstvý stav, ne stale RR snapshot (CRITICAL 1).
            $cur = $this->levels->currentForUpdate($supplierId, $sourceWarehouseId, $itemId);
            $key = $sourceWarehouseId . ':' . $itemId;
            $availableT = $cur['qtyT'] - ($committed[$key] ?? 0) + ($shipmentAllowance[$key] ?? 0);
            if ($n['qtyT'] > $availableT) {
                $shortages[] = [
                    'stock_item_id' => $itemId,
                    'sku'           => $n['sku'],
                    'name'          => $n['name'],
                    'requested'     => StockValuation::tToDecimal($n['qtyT']),
                    'available'     => StockValuation::tToDecimal($availableT),
                ];
            }
        }
        if ($shortages !== []) {
            throw new StockException('insufficient_stock', 'Nedostatek zásob pro výdej.', 409, $shortages);
        }
    }

    /**
     * Aplikuje jeden řádek na stavy a přepíše jeho vypočtené ocenění.
     * Převodka = výdej ze zdroje (klouzavý průměr) + příjem na cíl ve STEJNÉ
     * hodnotě (hodnotově neutrální přesun).
     *
     * @param array<string,mixed> $line
     */
    private function applyLine(int $supplierId, string $docType, int $warehouseId, ?int $warehouseToId, string $docDate, array $line): void
    {
        $itemId = (int) $line['stock_item_id'];
        $qtyT   = StockValuation::qtyToT((string) $line['qty']);
        if ($qtyT <= 0) {
            throw new StockException('invalid_document', 'Množství řádku musí být větší než 0.', 422, [[
                'line_id' => (int) $line['id'],
                'qty'     => (string) $line['qty'],
            ]]);
        }
        $context = ['sku' => (string) ($line['sku'] ?? ''), 'name' => (string) ($line['name'] ?? '')];

        if ($docType === 'receipt') {
            // Hodnota řádku = round(qty × zadaná PC, 2) + rozpuštěné extra náklady (B7).
            $lineValueC = StockValuation::valueToC(round((float) $line['qty'] * (float) $line['unit_cost'], 2))
                + StockValuation::valueToC((string) $line['extra_cost']);
            $res = $this->levels->applyReceipt($supplierId, $warehouseId, $itemId, $qtyT, $lineValueC);
            // unit_cost zůstává ZADANÁ pořizovací cena (schéma); value_total vč. extra.
            $this->docs->updateLineValuation(
                $supplierId,
                (int) $line['id'],
                (string) $line['unit_cost'],
                $res['value_total'],
                (string) $line['extra_cost'],
                $docDate,
            );
            return;
        }

        if ($docType === 'issue') {
            $res = $this->levels->applyIssue($supplierId, $warehouseId, $itemId, $qtyT, $context);
            $this->docs->updateLineValuation(
                $supplierId,
                (int) $line['id'],
                $res['unit_cost'],
                $res['value_total'],
                (string) $line['extra_cost'],
                $docDate,
            );
            return;
        }

        // transfer: výdej ze zdroje, příjem na cíl v hodnotě výdeje (neutralita).
        if ($warehouseToId === null) {
            throw new StockException('invalid_document', 'Převodka vyžaduje cílový sklad.');
        }
        $issueRes = $this->levels->applyIssue($supplierId, $warehouseId, $itemId, $qtyT, $context);
        $this->levels->applyReceipt($supplierId, $warehouseToId, $itemId, $qtyT, $issueRes['lineValueC']);
        $this->docs->updateLineValuation(
            $supplierId,
            (int) $line['id'],
            $issueRes['unit_cost'],
            $issueRes['value_total'],
            (string) $line['extra_cost'],
            $docDate,
        );
    }

    /**
     * B10: neaktivní karta post existujícího draftu neblokuje — jen warning.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<string>
     */
    private function collectPostWarnings(int $supplierId, array $lines): array
    {
        $itemIds = array_values(array_unique(array_map(
            static fn (array $l): int => (int) $l['stock_item_id'],
            $lines,
        )));
        $inactive = [];
        foreach ($this->itemsMeta($supplierId, $itemIds) as $meta) {
            if (!$meta['is_active']) {
                $inactive[] = $meta['sku'];
            }
        }
        return $inactive !== []
            ? ['stock.warning.inactive_item:' . implode(',', $inactive)]
            : [];
    }

    // ── interní: guardy ─────────────────────────────────────────────────────────

    /**
     * Zámek účetního období (B9, jen double_entry): doc_date v období
     * closing/closed → 409. tax_evidence nemá období (doc-lock řeší
     * DocumentLockService mimo tuto službu).
     */
    private function guardPeriodOpen(int $supplierId, string $docDate): void
    {
        if (!$this->isDoubleEntry($supplierId)) {
            return;
        }
        $period = $this->periods->findForDate($supplierId, $docDate);
        if ($period !== null && in_array((string) $period['status'], ['closing', 'closed'], true)) {
            throw new StockException(
                'stock_recompute_locked_period',
                'Doklad spadá do uzavřeného účetního období — proveď korekci dokladem k dnešnímu datu.',
                409,
                ['doc_date' => $docDate, 'period_status' => (string) $period['status']],
            );
        }
    }

    /** Otevřená inventura (status=counting) na dotčeném skladu blokuje pohyby (A13). */
    private function guardNoOpenStockTake(int $supplierId, int $warehouseId, ?int $warehouseToId): void
    {
        foreach (array_unique(array_filter([$warehouseId, $warehouseToId])) as $wh) {
            if ($this->takes->hasOpenCounting($supplierId, (int) $wh)) {
                throw new StockException(
                    'stock_take_in_progress',
                    'Na skladu probíhá inventura — dokončete ji před zaúčtováním pohybu.',
                    409,
                    ['warehouse_id' => (int) $wh],
                );
            }
        }
    }

    private function isDoubleEntry(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $mode = $stmt->fetchColumn();
        return $mode !== false && (string) $mode === 'double_entry';
    }

    /** @return array<string,mixed> */
    private function requireActiveWarehouse(int $supplierId, int $warehouseId): array
    {
        $warehouse = $this->warehouses->find($supplierId, $warehouseId);
        if ($warehouse === null) {
            throw new StockException('invalid_document', 'Sklad nenalezen.', 422, ['warehouse_id' => $warehouseId]);
        }
        if (empty($warehouse['is_active'])) {
            throw new StockException('invalid_document', 'Sklad je neaktivní.', 422, ['warehouse_id' => $warehouseId]);
        }
        return $warehouse;
    }

    // ── interní: validace + normalizace vstupu ──────────────────────────────────

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>, 1:list<array<string,mixed>>} [hlavička, řádky]
     */
    private function validateBody(int $supplierId, array $body, bool $blockInactiveItems): array
    {
        $docType = (string) ($body['doc_type'] ?? '');
        if (!in_array($docType, self::DOC_TYPES, true)) {
            throw new StockException('invalid_document', 'Neplatný typ dokladu (receipt|issue|transfer).');
        }
        $origin = (string) ($body['origin'] ?? 'manual');
        if (!in_array($origin, self::ORIGINS, true)) {
            throw new StockException('invalid_document', 'Neplatný původ dokladu.');
        }
        $docDate = trim((string) ($body['doc_date'] ?? ''));
        if (!self::isDate($docDate)) {
            throw new StockException('invalid_document', 'Datum dokladu je povinné (YYYY-MM-DD).');
        }
        $description = trim((string) ($body['description'] ?? ''));
        if ($description === '') {
            throw new StockException('invalid_document', 'Popis (obsah účetního případu) je povinný.');
        }

        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        $this->requireActiveWarehouse($supplierId, $warehouseId);

        $warehouseToId = null;
        if ($docType === 'transfer') {
            $warehouseToId = (int) ($body['warehouse_to_id'] ?? 0);
            if ($warehouseToId <= 0) {
                throw new StockException('invalid_document', 'Převodka vyžaduje cílový sklad.');
            }
            if ($warehouseToId === $warehouseId) {
                throw new StockException('invalid_document', 'Cílový sklad převodky musí být odlišný od zdrojového.');
            }
            $this->requireActiveWarehouse($supplierId, $warehouseToId);
        }

        $rawLines = is_array($body['lines'] ?? null) ? $body['lines'] : [];
        if ($rawLines === []) {
            throw new StockException('invalid_document', 'Doklad musí mít aspoň jeden řádek.');
        }

        $itemIds = [];
        foreach ($rawLines as $rl) {
            if (is_array($rl)) {
                $itemIds[] = (int) ($rl['stock_item_id'] ?? 0);
            }
        }
        $meta = $this->itemsMeta($supplierId, array_values(array_unique($itemIds)));

        $lines    = [];
        $lineNo   = 1;
        $missing  = [];
        $inactive = [];
        foreach ($rawLines as $rl) {
            if (!is_array($rl)) {
                continue;
            }
            $itemId = (int) ($rl['stock_item_id'] ?? 0);
            if ($itemId <= 0 || !isset($meta[$itemId])) {
                $missing[] = $itemId;
                continue;
            }
            if ($blockInactiveItems && !$meta[$itemId]['is_active']) {
                // B10: neaktivní karta blokuje NOVÉ řádky draftu.
                $inactive[] = ['stock_item_id' => $itemId, 'sku' => $meta[$itemId]['sku'], 'name' => $meta[$itemId]['name']];
                continue;
            }

            $qtyT = StockValuation::qtyToT((string) ($rl['qty'] ?? '0'));
            if ($qtyT <= 0) {
                throw new StockException('invalid_document', 'Množství řádku musí být větší než 0.', 422, [[
                    'stock_item_id' => $itemId,
                    'qty'           => (string) ($rl['qty'] ?? '0'),
                ]]);
            }

            // Výdej/převodka: ocenění se počítá při post() — draft drží 0 (A6).
            $unitCostMicro = 0;
            $extraC        = 0;
            if ($docType === 'receipt') {
                $unitCost = (float) ($rl['unit_cost'] ?? 0);
                if ($unitCost < 0) {
                    throw new StockException('invalid_document', 'Pořizovací cena nesmí být záporná.', 422, [[
                        'stock_item_id' => $itemId,
                    ]]);
                }
                $unitCostMicro = (int) round($unitCost * StockValuation::MICRO);
                $extraC        = StockValuation::valueToC((string) ($rl['extra_cost'] ?? '0'));
                if ($extraC < 0) {
                    throw new StockException('invalid_document', 'Vedlejší náklady nesmí být záporné.', 422, [[
                        'stock_item_id' => $itemId,
                    ]]);
                }
            }
            $qty      = StockValuation::tToDecimal($qtyT);
            $unitCost = StockValuation::microToDecimal($unitCostMicro);
            // Informativní hodnota draftu příjemky (post() ji přepočítá závazně).
            $valueC = $docType === 'receipt'
                ? StockValuation::valueToC(round((float) $qty * (float) $unitCost, 2)) + $extraC
                : 0;

            $lines[] = [
                'stock_item_id'            => $itemId,
                'qty'                      => $qty,
                'unit_cost'                => $unitCost,
                'value_total'              => StockValuation::cToDecimal($valueC),
                'extra_cost'               => StockValuation::cToDecimal($extraC),
                'invoice_item_id'          => isset($rl['invoice_item_id']) && (int) $rl['invoice_item_id'] > 0 ? (int) $rl['invoice_item_id'] : null,
                'purchase_invoice_item_id' => isset($rl['purchase_invoice_item_id']) && (int) $rl['purchase_invoice_item_id'] > 0 ? (int) $rl['purchase_invoice_item_id'] : null,
                'purchase_order_line_id'   => isset($rl['purchase_order_line_id']) && (int) $rl['purchase_order_line_id'] > 0 ? (int) $rl['purchase_order_line_id'] : null,
                'source_description'       => self::nullableString($rl['source_description'] ?? null),
                'source_qty'               => isset($rl['source_qty']) && $rl['source_qty'] !== null && $rl['source_qty'] !== ''
                    ? StockValuation::tToDecimal(StockValuation::qtyToT((string) $rl['source_qty'])) : null,
                'line_no'                  => $lineNo++,
                'note'                     => self::nullableString($rl['note'] ?? null),
                'tracking_input_json'      => $this->tracking->normalizeDraftInput(
                    $supplierId,
                    $itemId,
                    is_array($rl['tracking_allocations'] ?? null) ? $rl['tracking_allocations'] : [],
                ),
            ];
        }

        if ($missing !== []) {
            throw new StockException('invalid_document', 'Skladová karta nenalezena.', 422, ['stock_item_ids' => $missing]);
        }
        if ($inactive !== []) {
            throw new StockException('invalid_document', 'Neaktivní skladovou kartu nelze přidat na doklad.', 422, $inactive);
        }
        if ($lines === []) {
            throw new StockException('invalid_document', 'Doklad musí mít aspoň jeden řádek.');
        }

        $header = [
            'doc_type'            => $docType,
            'origin'              => $origin,
            'warehouse_id'        => $warehouseId,
            'warehouse_to_id'     => $warehouseToId,
            'doc_date'            => $docDate,
            'description'         => mb_substr($description, 0, 255),
            'partner_name'        => self::nullableString($body['partner_name'] ?? null),
            'invoice_id'          => isset($body['invoice_id']) && (int) $body['invoice_id'] > 0 ? (int) $body['invoice_id'] : null,
            'purchase_invoice_id' => isset($body['purchase_invoice_id']) && (int) $body['purchase_invoice_id'] > 0 ? (int) $body['purchase_invoice_id'] : null,
            'purchase_order_id'   => isset($body['purchase_order_id']) && (int) $body['purchase_order_id'] > 0 ? (int) $body['purchase_order_id'] : null,
            'stock_take_id'       => isset($body['stock_take_id']) && (int) $body['stock_take_id'] > 0 ? (int) $body['stock_take_id'] : null,
            'status'              => 'draft',
            'allow_over_delivery' => !empty($body['allow_over_delivery']),
        ];

        $this->assertReferencesOwned($supplierId, $header, $lines);

        return [$header, $lines];
    }

    /**
     * Vazby dokladu do jiných agend (FV, PF, inventura) musí patřit témuž tenantovi.
     *
     * `warehouse_id` a `stock_item_id` mají vlastní kontrolu výš
     * (requireActiveWarehouse / itemsMeta); tyhle sloupce ji do opravy R2 neměly
     * a zapisovaly se rovnou z těla requestu (security report 2026-08, S102).
     * Únik z toho nebyl — všechny čtecí cesty skladu nesou `supplier_id` —, ale
     * trvalý zápis přes hranici firmy ano, a ten se stane únikem první den, kdy
     * na některý z těch sloupců někdo napíše JOIN.
     *
     * @param array<string,mixed>       $header
     * @param list<array<string,mixed>> $lines
     */
    private function assertReferencesOwned(int $supplierId, array $header, array $lines): void
    {
        $refs = [];
        foreach (array_keys(StockReferenceGuard::DIRECT) as $column) {
            $refs[$column] = $header[$column] ?? null;
        }
        foreach (array_keys(StockReferenceGuard::VIA_PARENT) as $column) {
            $refs[$column] = array_map(static fn (array $line): mixed => $line[$column] ?? null, $lines);
        }

        $bad = $this->references->violations($supplierId, $refs);
        if ($bad !== []) {
            throw new StockException(
                'invalid_reference',
                'Doklad odkazuje na záznam mimo vaši firmu.',
                422,
                $bad,
            );
        }
    }

    /**
     * Dávkové meta karet (validace + obohacení chyb) — přímý SQL místo N×find
     * (StockItemRepository je paralelní task; fallback dle zadání epicu).
     *
     * @param list<int> $itemIds
     * @return array<int,array{sku:string, name:string, is_active:bool, item_type:string}>
     */
    private function itemsMeta(int $supplierId, array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, sku, name, item_type, is_active, supplier_id
               FROM stock_items WHERE supplier_id = ? AND id IN ($place)"
        );
        $stmt->execute(array_merge([$supplierId], $ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = [
                'sku'       => (string) $r['sku'],
                'name'      => (string) $r['name'],
                'is_active' => (bool) $r['is_active'],
                'item_type' => (string) $r['item_type'],
            ];
        }
        return $out;
    }

    // ── interní: transakce ──────────────────────────────────────────────────────

    /**
     * Nested-tx vzor (CashDocumentService) + jeden retry celé transakce při
     * deadlocku 1213 / lock-wait timeoutu (§4.2) — jen když transakci vlastníme
     * (vnořené volání retry nechává na vlastníkovi transakce).
     *
     * Izolace READ COMMITTED (jen když transakci vlastníme): pod výchozím
     * REPEATABLE READ by čtení po lockLevels vracela stale snapshot a obešla by
     * celý zámkový návrh B3 (review CRITICAL 1). SET … bez SESSION platí pro
     * NÁSLEDUJÍCÍ transakci. POZN.: vnořený volající (issue hook FV) MUSÍ svou
     * obalující transakci spustit rovněž v READ COMMITTED.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function runInTransaction(callable $fn)
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }

        for ($attempt = 0; ; $attempt++) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            try {
                $result = $fn();
                $pdo->commit();
                return $result;
            } catch (\PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $mysqlCode = (int) ($e->errorInfo[1] ?? 0);
                if ($attempt === 0 && ($mysqlCode === 1213 || $mysqlCode === 1205)) {
                    continue; // jeden retry celé transakce (B3)
                }
                throw $e;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
    }

    // ── pomocné ─────────────────────────────────────────────────────────────────

    private static function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
