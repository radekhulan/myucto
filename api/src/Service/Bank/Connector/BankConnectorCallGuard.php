<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;

final class BankConnectorCallGuard
{
    private const COOLDOWN_SECONDS = 30.0;

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    public function withConnectionLock(int $supplierId, int $currencyId, callable $callback): mixed
    {
        $name = NamedLockName::for($this->db, 'bank-connection', $supplierId . ':' . $currencyId);
        return $this->withNamedLock($name, $callback);
    }

    public function call(#[\SensitiveParameter] string $token, callable $callback): mixed
    {
        $credentialHash = hash_hmac('sha256', $token, $this->hmacKey());
        $lockHash = hash_hmac('sha256', $this->lockIdentity($token), $this->hmacKey());
        $lockName = NamedLockName::for($this->db, 'bank-credential', $lockHash);

        return $this->withNamedLock($lockName, function () use ($credentialHash, $callback) {
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare(
                'SELECT TIMESTAMPDIFF(MICROSECOND, last_called_at, CURRENT_TIMESTAMP(6))
                   FROM bank_connector_cooldowns WHERE credential_hash = ?'
            );
            $stmt->execute([$credentialHash]);
            $elapsedMicros = $stmt->fetchColumn();
            if ($elapsedMicros !== false && ((float) $elapsedMicros / 1_000_000) < self::COOLDOWN_SECONDS) {
                throw new BankConnectorOperationException('bank_rate_limited');
            }

            $pdo->prepare(
                'INSERT INTO bank_connector_cooldowns (credential_hash, last_called_at)
                 VALUES (?, CURRENT_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE last_called_at = CURRENT_TIMESTAMP(6)'
            )->execute([$credentialHash]);

            return $callback();
        });
    }

    private function withNamedLock(string $name, callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $stmt->execute([$name]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new BankConnectorOperationException('bank_connection_busy');
        }
        try {
            return $callback();
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$name]);
            } catch (\Throwable) {
            }
        }
    }

    private function hmacKey(): string
    {
        $encoded = (string) $this->config->get('app.secret_encryption_key', '');
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) {
            throw new BankConnectorOperationException('encryption_key_unavailable');
        }
        return $key;
    }

    private function lockIdentity(#[\SensitiveParameter] string $credential): string
    {
        $data = json_decode($credential, true);
        if (!is_array($data) || !is_string($data['certificate'] ?? null) || $data['certificate'] === '' || !is_string($data['password'] ?? null)) {
            return $credential;
        }
        $options = BankClientCertificate::curlOptions($data['certificate'], $data['password']);
        $fingerprint = openssl_x509_fingerprint($options[CURLOPT_SSLCERT_BLOB], 'sha256');
        if ($fingerprint === false) throw new BankConnectorOperationException('certificate_invalid');
        return 'certificate:' . $fingerprint . ':' . (string) ($data['contract_number'] ?? $data['client_id'] ?? '');
    }
}
