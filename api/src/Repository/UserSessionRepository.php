<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Provozní přehled platných relací bez autentizačních a CSRF tokenů. */
final class UserSessionRepository
{
    private const PROJECTION = 'LOWER(HEX(s.session_family_id)) AS id, u.id AS user_id, u.name, u.email,
                    INET6_NTOA(s.ip) AS ip, s.user_agent, UNIX_TIMESTAMP(s.created_at) AS created_at,
                    UNIX_TIMESTAMP(s.last_seen) AS last_seen,
                    UNIX_TIMESTAMP(s.expires_at) AS expires_at, s.locked_at,
                    s.last_seen >= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 5 MINUTE) AS recent_contact';

    public function __construct(private readonly Connection $db) {}

    public function page(string $beforeSeen, string $beforeFamily, int $limit, string $currentFamily): array
    {
        // Celoinstanční přehled vychází z indexu aktuálních generací podle kontaktu.
        // Kurzor zachovává sestupné pořadí i mezi stránkami a shodnými časy.
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::PROJECTION . '
               FROM sessions s FORCE INDEX (idx_sessions_admin_last_seen) STRAIGHT_JOIN users u ON u.id = s.user_id
              WHERE s.revoked_at IS NULL AND s.replaced_at IS NULL
                AND s.expires_at > CURRENT_TIMESTAMP(6) AND u.is_active = 1
                AND s.session_family_id <> UNHEX(?)
                AND (s.last_seen < FROM_UNIXTIME(?) OR (s.last_seen = FROM_UNIXTIME(?) AND s.session_family_id < UNHEX(?)))
              ORDER BY s.last_seen DESC, s.session_family_id DESC LIMIT ' . ($limit + 1)
        );
        $stmt->execute([$currentFamily, $beforeSeen, $beforeSeen, $beforeFamily]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Vlastní relace je připnutá mimo kurzor, aby zůstala první na každé stránce. */
    public function current(string $family): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::PROJECTION . '
            FROM sessions s JOIN users u ON u.id = s.user_id
            WHERE s.session_family_id = UNHEX(?) AND s.revoked_at IS NULL AND s.replaced_at IS NULL
              AND s.expires_at > CURRENT_TIMESTAMP(6) AND u.is_active = 1
            ORDER BY s.generation DESC LIMIT 1');
        $stmt->execute([$family]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Ostatní nedávno připojené relace pro výstrahu před přerušením provozu. */
    public function otherRecentCount(string $currentFamily): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*)
            FROM sessions s FORCE INDEX (idx_sessions_admin_last_seen) STRAIGHT_JOIN users u ON u.id = s.user_id
            WHERE s.revoked_at IS NULL AND s.replaced_at IS NULL
              AND s.last_seen >= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 5 MINUTE)
              AND s.expires_at > CURRENT_TIMESTAMP(6) AND u.is_active = 1
              AND s.session_family_id <> UNHEX(?)');
        $stmt->execute([$currentFamily]);
        return (int) $stmt->fetchColumn();
    }

    /** Token zůstává jen uvnitř backendu pro společnou revokaci lineage. */
    public function revocable(string $family): ?array
    {
        return $this->revocableMany([$family])[0] ?? null;
    }

    /** @param list<string> $families */
    public function revocableMany(array $families): array
    {
        $placeholders = implode(',', array_fill(0, count($families), 'UNHEX(?)'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id AS token, user_id, LOWER(HEX(session_family_id)) AS family FROM sessions
              WHERE session_family_id IN (' . $placeholders . ') AND revoked_at IS NULL
                AND replaced_at IS NULL AND expires_at > CURRENT_TIMESTAMP(6)
              ORDER BY generation DESC'
        );
        $stmt->execute($families);
        $byFamily = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $byFamily[$row['family']] ??= $row;
        return array_values($byFamily);
    }
}
