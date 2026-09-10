<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Eshop\CatalogJobService;

final class SalesOrderExpiryJobService
{
    public const KIND = 'sales_order_expiry';

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly SalesOrderService $orders,
    ) {}

    public function enqueue(int $supplierId, ?int $userId): array
    {
        $row = $this->db->pdo()->prepare(
            "SELECT COUNT(*) total, COALESCE(MAX(id), 0) max_id FROM sales_orders
              WHERE supplier_id = ? AND commercial_status = 'confirmed'
                AND reservation_expires_at IS NOT NULL AND reservation_expires_at <= NOW()"
        );
        $row->execute([$supplierId]);
        $snapshot = $row->fetch(\PDO::FETCH_ASSOC) ?: ['total' => 0, 'max_id' => 0];
        $id = $this->jobs->enqueue($supplierId, self::KIND, ['max_id' => (int) $snapshot['max_id']], (int) $snapshot['total'], 1, $userId);
        return $this->jobs->find($supplierId, $id) ?? throw new \LogicException('Úloha nevznikla.');
    }

    public function find(int $supplierId, int $jobId): ?array
    {
        $job = $this->jobs->find($supplierId, $jobId);
        return $job !== null && $job['kind'] === self::KIND ? $job : null;
    }

    public function runNextBatch(int $supplierId, int $batchSize = 100): ?array
    {
        $job = $this->jobs->claim($supplierId, self::KIND);
        if ($job === null) return null;
        try {
            $result = $this->jobs->batch($supplierId, $job['id'], $job['lease_token'], function (array $current) use ($supplierId, $batchSize): array {
                $report = is_array($current['report']) ? $current['report'] : [];
                $cursor = (int) ($report['cursor_id'] ?? 0);
                $maxId = (int) ($current['input']['max_id'] ?? 0);
                $stmt = $this->db->pdo()->prepare(
                    "SELECT id FROM sales_orders WHERE supplier_id = ? AND id > ? AND id <= ?
                       AND commercial_status = 'confirmed' AND reservation_expires_at IS NOT NULL
                       AND reservation_expires_at <= NOW() ORDER BY id LIMIT " . max(1, min(500, $batchSize))
                );
                $stmt->execute([$supplierId, $cursor, $maxId]);
                $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
                $expired = (int) ($report['expired'] ?? 0);
                foreach ($ids as $id) {
                    $this->orders->expire($supplierId, $id);
                    ++$expired;
                    $cursor = $id;
                }
                $checkpoint = min((int) $current['total'], (int) $current['checkpoint'] + count($ids));
                $done = $ids === [] || $cursor >= $maxId || $checkpoint >= (int) $current['total'];
                return ['checkpoint' => $checkpoint, 'done' => $done, 'report' => ['cursor_id' => $cursor, 'expired' => $expired]];
            });
            if ($result['status'] === 'running') $this->jobs->release($supplierId, $job['id'], $job['lease_token']);
            return $this->jobs->find($supplierId, $job['id']);
        } catch (\Throwable $error) {
            $this->jobs->fail($supplierId, $job['id'], $job['lease_token'], 'sales_order_expiry_failed');
            throw $error;
        }
    }

    public function tick(int $supplierId, int $maxBatches = 10): ?array
    {
        $result = null;
        for ($i = 0; $i < max(1, min(100, $maxBatches)); ++$i) {
            $batch = $this->runNextBatch($supplierId);
            if ($batch === null) break;
            $result = $batch;
        }
        return $result;
    }
}
