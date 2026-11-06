<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Allocation;

use MyInvoice\Infrastructure\Database\Connection;

final class JointAllocationAudit
{
    public function __construct(private readonly Connection $db) {}

    public function record(int $supplierId, string $entity, int $id, string $action, array $payload, ?int $actor): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Allocation audit requires the owning transaction.');
        }
        $this->db->pdo()->prepare('INSERT INTO joint_allocation_audit (supplier_id, entity_type, entity_id, action, payload_json, actor_id, actor_source) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$supplierId, $entity, $id, $action, JointAllocationKeyVersion::encodeJson($payload), $actor, $actor === null ? 'system' : 'user']);
    }
}
