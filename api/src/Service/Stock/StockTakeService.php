<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Repository\StockLevelRepository;
use MyInvoice\Repository\StockDocumentRepository;
use MyInvoice\Repository\StockTakeRepository;
use MyInvoice\Repository\WarehouseRepository;

/**
 * Inventury (Epic SKLAD, §29–30 ZoÚ) — snapshot očekávaných stavů při přechodu
 * do počítání, zadání skutečností, uzavření → rozdílové doklady (origin='inventory',
 * jen evidence — způsob B rozdíly zaúčtuje až uzávěrka, §3.4).
 *
 * Souběh: otevřená inventura (status=counting) blokuje POST pohybů skladu (A13) —
 * hlídá to {@see StockDocumentService::guardNoOpenStockTake()} přes
 * {@see StockTakeRepository::hasOpenCounting()}. Aby rozdílové doklady vzniklé
 * PŘI uzavření (close()) neuvázly na vlastním guardu, přepneme status na 'closed'
 * JEŠTĚ PŘED jejich vytvořením — v jedné transakci, takže při jakémkoliv selhání
 * (např. souběžná změna) se vrátí i status.
 */
final class StockTakeService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockTakeRepository $takes,
        private readonly StockLevelRepository $levels,
        private readonly StockItemRepository $items,
        private readonly StockDocumentService $documents,
        private readonly WarehouseRepository $warehouses,
        private readonly StockReportService $reports,
        private readonly StockDocumentRepository $documentRepository,
        private readonly StockValuationJobService $valuationJobs,
        private readonly \MyInvoice\Service\Eshop\CatalogJobService $jobs,
    ) {}

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $body, ?int $userId): array
    {
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        $warehouse = $this->warehouses->find($supplierId, $warehouseId);
        if ($warehouse === null) {
            throw new StockException('invalid_document', 'Sklad nenalezen.', 422, ['warehouse_id' => $warehouseId]);
        }
        if (empty($warehouse['is_active'])) {
            throw new StockException('invalid_document', 'Sklad je neaktivní.', 422, ['warehouse_id' => $warehouseId]);
        }

        $takeDate = trim((string) ($body['take_date'] ?? ''));
        if (!self::isDate($takeDate)) {
            throw new StockException('invalid_document', 'Datum inventury je povinné (YYYY-MM-DD).');
        }

        $countingMethod = trim((string) ($body['counting_method'] ?? ''));
        $responsibleCount = trim((string) ($body['responsible_count_name'] ?? ''));
        $responsibleInventory = trim((string) ($body['responsible_inventory_name'] ?? ''));
        if (!in_array($countingMethod, ['physical_count', 'measurement', 'weighing', 'other'], true)
            || $responsibleCount === '' || $responsibleInventory === '') {
            throw new StockException(
                'invalid_document',
                'Vyplňte způsob zjištění stavu a obě odpovědné osoby inventury.',
                422,
            );
        }

        // Jen jedna otevřená (draft/counting) inventura na sklad zaráz.
        $open = $this->openTakeForWarehouse($supplierId, $warehouseId);
        if ($open !== null) {
            throw new StockException(
                'stock_take_in_progress',
                'Na skladu už je rozpracovaná inventura.',
                409,
                ['warehouse_id' => $warehouseId, 'stock_take_id' => (int) $open['id']],
            );
        }

        // Unikátní constraint uq_st_supplier_wh_date je na (sklad, datum) bez ohledu na stav
        // — i uzavřená inventura blokuje další ke stejnému dni. Pre-check, ať uživatel dostane
        // hlášku (a odkaz na existující) místo syrového 500 z PDO.
        $existing = $this->takeForWarehouseAndDate($supplierId, $warehouseId, $takeDate);
        if ($existing !== null) {
            throw new StockException(
                'stock_take_exists',
                'Pro tento sklad a datum už inventura existuje.',
                409,
                ['warehouse_id' => $warehouseId, 'take_date' => $takeDate, 'stock_take_id' => (int) $existing['id']],
            );
        }

        try {
            return $this->runInTransaction(function () use (
                $supplierId,
                $warehouseId,
                $takeDate,
                $countingMethod,
                $responsibleCount,
                $responsibleInventory,
                $body,
                $userId,
            ): array {
                $id = $this->takes->insert($supplierId, [
                    'warehouse_id' => $warehouseId,
                    'take_date'    => $takeDate,
                    'status'       => 'draft',
                    'note'         => self::nullableString($body['note'] ?? null),
                    'counting_method' => $countingMethod,
                    'responsible_count_name' => $responsibleCount,
                    'responsible_inventory_name' => $responsibleInventory,
                    'created_by'   => $userId,
                ]);
                return $this->get($supplierId, $id);
            });
        } catch (\PDOException $e) {
            // Backstop pro race (dvě inventury naráz) — SQLSTATE 23000 = integrity constraint.
            if ((string) $e->getCode() === '23000') {
                throw new StockException(
                    'stock_take_exists',
                    'Pro tento sklad a datum už inventura existuje.',
                    409,
                    ['warehouse_id' => $warehouseId, 'take_date' => $takeDate],
                );
            }
            throw $e;
        }
    }

    /** @return array<string,mixed>|null inventura skladu k danému dni (jakýkoli stav). */
    private function takeForWarehouseAndDate(int $supplierId, int $warehouseId, string $takeDate): ?array
    {
        foreach ($this->takes->list($supplierId, ['warehouse_id' => $warehouseId]) as $t) {
            if (substr((string) $t['take_date'], 0, 10) === $takeDate) {
                return $t;
            }
        }
        return null;
    }

    /**
     * draft → counting: snapshot VŠECH aktivních karet s aktuálním stavem na daném
     * skladu (expected_qty/expected_value) do stock_take_lines (replaceLines).
     * Karty bez pohybu (bez řádku stock_levels) dostanou expected 0/0.
     *
     * @return array<string,mixed>
     */
    public function start(int $supplierId, int $id, ?int $userId): array
    {
        return $this->runInTransaction(function () use ($supplierId, $id): array {
            $take = $this->takes->find($supplierId, $id);
            if ($take === null) {
                throw new StockException('not_found', 'Inventura nenalezena.', 404);
            }
            if ($take['status'] !== 'draft') {
                throw new StockException('invalid_document', 'Do počítání lze přepnout jen rozpracovanou (draft) inventuru.', 422);
            }
            $warehouseId = (int) $take['warehouse_id'];
            $this->warehouses->lockForStockOperation($supplierId, [$warehouseId]);

            $activeItems = $this->items->list($supplierId, ['active' => true]);
            $valuation = $this->reports->valuation($supplierId, (string) $take['take_date'], ['warehouse_id' => $warehouseId]);
            $byItem = [];
            foreach ($valuation['items'] as $item) {
                $byItem[(int) $item['stock_item_id']] = $item;
            }
            $lastCosts = $this->documentRepository->lastKnownUnitCosts(
                $supplierId,
                $warehouseId,
                (string) $take['take_date'],
            );

            $itemIds = [];
            foreach ($activeItems as $item) {
                $itemIds[(int) $item['id']] = true;
            }
            foreach ($byItem as $itemId => $item) {
                if (StockValuation::qtyToT((string) $item['qty']) !== 0) {
                    $itemIds[$itemId] = true;
                }
            }

            $lines = [];
            foreach (array_keys($itemIds) as $itemId) {
                $lvl = $byItem[$itemId] ?? null;
                $expectedQty = (string) ($lvl['qty'] ?? '0.000');
                $expectedValue = (string) ($lvl['value_total'] ?? '0.00');
                $qty = (float) $expectedQty;
                $suggestedCost = $qty > 0.0
                    ? number_format((float) $expectedValue / $qty, 6, '.', '')
                    : ($lastCosts[$itemId] ?? null);
                $lines[] = [
                    'stock_item_id'  => $itemId,
                    'expected_qty'   => $expectedQty,
                    'expected_value' => $expectedValue,
                    'counted_qty'    => null,
                    'surplus_unit_cost' => $suggestedCost,
                ];
            }

            $this->takes->replaceLines($supplierId, $id, $lines);
            if (!$this->takes->updateStatus($supplierId, $id, 'counting', [
                'started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ])) {
                throw new StockException('invalid_document', 'Inventuru se nepodařilo spustit (souběžná změna stavu).', 409);
            }

            return $this->get($supplierId, $id);
        });
    }

    public function prepare(int $supplierId, int $id, ?int $userId): array
    {
        return $this->runInTransaction(function () use ($supplierId, $id): array {
            $take = $this->get($supplierId, $id);
            $this->warehouses->lockForStockOperation($supplierId, [(int) $take['warehouse_id']]);
            $take = $this->takes->find($supplierId, $id);
            if ($take['status'] === 'preparing') {
                return $this->get($supplierId, $id);
            }
            if ($take['status'] !== 'draft') {
                throw new StockException('invalid_document', 'Připravit lze jen rozpracovanou inventuru.', 409);
            }
            $job = $this->valuationJobs->enqueue($supplierId, $take['take_date'], [
                'warehouse_id' => (int) $take['warehouse_id'], 'stock_take_id' => $id,
            ]);
            $this->takes->updateStatus($supplierId, $id, 'preparing', ['preparation_job_id' => $job['id']]);
            return $this->get($supplierId, $id);
        });
    }

    public function cancelPreparation(int $supplierId, int $id): array
    {
        return $this->runInTransaction(function () use ($supplierId, $id): array {
            $take = $this->get($supplierId, $id);
            if ($take['status'] !== 'preparing') {
                throw new StockException('invalid_document', 'Inventura není ve fázi přípravy.', 409);
            }
            $this->jobs->cancel($supplierId, (int) $take['preparation_job_id']);
            $this->warehouses->lockForStockOperation($supplierId, [(int) $take['warehouse_id']]);
            $take = $this->takes->find($supplierId, $id);
            if ($take['status'] !== 'preparing') {
                throw new StockException('stock_take_preparation_conflict', 'Příprava inventury již skončila.', 409);
            }
            $this->takes->replaceLines($supplierId, $id, []);
            $this->takes->updateStatus($supplierId, $id, 'draft', ['preparation_job_id' => null]);
            return $this->get($supplierId, $id);
        });
    }

    public function retryPreparation(int $supplierId, int $id): array
    {
        $this->cancelPreparation($supplierId, $id);
        return $this->prepare($supplierId, $id, null);
    }

    /**
     * Zadání skutečností — jen ve fázi counting. `body.lines` = list
     * {id (stock_take_lines.id), counted_qty (string|null)}.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function updateCounts(int $supplierId, int $id, array $body, ?int $userId): array
    {
        $take = $this->takes->find($supplierId, $id);
        if ($take === null) {
            throw new StockException('not_found', 'Inventura nenalezena.', 404);
        }
        if ($take['status'] !== 'counting') {
            throw new StockException('invalid_document', 'Počty lze zadávat jen u inventury ve fázi počítání.', 422);
        }

        $rawLines = is_array($body['lines'] ?? null) ? $body['lines'] : [];
        return $this->runInTransaction(function () use ($supplierId, $id, $rawLines): array {
            foreach ($rawLines as $rl) {
                if (!is_array($rl)) {
                    continue;
                }
                $lineId = (int) ($rl['id'] ?? 0);
                if ($lineId <= 0) {
                    continue;
                }
                $raw = $rl['counted_qty'] ?? null;
                $counted = ($raw === null || $raw === '')
                    ? null
                    : StockValuation::tToDecimal(StockValuation::qtyToT((string) $raw));
                if (!array_key_exists('surplus_unit_cost', $rl)) {
                    $this->takes->updateCounted($supplierId, $id, $lineId, $counted);
                    continue;
                }
                $rawCost = $rl['surplus_unit_cost'];
                $surplusCost = ($rawCost === null || $rawCost === '')
                    ? null
                    : number_format((float) $rawCost, 6, '.', '');
                if ($surplusCost !== null && (float) $surplusCost < 0.0) {
                    throw new StockException('invalid_document', 'Cena přebytku nesmí být záporná.', 422);
                }
                $this->takes->updateCountedAndSurplusCost($supplierId, $id, $lineId, $counted, $surplusCost);
            }
            return $this->get($supplierId, $id);
        });
    }

    /**
     * counting → closed: diffy (counted−expected) na počítaných řádcích (NULL =
     * nepočítáno = beze změny) → rozdílové doklady origin='inventory' (jeden
     * souhrnný receipt za přebytky, jeden issue za manka), rovnou postnuté.
     * Status na 'closed' se nastaví PŘED vytvořením dokladů (viz class docblock).
     *
     * @return array<string,mixed>
     */
    public function close(int $supplierId, int $id, ?int $userId): array
    {
        return $this->runInTransaction(function () use ($supplierId, $id, $userId): array {
            $take = $this->takes->find($supplierId, $id);
            if ($take === null) {
                throw new StockException('not_found', 'Inventura nenalezena.', 404);
            }
            if ($take['status'] !== 'counting') {
                throw new StockException('invalid_document', 'Uzavřít lze jen inventuru ve fázi počítání.', 422);
            }
            $warehouseId = (int) $take['warehouse_id'];
            $takeDate    = (string) $take['take_date'];

            $lines = $this->takes->lines($supplierId, $id);
            $surplusLines  = [];
            $shortageLines = [];
            foreach ($lines as $l) {
                if ($l['counted_qty'] === null) {
                    continue; // nepočítáno → beze změny
                }
                $expectedT = StockValuation::qtyToT((string) $l['expected_qty']);
                $countedT  = StockValuation::qtyToT((string) $l['counted_qty']);
                $diffT     = $countedT - $expectedT;
                if ($diffT === 0) {
                    continue;
                }
                $itemId = (int) $l['stock_item_id'];
                if ($diffT > 0) {
                    $surplusUnitCost = (float) ($l['surplus_unit_cost'] ?? 0);
                    if ($surplusUnitCost <= 0.0) {
                        throw new StockException(
                            'missing_surplus_unit_cost',
                            'Inventurní přebytek musí mít zadanou reprodukční pořizovací cenu.',
                            422,
                            ['stock_item_id' => $itemId],
                        );
                    }
                    $surplusLines[] = [
                        'stock_item_id' => $itemId,
                        'qty'           => StockValuation::tToDecimal($diffT),
                        'unit_cost'     => number_format($surplusUnitCost, 6, '.', ''),
                    ];
                } else {
                    $shortageLines[] = [
                        'stock_item_id' => $itemId,
                        'qty'           => StockValuation::tToDecimal(-$diffT),
                    ];
                }
            }

            // Status na 'closed' HNED — jinak by guardNoOpenStockTake zablokoval
            // post() vlastních rozdílových dokladů (status=counting stále platí).
            if (!$this->takes->updateStatus($supplierId, $id, 'closed', [
                'closed_by' => $userId,
                'closed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ])) {
                throw new StockException('invalid_document', 'Inventuru se nepodařilo uzavřít (souběžná změna stavu).', 409);
            }

            $receiptDoc = null;
            $issueDoc   = null;

            if ($surplusLines !== []) {
                $draft = $this->documents->create($supplierId, [
                    'doc_type'      => 'receipt',
                    'origin'        => 'inventory',
                    'warehouse_id'  => $warehouseId,
                    'doc_date'      => $takeDate,
                    'description'   => 'Inventurní přebytek — inventura #' . $id,
                    'stock_take_id' => $id,
                    'lines'         => $surplusLines,
                ], $userId);
                $receiptDoc = $this->documents->post($supplierId, (int) $draft['id'], $userId);
            }
            if ($shortageLines !== []) {
                $draft = $this->documents->create($supplierId, [
                    'doc_type'      => 'issue',
                    'origin'        => 'inventory',
                    'warehouse_id'  => $warehouseId,
                    'doc_date'      => $takeDate,
                    'description'   => 'Inventurní manko — inventura #' . $id,
                    'stock_take_id' => $id,
                    'lines'         => $shortageLines,
                ], $userId);
                $issueDoc = $this->documents->post($supplierId, (int) $draft['id'], $userId);
            }

            if ($receiptDoc !== null || $issueDoc !== null) {
                $this->takes->updateStatus($supplierId, $id, 'closed', [
                    'receipt_document_id' => $receiptDoc['id'] ?? null,
                    'issue_document_id'   => $issueDoc['id'] ?? null,
                ]);
            }

            $result = $this->get($supplierId, $id);
            $result['receipt_document'] = $receiptDoc;
            $result['issue_document']   = $issueDoc;
            return $result;
        });
    }

    /** @return array<string,mixed> inventura + řádky (vč. diff_qty) */
    public function get(int $supplierId, int $id): array
    {
        $take = $this->takes->find($supplierId, $id);
        if ($take === null) {
            throw new StockException('not_found', 'Inventura nenalezena.', 404);
        }
        $take['preparation_job'] = $take['preparation_job_id'] !== null ? $this->jobs->find($supplierId, (int) $take['preparation_job_id']) : null;
        $take['lines'] = array_map(static function (array $l): array {
            if ($l['counted_qty'] === null) {
                $l['diff_qty'] = null;
            } else {
                $diffT = StockValuation::qtyToT((string) $l['counted_qty']) - StockValuation::qtyToT((string) $l['expected_qty']);
                $l['diff_qty'] = StockValuation::tToDecimal($diffT);
            }
            return $l;
        }, $take['status'] === 'preparing' ? [] : $this->takes->lines($supplierId, $id));
        return $take;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId, array $filters): array
    {
        return $this->takes->list($supplierId, $filters);
    }

    /** @return array<string,mixed>|null nejnovější otevřená (draft/counting) inventura skladu */
    private function openTakeForWarehouse(int $supplierId, int $warehouseId): ?array
    {
        foreach ($this->takes->list($supplierId, ['warehouse_id' => $warehouseId]) as $t) {
            if (in_array((string) $t['status'], ['draft', 'preparing', 'counting'], true)) {
                return $t;
            }
        }
        return null;
    }

    /**
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
                    continue;
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
