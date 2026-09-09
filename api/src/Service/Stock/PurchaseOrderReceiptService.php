<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseOrderRepository;
use MyInvoice\Repository\StockDocumentRepository;
use PDO;

/**
 * Příjem na sklad z objednávky (Epic SKLAD „na cestě", §5.3).
 *
 * Zakládá DRAFT `stock_documents` s `origin='purchase_order'` a vazbou
 * `purchase_order_line_id` na každém řádku. Zaúčtování zůstává na existujícím
 * `POST /api/stock/documents/{id}/post` — žádný paralelní post endpoint;
 * tam se taky (uvnitř téže transakce) přepočte stav objednávky.
 *
 * ## Cena při příjmu před fakturou (rozhodnutí #3)
 *
 * Přijmout se smí i dřív, než dorazí faktura — zboží fyzicky leží ve skladu
 * a předstírat, že tam není, je horší lež než odhadnutá cena. Pořizovací cena
 * se pak bere v pořadí:
 *   1) napárovaný řádek přijaté faktury (přes `purchase_order_line_id`),
 *   2) `unit_price × exchange_rate` z objednávky jako ODHAD — řádek dostane
 *      `cost_is_estimate: true` a UI na to varuje.
 *
 * ## Nadměrná dodávka (§4.1)
 *
 * Default je 409 `over_receipt`; s `allow_over_delivery: true` se přijme
 * a řádek objednávky dostane `has_over_delivery = 1`. `qty_ordered` se
 * NIKDY nezvyšuje — objednali jsme, co jsme objednali, a doklad o tom má zůstat.
 */
final class PurchaseOrderReceiptService
{
    /** Stavy, ze kterých se ještě dá přijímat. */
    private const RECEIVABLE_STATES = ['sent', 'confirmed', 'partially_received', 'received'];

    /** Tolerance porovnání množství (tisíciny — DECIMAL(14,3) je přesná). */
    private const EPSILON_T = 0;

    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseOrderRepository $orders,
        private readonly StockDocumentRepository $docs,
        private readonly StockDocumentService $documents,
        private readonly StockAcquisitionCostService $costs,
    ) {}

    /**
     * Návrh příjemky: otevřené řádky objednávky se skladovou kartou, množství
     * „zbývá přijmout" a odhad ceny.
     *
     * @return array<string,mixed>
     */
    public function propose(int $supplierId, int $orderId): array
    {
        $order = $this->requireOrder($supplierId, $orderId);
        $lines = $this->orders->lines($supplierId, $orderId);
        $received = $this->orders->receivedByOrder($supplierId, [$orderId]);
        $invoiceCosts = $this->invoiceCostsByOrderLine($supplierId, $orderId);
        $rate = $order['exchange_rate'] !== null ? (float) $order['exchange_rate'] : 1.0;

        $anyEstimate = false;
        $out = [];
        foreach ($lines as $line) {
            if ($line['stock_item_id'] === null) {
                continue; // doprava/služba se neskladuje
            }
            $effT = PurchaseOrderStateService::effectiveQtyT($line);
            $recT = StockValuation::qtyToT((string) ($received[(int) $line['id']] ?? '0'));
            $remT = max(0, $effT - $recT);

            [$unitCost, $isEstimate] = $this->resolveUnitCost($line, $invoiceCosts, $rate);
            $anyEstimate = $anyEstimate || ($isEstimate && $remT > 0);

            $out[] = [
                'purchase_order_line_id' => (int) $line['id'],
                'stock_item_id'          => (int) $line['stock_item_id'],
                'warehouse_id'           => $line['warehouse_id'] ?? (int) $order['warehouse_id'],
                'sku'                    => $line['sku'],
                'description'            => (string) $line['description'],
                'unit'                   => (string) $line['unit'],
                'qty_ordered'            => StockValuation::tToDecimal(max(0, $effT)),
                'qty_received'           => StockValuation::tToDecimal($recT),
                'remaining_qty'          => StockValuation::tToDecimal($remT),
                'unit_cost'              => $unitCost,
                'cost_is_estimate'       => $isEstimate,
            ];
        }

        return [
            'order' => [
                'id'            => (int) $order['id'],
                'order_number'  => $order['order_number'],
                'state'         => (string) $order['state'],
                'warehouse_id'  => (int) $order['warehouse_id'],
                'vendor_name'   => $this->vendorName($supplierId, (int) $order['vendor_id']),
                'exchange_rate' => $order['exchange_rate'],
            ],
            'lines'            => $out,
            'cost_is_estimate' => $anyEstimate,
        ];
    }

    /**
     * Založí DRAFT příjemku z vybraných řádků objednávky.
     *
     * @param array<string,mixed> $body {warehouse_id?, doc_date, description?, allow_over_delivery?, lines:list}
     * @return array<string,mixed>
     */
    public function createReceipt(int $supplierId, int $orderId, array $body, ?int $userId): array
    {
        $order = $this->requireOrder($supplierId, $orderId);
        if (!in_array((string) $order['state'], self::RECEIVABLE_STATES, true)) {
            throw new StockException(
                'order_state_conflict',
                'Z téhle objednávky se už nepřijímá — je rozpracovaná, uzavřená nebo stornovaná.',
                409,
                ['state' => (string) $order['state']],
            );
        }

        $docDate = trim((string) ($body['doc_date'] ?? ''));
        if (!self::isDate($docDate)) {
            throw new StockException('invalid_document', 'Datum příjemky je povinné (YYYY-MM-DD).');
        }
        $rawLines = is_array($body['lines'] ?? null) ? $body['lines'] : [];
        if ($rawLines === []) {
            throw new StockException('invalid_document', 'Příjemka musí mít aspoň jeden řádek.');
        }
        $allowOver = !empty($body['allow_over_delivery']);

        $byId = [];
        foreach ($this->orders->lines($supplierId, $orderId) as $line) {
            $byId[(int) $line['id']] = $line;
        }
        $received     = $this->orders->receivedByOrder($supplierId, [$orderId]);
        $invoiceCosts = $this->invoiceCostsByOrderLine($supplierId, $orderId);
        $rate         = $order['exchange_rate'] !== null ? (float) $order['exchange_rate'] : 1.0;

        $docLines   = [];
        $overflow   = [];
        $costEstimate = false;
        foreach ($rawLines as $rl) {
            if (!is_array($rl)) {
                continue;
            }
            $polId = (int) ($rl['purchase_order_line_id'] ?? 0);
            $line  = $byId[$polId] ?? null;
            if ($line === null || $line['stock_item_id'] === null) {
                throw new StockException('invalid_document', 'Řádek objednávky nenalezen nebo nemá skladovou kartu.', 422, [
                    'purchase_order_line_id' => $polId,
                ]);
            }

            $qtyT = StockValuation::qtyToT((string) ($rl['qty'] ?? $rl['quantity'] ?? '0'));
            if ($qtyT <= 0) {
                throw new StockException('invalid_document', 'Množství musí být větší než 0.', 422, [
                    'purchase_order_line_id' => $polId,
                ]);
            }

            $effT = PurchaseOrderStateService::effectiveQtyT($line);
            $recT = StockValuation::qtyToT((string) ($received[$polId] ?? '0'));
            $remT = max(0, $effT - $recT);
            if ($qtyT > $remT + self::EPSILON_T) {
                if (!$allowOver) {
                    $overflow[] = [
                        'purchase_order_line_id' => $polId,
                        'sku'                    => $line['sku'],
                        'requested'              => StockValuation::tToDecimal($qtyT),
                        'remaining'              => StockValuation::tToDecimal($remT),
                    ];
                    continue;
                }
            }

            if (isset($rl['unit_cost']) && $rl['unit_cost'] !== '' && $rl['unit_cost'] !== null) {
                $unitCost = number_format((float) $rl['unit_cost'], 6, '.', '');
            } else {
                [$unitCost, $isEstimate] = $this->resolveUnitCost($line, $invoiceCosts, $rate);
                $costEstimate = $costEstimate || $isEstimate;
            }

            $docLines[] = [
                'stock_item_id'          => (int) $line['stock_item_id'],
                'qty'                    => StockValuation::tToDecimal($qtyT),
                'unit_cost'              => $unitCost,
                'extra_cost'             => '0',
                'purchase_order_line_id' => $polId,
                'source_description'     => (string) $line['description'],
                'source_qty'             => StockValuation::tToDecimal($effT),
            ];
        }

        if ($overflow !== []) {
            throw new StockException(
                'over_receipt',
                'Množství přesahuje zbývající k příjmu z objednávky. Potvrď nadměrnou dodávku, nebo množství uprav.',
                409,
                $overflow,
            );
        }
        if ($docLines === []) {
            throw new StockException('invalid_document', 'Příjemka musí mít aspoň jeden řádek.');
        }

        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            $warehouseId = (int) $order['warehouse_id'];
        }
        $description = trim((string) ($body['description'] ?? ''));
        if ($description === '') {
            $description = 'Příjem z objednávky ' . ((string) ($order['order_number'] ?? ('#' . $orderId)));
        }

        return $this->runInTransaction(function () use (
            $supplierId, $orderId, $warehouseId, $docDate, $description, $docLines, $userId, $costEstimate, $allowOver, $body
        ): array {
            $draft = $this->documents->create($supplierId, [
                'doc_type'          => 'receipt',
                'origin'            => 'purchase_order',
                'warehouse_id'      => $warehouseId,
                'doc_date'          => $docDate,
                'description'       => $description,
                'purchase_order_id' => $orderId,
                'allow_over_delivery' => $allowOver,
                'lines'             => $docLines,
            ], $userId);

            $this->costs->applyLandedCosts($supplierId, (int) $draft['id'], $docDate, is_array($body['landed_costs'] ?? null) ? $body['landed_costs'] : []);

            $doc = $this->docs->findWithLines($supplierId, (int) $draft['id']) ?? $draft;
            $doc['cost_is_estimate'] = $costEstimate;

            return $doc;
        });
    }

    /** @return list<array<string,mixed>> */
    public function receiptsForOrder(int $supplierId, int $orderId): array
    {
        return $this->orders->receiptDocuments($supplierId, $orderId);
    }

    // ── interní ──────────────────────────────────────────────────────────────

    /**
     * Pořizovací cena řádku: faktura má přednost, objednávka je odhad.
     *
     * @param array<string,mixed>  $line
     * @param array<int,string>    $invoiceCosts purchase_order_line_id => cena/MJ
     * @return array{0:string, 1:bool} [cena, je_odhad]
     */
    private function resolveUnitCost(array $line, array $invoiceCosts, float $rate): array
    {
        $polId = (int) $line['id'];
        if (isset($invoiceCosts[$polId])) {
            return [$invoiceCosts[$polId], false];
        }

        return [number_format((float) $line['unit_price'] * $rate, 6, '.', ''), true];
    }

    /**
     * Ceny z řádků přijatých faktur napárovaných na řádky objednávky.
     *
     * Vazba `purchase_invoice_items.purchase_order_line_id` je křehká (editace
     * faktury je replace-all a vazbu zahodí), ale tady vadí jen tolik, že se
     * cena degraduje zpátky na odhad z objednávky — nikdy nevzniká špatné číslo.
     * Autoritou o PŘIJATÉM MNOŽSTVÍ zůstává příjemka, ne tohle.
     *
     * @return array<int,string>
     */
    private function invoiceCostsByOrderLine(int $supplierId, int $orderId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.purchase_order_line_id, pii.quantity, pii.total_without_vat, pii.total_with_vat,
                    pi.id AS purchase_invoice_id, pi.exchange_rate, pi.tax_date, pi.issue_date
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
               JOIN purchase_order_lines pol ON pol.id = pii.purchase_order_line_id
              WHERE pi.supplier_id = ? AND pol.supplier_id = pi.supplier_id
                AND pol.order_id = ? AND pii.purchase_order_line_id IS NOT NULL
                AND pi.document_kind = \'invoice\' AND pii.quantity > 0
              ORDER BY pi.issue_date DESC, pi.id DESC, pii.id DESC'
        );
        $stmt->execute([$supplierId, $orderId]);

        $out = [];
        $contexts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (isset($out[(int) $r['purchase_order_line_id']])) {
                continue;
            }
            $context = $contexts[(int) $r['purchase_invoice_id']] ??= $this->costs->context($supplierId, $r);
            $out[(int) $r['purchase_order_line_id']] = number_format($this->costs->unitCost($context, $r), 6, '.', '');
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function requireOrder(int $supplierId, int $orderId): array
    {
        $order = $this->orders->find($supplierId, $orderId);
        if ($order === null) {
            throw new StockException('not_found', 'Objednávka nenalezena.', 404);
        }

        return $order;
    }

    private function vendorName(int $supplierId, int $vendorId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name FROM clients WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$vendorId, $supplierId]);
        $name = $stmt->fetchColumn();

        return $name === false ? null : (string) $name;
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
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
