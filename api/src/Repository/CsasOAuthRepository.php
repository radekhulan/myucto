<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Účet ČSAS a uložený přístup. Metody nad bank_oauth_sessions slouží jen k dokončení
 * přihlášení zahájených před společnou vrstvou OAuth klienta (oauth_sessions).
 */
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

    /** Ukončí rozpracované přihlášení účtu z doby před společnou vrstvou OAuth klienta. */
    public function cancelPending(int $supplierId, int $currencyId): void
    {
        $this->db->pdo()->prepare("UPDATE bank_oauth_sessions SET status = 'failed', secret_ciphertext = '', consumed_at = CURRENT_TIMESTAMP(6)
            WHERE supplier_id = ? AND currency_id = ? AND provider = 'csas' AND status = 'pending'")
            ->execute([$supplierId, $currencyId]);
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
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE bank_oauth_sessions SET status = 'processing', consumed_at = CURRENT_TIMESTAMP(6)
                WHERE state_hash = ? AND supplier_id = ? AND user_id = ? AND provider = 'csas'
                  AND stage = 'oauth' AND status = 'pending' AND expires_at > CURRENT_TIMESTAMP(6)");
            $stmt->execute([$hash, $supplierId, $userId]);
            if ($stmt->rowCount() !== 1) {
                if ($own) $pdo->rollBack();
                return null;
            }
            $stmt = $pdo->prepare('SELECT currency_id, secret_ciphertext FROM bank_oauth_sessions WHERE state_hash = ? FOR UPDATE');
            $stmt->execute([$hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($own) $pdo->commit();
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function finish(string $hash, bool $success): void
    {
        $this->db->pdo()->prepare("UPDATE bank_oauth_sessions SET status = ?, secret_ciphertext = ''
            WHERE state_hash = ? AND provider = 'csas' AND status = 'processing'")
            ->execute([$success ? 'completed' : 'failed', $hash]);
    }

    /** Aktuálně uložený přístup napojení (ciphertext); null, když napojení přístup nemá. */
    public function credential(int $supplierId, int $connectionId): ?string
    {
        $stmt = $this->db->pdo()->prepare("SELECT token_ciphertext FROM bank_connections
            WHERE supplier_id = ? AND id = ? AND provider = 'csas' AND token_ciphertext IS NOT NULL");
        $stmt->execute([$supplierId, $connectionId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    /** @return array{id:int,token_ciphertext:string}|null připojený přístup ČS k účtu (měně) */
    public function connectedCredential(int $supplierId, int $currencyId): ?array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id, token_ciphertext FROM bank_connections
            WHERE supplier_id = ? AND currency_id = ? AND provider = 'csas' AND token_ciphertext IS NOT NULL");
        $stmt->execute([$supplierId, $currencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['id' => (int) $row['id'], 'token_ciphertext' => (string) $row['token_ciphertext']];
    }

    public function rotate(int $supplierId, int $connectionId, string $ciphertext): bool
    {
        $stmt = $this->db->pdo()->prepare("UPDATE bank_connections SET token_ciphertext = ?
            WHERE supplier_id = ? AND id = ? AND provider = 'csas' AND token_ciphertext IS NOT NULL");
        $stmt->execute([$ciphertext, $supplierId, $connectionId]);
        return $stmt->rowCount() === 1;
    }
}
