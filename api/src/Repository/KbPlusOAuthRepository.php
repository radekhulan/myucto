<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class KbPlusOAuthRepository
{
    public function __construct(private readonly Connection $db) {}

    public function account(int $supplierId, int $currencyId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, code, is_active, account_number, bank_code, iban
               FROM currencies WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $currencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }

    /** @internal Contains encrypted credentials and must never be serialized. */
    public function client(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT supplier_id, provider, credentials_ciphertext, registered_at
               FROM bank_oauth_clients WHERE supplier_id = ? AND provider = 'kb_plus'"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function saveClient(int $supplierId, string $ciphertext, int $userId): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO bank_oauth_clients
                    (supplier_id, provider, credentials_ciphertext, registered_by_user_id, registered_at)
             VALUES (?, 'kb_plus', ?, ?, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE credentials_ciphertext = VALUES(credentials_ciphertext),
                 registered_by_user_id = VALUES(registered_by_user_id), registered_at = CURRENT_TIMESTAMP"
        )->execute([$supplierId, $ciphertext, $userId]);
    }

    public function replacePending(
        string $stateHash,
        int $supplierId,
        int $currencyId,
        int $userId,
        string $stage,
        string $ciphertext,
        int $lifetimeSeconds,
    ): void {
        if (!in_array($stage, ['registration', 'oauth'], true) || $lifetimeSeconds < 1) {
            throw new \InvalidArgumentException('Invalid OAuth session.');
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE bank_oauth_sessions
                    SET status = 'failed', error_code = 'kb_plus_onboarding_restarted', consumed_at = CURRENT_TIMESTAMP(6)
                  WHERE supplier_id = ? AND currency_id = ? AND provider = 'kb_plus' AND status = 'pending'"
            )->execute([$supplierId, $currencyId]);
            $pdo->prepare(
                "INSERT INTO bank_oauth_sessions
                    (state_hash, supplier_id, currency_id, user_id, provider, stage, secret_ciphertext, expires_at)
                 VALUES (?, ?, ?, ?, 'kb_plus', ?, ?, TIMESTAMPADD(SECOND, ?, CURRENT_TIMESTAMP(6)))"
            )->execute([$stateHash, $supplierId, $currencyId, $userId, $stage, $ciphertext, $lifetimeSeconds]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @internal Contains encrypted flow data and must never be serialized. */
    public function claim(string $stateHash, int $supplierId, int $userId, string $stage): ?array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE bank_oauth_sessions SET status = 'processing', consumed_at = CURRENT_TIMESTAMP(6)
                  WHERE state_hash = ? AND supplier_id = ? AND user_id = ? AND provider = 'kb_plus'
                    AND stage = ? AND status = 'pending' AND expires_at > CURRENT_TIMESTAMP(6)"
            );
            $stmt->execute([$stateHash, $supplierId, $userId, $stage]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }
            $read = $pdo->prepare(
                'SELECT state_hash, supplier_id, currency_id, user_id, stage, secret_ciphertext
                   FROM bank_oauth_sessions WHERE state_hash = ? FOR UPDATE'
            );
            $read->execute([$stateHash]);
            $row = $read->fetch(PDO::FETCH_ASSOC);
            $pdo->commit();
            if ($row === false) {
                return null;
            }
            $row['supplier_id'] = (int) $row['supplier_id'];
            $row['currency_id'] = (int) $row['currency_id'];
            $row['user_id'] = (int) $row['user_id'];
            return $row;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function finish(string $stateHash, bool $success, ?string $errorCode = null): void
    {
        $this->db->pdo()->prepare(
            "UPDATE bank_oauth_sessions SET status = ?, error_code = ?, consumed_at = COALESCE(consumed_at, CURRENT_TIMESTAMP(6))
              WHERE state_hash = ? AND status = 'processing'"
        )->execute([$success ? 'completed' : 'failed', $errorCode, $stateHash]);
    }

    public function publicStatus(int $supplierId, int $currencyId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT stage, status, error_code, expires_at
               FROM bank_oauth_sessions
              WHERE supplier_id = ? AND currency_id = ? AND provider = 'kb_plus'
           ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$supplierId, $currencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if ($row['status'] === 'pending' && strtotime((string) $row['expires_at']) <= time()) {
            $row['status'] = 'expired';
        }
        return $row;
    }

    public function currencyForState(string $stateHash, int $supplierId, int $userId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT currency_id FROM bank_oauth_sessions
              WHERE state_hash = ? AND supplier_id = ? AND user_id = ? AND provider = 'kb_plus'"
        );
        $stmt->execute([$stateHash, $supplierId, $userId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function replaceConnectionCredential(
        int $supplierId,
        int $connectionId,
        string $ciphertext,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE bank_connections SET token_ciphertext = ?
              WHERE supplier_id = ? AND id = ? AND provider = 'kb_plus' AND token_ciphertext IS NOT NULL"
        );
        $stmt->execute([$ciphertext, $supplierId, $connectionId]);
        return $stmt->rowCount() === 1;
    }
}
