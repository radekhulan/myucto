<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Repository\CatalogJobItemRepository;

final class CatalogExportWorker
{
    public function __construct(
        private readonly CatalogJobService $jobs,
        private readonly CatalogJobItemRepository $items,
        private readonly CatalogReadService $catalog,
    ) {}

    public function tick(int $supplierId, int $maxBatches = 10): ?array
    {
        $job = $this->jobs->claim($supplierId, CatalogExportService::KIND);
        if ($job === null) {
            return null;
        }
        $token = $job['lease_token'];
        try {
            for ($i = 0; $i < max(1, min(10, $maxBatches)); $i++) {
                $job = $this->jobs->batch($supplierId, $job['id'], $token,
                    fn (array $current): array => $this->processBatch($supplierId, $current), consistentSnapshot: true);
                if ($job['status'] !== 'running') {
                    return $job;
                }
            }
            $this->jobs->release($supplierId, $job['id'], $token);
            return $this->jobs->find($supplierId, $job['id']);
        } catch (\Throwable $e) {
            $this->jobs->fail($supplierId, $job['id'], $token, 'catalog_export_failed');
            throw $e;
        }
    }

    private function processBatch(int $supplierId, array $job): array
    {
        if ($job['input_version'] !== 1) {
            throw new \RuntimeException('unsupported_input_version');
        }
        $batch = $this->items->batch($supplierId, $job['id'], $job['checkpoint'], 100);
        $pending = array_values(array_filter($batch, static fn (array $row): bool => $row['status'] === 'pending'));
        if ($pending !== []) {
            $capturedAt = gmdate('Y-m-d\TH:i:s\Z');
            $result = $this->catalog->products($supplierId, ['ids' => array_column($pending, 'stock_item_id')] + $job['input']['projection']);
            foreach ($pending as $index => $item) {
                $product = $result['items'][$index];
                if ($product['status'] !== 'ok') {
                    $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'failed', errorCode: 'unavailable');
                } elseif ($product['data']['row_version'] !== $item['expected_version']) {
                    $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'conflict', errorCode: 'version_conflict');
                } else {
                    $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'ready', after: ['captured_at' => $capturedAt, 'data' => $product['data']]);
                }
            }
        }
        $checkpoint = $batch === [] ? $job['checkpoint'] : (int) end($batch)['ordinal'];
        return ['checkpoint' => $checkpoint, 'done' => $checkpoint === $job['total'],
            'report' => ['processed' => $checkpoint, 'counts' => $this->items->counts($supplierId, $job['id'])]];
    }
}
