<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class CsasOAuthRepository
{
    public function __construct(private readonly Connection $db) {}

    public function account(int $supplierId, int $currencyId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, supplier_id, code, is_active, account_number, bank_code, iban
            FROM currencies WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $currencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function start(string $hash, int $supplierId, int $currencyId, int $userId, string $ciphertext): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE bank_oauth_sessions SET status = 'failed', secret_ciphertext = '', consumed_at = CURRENT_TIMESTAMP(6)
                WHERE supplier_id = ? AND currency_id = ? AND provider = 'csas' AND status = 'pending'")
                ->execute([$supplierId, $currencyId]);
            $pdo->prepare("INSERT INTO bank_oauth_sessions
                (state_hash, supplier_id, currency_id, user_id, provider, stage, secret_ciphertext, expires_at)
                VALUES (?, ?, ?, ?, 'csas', 'oauth', ?, TIMESTAMPADD(SECOND, 600, CURRENT_TIMESTAMP(6)))")
                ->execute([$hash, $supplierId, $currencyId, $userId, $ciphertext]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function currency(string $hash, int $supplierId, int $userId): ?int
    {
        $stmt = $this->db->pdo()->prepare("SELECT currency_id FROM bank_oauth_sessions
            WHERE state_hash = ? AND supplier_id = ? AND user_id = ? AND provider = 'csas'");
        $stmt->execute([$hash, $supplierId, $userId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function claim(string $hash, int $supplierId, int $userId): ?array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE bank_oauth_sessions SET status = 'processing', consumed_at = CURRENT_TIMESTAMP(6)
                WHERE state_hash = ? AND supplier_id = ? AND user_id = ? AND provider = 'csas'
                  AND stage = 'oauth' AND status = 'pending' AND expires_at > CURRENT_TIMESTAMP(6)");
            $stmt->execute([$hash, $supplierId, $userId]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }
            $stmt = $pdo->prepare('SELECT currency_id, secret_ciphertext FROM bank_oauth_sessions WHERE state_hash = ? FOR UPDATE');
            $stmt->execute([$hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $pdo->commit();
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function finish(string $hash, bool $success): void
    {
        $this->db->pdo()->prepare("UPDATE bank_oauth_sessions SET status = ?, secret_ciphertext = ''
            WHERE state_hash = ? AND provider = 'csas' AND status = 'processing'")
            ->execute([$success ? 'completed' : 'failed', $hash]);
    }

    public function rotate(int $supplierId, int $connectionId, string $ciphertext): bool
    {
        $stmt = $this->db->pdo()->prepare("UPDATE bank_connections SET token_ciphertext = ?
            WHERE supplier_id = ? AND id = ? AND provider = 'csas' AND token_ciphertext IS NOT NULL");
        $stmt->execute([$ciphertext, $supplierId, $connectionId]);
        return $stmt->rowCount() === 1;
    }
}
