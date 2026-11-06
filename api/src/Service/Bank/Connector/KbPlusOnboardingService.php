<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\KbPlusOAuthRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\OAuth\OAuthAuthorization;
use MyInvoice\Service\OAuth\OAuthCallback;
use MyInvoice\Service\OAuth\OAuthClientFlow;
use MyInvoice\Service\OAuth\OAuthException;

/**
 * Připojení účtu KB+ ve dvou krocích: registrace klienta u KB (registrační callback KB+)
 * a OAuth souhlas (bez PKCE, KB+ ho nepodporuje). State a rozpracované přihlášení obou
 * kroků drží společná vrstva {@see OAuthClientFlow} (doména bank, poskytovatel kb_plus).
 *
 * Redirect URI s `?supplier_id=` je registrovaná u KB při registraci klienta, proto zůstává;
 * routa je alias společného callbacku. Přihlášení zahájená před přechodem na společnou
 * vrstvu se dočtou z bank_oauth_sessions ({@see claim()}).
 */
final class KbPlusOnboardingService
{
    public const PROVIDER = 'kb_plus';
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
        private readonly OAuthClientFlow $flow,
    ) {}

    public function status(int $supplierId, int $currencyId): array
    {
        $account = $this->account($supplierId, $currencyId);
        $blockers = $this->serverBlockers();
        $connection = $this->connections->findPublicByCurrency($supplierId, $currencyId);
        $client = $this->oauth->client($supplierId);
        $flow = $this->flowStatus($supplierId, $currencyId);
        $connected = $connection !== null && $connection['provider'] === 'kb_plus' && $connection['has_token'];
        $token = $connected ? $this->connectedCredentials($supplierId, $currencyId) : null;
        $batch = $this->batchSubmission($supplierId, $client, $connected, $token);
        $status = $connected
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
            'api_plan' => $token === null ? null : KbPlusCredentialVault::plan($token),
            'capabilities' => [
                'statement_import' => true,
                'statement_import_status' => $this->statementImport($supplierId, $client, $token),
                'payment_batch_submission' => $batch === 'available',
                'payment_batch_status' => $batch,
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
        $plan = $this->planInput($input['api_plan'] ?? KbPlusCredentialVault::PLAN_PLUS, 'kb_plus_registration_input_invalid');
        unset($input['api_plan']);

        return $this->calls->withConnectionLock($supplierId, $currencyId, function () use (
            $supplierId, $currencyId, $userId, $input, $account, $plan,
        ): array {
            $clientRow = $this->oauth->client($supplierId);
            if ($clientRow !== null && $input === []) {
                $client = $this->decryptClient($supplierId, (string) $clientRow['credentials_ciphertext']);
                return $this->startOAuth($supplierId, $currencyId, $userId, $client, $plan);
            }
            $batches = $this->paymentBatchesRequested($input);
            unset($input['payment_batches']);
            $credentials = $this->registrationInput($input);
            try {
                $authorization = $this->flow->authorize(false, OAuthClientFlow::STATE_BASE64URL);
                $state = $authorization->state;
                $statement = $this->calls->call(
                    $credentials['client_registration_api_key'],
                    fn (): string => $this->registrationClient->createSoftwareStatement(
                        $credentials,
                        $this->softwareMetadata($supplierId, $state),
                    ),
                );
                $begin = $this->registration->begin($statement, [
                    'client_name' => 'MyÚčto.cz',
                    'client_name_en' => 'MyUcto.cz',
                    'redirect_uris' => [$this->oauthCallback($supplierId)],
                    'scopes' => $this->scopes($credentials + ['payment_batches' => $batches, 'api_plan' => $plan]),
                ], $state);
                $secret = [
                    'state' => $state,
                    'encryption_key' => $begin['encryption_key'],
                    'payment_batches' => $batches,
                    'api_plan' => $plan,
                    'oauth_api_key' => $credentials['oauth_api_key'],
                    'adaa_api_key' => $credentials['adaa_api_key'],
                    'batchda_api_key' => $credentials['batchda_api_key'],
                    'redirect_uri' => $this->oauthCallback($supplierId),
                ];
                $this->storeSession($authorization, $supplierId, $currencyId, $userId, 'registration', $secret, self::REGISTRATION_TTL);
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
        if ($state === '' && !array_key_exists('state', $callback)) {
            $state = $this->registrationStateFromResponse($supplierId, $userId, $callback);
        }
        $currencyId = $this->currencyForState($supplierId, $userId, $state);
        if ($currencyId === null) {
            throw new BankConnectorOperationException('kb_plus_onboarding_used_or_expired');
        }
        return $this->calls->withConnectionLock($supplierId, $currencyId, function () use (
            $supplierId, $currencyId, $userId, $state, $callback,
        ): array {
            [$open, $finish] = $this->claim($supplierId, $userId, $state, 'registration');
            try {
                $secret = $open();
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
                $finish(true, null);
                return $this->startOAuth($supplierId, $currencyId, $userId, $client, KbPlusCredentialVault::plan($secret));
            } catch (BankConnectorException $e) {
                $finish(false, $e->errorCode);
                throw new BankConnectorOperationException($e->errorCode);
            } catch (BankConnectorOperationException $e) {
                $finish(false, $e->errorCode);
                throw $e;
            } catch (\Throwable) {
                $finish(false, 'kb_plus_registration_invalid');
                throw new BankConnectorOperationException('kb_plus_registration_invalid');
            }
        });
    }

    /** Návrat z KB na společný callback (nebo jeho alias) pro přihlášení zahájené přes společnou vrstvu. */
    public function completeCallback(OAuthCallback $callback): int
    {
        return $this->completeOAuth($callback->supplierId(), $callback->userId(), $callback->state, $callback->code);
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
            [$open, $finish] = $this->claim($supplierId, $userId, $state, 'oauth');
            try {
                $secret = $open();
                $basic = KbPlusCredentialVault::plan($secret) === KbPlusCredentialVault::PLAN_BASIC;
                try {
                    [$tokens, $accounts] = $this->calls->call(
                        (string) $secret['call_guard_key'],
                        function () use ($secret, $code, $basic): array {
                            $tokens = $this->api->exchangeAuthorizationCode($secret, $code);
                            // Basic nemá ADAA; účty pro výpisy vrací STATDA, a to bez měny.
                            return [$tokens, $basic
                                ? $this->api->statementAccounts($tokens['access_token'])
                                : $this->api->accounts($secret, $tokens['access_token'])];
                        },
                    );
                } catch (BankConnectorException $e) {
                    throw new BankConnectorOperationException($e->errorCode);
                }
                $account = $this->account($supplierId, $currencyId);
                $matches = array_values(array_filter($accounts, fn (array $candidate): bool =>
                    (!isset($candidate['currency']) || strtoupper((string) $candidate['currency']) === strtoupper((string) $account['code']))
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
                $remoteCurrency = strtoupper((string) ($remote['currency'] ?? $account['code']));
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
                    'account_currency' => $remoteCurrency,
                    'call_guard_key' => $secret['call_guard_key'],
                    'api_plan' => KbPlusCredentialVault::plan($secret),
                ];
                $serialized = $this->vault->encode($credentials);
                $ciphertext = $this->secrets->encryptFor($serialized, KbPlusCredentialVault::context($supplierId, $connectionId));
                if (!str_starts_with($ciphertext, 'enc:v2:')) {
                    throw new BankConnectorOperationException('encryption_failed');
                }
                $this->connections->saveValidated(
                    $supplierId, $connectionId, 'kb_plus', $ciphertext, true,
                    strtoupper((string) $remote['iban']), '0100', $remoteCurrency,
                );
                $finish(true, null);
                return $currencyId;
            } catch (BankConnectorOperationException $e) {
                $finish(false, $e->errorCode);
                throw $e;
            } catch (\Throwable) {
                $finish(false, 'kb_plus_oauth_failed');
                throw new BankConnectorOperationException('kb_plus_oauth_failed');
            }
        });
    }

    /** Účet rozpracovaného přihlášení; přihlášení z doby před společnou vrstvou se hledá v bank_oauth_sessions. */
    public function currencyForState(int $supplierId, int $userId, string $state): ?int
    {
        if (!$this->validState($state)) return null;
        $session = $this->flow->find($state);
        if ($session !== null) {
            return $session['supplier_id'] === $supplierId && $session['user_id'] === $userId
                && $session['domain'] === BankOAuthCallbackHandler::DOMAIN && $session['provider'] === self::PROVIDER
                ? $session['subject_id'] : null;
        }
        return $this->oauth->currencyForState(hash('sha256', $state), $supplierId, $userId);
    }

    /**
     * Přepne sjednanou variantu API Business u připojeného účtu. Mění se jen
     * chování aplikace (odstup automatického načítání, období, dávky); souhlas
     * v KB zůstává, proto se banka nevolá.
     */
    public function changePlan(int $supplierId, int $currencyId, mixed $plan): array
    {
        $plan = $this->planInput($plan, 'kb_plus_plan_invalid');
        $this->account($supplierId, $currencyId);
        $this->calls->withConnectionLock($supplierId, $currencyId, function () use ($supplierId, $currencyId, $plan): void {
            $row = $this->connections->findWithCredentialByCurrency($supplierId, $currencyId);
            $stored = (string) ($row['token_ciphertext'] ?? '');
            if ($row === null || ($row['provider'] ?? null) !== 'kb_plus' || !str_starts_with($stored, 'enc:v2:')) {
                throw new BankConnectorOperationException('kb_plus_not_connected');
            }
            $context = KbPlusCredentialVault::context($supplierId, (int) $row['id']);
            try {
                $credentials = $this->vault->decode($this->secrets->decryptFor($stored, $context));
                $credentials['api_plan'] = $plan;
                $serialized = $this->vault->encode($credentials);
            } catch (\Throwable) {
                throw new BankConnectorOperationException('credential_unavailable');
            }
            $ciphertext = $this->secrets->encryptFor($serialized, $context);
            if (!str_starts_with($ciphertext, 'enc:v2:')
                || !$this->oauth->replaceConnectionCredential($supplierId, (int) $row['id'], $ciphertext)
            ) {
                throw new BankConnectorOperationException('encryption_failed');
            }
        });
        return $this->status($supplierId, $currencyId);
    }

    /** @param array<string,mixed> $client */
    private function startOAuth(int $supplierId, int $currencyId, int $userId, #[\SensitiveParameter] array $client, string $plan): array
    {
        // KB+ PKCE nepodporuje; state v base64url jako před společnou vrstvou.
        $authorization = $this->flow->authorize(false, OAuthClientFlow::STATE_BASE64URL);
        $state = $authorization->state;
        $secret = $client + ['state' => $state, 'call_guard_key' => $this->state()];
        $secret['api_plan'] = $plan;
        // Souhlas nemůže přesáhnout registraci: Basic čte výpisy STATDA, Plus pohyby ADAA.
        if (!self::hasScope((string) ($client['scope'] ?? ''), self::planScope($plan))) {
            throw new BankConnectorOperationException('kb_plus_registration_plan_mismatch');
        }
        // Basic nemá ADAA ani dávky, souhlas se žádá jen k výpisům i u širší registrace.
        if ($plan === KbPlusCredentialVault::PLAN_BASIC) {
            $secret['scope'] = 'statda';
        }
        $url = $this->api->authorizationUrl($secret, $state);
        $this->storeSession($authorization, $supplierId, $currencyId, $userId, 'oauth', $secret, self::OAUTH_TTL);
        return $this->started('authorization_pending', $url, self::OAUTH_TTL);
    }

    /**
     * Jednorázově převezme rozpracované přihlášení: ze společné vrstvy, jinak z bank_oauth_sessions
     * (přihlášení zahájené před přechodem na ni). Vrací [otevření tajemství, ukončení přihlášení].
     *
     * @return array{0:\Closure():array<string,mixed>, 1:\Closure(bool, ?string):void}
     */
    private function claim(int $supplierId, int $userId, string $state, string $stage): array
    {
        if (!$this->validState($state)) {
            throw new BankConnectorOperationException('kb_plus_onboarding_invalid');
        }
        try {
            $session = $this->flow->claim($state, $supplierId, $userId, BankOAuthCallbackHandler::DOMAIN, self::PROVIDER, $stage);
        } catch (OAuthException) {
            throw new BankConnectorOperationException('credential_unavailable');
        }
        if ($session !== null) {
            return [static fn (): array => $session->secret,
                fn (bool $success, ?string $errorCode): mixed => $this->flow->finish($session, $success, $errorCode)];
        }
        $hash = hash('sha256', $state);
        $legacy = $this->oauth->claim($hash, $supplierId, $userId, $stage);
        if ($legacy === null) {
            throw new BankConnectorOperationException('kb_plus_onboarding_used_or_expired');
        }
        return [fn (): array => $this->decryptSession($legacy),
            fn (bool $success, ?string $errorCode): mixed => $this->oauth->finish($hash, $success, $errorCode)];
    }

    /** Stav posledního přihlášení účtu pro UI; bez přihlášení ve společné vrstvě to z bank_oauth_sessions. */
    private function flowStatus(int $supplierId, int $currencyId): ?array
    {
        $row = $this->flow->latest($supplierId, BankOAuthCallbackHandler::DOMAIN, self::PROVIDER, $currencyId);
        if ($row === null) return $this->oauth->publicStatus($supplierId, $currencyId);
        return ['stage' => $row['stage'], 'status' => $row['status'] === 'pending' && $row['expired'] ? 'expired' : $row['status'],
            'error_code' => $row['error_code'], 'expires_at' => $row['expires_at']];
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
     * BATCHDA stojí na stejné registraci a tokenech jako ADAA, jen se scope bpisp.
     * Ten se žádá, když si správce dávky výslovně zvolí nebo vyplní klíč BATCHDA;
     * jinak zůstane registrace jen pro čtení, protože scope bez sjednané služby KB odmítne.
     * Basic ADAA ani dávky nezahrnuje, registruje se jen pro výpisy STATDA.
     *
     * @param array<string,mixed> $keys
     * @return list<string>
     */
    private function scopes(#[\SensitiveParameter] array $keys): array
    {
        if (KbPlusCredentialVault::plan($keys) === KbPlusCredentialVault::PLAN_BASIC) {
            return ['statda'];
        }
        return ($keys['payment_batches'] ?? false) === true || trim((string) ($keys['batchda_api_key'] ?? '')) !== ''
            ? ['adaa', 'bpisp']
            : ['adaa'];
    }

    private function planInput(mixed $plan, string $errorCode): string
    {
        if (!is_string($plan) || !in_array($plan, KbPlusCredentialVault::PLANS, true)) {
            throw new BankConnectorOperationException($errorCode);
        }
        return $plan;
    }

    /** @return array<string,mixed>|null */
    private function connectedCredentials(int $supplierId, int $currencyId): ?array
    {
        $row = $this->connections->findWithCredentialByCurrency($supplierId, $currencyId);
        $stored = (string) ($row['token_ciphertext'] ?? '');
        if ($row === null || !str_starts_with($stored, 'enc:v2:')) {
            return null;
        }
        try {
            return $this->vault->decode($this->secrets->decryptFor(
                $stored,
                KbPlusCredentialVault::context($supplierId, (int) $row['id']),
            ));
        } catch (\Throwable) {
            return null;
        }
    }

    private function paymentBatchesRequested(array $input): bool
    {
        $value = $input['payment_batches'] ?? false;
        if (!is_bool($value)) {
            throw new BankConnectorOperationException('kb_plus_registration_input_invalid');
        }
        return $value;
    }

    /**
     * Rozšíření o dávky má u KB dvě cesty: registrace bez bpisp se musí zopakovat
     * (Zadat klíče znovu), registrace s bpisp potřebuje jen nový souhlas.
     *
     * Varianta Basic dávky nezahrnuje vůbec, takže má přednost před chybějícím scope.
     *
     * @param array<string,mixed>|null $token
     * @return 'available'|'not_registered'|'plan_basic'|'registration_scope_missing'|'authorization_scope_missing'|'unknown'
     */
    private function batchSubmission(int $supplierId, ?array $client, bool $connected, #[\SensitiveParameter] ?array $token): string
    {
        if ($token !== null && KbPlusCredentialVault::plan($token) === KbPlusCredentialVault::PLAN_BASIC) {
            return 'plan_basic';
        }
        if ($client !== null) {
            try {
                $credentials = $this->decryptClient($supplierId, (string) $client['credentials_ciphertext']);
            } catch (BankConnectorOperationException) {
                return 'unknown';
            }
            if (!KbPlusApiClient::grantsBatchPayments((string) ($credentials['scope'] ?? ''))) {
                return 'registration_scope_missing';
            }
        }
        if (!$connected) {
            return $client === null ? 'not_registered' : 'available';
        }
        if ($token === null) {
            return 'unknown';
        }
        return KbPlusApiClient::grantsBatchPayments((string) ($token['scope'] ?? '')) ? 'available' : 'authorization_scope_missing';
    }

    /**
     * Po přepnutí varianty u připojeného účtu musí registrace i udělený souhlas
     * nést scope služby, přes kterou varianta čte (Basic STATDA, Plus ADAA).
     * Nepřipojený účet nemá co hlásit.
     *
     * @param array<string,mixed>|null $token
     * @return 'available'|'registration_scope_missing'|'authorization_scope_missing'|'unknown'|null
     */
    private function statementImport(int $supplierId, ?array $client, #[\SensitiveParameter] ?array $token): ?string
    {
        if ($token === null) {
            return null;
        }
        $scope = self::planScope(KbPlusCredentialVault::plan($token));
        if (self::hasScope((string) ($token['scope'] ?? ''), $scope)) {
            return 'available';
        }
        if ($client === null) {
            return 'unknown';
        }
        try {
            $registered = (string) ($this->decryptClient($supplierId, (string) $client['credentials_ciphertext'])['scope'] ?? '');
        } catch (BankConnectorOperationException) {
            return 'unknown';
        }
        return self::hasScope($registered, $scope) ? 'authorization_scope_missing' : 'registration_scope_missing';
    }

    private static function planScope(string $plan): string
    {
        return $plan === KbPlusCredentialVault::PLAN_BASIC ? 'statda' : 'adaa';
    }

    private static function hasScope(string $scopes, string $scope): bool
    {
        return in_array($scope, preg_split('/\s+/', trim($scopes)) ?: [], true);
    }

    /** @return array<string,mixed> */
    private function softwareMetadata(int $supplierId, string $state): array
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
            'registrationBackUri' => $this->registrationCallback($supplierId) . '&state=' . rawurlencode($state),
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

    /**
     * Uloží rozpracované přihlášení do společné vrstvy. Platí jen poslední zahájené, proto
     * končí i rozpracované přihlášení účtu v bank_oauth_sessions.
     *
     * @param array<string,mixed> $secret
     */
    private function storeSession(OAuthAuthorization $authorization, int $supplierId, int $currencyId, int $userId, string $stage,
        #[\SensitiveParameter] array $secret, int $ttl): void
    {
        $this->encodeSecret($secret);
        $this->oauth->cancelPending($supplierId, $currencyId);
        try {
            $this->flow->store($authorization, $supplierId, $userId, BankOAuthCallbackHandler::DOMAIN, self::PROVIDER, $currencyId,
                OAuthClientFlow::FLOW_REDIRECT, $secret, $ttl, $stage);
        } catch (OAuthException $e) {
            throw new BankConnectorOperationException($e->errorCode === OAuthException::ENCRYPTION_KEY ? 'encryption_key_unavailable' : 'encryption_failed');
        }
    }

    private function registrationStateFromResponse(int $supplierId, int $userId, #[\SensitiveParameter] array $callback): string
    {
        $candidates = [];
        foreach ($this->flow->pendingForUser($supplierId, $userId, BankOAuthCallbackHandler::DOMAIN, self::PROVIDER, 'registration') as $session) {
            $candidates[] = [$session->stateHash, static fn (): array => $session->secret];
        }
        foreach ($this->oauth->pendingRegistrations($supplierId, $userId) as $session) {
            $candidates[] = [(string) $session['state_hash'], fn (): array => $this->decryptSession($session)];
        }
        $matched = null;
        foreach ($candidates as [$stateHash, $open]) {
            try {
                $secret = $open();
                $state = (string) ($secret['state'] ?? '');
                if (!$this->validState($state) || !hash_equals($stateHash, hash('sha256', $state))) {
                    continue;
                }
                $this->registration->complete(
                    (string) $secret['encryption_key'],
                    $state,
                    (string) $secret['redirect_uri'],
                    $this->scopes($secret),
                    $callback + ['state' => $state],
                );
            } catch (BankConnectorException | BankConnectorOperationException) {
                continue;
            }
            if ($matched !== null) {
                throw new BankConnectorOperationException('kb_plus_registration_invalid');
            }
            $matched = $state;
        }
        if ($matched === null) {
            throw new BankConnectorOperationException('kb_plus_onboarding_used_or_expired');
        }
        return $matched;
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
