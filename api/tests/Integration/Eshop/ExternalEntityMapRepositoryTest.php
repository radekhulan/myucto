<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\ExternalEntityMapRepository;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PDOException;

final class ExternalEntityMapRepositoryTest extends StockTestCase
{
    public function testEqualVersionIsReplayOnlyAndHigherVersionWins(): void
    {
        $supplierId = $this->createSupplier();
        $connectionId = $this->connection($supplierId);
        $firstItem = $this->item($supplierId, 'MAP-VERSION-FIRST');
        $secondItem = $this->item($supplierId, 'MAP-VERSION-SECOND');
        $maps = $this->container->get(ExternalEntityMapRepository::class);

        $created = $maps->put($supplierId, $connectionId, 'synthetic', 'product_variant',
            'versioned-external', $firstItem, 'parent-a', 'variant-a', 7);
        self::assertSame($created, $maps->put($supplierId, $connectionId, 'synthetic', 'product_variant',
            'versioned-external', $firstItem, 'parent-a', 'variant-a', 7));

        try {
            $maps->put($supplierId, $connectionId, 'synthetic', 'product_variant',
                'versioned-external', $secondItem, 'parent-b', 'variant-b', 7);
            self::fail('Conflicting replay with the same external version was accepted.');
        } catch (\RuntimeException $e) {
            self::assertSame('external_entity_map_version_conflict', $e->getMessage());
        }

        $older = $maps->put($supplierId, $connectionId, 'synthetic', 'product_variant',
            'versioned-external', $secondItem, 'parent-b', 'variant-b', 6);
        self::assertSame($firstItem, $older['internal_id']);
        self::assertSame(7, $older['external_version']);

        $newer = $maps->put($supplierId, $connectionId, 'synthetic', 'product_variant',
            'versioned-external', $secondItem, 'parent-b', 'variant-b', 8);
        self::assertSame($secondItem, $newer['internal_id']);
        self::assertSame(8, $newer['external_version']);
    }

    public function testConnectionIdentityCannotSplitAcrossSourceKeys(): void
    {
        $supplierId = $this->createSupplier();
        $connectionId = $this->connection($supplierId);
        $itemId = $this->item($supplierId, 'MAP-UNIQUE');
        $insert = $this->db->pdo()->prepare('INSERT INTO external_entity_map
            (supplier_id, connection_id, source_key, entity_type, external_id, internal_id)
            VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([$supplierId, $connectionId, 'source-a', 'product_variant', 'same-external', $itemId]);

        try {
            $insert->execute([$supplierId, $connectionId, 'source-b', 'product_variant', 'same-external', $itemId]);
            self::fail('Connection-scoped identity was split by source_key.');
        } catch (PDOException $e) {
            self::assertSame(1062, (int) ($e->errorInfo[1] ?? 0));
        }
    }

    private function connection(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO integration_connections
            (connection_uuid, supplier_id, connector_key, name, status, mappings_json, field_ownership_json, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            sprintf('10000000-0000-4000-8000-%012d', $supplierId),
            $supplierId,
            'synthetic.adapter',
            'Synthetic map ' . $supplierId,
            'active',
            '{}',
            '{}',
            $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
