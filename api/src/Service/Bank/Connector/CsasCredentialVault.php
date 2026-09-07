<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Repository\CsasOAuthRepository;
use MyInvoice\Service\Auth\SecretEncryption;

final class CsasCredentialVault
{
    public function __construct(private readonly CsasApiClient $api, private readonly CsasOAuthRepository $repository,
        private readonly SecretEncryption $secrets) {}

    public function access(#[\SensitiveParameter] string $serialized): array
    {
        $data = $this->decode($serialized);
        if ($data['access_expires_at'] > time() + 30) return $data;
        $tokens = $this->api->refresh($data);
        $data['access_token'] = $tokens['access_token'];
        $data['access_expires_at'] = time() + $tokens['expires_in'];
        $data['refresh_token'] = $tokens['refresh_token'] ?? $data['refresh_token'];
        $ciphertext = $this->secrets->encryptFor($this->encode($data), self::context($data['supplier_id'], $data['connection_id']));
        if (!str_starts_with($ciphertext, 'enc:v2:') || !$this->repository->rotate($data['supplier_id'], $data['connection_id'], $ciphertext)) {
            throw new BankConnectorException('credential_rotation_failed', 'Obnovený přístup nelze bezpečně uložit.');
        }
        return $data;
    }

    public function decode(#[\SensitiveParameter] string $serialized): array
    {
        try {
            if (strlen($serialized) > 65535) throw new \RuntimeException();
            $data = json_decode($serialized, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new \RuntimeException();
            $data['environment'] ??= 'production';
            $required = ['version', 'supplier_id', 'connection_id', 'api_key', 'client_id', 'client_secret', 'redirect_uri',
                'access_token', 'refresh_token', 'access_expires_at', 'account_id', 'account_iban', 'account_currency', 'call_guard_key', 'environment'];
            if (!is_array($data) || array_diff(array_keys($data), $required) || array_diff($required, array_keys($data))
                || $data['version'] !== 1) throw new \RuntimeException();
            foreach (['supplier_id', 'connection_id', 'access_expires_at'] as $key) {
                if (!is_int($data[$key]) || $data[$key] < 1) throw new \RuntimeException();
            }
            foreach (array_diff($required, ['version', 'supplier_id', 'connection_id', 'access_expires_at']) as $key) {
                if (!is_string($data[$key]) || $data[$key] === '' || strlen($data[$key]) > 16384
                    || preg_match('/[\x00-\x1f\x7f]/', $data[$key])) throw new \RuntimeException();
            }
            if (!preg_match('/^CZ\d{2}0800\d{16}$/D', $data['account_iban'])
                || !preg_match('/^[A-Z]{3}$/D', $data['account_currency'])
                || !preg_match('/^[a-f0-9]{64}$/D', $data['call_guard_key'])) throw new \RuntimeException();
            $this->api->assertEnvironment($data);
            return $data;
        } catch (\Throwable) {
            throw new BankConnectorException(BankConnectorException::INVALID_TOKEN, 'Neplatný přístup České spořitelny.');
        }
    }

    public function encode(#[\SensitiveParameter] array $data): string
    {
        $value = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->decode($value);
        return $value;
    }

    public static function context(int $supplierId, int $connectionId): string
    {
        return sprintf('bank-connection:supplier:%d:connection:%d:type:token', $supplierId, $connectionId);
    }
}
