<?php

declare(strict_types=1);

namespace MyInvoice\Service;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Aktivita jednoho dokladu (detail faktury a přijaté faktury).
 *
 * Endpoint čtou i klientské a read-only role a API tokeny, proto projekce
 * nevrací IP adresy ani e-maily uživatelů. Úplný záznam včetně IP zůstává
 * v administraci (log aktivit). Stejně se z payloadu odstraňují technické
 * klíče s IP a user agentem, které tam ukládají některé akce.
 */
final class DocumentActivityFeed
{
    private const HIDDEN_PAYLOAD_KEYS = ['ip', 'client_ip', 'remote_ip', 'ip_address', 'user_agent'];

    public function __construct(private readonly Connection $db) {}

    /** @return list<array{id:int, user_id:?int, user_name:?string, action:string, payload:mixed, created_at:string}> */
    public function forEntity(string $entityType, int $entityId, int $limit = 200): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT al.id, al.user_id, u.name AS user_name, al.action, al.payload, al.created_at
               FROM activity_log al
          LEFT JOIN users u ON u.id = al.user_id
              WHERE al.entity_type = ? AND al.entity_id = ?
           ORDER BY al.id DESC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$entityType, $entityId]);

        return array_map(self::project(...), $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int, user_id:?int, user_name:?string, action:string, payload:mixed, created_at:string}
     */
    public static function project(array $row): array
    {
        $payload = $row['payload'] ?? null;
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (is_array($payload)) {
            $payload = array_diff_key($payload, array_flip(self::HIDDEN_PAYLOAD_KEYS));
        }
        $name = trim((string) ($row['user_name'] ?? ''));

        return [
            'id'         => (int) $row['id'],
            'user_id'    => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'user_name'  => $name !== '' ? $name : null,
            'action'     => (string) $row['action'],
            'payload'    => $payload,
            'created_at' => (string) $row['created_at'],
        ];
    }
}
