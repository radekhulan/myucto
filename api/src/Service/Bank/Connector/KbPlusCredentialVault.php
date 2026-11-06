<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Repository\KbPlusOAuthRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\OAuth\OAuthClientFlow;
use MyInvoice\Service\OAuth\OAuthException;

final class KbPlusCredentialVault
{
    /**
     * Varianta Extra služby API Business sjednaná u KB. Basic nemá ADAA ani dávky,
     * stahuje jednou denně výpisy STATDA za předchozí obchodní dny; Plus čte
     * pohyby ADAA nejvýš jednou za 61 minut. Údaje uložené před zavedením volby
     * variantu nenesou a chovají se jako Plus.
     */
    public const PLAN_BASIC = 'basic';
    public const PLAN_PLUS = 'plus';
    public const PLANS = [self::PLAN_BASIC, self::PLAN_PLUS];

    public function __construct(
        private readonly KbPlusApiClient $api,
        private readonly KbPlusOAuthRepository $repository,
        private readonly SecretEncryption $secrets,
        private readonly OAuthClientFlow $flow,
    ) {}

    /**
     * Platný přístup. Propadlý access token obnoví přes společnou vrstvu ({@see OAuthClientFlow::rotate()}):
     * pod zámkem napojení načte uložený přístup znovu (souběžný požadavek ho mohl obnovit)
     * a obnovený refresh token uloží dřív, než se použije.
     *
     * @return array{credentials:array<string,mixed>,access_token:string}
     */
    public function access(#[\SensitiveParameter] string $serialized): array
    {
        $credentials = $this->decode($serialized);
        if (!self::fresh($credentials)) {
            $supplierId = (int) $credentials['supplier_id'];
            $connectionId = (int) $credentials['connection_id'];
            try {
                $credentials = $this->flow->rotate('bank-token', $supplierId . ':' . $connectionId,
                    fn (): array => $this->stored($supplierId, $connectionId),
                    static fn (array $current): bool => self::fresh($current),
                    function (array $current): array {
                        $tokens = $this->api->refreshAccessToken($current);
                        $current['access_token'] = $tokens['access_token'];
                        $current['access_expires_at'] = time() + (int) $tokens['expires_in'];
                        $current['scope'] = $tokens['scope'];
                        if (isset($tokens['refresh_token'])) {
                            $current['refresh_token'] = $tokens['refresh_token'];
                        }
                        return $current;
                    },
                    function (array $current) use ($supplierId, $connectionId): bool {
                        $ciphertext = $this->secrets->encryptFor($this->encode($current), self::context($supplierId, $connectionId));
                        return str_starts_with($ciphertext, 'enc:v2:')
                            && $this->repository->replaceConnectionCredential($supplierId, $connectionId, $ciphertext);
                    },
                    static fn (): BankConnectorOperationException => new BankConnectorOperationException('bank_connection_busy'));
            } catch (OAuthException) {
                throw new BankConnectorException('credential_rotation_failed', 'Obnovené credentials KB+ nelze bezpečně uložit.');
            }
        }
        return ['credentials' => $credentials, 'access_token' => (string) $credentials['access_token']];
    }

    /** @param array<string,mixed> $credentials */
    private static function fresh(#[\SensitiveParameter] array $credentials): bool
    {
        return (int) $credentials['access_expires_at'] > time() + 30;
    }

    /**
     * Přístup tak, jak je teď uložený u napojení; bez přístupu (odpojeno) ho obnovit nejde.
     *
     * @return array<string,mixed>
     */
    private function stored(int $supplierId, int $connectionId): array
    {
        $ciphertext = $this->repository->connectionCredential($supplierId, $connectionId);
        if ($ciphertext === null) {
            throw new BankConnectorException('credential_rotation_failed', 'Obnovené credentials KB+ nelze bezpečně uložit.');
        }
        try {
            $serialized = $this->secrets->decryptFor($ciphertext, self::context($supplierId, $connectionId));
        } catch (\Throwable) {
            throw $this->invalid();
        }
        $credentials = $this->decode($serialized);
        if ($credentials['supplier_id'] !== $supplierId || $credentials['connection_id'] !== $connectionId) {
            throw $this->invalid();
        }
        return $credentials;
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
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), [...$required, 'api_plan']) !== []
            || array_diff($required, array_keys($data)) !== [] || $data['version'] !== 1
            || (array_key_exists('api_plan', $data) && !in_array($data['api_plan'], self::PLANS, true))
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

    /** @param array<string,mixed> $credentials */
    public static function plan(#[\SensitiveParameter] array $credentials): string
    {
        return ($credentials['api_plan'] ?? null) === self::PLAN_BASIC ? self::PLAN_BASIC : self::PLAN_PLUS;
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
