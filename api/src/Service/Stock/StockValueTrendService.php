<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Vývoj hodnoty zásob: ocenění k několika datům vedle sebe (např. koncům měsíců),
 * po kategoriích a celkem. Hodnota každé dvojice (sklad, karta) k datu vychází ze
 * stejného průběhu skladové knihy ({@see StockLedgerReplay}) jako jednodenní
 * ocenění {@see StockReportService::valuation()}; běží jako úloha na pozadí a kniha se čte jednou pro
 * všechna data (jeden dotaz na dávku karet, ne na kartu a datum) a stav se
 * zaznamená při přechodu každého data. Zařazení do kategorií řeší
 * {@see StockValuationCategoryGroups} (dnešní kategorie karet).
 */
final class StockValueTrendService
{
    public const MAX_DATES = 36;
    public const MAX_RESTARTS = 2;

    public function __construct(
        private readonly Connection $db,
        private readonly StockValuationCategoryGroups $categoryGroups,
        private readonly \MyInvoice\Service\Eshop\CatalogJobService $jobs,
        private readonly \MyInvoice\Repository\StockValuationSnapshotRepository $snapshots,
        private readonly \MyInvoice\Repository\WarehouseRepository $warehouses,
        /** Karet na dávku. */
        private readonly int $chunkItems = 1000,
    ) {}

    /**
     * Konce měsíců v rozmezí od–do a samotné `do` (např. dnešek), vzestupně.
     *
     * @return list<string>
     */
    public static function dates(string $from, string $to): array
    {
        $f = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $t = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($f === false || $t === false || $f->format('Y-m-d') !== $from || $t->format('Y-m-d') !== $to) {
            throw new StockException('validation_failed', 'Datum musí mít tvar RRRR-MM-DD.', 422, ['field' => 'from']);
        }
        if ($f > $t) {
            throw new StockException('validation_failed', 'Počáteční datum je po koncovém.', 422, ['field' => 'from']);
        }
        $out = [];
        for ($m = $f->modify('last day of this month'); $m <= $t; $m = $m->modify('first day of next month')->modify('last day of this month')) {
            $out[] = $m->format('Y-m-d');
            if (count($out) > self::MAX_DATES) {
                break;
            }
        }
        if ($out === [] || end($out) !== $to) {
            $out[] = $to;
        }
        if (count($out) > self::MAX_DATES) {
            throw new StockException('validation_failed', 'Zvolené období má víc než ' . self::MAX_DATES . ' dat (konce měsíců a koncové datum). Zkraťte ho.', 422, ['field' => 'to']);
        }
        return $out;
    }

    public const KIND = 'stock_value_trend';

    /**
     * Zařadí výpočet na pozadí (stejná infrastruktura jako ocenění k datu). Seskupení
     * (`$grouped`, kategorie `$parentId`) se určí při zadání podle práv žadatele a patří
     * k úloze; výsledek jiného uživatele bez práva ke katalogu skupiny nedostane
     * (viz {@see result()}).
     *
     * @param list<string> $dates vzestupně, bez duplicit
     * @return array<string,mixed> úloha
     */
    public function enqueue(int $supplierId, array $dates, ?int $warehouseId, ?int $parentId, bool $grouped, ?int $userId = null): array
    {
        if ($dates === [] || count($dates) > self::MAX_DATES) {
            throw new StockException('validation_failed', 'Neplatný počet dat.', 422, ['field' => 'to']);
        }
        if ($grouped) {
            $this->categoryGroups->plan($supplierId, $parentId, []); // neznámá kategorie = 422
        }
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }
        try {
            $versions = $this->snapshots->versions($supplierId, $warehouseId !== null ? [$warehouseId] : null);
            $this->warehouses->lockForStockOperation($supplierId, array_keys($versions));
            $versions = $this->snapshots->versions($supplierId, array_keys($versions));
            // Stejné zadání nad stejným stavem skladu se nepočítá znovu (jako u ocenění k datu).
            $input = [
                'dates' => $dates, 'warehouse_id' => $warehouseId, 'parent_id' => $parentId,
                'grouped' => $grouped, 'source_versions' => $versions,
            ];
            foreach ($this->jobs->history($supplierId, PHP_INT_MAX, 30, [self::KIND]) as $candidate) {
                if (in_array($candidate['status'], ['queued', 'running', 'completed'], true) && !$candidate['cancel_requested']
                    && $candidate['input'] == $input) {
                    if ($own) {
                        $pdo->commit();
                    }
                    return $candidate + ['reused' => true]; // příznak pro volajícího: úlohu založil někdo jiný
                }
            }
            $jobId = $this->jobs->enqueue($supplierId, self::KIND, $input, 0, 1, $userId);
            $this->fillQueue($supplierId, $jobId, end($dates), $warehouseId);
            $job = $this->jobs->find($supplierId, $jobId) + ['reused' => false];
            if ($own) {
                $pdo->commit();
            }
            return $job;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Seznam dvojic (sklad, karta) s pohybem do posledního data. Tabulku řádků ocenění
     * používáme jen jako frontu karet; řádky se mažou po dokončení, chybě i zrušení.
     * `total` úlohy se nikdy nesnižuje (checkpoint úlohy klesat nesmí).
     */
    private function fillQueue(int $supplierId, int $jobId, string $last, ?int $warehouseId): void
    {
        $legA = $warehouseId !== null ? ' AND d.warehouse_id = ?' : '';
        $legB = $warehouseId !== null ? ' AND d.warehouse_to_id = ?' : '';
        $params = [$jobId, $supplierId, $supplierId, $last];
        if ($warehouseId !== null) {
            $params[] = $warehouseId;
        }
        array_push($params, $supplierId, $last);
        if ($warehouseId !== null) {
            $params[] = $warehouseId;
        }
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO stock_valuation_rows (job_id, supplier_id, warehouse_id, stock_item_id)
            SELECT ?, ?, wh, item FROM (
                SELECT d.warehouse_id AS wh, l.stock_item_id AS item FROM stock_document_lines l
                JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
                WHERE l.supplier_id = ? AND l.doc_date <= ? AND d.status IN ('posted','reversed'){$legA}
                UNION
                SELECT d.warehouse_to_id, l.stock_item_id FROM stock_document_lines l
                JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
                WHERE l.supplier_id = ? AND l.doc_date <= ? AND d.status IN ('posted','reversed') AND d.doc_type = 'transfer'{$legB}
            ) legs")->execute($params);
        $pdo->prepare('UPDATE catalog_jobs SET total = GREATEST(total, (SELECT COUNT(DISTINCT stock_item_id) FROM stock_valuation_rows WHERE job_id = ? AND supplier_id = ?))
            WHERE id = ? AND supplier_id = ?')->execute([$jobId, $supplierId, $jobId, $supplierId]);
    }

    public function dropQueue(int $supplierId, int $jobId): void
    {
        $this->db->pdo()->prepare('DELETE FROM stock_valuation_rows WHERE supplier_id = ? AND job_id = ?')->execute([$supplierId, $jobId]);
    }

    public function tick(int $supplierId, int $maxBatches = 10): ?array
    {
        $job = $this->jobs->claim($supplierId, self::KIND);
        if ($job === null) {
            return null;
        }
        $token = $job['lease_token'];
        try {
            for ($i = 0; $i < max(1, min(100, $maxBatches)); $i++) {
                $job = $this->jobs->batch($supplierId, $job['id'], $token, fn (array $current): array => $this->processBatch($supplierId, $current));
                if ($job['status'] !== 'running') {
                    if ($job['status'] === 'cancelled') {
                        $this->dropQueue($supplierId, $job['id']);
                    }
                    return $job;
                }
            }
            $this->jobs->release($supplierId, $job['id'], $token);
            return $this->jobs->find($supplierId, $job['id']);
        } catch (\Throwable $e) {
            // Frontu mažeme jen když úlohu selhala nám; při ztracené lease ji drží jiný worker.
            if ($this->jobs->fail($supplierId, $job['id'], $token, $e instanceof StockException ? $e->errorCode : 'stock_value_trend_failed')) {
                $this->dropQueue($supplierId, $job['id']);
            }
            throw $e;
        }
    }

    /**
     * Jedna dávka = až `chunkItems` karet; průběžné součty se ukládají do reportu úlohy.
     * Změní-li se sklad (`valuation_version` roste při každém pohybu kdekoli ve skladu, nejde
     * ho omezit na data sestavy), výpočet se sám začne od začátku, nejvýše {@see MAX_RESTARTS}krát.
     */
    private function processBatch(int $supplierId, array $job): array
    {
        $input = $job['input'];
        $dates = $input['dates'];
        $warehouseIds = array_map('intval', array_keys($input['source_versions']));
        $this->warehouses->lockForStockOperation($supplierId, $warehouseIds);
        $pdo = $this->db->pdo();
        $report = $job['report'];
        $restarts = (int) ($report['restarts'] ?? 0);
        $cursor = (int) ($report['cursor'] ?? 0);
        $current = $this->snapshots->versions($supplierId, $warehouseIds);
        $queued = $pdo->prepare('SELECT 1 FROM stock_valuation_rows WHERE supplier_id = ? AND job_id = ? LIMIT 1');
        $queued->execute([$supplierId, $job['id']]);
        $hasQueue = $queued->fetchColumn() !== false;
        $changed = $current != $input['source_versions'];
        $lostQueue = !$hasQueue && $cursor > 0; // opakování po chybě: fronta byla smazána
        if ($changed || $lostQueue) {
            if ($changed && $restarts >= self::MAX_RESTARTS) {
                throw new StockException('stock_value_trend_stale', 'Během výpočtu se změnil sklad, spusťte výpočet znovu.', 409);
            }
            $input['source_versions'] = $current;
            $pdo->prepare('UPDATE catalog_jobs SET input_json = ? WHERE supplier_id = ? AND id = ?')
                ->execute([json_encode($input, JSON_THROW_ON_ERROR), $supplierId, $job['id']]);
            $this->dropQueue($supplierId, $job['id']);
            $this->fillQueue($supplierId, $job['id'], end($dates), $input['warehouse_id']);
            return ['checkpoint' => (int) $job['checkpoint'], 'done' => false, 'report' => [
                'cursor' => 0, 'restarts' => $changed ? $restarts + 1 : 0,
                'sums' => array_fill(0, count($dates), []), 'counts' => array_fill(0, count($dates), []),
            ]];
        }
        if (!$hasQueue) {
            $this->fillQueue($supplierId, $job['id'], end($dates), $input['warehouse_id']); // první dávka po chybě
        }
        $stmt = $pdo->prepare('SELECT DISTINCT stock_item_id FROM stock_valuation_rows WHERE supplier_id = ? AND job_id = ? AND stock_item_id > ?
            ORDER BY stock_item_id LIMIT ' . $this->chunkItems);
        $stmt->execute([$supplierId, $job['id'], $cursor]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $acc = [
            'plan' => $input['grouped'] && $ids !== [] ? $this->categoryGroups->plan($supplierId, $input['parent_id'], $ids) : null,
            'sums' => $report['sums'] ?? array_fill(0, count($dates), []),
            'counts' => $report['counts'] ?? array_fill(0, count($dates), []),
        ];
        if ($ids !== []) {
            $this->replayChunk($supplierId, $ids, $dates, $input['warehouse_id'], $acc);
        }
        $done = count($ids) < $this->chunkItems;
        $checkpoint = $done ? (int) $job['total'] : min((int) $job['total'], max((int) $job['checkpoint'], (int) $job['checkpoint'] + count($ids)));
        if ($done) {
            $this->dropQueue($supplierId, $job['id']);
        }
        return ['checkpoint' => $checkpoint, 'done' => $done, 'report' => [
            'cursor' => $ids === [] ? $cursor : end($ids), 'restarts' => $restarts, 'sums' => $acc['sums'], 'counts' => $acc['counts'],
        ]];
    }

    /**
     * Hotový výsledek je snímek ke dni dokončení (`computed_at`): zůstává čitelný a
     * exportovatelný i po dalších pohybech skladu. Skupiny po kategoriích dostane jen
     * žadatel s právem číst katalog a jen když byla úloha zadaná se seskupením; součty jsou vždy.
     * Kategorie smazané nebo přesunuté během výpočtu spadnou do „Bez kategorie“,
     * takže součet skupin vždy sedí s celkem.
     *
     * @return array<string,mixed>
     */
    public function result(int $supplierId, int $jobId, bool $withGroups): array
    {
        $job = $this->jobs->find($supplierId, $jobId);
        if ($job === null || $job['kind'] !== self::KIND) {
            throw new StockException('not_found', 'Výpočet nenalezen.', 404);
        }
        if ($job['status'] !== 'completed') {
            throw new StockException('stock_value_trend_not_ready', 'Výpočet dosud není dokončen.', 409);
        }
        $input = $job['input'];
        $dates = $input['dates'];
        $sums = $job['report']['sums'];
        $counts = $job['report']['counts'];
        $totals = [];
        foreach ($dates as $k => $date) {
            $totals[$date] = ['value_total' => StockValuation::cToDecimal(array_sum($sums[$k])), 'count' => array_sum($counts[$k])];
        }
        $groups = [];
        $parent = null;
        if ($withGroups && $input['grouped']) {
            $plan = $this->categoryGroups->plan($supplierId, $input['parent_id'], []);
            $parent = $plan['parent'] !== null ? ['id' => $plan['parent']['id'], 'code' => $plan['parent']['code'], 'name' => $plan['parent']['name']] : null;
            $valid = ['uncategorized' => true] + ($parent !== null ? ['parent' => true] : []);
            foreach ($plan['categories'] as $id => $category) {
                if ($category['parent_id'] === ($parent['id'] ?? null)) {
                    $valid[$id] = true;
                }
            }
            $unionCounts = [];
            foreach ($dates as $k => $date) {
                foreach ($counts[$k] as $key => $n) {
                    $to = isset($valid[$key]) ? $key : 'uncategorized';
                    if ($to !== $key) {
                        $sums[$k][$to] = ($sums[$k][$to] ?? 0) + $sums[$k][$key];
                        $counts[$k][$to] = ($counts[$k][$to] ?? 0) + $n;
                        unset($sums[$k][$key], $counts[$k][$key]);
                    }
                    $unionCounts[$to] = ($unionCounts[$to] ?? 0) + $n;
                }
            }
            foreach ($this->categoryGroups->build($plan, $unionCounts, $unionCounts) as $g) {
                $key = $g['kind'] === 'category' ? $g['category_id'] : $g['kind'];
                $values = [];
                foreach ($dates as $k => $date) {
                    $values[$date] = StockValuation::cToDecimal($sums[$k][$key] ?? 0);
                }
                $groups[] = ['kind' => $g['kind'], 'category_id' => $g['category_id'], 'code' => $g['code'], 'name' => $g['name'], 'values' => $values];
            }
        }
        return ['job_id' => $jobId, 'computed_at' => $job['finished_at'], 'dates' => $dates, 'parent' => $parent, 'groups' => $groups, 'totals' => $totals];
    }

    /**
     * @param list<int> $itemIds
     * @param list<string> $dates
     * @param array<string,mixed> $acc průběžné součty, viz report()
     */
    private function replayChunk(int $supplierId, array $itemIds, array $dates, ?int $warehouseId, array &$acc): void
    {
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $legA = $warehouseId !== null ? ' AND d.warehouse_id = ?' : '';
        $legB = $warehouseId !== null ? ' AND d.warehouse_to_id = ?' : '';
        // Dvě nohy: sklad dokladu (příjem +, ostatní −) a cílový sklad převodu (+).
        $sql = "SELECT * FROM (
            SELECT l.stock_item_id, d.warehouse_id AS wh, l.id AS line_id, l.document_id, d.status, l.doc_date,
                l.ledger_booked_at AS booked_at, l.line_no, l.qty, l.value_total,
                CASE WHEN d.doc_type = 'receipt' THEN 1 ELSE -1 END AS direction,
                EXISTS(SELECT 1 FROM stock_documents o WHERE o.supplier_id = d.supplier_id AND o.reversal_document_id = d.id) AS is_reversal
            FROM stock_document_lines l JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
            WHERE l.supplier_id = ? AND l.doc_date <= ? AND d.status IN ('posted','reversed') AND l.stock_item_id IN ({$in}){$legA}
            UNION ALL
            SELECT l.stock_item_id, d.warehouse_to_id AS wh, l.id, l.document_id, d.status, l.doc_date,
                l.ledger_booked_at, l.line_no, l.qty, l.value_total, 1,
                EXISTS(SELECT 1 FROM stock_documents o WHERE o.supplier_id = d.supplier_id AND o.reversal_document_id = d.id)
            FROM stock_document_lines l JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
            WHERE l.supplier_id = ? AND l.doc_date <= ? AND d.status IN ('posted','reversed') AND d.doc_type = 'transfer' AND l.stock_item_id IN ({$in}){$legB}
        ) x ORDER BY stock_item_id, wh, doc_date, booked_at, document_id, line_no, line_id";
        $last = end($dates);
        $params = [$supplierId, $last, ...$itemIds];
        if ($warehouseId !== null) {
            $params[] = $warehouseId;
        }
        array_push($params, $supplierId, $last, ...$itemIds);
        if ($warehouseId !== null) {
            $params[] = $warehouseId;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        $key = null;
        $qtyT = $valueC = $k = 0;
        $itemId = 0;
        $groupOf = $acc['plan']['groupOf'] ?? null;
        $flush = function (int $upTo) use (&$k, &$qtyT, &$valueC, &$itemId, &$acc, $groupOf): void {
            for (; $k < $upTo; $k++) {
                if ($qtyT !== 0 || $valueC !== 0) {
                    $key = $acc['plan'] === null ? 'all' : ($groupOf[$itemId] ?? 'uncategorized');
                    $acc['sums'][$k][$key] = ($acc['sums'][$k][$key] ?? 0) + $valueC;
                    $acc['counts'][$k][$key] = ($acc['counts'][$k][$key] ?? 0) + 1;
                }
            }
        };
        $n = count($dates);
        while (($line = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $lineKey = $line['stock_item_id'] . ':' . $line['wh'];
            if ($lineKey !== $key) {
                $flush($n);
                $key = $lineKey;
                $itemId = (int) $line['stock_item_id'];
                $qtyT = $valueC = $k = 0;
            }
            while ($k < $n && $line['doc_date'] > $dates[$k]) {
                $flush($k + 1);
            }
            $state = StockLedgerReplay::advance($qtyT, $valueC, $line);
            $qtyT = $state['qtyT'];
            $valueC = $state['valueC'];
        }
        $flush($n);
    }
}
