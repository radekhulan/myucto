<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogJobItemRepository;

final class CatalogBulkWorker
{
    private const BATCH_SIZE = 100;
    private const KINDS = [CatalogBulkService::PREVIEW_KIND, CatalogBulkService::APPLY_KIND, CatalogBulkService::RESTORE_KIND];

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly CatalogJobItemRepository $items,
        private readonly CatalogBulkService $bulk,
    ) {}

    public function tick(int $supplierId, int $maxBatches = 10): ?array
    {
        foreach (self::KINDS as $kind) {
            $result = $this->tickKind($supplierId, $kind, $maxBatches);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    public function tickKind(int $supplierId, string $kind, int $maxBatches = 10): ?array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Neznámý typ hromadné katalogové úlohy.');
        }
        $job = $this->jobs->claim($supplierId, $kind);
        if ($job === null) {
            return null;
        }
        $token = $job['lease_token'];
        try {
            for ($batch = 0; $batch < max(1, min(10, $maxBatches)); $batch++) {
                $job = $this->jobs->batch($supplierId, $job['id'], $token,
                    fn (array $current): array => $this->processBatch($supplierId, $current));
                if ($job['status'] !== 'running') {
                    return $job;
                }
            }
            $this->jobs->release($supplierId, $job['id'], $token);
            return $this->jobs->find($supplierId, $job['id']);
        } catch (\Throwable $e) {
            $this->jobs->fail($supplierId, $job['id'], $token, 'catalog_bulk_failed');
            throw $e;
        }
    }

    private function processBatch(int $supplierId, array $job): array
    {
        if ($job['input_version'] !== 1 || !in_array($job['kind'], self::KINDS, true)) {
            throw new \RuntimeException('unsupported_input_version');
        }
        $batch = $this->items->batch($supplierId, $job['id'], $job['checkpoint'], self::BATCH_SIZE);
        $pending = array_values(array_filter($batch, static fn (array $item): bool => $item['status'] === 'pending'));
        $states = $this->bulk->states($supplierId, array_column($pending, 'stock_item_id'));
        foreach ($pending as $item) {
            if ($job['kind'] === CatalogBulkService::PREVIEW_KIND) {
                $this->previewItem($supplierId, $job, $item, $states);
            } else {
                $this->writeItem($supplierId, $job, $item, $states);
            }
        }
        $checkpoint = $batch === [] ? $job['checkpoint'] : (int) end($batch)['ordinal'];
        return [
            'checkpoint' => $checkpoint,
            'done' => $checkpoint === $job['total'],
            'report' => ['processed' => $checkpoint, 'counts' => $this->items->counts($supplierId, $job['id'])],
        ];
    }

    private function previewItem(int $supplierId, array $job, array $item, array $states): void
    {
        $before = $states[$item['stock_item_id']] ?? null;
        if ($before === null) {
            $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'failed', errorCode: 'unavailable');
            return;
        }
        if ($before['row_version'] !== $item['expected_version']) {
            $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'conflict', $before, null, 'version_conflict');
            return;
        }
        $after = $this->bulk->changedState($before, $job['input']['changes']);
        $this->items->finish($supplierId, $job['id'], $item['ordinal'],
            $this->bulk->sameValues($before, $after) ? 'unchanged' : 'ready', $before, $after);
    }

    private function writeItem(int $supplierId, array $job, array $item, array $states): void
    {
        $before = $states[$item['stock_item_id']] ?? null;
        $desired = $job['kind'] === CatalogBulkService::RESTORE_KIND
            ? ($item['input']['before'] ?? null)
            : ($item['input']['after'] ?? null);
        if ($before === null || !is_array($desired)) {
            $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'failed', $before, null, 'unavailable');
            return;
        }
        if ($before['row_version'] !== $item['expected_version']) {
            $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'conflict', $before, $desired, 'version_conflict');
            return;
        }
        if ($this->bulk->sameValues($before, $desired)) {
            $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'unchanged', $before, $before);
            return;
        }
        $this->db->pdo()->exec('SAVEPOINT catalog_bulk_item');
        try {
            $this->bulk->writeState($supplierId, $item['stock_item_id'], $item['expected_version'], $desired, $job['input']['changes']);
            $after = $this->bulk->states($supplierId, [$item['stock_item_id']])[$item['stock_item_id']] ?? null;
            $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'applied', $before, $after);
        } catch (EshopException $e) {
            $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT catalog_bulk_item');
            $status = $e->errorCode === 'version_conflict' ? 'conflict' : 'failed';
            $this->items->finish($supplierId, $job['id'], $item['ordinal'], $status, $before, $desired, $e->errorCode);
        } catch (\MyInvoice\Service\Eshop\Pricing\PricingInputException $e) {
            $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT catalog_bulk_item');
            $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'failed', $before, $desired, $e->errorCode);
        } catch (\Throwable) {
            $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT catalog_bulk_item');
            $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'failed', $before, $desired, 'write_failed');
        } finally {
            $this->db->pdo()->exec('RELEASE SAVEPOINT catalog_bulk_item');
        }
    }
}
