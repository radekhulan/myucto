<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Repository\CatalogJobItemRepository;

final class CatalogExportService
{
    public const KIND = 'catalog_export';

    public function __construct(
        private readonly CatalogSelectionService $selection,
        private readonly CatalogJobService $jobs,
        private readonly CatalogJobItemRepository $items,
    ) {}

    public function enqueue(int $supplierId, array $selection, array $projection, ?int $createdBy = null): array
    {
        if (array_key_exists('ids', $projection)) {
            throw new \InvalidArgumentException('ID patří do výběru, ne do projekce.');
        }
        $projection = CatalogReadRequest::products(['ids' => [1]] + $projection);
        if (in_array('costs', $projection['fields'], true)) {
            throw new EshopException('forbidden_projection', 'Export neobsahuje nákladové údaje.', 403);
        }
        unset($projection['ids']);
        if ($projection['warehouse_ids'] === []) {
            unset($projection['warehouse_ids']);
        }
        $id = $this->selection->enqueue($supplierId, self::KIND, ['projection' => $projection], $selection, $createdBy);
        return $this->jobs->find($supplierId, $id);
    }

    public function download(int $supplierId, int $id): mixed
    {
        $job = $this->jobs->find($supplierId, $id);
        if ($job === null || $job['kind'] !== self::KIND) {
            throw new EshopException('not_found', 'Export nenalezen.', 404);
        }
        if ($job['status'] !== 'completed') {
            throw new EshopException('export_not_ready', 'Export ještě není dokončen.', 409);
        }
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('export_stream_failed');
        }
        try {
            $this->write($stream, ['type' => 'manifest', 'format_version' => 1, 'job_id' => $id,
                'total' => $job['total'], 'projection' => $job['input']['projection'],
                'created_at' => $job['created_at'], 'finished_at' => $job['finished_at'],
                'consistency' => 'selection_versions_with_per_batch_snapshot']);
            $after = 0;
            while (($batch = $this->items->batch($supplierId, $id, $after, 500)) !== []) {
                foreach ($batch as $item) {
                    $this->write($stream, ['type' => 'product', 'ordinal' => $item['ordinal'],
                        'id' => $item['stock_item_id'], 'status' => $item['status'] === 'ready' ? 'ok' : $item['status'],
                        'expected_version' => $item['expected_version'], 'error_code' => $item['error_code'],
                        'captured_at' => $item['after']['captured_at'] ?? null, 'data' => $item['after']['data'] ?? null]);
                    $after = $item['ordinal'];
                }
            }
            rewind($stream);
            return $stream;
        } catch (\Throwable $e) {
            fclose($stream);
            throw $e;
        }
    }

    private function write(mixed $stream, array $row): void
    {
        $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if (fwrite($stream, $line) !== strlen($line)) {
            throw new \RuntimeException('export_write_failed');
        }
    }
}
