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

    /** @return list<array{id:int,currency_id:int,token_ciphertext:string}> napojení ČS firmy s uloženým přístupem */
    public function connectedCredentials(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id, currency_id, token_ciphertext FROM bank_connections
            WHERE supplier_id = ? AND provider = 'csas' AND token_ciphertext IS NOT NULL ORDER BY id");
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'currency_id' => (int) $row['currency_id'],
            'token_ciphertext' => (string) $row['token_ciphertext']], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Další aktivní účty firmy u České spořitelny (kód banky 0800) a stav jejich napojení.
     *
     * @return list<array{currency_id:int,code:string,account_number:string,connection_id:?int,provider:?string,
     *     token_ciphertext:?string,disconnected:bool}>
     */
    public function siblingAccounts(int $supplierId, int $excludeCurrencyId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT c.id, c.code, COALESCE(NULLIF(c.account_number, ''), c.iban, '') AS account_number,
                bc.id AS connection_id, bc.provider, bc.token_ciphertext, bc.disconnected_at
            FROM currencies c
            LEFT JOIN bank_connections bc ON bc.currency_id = c.id AND bc.supplier_id = c.supplier_id
            WHERE c.supplier_id = ? AND c.id <> ? AND c.is_active = 1 AND c.bank_code = '0800'
            ORDER BY c.id");
        $stmt->execute([$supplierId, $excludeCurrencyId]);
        return array_map(static fn (array $row): array => [
            'currency_id' => (int) $row['id'],
            'code' => strtoupper((string) $row['code']),
            'account_number' => (string) $row['account_number'],
            'connection_id' => $row['connection_id'] === null ? null : (int) $row['connection_id'],
            'provider' => $row['provider'] === null ? null : (string) $row['provider'],
            'token_ciphertext' => $row['token_ciphertext'] === null ? null : (string) $row['token_ciphertext'],
            'disconnected' => $row['disconnected_at'] !== null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Napojení ČS všech firem instalace s uloženým přístupem; pro rozeslání obnoveného tokenu
     * sdíleného souhlasu ({@see \MyInvoice\Service\Bank\Connector\CsasSharedConsent}).
     *
     * @return list<array{id:int,supplier_id:int,currency_id:int,token_ciphertext:string}>
     */
    public function allConnectedCredentials(): array
    {
        $stmt = $this->db->pdo()->query("SELECT id, supplier_id, currency_id, token_ciphertext FROM bank_connections
            WHERE provider = 'csas' AND token_ciphertext IS NOT NULL ORDER BY supplier_id, id");
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'supplier_id' => (int) $row['supplier_id'],
            'currency_id' => (int) $row['currency_id'], 'token_ciphertext' => (string) $row['token_ciphertext']],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Aktivní účty u České spořitelny ostatních firem instalace (mimo nosnou firmu sdíleného
     * souhlasu) a stav jejich napojení; tvar jako {@see siblingAccounts()} s firmou a jejím názvem navíc.
     *
     * @return list<array{supplier_id:int,company_name:string,currency_id:int,code:string,account_number:string,connection_id:?int,
     *     provider:?string,token_ciphertext:?string,disconnected:bool}>
     */
    public function otherSuppliersAccounts(int $ownerSupplierId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT c.supplier_id, s.company_name, c.id, c.code,
                COALESCE(NULLIF(c.account_number, ''), c.iban, '') AS account_number,
                bc.id AS connection_id, bc.provider, bc.token_ciphertext, bc.disconnected_at
            FROM currencies c
            JOIN supplier s ON s.id = c.supplier_id
            LEFT JOIN bank_connections bc ON bc.currency_id = c.id AND bc.supplier_id = c.supplier_id
            WHERE c.supplier_id <> ? AND c.is_active = 1 AND c.bank_code = '0800'
            ORDER BY c.supplier_id, c.id");
        $stmt->execute([$ownerSupplierId]);
        return array_map(static fn (array $row): array => [
            'supplier_id' => (int) $row['supplier_id'],
            'company_name' => (string) $row['company_name'],
            'currency_id' => (int) $row['id'],
            'code' => strtoupper((string) $row['code']),
            'account_number' => (string) $row['account_number'],
            'connection_id' => $row['connection_id'] === null ? null : (int) $row['connection_id'],
            'provider' => $row['provider'] === null ? null : (string) $row['provider'],
            'token_ciphertext' => $row['token_ciphertext'] === null ? null : (string) $row['token_ciphertext'],
            'disconnected' => $row['disconnected_at'] !== null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
}
