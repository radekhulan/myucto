<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Import;

use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\ProductMediaIngestService;

final class CatalogMediaImportWorker
{
    public function __construct(
        private readonly CatalogJobService $jobs,
        private readonly CatalogJobItemRepository $items,
        private readonly CatalogMediaImportService $imports,
        private readonly CatalogMediaFetcher $fetcher,
        private readonly ProductMediaIngestService $ingest,
    ) {}

    public function tickKind(int $supplierId, string $kind, int $maxBatches = 10): ?array
    {
        if ($kind !== CatalogMediaImportService::KIND) {
            throw new \InvalidArgumentException('import_kind_invalid');
        }
        $job = $this->jobs->claim($supplierId, $kind);
        if ($job === null) {
            return null;
        }
        $token = $job['lease_token'];
        try {
            for ($batch = 0; $batch < max(1, min(10, $maxBatches)); $batch++) {
                $next = $this->items->batch($supplierId, $job['id'], $job['checkpoint'], 1)[0] ?? null;
                if ($next === null) {
                    throw new \RuntimeException('media_checkpoint_invalid');
                }
                $fetchError = null;
                try {
                    $url = $this->imports->decryptUrl(
                        $supplierId,
                        (int) $next['input']['stage_job_id'],
                        (int) $next['input']['source_ordinal'],
                        (array) $next['input']['media'],
                    );
                    $download = $this->fetcher->fetch($url);
                } catch (CatalogMediaFetchException $e) {
                    if ($e->retryable) {
                        $this->jobs->fail($supplierId, $job['id'], $token, $e->errorCode);
                        return $this->jobs->find($supplierId, $job['id']);
                    }
                    $download = null;
                    $fetchError = $e->errorCode;
                }

                $job = $this->jobs->batch($supplierId, $job['id'], $token, function (array $current) use ($supplierId, $next, $download, $fetchError): array {
                    $locked = $this->items->batch($supplierId, $current['id'], $current['checkpoint'], 1)[0] ?? null;
                    if ($locked === null || $locked['ordinal'] !== $next['ordinal']) {
                        throw new \RuntimeException('media_checkpoint_invalid');
                    }
                    $status = 'applied';
                    $error = $fetchError ?? null;
                    $after = null;
                    if ($download === null) {
                        $status = 'failed';
                    } else {
                        try {
                            $result = $this->ingest->ingestBytes(
                                $supplierId,
                                (int) $locked['stock_item_id'],
                                $download['body'],
                                $download['original_name'],
                            );
                            $after = [
                                'media_id' => (int) $result['media']['id'],
                                'created' => $result['created'],
                                'url_hash' => (string) $locked['input']['media']['url_hash'],
                            ];
                            $status = $result['created'] ? 'applied' : 'unchanged';
                        } catch (DocumentException $e) {
                            if (!in_array($e->errorCode, ['unsupported_type', 'executable_blocked', 'empty_file', 'file_too_large'], true)) {
                                throw $e;
                            }
                            $status = 'failed';
                            $error = 'media_' . $e->errorCode;
                        }
                    }
                    $this->items->finish($supplierId, $current['id'], $locked['ordinal'], $status, null, $after, $error);
                    $checkpoint = $locked['ordinal'];
                    return [
                        'checkpoint' => $checkpoint,
                        'done' => $checkpoint === $current['total'],
                        'report' => [
                            'source_job_id' => (int) $current['input']['source_job_id'],
                            'processed' => $checkpoint,
                            'counts' => $this->items->counts($supplierId, $current['id']),
                        ],
                    ];
                });
                unset($fetchError);
                if ($job['status'] !== 'running') {
                    return $job;
                }
            }
            $this->jobs->release($supplierId, $job['id'], $token);
            return $this->jobs->find($supplierId, $job['id']);
        } catch (\Throwable $e) {
            $this->jobs->fail($supplierId, $job['id'], $token, 'catalog_media_import_failed');
            throw $e;
        }
    }
}
