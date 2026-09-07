<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class BankConnectionRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listPublic(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT bc.id, bc.supplier_id, bc.currency_id, bc.provider, bc.enabled,
                    (bc.token_ciphertext IS NOT NULL) AS has_token,
                    bc.validated_at, bc.sync_watermark_date, bc.last_sync_at,
                    bc.last_sync_status, bc.last_sync_error_code,
                    bc.created_at, bc.updated_at,
                    c.code AS account_code, c.label AS account_label,
                    c.account_number, c.bank_code, c.iban
               FROM bank_connections bc
               JOIN currencies c ON c.id = bc.currency_id AND c.supplier_id = bc.supplier_id
              WHERE bc.supplier_id = ?
           ORDER BY c.code, c.label, bc.id'
        );
        $stmt->execute([$supplierId]);

        return array_map([$this, 'publicProjection'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findPublicByCurrency(int $supplierId, int $currencyId): ?array
    {
        $row = $this->findJoined($supplierId, $currencyId, false);
        return $row !== null ? $this->publicProjection($row) : null;
    }

    /** @internal Obsahuje ciphertext, nikdy neposílat do API ani logu. */
    public function findWithCredentialByCurrency(int $supplierId, int $currencyId): ?array
    {
        return $this->findJoined($supplierId, $currencyId, true);
    }

    /** @internal Obsahuje ciphertext, nikdy neposílat do API ani logu. */
    public function findWithCredentialById(int $supplierId, int $connectionId): ?array
    {
        $stmt = $this->db->pdo()->prepare($this->joinedSql(true) . ' WHERE bc.supplier_id = ? AND bc.id = ?');
        $stmt->execute([$supplierId, $connectionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->castInternal($row) : null;
    }

    /** @return list<array<string,mixed>> @internal */
    public function enabledWithCredentials(): array
    {
        $stmt = $this->db->pdo()->query(
            $this->joinedSql(true)
            . ' WHERE bc.enabled = 1 AND bc.token_ciphertext IS NOT NULL ORDER BY bc.id'
        );

        return array_map([$this, 'castInternal'], $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : []);
    }

    public function ensure(int $supplierId, int $currencyId, string $provider): int
    {
        $pdo = $this->db->pdo();
        $current = $this->findWithCredentialByCurrency($supplierId, $currencyId);
        if ($current !== null) {
            return (int) $current['id'];
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO bank_connections (supplier_id, currency_id, provider, enabled)
                 SELECT c.supplier_id, c.id, ?, 0 FROM currencies c
                  WHERE c.supplier_id = ? AND c.id = ?'
            );
            $stmt->execute([$provider, $supplierId, $currencyId]);
            if ($stmt->rowCount() !== 1) {
                throw new \InvalidArgumentException('Bankovní účet nebyl nalezen.');
            }
            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $current = $this->findWithCredentialByCurrency($supplierId, $currencyId);
            if ($current === null) {
                throw $e;
            }
            return (int) $current['id'];
        }
    }

    public function saveValidated(
        int $supplierId,
        int $connectionId,
        string $provider,
        string $tokenCiphertext,
        bool $enabled,
        string $accountNumber,
        string $bankCode,
        string $currency,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE bank_connections
                SET provider = ?, token_ciphertext = ?, enabled = ?,
                    verified_account_number = ?, verified_bank_code = ?, verified_currency = ?,
                    validated_at = CURRENT_TIMESTAMP, disconnected_at = NULL,
                    last_sync_error_code = NULL
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            $provider, $tokenCiphertext, $enabled ? 1 : 0,
            $accountNumber, $bankCode, strtoupper($currency),
            $connectionId, $supplierId,
        ]);
    }

    public function disconnect(int $supplierId, int $currencyId): bool
    {
        $unused = $this->db->pdo()->prepare(
            'DELETE FROM bank_connections
              WHERE supplier_id = ? AND currency_id = ?
                AND NOT EXISTS (
                    SELECT 1 FROM bank_payment_order_submissions s
                     WHERE s.supplier_id = bank_connections.supplier_id
                       AND s.connection_id = bank_connections.id
                )'
        );
        $unused->execute([$supplierId, $currencyId]);
        if ($unused->rowCount() === 1) {
            return true;
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE bank_connections
                SET token_ciphertext = NULL, enabled = 0, disconnected_at = CURRENT_TIMESTAMP
              WHERE supplier_id = ? AND currency_id = ?'
        );
        $stmt->execute([$supplierId, $currencyId]);
        return $stmt->rowCount() === 1;
    }

    public function setEnabled(int $supplierId, int $connectionId, bool $enabled): void
    {
        $this->db->pdo()->prepare(
            'UPDATE bank_connections SET enabled = ? WHERE supplier_id = ? AND id = ?'
        )->execute([$enabled ? 1 : 0, $supplierId, $connectionId]);
    }

    public function recordSyncSuccess(int $supplierId, int $connectionId, ?string $watermarkTo): void
    {
        if ($watermarkTo === null) {
            $this->db->pdo()->prepare(
                "UPDATE bank_connections
                    SET last_sync_at = CURRENT_TIMESTAMP, last_sync_status = 'success', last_sync_error_code = NULL
                  WHERE supplier_id = ? AND id = ?"
            )->execute([$supplierId, $connectionId]);
            return;
        }
        $this->db->pdo()->prepare(
            "UPDATE bank_connections
                SET sync_watermark_date = CASE
                        WHEN sync_watermark_date IS NULL OR sync_watermark_date < ? THEN ?
                        ELSE sync_watermark_date END,
                    last_sync_at = CURRENT_TIMESTAMP, last_sync_status = 'success', last_sync_error_code = NULL
              WHERE supplier_id = ? AND id = ?"
        )->execute([$watermarkTo, $watermarkTo, $supplierId, $connectionId]);
    }

    public function recordSyncError(int $supplierId, int $connectionId, string $errorCode): void
    {
        $this->db->pdo()->prepare(
            "UPDATE bank_connections
                SET last_sync_at = CURRENT_TIMESTAMP, last_sync_status = 'error', last_sync_error_code = ?
              WHERE supplier_id = ? AND id = ?"
        )->execute([mb_substr($errorCode, 0, 80), $supplierId, $connectionId]);
    }

    private function findJoined(int $supplierId, int $currencyId, bool $withCredential): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            $this->joinedSql($withCredential) . ' WHERE bc.supplier_id = ? AND bc.currency_id = ?'
        );
        $stmt->execute([$supplierId, $currencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->castInternal($row) : null;
    }

    private function joinedSql(bool $withCredential): string
    {
        $secret = $withCredential ? ', bc.token_ciphertext' : '';
        return 'SELECT bc.id, bc.supplier_id, bc.currency_id, bc.provider, bc.enabled,
                       (bc.token_ciphertext IS NOT NULL) AS has_token' . $secret . ',
                       bc.verified_account_number, bc.verified_bank_code, bc.verified_currency,
                       bc.validated_at, bc.sync_watermark_date, bc.last_sync_at,
                       bc.last_sync_status, bc.last_sync_error_code,
                       bc.created_at, bc.updated_at,
                       c.code AS account_code, c.label AS account_label, c.is_active,
                       c.account_number, c.bank_code, c.iban
                  FROM bank_connections bc
                  JOIN currencies c ON c.id = bc.currency_id AND c.supplier_id = bc.supplier_id';
    }

    private function castInternal(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['currency_id'] = (int) $row['currency_id'];
        $row['enabled'] = (bool) $row['enabled'];
        $row['has_token'] = (bool) $row['has_token'];
        $row['is_active'] = isset($row['is_active']) ? (bool) $row['is_active'] : false;
        return $row;
    }

    private function publicProjection(array $row): array
    {
        $row = $this->castInternal($row);
        $watermark = trim((string) ($row['sync_watermark_date'] ?? ''));
        return [
            'id' => $row['id'],
            'currency_id' => $row['currency_id'],
            'provider' => (string) $row['provider'],
            'enabled' => $row['enabled'],
            'has_token' => $row['has_token'],
            'validated_at' => $row['validated_at'] ?: null,
            'last_sync_at' => $row['last_sync_at'] ?: null,
            'last_sync_status' => $row['last_sync_status'] ?: null,
            'last_sync_error_code' => $row['last_sync_error_code'] ?: null,
            'next_sync_from' => $watermark !== ''
                ? (new \DateTimeImmutable($watermark))->modify('-3 days')->format('Y-m-d')
                : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'account' => [
                'id' => $row['currency_id'],
                'code' => (string) $row['account_code'],
                'label' => (string) $row['account_label'],
                'account_number' => $row['account_number'] ?: null,
                'bank_code' => $row['bank_code'] ?: null,
                'iban' => $row['iban'] ?: null,
            ],
        ];
    }
}
