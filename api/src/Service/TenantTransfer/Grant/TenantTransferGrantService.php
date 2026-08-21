<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Grant;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecurityClock;
use MyInvoice\Service\Auth\SecurityTime;
use PDO;

final class TenantTransferGrantService
{
    public const TTL_SECONDS = 1_800;

    private const CODE_PREFIX = 'ttg_v1_';
    private const RANDOM_BYTES = 32;
    private const REJECTED_IP_ATTEMPTS_PER_MINUTE = 20;
    private const GRANT_ATTEMPTS_PER_MINUTE = 120;

    public function __construct(
        private readonly Connection $db,
        private readonly SecurityClock $clock,
    ) {}

    /**
     * @return array{id:int,public_id:string,plaintext:string,prefix:string,supplier_id:int,expires_at:string}
     */
    public function issue(int $userId, int $supplierId, string $requestIp): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $grant = $this->issueInTransaction(
                $pdo,
                $this->clock->capture($pdo),
                $userId,
                $supplierId,
                $requestIp,
            );
            $pdo->commit();
            return $grant;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return array{id:int,public_id:string,plaintext:string,prefix:string,supplier_id:int,expires_at:string}
     */
    public function issueInTransaction(
        PDO $pdo,
        SecurityTime $now,
        int $userId,
        int $supplierId,
        string $requestIp,
    ): array {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Vydání transfer grantu vyžaduje aktivní transakci.');
        }
        if ($userId < 1 || $supplierId < 1) {
            throw new \InvalidArgumentException('Transfer grant vyžaduje platného uživatele a firmu.');
        }

        $plaintext = self::CODE_PREFIX . self::base64Url(random_bytes(self::RANDOM_BYTES));
        $prefix = substr($plaintext, 0, 16);
        $publicId = self::uuidV4();
        $expiresAt = $now->plusSeconds(self::TTL_SECONDS);
        $statement = $pdo->prepare(
            'INSERT INTO tenant_transfer_grants
                (public_id, grant_hash, grant_prefix, supplier_id, created_by_user_id,
                 expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $publicId,
            hash('sha256', $plaintext, true),
            $prefix,
            $supplierId,
            $userId,
            self::formatDatabaseTime($expiresAt),
            $now->utcSql,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->auditInTransaction(
            $pdo,
            $now,
            $id,
            $supplierId,
            $userId,
            'issued',
            'allowed',
            'issued',
            'POST',
            '/api/admin/tenant-transfer-grants',
            $requestIp,
        );

        return [
            'id' => $id,
            'public_id' => $publicId,
            'plaintext' => $plaintext,
            'prefix' => $prefix,
            'supplier_id' => $supplierId,
            'expires_at' => self::formatWireTime($expiresAt),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId): array
    {
        if ($supplierId < 1) {
            throw new \InvalidArgumentException('Chybí firma transfer grantu.');
        }
        $pdo = $this->db->pdo();
        $now = $this->clock->capture($pdo);
        $statement = $pdo->prepare(
            'SELECT id, public_id, grant_prefix, supplier_id, created_by_user_id,
                    expires_at, paired_at, target_instance_fingerprint,
                    target_payload_key_fingerprint, consumed_at, revoked_at,
                    revoked_reason, last_used_at, created_at
               FROM tenant_transfer_grants
              WHERE supplier_id = ?
              ORDER BY created_at DESC, id DESC',
        );
        $statement->execute([$supplierId]);
        $rows = [];
        $fetchedRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($fetchedRows as $fetchedRow) {
            $row = self::associativeRow($fetchedRow);
            if ($row === null) {
                throw new \UnexpectedValueException('Databáze vrátila neplatný transfer grant.');
            }
            $row = self::normalizeRow($row);
            $row['state'] = self::state($row, $now);
            foreach ([
                'target_instance_fingerprint',
                'target_payload_key_fingerprint',
            ] as $fingerprint) {
                if (is_string($row[$fingerprint] ?? null)) {
                    $row[$fingerprint] = bin2hex($row[$fingerprint]);
                }
            }
            foreach ([
                'expires_at',
                'paired_at',
                'consumed_at',
                'revoked_at',
                'last_used_at',
                'created_at',
            ] as $timeField) {
                $time = $row[$timeField] ?? null;
                if (is_string($time) && $time !== '') {
                    $row[$timeField] = self::formatWireTime(self::parseTime($time));
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public function revoke(
        int $grantId,
        int $supplierId,
        int $actorUserId,
        string $requestIp,
    ): bool {
        if ($grantId < 1 || $supplierId < 1 || $actorUserId < 1) {
            return false;
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $now = $this->clock->capture($pdo);
            $forUpdate = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? ''
                : ' FOR UPDATE';
            $select = $pdo->prepare(
                'SELECT id, supplier_id, created_by_user_id, revoked_at, consumed_at
                   FROM tenant_transfer_grants
                  WHERE id = ? AND supplier_id = ?
                  LIMIT 1' . $forUpdate,
            );
            $select->execute([$grantId, $supplierId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->auditInTransaction(
                    $pdo,
                    $now,
                    null,
                    $supplierId,
                    $actorUserId,
                    'management',
                    'rejected',
                    'grant_not_found',
                    'DELETE',
                    '/api/admin/tenant-transfer-grants/' . $grantId,
                    $requestIp,
                );
                $pdo->commit();
                return false;
            }

            if ($row['revoked_at'] === null && $row['consumed_at'] === null) {
                $update = $pdo->prepare(
                    "UPDATE tenant_transfer_grants
                        SET revoked_at = ?, revoked_reason = 'manual'
                      WHERE id = ? AND revoked_at IS NULL AND consumed_at IS NULL",
                );
                $update->execute([$now->utcSql, $grantId]);
            }
            $this->auditInTransaction(
                $pdo,
                $now,
                $grantId,
                $supplierId,
                $actorUserId,
                'revoked',
                'allowed',
                'manual',
                'DELETE',
                '/api/admin/tenant-transfer-grants/' . $grantId,
                $requestIp,
            );
            $pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function authenticate(
        string $plaintext,
        string $httpMethod,
        string $endpoint,
        string $requestIp,
    ): TenantTransferGrantValidation {
        $pdo = $this->db->pdo();
        $now = $this->clock->capture($pdo);
        if ($this->tooManyRejectedIpAttempts($pdo, $now, $requestIp)) {
            $this->auditInTransaction(
                $pdo,
                $now,
                null,
                null,
                null,
                'authentication',
                'rejected',
                'rate_limited',
                $httpMethod,
                $endpoint,
                $requestIp,
            );
            return TenantTransferGrantValidation::rejected('transfer_rate_limited', 429, 60);
        }
        if (!self::hasValidShape($plaintext)) {
            $this->auditInTransaction(
                $pdo,
                $now,
                null,
                null,
                null,
                'authentication',
                'rejected',
                'invalid_shape',
                $httpMethod,
                $endpoint,
                $requestIp,
            );
            return TenantTransferGrantValidation::rejected('invalid_transfer_grant', 401);
        }

        $statement = $pdo->prepare(
            'SELECT id, public_id, supplier_id, created_by_user_id, expires_at,
                    paired_at, target_instance_fingerprint,
                    target_payload_key_fingerprint, consumed_at, revoked_at,
                    revoked_reason, created_at
               FROM tenant_transfer_grants
              WHERE grant_hash = ?
              LIMIT 1',
        );
        $statement->execute([hash('sha256', $plaintext, true)]);
        $row = self::associativeRow($statement->fetch(PDO::FETCH_ASSOC));
        if ($row === null) {
            $this->auditInTransaction(
                $pdo,
                $now,
                null,
                null,
                null,
                'authentication',
                'rejected',
                'unknown_grant',
                $httpMethod,
                $endpoint,
                $requestIp,
            );
            return TenantTransferGrantValidation::rejected('invalid_transfer_grant', 401);
        }
        $row = self::normalizeRow($row);
        if ($this->tooManyGrantAttempts($pdo, $now, self::rowInt($row, 'id'))) {
            $this->auditRow(
                $row,
                'authentication',
                'rejected',
                'rate_limited',
                $httpMethod,
                $endpoint,
                $requestIp,
                $now,
            );
            return TenantTransferGrantValidation::rejected('transfer_rate_limited', 429, 60);
        }
        if ($row['revoked_at'] !== null) {
            $this->auditRow(
                $row,
                'authentication',
                'rejected',
                'revoked',
                $httpMethod,
                $endpoint,
                $requestIp,
                $now,
            );
            return TenantTransferGrantValidation::rejected('invalid_transfer_grant', 401);
        }
        if ($row['consumed_at'] !== null) {
            $this->auditRow(
                $row,
                'authentication',
                'rejected',
                'consumed',
                $httpMethod,
                $endpoint,
                $requestIp,
                $now,
            );
            return TenantTransferGrantValidation::rejected('invalid_transfer_grant', 401);
        }
        $expiresAt = $row['expires_at'] ?? null;
        if (!is_string($expiresAt)) {
            throw new \UnexpectedValueException('Transfer grant nemá platnou expiraci.');
        }
        if (self::parseTime($expiresAt) <= $now->utc) {
            $expire = $pdo->prepare(
                "UPDATE tenant_transfer_grants
                    SET revoked_at = ?, revoked_reason = 'expired'
                  WHERE id = ? AND revoked_at IS NULL AND consumed_at IS NULL",
            );
            $expire->execute([$now->utcSql, $row['id']]);
            $this->auditRow(
                $row,
                'expired',
                'rejected',
                'expired',
                $httpMethod,
                $endpoint,
                $requestIp,
                $now,
            );
            return TenantTransferGrantValidation::rejected('transfer_grant_expired', 401);
        }

        $touch = $pdo->prepare('UPDATE tenant_transfer_grants SET last_used_at = ? WHERE id = ?');
        $touch->execute([$now->utcSql, $row['id']]);
        $this->auditRow(
            $row,
            'authentication',
            'allowed',
            'authorized',
            $httpMethod,
            $endpoint,
            $requestIp,
            $now,
        );
        return TenantTransferGrantValidation::allowed($row);
    }

    public function auditManagementRejection(
        ?int $supplierId,
        ?int $actorUserId,
        string $reason,
        string $httpMethod,
        string $endpoint,
        string $requestIp,
    ): void {
        $pdo = $this->db->pdo();
        $this->auditInTransaction(
            $pdo,
            $this->clock->capture($pdo),
            null,
            $supplierId,
            $actorUserId,
            'management',
            'rejected',
            $reason,
            $httpMethod,
            $endpoint,
            $requestIp,
        );
    }

    public function rejectAuthenticationAttempt(
        string $reason,
        string $httpMethod,
        string $endpoint,
        string $requestIp,
    ): TenantTransferGrantValidation {
        $pdo = $this->db->pdo();
        $now = $this->clock->capture($pdo);
        if ($this->tooManyRejectedIpAttempts($pdo, $now, $requestIp)) {
            $this->auditInTransaction(
                $pdo,
                $now,
                null,
                null,
                null,
                'authentication',
                'rejected',
                'rate_limited',
                $httpMethod,
                $endpoint,
                $requestIp,
            );
            return TenantTransferGrantValidation::rejected(
                'transfer_rate_limited',
                429,
                60,
            );
        }
        $this->auditInTransaction(
            $pdo,
            $now,
            null,
            null,
            null,
            'authentication',
            'rejected',
            $reason,
            $httpMethod,
            $endpoint,
            $requestIp,
        );
        return TenantTransferGrantValidation::rejected(
            'transfer_authorization_required',
            403,
        );
    }

    private function tooManyRejectedIpAttempts(
        PDO $pdo,
        SecurityTime $now,
        string $requestIp,
    ): bool {
        $packed = @inet_pton($requestIp);
        $ipCondition = $packed === false ? 'IS NULL' : '= ?';
        $statement = $pdo->prepare(
            "SELECT COUNT(*)
               FROM tenant_transfer_grant_audit
              WHERE ip {$ipCondition}
                AND outcome = 'rejected'
                AND created_at >= ?",
        );
        $parameters = [self::formatDatabaseTime($now->utc->modify('-60 seconds'))];
        if ($packed !== false) {
            array_unshift($parameters, $packed);
        }
        $statement->execute($parameters);
        return self::databaseCount($statement->fetchColumn())
            >= self::REJECTED_IP_ATTEMPTS_PER_MINUTE;
    }

    private function tooManyGrantAttempts(PDO $pdo, SecurityTime $now, int $grantId): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
               FROM tenant_transfer_grant_audit
              WHERE grant_id = ?
                AND event = ?
                AND created_at >= ?',
        );
        $statement->execute([
            $grantId,
            'authentication',
            self::formatDatabaseTime($now->utc->modify('-60 seconds')),
        ]);
        return self::databaseCount($statement->fetchColumn())
            >= self::GRANT_ATTEMPTS_PER_MINUTE;
    }

    /** @param array<string,mixed> $row */
    private function auditRow(
        array $row,
        string $event,
        string $outcome,
        string $reason,
        string $httpMethod,
        string $endpoint,
        string $requestIp,
        SecurityTime $now,
    ): void {
        $this->auditInTransaction(
            $this->db->pdo(),
            $now,
            self::rowInt($row, 'id'),
            self::rowInt($row, 'supplier_id'),
            null,
            $event,
            $outcome,
            $reason,
            $httpMethod,
            $endpoint,
            $requestIp,
        );
    }

    private function auditInTransaction(
        PDO $pdo,
        SecurityTime $now,
        ?int $grantId,
        ?int $supplierId,
        ?int $actorUserId,
        string $event,
        string $outcome,
        string $reason,
        string $httpMethod,
        string $endpoint,
        string $requestIp,
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO tenant_transfer_grant_audit
                (grant_id, supplier_id, actor_user_id, event, outcome, reason,
                 http_method, endpoint, ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $packedIp = @inet_pton($requestIp);
        $statement->execute([
            $grantId,
            $supplierId,
            $actorUserId,
            substr($event, 0, 32),
            $outcome,
            substr($reason, 0, 64),
            substr(strtoupper($httpMethod), 0, 8),
            substr($endpoint, 0, 190),
            $packedIp === false ? null : $packedIp,
            $now->utcSql,
        ]);
    }

    /** @param array<string,mixed> $row */
    private static function state(array $row, SecurityTime $now): string
    {
        if ($row['consumed_at'] !== null) {
            return 'consumed';
        }
        if ($row['revoked_at'] !== null) {
            return ($row['revoked_reason'] ?? null) === 'expired' ? 'expired' : 'revoked';
        }
        $expiresAt = $row['expires_at'] ?? null;
        if (!is_string($expiresAt)) {
            throw new \UnexpectedValueException('Transfer grant nemá platnou expiraci.');
        }
        if (self::parseTime($expiresAt) <= $now->utc) {
            return 'expired';
        }
        if ($row['paired_at'] !== null) {
            return 'paired';
        }
        return 'active';
    }

    private static function hasValidShape(string $plaintext): bool
    {
        return preg_match('/^ttg_v1_[A-Za-z0-9_-]{43}$/D', $plaintext) === 1;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $row['id'] = self::rowInt($row, 'id');
        $row['supplier_id'] = self::rowInt($row, 'supplier_id');
        $row['created_by_user_id'] = self::rowInt($row, 'created_by_user_id');
        return $row;
    }

    /** @return array<string,mixed>|null */
    private static function associativeRow(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $row = [];
        foreach ($value as $key => $field) {
            if (is_string($key)) {
                $row[$key] = $field;
            }
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private static function rowInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException('Transfer grant obsahuje neplatné celé číslo.');
    }

    private static function databaseCount(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException('Databáze vrátila neplatný počet grantů.');
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8)
            . '-' . substr($hex, 8, 4)
            . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4)
            . '-' . substr($hex, 20);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function formatDatabaseTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function formatWireTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function parseTime(string $time): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $time,
            new \DateTimeZone('UTC'),
        );
        if ($parsed === false) {
            throw new \UnexpectedValueException('Neplatný UTC čas transfer grantu.');
        }
        return $parsed;
    }
}
