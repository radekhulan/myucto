<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Eshop\CatalogJobService;

final class IntegrationReconcileService
{
    public const KIND = 'integration_reconcile';

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
    ) {}

    public function enqueue(int $supplierId, int $connectionId, ?int $createdBy = null): int
    {
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }
        try {
            $connection = $pdo->prepare('SELECT id FROM integration_connections WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $connection->execute([$supplierId, $connectionId]);
            if ($connection->fetchColumn() === false) {
                if ($ownTransaction) {
                    $pdo->commit();
                }
                return 0;
            }
            $active = $pdo->prepare("SELECT id FROM catalog_jobs WHERE supplier_id = ? AND kind = ?
                AND status IN ('queued','running') AND JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.connection_id')) = ? ORDER BY id LIMIT 1 FOR UPDATE");
            $active->execute([$supplierId, self::KIND, (string) $connectionId]);
            $existing = $active->fetchColumn();
            if ($existing !== false) {
                if ($ownTransaction) {
                    $pdo->commit();
                }
                return (int) $existing;
            }
            $bounds = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(MAX(id), 0) AS max_map_id
                FROM external_entity_map WHERE supplier_id = ? AND connection_id = ?');
            $bounds->execute([$supplierId, $connectionId]);
            $snapshot = $bounds->fetch(\PDO::FETCH_ASSOC);
            $id = $this->jobs->enqueue($supplierId, self::KIND, [
                'connection_id' => $connectionId,
                'max_map_id' => (int) ($snapshot['max_map_id'] ?? 0),
            ], (int) ($snapshot['total'] ?? 0), 1, $createdBy);
            $pdo->prepare('UPDATE integration_connections SET last_reconcile_enqueued_at = NOW(6) WHERE supplier_id = ? AND id = ?')
                ->execute([$supplierId, $connectionId]);
            if ($ownTransaction) {
                $pdo->commit();
            }
            return $id;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function enqueueDue(): int
    {
        $stmt = $this->db->pdo()->query("SELECT supplier_id, id FROM integration_connections
            WHERE status = 'active' AND (last_reconcile_enqueued_at IS NULL
                OR last_reconcile_enqueued_at < DATE_SUB(NOW(6), INTERVAL 24 HOUR)) ORDER BY supplier_id, id LIMIT 100");
        $count = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($this->enqueue((int) $row['supplier_id'], (int) $row['id']) > 0) {
                $count++;
            }
        }
        return $count;
    }
}
