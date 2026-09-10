<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\StockDocumentRepository;
use PDO;

/**
 * Replay ledgeru skladové karty (§3.2 — backdating, storno). Přehraje average-cost
 * sekvenci pohybů karty na skladu a přepíše ocenění výdejových řádků + stock_levels.
 *
 * Replay začíná posledním platným snapshotem před fromDate, jinak od nuly.
 * Historie se čte po omezených dávkách ve stejném pořadí jako skladová sestava.
 *
 * Pravidla ocenění při replayi:
 *  - příjmové nohy (receipt, převodka-příjem) drží ULOŽENOU hodnotu řádku
 *    (zadaná PC vč. rozpuštěných extra nákladů) — nepřeceňují se,
 *  - výdejové nohy (issue, převodka-výdej) se PŘECEŇUJÍ klouzavým průměrem
 *    v daném bodě sekvence (StockValuation::issue) a přepíše se jejich
 *    unit_cost/value_total,
 *  - VÝJIMKA (fixní hodnota, bez přecenění): výdejová noha dokladu, který je SÁM
 *    stornovaný (status='reversed') NEBO je protidokladem storna (is_reversal).
 *    Storno-pár je pak v téže uložené hodnotě plně transparentní vůči replayi →
 *    hodnotová neutralita §4.4 platí i po zpětném pohybu měnícím průměr (CRITICAL 2).
 *
 * Souběh: volající MUSÍ držet zámek řádku stock_levels dotčené karty
 * (StockLevelService::lockLevels v téže transakci, lock-order B3) — replay
 * sám nezamyká, jen vyžaduje otevřenou transakci. Reads jsou pod READ COMMITTED
 * (StockDocumentService) čerstvé, ne ze stale snapshotu (CRITICAL 1).
 *
 * Omezení v1 (HIGH 3): zpětný pohyb, který by PŘECENIL existující převodku, se
 * ODMÍTNE (stock_backdate_transfer_unsupported) místo tichého rozejití hodnoty
 * s cílovou kartou — cílová karta se zde kaskádově nepřehrává. Korekce dokladem
 * k dnešnímu datu. (Plná kaskáda převodek = v2.)
 */
final class StockRecomputeService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockLevelService $levels,
        private readonly StockDocumentRepository $docs,
        private readonly AccountingPeriodRepository $periods,
        private readonly StockLedgerReader $ledger,
        private readonly \MyInvoice\Repository\StockValuationSnapshotRepository $snapshots,
    ) {}

    /**
     * Přehraje kartu (warehouse_id, stock_item_id) a přepíše stock_levels.
     *
     * @throws StockException stock_recompute_locked_period (409) — dotčený pohyb
     *         (doc_date >= fromDate) leží v období closing/closed (jen double_entry)
     * @throws StockException insufficient_stock (409) — množství (u fixních
     *         storno-výdejů i hodnota) by v některém kroku kleslo pod nulu
     */
    public function replay(int $supplierId, int $warehouseId, int $stockItemId, string $fromDate): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new StockException('no_transaction', 'Replay ledgeru vyžaduje otevřenou transakci (a držený zámek stock_levels).', 500);
        }

        $snapshot = $this->snapshots->latest($supplierId, $warehouseId, $stockItemId, $fromDate, true);
        $this->guardLockedPeriods($supplierId, $this->ledger->affectedDates($supplierId, $warehouseId, $stockItemId, $fromDate), $fromDate);
        $qtyT = StockValuation::qtyToT((string) ($snapshot['qty'] ?? '0'));
        $valueC = StockValuation::valueToC((string) ($snapshot['value_total'] ?? '0'));
        foreach ($this->ledger->stream($supplierId, $warehouseId, $stockItemId, '9999-12-31', $snapshot['cutoff_date'] ?? null) as $line) {
            $res = StockLedgerReplay::advance($qtyT, $valueC, $line);
            $qtyT = $res['qtyT'];
            $valueC = $res['valueC'];
            if ((int) $line['direction'] === 1 || !empty($line['is_reversal']) || $line['status'] === 'reversed') {
                continue;
            }

            // HIGH 3: přecenění výdejové nohy PŘEVODKY by rozešlo hodnotu s cílovou
            // kartou (přijala PŮVODNÍ hodnotu, zde se kaskádově nepřehrává). Místo
            // tichého driftu ledgeru zpětný pohyb ODMÍTNI — v1 nepodporuje backdating,
            // který mění ocenění existující převodky. Korekce dokladem k dnešku.
            if ($line['doc_type'] === 'transfer'
                && $res['lineValueC'] !== StockValuation::valueToC($line['value_total'])
            ) {
                throw new StockException(
                    'stock_backdate_transfer_unsupported',
                    'Zpětný pohyb by přecenil existující převodku — v1 to nepodporuje. Proveďte korekci dokladem k dnešnímu datu.',
                    409,
                    [[
                        'stock_item_id' => $stockItemId,
                        'sku'           => $line['sku'],
                        'name'          => $line['name'],
                        'document_id'   => $line['document_id'],
                        'doc_date'      => $line['doc_date'],
                    ]],
                );
            }

            $qtyT   = $res['qtyT'];
            $valueC = $res['valueC'];

            // Přepis ocenění jen při reálné změně (šetří UPDATE na dlouhé historii).
            $newUnitCost   = StockValuation::microToDecimal($res['lineUnitCostMicro']);
            $newValueTotal = StockValuation::cToDecimal($res['lineValueC']);
            if ($line['doc_type'] === 'issue' && StockValuation::valueToC($line['value_total']) !== $res['lineValueC']) {
                $assembly = $this->db->pdo()->prepare("SELECT id FROM product_assemblies WHERE supplier_id = ? AND issue_document_id = ? AND status = 'posted' LIMIT 1");
                $assembly->execute([$supplierId, $line['document_id']]);
                if ($assembly->fetchColumn() !== false) throw new StockException('stock_backdate_assembly_unsupported', 'Zpětná změna by přecenila komponenty dokončené kompletace. Proveďte korekci po kompletaci nebo ji nejprve stornujte.', 409);
            }
            if (StockValuation::valueToC($line['value_total']) !== $res['lineValueC']
                || $this->unitCostMicro($line['unit_cost']) !== $res['lineUnitCostMicro']
            ) {
                $this->docs->updateLineValuation(
                    $supplierId,
                    (int) $line['line_id'],
                    $newUnitCost,
                    $newValueTotal,
                    $line['extra_cost'],
                    $line['doc_date'],
                );
            }
        }

        // setLevel má vlastní non-negative pojistku; sem už záporno nedojde.
        $this->levels->setLevel($supplierId, $warehouseId, $stockItemId, $qtyT, $valueC);
    }

    /**
     * Guard uzavřených období (A5 + B9, jen double_entry): pokud KTERÝKOLI dotčený
     * pohyb (doc_date >= fromDate) leží v období closing/closed → 409 a rollback
     * transakce volajícího. Konzervativně se hlídají všechny dotčené pohyby (spec
     * §3.2 „kterýkoli dotčený pohyb"), ne jen přeceňované výdeje.
     *
     * @param list<array<string,mixed>> $lines
     */
    private function guardLockedPeriods(int $supplierId, array $lines, string $fromDate): void
    {
        $dates = [];
        foreach ($lines as $line) {
            $d = (string) $line['doc_date'];
            if ($d >= $fromDate) {
                $dates[$d] = true;
            }
        }
        if ($dates === [] || !$this->isDoubleEntry($supplierId)) {
            return;
        }

        // Memoizace období podle rozsahu — jeden findForDate na období, ne na datum.
        /** @var list<array{starts_on:string, ends_on:string, status:string}> $known */
        $known = [];
        foreach (array_keys($dates) as $date) {
            $period = null;
            foreach ($known as $k) {
                if ($date >= $k['starts_on'] && $date <= $k['ends_on']) {
                    $period = $k;
                    break;
                }
            }
            if ($period === null) {
                $row = $this->periods->findForDate($supplierId, $date);
                if ($row === null) {
                    continue;
                }
                $period = [
                    'starts_on' => (string) $row['starts_on'],
                    'ends_on'   => (string) $row['ends_on'],
                    'status'    => (string) $row['status'],
                ];
                $known[] = $period;
            }
            if (in_array($period['status'], ['closing', 'closed'], true)) {
                throw new StockException(
                    'stock_recompute_locked_period',
                    'Zpětný pohyb by změnil ocenění výdejů v uzavřeném období — proveď korekci dokladem k dnešnímu datu.',
                    409,
                    ['doc_date' => $date, 'period_status' => $period['status']],
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

    /** DECIMAL(15,6) string → micro int (porovnání uloženého vs. přepočteného). */
    private function unitCostMicro(string $unitCost): int
    {
        return (int) round((float) $unitCost * StockValuation::MICRO);
    }
}
