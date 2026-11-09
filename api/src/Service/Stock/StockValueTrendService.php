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
 * všechna data a stav se zaznamená při přechodu každého data. Dávka je omezená počtem
 * pohybů (jako u {@see StockValuationJobService}): fronta dvojic (sklad, karta) se spočítá
 * s počtem pohybů, malé karty se čtou po skupinách jedním dotazem, karta s obří historií
 * po stránkách s kurzorem uvnitř karty. Zařazení do kategorií řeší
 * {@see StockValuationCategoryGroups} (dnešní kategorie karet).
 */
final class StockValueTrendService
{
    public const MAX_DATES = 36;
    public const MAX_RESTARTS = 2;
    private const BATCH_ITEMS = 500;
    private const FILL_ITEMS = 5000;

    public function __construct(
        private readonly Connection $db,
        private readonly StockValuationCategoryGroups $categoryGroups,
        private readonly \MyInvoice\Service\Eshop\CatalogJobService $jobs,
        private readonly \MyInvoice\Repository\StockValuationSnapshotRepository $snapshots,
        private readonly \MyInvoice\Repository\WarehouseRepository $warehouses,
        private readonly StockLedgerReader $ledger,
        /** Strop pohybů načtených jednou dávkou (jako BATCH_SIZE u ocenění k datu). */
        private readonly int $batchMovements = 2000,
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
     * Zařadí výpočet na pozadí. Nic těžkého se tu nepočítá: frontu karet naplní až worker
     * v první dávce. Seskupení (`$grouped`, kategorie `$parentId`) se určí při zadání podle práv
     * žadatele a patří k úloze; výsledek jiného uživatele bez práva ke katalogu skupiny nedostane
     * (viz {@see result()}). Stejné zadání nad stejným stavem skladu vrátí existující úlohu.
     *
     * @param list<string> $dates vzestupně, bez duplicit
     * @return array<string,mixed> úloha (`reused` = vrácena už existující)
     */
    public function enqueue(int $supplierId, array $dates, ?int $warehouseId, ?int $parentId, bool $grouped, ?int $userId = null): array
    {
        if ($dates === [] || count($dates) > self::MAX_DATES) {
            throw new StockException('validation_failed', 'Neplatný počet dat.', 422, ['field' => 'to']);
        }
        if ($grouped) {
            $this->categoryGroups->plan($supplierId, $parentId, []); // neznámá kategorie = 422
        }
        $versions = $this->snapshots->versions($supplierId, $warehouseId !== null ? [$warehouseId] : null);
        $input = [
            'dates' => $dates, 'warehouse_id' => $warehouseId, 'parent_id' => $parentId,
            'grouped' => $grouped, 'source_versions' => $versions,
        ];
        foreach ($this->jobs->history($supplierId, PHP_INT_MAX, 30, [self::KIND]) as $candidate) {
            if (in_array($candidate['status'], ['queued', 'running', 'completed'], true) && !$candidate['cancel_requested']
                && $candidate['input'] == $input) {
                return $candidate + ['reused' => true]; // zrušit ji smí jen ten, kdo ji založil
            }
        }
        $jobId = $this->jobs->enqueue($supplierId, self::KIND, $input, 0, 1, $userId);
        return $this->jobs->find($supplierId, $jobId) + ['reused' => false];
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
            $this->abort($supplierId, $job['id'], $token, $e);
            throw $e;
        }
    }

    /** Ukončí úlohu chybou a smaže frontu, ale jen když úloha ještě patří nám (při ztracené lease ji drží jiný worker). */
    private function abort(int $supplierId, int $jobId, string $token, \Throwable $e): bool
    {
        $failed = $this->jobs->fail($supplierId, $jobId, $token, $e instanceof StockException ? $e->errorCode : 'stock_value_trend_failed');
        if ($failed) {
            $this->dropQueue($supplierId, $jobId);
        }
        return $failed;
    }

    /**
     * Jedna dávka. Fáze: `init` (čas začátku a stav skladu), `fill` (fronta dvojic s počty
     * pohybů po oknech karet, bez zámku skladu), `compute` (nejvýše `batchMovements` pohybů
     * pod zámkem skladů). Změna skladu se řeší jen když přibyl nebo se změnil doklad s datem do
     * posledního data sestavy (ve filtrovaném skladu jen jeho, viz {@see fingerprint()}): pak se výpočet nejvýše
     * {@see MAX_RESTARTS}krát začne znovu. Opakování po chybě nebo zrušení začíná znovu (fronta je smazaná).
     */
    private function processBatch(int $supplierId, array $job): array
    {
        $input = $job['input'];
        $dates = $input['dates'];
        $last = end($dates);
        $report = $job['report'];
        $pdo = $this->db->pdo();
        $warehouseIds = array_map('intval', array_keys($input['source_versions']));

        if (($report['phase'] ?? null) === null) {
            return $this->restart($supplierId, $job, 0, 0);
        }
        if ($report['phase'] === 'fill') {
            $stmt = $pdo->prepare('SELECT id FROM stock_items WHERE supplier_id = ? AND id > ? ORDER BY id LIMIT ' . self::FILL_ITEMS);
            $stmt->execute([$supplierId, (int) $report['fill_cursor']]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if ($ids !== []) {
                $this->fillQueue($supplierId, $job['id'], $ids[0], end($ids), $last, $input['warehouse_id']);
                $report['fill_cursor'] = end($ids);
            } else {
                $report['phase'] = 'compute';
                $pdo->prepare('UPDATE catalog_jobs SET total = GREATEST(total, (SELECT COUNT(*) FROM stock_valuation_rows WHERE job_id = ? AND supplier_id = ?))
                    WHERE id = ? AND supplier_id = ?')->execute([$job['id'], $supplierId, $job['id'], $supplierId]);
            }
            return ['checkpoint' => (int) $job['checkpoint'], 'done' => false, 'report' => $report];
        }

        $this->warehouses->lockForStockOperation($supplierId, $warehouseIds);
        $restarts = (int) $report['restarts'];
        $queued = $pdo->prepare('SELECT 1 FROM stock_valuation_rows WHERE supplier_id = ? AND job_id = ? LIMIT 1');
        $queued->execute([$supplierId, $job['id']]);
        if ($queued->fetchColumn() === false && (int) $job['total'] > 0) {
            return $this->restart($supplierId, $job, 0, 0); // fronta byla smazaná (opakování po chybě nebo zrušení)
        }
        $current = $this->snapshots->versions($supplierId, $warehouseIds);
        if ($current != $input['source_versions']) {
            if ($this->fingerprint($supplierId, $last, $input['warehouse_id']) !== $report['fp']) {
                if ($restarts >= self::MAX_RESTARTS) {
                    throw new StockException('stock_value_trend_stale', 'Během výpočtu se změnil sklad, spusťte výpočet znovu.', 409);
                }
                return $this->restart($supplierId, $job, $restarts + 1, (int) $job['checkpoint']);
            }
            $input['source_versions'] = $current; // pohyb po datu sestavy výsledek nemění
            $pdo->prepare('UPDATE catalog_jobs SET input_json = ? WHERE supplier_id = ? AND id = ?')
                ->execute([json_encode($input, JSON_THROW_ON_ERROR), $supplierId, $job['id']]);
        }

        // Rozpracovaná karta s obří historií má přednost, jinak okno malých karet do stropu pohybů.
        $open = $pdo->prepare('SELECT * FROM stock_valuation_rows WHERE supplier_id = ? AND job_id = ? AND started = 1 AND completed = 0 LIMIT 1 FOR UPDATE');
        $open->execute([$supplierId, $job['id']]);
        $row = $open->fetch(PDO::FETCH_ASSOC) ?: null;
        $window = [];
        if ($row === null) {
            $groups = $pdo->prepare('SELECT stock_item_id, SUM(movements) AS m FROM stock_valuation_rows WHERE supplier_id = ? AND job_id = ? AND completed = 0
                GROUP BY stock_item_id ORDER BY stock_item_id LIMIT ' . self::BATCH_ITEMS);
            $groups->execute([$supplierId, $job['id']]);
            $sum = 0;
            foreach ($groups->fetchAll(PDO::FETCH_ASSOC) as $g) {
                if ($window !== [] && $sum + (int) $g['m'] > $this->batchMovements) {
                    break;
                }
                if ($window === [] && (int) $g['m'] > $this->batchMovements) {
                    $first = $pdo->prepare('SELECT * FROM stock_valuation_rows WHERE supplier_id = ? AND job_id = ? AND stock_item_id = ? AND completed = 0 ORDER BY warehouse_id LIMIT 1 FOR UPDATE');
                    $first->execute([$supplierId, $job['id'], $g['stock_item_id']]);
                    $row = $first->fetch(PDO::FETCH_ASSOC);
                    break;
                }
                $window[] = (int) $g['stock_item_id'];
                $sum += (int) $g['m'];
            }
        }

        $acc = ['sums' => $report['sums'], 'counts' => $report['counts'], 'plan' => null];
        $completed = 0;
        if ($row !== null) {
            $completed = $this->stepLargeCard($supplierId, $job, $row, $acc);
        } elseif ($window !== []) {
            $completed = $this->stepWindow($supplierId, $job, $window, $acc);
        }
        $remaining = $pdo->prepare('SELECT 1 FROM stock_valuation_rows WHERE supplier_id = ? AND job_id = ? AND completed = 0 LIMIT 1');
        $remaining->execute([$supplierId, $job['id']]);
        $done = $remaining->fetchColumn() === false;
        if ($done) {
            $this->dropQueue($supplierId, $job['id']);
        }
        $report['sums'] = $acc['sums'];
        $report['counts'] = $acc['counts'];
        return [
            'checkpoint' => $done ? (int) $job['total'] : min((int) $job['total'], (int) $job['checkpoint'] + $completed),
            'done' => $done, 'report' => $report,
        ];
    }

    /** Začátek nebo restart: nový čas začátku, aktuální stav skladu, prázdná fronta i součty. */
    private function restart(int $supplierId, array $job, int $restarts, int $checkpoint): array
    {
        $input = $job['input'];
        $pdo = $this->db->pdo();
        $input['source_versions'] = $this->snapshots->versions($supplierId, array_map('intval', array_keys($input['source_versions'])));
        $pdo->prepare('UPDATE catalog_jobs SET input_json = ? WHERE supplier_id = ? AND id = ?')
            ->execute([json_encode($input, JSON_THROW_ON_ERROR), $supplierId, $job['id']]);
        $this->dropQueue($supplierId, $job['id']);
        $dates = $input['dates'];
        $fp = $this->fingerprint($supplierId, end($dates), $input['warehouse_id']);
        $n = count($dates);
        return ['checkpoint' => $checkpoint, 'done' => false, 'report' => [
            'phase' => 'fill', 'fill_cursor' => 0, 'fp' => $fp, 'restarts' => $restarts,
            'sums' => array_fill(0, $n, []), 'counts' => array_fill(0, $n, []),
        ]];
    }

    /**
     * Otisk dokladů, které ovlivňují výsledek: zaúčtované a stornované doklady s datem do posledního
     * data sestavy (u filtru skladu jen jeho). Přibyl-li doklad, nebo byl některý stornován či změněn,
     * otisk se změní; pohyb po datu sestavy ho nemění. Dotaz se spouští jen po změně `valuation_version`.
     */
    private function fingerprint(int $supplierId, string $last, ?int $warehouseId): string
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*), COALESCE(SUM(UNIX_TIMESTAMP(d.updated_at)), 0), COALESCE(SUM(d.status = 'reversed'), 0)
            FROM stock_documents d WHERE d.supplier_id = ? AND d.status IN ('posted','reversed') AND d.doc_date <= ?"
            . ($warehouseId !== null ? ' AND (d.warehouse_id = ? OR d.warehouse_to_id = ?)' : ''));
        $stmt->execute(array_merge([$supplierId, $last], $warehouseId !== null ? [$warehouseId, $warehouseId] : []));
        return implode(':', $stmt->fetch(PDO::FETCH_NUM));
    }

    /**
     * Dvojice (sklad, karta) s pohybem do data `$last` pro karty s id v rozmezí; `movements` nese
     * počet pohybů (podle něj se dávky omezují). Probíhá ve workeru po oknech karet a bez zámku skladu.
     */
    private function fillQueue(int $supplierId, int $jobId, int $fromItem, int $toItem, string $last, ?int $warehouseId): void
    {
        $legA = $warehouseId !== null ? ' AND d.warehouse_id = ?' : '';
        $legB = $warehouseId !== null ? ' AND d.warehouse_to_id = ?' : '';
        $params = [$jobId, $supplierId, $supplierId, $fromItem, $toItem, $last];
        if ($warehouseId !== null) {
            $params[] = $warehouseId;
        }
        array_push($params, $supplierId, $fromItem, $toItem, $last);
        if ($warehouseId !== null) {
            $params[] = $warehouseId;
        }
        $this->db->pdo()->prepare("INSERT INTO stock_valuation_rows (job_id, supplier_id, warehouse_id, stock_item_id, movements)
            SELECT ?, ?, wh, item, COUNT(*) FROM (
                SELECT d.warehouse_id AS wh, l.stock_item_id AS item FROM stock_document_lines l
                JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
                WHERE l.supplier_id = ? AND l.stock_item_id BETWEEN ? AND ? AND l.doc_date <= ? AND d.status IN ('posted','reversed'){$legA}
                UNION ALL
                SELECT d.warehouse_to_id, l.stock_item_id FROM stock_document_lines l
                JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
                WHERE l.supplier_id = ? AND l.stock_item_id BETWEEN ? AND ? AND l.doc_date <= ? AND d.status IN ('posted','reversed') AND d.doc_type = 'transfer'{$legB}
            ) legs GROUP BY wh, item")->execute($params);
    }

    /** Okno malých karet: jeden dotaz do knihy, všechny dvojice se dopočítají a uzavřou. @return int počet uzavřených dvojic */
    private function stepWindow(int $supplierId, array $job, array $itemIds, array &$acc): int
    {
        $input = $job['input'];
        $dates = $input['dates'];
        $acc['plan'] = $input['grouped'] ? $this->categoryGroups->plan($supplierId, $input['parent_id'], $itemIds) : null;
        $in = implode(',', array_map('intval', $itemIds));
        $pending = $this->db->pdo()->prepare("SELECT stock_item_id, warehouse_id FROM stock_valuation_rows WHERE supplier_id = ? AND job_id = ? AND completed = 0 AND stock_item_id IN ({$in})");
        $pending->execute([$supplierId, $job['id']]);
        $open = [];
        foreach ($pending->fetchAll(PDO::FETCH_NUM) as [$item, $wh]) {
            $open[$item . ':' . $wh] = true; // dvojice už uzavřené po stránkách (karta s obří historií v jiném skladu) se nepočítají znovu
        }
        $pairs = [];
        foreach ($this->ledger->linesForItems($supplierId, $itemIds, end($dates), $input['warehouse_id']) as $line) {
            $key = $line['item_id'] . ':' . $line['wh'];
            if (!isset($open[$key])) {
                continue;
            }
            $pairs[$key]['item'] = (int) $line['item_id'];
            $pairs[$key]['lines'][] = $line;
        }
        foreach ($pairs as $pair) {
            $qtyT = $valueC = $k = 0;
            foreach ($pair['lines'] as $line) {
                while ($k < count($dates) && $line['doc_date'] > $dates[$k]) {
                    $this->record($acc, $k++, $pair['item'], $qtyT, $valueC);
                }
                $state = StockLedgerReplay::advance($qtyT, $valueC, $line);
                $qtyT = $state['qtyT'];
                $valueC = $state['valueC'];
            }
            while ($k < count($dates)) {
                $this->record($acc, $k++, $pair['item'], $qtyT, $valueC);
            }
        }
        $stmt = $this->db->pdo()->prepare("UPDATE stock_valuation_rows SET started = 1, completed = 1 WHERE supplier_id = ? AND job_id = ? AND completed = 0 AND stock_item_id IN ({$in})");
        $stmt->execute([$supplierId, $job['id']]);
        return $stmt->rowCount();
    }

    /** Dvojice s obří historií: stránka pohybů s kurzorem, stav (množství, hodnota, index data) se ukládá do řádku. @return int 1 při uzavření */
    private function stepLargeCard(int $supplierId, array $job, array $row, array &$acc): int
    {
        $input = $job['input'];
        $dates = $input['dates'];
        $itemId = (int) $row['stock_item_id'];
        $acc['plan'] = $input['grouped'] ? $this->categoryGroups->plan($supplierId, $input['parent_id'], [$itemId]) : null;
        $state = $row['cursor_json'] !== null ? json_decode($row['cursor_json'], true, 512, JSON_THROW_ON_ERROR) : ['c' => null, 'k' => 0];
        $qtyT = StockValuation::qtyToT((string) $row['qty']);
        $valueC = StockValuation::valueToC((string) $row['value_total']);
        $k = (int) $state['k'];
        $cursor = $state['c'];
        $lines = $this->ledger->page($supplierId, (int) $row['warehouse_id'], $itemId, end($dates), null, $cursor, $this->batchMovements);
        foreach ($lines as $line) {
            while ($k < count($dates) && $line['doc_date'] > $dates[$k]) {
                $this->record($acc, $k++, $itemId, $qtyT, $valueC);
            }
            $advanced = StockLedgerReplay::advance($qtyT, $valueC, $line);
            $qtyT = $advanced['qtyT'];
            $valueC = $advanced['valueC'];
            $cursor = StockLedgerReader::cursor($line);
        }
        $complete = count($lines) < $this->batchMovements;
        if ($complete) {
            while ($k < count($dates)) {
                $this->record($acc, $k++, $itemId, $qtyT, $valueC);
            }
        }
        $this->db->pdo()->prepare('UPDATE stock_valuation_rows SET started = 1, completed = ?, cursor_json = ?, qty = ?, value_total = ?
            WHERE supplier_id = ? AND job_id = ? AND warehouse_id = ? AND stock_item_id = ?')
            ->execute([(int) $complete, json_encode(['c' => $cursor, 'k' => $k], JSON_THROW_ON_ERROR),
                StockValuation::tToDecimal($qtyT), StockValuation::cToDecimal($valueC), $supplierId, $job['id'], $row['warehouse_id'], $itemId]);
        return $complete ? 1 : 0;
    }

    /** Přičte stav dvojice k datu (index `$k`) do součtů skupiny karty; nulový stav se vynechá. */
    private function record(array &$acc, int $k, int $itemId, int $qtyT, int $valueC): void
    {
        if ($qtyT === 0 && $valueC === 0) {
            return;
        }
        $key = $acc['plan'] === null ? 'all' : ($acc['plan']['groupOf'][$itemId] ?? 'uncategorized');
        $acc['sums'][$k][$key] = ($acc['sums'][$k][$key] ?? 0) + $valueC;
        $acc['counts'][$k][$key] = ($acc['counts'][$k][$key] ?? 0) + 1;
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
}
