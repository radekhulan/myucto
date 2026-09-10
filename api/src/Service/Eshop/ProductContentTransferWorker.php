<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Repository\ProductMasterRepository;
use MyInvoice\Service\ActivityLogger;

final class ProductContentTransferWorker
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly CatalogJobItemRepository $items,
        private readonly ProductContentTransferService $transfers,
        private readonly ProductMasterRepository $masters,
        private readonly ActivityLogger $logger,
    ) {}

    public function tickKind(int $supplierId, string $kind, int $maxBatches = 10): ?array
    {
        if (!in_array($kind, [ProductContentTransferService::PREVIEW_KIND, ProductContentTransferService::APPLY_KIND], true)) {
            throw new \InvalidArgumentException('Neznámý typ přenosu obsahu.');
        }
        $job = $this->jobs->claim($supplierId, $kind);
        if ($job === null) {
            return null;
        }
        $token = $job['lease_token'];
        try {
            for ($batchNo = 0; $batchNo < max(1, min(10, $maxBatches)); $batchNo++) {
                $job = $this->jobs->batch($supplierId, $job['id'], $token,
                    fn (array $current): array => $this->process($supplierId, $current));
                if ($job['status'] !== 'running') {
                    return $job;
                }
            }
            $this->jobs->release($supplierId, $job['id'], $token);
            return $this->jobs->find($supplierId, $job['id']);
        } catch (\Throwable $e) {
            $this->jobs->fail($supplierId, $job['id'], $token, 'product_content_transfer_failed');
            throw $e;
        }
    }

    private function process(int $supplierId, array $job): array
    {
        if ($job['input_version'] !== 1) {
            throw new \RuntimeException('unsupported_input_version');
        }
        $master = $this->masters->find($supplierId, (int) $job['input']['master_id'], true);
        if ($master === null || $master['row_version'] !== (int) $job['input']['master_row_version']) {
            throw new \RuntimeException('master_version_conflict');
        }
        $batch = $this->items->batch($supplierId, $job['id'], $job['checkpoint'], self::BATCH_SIZE);
        $pending = array_values(array_filter($batch, static fn (array $item): bool => $item['status'] === 'pending'));
        if ($job['kind'] === ProductContentTransferService::PREVIEW_KIND) {
            $states = $this->transfers->states($supplierId, $master['id'], array_column($pending, 'stock_item_id'),
                $job['input']['fields'], (bool) $job['input']['overwrite']);
            foreach ($pending as $item) {
                $state = $states[$item['stock_item_id']] ?? null;
                if ($state === null) {
                    $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'failed', errorCode: 'unavailable');
                } elseif ($state['before']['row_version'] !== $item['expected_version']) {
                    $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'conflict', $state['before'], null, 'version_conflict');
                } elseif ($this->same($state['before'], $state['after'])) {
                    $state['after']['row_version'] = $state['before']['row_version'];
                    $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'unchanged', $state['before'], $state['after']);
                } else {
                    $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'ready', $state['before'], $state['after']);
                }
            }
        } else {
            foreach ($pending as $item) {
                $after = $item['input']['after'] ?? null;
                $this->db->pdo()->exec('SAVEPOINT product_content_transfer_item');
                try {
                    if (!is_array($after)) {
                        throw new EshopException('unavailable', 'Chybí snapshot náhledu.', 409);
                    }
                    $this->transfers->writeState($supplierId, $master['id'], $item['stock_item_id'], $item['expected_version'], $after, $job['input']['fields']);
                    $this->logger->log('eshop.product_content_transferred', $job['created_by'], 'stock_item', $item['stock_item_id'], [
                        'master_id' => $master['id'], 'fields' => $job['input']['fields'], 'job_id' => $job['id'],
                    ], supplierId: $supplierId);
                    $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'applied', null, $after);
                } catch (EshopException $e) {
                    $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT product_content_transfer_item');
                    $this->items->finish($supplierId, $job['id'], $item['ordinal'],
                        $e->errorCode === 'version_conflict' ? 'conflict' : 'failed', null, $after, $e->errorCode);
                } catch (\Throwable) {
                    $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT product_content_transfer_item');
                    $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'failed', null, $after, 'write_failed');
                } finally {
                    $this->db->pdo()->exec('RELEASE SAVEPOINT product_content_transfer_item');
                }
            }
        }
        $checkpoint = $batch === [] ? $job['checkpoint'] : (int) end($batch)['ordinal'];
        return ['checkpoint' => $checkpoint, 'done' => $checkpoint === $job['total'],
            'report' => ['processed' => $checkpoint, 'counts' => $this->items->counts($supplierId, $job['id'])]];
    }

    private function same(array $before, array $after): bool
    {
        unset($before['row_version'], $after['row_version']);
        return $before === $after;
    }
}
