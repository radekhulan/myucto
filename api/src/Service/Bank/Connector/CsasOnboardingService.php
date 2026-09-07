<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\CsasOAuthRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\AccountNumberNormalizer;

final class CsasOnboardingService
{
    public function __construct(private readonly CsasOAuthRepository $oauth, private readonly BankConnectionRepository $connections,
        private readonly CsasApiClient $api, private readonly CsasCredentialVault $vault, private readonly BankConnectorCallGuard $calls,
        private readonly SecretEncryption $secrets, private readonly Config $config) {}

    public function callbackUrl(): string
    {
        $base = rtrim((string) $this->config->get('app.url', ''), '/');
        $parts = parse_url($base);
        if (($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new BankConnectorOperationException('app_url_invalid');
        }
        return $base . '/api/settings/bank-connections/csas/oauth/callback';
    }

    public function status(int $supplierId, int $currencyId): array
    {
        $this->account($supplierId, $currencyId);
        return ['callback_url' => $this->callbackUrl(), 'server_ready' => $this->secrets->validateKey() === null,
            'environment' => $this->api->environment()];
    }

    public function start(int $supplierId, int $currencyId, int $userId, #[\SensitiveParameter] array $input): array
    {
        $this->account($supplierId, $currencyId);
        if ($userId < 1 || array_diff(array_keys($input), ['api_key', 'client_id', 'client_secret'])) {
            throw new BankConnectorOperationException('credential_invalid');
        }
        if ($this->secrets->validateKey() !== null) throw new BankConnectorOperationException('encryption_key_unavailable');
        $credentials = [];
        foreach (['api_key', 'client_id', 'client_secret'] as $key) {
            $value = $input[$key] ?? null;
            if (!is_string($value) || trim($value) === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value)) {
                throw new BankConnectorOperationException('credential_invalid');
            }
            $credentials[$key] = $value;
        }
        $credentials['redirect_uri'] = $this->callbackUrl();
        $credentials['environment'] = $this->api->environment();
        $credentials['code_verifier'] = bin2hex(random_bytes(32));
        return $this->calls->withConnectionLock($supplierId, $currencyId, function () use ($supplierId, $currencyId, $userId, $credentials): array {
            $state = bin2hex(random_bytes(32));
            $hash = hash('sha256', $state);
            $ciphertext = $this->secrets->encryptFor(json_encode($credentials, JSON_THROW_ON_ERROR), $this->sessionContext($supplierId, $hash));
            if (!str_starts_with($ciphertext, 'enc:v2:')) throw new BankConnectorOperationException('encryption_failed');
            $url = $this->api->authorizationUrl($credentials, $state);
            $this->oauth->start($hash, $supplierId, $currencyId, $userId, $ciphertext);
            return ['redirect_url' => $url];
        });
    }

    public function complete(int $supplierId, int $userId, #[\SensitiveParameter] string $state, #[\SensitiveParameter] string $code): int
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $state)) throw new BankConnectorOperationException('csas_oauth_expired');
        $hash = hash('sha256', $state);
        $currencyId = $this->oauth->currency($hash, $supplierId, $userId);
        if ($currencyId === null) throw new BankConnectorOperationException('csas_oauth_expired');
        return $this->calls->withConnectionLock($supplierId, $currencyId, function () use ($supplierId, $currencyId, $userId, $hash, $code): int {
            $session = $this->oauth->claim($hash, $supplierId, $userId);
            if ($session === null) throw new BankConnectorOperationException('csas_oauth_expired');
            try {
                $account = $this->account($supplierId, $currencyId);
                $credentials = json_decode($this->secrets->decryptFor($session['secret_ciphertext'], $this->sessionContext($supplierId, $hash)), true, 8, JSON_THROW_ON_ERROR);
                if ($credentials['redirect_uri'] !== $this->callbackUrl()) throw new BankConnectorOperationException('app_url_invalid');
                $tokens = $this->api->exchange($credentials, $code);
                unset($credentials['code_verifier']);
                $credentials['access_token'] = $tokens['access_token'];
                $matches = array_values(array_filter($this->api->accounts($credentials), static fn (array $remote): bool =>
                    is_string($remote['identification']['iban'] ?? null)
                    && preg_match('/^CZ\d{2}0800\d{16}$/D', $remote['identification']['iban'])
                    && ($remote['currency'] ?? '') === $account['code']
                    && AccountNumberNormalizer::equalsCzech($remote['identification']['iban'], (string) ($account['account_number'] ?: $account['iban']))));
                if (count($matches) !== 1) throw new BankConnectorOperationException('statement_account_mismatch');
                $remote = $matches[0];
                $connectionId = $this->connections->ensure($supplierId, $currencyId, 'csas');
                $credentials += ['version' => 1, 'supplier_id' => $supplierId, 'connection_id' => $connectionId,
                    'refresh_token' => $tokens['refresh_token'], 'access_expires_at' => time() + $tokens['expires_in'],
                    'account_id' => $remote['id'], 'account_iban' => $remote['identification']['iban'],
                    'account_currency' => $remote['currency'], 'call_guard_key' => bin2hex(random_bytes(32))];
                $ciphertext = $this->secrets->encryptFor($this->vault->encode($credentials), CsasCredentialVault::context($supplierId, $connectionId));
                if (!str_starts_with($ciphertext, 'enc:v2:')) throw new BankConnectorOperationException('encryption_failed');
                $this->connections->saveValidated($supplierId, $connectionId, 'csas', $ciphertext, true,
                    $remote['identification']['iban'], '0800', $remote['currency']);
                $this->oauth->finish($hash, true);
                return $currencyId;
            } catch (\Throwable $e) {
                $this->oauth->finish($hash, false);
                throw new BankConnectorOperationException($e instanceof BankConnectorOperationException || $e instanceof BankConnectorException
                    ? $e->errorCode : 'csas_oauth_failed');
            }
        });
    }

    private function account(int $supplierId, int $currencyId): array
    {
        $account = $this->oauth->account($supplierId, $currencyId);
        if ($account === null) throw new BankConnectorOperationException('connection_not_found');
        if (!(bool) $account['is_active']) throw new BankConnectorOperationException('account_inactive');
        if ($account['bank_code'] !== '0800') throw new BankConnectorOperationException('provider_account_mismatch');
        return $account;
    }

    private function sessionContext(int $supplierId, string $hash): string
    {
        return 'bank-csas-oauth:supplier:' . $supplierId . ':state:' . $hash;
    }
}
