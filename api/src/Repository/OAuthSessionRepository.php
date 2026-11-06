<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro oauth_sessions — rozpracovaná OAuth přihlášení k externím službám
 * ({@see \MyInvoice\Service\OAuth\OAuthClientFlow}). Řádek se hledá jen podle hashe
 * state; ciphertext vrací pouze metody, které ho flow potřebuje rozšifrovat.
 */
final class OAuthSessionRepository
{
    private const COLUMNS = 'state_hash, supplier_id, user_id, domain, provider, subject_id, flow, stage, status,
        error_code, expires_at, (expires_at <= CURRENT_TIMESTAMP(6)) AS expired';

    public function __construct(private readonly Connection $db) {}

    /**
     * Nové rozpracované přihlášení. Předchozí nedokončené přihlášení téhož napojení
     * se ukončí, platí vždy jen poslední.
     */
    public function create(string $stateHash, int $supplierId, int $userId, string $domain, string $provider, int $subjectId,
        string $flow, string $stage, string $ciphertext, int $lifetimeSeconds): void
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE oauth_sessions
                    SET status = 'failed', error_code = 'restarted', secret_ciphertext = '', consumed_at = CURRENT_TIMESTAMP(6)
                  WHERE supplier_id = ? AND domain = ? AND provider = ? AND subject_id = ? AND status IN ('pending','processing')"
            )->execute([$supplierId, $domain, $provider, $subjectId]);
            $pdo->prepare(
                'INSERT INTO oauth_sessions
                    (state_hash, supplier_id, user_id, domain, provider, subject_id, flow, stage, secret_ciphertext, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TIMESTAMPADD(SECOND, ?, CURRENT_TIMESTAMP(6)))'
            )->execute([$stateHash, $supplierId, $userId, $domain, $provider, $subjectId, $flow, $stage, $ciphertext, $lifetimeSeconds]);
            if ($own) $pdo->commit();
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Údaje pro rozeslání callbacku (bez tajemství), v jakémkoli stavu. */
    public function find(string $stateHash): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM oauth_sessions WHERE state_hash = ?');
        $stmt->execute([$stateHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Jednorázově převezme čekající přihlášení (pending → processing). Vrací řádek
     * s ciphertextem, nebo null, když přihlášení neexistuje, patří jinému uživateli
     * nebo firmě, vypršelo nebo už bylo použité.
     */
    public function claim(string $stateHash, int $supplierId, int $userId, string $domain, string $provider, string $stage): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE oauth_sessions SET status = 'processing', consumed_at = CURRENT_TIMESTAMP(6)
              WHERE state_hash = ? AND supplier_id = ? AND user_id = ? AND domain = ? AND provider = ? AND stage = ?
                AND status = 'pending' AND expires_at > CURRENT_TIMESTAMP(6)"
        );
        $stmt->execute([$stateHash, $supplierId, $userId, $domain, $provider, $stage]);
        if ($stmt->rowCount() !== 1) return null;
        return $this->withSecret($stateHash, $supplierId, "status = 'processing'");
    }

    /**
     * Rozpracované přihlášení napojení (čeká nebo už má výsledek callbacku) včetně ciphertextu,
     * jen pro uživatele, který ho zahájil.
     */
    public function open(string $stateHash, int $supplierId, int $userId, string $domain, string $provider, int $subjectId): ?array
    {
        $row = $this->withSecret($stateHash, $supplierId, "status IN ('pending','processing')");
        return $row !== null && $row['user_id'] === $userId && $row['domain'] === $domain && $row['provider'] === $provider
            && $row['subject_id'] === $subjectId ? $row : null;
    }

    /** Nové tajemství rozpracovaného přihlášení (polling device code, výsledek callbacku). */
    public function updateSecret(string $stateHash, int $supplierId, string $ciphertext): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE oauth_sessions SET secret_ciphertext = ?
              WHERE state_hash = ? AND supplier_id = ? AND status IN ('pending','processing')"
        );
        $stmt->execute([$ciphertext, $stateHash, $supplierId]);
        return $stmt->rowCount() === 1;
    }

    /** Ukončí přihlášení a smaže jeho tajemství. */
    public function finish(string $stateHash, int $supplierId, bool $success, ?string $errorCode = null): void
    {
        $this->db->pdo()->prepare(
            "UPDATE oauth_sessions
                SET status = ?, error_code = ?, secret_ciphertext = '', consumed_at = COALESCE(consumed_at, CURRENT_TIMESTAMP(6))
              WHERE state_hash = ? AND supplier_id = ? AND status IN ('pending','processing')"
        )->execute([$success ? 'completed' : 'failed', $errorCode !== null ? mb_substr($errorCode, 0, 80) : null, $stateHash, $supplierId]);
    }

    /** Poslední přihlášení napojení (stav pro UI). */
    public function latest(int $supplierId, string $domain, string $provider, int $subjectId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM oauth_sessions
              WHERE supplier_id = ? AND domain = ? AND provider = ? AND subject_id = ?
              ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $domain, $provider, $subjectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** @return list<array<string,mixed>> čekající přihlášení uživatele v dané fázi včetně ciphertextu */
    public function pendingForUser(int $supplierId, int $userId, string $domain, string $provider, string $stage, int $limit = 10): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ", secret_ciphertext FROM oauth_sessions
              WHERE supplier_id = ? AND user_id = ? AND domain = ? AND provider = ? AND stage = ?
                AND status = 'pending' AND expires_at > CURRENT_TIMESTAMP(6)
              ORDER BY created_at DESC LIMIT " . max(1, min(50, $limit))
        );
        $stmt->execute([$supplierId, $userId, $domain, $provider, $stage]);
        return array_map(fn (array $r): array => $this->cast($r) + ['secret_ciphertext' => (string) $r['secret_ciphertext']],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function withSecret(string $stateHash, int $supplierId, string $statusCondition): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ', secret_ciphertext FROM oauth_sessions
            WHERE state_hash = ? AND supplier_id = ? AND ' . $statusCondition);
        $stmt->execute([$stateHash, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row) + ['secret_ciphertext' => (string) $row['secret_ciphertext']];
    }

    private function cast(array $r): array
    {
        return [
            'state_hash'  => (string) $r['state_hash'],
            'supplier_id' => (int) $r['supplier_id'],
            'user_id'     => (int) $r['user_id'],
            'domain'      => (string) $r['domain'],
            'provider'    => (string) $r['provider'],
            'subject_id'  => (int) $r['subject_id'],
            'flow'        => (string) $r['flow'],
            'stage'       => (string) $r['stage'],
            'status'      => (string) $r['status'],
            'error_code'  => $r['error_code'] !== null ? (string) $r['error_code'] : null,
            'expires_at'  => (string) $r['expires_at'],
            'expired'     => (bool) $r['expired'],
        ];
    }
}
