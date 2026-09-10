<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class IntegrationDiagnosticsService
{
    public function __construct(private readonly Connection $db) {}

    public function overview(int $supplierId, int $connectionId): ?array
    {
        $exists = $this->db->pdo()->prepare('SELECT last_synced_at, last_error_code, last_error_at
            FROM integration_connections WHERE supplier_id = ? AND id = ?');
        $exists->execute([$supplierId, $connectionId]);
        $connection = $exists->fetch(PDO::FETCH_ASSOC);
        if ($connection === false) {
            return null;
        }
        $result = $connection;
        foreach (['inbox' => 'integration_inbox', 'outbox' => 'integration_outbox'] as $key => $table) {
            $stmt = $this->db->pdo()->prepare("SELECT status, COUNT(*) AS count FROM {$table}
                WHERE supplier_id = ? AND connection_id = ? GROUP BY status");
            $stmt->execute([$supplierId, $connectionId]);
            $result[$key] = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[$key][$row['status']] = (int) $row['count'];
            }
        }
        $errors = $this->db->pdo()->prepare("SELECT 'outbox' AS direction, id, entity_type, entity_id,
                event_type, aggregate_version, status, attempts, last_error_code,
                payload_redacted_at IS NOT NULL AS payload_redacted, created_at AS occurred_at
            FROM integration_outbox WHERE supplier_id = ? AND connection_id = ? AND status IN ('retry','dead_letter')
            UNION ALL SELECT 'inbox', id, entity_type, entity_id, event_type, aggregate_version,
                status, attempts, last_error_code, payload_redacted_at IS NOT NULL, received_at
            FROM integration_inbox WHERE supplier_id = ? AND connection_id = ? AND status IN ('retry','dead_letter')
            ORDER BY occurred_at DESC LIMIT 100");
        $errors->execute([$supplierId, $connectionId, $supplierId, $connectionId]);
        $result['errors'] = array_map(static function (array $row): array {
            foreach (['id', 'aggregate_version', 'attempts'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['payload_redacted'] = (bool) $row['payload_redacted'];
            return $row;
        }, $errors->fetchAll(PDO::FETCH_ASSOC));
        return $result;
    }

    public function purgeExpired(): array
    {
        $pdo = $this->db->pdo();
        $removed = 0;
        foreach ([
            ['integration_inbox', 'received_at', "('processed','ignored')"],
            ['integration_outbox', 'created_at', "('delivered')"],
        ] as [$table, $date, $states]) {
            $stmt = $pdo->prepare("DELETE e FROM {$table} e JOIN integration_connections c ON c.id = e.connection_id
                AND c.supplier_id = e.supplier_id WHERE e.status IN {$states}
                AND e.{$date} < DATE_SUB(NOW(6), INTERVAL c.retention_days DAY)");
            $stmt->execute();
            $removed += $stmt->rowCount();
        }
        $redacted = 0;
        foreach (['integration_inbox' => 'received_at', 'integration_outbox' => 'created_at'] as $table => $date) {
            $stmt = $pdo->prepare("UPDATE {$table} e JOIN integration_connections c ON c.id = e.connection_id
                AND c.supplier_id = e.supplier_id SET e.payload_json = '{}', e.payload_redacted_at = NOW(6)
                WHERE e.status = 'dead_letter' AND e.payload_redacted_at IS NULL
                AND e.{$date} < DATE_SUB(NOW(6), INTERVAL c.retention_days DAY)");
            $stmt->execute();
            $redacted += $stmt->rowCount();
        }
        $jobs = $pdo->prepare("UPDATE catalog_jobs j JOIN integration_connections c
            ON c.supplier_id = j.supplier_id AND c.id = JSON_VALUE(j.input_json, '$.connection_id')
            SET j.input_json = JSON_OBJECT('connection_id', c.id, 'retained', FALSE), j.report_json = '{}'
            WHERE j.kind = 'integration_reconcile' AND j.finished_at IS NOT NULL
              AND j.finished_at < DATE_SUB(NOW(6), INTERVAL c.retention_days DAY)");
        $jobs->execute();
        return ['removed' => $removed, 'redacted' => $redacted, 'reports_redacted' => $jobs->rowCount()];
    }
}
