<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Eshop\CatalogJobService;
use PDO;

final class IntegrationReconcileWorker
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
    ) {}

    public function tick(int $supplierId, int $maxBatches = 10): ?array
    {
        $job = $this->jobs->claim($supplierId, IntegrationReconcileService::KIND);
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
            $this->jobs->fail($supplierId, $job['id'], $token, 'integration_reconcile_failed');
            $this->db->pdo()->prepare("UPDATE integration_connections SET status = 'error',
                last_error_code = 'integration_reconcile_failed', last_error_at = NOW(6)
                WHERE supplier_id = ? AND id = ?")
                ->execute([$supplierId, (int) ($job['input']['connection_id'] ?? 0)]);
            throw $e;
        }
    }

    private function processBatch(int $supplierId, array $job): array
    {
        if ($job['input_version'] !== 1 || (int) ($job['input']['connection_id'] ?? 0) < 1
            || !array_key_exists('max_map_id', $job['input']) || (int) $job['input']['max_map_id'] < 0) {
            throw new \RuntimeException('unsupported_input_version');
        }
        $connectionId = (int) $job['input']['connection_id'];
        $maxMapId = (int) $job['input']['max_map_id'];
        $report = is_array($job['report']) ? $job['report'] : [];
        $lastMapId = (int) ($report['_last_map_id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('SELECT id, entity_type, internal_id FROM external_entity_map
            WHERE supplier_id = ? AND connection_id = ? AND id > ? AND id <= ?
            ORDER BY id LIMIT ' . self::BATCH_SIZE);
        $stmt->execute([$supplierId, $connectionId, $lastMapId, $maxMapId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $report += ['processed' => 0, 'missing_internal' => 0, 'unchecked' => 0];
        foreach ($rows as $row) {
            $lastMapId = (int) $row['id'];
            $report['processed']++;
            if ($row['entity_type'] !== 'stock_item') {
                $report['unchecked']++;
                continue;
            }
            $check = $this->db->pdo()->prepare('SELECT 1 FROM stock_items WHERE supplier_id = ? AND id = ?');
            $check->execute([$supplierId, $row['internal_id']]);
            if ($check->fetchColumn() === false) {
                $report['missing_internal']++;
            }
        }
        $checkpoint = (int) $job['checkpoint'] + count($rows);
        $done = count($rows) < self::BATCH_SIZE || $lastMapId >= $maxMapId || $checkpoint >= (int) $job['total'];
        if ($done) {
            $checkpoint = (int) $job['total'];
            unset($report['_last_map_id']);
            $this->markCompleted($supplierId, $connectionId, $report);
        } else {
            $report['_last_map_id'] = $lastMapId;
        }
        return ['checkpoint' => $checkpoint, 'done' => $done, 'report' => $report];
    }

    private function markCompleted(int $supplierId, int $connectionId, array $report): void
    {
        $error = ((int) ($report['missing_internal'] ?? 0)) > 0 ? 'integration_mapping_incomplete' : null;
        $stmt = $this->db->pdo()->prepare("UPDATE integration_connections SET last_synced_at = NOW(6),
            status = IF(? IS NULL AND status = 'error', 'active', status), last_error_code = ?,
            last_error_at = IF(? IS NULL, NULL, NOW(6)) WHERE supplier_id = ? AND id = ?");
        $stmt->execute([$error, $error, $error, $supplierId, $connectionId]);
    }
}
