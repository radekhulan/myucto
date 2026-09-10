<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\KbPlusOAuthRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\AccountNumberNormalizer;

final class KbPlusOnboardingService
{
    private const REGISTRATION_TTL = 1800;
    private const OAUTH_TTL = 300;
    private const REGISTRATION_FIELDS = [
        'client_registration_api_key', 'oauth_api_key', 'adaa_api_key', 'batchda_api_key',
        'certificate_p12', 'certificate_password',
    ];
    private const OPTIONAL_FIELDS = ['batchda_api_key', 'certificate_password'];

    public function __construct(
        private readonly KbPlusOAuthRepository $oauth,
        private readonly BankConnectionRepository $connections,
        private readonly KbPlusRegistrationClient $registrationClient,
        private readonly KbPlusRegistrationService $registration,
        private readonly KbPlusApiClient $api,
        private readonly KbPlusCredentialVault $vault,
        private readonly BankConnectorCallGuard $calls,
        private readonly SecretEncryption $secrets,
        private readonly Config $config,
    ) {}

    public function status(int $supplierId, int $currencyId): array
    {
        $account = $this->account($supplierId, $currencyId);
        $blockers = $this->serverBlockers();
        $connection = $this->connections->findPublicByCurrency($supplierId, $currencyId);
        $client = $this->oauth->client($supplierId);
        $flow = $this->oauth->publicStatus($supplierId, $currencyId);
        $status = $connection !== null && $connection['provider'] === 'kb_plus' && $connection['has_token']
            ? 'connected'
            : ($flow === null ? ($client === null ? 'not_registered' : 'registered')
                : ((in_array($flow['status'], ['pending', 'processing'], true))
                    ? ($flow['stage'] === 'registration' ? 'registration_pending' : 'authorization_pending')
                    : ($flow['status'] === 'expired' ? 'expired' : ($client === null ? 'not_registered' : 'registered'))));

        return [
            'provider' => 'kb_plus',
            'status' => $status,
            'server_ready' => $blockers === [],
            'blockers' => $blockers,
            'required_fields' => $client === null ? self::REGISTRATION_FIELDS : [],
            'registration_fields' => self::REGISTRATION_FIELDS,
            'optional_fields' => self::OPTIONAL_FIELDS,
            'capabilities' => [
                'statement_import' => true,
                'payment_batch_submission' => $this->batchSubmissionAvailable($supplierId, $client),
            ],
            'expires_at' => in_array($status, ['registration_pending', 'authorization_pending'], true)
                ? $this->isoDateTime($flow['expires_at'] ?? null) : null,
            'account' => ['currency_id' => $account['id']],
        ];
    }

    /** @param array<string,mixed> $input */
    public function start(int $supplierId, int $currencyId, int $userId, #[\SensitiveParameter] array $input): array
    {
        if ($userId < 1) {
            throw new BankConnectorOperationException('kb_plus_onboarding_invalid');
        }
        $this->assertServerReady();
        $account = $this->account($supplierId, $currencyId);

        return $this->calls->withConnectionLock($supplierId, $currencyId, function () use (
            $supplierId, $currencyId, $userId, $input, $account,
        ): array {
            $clientRow = $this->oauth->client($supplierId);
            if ($clientRow !== null && $input === []) {
                $client = $this->decryptClient($supplierId, (string) $clientRow['credentials_ciphertext']);
                return $this->startOAuth($supplierId, $currencyId, $userId, $client);
            }
            $credentials = $this->registrationInput($input);
            try {
                $statement = $this->calls->call(
                    $credentials['client_registration_api_key'],
                    fn (): string => $this->registrationClient->createSoftwareStatement(
                        $credentials,
                        $this->softwareMetadata($supplierId),
                    ),
                );
                $state = $this->state();
                $begin = $this->registration->begin($statement, [
                    'client_name' => 'MyÚčto.cz',
                    'client_name_en' => 'MyUcto.cz',
                    'redirect_uris' => [$this->oauthCallback($supplierId)],
                    'scopes' => $this->scopes($credentials),
                ], $state);
                $secret = $this->encodeSecret([
                    'state' => $state,
                    'encryption_key' => $begin['encryption_key'],
                    'oauth_api_key' => $credentials['oauth_api_key'],
                    'adaa_api_key' => $credentials['adaa_api_key'],
                    'batchda_api_key' => $credentials['batchda_api_key'],
                    'redirect_uri' => $this->oauthCallback($supplierId),
                ]);
                $this->storeSession($state, $supplierId, $currencyId, $userId, 'registration', $secret, self::REGISTRATION_TTL);
                return $this->started('registration_pending', $begin['url'], self::REGISTRATION_TTL);
            } catch (BankConnectorException $e) {
                throw new BankConnectorOperationException($e->errorCode);
            }
        });
    }

    /** @param array<string,mixed> $callback */
    public function completeRegistration(
        int $supplierId,
        int $userId,
        #[\SensitiveParameter] string $state,
        #[\SensitiveParameter] array $callback,
    ): array {
        $currencyId = $this->currencyForState($supplierId, $userId, $state);
        if ($currencyId === null) {
            throw new BankConnectorOperationException('kb_plus_onboarding_used_or_expired');
        }
        return $this->calls->withConnectionLock($supplierId, $currencyId, function () use (
            $supplierId, $currencyId, $userId, $state, $callback,
        ): array {
            $session = $this->claim($supplierId, $userId, $state, 'registration');
            $hash = hash('sha256', $state);
            try {
                $secret = $this->decryptSession($session);
                $client = $this->registration->complete(
                    (string) $secret['encryption_key'],
                    (string) $secret['state'],
                    (string) $secret['redirect_uri'],
                    $this->scopes($secret),
                    $callback + ['state' => $state],
                ) + [
                    'oauth_api_key' => $secret['oauth_api_key'],
                    'adaa_api_key' => $secret['adaa_api_key'],
                    'batchda_api_key' => $secret['batchda_api_key'],
                    'redirect_uri' => $secret['redirect_uri'],
                ];
                $this->saveClient($supplierId, $userId, $client);
                $result = $this->startOAuth($supplierId, $currencyId, $userId, $client);
                $this->oauth->finish($hash, true);
                return $result;
            } catch (BankConnectorException $e) {
                $this->oauth->finish($hash, false, $e->errorCode);
                throw new BankConnectorOperationException($e->errorCode);
            } catch (BankConnectorOperationException $e) {
                $this->oauth->finish($hash, false, $e->errorCode);
                throw $e;
            } catch (\Throwable) {
                $this->oauth->finish($hash, false, 'kb_plus_registration_invalid');
                throw new BankConnectorOperationException('kb_plus_registration_invalid');
            }
        });
    }

    public function completeOAuth(
        int $supplierId,
        int $userId,
        #[\SensitiveParameter] string $state,
        #[\SensitiveParameter] string $code,
    ): int {
        $currencyId = $this->currencyForState($supplierId, $userId, $state);
        if ($currencyId === null) {
            throw new BankConnectorOperationException('kb_plus_onboarding_used_or_expired');
        }
        return $this->calls->withConnectionLock($supplierId, $currencyId, function () use (
            $supplierId, $currencyId, $userId, $state, $code,
        ): int {
            $session = $this->claim($supplierId, $userId, $state, 'oauth');
            $hash = hash('sha256', $state);
            try {
                $secret = $this->decryptSession($session);
                try {
                    [$tokens, $accounts] = $this->calls->call(
                        (string) $secret['call_guard_key'],
                        function () use ($secret, $code): array {
                            $tokens = $this->api->exchangeAuthorizationCode($secret, $code);
                            return [$tokens, $this->api->accounts($secret, $tokens['access_token'])];
                        },
                    );
                } catch (BankConnectorException $e) {
                    throw new BankConnectorOperationException($e->errorCode);
                }
                $account = $this->account($supplierId, $currencyId);
                $matches = array_values(array_filter($accounts, fn (array $candidate): bool =>
                    strtoupper((string) $candidate['currency']) === strtoupper((string) $account['code'])
                    && $this->matchesAccount((string) $candidate['iban'], $account)
                ));
                if ($matches === []) {
                    throw new BankConnectorOperationException('kb_plus_account_not_found');
                }
                if (count($matches) !== 1) {
                    throw new BankConnectorOperationException('kb_plus_account_ambiguous');
                }
                $connectionId = $this->connections->ensure($supplierId, $currencyId, 'kb_plus');
                $remote = $matches[0];
                $credentials = [
                    'version' => 1,
                    'supplier_id' => $supplierId,
                    'connection_id' => $connectionId,
                    'oauth_api_key' => $secret['oauth_api_key'],
                    'adaa_api_key' => $secret['adaa_api_key'],
                    'batchda_api_key' => $secret['batchda_api_key'],
                    'client_id' => $secret['client_id'],
                    'client_secret' => $secret['client_secret'],
                    'redirect_uri' => $secret['redirect_uri'],
                    'scope' => $tokens['scope'],
                    'refresh_token' => $tokens['refresh_token'],
                    'access_token' => $tokens['access_token'],
                    'access_expires_at' => time() + (int) $tokens['expires_in'],
                    'account_id' => $remote['accountId'],
                    'account_iban' => strtoupper((string) $remote['iban']),
                    'account_currency' => strtoupper((string) $remote['currency']),
                    'call_guard_key' => $secret['call_guard_key'],
                ];
                $serialized = $this->vault->encode($credentials);
                $ciphertext = $this->secrets->encryptFor($serialized, KbPlusCredentialVault::context($supplierId, $connectionId));
                if (!str_starts_with($ciphertext, 'enc:v2:')) {
                    throw new BankConnectorOperationException('encryption_failed');
                }
                $this->connections->saveValidated(
                    $supplierId, $connectionId, 'kb_plus', $ciphertext, true,
                    strtoupper((string) $remote['iban']), '0100', strtoupper((string) $remote['currency']),
                );
                $this->oauth->finish($hash, true);
                return $currencyId;
            } catch (BankConnectorOperationException $e) {
                $this->oauth->finish($hash, false, $e->errorCode);
                throw $e;
            } catch (\Throwable) {
                $this->oauth->finish($hash, false, 'kb_plus_oauth_failed');
                throw new BankConnectorOperationException('kb_plus_oauth_failed');
            }
        });
    }

    public function currencyForState(int $supplierId, int $userId, string $state): ?int
    {
        return $this->validState($state)
            ? $this->oauth->currencyForState(hash('sha256', $state), $supplierId, $userId)
            : null;
    }

    /** @param array<string,mixed> $client */
    private function startOAuth(int $supplierId, int $currencyId, int $userId, #[\SensitiveParameter] array $client): array
    {
        $state = $this->state();
        $secret = $client + ['state' => $state, 'call_guard_key' => $this->state()];
        $url = $this->api->authorizationUrl($secret, $state);
        $this->storeSession($state, $supplierId, $currencyId, $userId, 'oauth', $this->encodeSecret($secret), self::OAUTH_TTL);
        return $this->started('authorization_pending', $url, self::OAUTH_TTL);
    }

    private function claim(int $supplierId, int $userId, string $state, string $stage): array
    {
        if (!$this->validState($state)) {
            throw new BankConnectorOperationException('kb_plus_onboarding_invalid');
        }
        $session = $this->oauth->claim(hash('sha256', $state), $supplierId, $userId, $stage);
        if ($session === null) {
            throw new BankConnectorOperationException('kb_plus_onboarding_used_or_expired');
        }
        return $session;
    }

    private function account(int $supplierId, int $currencyId): array
    {
        $account = $this->oauth->account($supplierId, $currencyId);
        if ($account === null) {
            throw new BankConnectorOperationException('connection_not_found');
        }
        if (!$account['is_active']) {
            throw new BankConnectorOperationException('account_inactive');
        }
        if ((string) $account['bank_code'] !== '0100') {
            throw new BankConnectorOperationException('provider_account_mismatch');
        }
        return $account;
    }

    private function matchesAccount(string $remoteIban, array $account): bool
    {
        $remote = strtoupper((string) preg_replace('/\s+/', '', $remoteIban));
        $remoteAccount = AccountNumberNormalizer::canonical(null, $remote);
        if ($remoteAccount === null || AccountNumberNormalizer::canonicalBankCode(null, $remote) !== '0100') {
            return false;
        }

        $matchedIdentifier = false;
        $configuredIban = strtoupper((string) preg_replace('/\s+/', '', (string) ($account['iban'] ?? '')));
        if ($configuredIban !== '') {
            $matchedIdentifier = true;
            if (!hash_equals($configuredIban, $remote)) {
                return false;
            }
        }

        $configuredNumber = trim((string) ($account['account_number'] ?? ''));
        if ($configuredNumber !== '') {
            $matchedIdentifier = true;
            $configuredAccount = AccountNumberNormalizer::canonical($configuredNumber);
            if ($configuredAccount === null || !hash_equals($configuredAccount, $remoteAccount)) {
                return false;
            }
        }

        return $matchedIdentifier;
    }

    /** @param array<string,mixed> $input @return array<string,string> */
    private function registrationInput(#[\SensitiveParameter] array $input): array
    {
        $keys = self::REGISTRATION_FIELDS;
        if (array_diff(array_keys($input), $keys) !== [] || array_diff($keys, array_keys($input)) !== []) {
            throw new BankConnectorOperationException('kb_plus_registration_input_invalid');
        }
        $result = [];
        foreach ($keys as $key) {
            if (!is_string($input[$key])
                || (!in_array($key, self::OPTIONAL_FIELDS, true) && $input[$key] === '')
                || strlen($input[$key]) > ($key === 'certificate_p12' ? 32768 : 16384)
            ) {
                throw new BankConnectorOperationException('kb_plus_registration_input_invalid');
            }
            $result[$key] = $key === 'batchda_api_key' && trim($input[$key]) === '' ? '' : $input[$key];
        }
        return $result;
    }

    /**
     * KB+ zatím BATCHDA nenabízí všem; bez jeho klíče se registruje jen čtení,
     * jinak by KB odmítla scope bpisp a registrace by nešla dokončit.
     *
     * @param array<string,mixed> $keys
     * @return list<string>
     */
    private function scopes(#[\SensitiveParameter] array $keys): array
    {
        return trim((string) ($keys['batchda_api_key'] ?? '')) === '' ? ['adaa'] : ['adaa', 'bpisp'];
    }

    private function batchSubmissionAvailable(int $supplierId, ?array $client): bool
    {
        if ($client === null) {
            return false;
        }
        try {
            $credentials = $this->decryptClient($supplierId, (string) $client['credentials_ciphertext']);
        } catch (BankConnectorOperationException) {
            return false;
        }
        return trim((string) ($credentials['batchda_api_key'] ?? '')) !== '';
    }

    /** @return array<string,mixed> */
    private function softwareMetadata(int $supplierId): array
    {
        $base = $this->baseUrl();
        $email = trim((string) $this->config->get('smtp.from_email', ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 43) {
            throw new BankConnectorOperationException('kb_plus_contact_missing');
        }
        return [
            'softwareName' => 'MyÚčto.cz',
            'softwareNameEn' => 'MyUcto.cz',
            'softwareId' => substr(hash('sha256', $base . ':supplier:' . $supplierId), 0, 32),
            'softwareVersion' => '1.0',
            'softwareUri' => $base,
            'redirectUris' => [$this->oauthCallback($supplierId)],
            'registrationBackUri' => $this->registrationCallback($supplierId),
            'contacts' => ['email: ' . $email],
        ];
    }

    private function registrationCallback(int $supplierId): string
    {
        return $this->baseUrl() . '/api/settings/bank-connections/kb-plus/registration/callback?supplier_id=' . $supplierId;
    }

    private function oauthCallback(int $supplierId): string
    {
        return $this->baseUrl() . '/api/settings/bank-connections/kb-plus/oauth/callback?supplier_id=' . $supplierId;
    }

    private function baseUrl(): string
    {
        $value = rtrim(trim((string) $this->config->get('app.url', '')), '/');
        $parts = parse_url($value);
        if (($parts['scheme'] ?? null) !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
        ) {
            throw new BankConnectorOperationException('app_url_invalid');
        }
        return $value;
    }

    /** @return list<string> */
    private function serverBlockers(): array
    {
        $result = [];
        try {
            $this->baseUrl();
        } catch (BankConnectorOperationException $e) {
            $result[] = $e->errorCode;
        }
        if ($this->secrets->validateKey() !== null) {
            $result[] = 'encryption_key_unavailable';
        }
        $email = trim((string) $this->config->get('smtp.from_email', ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 43) {
            $result[] = 'kb_plus_contact_missing';
        }
        return $result;
    }

    private function assertServerReady(): void
    {
        $blockers = $this->serverBlockers();
        if ($blockers !== []) {
            throw new BankConnectorOperationException($blockers[0]);
        }
    }

    private function storeSession(string $state, int $supplierId, int $currencyId, int $userId, string $stage, string $secret, int $ttl): void
    {
        $hash = hash('sha256', $state);
        $ciphertext = $this->secrets->encryptFor($secret, $this->sessionContext($supplierId, $currencyId, $userId, $hash, $stage));
        if (!str_starts_with($ciphertext, 'enc:v2:')) {
            throw new BankConnectorOperationException('encryption_failed');
        }
        $this->oauth->replacePending($hash, $supplierId, $currencyId, $userId, $stage, $ciphertext, $ttl);
    }

    private function decryptSession(array $session): array
    {
        $stored = (string) $session['secret_ciphertext'];
        if (!str_starts_with($stored, 'enc:v2:')) {
            throw new BankConnectorOperationException('credential_format_invalid');
        }
        try {
            $plaintext = $this->secrets->decryptFor($stored, $this->sessionContext(
                (int) $session['supplier_id'], (int) $session['currency_id'], (int) $session['user_id'],
                (string) $session['state_hash'], (string) $session['stage'],
            ));
            $value = json_decode($plaintext, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new BankConnectorOperationException('credential_unavailable');
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new BankConnectorOperationException('credential_unavailable');
        }
        return $value;
    }

    /** @param array<string,mixed> $client */
    private function saveClient(int $supplierId, int $userId, #[\SensitiveParameter] array $client): void
    {
        $ciphertext = $this->secrets->encryptFor($this->encodeSecret($client), $this->clientContext($supplierId));
        if (!str_starts_with($ciphertext, 'enc:v2:')) {
            throw new BankConnectorOperationException('encryption_failed');
        }
        $this->oauth->saveClient($supplierId, $ciphertext, $userId);
    }

    private function decryptClient(int $supplierId, #[\SensitiveParameter] string $stored): array
    {
        if (!str_starts_with($stored, 'enc:v2:')) {
            throw new BankConnectorOperationException('credential_format_invalid');
        }
        try {
            $value = json_decode($this->secrets->decryptFor($stored, $this->clientContext($supplierId)), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new BankConnectorOperationException('credential_unavailable');
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new BankConnectorOperationException('credential_unavailable');
        }
        return $value;
    }

    private function encodeSecret(#[\SensitiveParameter] array $value): string
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES, 16);
        } catch (\JsonException) {
            throw new BankConnectorOperationException('credential_format_invalid');
        }
        if (strlen($json) > 65535) {
            throw new BankConnectorOperationException('credential_format_invalid');
        }
        return $json;
    }

    private function sessionContext(int $supplierId, int $currencyId, int $userId, string $hash, string $stage): string
    {
        return sprintf('kb-plus-oauth:supplier:%d:currency:%d:user:%d:state:%s:stage:%s', $supplierId, $currencyId, $userId, $hash, $stage);
    }

    private function clientContext(int $supplierId): string
    {
        return sprintf('kb-plus-client:supplier:%d:provider:kb_plus', $supplierId);
    }

    private function state(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function validState(string $state): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $state) === 1;
    }

    private function started(string $status, string $url, int $ttl): array
    {
        return [
            'status' => $status,
            'redirect_url' => $url,
            'expires_at' => (new \DateTimeImmutable())->modify('+' . $ttl . ' seconds')->format(DATE_ATOM),
        ];
    }

    private function isoDateTime(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
