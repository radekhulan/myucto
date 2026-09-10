<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Repository\KbPlusOAuthRepository;
use MyInvoice\Service\Auth\SecretEncryption;

final class KbPlusCredentialVault
{
    public function __construct(
        private readonly KbPlusApiClient $api,
        private readonly KbPlusOAuthRepository $repository,
        private readonly SecretEncryption $secrets,
    ) {}

    /** @return array{credentials:array<string,mixed>,access_token:string} */
    public function access(#[\SensitiveParameter] string $serialized): array
    {
        $credentials = $this->decode($serialized);
        if ((int) $credentials['access_expires_at'] > time() + 30) {
            return ['credentials' => $credentials, 'access_token' => (string) $credentials['access_token']];
        }

        $tokens = $this->api->refreshAccessToken($credentials);
        $credentials['access_token'] = $tokens['access_token'];
        $credentials['access_expires_at'] = time() + (int) $tokens['expires_in'];
        $credentials['scope'] = $tokens['scope'];
        if (isset($tokens['refresh_token'])) {
            $credentials['refresh_token'] = $tokens['refresh_token'];
        }
        $updated = $this->encode($credentials);
        $ciphertext = $this->secrets->encryptFor(
            $updated,
            self::context((int) $credentials['supplier_id'], (int) $credentials['connection_id']),
        );
        if (!str_starts_with($ciphertext, 'enc:v2:') || !$this->repository->replaceConnectionCredential(
            (int) $credentials['supplier_id'],
            (int) $credentials['connection_id'],
            $ciphertext,
        )) {
            throw new BankConnectorException('credential_rotation_failed', 'Obnovené credentials KB+ nelze bezpečně uložit.');
        }
        return ['credentials' => $credentials, 'access_token' => (string) $credentials['access_token']];
    }

    /** @return array<string,mixed> */
    public function decode(#[\SensitiveParameter] string $serialized): array
    {
        try {
            $data = json_decode($serialized, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->invalid();
        }
        $required = [
            'version', 'supplier_id', 'connection_id', 'oauth_api_key', 'adaa_api_key', 'batchda_api_key',
            'client_id', 'client_secret', 'redirect_uri', 'scope', 'refresh_token', 'access_token',
            'access_expires_at', 'account_id', 'account_iban', 'account_currency', 'call_guard_key',
        ];
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), $required) !== []
            || array_diff($required, array_keys($data)) !== [] || $data['version'] !== 1
            || !is_int($data['supplier_id']) || $data['supplier_id'] < 1
            || !is_int($data['connection_id']) || $data['connection_id'] < 1
            || !is_int($data['access_expires_at']) || $data['access_expires_at'] < 1
        ) {
            throw $this->invalid();
        }
        foreach (array_diff($required, ['version', 'supplier_id', 'connection_id', 'access_expires_at']) as $key) {
            if (!is_string($data[$key]) || ($data[$key] === '' && $key !== 'batchda_api_key') || strlen($data[$key]) > 16384) {
                throw $this->invalid();
            }
        }
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $data['call_guard_key'])
            || !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $data['account_iban'])
            || !preg_match('/^[A-Z]{3}$/D', $data['account_currency'])
        ) {
            throw $this->invalid();
        }
        return $data;
    }

    /** @param array<string,mixed> $credentials */
    public function encode(#[\SensitiveParameter] array $credentials): string
    {
        try {
            $serialized = json_encode($credentials, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES, 16);
        } catch (\JsonException) {
            throw $this->invalid();
        }
        if (strlen($serialized) > 65535) {
            throw $this->invalid();
        }
        $this->decode($serialized);
        return $serialized;
    }

    public static function context(int $supplierId, int $connectionId): string
    {
        return sprintf('bank-connection:supplier:%d:connection:%d:type:token', $supplierId, $connectionId);
    }

    private function invalid(): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::INVALID_TOKEN, 'Credentials KB+ nemají platný formát.');
    }
}
