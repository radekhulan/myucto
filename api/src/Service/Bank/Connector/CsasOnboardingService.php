<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\CsasOAuthRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\OAuth\OAuthCallback;
use MyInvoice\Service\OAuth\OAuthClientFlow;

/**
 * Připojení účtu České spořitelny přes OAuth (PKCE S256). State, PKCE a rozpracované
 * přihlášení drží společná vrstva {@see OAuthClientFlow} (doména bank, poskytovatel csas);
 * výměnu kódu a ověření účtu dělá tato služba.
 *
 * Redirect URI zůstává `/api/settings/bank-connections/csas/oauth/callback`: je registrovaná
 * v aplikaci u banky. Routa je alias společného callbacku a přihlášení zahájená před
 * přechodem na společnou vrstvu (bank_oauth_sessions) dokončí {@see complete()}.
 *
 * S klíči platformy ({@see platformCredentials()}) firma klíče nezadává a banka vrací
 * přihlášení na společnou adresu platformy (myucto.cz). State pak nese za tečkou adresu
 * instance, předávací stránka ji podle seznamu povolených instancí přesměruje sem a tečku
 * s adresou ze state odebere.
 */
final class CsasOnboardingService
{
    public const PROVIDER = 'csas';
    private const OAUTH_TTL = 600;

    public function __construct(private readonly CsasOAuthRepository $oauth, private readonly BankConnectionRepository $connections,
        private readonly CsasApiClient $api, private readonly CsasCredentialVault $vault, private readonly BankConnectorCallGuard $calls,
        private readonly SecretEncryption $secrets, private readonly Config $config, private readonly OAuthClientFlow $flow,
        private readonly CsasSiblingAccountLinker $siblings) {}

    /** Adresa, na kterou banka vrací přihlášení: společná adresa platformy, jinak callback instance. */
    public function callbackUrl(): string
    {
        return $this->platformCredentials()['redirect_uri'] ?? $this->instanceCallbackUrl();
    }

    /**
     * Klíče aplikace MyÚčta registrované u České spořitelny (`bank_connectors.csas.platform`).
     * Když jsou nastavené, firma klíče nezadává ani nevidí a jen udělí souhlas v bance.
     * null = firma zadává vlastní klíče; neúplná konfigurace je chyba, ne návrat k vlastním klíčům.
     *
     * @return array{api_key:string,client_id:string,client_secret:string,redirect_uri:string}|null
     */
    public function platformCredentials(): ?array
    {
        $platform = $this->config->get('bank_connectors.csas.platform', null);
        if ($platform === null || $platform === []) return null;
        if (!is_array($platform)) throw new BankConnectorOperationException('platform_config_invalid');
        $credentials = [];
        foreach (['api_key', 'client_id', 'client_secret', 'redirect_uri'] as $key) {
            $value = $platform[$key] ?? null;
            if (!self::validCredential($value)) throw new BankConnectorOperationException('platform_config_invalid');
            $credentials[$key] = $value;
        }
        $parts = parse_url($credentials['redirect_uri']);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new BankConnectorOperationException('platform_config_invalid');
        }
        return $credentials;
    }

    private static function validCredential(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 2048 && !preg_match('/[\x00-\x20\x7f]/', $value);
    }

    /**
     * Instance, které společná návratová adresa předá návrat z banky (do state); null,
     * když banka vrací přihlášení přímo na callback instance.
     */
    private function stateHost(string $redirectUri): ?string
    {
        $instance = parse_url($this->instanceCallbackUrl());
        $host = strtolower((string) $instance['host']);
        if (strcasecmp((string) parse_url($redirectUri, PHP_URL_HOST), $host) === 0) return null;
        if (isset($instance['port'])) throw new BankConnectorOperationException('app_url_invalid');
        return $host;
    }

    private function instanceCallbackUrl(): string
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
        $this->instanceCallbackUrl();
        $platform = $this->platformCredentials() !== null;
        // S klíči platformy firma nic neregistruje, společnou adresu proto neukazujeme.
        return ['callback_url' => $platform ? null : $this->callbackUrl(), 'platform' => $platform,
            'server_ready' => $this->secrets->validateKey() === null,
            'environment' => $this->api->environment(), 'payment_submission' => $this->paymentSubmission($supplierId, $currencyId)];
    }

    /**
     * Stav odesílání plateb připojeného účtu: null = účet není připojený; jinak
     * {@see CsasCredentialVault::paymentAvailability()}. Nečitelný přístup čtení neblokuje,
     * jen se pro platby hlásí jako souhlas k obnovení.
     */
    private function paymentSubmission(int $supplierId, int $currencyId): ?string
    {
        $stored = $this->oauth->connectedCredential($supplierId, $currencyId);
        if ($stored === null) return null;
        try {
            $data = $this->vault->decode($this->secrets->decryptFor($stored['token_ciphertext'],
                CsasCredentialVault::context($supplierId, $stored['id'])));
        } catch (\Throwable) {
            return 'authorization_scope_missing';
        }
        return CsasCredentialVault::paymentAvailability($data);
    }

    public function start(int $supplierId, int $currencyId, int $userId, #[\SensitiveParameter] array $input): array
    {
        $this->account($supplierId, $currencyId);
        $platform = $this->platformCredentials();
        $allowed = $platform === null ? ['api_key', 'client_id', 'client_secret', 'payments'] : ['payments'];
        if ($userId < 1 || array_diff(array_keys($input), $allowed)
            || (array_key_exists('payments', $input) && !is_bool($input['payments']))) {
            throw new BankConnectorOperationException('credential_invalid');
        }
        // Odesílání plateb je vědomá volba; bez ní souhlas žádá jen čtení účtů.
        $payments = $input['payments'] ?? false;
        unset($input['payments']);
        if ($this->secrets->validateKey() !== null) throw new BankConnectorOperationException('encryption_key_unavailable');
        $credentials = $platform ?? [];
        if ($platform === null) {
            foreach (['api_key', 'client_id', 'client_secret'] as $key) {
                $value = $input[$key] ?? null;
                if (!self::validCredential($value)) throw new BankConnectorOperationException('credential_invalid');
                $credentials[$key] = $value;
            }
            $credentials['redirect_uri'] = $this->instanceCallbackUrl();
        }
        $credentials['environment'] = $this->api->environment();
        $stateHost = $this->stateHost($credentials['redirect_uri']);
        return $this->calls->withConnectionLock($supplierId, $currencyId, function () use ($supplierId, $currencyId, $userId, $credentials, $payments, $stateHost): array {
            $authorization = $this->flow->authorize();
            $url = $this->api->authorizationUrl($credentials + ['code_verifier' => (string) $authorization->codeVerifier],
                $authorization->state, $payments, $stateHost);
            $credentials['payments_requested'] = $payments;
            // Platí jen poslední zahájené přihlášení, i to rozpracované v původní tabulce.
            $this->oauth->cancelPending($supplierId, $currencyId);
            $this->flow->store($authorization, $supplierId, $userId, 'bank', self::PROVIDER, $currencyId,
                OAuthClientFlow::FLOW_REDIRECT, $credentials, self::OAUTH_TTL);
            return ['redirect_url' => $url];
        });
    }

    /** Návrat na společný callback (nebo jeho alias) pro přihlášení zahájené přes {@see start()}. */
    public function completeCallback(OAuthCallback $callback): int
    {
        $supplierId = $callback->supplierId();
        $currencyId = (int) $callback->session['subject_id'];
        return $this->calls->withConnectionLock($supplierId, $currencyId, function () use ($callback, $supplierId, $currencyId): int {
            $session = $this->flow->claim($callback->state, $supplierId, $callback->userId(), 'bank', self::PROVIDER);
            if ($session === null || $session->subjectId !== $currencyId) throw new BankConnectorOperationException('csas_oauth_expired');
            return $this->connect($supplierId, $currencyId, $session->secret + ['code_verifier' => (string) $session->codeVerifier],
                $callback->code, fn (bool $success, ?string $error = null) => $this->flow->finish($session, $success, $error));
        });
    }

    /**
     * Přihlášení zahájené před přechodem na společnou vrstvu (bank_oauth_sessions).
     * Platí, dokud nevyprší rozpracovaná přihlášení z doby před nasazením.
     */
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
                $credentials = json_decode($this->secrets->decryptFor($session['secret_ciphertext'], $this->sessionContext($supplierId, $hash)), true, 8, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $this->oauth->finish($hash, false);
                throw new BankConnectorOperationException('csas_oauth_failed');
            }
            return $this->connect($supplierId, $currencyId, $credentials, $code, fn (bool $success) => $this->oauth->finish($hash, $success));
        });
    }

    /**
     * Vymění kód za tokeny, ověří, že souhlas pokrývá právě připojovaný účet, a uloží přístup.
     * Volá se pod zámkem napojení; `$finish` ukončí rozpracované přihlášení.
     *
     * @param array<string,mixed>  $credentials údaje z rozpracovaného přihlášení včetně code_verifier
     * @param callable(bool, ?string):void $finish
     */
    private function connect(int $supplierId, int $currencyId, #[\SensitiveParameter] array $credentials,
        #[\SensitiveParameter] string $code, callable $finish): int
    {
        try {
            $account = $this->account($supplierId, $currencyId);
            if (($credentials['redirect_uri'] ?? null) !== $this->callbackUrl()) throw new BankConnectorOperationException('app_url_invalid');
            $previous = $this->previousCredential($supplierId, $currencyId);
            $tokens = $this->api->exchange($credentials, $code);
            $paymentsRequested = ($credentials['payments_requested'] ?? false) === true;
            unset($credentials['code_verifier'], $credentials['payments_requested']);
            $credentials['access_token'] = $tokens['access_token'];
            $remoteAccounts = $this->api->accounts($credentials);
            $matches = array_values(array_filter($remoteAccounts, static fn (array $remote): bool =>
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
                'account_currency' => $remote['currency'], 'call_guard_key' => bin2hex(random_bytes(32)),
                'scope' => self::grantedScope($tokens['scope'] ?? '', $paymentsRequested),
                'payment_account_suitable' => self::suitableForPayments($remote)];
            $ciphertext = $this->secrets->encryptFor($this->vault->encode($credentials), CsasCredentialVault::context($supplierId, $connectionId));
            if (!str_starts_with($ciphertext, 'enc:v2:')) throw new BankConnectorOperationException('encryption_failed');
            $this->connections->saveValidated($supplierId, $connectionId, 'csas', $ciphertext, true,
                $remote['identification']['iban'], '0800', $remote['currency']);
            $finish(true);
        } catch (\Throwable $e) {
            $errorCode = $e instanceof BankConnectorOperationException || $e instanceof BankConnectorException ? $e->errorCode : 'csas_oauth_failed';
            $finish(false, $errorCode);
            throw new BankConnectorOperationException($errorCode);
        }
        // Další účty firmy z téhož souhlasu. Připojení účtu už je uložené; chyba propojení
        // dalších účtů ho nezruší, účty jde propojit znovu dalším přihlášením.
        try {
            $this->siblings->link($currencyId, $credentials, $remoteAccounts, $previous);
        } catch (\Throwable) {
        }
        // Účty ostatních firem, které souhlas pokrývá a které nemají vlastní napojení ({@see CsasSharedConsent}).
        try {
            $this->siblings->linkShared($credentials, $remoteAccounts, $previous);
        } catch (\Throwable) {
        }
        return $currencyId;
    }

    /** Přístup, kterým byl účet připojený před novým přihlášením; jeho další účty se převezmou na nový souhlas. */
    private function previousCredential(int $supplierId, int $currencyId): ?array
    {
        try {
            $stored = $this->oauth->connectedCredential($supplierId, $currencyId);
            if ($stored === null) return null;
            $data = $this->vault->decode($this->secrets->decryptFor($stored['token_ciphertext'],
                CsasCredentialVault::context($supplierId, $stored['id'])));
            return $data['supplier_id'] === $supplierId && $data['connection_id'] === $stored['id'] ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Udělený scope, jak ho vrátila banka. Bez vyžádaných plateb se platební oprávnění
     * neukládá, ani když ho banka vydá (IdP může vracet všechny scope registrované u aplikace).
     * Když banka scope nevrátí, platby zůstanou nedostupné.
     */
    private static function grantedScope(string $scope, bool $paymentsRequested): string
    {
        $parts = preg_split('/\s+/', trim($scope)) ?: [];
        if (!$paymentsRequested) $parts = array_diff($parts, ['siblings.payments']);
        return implode(' ', array_values(array_unique(array_filter($parts, static fn (string $part): bool => $part !== ''))));
    }

    /**
     * Accounts v3 vrací u účtu suitableScope; klíč PISP znamená, že účet smí platit přes
     * Premium Payments. Bez suitableScope se účet za vhodný nepovažuje.
     */
    public static function suitableForPayments(array $remote): bool
    {
        $scope = $remote['suitableScope'] ?? null;
        return is_array($scope) && array_key_exists('PISP', $scope);
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
