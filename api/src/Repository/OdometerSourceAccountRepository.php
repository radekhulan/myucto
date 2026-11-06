<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro odometer_source_accounts — napojení na zdroj stavu tachometru = účet
 * u zdroje ({@see \MyInvoice\Service\Logbook\OdometerSource\OdometerSourceProvider}).
 * Šifrované sloupce se ven nevracejí jinak než přes {@see findWithSecrets()}.
 */
final class OdometerSourceAccountRepository
{
    private const PUBLIC_COLUMNS = 'id, supplier_id, provider, status, pending_car_id, pending_expires_at, calls_day, calls_used,
        last_sync_at, last_sync_status, last_sync_error_code, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::PUBLIC_COLUMNS . ' FROM odometer_source_accounts WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Řádek včetně ciphertextu přístupů a hashe state rozpracovaného přihlášení
     * (klíč do oauth_sessions) — jen pro službu, nikdy do odpovědi API.
     */
    public function findWithSecrets(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ', credentials_ciphertext, pending_state_hash
               FROM odometer_source_accounts WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return $this->cast($row) + [
            'credentials_ciphertext' => $row['credentials_ciphertext'],
            'pending_state_hash'     => $row['pending_state_hash'] !== null ? (string) $row['pending_state_hash'] : null,
        ];
    }

    /** Rozpracované připojení zahájené z karty auta (nejnovější). */
    public function findPendingForCar(int $supplierId, int $carId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM odometer_source_accounts
              WHERE supplier_id = ? AND pending_car_id = ? AND pending_state_hash IS NOT NULL
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $carId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return list<array<string,mixed>> připojené účty firmy (s přístupem) */
    public function listConnected(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . " FROM odometer_source_accounts
              WHERE supplier_id = ? AND status IN ('active','error') AND credentials_ciphertext IS NOT NULL
              ORDER BY id"
        );
        $stmt->execute([$supplierId]);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{id:int, supplier_id:int}> účty s přiřazeným autem k dennímu načtení */
    public function listSyncable(): array
    {
        $rows = $this->db->pdo()->query(
            "SELECT a.id, a.supplier_id FROM odometer_source_accounts a
              WHERE a.status IN ('active','error') AND a.credentials_ciphertext IS NOT NULL
                AND EXISTS (SELECT 1 FROM odometer_source_cars c WHERE c.account_id = a.id)
              ORDER BY a.id"
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'supplier_id' => (int) $r['supplier_id']], $rows);
    }

    /** Nový účet ve stavu „čeká na přihlášení“; vrací jeho id (potřebné pro kontext šifry). */
    public function createPending(int $supplierId, string $provider, int $carId, string $stateHash, string $expiresAt, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO odometer_source_accounts (supplier_id, provider, status, pending_state_hash, pending_car_id, pending_expires_at, created_by)
             VALUES (?, ?, 'pending', ?, ?, ?, ?)"
        )->execute([$supplierId, $provider, $stateHash, $carId, $expiresAt, $userId]);
        return (int) $pdo->lastInsertId();
    }

    /** Nové přihlášení k existujícímu účtu (propadlý přístup); přístupy a auta zůstávají do dokončení. */
    public function beginReauth(int $supplierId, int $id, int $carId, string $stateHash, string $expiresAt): void
    {
        $this->db->pdo()->prepare(
            'UPDATE odometer_source_accounts
                SET pending_state_hash = ?, pending_car_id = ?, pending_expires_at = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([$stateHash, $carId, $expiresAt, $supplierId, $id]);
    }

    public function activate(int $supplierId, int $id, string $credentialsCiphertext): void
    {
        $this->db->pdo()->prepare(
            "UPDATE odometer_source_accounts
                SET status = 'active', credentials_ciphertext = ?, pending_state_hash = NULL,
                    pending_car_id = NULL, pending_expires_at = NULL, last_sync_error_code = NULL
              WHERE supplier_id = ? AND id = ?"
        )->execute([$credentialsCiphertext, $supplierId, $id]);
    }

    public function rotateCredentials(int $supplierId, int $id, string $credentialsCiphertext): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE odometer_source_accounts SET credentials_ciphertext = ? WHERE supplier_id = ? AND id = ? AND credentials_ciphertext IS NOT NULL'
        );
        $stmt->execute([$credentialsCiphertext, $supplierId, $id]);
        return $stmt->rowCount() > 0 || $this->hasCredentials($supplierId, $id);
    }

    /**
     * Započte volání do denního limitu účtu (den podle UTC, jako u služeb s resetem o půlnoci UTC).
     * Vrací false, když by limit přetekl — volání se pak nesmí uskutečnit.
     */
    public function spendCalls(int $supplierId, int $id, int $calls, ?int $limit, string $utcDate): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE odometer_source_accounts
                SET calls_used = IF(calls_day <=> ?, calls_used, 0) + ?, calls_day = ?
              WHERE supplier_id = ? AND id = ?
                AND (? IS NULL OR IF(calls_day <=> ?, calls_used, 0) + ? <= ?)'
        );
        $stmt->execute([$utcDate, $calls, $utcDate, $supplierId, $id, $limit, $utcDate, $calls, $limit]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Ukončí rozpracované přihlášení. Účet, který přístup nikdy nedostal, se smaže;
     * propadlý účet s auty zůstává ve svém stavu.
     */
    public function clearPending(int $supplierId, int $id): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("DELETE FROM odometer_source_accounts WHERE supplier_id = ? AND id = ? AND credentials_ciphertext IS NULL AND status = 'pending'")
            ->execute([$supplierId, $id]);
        $pdo->prepare(
            'UPDATE odometer_source_accounts
                SET pending_state_hash = NULL, pending_car_id = NULL, pending_expires_at = NULL
              WHERE supplier_id = ? AND id = ?'
        )->execute([$supplierId, $id]);
    }

    /** Smaže nedokončená připojení auta a propadlá nedokončená připojení firmy. */
    public function deleteStalePending(int $supplierId, int $carId): void
    {
        $this->db->pdo()->prepare(
            "DELETE FROM odometer_source_accounts
              WHERE supplier_id = ? AND status = 'pending' AND credentials_ciphertext IS NULL
                AND (pending_car_id = ? OR pending_car_id IS NULL OR pending_expires_at < CURRENT_TIMESTAMP)"
        )->execute([$supplierId, $carId]);
    }

    public function recordSyncSuccess(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare(
            "UPDATE odometer_source_accounts
                SET status = 'active', last_sync_at = CURRENT_TIMESTAMP, last_sync_status = 'success', last_sync_error_code = NULL
              WHERE supplier_id = ? AND id = ? AND credentials_ciphertext IS NOT NULL"
        )->execute([$supplierId, $id]);
    }

    /** Chyba celého účtu (přístup, limit, výpadek služby). */
    public function recordSyncError(int $supplierId, int $id, string $errorCode): void
    {
        $this->db->pdo()->prepare(
            "UPDATE odometer_source_accounts
                SET status = 'error', last_sync_at = CURRENT_TIMESTAMP, last_sync_status = 'error', last_sync_error_code = ?
              WHERE supplier_id = ? AND id = ? AND credentials_ciphertext IS NOT NULL"
        )->execute([mb_substr($errorCode, 0, 64), $supplierId, $id]);
    }

    /** Odpojí účet: smaže přístupy; přiřazení aut maže volající. */
    public function disconnect(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare(
            "UPDATE odometer_source_accounts
                SET status = 'disconnected', credentials_ciphertext = NULL,
                    pending_state_hash = NULL, pending_car_id = NULL, pending_expires_at = NULL
              WHERE supplier_id = ? AND id = ?"
        )->execute([$supplierId, $id]);
    }

    private function hasCredentials(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM odometer_source_accounts WHERE supplier_id = ? AND id = ? AND credentials_ciphertext IS NOT NULL');
        $stmt->execute([$supplierId, $id]);
        return $stmt->fetchColumn() !== false;
    }

    private function cast(array $r): array
    {
        $today = gmdate('Y-m-d');
        return [
            'id'                   => (int) $r['id'],
            'supplier_id'          => (int) $r['supplier_id'],
            'provider'             => (string) $r['provider'],
            'status'               => (string) $r['status'],
            'pending_car_id'       => $r['pending_car_id'] !== null ? (int) $r['pending_car_id'] : null,
            'pending_expires_at'   => $r['pending_expires_at'] !== null ? (string) $r['pending_expires_at'] : null,
            'calls_today'          => $r['calls_day'] === $today ? (int) $r['calls_used'] : 0,
            'last_sync_at'         => $r['last_sync_at'] !== null ? (string) $r['last_sync_at'] : null,
            'last_sync_status'     => $r['last_sync_status'] !== null ? (string) $r['last_sync_status'] : null,
            'last_sync_error_code' => $r['last_sync_error_code'] !== null ? (string) $r['last_sync_error_code'] : null,
            'created_at'           => (string) $r['created_at'],
            'updated_at'           => (string) $r['updated_at'],
        ];
    }
}
