<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Import\CatalogImportReader;
use MyInvoice\Service\Eshop\Import\CatalogImportSourceStore;
use PDO;

final class OpeningStockImportWorker
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly CatalogJobItemRepository $items,
        private readonly CatalogImportSourceStore $sources,
        private readonly CatalogImportReader $reader,
        private readonly StockDocumentService $documents,
    ) {}

    public function tickKind(int $supplierId, string $kind, int $maxBatches = 10): ?array
    {
        if (!in_array($kind, [OpeningStockImportService::STAGE_KIND, OpeningStockImportService::APPLY_KIND], true)) {
            throw new \InvalidArgumentException('opening_import_kind_invalid');
        }
        $job = $this->jobs->claim($supplierId, $kind);
        if ($job === null) {
            return null;
        }
        $token = $job['lease_token'];
        try {
            $rows = null;
            $header = [];
            if ($kind === OpeningStockImportService::STAGE_KIND) {
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
                );
                if (!$resume) {
                    if (!$rows->valid()) {
                        throw new \InvalidArgumentException('import_file_empty');
                    }
                    $header = $rows->current();
                    $rows->next();
                    while ($rows->valid() && $rows->key() <= $job['checkpoint'] + 1) {
                        $rows->next();
                    }
                }
            }
            for ($batch = 0; $batch < max(1, min(10, $maxBatches)); $batch++) {
                $job = $this->jobs->batch(
                    $supplierId,
                    $job['id'],
                    $token,
                    fn (array $current): array => $kind === OpeningStockImportService::STAGE_KIND
                        ? $this->stage($supplierId, $current, $header, $rows)
                        : $this->apply($supplierId, $current),
                );
                if ($job['status'] !== 'running') {
                    return $job;
                }
            }
            $this->jobs->release($supplierId, $job['id'], $token);
            return $this->jobs->find($supplierId, $job['id']);
        } catch (\Throwable $e) {
            $this->jobs->fail($supplierId, $job['id'], $token, 'opening_import_failed');
            throw $e;
        }
    }

    private function stage(int $supplierId, array $job, array $header, \Generator $rows): array
    {
        $checkpoint = $job['checkpoint'];
        $profile = $job['input']['profile'];
        for ($i = 0; $i < self::BATCH_SIZE && $rows->valid(); $i++, $rows->next()) {
            $checkpoint = $rows->key() - 1;
            $raw = $rows->current();
            $values = [];
            $before = null;
            $after = null;
            $error = null;
            $status = 'failed';
            try {
                $values = OpeningStockImportProfile::map($profile, $header, $raw);
                $item = $this->findItem($supplierId, $values['sku']);
                $before = $this->findIdentity($supplierId, $profile['source_key'], $values['external_id']);
                $after = $this->desired($item, $profile, $values);
                if ($before !== null) {
                    $status = $before['document_status'] === 'posted' && $this->comparable($before) === $this->comparable($after)
                        ? 'unchanged' : 'conflict';
                    $error = $status === 'conflict' ? 'opening_import_identity_conflict' : null;
                } else {
                    $after = $this->trial($supplierId, $profile, $values, $item);
                    $status = 'ready';
                }
            } catch (StockException|EshopException $e) {
                $error = $e->errorCode;
            } catch (\InvalidArgumentException $e) {
                $error = preg_match('/^[a-z0-9_]{1,100}$/D', $e->getMessage()) ? $e->getMessage() : 'opening_import_validation_failed';
            } catch (\PDOException) {
                $error = 'opening_import_database_validation_failed';
            }
            $this->items->append($supplierId, $job['id'], [[
                'ordinal' => $checkpoint,
                'source_row' => $rows->key(),
                'stock_item_id' => $after['stock_item_id'] ?? $before['stock_item_id'] ?? null,
                'input' => ['raw' => $raw, 'values' => $values, 'identity' => $values['external_id'] ?? null],
            ]]);
            $this->items->finish($supplierId, $job['id'], $checkpoint, $status, $before, $after, $error);
        }
        $done = !$rows->valid();
        if ($done) {
            $pdo = $this->db->pdo();
            $pdo->prepare("UPDATE catalog_job_items target JOIN (
                SELECT ordinal, COUNT(*) OVER (
                    PARTITION BY JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.identity')) COLLATE utf8mb4_bin
                ) AS copies
                FROM catalog_job_items WHERE supplier_id = ? AND job_id = ?
                    AND JSON_TYPE(JSON_EXTRACT(input_json, '$.identity')) = 'STRING'
            ) duplicates ON duplicates.ordinal = target.ordinal
                SET target.status = 'failed', target.error_code = 'opening_import_duplicate_identity'
                WHERE target.supplier_id = ? AND target.job_id = ? AND duplicates.copies > 1")
                ->execute([$supplierId, $job['id'], $supplierId, $job['id']]);
            $pdo->prepare('UPDATE catalog_jobs SET total = ? WHERE supplier_id = ? AND id = ?')
                ->execute([$checkpoint, $supplierId, $job['id']]);
        }
        return [
            'checkpoint' => $checkpoint,
            'done' => $done,
            'report' => [
                'processed' => $checkpoint,
                'stage' => 'validation',
                'header' => $header,
                'counts' => $this->items->counts($supplierId, $job['id']),
            ],
        ];
    }

    private function trial(int $supplierId, array $profile, array $values, array $item): array
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT opening_import_trial');
        try {
            $document = $this->documents->create($supplierId, $this->documentBody($profile, [$values + ['stock_item_id' => $item['id']]], 0), null);
            $posted = $this->documents->post($supplierId, (int) $document['id'], null);
            return $this->canonical($posted, $posted['lines'][0]);
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT opening_import_trial');
            $pdo->exec('RELEASE SAVEPOINT opening_import_trial');
        }
    }

    private function apply(int $supplierId, array $job): array
    {
        $batch = $this->items->batch($supplierId, $job['id'], $job['checkpoint'], self::BATCH_SIZE);
        $ready = [];
        foreach ($batch as $item) {
            if ($item['status'] !== 'pending') {
                continue;
            }
            $values = $item['input']['values'];
            $identity = $this->findIdentity($supplierId, $job['input']['profile']['source_key'], $values['external_id']);
            if ($identity !== null) {
                $same = $identity['document_status'] === 'posted'
                    && $this->comparable($identity) === $this->comparable($item['after']);
                $this->items->finish(
                    $supplierId,
                    $job['id'],
                    $item['ordinal'],
                    $same ? 'unchanged' : 'conflict',
                    $identity,
                    $same ? $identity : $item['after'],
                    $same ? null : 'opening_import_identity_conflict',
                );
                continue;
            }
            $current = $this->findItem($supplierId, $values['sku']);
            $desired = $this->desired($current, $job['input']['profile'], $values);
            if ($this->comparable($desired) !== $this->comparable($item['after'])) {
                $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'conflict', $item['before'], $item['after'], 'opening_import_input_changed');
                continue;
            }
            $ready[] = $item;
        }

        if ($ready !== []) {
            $pdo = $this->db->pdo();
            $pdo->exec('SAVEPOINT opening_import_apply');
            try {
                $lines = array_map(static fn (array $item): array => $item['input']['values'] + ['stock_item_id' => $item['stock_item_id']], $ready);
                $document = $this->documents->create($supplierId, $this->documentBody($job['input']['profile'], $lines, $job['id']), $job['created_by']);
                $posted = $this->documents->post($supplierId, (int) $document['id'], $job['created_by']);
                foreach ($ready as $index => $item) {
                    $after = $this->canonical($posted, $posted['lines'][$index]);
                    if ($this->comparable($after) !== $this->comparable($item['after'])) {
                        throw new EshopException('opening_import_input_changed', 'Výsledek již neodpovídá náhledu.', 409);
                    }
                    $this->insertIdentity(
                        $supplierId,
                        $job['input']['profile']['source_key'],
                        $item['input']['values']['external_id'],
                        (int) $posted['lines'][$index]['id'],
                    );
                    $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'applied', $item['before'], $after);
                }
            } catch (\Throwable $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT opening_import_apply');
                $code = $e instanceof StockException || $e instanceof EshopException ? $e->errorCode : 'opening_import_write_failed';
                foreach ($ready as $item) {
                    $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'conflict', $item['before'], $item['after'], $code);
                }
            } finally {
                $pdo->exec('RELEASE SAVEPOINT opening_import_apply');
            }
        }
        $checkpoint = $batch === [] ? $job['checkpoint'] : end($batch)['ordinal'];
        return [
            'checkpoint' => $checkpoint,
            'done' => $checkpoint === $job['total'],
            'report' => ['processed' => $checkpoint, 'counts' => $this->items->counts($supplierId, $job['id'])],
        ];
    }

    private function findItem(int $supplierId, string $sku): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, sku, name, unit, is_active, is_stocked, tracking_mode
            FROM stock_items WHERE supplier_id = ? AND sku = ? LIMIT 1');
        $stmt->execute([$supplierId, $sku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \InvalidArgumentException('opening_import_item_not_found');
        }
        if (!(bool) $row['is_active'] || !(bool) $row['is_stocked']) {
            throw new \InvalidArgumentException('opening_import_item_unavailable');
        }
        if (($row['tracking_mode'] ?? 'none') !== 'none') {
            throw new \InvalidArgumentException('opening_import_tracking_required');
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }

    private function desired(array $item, array $profile, array $values): array
    {
        $valueC = $this->roundedCents(
            str_replace('.', '', $values['quantity']),
            str_replace('.', '', $values['unit_cost']),
        );
        return [
            'stock_item_id' => (int) $item['id'],
            'sku' => (string) $item['sku'],
            'warehouse_id' => (int) $profile['warehouse_id'],
            'doc_date' => (string) $profile['doc_date'],
            'quantity' => (string) $values['quantity'],
            'unit_cost' => (string) $values['unit_cost'],
            'value_total' => StockValuation::cToDecimal($valueC),
        ];
    }

    private function findIdentity(int $supplierId, string $sourceKey, string $externalId): ?array
    {
        $stmt = $this->db->pdo()->prepare("SELECT l.id AS document_line_id, l.stock_item_id, i.sku,
                d.id AS document_id, d.warehouse_id, d.doc_date, d.status AS document_status,
                l.qty AS quantity, l.unit_cost, l.value_total
            FROM external_entity_map m
            LEFT JOIN stock_document_lines l ON l.id = m.internal_id AND l.supplier_id = m.supplier_id
            LEFT JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
            LEFT JOIN stock_items i ON i.id = l.stock_item_id AND i.supplier_id = l.supplier_id
            WHERE m.supplier_id = ? AND m.connection_id IS NULL AND m.source_key = ?
                AND m.entity_type = 'stock_opening_line' AND m.external_id = ? LIMIT 1");
        $stmt->execute([$supplierId, $sourceKey, $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        foreach (['document_line_id', 'stock_item_id', 'document_id', 'warehouse_id'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        return $row;
    }

    private function insertIdentity(int $supplierId, string $sourceKey, string $externalId, int $lineId): void
    {
        $this->db->pdo()->prepare("INSERT INTO external_entity_map
            (supplier_id, connection_id, source_key, entity_type, external_id, internal_id)
            VALUES (?, NULL, ?, 'stock_opening_line', ?, ?)")
            ->execute([$supplierId, $sourceKey, $externalId, $lineId]);
    }

    private function documentBody(array $profile, array $lines, int $jobId): array
    {
        return [
            'doc_type' => 'receipt',
            'origin' => 'manual',
            'warehouse_id' => $profile['warehouse_id'],
            'doc_date' => $profile['doc_date'],
            'description' => 'Počáteční zásoby ' . $profile['source_key'] . ($jobId > 0 ? ' #' . $jobId : ''),
            'lines' => array_map(static fn (array $line): array => [
                'stock_item_id' => (int) $line['stock_item_id'],
                'qty' => $line['quantity'],
                'unit_cost' => $line['unit_cost'],
            ], $lines),
        ];
    }

    private function canonical(array $document, array $line): array
    {
        return [
            'document_id' => (int) $document['id'],
            'document_line_id' => (int) $line['id'],
            'document_number' => $document['doc_number'],
            'document_status' => $document['status'],
            'stock_item_id' => (int) $line['stock_item_id'],
            'sku' => (string) $line['sku'],
            'warehouse_id' => (int) $document['warehouse_id'],
            'doc_date' => (string) $document['doc_date'],
            'quantity' => (string) $line['qty'],
            'unit_cost' => (string) $line['unit_cost'],
            'value_total' => (string) $line['value_total'],
        ];
    }

    private function comparable(array $state): array
    {
        return array_intersect_key($state, array_flip([
            'stock_item_id', 'sku', 'warehouse_id', 'doc_date', 'quantity', 'unit_cost', 'value_total',
        ]));
    }

    private function roundedCents(string $quantityT, string $unitCostMicro): int
    {
        $left = array_reverse(array_map('intval', str_split(ltrim($quantityT, '0') ?: '0')));
        $right = array_reverse(array_map('intval', str_split(ltrim($unitCostMicro, '0') ?: '0')));
        $digits = array_fill(0, count($left) + count($right), 0);
        foreach ($left as $i => $a) {
            foreach ($right as $j => $b) {
                $digits[$i + $j] += $a * $b;
            }
        }
        for ($i = 0; $i < count($digits) - 1; $i++) {
            $digits[$i + 1] += intdiv($digits[$i], 10);
            $digits[$i] %= 10;
        }
        while (count($digits) > 1 && end($digits) === 0) {
            array_pop($digits);
        }
        $product = implode('', array_reverse($digits));
        $product = str_pad($product, 8, '0', STR_PAD_LEFT);
        $cents = ltrim(substr($product, 0, -7), '0') ?: '0';
        if ((int) substr($product, -7, 1) >= 5) {
            $cents = $this->incrementDecimal($cents);
        }
        if (strlen($cents) > 15) {
            throw new \InvalidArgumentException('opening_import_value_invalid');
        }
        return (int) $cents;
    }

    private function incrementDecimal(string $value): string
    {
        $digits = str_split($value);
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);
                return implode('', $digits);
            }
            $digits[$i] = '0';
        }
        return '1' . implode('', $digits);
    }
}
