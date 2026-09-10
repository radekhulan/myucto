<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Service\Integration\IntegrationReconcileService;
use MyInvoice\Service\Integration\IntegrationReconcileWorker;
use MyInvoice\Tests\Integration\Stock\StockTestCase;

final class IntegrationReconcileConcurrencyTest extends StockTestCase
{
    public function testDeletedUnprocessedMappingDoesNotLeaveJobStuck(): void
    {
        $supplierId = $this->createSupplier();
        $connectionId = $this->connection($supplierId);
        $itemId = $this->item($supplierId, 'RECONCILE-DELETED');
        $mapIds = $this->maps($supplierId, $connectionId, $itemId, 'deleted');
        $jobs = $this->container->get(IntegrationReconcileService::class);
        $worker = $this->container->get(IntegrationReconcileWorker::class);

        $jobId = $jobs->enqueue($supplierId, $connectionId, $this->userId);
        self::assertSame($jobId, $jobs->enqueue($supplierId, $connectionId, $this->userId));
        self::assertSame(200, $worker->tick($supplierId, 1)['checkpoint']);
        $this->db->pdo()->prepare('DELETE FROM external_entity_map WHERE supplier_id = ? AND id = ?')
            ->execute([$supplierId, $mapIds[200]]);

        $completed = $worker->tick($supplierId, 2);
        self::assertSame('completed', $completed['status']);
        self::assertSame(201, $completed['checkpoint']);
        self::assertSame(201, $completed['total']);
        self::assertSame(200, $completed['report']['processed']);
        self::assertArrayNotHasKey('_last_map_id', $completed['report']);
    }

    public function testSnapshotUsesKeysetWhenMappingsChangeBetweenBatches(): void
    {
        $supplierId = $this->createSupplier();
        $connectionId = $this->connection($supplierId);
        $itemId = $this->item($supplierId, 'RECONCILE-CONCURRENT');
        $mapIds = $this->maps($supplierId, $connectionId, $itemId, 'bounded');
        $insert = $this->db->pdo()->prepare('INSERT INTO external_entity_map
            (supplier_id, connection_id, source_key, entity_type, external_id, internal_id)
            VALUES (?, ?, ?, ?, ?, ?)');

        $jobs = $this->container->get(IntegrationReconcileService::class);
        $worker = $this->container->get(IntegrationReconcileWorker::class);
        $jobId = $jobs->enqueue($supplierId, $connectionId, $this->userId);
        self::assertSame($jobId, $jobs->enqueue($supplierId, $connectionId, $this->userId));

        $first = $worker->tick($supplierId, 1);
        self::assertSame('queued', $first['status']);
        self::assertSame(200, $first['checkpoint']);
        self::assertSame(201, $first['total']);

        $this->db->pdo()->prepare('DELETE FROM external_entity_map WHERE supplier_id = ? AND id = ?')
            ->execute([$supplierId, $mapIds[0]]);
        $insert->execute([$supplierId, $connectionId, 'synthetic', 'stock_item',
            'created-after-snapshot', 999999999]);

        $completed = $worker->tick($supplierId, 2);
        self::assertSame('completed', $completed['status']);
        self::assertSame(201, $completed['checkpoint']);
        self::assertSame(201, $completed['total']);
        self::assertSame(201, $completed['report']['processed']);
        self::assertSame(201, $completed['report']['unchecked']);
        self::assertSame(0, $completed['report']['missing_internal']);
        self::assertArrayNotHasKey('_last_map_id', $completed['report']);
        self::assertNotNull($this->db->pdo()->query('SELECT last_synced_at FROM integration_connections
            WHERE id = ' . $connectionId)->fetchColumn());
    }

    private function maps(int $supplierId, int $connectionId, int $itemId, string $prefix): array
    {
        $insert = $this->db->pdo()->prepare('INSERT INTO external_entity_map
            (supplier_id, connection_id, source_key, entity_type, external_id, internal_id)
            VALUES (?, ?, ?, ?, ?, ?)');
        $ids = [];
        for ($i = 1; $i <= 201; $i++) {
            $insert->execute([$supplierId, $connectionId, 'synthetic', 'product_variant',
                sprintf('%s-%03d', $prefix, $i), $itemId]);
            $ids[] = (int) $this->db->pdo()->lastInsertId();
        }
        return $ids;
    }

    private function connection(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO integration_connections
            (connection_uuid, supplier_id, connector_key, name, status, mappings_json, field_ownership_json, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            sprintf('00000000-0000-4000-8000-%012d', $supplierId),
            $supplierId,
            'synthetic.adapter',
            'Synthetic reconcile ' . $supplierId,
            'active',
            '{}',
            '{}',
            $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
