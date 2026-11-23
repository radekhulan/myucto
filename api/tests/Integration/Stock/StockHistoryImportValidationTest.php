<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Stock\StockHistoryImportService;
use MyInvoice\Service\Stock\StockHistoryImportWorker;

/**
 * Kontroly náhledu historie přes celý soubor (hlavička dokladu, neúplný doklad, záporné
 * stavy) musí dávat stejný výsledek jako původní okenní dotazy a i u dokladu s tisíci
 * řádky doběhnout v řádu sekund.
 */
final class StockHistoryImportValidationTest extends StockTestCase
{
    private const REFERENCE_HEADER = "UPDATE catalog_job_items target JOIN (SELECT ordinal FROM (SELECT ordinal,
            MIN(JSON_VALUE(input_json, '$.header')) OVER (PARTITION BY JSON_VALUE(input_json, '$.doc')) AS first_header,
            MAX(JSON_VALUE(input_json, '$.header')) OVER (PARTITION BY JSON_VALUE(input_json, '$.doc')) AS last_header
        FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? AND status = 'ready') headers
        WHERE first_header <> last_header) bad ON bad.ordinal = target.ordinal
        SET target.status = 'failed', target.error_code = 'history_import_document_inconsistent'
        WHERE target.supplier_id = ? AND target.job_id = ?";

    private const REFERENCE_INCOMPLETE = "UPDATE catalog_job_items target JOIN (SELECT ordinal FROM (SELECT ordinal, status,
            MAX(status = 'failed') OVER (PARTITION BY JSON_VALUE(input_json, '$.doc')) AS has_failed
        FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? AND status IN ('ready','failed')
            AND JSON_VALUE(input_json, '$.doc') IS NOT NULL) documents
        WHERE status = 'ready' AND has_failed = 1) bad ON bad.ordinal = target.ordinal
        SET target.status = 'failed', target.error_code = 'history_import_document_incomplete'
        WHERE target.supplier_id = ? AND target.job_id = ?";

    private const REFERENCE_NEGATIVE = "UPDATE catalog_job_items target JOIN (
        WITH staged AS (
            SELECT ordinal, stock_item_id AS item,
                JSON_VALUE(input_json, '$.doc') AS doc,
                JSON_VALUE(input_json, '$.values.doc_date') AS doc_date,
                JSON_VALUE(input_json, '$.values.doc_type') AS doc_type,
                CAST(JSON_VALUE(input_json, '$.values.warehouse_id') AS UNSIGNED) AS wh,
                CAST(JSON_VALUE(input_json, '$.values.warehouse_to_id') AS UNSIGNED) AS wh_to,
                CAST(JSON_VALUE(input_json, '$.values.quantity') AS DECIMAL(14,3)) AS qty,
                CAST(JSON_VALUE(input_json, '$.values.unit_cost') AS DECIMAL(15,6)) AS cost
            FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? AND status = 'ready'
        ), ordered AS (
            SELECT staged.*, MIN(ordinal) OVER (PARTITION BY doc) AS first_ordinal FROM staged
        ), legs AS (
            SELECT ordinal, doc_date, first_ordinal, 0 AS leg, wh, item,
                CASE WHEN doc_type IN ('receipt','surplus') THEN qty ELSE -qty END AS delta,
                CASE WHEN doc_type IN ('receipt','surplus') THEN cost END AS cost
            FROM ordered
            UNION ALL
            SELECT ordinal, doc_date, first_ordinal, 1, wh_to, item, qty, NULL FROM ordered WHERE doc_type = 'transfer'
        ), running AS (
            SELECT legs.*,
                SUM(delta) OVER (PARTITION BY wh, item ORDER BY doc_date, first_ordinal, ordinal, leg ROWS UNBOUNDED PRECEDING) AS balance,
                COUNT(cost) OVER (PARTITION BY item ORDER BY doc_date, first_ordinal, ordinal, leg ROWS UNBOUNDED PRECEDING) AS cost_group
            FROM legs
        ), needed AS (
            SELECT running.*,
                GREATEST(0, -MIN(balance) OVER (PARTITION BY wh, item ORDER BY doc_date, first_ordinal, ordinal, leg ROWS UNBOUNDED PRECEDING)) AS total_fill,
                FIRST_VALUE(cost) OVER (PARTITION BY item, cost_group ORDER BY doc_date, first_ordinal, ordinal, leg) AS last_cost
            FROM running
        )
        SELECT ordinal, fill, last_cost FROM (
            SELECT ordinal, last_cost,
                total_fill - COALESCE(LAG(total_fill) OVER (PARTITION BY wh, item ORDER BY doc_date, first_ordinal, ordinal, leg), 0) AS fill
            FROM needed
        ) fills WHERE fill > 0
    ) negative ON negative.ordinal = target.ordinal
    SET target.input_json = JSON_SET(target.input_json, '$.fill', JSON_OBJECT(
        'quantity', CAST(negative.fill AS CHAR), 'unit_cost', CAST(COALESCE(negative.last_cost, 0) AS CHAR)))
    WHERE target.supplier_id = ? AND target.job_id = ?";

    public function testValidationMatchesReferenceWindowQueriesOnMixedHistory(): void
    {
        $sid = $this->createSupplier();
        $rows = self::mixedRows(3000);
        $reference = $this->job($sid, $rows);
        $candidate = $this->job($sid, $rows);

        $pdo = $this->db->pdo();
        foreach ([self::REFERENCE_HEADER, self::REFERENCE_INCOMPLETE, self::REFERENCE_NEGATIVE] as $sql) {
            $pdo->prepare($sql)->execute([$sid, $reference, $sid, $reference]);
        }
        $this->validate($sid, $candidate);

        $expected = $this->items($sid, $reference);
        self::assertSame($expected, $this->items($sid, $candidate));
        $statuses = array_count_values(array_filter(array_column($expected, 'error_code')));
        self::assertGreaterThan(0, $statuses['history_import_document_inconsistent'] ?? 0);
        self::assertGreaterThan(0, $statuses['history_import_document_incomplete'] ?? 0);
        self::assertGreaterThan(0, count(array_filter($expected, static fn (array $row): bool => str_contains($row['input_json'], '"fill"'))));

    }

    public function testLargeDocumentsValidateQuicklyWithSameResult(): void
    {
        $sid = $this->createSupplier();
        $lineCount = 6554;
        $rows = [self::row('p1', 'receipt', '2020-01-02', 1, 7, '2', '12.5')];
        for ($i = 0; $i < $lineCount; $i++) {
            $rows[] = self::row('v1', 'issue', '2020-01-05', 1, 7, '1');
        }
        for ($i = 0; $i < $lineCount; $i++) {
            $rows[] = self::row('v2', 'issue', '2020-01-06', 2, 8, '1', null, $i === $lineCount - 1 ? 'jiný partner' : '');
        }
        $job = $this->job($sid, $rows);

        $started = microtime(true);
        $this->validate($sid, $job);
        self::assertLessThan(10.0, microtime(true) - $started);

        $items = $this->items($sid, $job);
        self::assertSame(['ready' => $lineCount + 1, 'failed' => $lineCount], array_count_values(array_column($items, 'status')));
        $failed = array_filter($items, static fn (array $row): bool => $row['status'] === 'failed');
        self::assertSame(['history_import_document_inconsistent'], array_values(array_unique(array_column($failed, 'error_code'))));
        $fills = [];
        foreach ($items as $row) {
            $fill = json_decode($row['input_json'], true, 512, JSON_THROW_ON_ERROR)['fill'] ?? null;
            if ($fill !== null) {
                $fills[] = $fill;
            }
        }
        self::assertCount($lineCount - 2, $fills);
        self::assertSame([['quantity' => '1.000', 'unit_cost' => '12.500000']], array_values(array_unique($fills, SORT_REGULAR)));
    }

    private function validate(int $supplierId, int $jobId): void
    {
        $method = new \ReflectionMethod(StockHistoryImportWorker::class, 'validateDocuments');
        $method->invoke($this->container->get(StockHistoryImportWorker::class), $supplierId, $jobId,
            ['cutover_date' => null, 'fill_negative' => false]);
    }

    private function job(int $supplierId, array $rows): int
    {
        $jobId = $this->container->get(CatalogJobService::class)->enqueue($supplierId, StockHistoryImportService::STAGE_KIND, [
            'profile' => ['cutover_date' => null, 'fill_negative' => false],
        ], createdBy: $this->userId);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE catalog_jobs SET status = 'completed' WHERE supplier_id = ? AND id = ?")->execute([$supplierId, $jobId]);
        foreach (array_chunk($rows, 500, true) as $chunk) {
            $values = [];
            foreach ($chunk as $index => $row) {
                $values[] = [$supplierId, $jobId, $index + 1, $row['item'], $index + 2, $row['status'], $row['status'] === 'failed' ? 'history_import_sku_unknown' : null,
                    json_encode($row['input'], JSON_THROW_ON_ERROR)];
            }
            $pdo->prepare('INSERT INTO catalog_job_items (supplier_id, job_id, ordinal, stock_item_id, source_row, status, error_code, input_json) VALUES '
                . implode(',', array_fill(0, count($values), '(?,?,?,?,?,?,?,?)')))->execute(array_merge(...$values));
        }
        return $jobId;
    }

    private function items(int $supplierId, int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ordinal, status, error_code, input_json FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? ORDER BY ordinal');
        $stmt->execute([$supplierId, $jobId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function row(string $doc, string $type, string $date, int $warehouse, int $item, string $quantity, ?string $cost = null, string $partner = '', ?int $warehouseTo = null, string $status = 'ready'): array
    {
        $values = ['doc_number' => strtoupper($doc), 'doc_type' => $type, 'doc_date' => $date, 'warehouse_id' => $warehouse,
            'partner' => $partner, 'description' => '', 'sku' => 'SKU-' . $item, 'quantity' => $quantity, 'unit_cost' => $cost];
        if ($warehouseTo !== null) {
            $values['warehouse_to_id'] = $warehouseTo;
        }
        $header = sha1(json_encode([$type, $date, $warehouse, $warehouseTo, $partner, '', null, null], JSON_THROW_ON_ERROR));
        return ['item' => $item, 'status' => $status, 'input' => ['raw' => [], 'values' => $values, 'doc' => $doc, 'header' => $header]];
    }

    private static function mixedRows(int $count): array
    {
        mt_srand(20261112);
        $types = ['receipt', 'issue', 'transfer', 'surplus', 'shortage'];
        $rows = [];
        $document = 0;
        $left = 0;
        $head = [];
        for ($i = 0; $i < $count; $i++) {
            if ($left === 0) {
                $document++;
                $left = mt_rand(1, 12);
                $type = $types[mt_rand(0, 4)];
                $head = ['type' => $type, 'date' => sprintf('2020-%02d-%02d', mt_rand(1, 12), mt_rand(1, 28)), 'warehouse' => mt_rand(1, 3),
                    'to' => $type === 'transfer' ? mt_rand(1, 3) : null];
            }
            $left--;
            $doc = mt_rand(1, 40) === 1 ? 'd' . mt_rand(1, $document) : "d{$document}";
            $receipt = in_array($head['type'], ['receipt', 'surplus'], true);
            $rows[] = self::row($doc, $head['type'], $head['date'], $head['warehouse'], mt_rand(1, 25), mt_rand(1, 900) / 100 . '',
                $receipt ? (string) (mt_rand(1, 50000) / 1000) : null, mt_rand(1, 300) === 1 ? 'jiný partner' : '', $head['to'],
                mt_rand(1, 150) === 1 ? 'failed' : 'ready');
        }
        return $rows;
    }
}
