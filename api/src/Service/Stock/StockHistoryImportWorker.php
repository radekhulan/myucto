<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Import\CatalogImportReader;
use MyInvoice\Service\Eshop\Import\CatalogImportSourceStore;
use MyInvoice\Service\Eshop\Pricing\CatalogPriceJobService;
use PDO;

/**
 * Úlohy importu historie skladu ({@see StockHistoryImportService}).
 *
 * Náhled čte soubor po dávkách, odkazy (sklad, karta, faktura) dohledává dávkově a na
 * konci jednou sadou dotazů zkontroluje celé doklady, záporné stavy a uzavřená období.
 *
 * Aplikace nejde přes StockDocumentService::post(): zpětný doklad by na každý zápis
 * přehrál celou kartu, čerpal číselnou řadu a odečítal dnešní rezervace. Doklady se
 * zapíšou rovnou jako zaúčtované s původním číslem, pak je jeden průchod po kartách
 * ocení klouzavým průměrem přes všechny sklady najednou (převodka nese hodnotu výdeje
 * na cílový sklad) a nakonec se historie uzavře vyrovnávací výdejkou, nebo (bez data
 * přechodu) nastaví stav skladu.
 */
final class StockHistoryImportWorker
{
    private const STAGE_BATCH = 500;
    private const APPLY_BATCH = 500;
    private const VALUATION_ITEMS = 200;
    private const REPORT_LIMIT = 100;
    /** Faktura podle čísla s tím, co rozhoduje o vazbě převzatého dokladu (viz invoiceLinkProblem). */
    private const INVOICE_LINK_SQL = "SELECT i.id, i.varsymbol, i.status, i.invoice_type,
            EXISTS(SELECT 1 FROM stock_documents d WHERE d.supplier_id = i.supplier_id AND d.invoice_id = i.id
                AND d.origin IN ('invoice','credit_note')) AS own_stock
        FROM invoices i WHERE i.supplier_id = ?";

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly CatalogJobItemRepository $items,
        private readonly CatalogImportSourceStore $sources,
        private readonly CatalogImportReader $reader,
        private readonly StockLevelService $levels,
        private readonly CatalogPriceJobService $priceJobs,
    ) {}

    public function tickKind(int $supplierId, string $kind, int $maxBatches = 10): ?array
    {
        if (!in_array($kind, [StockHistoryImportService::STAGE_KIND, StockHistoryImportService::APPLY_KIND, StockHistoryImportService::ROLLBACK_KIND], true)) {
            throw new \InvalidArgumentException('history_import_kind_invalid');
        }
        $job = $this->jobs->claim($supplierId, $kind);
        if ($job === null) {
            return null;
        }
        $token = $job['lease_token'];
        try {
            $rows = null;
            $header = [];
            if ($kind === StockHistoryImportService::STAGE_KIND) {
                $source = $this->sources->get($supplierId, (int) $job['input']['source_id']);
                if (!hash_equals((string) $job['input']['source_sha256'], (string) $source['sha256'])) {
                    throw new EshopException('import_source_changed', 'Zdroj importu byl změněn.', 409);
                }
                $header = $job['report']['header'] ?? [];
                $resume = $job['checkpoint'] > 0 && $header !== [];
                $rows = $this->reader->rows(
                    $this->sources->path($supplierId, $source['id']),
                    $source['format'],
                    $job['input']['profile']['reader'],
                    $resume ? $job['checkpoint'] + 1 : 0,
                    StockHistoryImportProfile::MAX_ROWS,
                );
                if (!$resume) {
                    if (!$rows->valid()) {
                        throw new \InvalidArgumentException('import_file_empty');
                    }
                    $header = $rows->current();
                    $rows->next();
                }
            }
            for ($batch = 0; $batch < max(1, min(10, $maxBatches)); $batch++) {
                $job = $this->jobs->batch(
                    $supplierId,
                    $job['id'],
                    $token,
                    fn (array $current): array => match ($kind) {
                        StockHistoryImportService::STAGE_KIND => $this->stage($supplierId, $current, $header, $rows),
                        StockHistoryImportService::APPLY_KIND => $this->apply($supplierId, $current),
                        default => $this->rollback($supplierId, $current),
                    },
                );
                if ($job['status'] !== 'running') {
                    return $job;
                }
            }
            $this->jobs->release($supplierId, $job['id'], $token);
            return $this->jobs->find($supplierId, $job['id']);
        } catch (\Throwable $e) {
            $code = $e instanceof StockException || $e instanceof EshopException ? $e->errorCode : 'history_import_failed';
            $this->jobs->fail($supplierId, $job['id'], $token, $code);
            throw $e;
        }
    }

    // ── náhled ───────────────────────────────────────────────────────────────

    private function stage(int $supplierId, array $job, array $header, \Generator $rows): array
    {
        $profile = $job['input']['profile'];
        $checkpoint = $job['checkpoint'];
        $headerError = null;
        $indexes = [];
        try {
            $indexes = StockHistoryImportProfile::headerIndexes($profile, $header);
        } catch (\InvalidArgumentException $e) {
            $headerError = $e->getMessage();
        }

        $batch = [];
        for ($i = 0; $i < self::STAGE_BATCH && $rows->valid(); $i++, $rows->next()) {
            $batch[$rows->key()] = $rows->current();
        }
        $mapped = [];
        foreach ($batch as $line => $cells) {
            $mapped[$line] = ['values' => null, 'error' => $headerError, 'doc' => null];
            if ($headerError !== null) {
                continue;
            }
            if (implode('', array_map('trim', $cells)) === '') {
                $mapped[$line]['error'] = 'skipped';
                continue;
            }
            $mapped[$line]['doc'] = StockHistoryImportProfile::documentKey($profile, $indexes, $cells);
            try {
                $mapped[$line]['values'] = StockHistoryImportProfile::map($profile, $indexes, $cells);
            } catch (\InvalidArgumentException $e) {
                $mapped[$line]['error'] = self::errorCode($e);
            }
        }
        $references = $this->references($supplierId, array_values(array_filter(array_column($mapped, 'values'))));
        foreach ($mapped as $line => $row) {
            $values = $row['values'];
            $error = $row['error'];
            if ($values !== null) {
                try {
                    $values = $this->resolve($values, $references);
                } catch (\InvalidArgumentException $e) {
                    $error = self::errorCode($e);
                }
            }
            $checkpoint = $line - 1;
            $this->items->append($supplierId, $job['id'], [[
                'ordinal' => $checkpoint,
                'source_row' => $line,
                'stock_item_id' => $values['stock_item_id'] ?? null,
                'input' => [
                    'raw' => $batch[$line],
                    'values' => $values ?? [],
                    'doc' => $row['doc'],
                    'header' => $values === null ? null : sha1(json_encode([
                        $values['doc_type'], $values['doc_date'], $values['warehouse_id'] ?? null, $values['warehouse_to_id'] ?? null,
                        $values['partner'], $values['description'], $values['invoice_id'] ?? null, $values['purchase_invoice_id'] ?? null,
                    ], JSON_THROW_ON_ERROR)),
                ],
            ]]);
            $status = $error === 'skipped' ? 'skipped' : ($error === null ? 'ready' : 'failed');
            $this->items->finish($supplierId, $job['id'], $checkpoint, $status, null, null, $status === 'failed' ? $error : null);
        }

        $done = !$rows->valid();
        $report = [
            'processed' => $checkpoint,
            'stage' => 'validation',
            'header' => $header,
        ];
        if ($done) {
            $report += $this->validateDocuments($supplierId, $job['id'], $profile);
            $this->db->pdo()->prepare('UPDATE catalog_jobs SET total = ? WHERE supplier_id = ? AND id = ?')
                ->execute([$checkpoint, $supplierId, $job['id']]);
        }
        $report['counts'] = $this->items->counts($supplierId, $job['id']);
        return ['checkpoint' => $checkpoint, 'done' => $done, 'report' => $report];
    }

    /**
     * Odkazy dávky jedním dotazem na druh: sklady, karty, faktury, už použitá čísla dokladů
     * a tvary čísel skladových řad (převzaté číslo nesmí později kolidovat s řadou MyÚčta).
     */
    private function references(int $supplierId, array $rows): array
    {
        $pdo = $this->db->pdo();
        $out = ['warehouses' => [], 'items' => [], 'invoices' => [], 'purchase_invoices' => [], 'numbers' => [], 'series' => $this->seriesPatterns($supplierId)];
        $stmt = $pdo->prepare('SELECT id, code FROM warehouses WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $warehouse) {
            $out['warehouses'][mb_strtolower((string) $warehouse['code'])] = (int) $warehouse['id'];
        }
        $lookup = function (string $sql, array $keys) use ($pdo, $supplierId): array {
            $keys = array_values(array_unique($keys));
            if ($keys === []) {
                return [];
            }
            $stmt = $pdo->prepare(sprintf($sql, implode(',', array_fill(0, count($keys), '?'))));
            $stmt->execute([$supplierId, ...$keys]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };
        foreach ($lookup('SELECT id, sku, is_stocked, tracking_mode FROM stock_items WHERE supplier_id = ? AND sku IN (%s)', array_column($rows, 'sku')) as $item) {
            $out['items'][mb_strtolower((string) $item['sku'])] = $item;
        }
        foreach ($lookup(self::INVOICE_LINK_SQL . ' AND i.varsymbol IN (%s)', array_filter(array_column($rows, 'invoice_number'))) as $invoice) {
            $out['invoices'][mb_strtolower((string) $invoice['varsymbol'])] = $invoice;
        }
        $purchaseNumbers = array_filter(array_column($rows, 'purchase_invoice_number'));
        foreach ($lookup('SELECT id, vendor_invoice_number AS number FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number IN (%s)', $purchaseNumbers) as $invoice) {
            $out['purchase_invoices'][mb_strtolower((string) $invoice['number'])][(int) $invoice['id']] = true;
        }
        foreach ($lookup('SELECT id, varsymbol AS number FROM purchase_invoices WHERE supplier_id = ? AND varsymbol IN (%s)', $purchaseNumbers) as $invoice) {
            $key = mb_strtolower((string) $invoice['number']);
            // Číslo dodavatele má přednost, variabilní symbol je až náhradní shoda.
            if (!isset($out['purchase_invoices'][$key])) {
                $out['purchase_invoices']['~' . $key][(int) $invoice['id']] = true;
            }
        }
        foreach ($lookup('SELECT doc_number FROM stock_documents WHERE supplier_id = ? AND doc_number IN (%s)', array_column($rows, 'doc_number')) as $document) {
            $out['numbers'][mb_strtolower((string) $document['doc_number'])] = true;
        }
        return $out;
    }

    private function resolve(array $values, array $references): array
    {
        $warehouseId = $references['warehouses'][mb_strtolower($values['warehouse'])]
            ?? throw new \InvalidArgumentException('history_import_warehouse_not_found');
        $warehouseToId = null;
        if ($values['warehouse_to'] !== null) {
            $warehouseToId = $references['warehouses'][mb_strtolower($values['warehouse_to'])]
                ?? throw new \InvalidArgumentException('history_import_warehouse_not_found');
        }
        $item = $references['items'][mb_strtolower($values['sku'])]
            ?? throw new \InvalidArgumentException('history_import_item_not_found');
        if (!(bool) $item['is_stocked']) {
            throw new \InvalidArgumentException('history_import_item_not_stocked');
        }
        if ((string) $item['tracking_mode'] !== 'none') {
            throw new \InvalidArgumentException('history_import_tracking_unsupported');
        }
        $invoiceId = null;
        $unlinked = null;
        if ($values['invoice_number'] !== null) {
            $invoice = $references['invoices'][mb_strtolower($values['invoice_number'])]
                ?? throw new \InvalidArgumentException('history_import_invoice_not_found');
            $unlinked = self::invoiceLinkProblem($invoice);
            $invoiceId = $unlinked === null ? (int) $invoice['id'] : null;
        }
        $purchaseInvoiceId = null;
        if ($values['purchase_invoice_number'] !== null) {
            $key = mb_strtolower($values['purchase_invoice_number']);
            $candidates = array_keys($references['purchase_invoices'][$key] ?? $references['purchase_invoices']['~' . $key] ?? []);
            if ($candidates === []) {
                throw new \InvalidArgumentException('history_import_purchase_invoice_not_found');
            }
            if (count($candidates) > 1) {
                throw new \InvalidArgumentException('history_import_purchase_invoice_ambiguous');
            }
            $purchaseInvoiceId = $candidates[0];
        }
        if (isset($references['numbers'][mb_strtolower($values['doc_number'])])) {
            throw new \InvalidArgumentException('history_import_number_exists');
        }
        foreach ($references['series'] as $pattern) {
            if (preg_match($pattern, $values['doc_number'])) {
                throw new \InvalidArgumentException('history_import_number_series_collision');
            }
        }
        $value = '0.00';
        if ($values['unit_cost'] !== null) {
            $value = self::lineValue($values['quantity'], $values['unit_cost']);
        }
        return $values + [
            'warehouse_id' => $warehouseId,
            'warehouse_to_id' => $warehouseToId,
            'stock_item_id' => (int) $item['id'],
            'invoice_id' => $invoiceId,
            'invoice_unlinked' => $unlinked,
            'purchase_invoice_id' => $purchaseInvoiceId,
            'value_total' => $value,
        ];
    }

    /** Tvar čísel skladových řad (PRI/VYD/PRE i vlastní šablony firmy) jako regulární výrazy. */
    private function seriesPatterns(int $supplierId): array
    {
        $codes = ['stock_in', 'stock_out', 'stock_transfer'];
        $templates = [];
        foreach ($codes as $code) {
            $templates[DocumentSeriesService::DEFAULT_PREFIXES[$code] . "\0" . DocumentSeriesService::DEFAULT_TEMPLATE] = true;
        }
        $stmt = $this->db->pdo()->prepare('SELECT DISTINCT prefix, number_format FROM accounting_document_series
            WHERE supplier_id = ? AND series_code IN (?, ?, ?)');
        $stmt->execute([$supplierId, ...$codes]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $series) {
            $template = trim((string) $series['number_format']);
            $templates[$series['prefix'] . "\0" . ($template === '' ? DocumentSeriesService::DEFAULT_TEMPLATE : $template)] = true;
        }
        $patterns = [];
        foreach (array_keys($templates) as $key) {
            [$prefix, $template] = explode("\0", $key, 2);
            $parts = preg_split('/(\{PREFIX\}|\{YYYY\}|\{YY\}|\{C+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
            $regex = '';
            foreach ($parts as $part) {
                $regex .= match (true) {
                    $part === '{PREFIX}' => preg_quote($prefix, '~'),
                    $part === '{YYYY}' => '\d{4}',
                    $part === '{YY}' => '\d{2}',
                    (bool) preg_match('/^\{C+\}$/', $part) => '\d{' . (strlen($part) - 2) . ',}',
                    default => preg_quote($part, '~'),
                };
            }
            $patterns[] = '~^' . $regex . '$~iD';
        }
        return $patterns;
    }

    /**
     * Kontroly přes celý soubor: shodná hlavička na všech řádcích dokladu, doklad bez
     * chybného řádku, záporné stavy (s výpočtem technických příjemek), uzavřená období
     * a souhrn po typech dokladů.
     */
    private function validateDocuments(int $supplierId, int $jobId, array $profile): array
    {
        $pdo = $this->db->pdo();
        $markFailed = function (string $selectOrdinals, string $error) use ($pdo, $supplierId, $jobId): void {
            $pdo->prepare("UPDATE catalog_job_items target JOIN ({$selectOrdinals}) bad ON bad.ordinal = target.ordinal
                SET target.status = 'failed', target.error_code = ?
                WHERE target.supplier_id = ? AND target.job_id = ?")
                ->execute([$supplierId, $jobId, $error, $supplierId, $jobId]);
        };
        $markFailed("SELECT ordinal FROM (SELECT ordinal,
                MIN(JSON_VALUE(input_json, '$.header')) OVER (PARTITION BY JSON_VALUE(input_json, '$.doc')) AS first_header,
                MAX(JSON_VALUE(input_json, '$.header')) OVER (PARTITION BY JSON_VALUE(input_json, '$.doc')) AS last_header
            FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? AND status = 'ready') headers
            WHERE first_header <> last_header", 'history_import_document_inconsistent');
        $markFailed("SELECT ordinal FROM (SELECT ordinal, status,
                MAX(status = 'failed') OVER (PARTITION BY JSON_VALUE(input_json, '$.doc')) AS has_failed
            FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? AND status IN ('ready','failed')
                AND JSON_VALUE(input_json, '$.doc') IS NOT NULL) documents
            WHERE status = 'ready' AND has_failed = 1", 'history_import_document_incomplete');

        // Záporný stav: běžící součet nohou dokladů po (sklad, karta) v pořadí aplikace.
        // Potřebné doplnění = −minimum dosavadního součtu; technická příjemka před řádkem
        // doplní přírůstek za poslední známou nákupní cenu karty (bez ní 0).
        $pdo->prepare("UPDATE catalog_job_items target JOIN (
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
        WHERE target.supplier_id = ? AND target.job_id = ?")
            ->execute([$supplierId, $jobId, $supplierId, $jobId]);
        $this->checkReservedNumbers($supplierId, $jobId, $profile);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM catalog_job_items
            WHERE supplier_id = ? AND job_id = ? AND status = 'ready' AND JSON_EXISTS(input_json, '$.fill')");
        $stmt->execute([$supplierId, $jobId]);
        $negativeCount = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT i.source_row,
                JSON_VALUE(i.input_json, '$.values.doc_number') AS doc_number,
                JSON_VALUE(i.input_json, '$.values.doc_date') AS doc_date,
                JSON_VALUE(i.input_json, '$.values.warehouse') AS warehouse,
                JSON_VALUE(i.input_json, '$.values.sku') AS sku,
                JSON_VALUE(i.input_json, '$.fill.quantity') AS missing_quantity,
                JSON_VALUE(i.input_json, '$.fill.unit_cost') AS unit_cost
            FROM catalog_job_items i
            WHERE i.supplier_id = ? AND i.job_id = ? AND i.status = 'ready' AND JSON_EXISTS(i.input_json, '$.fill')
            ORDER BY doc_date, i.ordinal LIMIT " . self::REPORT_LIMIT);
        $stmt->execute([$supplierId, $jobId]);
        $negatives = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT JSON_VALUE(input_json, '$.values.doc_type') AS doc_type,
                COUNT(DISTINCT JSON_VALUE(input_json, '$.doc')) AS documents, COUNT(*) AS line_count,
                MIN(JSON_VALUE(input_json, '$.values.doc_date')) AS date_from,
                MAX(JSON_VALUE(input_json, '$.values.doc_date')) AS date_to
            FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? AND status = 'ready'
            GROUP BY JSON_VALUE(input_json, '$.values.doc_type')");
        $stmt->execute([$supplierId, $jobId]);
        $documents = array_fill_keys(StockHistoryImportProfile::TYPES, 0);
        $lines = 0;
        $from = null;
        $to = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $documents[$row['doc_type']] = (int) $row['documents'];
            $lines += (int) $row['line_count'];
            $from = $from === null || $row['date_from'] < $from ? $row['date_from'] : $from;
            $to = $to === null || $row['date_to'] > $to ? $row['date_to'] : $to;
        }

        // Doklady, které se na fakturu nenavážou (koncept, jiný typ, faktura s vlastním výdejem).
        $stmt = $pdo->prepare("SELECT MIN(source_row) AS source_row,
                JSON_VALUE(input_json, '$.values.doc_number') AS doc_number,
                JSON_VALUE(input_json, '$.values.invoice_number') AS invoice_number,
                JSON_VALUE(input_json, '$.values.invoice_unlinked') AS reason
            FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? AND status = 'ready'
                AND JSON_VALUE(input_json, '$.values.invoice_unlinked') IS NOT NULL
            GROUP BY JSON_VALUE(input_json, '$.doc'), doc_number, invoice_number, reason ORDER BY source_row");
        $stmt->execute([$supplierId, $jobId]);
        $unlinked = array_map(static fn (array $row): array => ['source_row' => (int) $row['source_row']] + $row, $stmt->fetchAll(PDO::FETCH_ASSOC));

        $closed = StockHistoryImportService::closedPeriodDocuments($pdo, $supplierId, $jobId, $profile['cutover_date']);

        return [
            'cutover_date' => $profile['cutover_date'],
            'fill_negative' => $profile['fill_negative'],
            'documents' => $documents,
            'lines' => $lines,
            'date_from' => $from,
            'date_to' => $to,
            'negative_count' => $negativeCount,
            'negatives' => $negatives,
            'closed_period_documents' => $closed,
            'invoice_unlinked_count' => count($unlinked),
            'invoice_unlinked' => array_slice($unlinked, 0, self::REPORT_LIMIT),
        ];
    }

    /**
     * Čísla, která aplikace přidělí sama (technické příjemky, vyrovnávací výdejky), nesmí
     * patřit dokladu ze souboru; technická příjemka navíc ani dokladu, který ve firmě už je.
     */
    private function checkReservedNumbers(int $supplierId, int $jobId, array $profile): void
    {
        $pdo = $this->db->pdo();
        $reserved = [];
        $fills = [];
        $fillDocs = [];
        if ($profile['fill_negative']) {
            $stmt = $pdo->prepare("SELECT JSON_VALUE(input_json, '$.doc') AS doc,
                    MIN(JSON_VALUE(input_json, '$.values.doc_number')) AS doc_number, MIN(source_row) AS source_row
                FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? AND status = 'ready'
                GROUP BY JSON_VALUE(input_json, '$.doc') HAVING MAX(JSON_EXISTS(input_json, '$.fill')) = 1");
            $stmt->execute([$supplierId, $jobId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $number = StockHistoryImportService::fillNumber($row['doc_number'], (int) $row['source_row']);
                $reserved[mb_strtolower($number)] = true;
                $fills[$number] = $row['doc'];
                $fillDocs[mb_strtolower($number)] = $row['doc'];
            }
        }
        if ($profile['cutover_date'] !== null) {
            $stmt = $pdo->prepare("SELECT DISTINCT CAST(JSON_VALUE(input_json, '$.values.warehouse_id') AS UNSIGNED) FROM catalog_job_items
                    WHERE supplier_id = ? AND job_id = ? AND status = 'ready'
                UNION SELECT DISTINCT CAST(JSON_VALUE(input_json, '$.values.warehouse_to_id') AS UNSIGNED) FROM catalog_job_items
                    WHERE supplier_id = ? AND job_id = ? AND status = 'ready' AND JSON_VALUE(input_json, '$.values.doc_type') = 'transfer'");
            $stmt->execute([$supplierId, $jobId, $supplierId, $jobId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $warehouseId) {
                $reserved[mb_strtolower(StockHistoryImportService::settlementNumber((int) $warehouseId, $profile['cutover_date']))] = true;
            }
        }
        if ($reserved === []) {
            return;
        }
        $stmt = $pdo->prepare("SELECT DISTINCT JSON_VALUE(input_json, '$.doc') FROM catalog_job_items
            WHERE supplier_id = ? AND job_id = ? AND status = 'ready'");
        $stmt->execute([$supplierId, $jobId]);
        // Klíč dokladu je jeho číslo malými písmeny (StockHistoryImportProfile::documentKey).
        $this->failDocuments($supplierId, $jobId, array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), static fn (string $doc): bool => isset($reserved[$doc]))), 'history_import_number_reserved');
        $taken = [];
        foreach (array_chunk(array_keys($fills), 1000) as $chunk) {
            $stmt = $pdo->prepare('SELECT doc_number FROM stock_documents WHERE supplier_id = ? AND doc_number IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $number) {
                $taken[] = $fillDocs[mb_strtolower($number)];
            }
        }
        $this->failDocuments($supplierId, $jobId, $taken, 'history_import_number_exists');
    }

    private function failDocuments(int $supplierId, int $jobId, array $docs, string $error): void
    {
        foreach (array_chunk($docs, 1000) as $chunk) {
            $this->db->pdo()->prepare("UPDATE catalog_job_items SET status = 'failed', error_code = ?
                WHERE supplier_id = ? AND job_id = ? AND status = 'ready' AND JSON_VALUE(input_json, '$.doc') IN ("
                . implode(',', array_fill(0, count($chunk), '?')) . ')')
                ->execute([$error, $supplierId, $jobId, ...$chunk]);
        }
    }

    // ── aplikace ─────────────────────────────────────────────────────────────

    private function apply(int $supplierId, array $job): array
    {
        $this->assertCurrent($supplierId, $job);
        if (empty($job['input']['confirm_closed_periods'])
            && StockHistoryImportService::closedPeriodDocuments($this->db->pdo(), $supplierId, $job['id'], $job['input']['profile']['cutover_date'], false) > 0) {
            throw new EshopException('history_import_closed_periods_unconfirmed', 'Část dokladů spadá do uzavřeného účetního období. Import vraťte a spusťte znovu s výslovným potvrzením.', 409);
        }
        $report = $job['report'] + ['phase' => 'documents', 'documents_created' => 0, 'fill_documents' => 0, 'valuation_cursor' => 0, 'settlement' => [], 'invoice_unlinked_on_apply' => 0, 'invoice_unlinked_on_apply_list' => []];
        $checkpoint = $job['checkpoint'];
        $done = false;
        if ($report['phase'] === 'documents') {
            $checkpoint = $this->insertDocuments($supplierId, $job, $report);
            if ($checkpoint >= $job['total']) {
                $report['phase'] = 'valuation';
            }
        } elseif ($report['phase'] === 'valuation') {
            if (!$this->valuate($supplierId, $job, $report)) {
                $report['phase'] = 'finish';
            }
        } else {
            $this->finish($supplierId, $job);
            $report['phase'] = 'done';
            $done = true;
        }
        $report['processed'] = $checkpoint;
        $report['counts'] = $this->items->counts($supplierId, $job['id']);
        return ['checkpoint' => $checkpoint, 'done' => $done, 'report' => $report];
    }

    /** Zapíše další celé doklady (doklad se mezi dávky nedělí); vrací nový checkpoint. */
    private function insertDocuments(int $supplierId, array $job, array &$report): int
    {
        $pdo = $this->db->pdo();
        $profile = $job['input']['profile'];
        $limit = self::APPLY_BATCH;
        while (true) {
            $stmt = $pdo->prepare('SELECT ordinal, source_row, stock_item_id, input_json FROM catalog_job_items
                WHERE supplier_id = ? AND job_id = ? AND ordinal > ? ORDER BY ordinal LIMIT ' . ($limit + 1));
            $stmt->execute([$supplierId, $job['id'], $job['checkpoint']]);
            $groups = [];
            $last = null;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $index => $row) {
                $input = json_decode($row['input_json'], true, 512, JSON_THROW_ON_ERROR);
                if ($index === $limit) {
                    // Řádek navíc jen říká, jestli poslední doklad pokračuje za dávkou.
                    if ($input['doc'] === $last) {
                        array_pop($groups);
                    }
                    break;
                }
                if ($input['doc'] !== $last) {
                    $groups[] = [];
                    $last = $input['doc'];
                }
                $groups[array_key_last($groups)][] = ['ordinal' => (int) $row['ordinal'], 'source_row' => (int) $row['source_row']] + $input;
            }
            if ($groups !== [] || $last === null) {
                break;
            }
            $limit *= 2;
        }
        if ($groups === []) {
            return $job['checkpoint'];
        }

        $numbers = [];
        foreach ($groups as $rows) {
            $numbers[] = $rows[0]['values']['doc_number'];
            if ($profile['fill_negative'] && self::fills($rows) !== []) {
                $numbers[] = StockHistoryImportService::fillNumber($rows[0]['values']['doc_number'], self::firstRow($rows));
            }
        }
        $this->assertNumbersFree($supplierId, $numbers);

        // Faktura se od náhledu mohla změnit (vystavení, vlastní výdejka): vazbu ověří znovu.
        $invoiceIds = array_values(array_unique(array_filter(array_map(static fn (array $rows): ?int => $rows[0]['values']['invoice_id'], $groups))));
        $problems = [];
        if ($invoiceIds !== []) {
            $stmt = $pdo->prepare(self::INVOICE_LINK_SQL . ' AND i.id IN (' . implode(',', array_fill(0, count($invoiceIds), '?')) . ')');
            $stmt->execute([$supplierId, ...$invoiceIds]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
                $problems[(int) $invoice['id']] = self::invoiceLinkProblem($invoice);
            }
        }

        $now = (string) $pdo->query('SELECT NOW()')->fetchColumn();
        foreach ($groups as $rows) {
            $values = $rows[0]['values'];
            $reason = $values['invoice_id'] === null ? null
                : (array_key_exists($values['invoice_id'], $problems) ? $problems[$values['invoice_id']] : 'missing');
            if ($reason !== null) {
                $values['invoice_id'] = null;
                $report['invoice_unlinked_on_apply']++;
                if (count($report['invoice_unlinked_on_apply_list']) < self::REPORT_LIMIT) {
                    $report['invoice_unlinked_on_apply_list'][] = ['source_row' => $rows[0]['source_row'], 'doc_number' => $values['doc_number'],
                        'invoice_number' => $values['invoice_number'], 'reason' => $reason];
                }
            }
            $fills = $profile['fill_negative'] ? self::fills($rows) : [];
            if ($fills !== []) {
                $this->insertDocument($supplierId, $job, $now, [
                    'doc_type' => 'receipt',
                    'origin' => 'manual',
                    'warehouse_id' => $values['warehouse_id'],
                    'warehouse_to_id' => null,
                    'doc_number' => StockHistoryImportService::fillNumber($values['doc_number'], self::firstRow($rows)),
                    'doc_date' => $values['doc_date'],
                    'description' => mb_substr('Technická příjemka: doplnění záporného stavu před ' . $values['doc_number'] . ' (převzatá historie)', 0, 255),
                    'partner_name' => null,
                    'invoice_id' => null,
                    'purchase_invoice_id' => null,
                ], array_map(static fn (array $fill): array => [
                    'stock_item_id' => $fill['stock_item_id'],
                    'qty' => $fill['quantity'],
                    'unit_cost' => $fill['unit_cost'],
                    'value_total' => self::lineValue($fill['quantity'], $fill['unit_cost']),
                    'note' => null,
                ], $fills));
                $report['fill_documents']++;
            }
            $receipt = in_array($values['doc_type'], ['receipt', 'surplus'], true);
            $this->insertDocument($supplierId, $job, $now, [
                'doc_type' => match ($values['doc_type']) { 'surplus' => 'receipt', 'shortage' => 'issue', default => $values['doc_type'] },
                'origin' => in_array($values['doc_type'], ['surplus', 'shortage'], true) ? 'inventory' : 'manual',
                'warehouse_id' => $values['warehouse_id'],
                'warehouse_to_id' => $values['warehouse_to_id'],
                'doc_number' => $values['doc_number'],
                'doc_date' => $values['doc_date'],
                'description' => $values['description'] ?? mb_substr('Převzato (' . $profile['source_key'] . '): ' . $values['doc_number'], 0, 255),
                'partner_name' => $values['partner'],
                'invoice_id' => $values['invoice_id'],
                'purchase_invoice_id' => $values['purchase_invoice_id'],
            ], array_map(static fn (array $row): array => [
                'stock_item_id' => $row['values']['stock_item_id'],
                'qty' => $row['values']['quantity'],
                'unit_cost' => $receipt ? $row['values']['unit_cost'] : '0',
                'value_total' => $receipt ? $row['values']['value_total'] : '0',
                'note' => $row['values']['note'],
            ], $rows));
            $report['documents_created']++;
        }
        $lastOrdinal = end($groups)[array_key_last(end($groups))]['ordinal'];
        $pdo->prepare("UPDATE catalog_job_items SET status = 'applied'
            WHERE supplier_id = ? AND job_id = ? AND ordinal > ? AND ordinal <= ?")
            ->execute([$supplierId, $job['id'], $job['checkpoint'], $lastOrdinal]);
        return $lastOrdinal;
    }

    private function insertDocument(int $supplierId, array $job, string $now, array $header, array $lines): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO stock_documents
            (supplier_id, doc_type, origin, warehouse_id, warehouse_to_id, doc_number, doc_date, description,
             partner_name, invoice_id, purchase_invoice_id, status, booked_at, booked_by, created_by, history_import_job_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?, ?, ?, ?)")
            ->execute([
                $supplierId, $header['doc_type'], $header['origin'], $header['warehouse_id'], $header['warehouse_to_id'],
                $header['doc_number'], $header['doc_date'], $header['description'], $header['partner_name'],
                $header['invoice_id'], $header['purchase_invoice_id'], $now, $job['created_by'], $job['created_by'], $job['id'],
            ]);
        $documentId = (int) $pdo->lastInsertId();
        foreach (array_chunk(array_values($lines), self::APPLY_BATCH, true) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $index => $line) {
                $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push($params, $documentId, $supplierId, $line['stock_item_id'], $header['doc_date'], $line['qty'],
                    $line['unit_cost'], $line['value_total'], $index + 1, $line['note'], $now);
            }
            $pdo->prepare('INSERT INTO stock_document_lines
                (document_id, supplier_id, stock_item_id, doc_date, qty, unit_cost, value_total, line_no, note, ledger_booked_at)
                VALUES ' . implode(', ', $placeholders))->execute($params);
        }
        return $documentId;
    }

    /**
     * Ocení další dávku karet v pořadí skladové knihy, každý sklad karty vlastním klouzavým
     * průměrem: příjem drží zadanou hodnotu, výdej bere průměr skladu, převodka přenese
     * hodnotu výdeje na cílový sklad.
     * Vrací false, když už žádná karta nezbývá.
     */
    private function valuate(int $supplierId, array $job, array &$report): bool
    {
        $pdo = $this->db->pdo();
        // Karty bere ze zadání úlohy, ne z právě zapsaných dokladů: statistiky čerstvě
        // naplněných tabulek svedou optimalizátor k plánu přes celou firmu.
        $stmt = $pdo->prepare('SELECT DISTINCT stock_item_id FROM catalog_job_items
            WHERE supplier_id = ? AND job_id = ? AND stock_item_id > ?
            ORDER BY stock_item_id LIMIT ' . self::VALUATION_ITEMS);
        $stmt->execute([$supplierId, $job['id'], $report['valuation_cursor']]);
        $itemIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($itemIds === []) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT l.id, l.stock_item_id, l.qty, l.unit_cost, l.value_total, d.doc_type, d.doc_number,
                d.doc_date, d.warehouse_id, d.warehouse_to_id
            FROM stock_document_lines l FORCE INDEX (idx_sdl_valuation_cursor)
            STRAIGHT_JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
            WHERE l.supplier_id = ? AND d.history_import_job_id = ? AND l.stock_item_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')
            ORDER BY l.stock_item_id, l.doc_date, l.ledger_booked_at, l.document_id, l.line_no, l.id');
        $stmt->execute([$supplierId, $job['id'], ...$itemIds]);
        $update = $pdo->prepare('UPDATE stock_document_lines SET unit_cost = ?, value_total = ? WHERE supplier_id = ? AND id = ?');
        $states = [];
        while (($line = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $itemId = (int) $line['stock_item_id'];
            $warehouseId = (int) $line['warehouse_id'];
            $qtyT = StockValuation::qtyToT((string) $line['qty']);
            [$stockT, $stockC] = $states[$itemId][$warehouseId] ?? [0, 0];
            if ($line['doc_type'] === 'receipt') {
                $res = StockValuation::receipt($stockT, $stockC, $qtyT, StockValuation::valueToC((string) $line['value_total']));
                $states[$itemId][$warehouseId] = [$res['qtyT'], $res['valueC']];
                continue;
            }
            if ($qtyT > $stockT) {
                throw new StockException('history_import_negative_stock', 'Převzatá historie vede k zápornému stavu zásob.', 409, [[
                    'stock_item_id' => $itemId, 'warehouse_id' => $warehouseId, 'doc_number' => $line['doc_number'], 'doc_date' => $line['doc_date'],
                ]]);
            }
            $res = StockValuation::issue($stockT, $stockC, $qtyT);
            $states[$itemId][$warehouseId] = [$res['qtyT'], $res['valueC']];
            if ($line['doc_type'] === 'transfer') {
                $targetId = (int) $line['warehouse_to_id'];
                [$targetT, $targetC] = $states[$itemId][$targetId] ?? [0, 0];
                $target = StockValuation::receipt($targetT, $targetC, $qtyT, $res['lineValueC']);
                $states[$itemId][$targetId] = [$target['qtyT'], $target['valueC']];
            }
            $unitCost = StockValuation::microToDecimal($res['lineUnitCostMicro']);
            $value = StockValuation::cToDecimal($res['lineValueC']);
            if ($value !== (string) $line['value_total'] || $unitCost !== (string) $line['unit_cost']) {
                $update->execute([$unitCost, $value, $supplierId, (int) $line['id']]);
            }
        }

        if ($job['input']['profile']['cutover_date'] === null) {
            $this->setLiveLevels($supplierId, $states);
        } else {
            $this->settle($supplierId, $job, $states, $report);
        }
        $report['valuation_cursor'] = end($itemIds);
        return true;
    }

    /**
     * Uzavření historie k D−1: výdej celého zůstatku každé karty na každém skladu
     * (přesná hodnota, bez zbytku průměru), jedna výdejka na sklad.
     */
    private function settle(int $supplierId, array $job, array $states, array &$report): void
    {
        $pdo = $this->db->pdo();
        $date = StockHistoryImportService::dayBefore((string) $job['input']['profile']['cutover_date']);
        $now = null;
        $byWarehouse = [];
        foreach ($states as $itemId => $warehouses) {
            foreach ($warehouses as $warehouseId => [$qtyT, $valueC]) {
                if ($qtyT > 0) {
                    $byWarehouse[$warehouseId][$itemId] = [$qtyT, $valueC];
                }
            }
        }
        ksort($byWarehouse);
        foreach ($byWarehouse as $warehouseId => $balances) {
            $documentId = $report['settlement'][$warehouseId]['document_id'] ?? null;
            $lineNo = $report['settlement'][$warehouseId]['lines'] ?? 0;
            if ($documentId === null) {
                $number = StockHistoryImportService::settlementNumber($warehouseId, (string) $job['input']['profile']['cutover_date']);
                $this->assertNumbersFree($supplierId, [$number]);
                $now ??= (string) $pdo->query('SELECT NOW()')->fetchColumn();
                $pdo->prepare("INSERT INTO stock_documents
                    (supplier_id, doc_type, origin, warehouse_id, doc_number, doc_date, description,
                     status, booked_at, booked_by, created_by, history_import_job_id)
                    VALUES (?, 'issue', 'manual', ?, ?, ?, ?, 'posted', ?, ?, ?, ?)")
                    ->execute([$supplierId, $warehouseId, $number, $date,
                        'Uzavření převzaté historie skladu: zůstatek ke dni přechodu na MyÚčto',
                        $now, $job['created_by'], $job['created_by'], $job['id']]);
                $documentId = (int) $pdo->lastInsertId();
            }
            $placeholders = [];
            $params = [];
            foreach ($balances as $itemId => [$qtyT, $valueC]) {
                $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, NOW())';
                array_push($params, $documentId, $supplierId, $itemId, $date, StockValuation::tToDecimal($qtyT),
                    StockValuation::microToDecimal(StockValuation::avgUnitCostMicro($qtyT, $valueC)), StockValuation::cToDecimal($valueC), ++$lineNo);
            }
            $pdo->prepare('INSERT INTO stock_document_lines
                (document_id, supplier_id, stock_item_id, doc_date, qty, unit_cost, value_total, line_no, ledger_booked_at)
                VALUES ' . implode(', ', $placeholders))->execute($params);
            $report['settlement'][$warehouseId] = ['document_id' => $documentId, 'lines' => $lineNo];
        }
    }

    /**
     * Historie je živý stav: konec historie = stav skladu. Firma nesmí mít jiné skladové
     * doklady (mezitím vzniklý doklad by se přepsal), proto se to ověří pod zámkem stavů.
     */
    private function setLiveLevels(int $supplierId, array $states): void
    {
        $pairs = [];
        foreach ($states as $itemId => $warehouses) {
            foreach (array_keys($warehouses) as $warehouseId) {
                $pairs[] = ['warehouse_id' => $warehouseId, 'stock_item_id' => $itemId];
            }
        }
        $this->levels->lockLevels($supplierId, $pairs);
        $this->assertNoLiveDocuments($supplierId);
        foreach ($states as $itemId => $warehouses) {
            foreach ($warehouses as $warehouseId => [$qtyT, $valueC]) {
                $this->levels->setLevel($supplierId, $warehouseId, $itemId, $qtyT, $valueC);
            }
        }
        $this->priceJobs->enqueue($supplierId, array_keys($states));
    }

    private function finish(int $supplierId, array $job): void
    {
        $pdo = $this->db->pdo();
        $profile = $job['input']['profile'];
        $from = (string) $job['input']['date_from'];
        if ($profile['cutover_date'] === null) {
            $this->assertNoLiveDocuments($supplierId);
            // Inventura spuštěná během převzetí by po odemčení zásobu zdvojila.
            StockHistoryImportService::assertNoOpenStockTake($pdo, $supplierId);
            $pdo->prepare('DELETE FROM stock_valuation_snapshots WHERE supplier_id = ? AND cutoff_date >= ?')
                ->execute([$supplierId, $from]);
            $pdo->prepare('UPDATE supplier SET stock_history_locked_until = ? WHERE id = ?')
                ->execute([(string) $job['input']['date_to'], $supplierId]);
        } else {
            // Nulové snapshoty k D−1 zůstávají, starší mohly vzniknout z rozpracované historie.
            $pdo->prepare('DELETE FROM stock_valuation_snapshots WHERE supplier_id = ? AND cutoff_date >= ? AND cutoff_date < ?')
                ->execute([$supplierId, $from, StockHistoryImportService::dayBefore((string) $profile['cutover_date'])]);
        }
        $pdo->prepare('UPDATE warehouses SET valuation_version = valuation_version + 1 WHERE supplier_id = ?')->execute([$supplierId]);
    }

    // ── vrácení ──────────────────────────────────────────────────────────────

    /** Maže převzaté doklady od nejnovějšího po dávkách, nakonec uklidí stav a odemkne historii. */
    private function rollback(int $supplierId, array $job): array
    {
        $this->assertCurrent($supplierId, $job);
        $pdo = $this->db->pdo();
        if (empty($job['input']['confirm_closed_periods'])
            && StockHistoryImportService::closedPeriodHistoryDocuments($pdo, $supplierId) > 0) {
            throw new EshopException('history_import_closed_periods_unconfirmed', 'Část převzatých dokladů spadá do uzavřeného účetního období. Vrácení spusťte znovu s výslovným potvrzením.', 409);
        }
        $report = $job['report'] + ['documents_deleted' => 0];
        // Firma má nejvýš jednu převzatou historii; osiřelá (bez úlohy) se maže celá stejně.
        $stmt = $pdo->prepare('SELECT id FROM stock_documents WHERE supplier_id = ? AND history_import_job_id IS NOT NULL
            ORDER BY history_import_job_id DESC, doc_date DESC, id DESC LIMIT ' . self::APPLY_BATCH);
        $stmt->execute([$supplierId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($ids !== []) {
            $pdo->prepare('DELETE FROM stock_documents WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
                ->execute([$supplierId, ...$ids]);
            $report['documents_deleted'] += count($ids);
            return ['checkpoint' => min($job['total'], $report['documents_deleted']), 'done' => false, 'report' => $report];
        }

        $cutover = $job['input']['cutover_date'];
        if ($cutover === null) {
            // Bez data přechodu byl stav skladu konec historie: bez jejích dokladů je nulový.
            $stmt = $pdo->prepare('SELECT warehouse_id, stock_item_id FROM stock_levels WHERE supplier_id = ?');
            $stmt->execute([$supplierId]);
            $pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->levels->lockLevels($supplierId, $pairs);
            $this->assertNoLiveDocuments($supplierId);
            foreach ($pairs as $pair) {
                $this->levels->setLevel($supplierId, (int) $pair['warehouse_id'], (int) $pair['stock_item_id'], 0, 0);
            }
            $this->priceJobs->enqueue($supplierId, array_column($pairs, 'stock_item_id'));
            $pdo->prepare('DELETE FROM stock_valuation_snapshots WHERE supplier_id = ?')->execute([$supplierId]);
        } else {
            $pdo->prepare('DELETE FROM stock_valuation_snapshots WHERE supplier_id = ? AND cutoff_date < ?')->execute([$supplierId, $cutover]);
        }
        $pdo->prepare('UPDATE supplier SET stock_history_locked_until = NULL WHERE id = ?')->execute([$supplierId]);
        $pdo->prepare('UPDATE warehouses SET valuation_version = valuation_version + 1 WHERE supplier_id = ?')->execute([$supplierId]);
        return ['checkpoint' => $job['total'], 'done' => true, 'report' => $report];
    }

    /**
     * Úloha musí být nejnovější aplikací nebo vrácením firmy: opakovaná starší úloha
     * (retry po vrácení nebo po novém importu) by přepsala novější stav. Zámek firmy ji
     * řadí za právě zakládané vrácení či aplikaci. Rovnou výhradní: dávka firmu později
     * mění (zámek historie), povýšení sdíleného zámku proti uložení nastavení firmy
     * končí deadlockem.
     */
    private function assertCurrent(int $supplierId, array $job): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE')->execute([$supplierId]);
        $stmt = $pdo->prepare('SELECT MAX(id) FROM catalog_jobs WHERE supplier_id = ? AND kind IN (?, ?)');
        $stmt->execute([$supplierId, StockHistoryImportService::APPLY_KIND, StockHistoryImportService::ROLLBACK_KIND]);
        if ((int) $stmt->fetchColumn() !== (int) $job['id']) {
            throw new StockException('history_import_job_stale', 'Úloha patří k importu historie, který byl mezitím vrácen nebo nahrazen. Import spusťte znovu.', 409);
        }
    }

    private function assertNoLiveDocuments(int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare("SELECT doc_number FROM stock_documents
            WHERE supplier_id = ? AND history_import_job_id IS NULL AND status IN ('posted','reversed') LIMIT 1");
        $stmt->execute([$supplierId]);
        if ($stmt->fetchColumn() !== false) {
            throw new StockException('history_import_live_documents', 'Během importu historie vznikl skladový doklad. Import vraťte a spusťte znovu s datem přechodu.', 409);
        }
    }

    private function assertNumbersFree(int $supplierId, array $numbers): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT doc_number FROM stock_documents WHERE supplier_id = ? AND doc_number IN ('
            . implode(',', array_fill(0, count($numbers), '?')) . ') LIMIT 10');
        $stmt->execute([$supplierId, ...$numbers]);
        $taken = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($taken !== []) {
            throw new StockException('history_import_number_exists', 'Číslo dokladu už ve firmě existuje: ' . implode(', ', $taken) . '.', 409, $taken);
        }
    }

    /**
     * Převzatý doklad se naváže jen na vystavenou fakturu nebo dobropis, který nemá vlastní
     * skladový doklad z MyÚčta. Jinak by vazba zablokovala výdej nebo vratku živého dokladu
     * (převzatý doklad se počítá jako proběhlý výdej). Vrací důvod, proč se nenaváže.
     */
    private static function invoiceLinkProblem(array $invoice): ?string
    {
        return match (true) {
            !in_array($invoice['invoice_type'], ['invoice', 'credit_note'], true) => 'type',
            $invoice['status'] === 'draft' => 'draft',
            (bool) $invoice['own_stock'] => 'own_stock',
            default => null,
        };
    }

    /** @return list<array{stock_item_id:int, quantity:string, unit_cost:string}> */
    private static function fills(array $rows): array
    {
        $fills = [];
        foreach ($rows as $row) {
            if (isset($row['fill'])) {
                $fills[] = [
                    'stock_item_id' => (int) $row['values']['stock_item_id'],
                    'quantity' => (string) $row['fill']['quantity'],
                    'unit_cost' => (string) $row['fill']['unit_cost'],
                ];
            }
        }
        return $fills;
    }

    private static function firstRow(array $rows): int
    {
        return min(array_column($rows, 'source_row'));
    }

    /** Hodnota řádku příjmu v haléřích, zaokrouhlená polovinou nahoru (jako příjemka v MyÚčtu). */
    private static function lineValue(string $quantity, string $unitCost): string
    {
        $value = bcadd(bcmul($quantity, $unitCost, 9), '0.005', 2);
        if (strlen(explode('.', $value)[0]) > 13) {
            throw new \InvalidArgumentException('history_import_value_invalid');
        }
        return $value;
    }

    private static function errorCode(\InvalidArgumentException $e): string
    {
        return preg_match('/^[a-z0-9_]{1,100}$/D', $e->getMessage()) ? $e->getMessage() : 'history_import_validation_failed';
    }
}
