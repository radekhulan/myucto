<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Import\CatalogImportSourceStore;
use PDO;

/**
 * Import historických skladových dokladů z jiného skladového programu (vzor importu
 * počátečních zásob: zdroj → náhled → aplikace, obojí jako úloha na pozadí).
 *
 * Dva režimy podle `cutover_date` (D):
 *  - historie před stavem v MyÚčtu: firma už má skladové doklady od D (typicky příjemku
 *    počátečních zásob). Historie musí skončit před D a k D−1 ji uzavře vyrovnávací
 *    výdejka celého zůstatku, takže stavy, ocenění i doklady od D zůstanou beze změny,
 *  - historie je živý stav (bez D): firma žádné skladové doklady nemá a stav skladu
 *    po importu je konec historie.
 *
 * Firma má nejvýš jeden aktivní import; další jde až po vrácení předchozího. Vrácení
 * smaže převzaté doklady a odemkne historii (v režimu živého stavu i vynuluje sklad).
 * Doklady se neúčtují (skladové doklady deník nikdy nezapisují), nečerpají číselnou
 * řadu, nevytvářejí rezervace ani nemění faktury, jen se na ně odkazují.
 */
final class StockHistoryImportService
{
    public const STAGE_KIND = 'stock_history_import_stage';
    public const APPLY_KIND = 'stock_history_import_apply';
    public const ROLLBACK_KIND = 'stock_history_import_rollback';
    /** Zámek celé historie skladu po dobu převzetí nebo vrácení bez data přechodu. */
    public const LOCK_RUNNING = '9999-12-31';

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly CatalogImportSourceStore $sources,
    ) {}

    public function preview(int $supplierId, int $sourceId, array $config, ?int $createdBy): array
    {
        $source = $this->sources->get($supplierId, $sourceId);
        $profile = StockHistoryImportProfile::normalize($config);
        $this->assertMode($supplierId, $profile);
        $id = $this->jobs->enqueue($supplierId, self::STAGE_KIND, [
            'source_id' => $sourceId,
            'source_sha256' => $source['sha256'],
            'profile' => $profile,
        ], StockHistoryImportProfile::MAX_ROWS, createdBy: $createdBy);
        return $this->jobs->find($supplierId, $id);
    }

    public function apply(int $supplierId, int $previewId, ?int $createdBy, bool $confirmClosedPeriods = false): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Stock history apply requires its own transaction.');
        }
        $pdo->beginTransaction();
        try {
            // Zámek firmy serializuje souběžná spuštění (jeden aktivní import na firmu).
            $pdo->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE')->execute([$supplierId]);
            $stmt = $pdo->prepare('SELECT id FROM catalog_jobs WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $stmt->execute([$supplierId, $previewId]);
            $source = $stmt->fetchColumn() === false ? null : $this->jobs->find($supplierId, $previewId);
            if ($source === null || $source['kind'] !== self::STAGE_KIND) {
                throw new EshopException('not_found', 'Náhled importu historie nenalezen.', 404);
            }
            $report = $source['report'];
            if ($source['status'] !== 'completed' || (($report['counts']['failed'] ?? 0) + ($report['counts']['conflict'] ?? 0)) > 0) {
                throw new EshopException('job_state_conflict', 'Náhled obsahuje chyby nebo ještě není dokončen.', 409);
            }
            if (($report['counts']['ready'] ?? 0) === 0) {
                throw new EshopException('history_import_empty', 'Náhled neobsahuje žádný doklad k převzetí.', 409);
            }
            $profile = $source['input']['profile'];
            if (($report['negative_count'] ?? 0) > 0 && !$profile['fill_negative']) {
                throw new EshopException('history_import_negative_stock', 'Historie vede k zápornému stavu zásob. Doplňte chybějící příjem, nebo zapněte doplnění technickými příjemkami.', 409);
            }
            if (self::closedPeriodDocuments($pdo, $supplierId, $previewId, $profile['cutover_date']) > 0 && !$confirmClosedPeriods) {
                throw new EshopException('history_import_closed_periods_unconfirmed', 'Část dokladů spadá do uzavřeného účetního období. Potvrďte, že je chcete převzít.', 409);
            }
            $this->assertMode($supplierId, $profile);
            if ($profile['cutover_date'] !== null) {
                $this->assertSettlementNumbersFree($supplierId, $previewId, $profile['cutover_date']);
            }

            $id = $this->jobs->enqueue($supplierId, self::APPLY_KIND, $source['input'] + [
                'source_job_id' => $previewId,
                'date_from' => $report['date_from'],
                'date_to' => $report['date_to'],
                'confirm_closed_periods' => $confirmClosedPeriods,
            ], createdBy: $createdBy);
            // Pořadí aplikace = chronologie: datum, pak pořadí prvního řádku dokladu v souboru.
            // Doklady tak dostanou rostoucí id ve stejném pořadí, v jakém je čte skladová kniha.
            $stmt = $pdo->prepare("INSERT INTO catalog_job_items
                (supplier_id, job_id, ordinal, stock_item_id, source_row, input_json)
                SELECT ?, ?, ROW_NUMBER() OVER (ORDER BY doc_date, first_ordinal, ordinal), stock_item_id, source_row, input_json
                FROM (SELECT ordinal, stock_item_id, source_row, input_json,
                        JSON_VALUE(input_json, '$.values.doc_date') AS doc_date,
                        MIN(ordinal) OVER (PARTITION BY JSON_VALUE(input_json, '$.doc')) AS first_ordinal
                    FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? AND status = 'ready') staged");
            $stmt->execute([$supplierId, $id, $supplierId, $previewId]);
            $total = $stmt->rowCount();
            $pdo->prepare('UPDATE catalog_jobs SET total = ? WHERE supplier_id = ? AND id = ?')->execute([$total, $supplierId, $id]);

            $cutover = $profile['cutover_date'];
            // Bez data přechodu se do konce aplikace nesmí zaúčtovat žádný živý doklad
            // (stav skladu přepíše konec historie); na date_to se zámek sníží až na konci.
            $lockedUntil = $cutover === null ? self::LOCK_RUNNING : self::dayBefore($cutover);
            $pdo->prepare('UPDATE supplier SET stock_history_locked_until = ? WHERE id = ?')->execute([$lockedUntil, $supplierId]);
            if ($cutover !== null) {
                // Nulový snapshot k D−1: přepočet živých dokladů (zpětný doklad od D) začne
                // za historií i během importu, kdy ještě není vyrovnaná.
                $pdo->prepare("INSERT INTO stock_valuation_snapshots
                    (supplier_id, warehouse_id, stock_item_id, cutoff_date, qty, value_total, source_version)
                    SELECT ?, legs.warehouse_id, legs.stock_item_id, ?, 0, 0, w.valuation_version
                    FROM (SELECT DISTINCT CAST(JSON_VALUE(input_json, '$.values.warehouse_id') AS UNSIGNED) AS warehouse_id, stock_item_id
                            FROM catalog_job_items WHERE supplier_id = ? AND job_id = ?
                          UNION
                          SELECT DISTINCT CAST(JSON_VALUE(input_json, '$.values.warehouse_to_id') AS UNSIGNED), stock_item_id
                            FROM catalog_job_items WHERE supplier_id = ? AND job_id = ?
                              AND JSON_VALUE(input_json, '$.values.doc_type') = 'transfer') legs
                    JOIN warehouses w ON w.id = legs.warehouse_id AND w.supplier_id = ?
                    ON DUPLICATE KEY UPDATE qty = 0, value_total = 0, algorithm_version = 1, created_at = NOW(6)")
                    ->execute([$supplierId, $lockedUntil, $supplierId, $id, $supplierId, $id, $supplierId]);
            }
            $pdo->commit();
            return $this->jobs->find($supplierId, $id);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Koncový stav historie z náhledu po skladech a kartách (s technickými příjemkami,
     * když je firma zapnula) proti počátečnímu stavu v MyÚčtu k datu přechodu: na skladu,
     * kde k tomuto datu proběhl import počátečních zásob, jen jeho řádkům, jinde všem
     * příjemkám s datem přechodu. Prodej ani jiný pohyb toho dne rozdíl nedělá (před D firma
     * skladové doklady nemá). Bez data přechodu se nemá s čím porovnat, stock_qty je null.
     */
    public function endState(int $supplierId, int $previewId): array
    {
        $job = $this->jobs->find($supplierId, $previewId);
        if ($job === null || $job['kind'] !== self::STAGE_KIND) {
            throw new EshopException('not_found', 'Náhled importu historie nenalezen.', 404);
        }
        if ($job['status'] !== 'completed') {
            throw new EshopException('job_state_conflict', 'Náhled ještě není dokončen.', 409);
        }
        $profile = $job['input']['profile'];
        $fill = $profile['fill_negative'] ? "COALESCE(CAST(JSON_VALUE(input_json, '$.fill.quantity') AS DECIMAL(14,3)), 0)" : '0';
        $stmt = $this->db->pdo()->prepare("SELECT legs.wh, legs.item, w.code, i.sku, i.name, i.unit, SUM(legs.qty) AS qty
            FROM (
                SELECT CAST(JSON_VALUE(input_json, '$.values.warehouse_id') AS UNSIGNED) AS wh, stock_item_id AS item,
                    {$fill} + CASE WHEN JSON_VALUE(input_json, '$.values.doc_type') IN ('receipt','surplus') THEN 1 ELSE -1 END
                        * CAST(JSON_VALUE(input_json, '$.values.quantity') AS DECIMAL(14,3)) AS qty
                FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? AND status = 'ready'
                UNION ALL
                SELECT CAST(JSON_VALUE(input_json, '$.values.warehouse_to_id') AS UNSIGNED), stock_item_id,
                    CAST(JSON_VALUE(input_json, '$.values.quantity') AS DECIMAL(14,3))
                FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? AND status = 'ready'
                    AND JSON_VALUE(input_json, '$.values.doc_type') = 'transfer'
            ) legs
            JOIN warehouses w ON w.id = legs.wh AND w.supplier_id = ?
            JOIN stock_items i ON i.id = legs.item AND i.supplier_id = ?
            GROUP BY legs.wh, legs.item, w.code, i.sku, i.name, i.unit");
        $stmt->execute([$supplierId, $previewId, $supplierId, $previewId, $supplierId, $supplierId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[$row['wh'] . '-' . $row['item']] = [
                'warehouse_code' => $row['code'], 'sku' => $row['sku'], 'name' => $row['name'], 'unit' => $row['unit'],
                'history_qty' => StockValuation::qtyToT((string) $row['qty']), 'stock_qty' => 0,
            ];
        }
        $cutover = $profile['cutover_date'];
        if ($cutover !== null) {
            $stmt = $this->db->pdo()->prepare("SELECT d.warehouse_id, l.stock_item_id, w.code, i.sku, i.name, i.unit, SUM(l.qty) AS qty
                FROM stock_documents d
                JOIN stock_document_lines l ON l.document_id = d.id AND l.supplier_id = d.supplier_id
                JOIN warehouses w ON w.id = d.warehouse_id AND w.supplier_id = d.supplier_id
                JOIN stock_items i ON i.id = l.stock_item_id AND i.supplier_id = d.supplier_id
                WHERE d.supplier_id = ? AND d.doc_date = ? AND d.doc_type = 'receipt' AND d.status = 'posted' AND d.history_import_job_id IS NULL
                    AND (NOT EXISTS (SELECT 1 FROM external_entity_map m2
                            JOIN stock_document_lines l2 ON l2.id = m2.internal_id AND l2.supplier_id = m2.supplier_id
                            JOIN stock_documents d2 ON d2.id = l2.document_id AND d2.supplier_id = l2.supplier_id
                            WHERE m2.supplier_id = d.supplier_id AND m2.connection_id IS NULL AND m2.entity_type = 'stock_opening_line'
                                AND d2.warehouse_id = d.warehouse_id AND d2.doc_date = d.doc_date AND d2.doc_type = 'receipt' AND d2.status = 'posted')
                        OR EXISTS (SELECT 1 FROM external_entity_map m WHERE m.supplier_id = d.supplier_id AND m.connection_id IS NULL
                            AND m.entity_type = 'stock_opening_line' AND m.internal_id = l.id))
                GROUP BY d.warehouse_id, l.stock_item_id, w.code, i.sku, i.name, i.unit");
            $stmt->execute([$supplierId, $cutover]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $rows[$item['warehouse_id'] . '-' . $item['stock_item_id']] ??= [
                    'warehouse_code' => $item['code'], 'sku' => $item['sku'], 'name' => $item['name'], 'unit' => $item['unit'],
                    'history_qty' => 0,
                ];
                $rows[$item['warehouse_id'] . '-' . $item['stock_item_id']]['stock_qty'] = StockValuation::qtyToT((string) $item['qty']);
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['warehouse_code'], $a['sku']] <=> [$b['warehouse_code'], $b['sku']]);
        $differences = 0;
        $items = array_map(static function (array $row) use ($cutover, &$differences): array {
            $difference = $row['history_qty'] - $row['stock_qty'];
            $differences += $cutover !== null && $difference !== 0 ? 1 : 0;
            return [
                'warehouse_code' => $row['warehouse_code'], 'sku' => $row['sku'], 'name' => $row['name'], 'unit' => $row['unit'],
                'history_qty' => StockValuation::tToDecimal($row['history_qty']),
                'stock_qty' => $cutover !== null ? StockValuation::tToDecimal($row['stock_qty']) : null,
                'difference' => $cutover !== null ? StockValuation::tToDecimal($difference) : null,
            ];
        }, $rows);
        return ['cutover_date' => $cutover, 'items' => $items, 'totals' => ['count' => count($items), 'differences' => $differences]];
    }

    /**
     * Vrácení převzaté historie: smazání převzatých dokladů na pozadí. Bez data přechodu
     * jde jen do prvního živého dokladu, potom by vynulovaný sklad neodpovídal dokladům.
     * Vrátit jde i historii, ke které chybí úloha (obnova z archivu ji nepřenese).
     */
    public function rollback(int $supplierId, int $applyId, ?int $createdBy, bool $confirmClosedPeriods = false): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Stock history rollback requires its own transaction.');
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE')->execute([$supplierId]);
            $active = $this->active($supplierId);
            $orphan = $active === null ? $this->orphan($supplierId) : null;
            if (($active === null || $active['id'] !== $applyId) && ($orphan === null || $orphan !== $applyId)) {
                throw new EshopException('not_found', 'Aktivní import historie nenalezen.', 404);
            }
            $stmt = $pdo->prepare("SELECT 1 FROM catalog_jobs WHERE supplier_id = ? AND kind = ? AND status IN ('queued','running') LIMIT 1");
            $stmt->execute([$supplierId, self::ROLLBACK_KIND]);
            if (in_array($active['status'] ?? null, ['queued', 'running'], true) || $stmt->fetchColumn() !== false) {
                throw new EshopException('job_state_conflict', 'Import nebo jeho vrácení ještě běží.', 409);
            }
            self::assertNoOpenStockTake($this->db->pdo(), $supplierId);
            if (self::closedPeriodHistoryDocuments($pdo, $supplierId) > 0 && !$confirmClosedPeriods) {
                throw new EshopException('history_import_closed_periods_unconfirmed', 'Část převzatých dokladů spadá do uzavřeného účetního období. Potvrďte, že je chcete smazat.', 409);
            }
            $cutover = $active !== null ? $active['input']['profile']['cutover_date'] : $this->orphanCutover($supplierId);
            if ($cutover === null) {
                $this->assertNoLiveDocuments($supplierId);
                // Do konce vrácení nesmí vzniknout živý doklad proti stavu, který se vynuluje.
                $pdo->prepare('UPDATE supplier SET stock_history_locked_until = ? WHERE id = ?')->execute([self::LOCK_RUNNING, $supplierId]);
            }
            $count = $pdo->prepare('SELECT COUNT(*) FROM stock_documents WHERE supplier_id = ? AND history_import_job_id IS NOT NULL');
            $count->execute([$supplierId]);
            $id = $this->jobs->enqueue($supplierId, self::ROLLBACK_KIND, [
                'apply_job_id' => $applyId,
                'cutover_date' => $cutover,
                'orphan' => $active === null,
                'confirm_closed_periods' => $confirmClosedPeriods,
            ], (int) $count->fetchColumn(), createdBy: $createdBy);
            $pdo->commit();
            return $this->jobs->find($supplierId, $id);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Aktivní import = poslední aplikace, která běží, vlastní převzaté doklady, nebo po ní
     * zůstala zamčená historie bez dokladů (selhání před prvním dokladem).
     */
    public function active(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT MAX(id) FROM catalog_jobs WHERE supplier_id = ? AND kind = ?');
        $stmt->execute([$supplierId, self::APPLY_KIND]);
        $id = $stmt->fetchColumn();
        if ($id === null) {
            return null;
        }
        $job = $this->jobs->find($supplierId, (int) $id);
        $owners = $this->db->pdo()->prepare('SELECT DISTINCT history_import_job_id FROM stock_documents
            WHERE supplier_id = ? AND history_import_job_id IS NOT NULL LIMIT 2');
        $owners->execute([$supplierId]);
        $owners = array_map('intval', $owners->fetchAll(PDO::FETCH_COLUMN));
        $locked = $this->db->pdo()->prepare('SELECT stock_history_locked_until FROM supplier WHERE id = ?');
        $locked->execute([$supplierId]);
        $isActive = in_array($job['status'], ['queued', 'running'], true)
            || $owners === [(int) $id]
            || ($owners === [] && $locked->fetchColumn() !== null);
        return $isActive ? $job : null;
    }

    /**
     * Převzatá historie bez aktivní úlohy (obnova z archivu nepřenáší úlohy): vrací id,
     * pod kterým ji jde vrátit (úloha z dokladů, 0 jen u samotného zámku), jinak null.
     */
    public function orphan(int $supplierId): ?int
    {
        if ($this->active($supplierId) !== null) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT (SELECT MAX(history_import_job_id) FROM stock_documents WHERE supplier_id = s.id),
                s.stock_history_locked_until FROM supplier s WHERE s.id = ?');
        $stmt->execute([$supplierId]);
        [$jobId, $locked] = $stmt->fetch(PDO::FETCH_NUM) ?: [null, null];
        return $jobId !== null ? (int) $jobId : ($locked !== null ? 0 : null);
    }

    /**
     * Režim osiřelé historie z dokladů. Když převzaté doklady na každém skladu a kartě
     * končí na nule (vyrovnání k D−1), mazání stav skladu nemění a vrací se jako s datem
     * přechodu. Stejně tak převzetí s D přerušené před vyrovnáním: zámek drží D−1, živé
     * doklady jsou až po něm a firma je vedla už před převzetím. Jinak byla historie
     * živým stavem a vrátit ji jde jen bez živých dokladů.
     */
    private function orphanCutover(int $supplierId): ?string
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT s.stock_history_locked_until,
                (SELECT MAX(doc_date) FROM stock_documents WHERE supplier_id = s.id AND history_import_job_id IS NOT NULL)
            FROM supplier s WHERE s.id = ?');
        $stmt->execute([$supplierId]);
        [$locked, $lastDay] = $stmt->fetch(PDO::FETCH_NUM) ?: [null, null];
        // Zámek běžícího převzetí není den historie.
        $locked = $locked === self::LOCK_RUNNING ? null : $locked;
        $stmt = $pdo->prepare("SELECT 1 FROM (
                SELECT d.warehouse_id AS wh, l.stock_item_id AS item, CASE WHEN d.doc_type = 'receipt' THEN l.qty ELSE -l.qty END AS qty
                FROM stock_documents d JOIN stock_document_lines l ON l.document_id = d.id AND l.supplier_id = d.supplier_id
                WHERE d.supplier_id = ? AND d.history_import_job_id IS NOT NULL AND d.status IN ('posted','reversed')
                UNION ALL
                SELECT d.warehouse_to_id, l.stock_item_id, l.qty
                FROM stock_documents d JOIN stock_document_lines l ON l.document_id = d.id AND l.supplier_id = d.supplier_id
                WHERE d.supplier_id = ? AND d.history_import_job_id IS NOT NULL AND d.status IN ('posted','reversed') AND d.doc_type = 'transfer'
            ) legs GROUP BY wh, item HAVING SUM(qty) <> 0 LIMIT 1");
        $stmt->execute([$supplierId, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            return self::dayAfter(max((string) $locked, (string) $lastDay, '0001-01-01'));
        }
        if ($locked === null) {
            return null;
        }
        // Živé doklady bez data přechodu vznikají až po převzetí, s datem přechodu už před ním
        // (čas zaúčtování má přesnost na sekundy, shodu rozhodne pořadí dokladů).
        $stmt = $pdo->prepare("SELECT
                (SELECT 1 FROM stock_documents WHERE supplier_id = ? AND history_import_job_id IS NULL AND status IN ('posted','reversed') AND doc_date <= ? LIMIT 1),
                (SELECT 1 FROM stock_document_lines l JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
                    WHERE l.supplier_id = ? AND d.history_import_job_id IS NULL AND d.status IN ('posted','reversed')
                        AND (l.ledger_booked_at, l.document_id) < (SELECT hl.ledger_booked_at, hl.document_id FROM stock_document_lines hl
                            JOIN stock_documents hd ON hd.id = hl.document_id AND hd.supplier_id = hl.supplier_id
                            WHERE hl.supplier_id = ? AND hd.history_import_job_id IS NOT NULL
                            ORDER BY hl.ledger_booked_at, hl.document_id LIMIT 1) LIMIT 1)");
        $stmt->execute([$supplierId, $locked, $supplierId, $supplierId]);
        [$liveInHistory, $liveBefore] = $stmt->fetch(PDO::FETCH_NUM);
        return $liveInHistory === null && $liveBefore !== null ? self::dayAfter($locked) : null;
    }

    public static function closedPeriodDocuments(PDO $pdo, int $supplierId, int $jobId, ?string $cutover, bool $readyOnly = true): int
    {
        $mode = $pdo->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $mode->execute([$supplierId]);
        if ($mode->fetchColumn() !== 'double_entry') {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT ' . ($readyOnly ? 'COUNT(DISTINCT staged.doc)' : '1') . " FROM (
                SELECT JSON_VALUE(input_json, '$.doc') AS doc, JSON_VALUE(input_json, '$.values.doc_date') AS doc_date
                FROM catalog_job_items FORCE INDEX (ix_catalog_job_items_item) WHERE supplier_id = ? AND job_id = ?" . ($readyOnly ? " AND status = 'ready'" : '') . "
            ) staged
            JOIN accounting_periods p ON p.supplier_id = ? AND p.status <> 'open'
                AND staged.doc_date >= p.starts_on AND staged.doc_date <= p.ends_on" . ($readyOnly ? '' : ' LIMIT 1'));
        $stmt->execute([$supplierId, $jobId, $supplierId]);
        $closed = (int) $stmt->fetchColumn();
        if ($cutover !== null) {
            $stmt = $pdo->prepare("SELECT 1 FROM accounting_periods WHERE supplier_id = ? AND status <> 'open' AND ? BETWEEN starts_on AND ends_on LIMIT 1");
            $stmt->execute([$supplierId, self::dayBefore($cutover)]);
            $closed += $stmt->fetchColumn() === false ? 0 : 1;
        }
        return $closed;
    }

    public static function closedPeriodHistoryDocuments(PDO $pdo, int $supplierId): int
    {
        $stmt = $pdo->prepare("SELECT 1 FROM stock_documents d
            JOIN supplier s ON s.id = d.supplier_id AND s.accounting_mode = 'double_entry'
            JOIN accounting_periods p ON p.supplier_id = d.supplier_id AND p.status <> 'open' AND d.doc_date BETWEEN p.starts_on AND p.ends_on
            WHERE d.supplier_id = ? AND d.history_import_job_id IS NOT NULL LIMIT 1");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function assertNoLiveDocuments(int $supplierId): void
    {
        $live = $this->db->pdo()->prepare("SELECT 1 FROM stock_documents
            WHERE supplier_id = ? AND history_import_job_id IS NULL AND status IN ('posted','reversed') LIMIT 1");
        $live->execute([$supplierId]);
        if ($live->fetchColumn() !== false) {
            throw new StockException('history_import_live_documents', 'Po převzetí historie už firma zaúčtovala vlastní skladový doklad, import bez data přechodu proto nejde vrátit.', 409);
        }
    }

    /** Vyrovnávací výdejky dostanou čísla až na konci převzetí; kolizi je třeba odhalit před ním. */
    private function assertSettlementNumbersFree(int $supplierId, int $previewId, string $cutover): void
    {
        $stmt = $this->db->pdo()->prepare("SELECT DISTINCT CAST(JSON_VALUE(input_json, '$.values.warehouse_id') AS UNSIGNED) FROM catalog_job_items
                WHERE supplier_id = ? AND job_id = ? AND status = 'ready'
            UNION SELECT DISTINCT CAST(JSON_VALUE(input_json, '$.values.warehouse_to_id') AS UNSIGNED) FROM catalog_job_items
                WHERE supplier_id = ? AND job_id = ? AND status = 'ready' AND JSON_VALUE(input_json, '$.values.doc_type') = 'transfer'");
        $stmt->execute([$supplierId, $previewId, $supplierId, $previewId]);
        $numbers = array_map(static fn ($id): string => self::settlementNumber((int) $id, $cutover), $stmt->fetchAll(PDO::FETCH_COLUMN));
        $stmt = $this->db->pdo()->prepare('SELECT doc_number FROM stock_documents WHERE supplier_id = ? AND doc_number IN ('
            . implode(',', array_fill(0, count($numbers), '?')) . ')');
        $stmt->execute([$supplierId, ...$numbers]);
        $taken = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($taken !== []) {
            throw new StockException('history_import_number_exists', 'Číslo vyrovnávací výdejky už ve firmě existuje: ' . implode(', ', $taken) . '. Přečíslujte ten doklad.', 409, $taken);
        }
    }

    /** Číslo technické příjemky před dokladem: řádek souboru ji odliší i po zkrácení čísla. */
    public static function fillNumber(string $number, int $sourceRow): string
    {
        $suffix = '-TP' . $sourceRow;
        return mb_substr($number, 0, 30 - strlen($suffix)) . $suffix;
    }

    /** Číslo vyrovnávací výdejky skladu k D−1 (id skladu, kód by se po zkrácení mohl shodovat). */
    public static function settlementNumber(int $warehouseId, string $cutover): string
    {
        return 'UH-' . $warehouseId . '-' . self::dayBefore($cutover);
    }

    public static function dayBefore(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    }

    private static function dayAfter(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    }

    /** Rozpracovaná inventura porovnává počty se stavem, který by import nebo vrácení změnily. */
    public static function assertNoOpenStockTake(PDO $pdo, int $supplierId): void
    {
        $stmt = $pdo->prepare("SELECT 1 FROM stock_takes WHERE supplier_id = ? AND status IN ('preparing','counting')
            UNION ALL SELECT 1 FROM stock_cycle_counts WHERE supplier_id = ? AND status IN ('queued','running','counting') LIMIT 1");
        $stmt->execute([$supplierId, $supplierId]);
        if ($stmt->fetchColumn() !== false) {
            throw new EshopException('history_import_stock_take_open', 'Ve firmě běží inventura. Import historie skladu spusťte až po jejím uzavření.', 409);
        }
    }

    /**
     * Bez data přechodu je během převzetí a vrácení zamčený celý sklad. Inventura spuštěná
     * v té době by porovnávala počty se stavem, který import ještě přepíše.
     */
    public static function assertNotRunning(PDO $pdo, int $supplierId, string $message): void
    {
        // Sdílený zámek: převzetí i vrácení nastavují zámek pod zámkem firmy.
        $stmt = $pdo->prepare('SELECT stock_history_locked_until FROM supplier WHERE id = ? LOCK IN SHARE MODE');
        $stmt->execute([$supplierId]);
        if ($stmt->fetchColumn() === self::LOCK_RUNNING) {
            throw new StockException('stock_history_import_running', $message, 409);
        }
    }

    /**
     * Režim musí sedět se skladem firmy: bez D jen firma bez skladových dokladů, s D jen
     * tak, aby historie skončila před prvním dokladem vzniklým v MyÚčtu.
     */
    private function assertMode(int $supplierId, array $profile): void
    {
        if ($this->active($supplierId) !== null || $this->orphan($supplierId) !== null) {
            throw new EshopException('history_import_active', 'Firma už má převzatou historii skladu. Další import je možný až po jejím vrácení.', 409);
        }
        self::assertNoOpenStockTake($this->db->pdo(), $supplierId);
        $stmt = $this->db->pdo()->prepare("SELECT MIN(doc_date) FROM stock_documents
            WHERE supplier_id = ? AND status IN ('posted','reversed') AND history_import_job_id IS NULL");
        $stmt->execute([$supplierId]);
        $first = $stmt->fetchColumn();
        if ($profile['cutover_date'] === null && is_string($first)) {
            throw new EshopException('history_import_cutover_required', 'Firma už má skladové doklady. Zadejte datum přechodu, před kterým historie končí.', 422);
        }
        if ($profile['cutover_date'] !== null && is_string($first) && $first < $profile['cutover_date']) {
            throw new EshopException('history_import_cutover_after_documents', 'Datum přechodu nesmí být pozdější než první skladový doklad v MyÚčtu (' . $first . ').', 422);
        }
    }
}
