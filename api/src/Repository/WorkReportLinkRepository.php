<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Úložiště pro veřejné sledovací odkazy na výkaz práce (migrace 0112).
 *
 * Tři tabulky:
 *   work_report_links          — trvalý odkaz na klienta/zakázku
 *   work_report_link_codes     — jednorázové e-mailové kódy (sha256 hash)
 *   work_report_link_sessions  — ověřená zařízení (sha256 hash session tokenu z cookie)
 *
 * Token odkazu: bin2hex(random_bytes(24)) → 48 hex znaků (stejný formát jako
 * approval token, validuje ApprovalTokenValidator).
 */
final class WorkReportLinkRepository
{
    public function __construct(private readonly Connection $db) {}

    // -- links -------------------------------------------------------------

    /** Aktivní (nerevokovaný) odkaz pro danou entitu, nebo null. */
    public function findActiveByEntity(int $supplierId, string $scope, int $clientId, ?int $projectId): ?array
    {
        $pdo = $this->db->pdo();
        if ($projectId === null) {
            $stmt = $pdo->prepare(
                'SELECT * FROM work_report_links
                  WHERE supplier_id = ? AND scope = ? AND client_id = ? AND project_id IS NULL
                    AND revoked_at IS NULL
                  ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$supplierId, $scope, $clientId]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT * FROM work_report_links
                  WHERE supplier_id = ? AND scope = ? AND client_id = ? AND project_id = ?
                    AND revoked_at IS NULL
                  ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$supplierId, $scope, $clientId, $projectId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** Aktivní (nerevokovaný) odkaz dle tokenu, nebo null. */
    public function findActiveByToken(string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM work_report_links WHERE token = ? AND revoked_at IS NULL LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** Vytvoří nový odkaz a vrátí jeho řádek (vč. tokenu). */
    public function create(int $supplierId, string $scope, int $clientId, ?int $projectId, ?int $userId): array
    {
        $token = bin2hex(random_bytes(24)); // 48 hex znaků
        $this->db->pdo()->prepare(
            'INSERT INTO work_report_links (supplier_id, scope, client_id, project_id, token, created_by_user_id)
             VALUES (?,?,?,?,?,?)'
        )->execute([$supplierId, $scope, $clientId, $projectId, $token, $userId]);

        $row = $this->findActiveByToken($token);
        // V krajně nepravděpodobném případě kolize/race vrátíme aspoň skeleton.
        return $row ?? [
            'id'          => (int) $this->db->pdo()->lastInsertId(),
            'supplier_id' => $supplierId,
            'scope'       => $scope,
            'client_id'   => $clientId,
            'project_id'  => $projectId,
            'token'       => $token,
        ];
    }

    /** Revokuje odkaz (a tím i všechny jeho ověřené relace). */
    public function revoke(int $linkId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE work_report_links SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL')
            ->execute([$linkId]);
        $pdo->prepare('UPDATE work_report_link_sessions SET revoked_at = NOW() WHERE link_id = ? AND revoked_at IS NULL')
            ->execute([$linkId]);
    }

    public function touchSent(int $linkId): void
    {
        $this->db->pdo()->prepare('UPDATE work_report_links SET last_sent_at = NOW() WHERE id = ?')
            ->execute([$linkId]);
    }

    public function touchViewed(int $linkId): void
    {
        $this->db->pdo()->prepare('UPDATE work_report_links SET last_viewed_at = NOW() WHERE id = ?')
            ->execute([$linkId]);
    }

    // -- codes -------------------------------------------------------------

    /** Poslední aktivní (nepoužitý, neexpirovaný) kód pro link+email. */
    public function activeCode(int $linkId, string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code_hash, attempts, UNIX_TIMESTAMP(created_at) AS created_ts
               FROM work_report_link_codes
              WHERE link_id = ? AND email = ? AND used_at IS NULL AND expires_at > NOW()
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$linkId, $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function invalidateCodes(int $linkId, string $email): void
    {
        $this->db->pdo()->prepare(
            'UPDATE work_report_link_codes SET used_at = NOW() WHERE link_id = ? AND email = ? AND used_at IS NULL'
        )->execute([$linkId, $email]);
    }

    public function insertCode(int $linkId, string $email, string $codeHash, string $expiresAt, ?string $ipBin): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO work_report_link_codes (link_id, email, code_hash, expires_at, ip) VALUES (?,?,?,?,?)'
        )->execute([$linkId, $email, $codeHash, $expiresAt, $ipBin]);
    }

    public function markCodeUsed(int $codeId): void
    {
        $this->db->pdo()->prepare('UPDATE work_report_link_codes SET used_at = NOW() WHERE id = ?')
            ->execute([$codeId]);
    }

    public function consumeCode(int $codeId, int $maxAttempts): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE work_report_link_codes SET used_at = NOW()
              WHERE id = ? AND used_at IS NULL AND expires_at > NOW() AND attempts < ?'
        );
        $stmt->execute([$codeId, $maxAttempts]);
        return $stmt->rowCount() === 1;
    }

    public function bumpCodeAttempts(int $codeId, int $maxAttempts): void
    {
        $this->db->pdo()->prepare(
            'UPDATE work_report_link_codes SET attempts = attempts + 1
              WHERE id = ? AND used_at IS NULL AND expires_at > NOW() AND attempts < ?'
        )->execute([$codeId, $maxAttempts]);
    }

    // -- sessions ----------------------------------------------------------

    public function createSession(int $linkId, string $email, string $sessionHash, ?string $ipBin): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO work_report_link_sessions (link_id, email, session_hash, last_seen_at, ip)
             VALUES (?,?,?,NOW(),?)'
        )->execute([$linkId, $email, $sessionHash, $ipBin]);
    }

    /** Platná (nerevokovaná) relace pro link+hash; ověřuje i revoke odkazu. */
    public function findActiveSession(int $linkId, string $sessionHash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.email
               FROM work_report_link_sessions s
               JOIN work_report_links l ON l.id = s.link_id
              WHERE s.link_id = ? AND s.session_hash = ?
                AND s.revoked_at IS NULL AND l.revoked_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$linkId, $sessionHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function touchSession(int $sessionId): void
    {
        $this->db->pdo()->prepare('UPDATE work_report_link_sessions SET last_seen_at = NOW() WHERE id = ?')
            ->execute([$sessionId]);
    }

    /** Údržba: smaž expirované/použité kódy starší než 1 den (volá cron-cleanup). */
    public function pruneExpiredCodes(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM work_report_link_codes
              WHERE expires_at < NOW() - INTERVAL 1 DAY OR used_at < NOW() - INTERVAL 1 DAY'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}
