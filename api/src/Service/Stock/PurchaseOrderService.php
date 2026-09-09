<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseOrderRepository;
use MyInvoice\Repository\WarehouseRepository;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use PDO;

/**
 * Objednávky vydané dodavateli (Epic SKLAD „na cestě", fáze 1).
 *
 * Objednávka NENÍ účetní případ (§ 11 ZoÚ): nepřešlo vlastnictví, nevznikl
 * závazek. Tahle služba proto NEGENERUJE deníkový zápis ani skladový pohyb —
 * dělá jen dokladovou evidenci a stavový automat. Do skladu se sáhne teprve
 * příjemkou ({@see PurchaseOrderReceiptService}).
 *
 * Ruční přechody jsou jen `send`, `confirm`, `cancel`, `close`, `reopen`.
 * `partially_received` / `received` dopočítává výhradně
 * {@see PurchaseOrderStateService::recompute()} po každém post/reverse příjemky.
 *
 * Číslo z řady OBJ se vydává až v `send()`, uvnitř transakce s FOR UPDATE na
 * hlavičce (vzor StockDocumentService::post — číslo se nesmí propálit při 409).
 */
final class PurchaseOrderService
{
    /** Stavy, ve kterých je hlavička i řádky ještě plně editovatelná. */
    private const EDITABLE_STATES = ['draft'];

    /** Terminální stavy — z nich už automat nikam nepokračuje (kromě reopen). */
    private const TERMINAL_STATES = ['closed', 'cancelled'];

    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseOrderRepository $orders,
        private readonly WarehouseRepository $warehouses,
        private readonly DocumentSeriesService $series,
        private readonly PurchaseOrderStateService $states,
    ) {}

    // ── CRUD ─────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $body, ?int $userId): array
    {
        [$header, $lines] = $this->validateBody($supplierId, $body);
        $header['created_by'] = $userId;

        return $this->runInTransaction(function () use ($supplierId, $header, $lines): array {
            $id = $this->orders->insertHeader($supplierId, $header);
            $this->orders->replaceLines($supplierId, $id, $lines);
            $this->recalcTotals($supplierId, $id, $lines);
            $order = $this->detail($supplierId, $id);
            if ($order === null) {
                throw new StockException('not_found', 'Objednávku se nepodařilo založit.', 500);
            }

            return $order;
        });
    }

    /**
     * Úprava. Hlavičku a řádky lze měnit jen v `draft` — jakmile objednávka
     * odešla dodavateli, je to doklad, který u něj existuje, a mění se
     * potvrzením (`confirm`) nebo uzavřením zbytku (`close`), ne přepisem.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(int $supplierId, int $id, array $body, ?int $userId): array
    {
        return $this->runInTransaction(function () use ($supplierId, $id, $body): array {
            $existing = $this->requireLocked($supplierId, $id);
            if (!in_array((string) $existing['state'], self::EDITABLE_STATES, true)) {
                throw new StockException('order_not_editable', 'Upravovat lze jen rozpracovanou objednávku.', 409);
            }
            [$header, $lines] = $this->validateBody($supplierId, $body);
            if (!$this->orders->updateHeader($supplierId, $id, $header)) {
                throw new StockException('order_not_editable', 'Stav objednávky se během úpravy změnil.', 409);
            }
            $this->orders->replaceLines($supplierId, $id, $lines);
            $this->recalcTotals($supplierId, $id, $lines);

            return $this->detailOrFail($supplierId, $id);
        });
    }

    /** Smazat jde jen draft — odeslaná objednávka se stornuje, ať zůstane stopa. */
    public function delete(int $supplierId, int $id): bool
    {
        return $this->runInTransaction(function () use ($supplierId, $id): bool {
            $existing = $this->requireLocked($supplierId, $id);
            if ((string) $existing['state'] !== 'draft') {
                throw new StockException('order_not_editable', 'Smazat lze jen rozpracovanou objednávku.', 409);
            }
            return $this->orders->delete($supplierId, $id);
        });
    }

    /**
     * Detail obohacený o plnění: přijato / zbývá per řádek + agregát hlavičky.
     *
     * @return array<string,mixed>|null
     */
    public function detail(int $supplierId, int $id): ?array
    {
        $order = $this->orders->findWithLines($supplierId, $id);
        if ($order === null) {
            return null;
        }

        return $this->withFulfilment($supplierId, $order);
    }

    /**
     * @param array<string,mixed> $order objednávka vč. klíče `lines`
     * @return array<string,mixed>
     */
    public function withFulfilment(int $supplierId, array $order): array
    {
        $received = $this->orders->receivedByOrder($supplierId, [(int) $order['id']]);

        $orderedT   = 0;
        $receivedT  = 0;
        $remainingT = 0;
        foreach ($order['lines'] as &$line) {
            $recT = StockValuation::qtyToT((string) ($received[(int) $line['id']] ?? '0'));
            $effT = PurchaseOrderStateService::effectiveQtyT($line);
            $remT = max(0, $effT - $recT);

            $line['qty_received']  = StockValuation::tToDecimal($recT);
            $line['qty_effective'] = StockValuation::tToDecimal(max(0, $effT));
            $line['qty_remaining'] = StockValuation::tToDecimal($remT);

            if ($line['stock_item_id'] !== null) {
                $orderedT   += max(0, $effT);
                $receivedT  += $recT;
                $remainingT += $remT;
            }
        }
        unset($line);

        $order['qty_ordered_total']   = StockValuation::tToDecimal($orderedT);
        $order['qty_received_total']  = StockValuation::tToDecimal($receivedT);
        $order['qty_remaining_total'] = StockValuation::tToDecimal($remainingT);
        $order['invoice_links']       = $this->orders->invoiceLinks($supplierId, (int) $order['id']);
        $order['receipts']            = $this->orders->receiptDocuments($supplierId, (int) $order['id']);

        // Popisky stran a skladu — detail musí nést stejná pole jako řádek seznamu,
        // jinak by karta objednávky ukazovala u dodavatele pomlčku i tam, kde ho
        // seznam vypisuje jménem.
        return array_merge($order, $this->labels($supplierId, $order));
    }

    /**
     * Názvy navázaných číselníků jedním dotazem (dodavatel, sklad, měna).
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function labels(int $supplierId, array $order): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.company_name AS vendor_name,
                    w.code AS warehouse_code, w.name AS warehouse_name,
                    cur.code AS currency_code
               FROM purchase_orders o
          LEFT JOIN clients c ON c.id = o.vendor_id AND c.supplier_id = o.supplier_id
          LEFT JOIN warehouses w ON w.id = o.warehouse_id AND w.supplier_id = o.supplier_id
          LEFT JOIN currencies cur ON cur.id = o.currency_id AND cur.supplier_id = o.supplier_id
              WHERE o.supplier_id = ? AND o.id = ?'
        );
        $stmt->execute([$supplierId, (int) $order['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [];
        }

        return [
            'vendor_name'    => $row['vendor_name'] !== null ? (string) $row['vendor_name'] : null,
            'warehouse_code' => $row['warehouse_code'] !== null ? (string) $row['warehouse_code'] : null,
            'warehouse_name' => $row['warehouse_name'] !== null ? (string) $row['warehouse_name'] : null,
            'currency_code'  => $row['currency_code'] !== null ? (string) $row['currency_code'] : null,
        ];
    }

    // ── stavový automat ──────────────────────────────────────────────────────

    /**
     * draft → sent. Přidělí číslo z řady OBJ (v transakci, po FOR UPDATE
     * hlavičky), od téhle chvíle se položky počítají „na cestě" (rozhodnutí #2).
     *
     * @return array<string,mixed>
     */
    public function send(int $supplierId, int $id, ?int $userId): array
    {
        return $this->runInTransaction(function () use ($supplierId, $id, $userId): array {
            $order = $this->requireLocked($supplierId, $id);
            if ((string) $order['state'] === 'sent') {
                // Idempotence dvojkliku — číslo se podruhé nepřiděluje.
                return $this->detailOrFail($supplierId, $id);
            }
            if ((string) $order['state'] !== 'draft') {
                throw $this->badState($order, 'Odeslat lze jen rozpracovanou (draft) objednávku.');
            }
            if ($this->orders->lines($supplierId, $id) === []) {
                throw new StockException('invalid_order', 'Objednávka musí mít aspoň jeden řádek.');
            }

            if ($order['order_number'] === null) {
                $year   = (int) substr((string) $order['order_date'], 0, 4);
                $number = $this->series->next($supplierId, 'purchase_order', $year);
                if (!$this->orders->assignNumber($supplierId, $id, $number)) {
                    throw new StockException('invalid_order', 'Číslo objednávky se nepodařilo přidělit.', 500);
                }
            }
            if (!$this->orders->transition($supplierId, $id, ['draft'], 'sent', ['sent_at' => '__NOW__'])) {
                throw new StockException('order_state_conflict', 'Stav objednávky se mezitím změnil.', 409);
            }

            return $this->detailOrFail($supplierId, $id);
        });
    }

    /**
     * sent → confirmed. Dodavatel potvrdil — volitelně s jiným množstvím
     * (`qty_confirmed` per řádek) a jiným termínem. Potvrzené množství nahrazuje
     * objednané ve VŠECH výpočtech „na cestě".
     *
     * @param array<string,mixed> $body {expected_date?, lines?: [{id, qty_confirmed?, expected_date?}]}
     * @return array<string,mixed>
     */
    public function confirm(int $supplierId, int $id, array $body, ?int $userId): array
    {
        return $this->runInTransaction(function () use ($supplierId, $id, $body, $userId): array {
            $order = $this->requireLocked($supplierId, $id);
            if (!in_array((string) $order['state'], ['sent', 'confirmed'], true)) {
                throw $this->badState($order, 'Potvrdit lze jen odeslanou objednávku.');
            }

            $byId = [];
            foreach ($this->orders->lines($supplierId, $id) as $line) {
                $byId[(int) $line['id']] = $line;
            }

            foreach (is_array($body['lines'] ?? null) ? $body['lines'] : [] as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $lineId = (int) ($raw['id'] ?? 0);
                if (!isset($byId[$lineId])) {
                    throw new StockException('invalid_order', 'Řádek objednávky nenalezen.', 422, ['line_id' => $lineId]);
                }
                $qtyConfirmed = null;
                if (array_key_exists('qty_confirmed', $raw) && $raw['qty_confirmed'] !== null && $raw['qty_confirmed'] !== '') {
                    $qtyT = StockValuation::qtyToT((string) $raw['qty_confirmed']);
                    if ($qtyT < 0) {
                        throw new StockException('invalid_order', 'Potvrzené množství nesmí být záporné.', 422, ['line_id' => $lineId]);
                    }
                    $qtyConfirmed = StockValuation::tToDecimal($qtyT);
                }
                $expected = self::dateOrNull($raw['expected_date'] ?? null);
                $this->orders->setLineConfirmed($supplierId, $lineId, $qtyConfirmed, $expected);
            }

            $set = ['confirmed_at' => '__NOW__', 'confirmed_by' => $userId];
            $headerExpected = self::dateOrNull($body['expected_date'] ?? null);
            if ($headerExpected !== null) {
                $set['expected_date'] = $headerExpected;
            }
            if (!$this->orders->transition($supplierId, $id, ['sent', 'confirmed'], 'confirmed', $set)) {
                throw new StockException('order_state_conflict', 'Stav objednávky se mezitím změnil.', 409);
            }

            return $this->detailOrFail($supplierId, $id);
        });
    }

    /**
     * → cancelled. Jen dokud se nic nepřijalo; s částečným příjmem se místo
     * storna zavírá zbytek (`close`), protože přijaté zboží už na skladě leží
     * a stornovaná objednávka by ho odpojila od svého původu.
     *
     * @return array<string,mixed>
     */
    public function cancel(int $supplierId, int $id, string $reason, ?int $userId): array
    {
        return $this->runInTransaction(function () use ($supplierId, $id, $reason, $userId): array {
            $order = $this->requireLocked($supplierId, $id);
            if (in_array((string) $order['state'], self::TERMINAL_STATES, true)) {
                throw $this->badState($order, 'Objednávka je už uzavřená nebo stornovaná.');
            }

            $receivedT = $this->receivedTotalT($supplierId, $id);
            if ($receivedT > 0) {
                throw new StockException(
                    'order_partially_received',
                    'K objednávce už existuje příjem — místo storna uzavři nedodaný zbytek („Zavřít zbytek").',
                    409,
                    ['received_qty' => StockValuation::tToDecimal($receivedT)],
                );
            }

            $ok = $this->orders->transition(
                $supplierId,
                $id,
                ['draft', 'sent', 'confirmed'],
                'cancelled',
                [
                    'cancelled_at'  => '__NOW__',
                    'cancelled_by'  => $userId,
                    'cancel_reason' => self::trimOrNull($reason, 255),
                ],
            );
            if (!$ok) {
                throw new StockException('order_state_conflict', 'Stav objednávky se mezitím změnil.', 409);
            }

            return $this->detailOrFail($supplierId, $id);
        });
    }

    /**
     * → closed. „Zbytek nedodán": doplní `qty_cancelled` na otevřených řádcích,
     * čímž z „na cestě" zmizí právě to, co už nepřijde, a objednávka se zavře.
     *
     * @return array<string,mixed>
     */
    public function close(int $supplierId, int $id, string $reason, ?int $userId): array
    {
        return $this->runInTransaction(function () use ($supplierId, $id, $reason, $userId): array {
            $order = $this->requireLocked($supplierId, $id);
            if (in_array((string) $order['state'], self::TERMINAL_STATES, true)) {
                throw $this->badState($order, 'Objednávka je už uzavřená nebo stornovaná.');
            }
            if ((string) $order['state'] === 'draft') {
                throw $this->badState($order, 'Rozpracovanou objednávku smaž nebo stornuj, zavírá se až odeslaná.');
            }

            $received = $this->orders->receivedByOrder($supplierId, [$id]);
            foreach ($this->orders->lines($supplierId, $id) as $line) {
                $recT  = StockValuation::qtyToT((string) ($received[(int) $line['id']] ?? '0'));
                $baseT = $line['qty_confirmed'] !== null && $line['qty_confirmed'] !== ''
                    ? StockValuation::qtyToT((string) $line['qty_confirmed'])
                    : StockValuation::qtyToT((string) $line['qty_ordered']);
                // Zbytek k uzavření = objednáno − přijato; nikdy záporně (nadměrná
                // dodávka objednané množství nezvyšuje ani nesnižuje).
                $cancelT = max(0, $baseT - $recT);
                if ($cancelT !== StockValuation::qtyToT((string) $line['qty_cancelled'])) {
                    $this->orders->setLineCancelled($supplierId, (int) $line['id'], StockValuation::tToDecimal($cancelT));
                }
            }

            $ok = $this->orders->transition(
                $supplierId,
                $id,
                ['sent', 'confirmed', 'partially_received', 'received'],
                'closed',
                [
                    'closed_at'    => '__NOW__',
                    'closed_by'    => $userId,
                    'close_reason' => self::trimOrNull($reason, 255),
                ],
            );
            if (!$ok) {
                throw new StockException('order_state_conflict', 'Stav objednávky se mezitím změnil.', 409);
            }

            return $this->detailOrFail($supplierId, $id);
        });
    }

    /**
     * closed → zpět do běhu. Vynuluje uzavřený zbytek na řádcích a nechá
     * {@see PurchaseOrderStateService} dopočítat, kde objednávka reálně je
     * (`sent` / `confirmed` / `partially_received` / `received`).
     *
     * @return array<string,mixed>
     */
    public function reopen(int $supplierId, int $id, ?int $userId): array
    {
        return $this->runInTransaction(function () use ($supplierId, $id): array {
            $order = $this->requireLocked($supplierId, $id);
            if ((string) $order['state'] !== 'closed') {
                throw $this->badState($order, 'Znovu otevřít lze jen uzavřenou objednávku.');
            }

            foreach ($this->orders->lines($supplierId, $id) as $line) {
                if (StockValuation::qtyToT((string) $line['qty_cancelled']) !== 0) {
                    $this->orders->setLineCancelled($supplierId, (int) $line['id'], '0.000');
                }
            }
            $ok = $this->orders->transition($supplierId, $id, ['closed'], 'sent', [
                'closed_at'    => null,
                'closed_by'    => null,
                'close_reason' => null,
            ]);
            if (!$ok) {
                throw new StockException('order_state_conflict', 'Stav objednávky se mezitím změnil.', 409);
            }
            $this->states->recompute($supplierId, $id);

            return $this->detailOrFail($supplierId, $id);
        });
    }

    // ── validace ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>, 1:list<array<string,mixed>>}
     */
    private function validateBody(int $supplierId, array $body): array
    {
        $vendorId = (int) ($body['vendor_id'] ?? 0);
        if ($vendorId <= 0 || !$this->ownsClient($supplierId, $vendorId)) {
            throw new StockException('invalid_order', 'Dodavatel je povinný a musí patřit vaší firmě.', 422, ['vendor_id' => $vendorId]);
        }

        $orderDate = trim((string) ($body['order_date'] ?? ''));
        if (!self::isDate($orderDate)) {
            throw new StockException('invalid_order', 'Datum objednávky je povinné (YYYY-MM-DD).');
        }

        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        $this->requireActiveWarehouse($supplierId, $warehouseId);

        $currencyId = (int) ($body['currency_id'] ?? 0);
        if ($currencyId <= 0 || !$this->ownsCurrency($supplierId, $currencyId)) {
            throw new StockException('invalid_order', 'Měna je povinná a musí patřit vaší firmě.', 422, ['currency_id' => $currencyId]);
        }

        $rawLines = is_array($body['lines'] ?? null) ? $body['lines'] : [];
        if ($rawLines === []) {
            throw new StockException('invalid_order', 'Objednávka musí mít aspoň jeden řádek.');
        }

        $itemIds = [];
        $whIds   = [];
        foreach ($rawLines as $rl) {
            if (!is_array($rl)) {
                continue;
            }
            if ((int) ($rl['stock_item_id'] ?? 0) > 0) {
                $itemIds[] = (int) $rl['stock_item_id'];
            }
            if ((int) ($rl['warehouse_id'] ?? 0) > 0) {
                $whIds[] = (int) $rl['warehouse_id'];
            }
        }
        $items = $this->itemsMeta($supplierId, $itemIds);
        foreach (array_values(array_unique($whIds)) as $wh) {
            $this->requireActiveWarehouse($supplierId, (int) $wh);
        }

        $lines  = [];
        $lineNo = 1;
        foreach ($rawLines as $rl) {
            if (!is_array($rl)) {
                continue;
            }
            $itemId = (int) ($rl['stock_item_id'] ?? 0);
            if ($itemId > 0 && !isset($items[$itemId])) {
                throw new StockException('invalid_order', 'Skladová karta nenalezena.', 422, ['stock_item_id' => $itemId]);
            }

            $qtyT = StockValuation::qtyToT((string) ($rl['qty_ordered'] ?? $rl['qty'] ?? '0'));
            if ($qtyT <= 0) {
                throw new StockException('invalid_order', 'Objednané množství musí být větší než 0.', 422, [
                    'line_no' => $lineNo,
                ]);
            }

            $description = trim((string) ($rl['description'] ?? ''));
            if ($description === '' && $itemId > 0) {
                $description = (string) $items[$itemId]['name'];
            }
            if ($description === '') {
                throw new StockException('invalid_order', 'Popis řádku je povinný.', 422, ['line_no' => $lineNo]);
            }

            $unitPrice = (float) ($rl['unit_price'] ?? 0);
            if ($unitPrice < 0) {
                throw new StockException('invalid_order', 'Cena za jednotku nesmí být záporná.', 422, ['line_no' => $lineNo]);
            }

            $lines[] = [
                'id' => (int) ($rl['id'] ?? 0),
                'line_no'       => $lineNo++,
                'stock_item_id' => $itemId > 0 ? $itemId : null,
                'warehouse_id'  => (int) ($rl['warehouse_id'] ?? 0) > 0 ? (int) $rl['warehouse_id'] : null,
                'vendor_sku'    => self::trimOrNull($rl['vendor_sku'] ?? null, 80),
                'description'   => mb_substr($description, 0, 500),
                'unit'          => mb_substr(trim((string) ($rl['unit'] ?? ($itemId > 0 ? $items[$itemId]['unit'] : 'ks'))) ?: 'ks', 0, 20),
                'qty_ordered'   => StockValuation::tToDecimal($qtyT),
                'qty_confirmed' => null,
                'qty_cancelled' => '0.000',
                'unit_price'    => number_format($unitPrice, 6, '.', ''),
                'vat_rate_id'   => (int) ($rl['vat_rate_id'] ?? 0) > 0 ? (int) $rl['vat_rate_id'] : null,
                'expected_date' => self::dateOrNull($rl['expected_date'] ?? null),
                'note'          => self::trimOrNull($rl['note'] ?? null, 255),
            ];
        }
        if ($lines === []) {
            throw new StockException('invalid_order', 'Objednávka musí mít aspoň jeden řádek.');
        }

        $header = [
            'vendor_id'        => $vendorId,
            'vendor_reference' => self::trimOrNull($body['vendor_reference'] ?? null, 50),
            'order_date'       => $orderDate,
            'expected_date'    => self::dateOrNull($body['expected_date'] ?? null),
            'warehouse_id'     => $warehouseId,
            'currency_id'      => $currencyId,
            'exchange_rate'    => isset($body['exchange_rate']) && $body['exchange_rate'] !== '' && $body['exchange_rate'] !== null
                ? number_format((float) $body['exchange_rate'], 6, '.', '') : null,
            'note'             => self::trimOrNull($body['note'] ?? null, 65535),
            'internal_note'    => self::trimOrNull($body['internal_note'] ?? null, 65535),
        ];

        return [$header, $lines];
    }

    /**
     * Součty hlavičky. Sazbu DPH bereme ze snapshotu `vat_rates` — objednávka
     * není daňový doklad, jde jen o orientační celkovou částku pro schvalování.
     *
     * @param list<array<string,mixed>> $lines
     */
    private function recalcTotals(int $supplierId, int $orderId, array $lines): void
    {
        $rates = $this->vatRates(array_values(array_filter(array_map(
            static fn (array $l): ?int => $l['vat_rate_id'] !== null ? (int) $l['vat_rate_id'] : null,
            $lines,
        ))));

        $withoutC = 0;
        $withC    = 0;
        foreach ($lines as $line) {
            $base = round((float) $line['qty_ordered'] * (float) $line['unit_price'], 2);
            $rate = $line['vat_rate_id'] !== null ? ($rates[(int) $line['vat_rate_id']] ?? 0.0) : 0.0;
            $withoutC += StockValuation::valueToC($base);
            $withC    += StockValuation::valueToC(round($base * (1 + $rate / 100), 2));
        }

        $this->orders->updateTotals(
            $supplierId,
            $orderId,
            StockValuation::cToDecimal($withoutC),
            StockValuation::cToDecimal($withC),
        );
    }

    // ── interní ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function requireLocked(int $supplierId, int $id): array
    {
        $order = $this->orders->lockForUpdate($supplierId, $id);
        if ($order === null) {
            throw new StockException('not_found', 'Objednávka nenalezena.', 404);
        }

        return $order;
    }

    /** @return array<string,mixed> */
    private function detailOrFail(int $supplierId, int $id): array
    {
        $order = $this->detail($supplierId, $id);
        if ($order === null) {
            throw new StockException('not_found', 'Objednávka nenalezena.', 404);
        }

        return $order;
    }

    /** @param array<string,mixed> $order */
    private function badState(array $order, string $message): StockException
    {
        return new StockException('order_state_conflict', $message, 409, ['state' => (string) $order['state']]);
    }

    private function receivedTotalT(int $supplierId, int $orderId): int
    {
        $total = 0;
        foreach ($this->orders->receivedByOrder($supplierId, [$orderId]) as $qty) {
            $total += StockValuation::qtyToT((string) $qty);
        }

        return $total;
    }

    private function ownsClient(int $supplierId, int $clientId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM clients WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$clientId, $supplierId]);

        return $stmt->fetchColumn() !== false;
    }

    private function ownsCurrency(int $supplierId, int $currencyId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM currencies WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$currencyId, $supplierId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,mixed> */
    private function requireActiveWarehouse(int $supplierId, int $warehouseId): array
    {
        $warehouse = $this->warehouses->find($supplierId, $warehouseId);
        if ($warehouse === null) {
            throw new StockException('invalid_order', 'Sklad nenalezen.', 422, ['warehouse_id' => $warehouseId]);
        }
        if (empty($warehouse['is_active'])) {
            throw new StockException('invalid_order', 'Sklad je neaktivní.', 422, ['warehouse_id' => $warehouseId]);
        }

        return $warehouse;
    }

    /**
     * @param list<int> $itemIds
     * @return array<int,array{sku:string, name:string, unit:string}>
     */
    private function itemsMeta(int $supplierId, array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter($itemIds, static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = $this->db->pdo()->prepare(
            "SELECT id, sku, name, unit FROM stock_items WHERE supplier_id = ? AND id IN ($place)"
        );
        $stmt->execute([$supplierId, ...$ids]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = [
                'sku'  => (string) $r['sku'],
                'name' => (string) $r['name'],
                'unit' => (string) $r['unit'],
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,float>
     */
    private function vatRates(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        // vat_rates je globální číselník (bez supplier_id) — tenant predikát
        // se sem nedoplňuje, protože ho tabulka nemá.
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = $this->db->pdo()->prepare("SELECT id, rate_percent FROM vat_rates WHERE id IN ($place)");
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = (float) $r['rate_percent'];
        }

        return $out;
    }

    /**
     * Nested-tx vzor s jedním retry při deadlocku (shodně
     * {@see StockDocumentService::runInTransaction()}); READ COMMITTED, aby
     * čtení po FOR UPDATE nevracelo stale snapshot.
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

    private static function trimOrNull(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : mb_substr($s, 0, $maxLength);
    }

    private static function dateOrNull(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));

        return self::isDate($s) ? $s : null;
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
