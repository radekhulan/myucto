<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Repository\PurchaseOrderRepository;

/**
 * JEDINÉ místo, které nastavuje `partially_received` / `received` (Epic SKLAD
 * „na cestě", §4.1).
 *
 * Tyhle dva stavy nejsou uživatelské rozhodnutí, ale funkce skutečně přijatého
 * množství — kdyby je uměl přepnout i někdo jiný, rozešel by se stav objednávky
 * s odvozeným „na cestě" a nikdo by si toho nevšiml (obojí čte jiný dotaz).
 * Volá se z {@see StockDocumentService::post()} a `reverse()` UVNITŘ TÉŽE
 * TRANSAKCE, ve které se hýbe skladem, aby stav a pohyb byly atomické.
 *
 * Recompute je čistě idempotentní přepočet z dat, nikoli přechod: dvojí volání
 * nad stejnými daty dá stejný výsledek a storno příjemky vrátí stav zpátky
 * z `received` na `partially_received` nebo až na `sent`/`confirmed`.
 */
final class PurchaseOrderStateService
{
    /** Stavy, ze kterých recompute smí vycházet (a do kterých se smí vracet). */
    private const OPEN_STATES = ['sent', 'confirmed', 'partially_received', 'received'];

    /** Tolerance zaokrouhlení v tisícinách (DECIMAL(14,3) je přesná, tohle je jen pojistka). */
    private const EPSILON_T = 0;

    public function __construct(private readonly PurchaseOrderRepository $orders) {}

    public function lockForLines(int $supplierId, array $lines): array
    {
        $ids = array_column($lines, 'purchase_order_line_id');
        $orders = [];
        foreach ($this->orders->orderIdsForLines($supplierId, $ids) as $orderId) {
            $order = $this->orders->lockForUpdate($supplierId, $orderId);
            if ($order !== null) {
                $orders[$orderId] = $order;
            }
        }
        return $orders;
    }

    public function assertReceiptAllowed(int $supplierId, array $doc, array $lines, array $orders): void
    {
        $requested = [];
        $requestedItems = [];
        foreach ($lines as $line) {
            $id = (int) ($line['purchase_order_line_id'] ?? 0);
            if ($id > 0) {
                $requested[$id] = ($requested[$id] ?? 0) + StockValuation::qtyToT((string) $line['qty']);
                $requestedItems[$id][(int) $line['stock_item_id']] = true;
            }
        }
        $received = $this->orders->receivedByOrder($supplierId, array_keys($orders));
        foreach ($orders as $orderId => $order) {
            if (!in_array((string) $order['state'], self::OPEN_STATES, true)) {
                throw new StockException('order_state_conflict', 'Objednávka již nepovoluje příjem.', 409);
            }
            foreach ($this->orders->lines($supplierId, $orderId) as $line) {
                $id = (int) $line['id'];
                if (!isset($requested[$id])) {
                    continue;
                }
                if (array_keys($requestedItems[$id]) !== [(int) $line['stock_item_id']]) {
                    throw new StockException('invalid_reference', 'Příjem musí odpovídat skladové kartě řádku objednávky.', 409);
                }
                $remaining = max(0, self::effectiveQtyT($line) - StockValuation::qtyToT((string) ($received[$id] ?? '0')));
                if ($requested[$id] > $remaining) {
                    if (empty($doc['allow_over_delivery'])) {
                        throw new StockException('over_receipt', 'Množství přesahuje zbývající k příjmu z objednávky.', 409, [
                            'purchase_order_line_id' => $id,
                            'requested' => StockValuation::tToDecimal($requested[$id]),
                            'remaining' => StockValuation::tToDecimal($remaining),
                        ]);
                    }
                    $this->orders->markLineOverDelivery($supplierId, $id);
                }
                unset($requested[$id]);
            }
        }
        if ($requested !== []) {
            throw new StockException('invalid_reference', 'Řádek objednávky již není dostupný.', 409);
        }
    }

    public function recomputeForLines(int $supplierId, array $lines): void
    {
        foreach (array_keys($this->lockForLines($supplierId, $lines)) as $orderId) {
            $this->recompute($supplierId, $orderId);
        }
    }

    /**
     * Přepočte stav objednávky podle skutečně přijatého množství.
     *
     * Rozhodovací tabulka (jen řádky se `stock_item_id`; doprava a služby plnění
     * neurčují):
     *   přijato = 0                    → zpět na výchozí stav (`confirmed`, jinak `sent`)
     *   0 < přijato < zbývá objednáno  → `partially_received`
     *   přijato >= objednáno − storno  → `received`
     *
     * `closed` a `cancelled` jsou terminální rozhodnutí uživatele — ty recompute
     * NEPŘEPISUJE (jinak by doúčtovaná příjemka tiše otevřela zavřenou objednávku).
     * `draft` taky ne: příjemka k draftu nemůže existovat.
     *
     * @return string výsledný stav (i když se nezměnil)
     */
    public function recompute(int $supplierId, int $orderId): string
    {
        $order = $this->orders->lockForUpdate($supplierId, $orderId);
        if ($order === null) {
            throw new StockException('not_found', 'Objednávka nenalezena.', 404);
        }

        $current = (string) $order['state'];
        if (!in_array($current, self::OPEN_STATES, true)) {
            return $current;
        }

        $target = $this->targetState($supplierId, $orderId, $order);
        if ($target === $current) {
            return $current;
        }

        // Predikát na OPEN_STATES: souběžný cancel/close vyhraje a recompute
        // ho nepřepíše (rowCount = 0, žádná výjimka — přepočet je best effort
        // vůči rozhodnutí uživatele).
        $this->orders->transition($supplierId, $orderId, self::OPEN_STATES, $target);

        return $target;
    }

    /**
     * Cílový stav bez zápisu — používá i detail objednávky, aby UI ukazovalo
     * totéž, co by recompute nastavil.
     *
     * @param array<string,mixed> $order
     */
    public function targetState(int $supplierId, int $orderId, array $order): string
    {
        $fallback = ((string) $order['state']) === 'confirmed'
            || $order['confirmed_at'] !== null ? 'confirmed' : 'sent';

        $lines    = $this->orders->lines($supplierId, $orderId);
        $received = $this->orders->receivedByOrder($supplierId, [$orderId]);

        $remainingT = 0;
        $receivedT = 0;
        $anyStockLine = false;
        foreach ($lines as $line) {
            if ($line['stock_item_id'] === null) {
                continue;
            }
            $anyStockLine = true;
            $lineReceivedT = max(0, StockValuation::qtyToT((string) ($received[(int) $line['id']] ?? '0')));
            $remainingT += max(0, self::effectiveQtyT($line) - $lineReceivedT);
            $receivedT += $lineReceivedT;
        }

        if (!$anyStockLine) {
            // Objednávka jen na služby: plnění se z ní odvodit nedá, zůstává
            // tam, kde ji nechal uživatel (zavře se ručně přes close()).
            return (string) $order['state'];
        }
        if ($receivedT <= self::EPSILON_T) {
            return $fallback;
        }
        if ($remainingT <= self::EPSILON_T) {
            return 'received';
        }

        return 'partially_received';
    }

    /**
     * Kolik se na řádku ještě čeká: potvrzeno (nebo objednáno) minus uzavřený
     * zbytek. Sdílená definice s {@see InTransitRepository} — kdyby se rozešly,
     * ukazovala by dlaždice „na cestě" jiné číslo než stav objednávky.
     *
     * @param array<string,mixed> $line
     */
    public static function effectiveQtyT(array $line): int
    {
        $base = $line['qty_confirmed'] !== null && $line['qty_confirmed'] !== ''
            ? (string) $line['qty_confirmed']
            : (string) $line['qty_ordered'];

        return StockValuation::qtyToT($base) - StockValuation::qtyToT((string) $line['qty_cancelled']);
    }
}
