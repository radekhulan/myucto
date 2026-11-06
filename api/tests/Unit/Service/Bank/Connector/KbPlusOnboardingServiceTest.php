<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\KbPlusOAuthRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\BankConnectorCallGuard;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\KbPlusApiClient;
use MyInvoice\Service\Bank\Connector\KbPlusCredentialVault;
use MyInvoice\Service\Bank\Connector\KbPlusOnboardingService;
use MyInvoice\Service\Bank\Connector\KbPlusRegistrationClient;
use MyInvoice\Service\Bank\Connector\KbPlusRegistrationService;
use MyInvoice\Service\OAuth\OAuthAuthorization;
use MyInvoice\Service\OAuth\OAuthCallback;
use MyInvoice\Service\OAuth\OAuthClientFlow;
use MyInvoice\Service\OAuth\OAuthSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class KbPlusOnboardingServiceTest extends TestCase
{
    private KbPlusOAuthRepository&MockObject $oauth;
    private BankConnectionRepository&MockObject $connections;
    private KbPlusRegistrationClient&MockObject $registrationClient;
    private KbPlusRegistrationService&MockObject $registration;
    private KbPlusApiClient&MockObject $api;
    private BankConnectorCallGuard&MockObject $calls;
    private SecretEncryption&MockObject $secrets;
    private Config&MockObject $config;
    private KbPlusCredentialVault&MockObject $vault;
    private OAuthClientFlow&MockObject $flow;
    private KbPlusOnboardingService $service;

    protected function setUp(): void
    {
        $this->oauth = $this->createMock(KbPlusOAuthRepository::class);
        $this->connections = $this->createMock(BankConnectionRepository::class);
        $this->registrationClient = $this->createMock(KbPlusRegistrationClient::class);
        $this->registration = $this->createMock(KbPlusRegistrationService::class);
        $this->api = $this->createMock(KbPlusApiClient::class);
        $this->calls = $this->createMock(BankConnectorCallGuard::class);
        $this->secrets = $this->createMock(SecretEncryption::class);
        $this->config = $this->createMock(Config::class);
        $this->vault = $this->createMock(KbPlusCredentialVault::class);
        $this->flow = $this->createMock(OAuthClientFlow::class);
        $this->flow->method('authorize')->willReturnCallback(static fn (): OAuthAuthorization =>
            new OAuthAuthorization(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), null));

        $this->config->method('get')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => match ($key) {
            'app.url' => 'https://example.invalid',
            'smtp.from_email' => 'synthetic@example.invalid',
            'bank.kb_plus.callback_query_logging_safe' => false,
            default => $default,
        });
        $this->secrets->method('validateKey')->willReturn(null);
        $this->calls->method('withConnectionLock')->willReturnCallback(
            static fn (int $supplierId, int $currencyId, callable $callback): mixed => $callback(),
        );
        $this->calls->method('call')->willReturnCallback(
            static fn (string $credential, callable $callback): mixed => $callback(),
        );

        $this->service = new KbPlusOnboardingService(
            $this->oauth,
            $this->connections,
            $this->registrationClient,
            $this->registration,
            $this->api,
            $this->vault,
            $this->calls,
            $this->secrets,
            $this->config,
            $this->flow,
        );
    }

    public function testFailedOnboardingCanBeStartedAgain(): void
    {
        $this->oauth->method('account')->willReturn($this->account());
        $this->connections->method('findPublicByCurrency')->willReturn(null);
        $this->oauth->method('client')->willReturn(null);
        $this->oauth->method('publicStatus')->willReturn(['stage' => 'registration', 'status' => 'failed']);

        $status = $this->service->status(7, 11);

        self::assertSame('not_registered', $status['status']);
        self::assertTrue($status['server_ready']);
        self::assertContains('certificate_p12', $status['required_fields']);
    }

    public function testStartsRegistrationAndPersistsOnlyEncryptedSingleUseState(): void
    {
        $this->oauth->expects(self::once())->method('account')->with(7, 11)->willReturn($this->account());
        $this->oauth->expects(self::once())->method('client')->with(7)->willReturn(null);
        $registrationState = null;
        $this->registrationClient->expects(self::once())->method('createSoftwareStatement')
            ->with(self::anything(), self::callback(static function (array $metadata) use (&$registrationState): bool {
                parse_str((string) parse_url($metadata['registrationBackUri'], PHP_URL_QUERY), $query);
                $registrationState = $query['state'] ?? null;
                return ($query['supplier_id'] ?? null) === '7'
                    && is_string($registrationState)
                    && preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $registrationState) === 1;
            }))
            ->willReturn('eyJhbGciOiJIUzI1NiJ9.e30.c2lnbmF0dXJl');
        $this->registration->expects(self::once())->method('begin')
            ->willReturnCallback(static fn (string $statement, array $application, string $state): array => [
                'url' => 'https://api-gateway.kb.cz/client-registration-ui/v2/saml/register?registrationRequest=synthetic&state=' . $state,
                'state' => $state,
                'encryption_key' => base64_encode(str_repeat('K', 32)),
            ]);
        // Rozpracovanou registraci šifruje společná vrstva; certifikát do ní nepatří.
        $this->oauth->expects(self::once())->method('cancelPending')->with(7, 11);
        $this->flow->expects(self::once())->method('store')
            ->with(self::callback(static fn (OAuthAuthorization $auth): bool => $auth->codeVerifier === null),
                7, 5, 'bank', 'kb_plus', 11, OAuthClientFlow::FLOW_REDIRECT,
                self::callback(static fn (array $secret): bool => $secret['oauth_api_key'] === 'synthetic-oauth-key'
                    && !str_contains((string) json_encode($secret), 'synthetic-certificate')), 1800, 'registration');

        $result = $this->service->start(7, 11, 5, $this->registrationInput());

        self::assertSame('registration_pending', $result['status']);
        parse_str((string) parse_url($result['redirect_url'], PHP_URL_QUERY), $redirectQuery);
        self::assertSame($registrationState, $redirectQuery['state']);
        self::assertStringStartsWith('https://api-gateway.kb.cz/client-registration-ui/v2/saml/register?', $result['redirect_url']);
        self::assertArrayNotHasKey('state', $result);
        self::assertArrayNotHasKey('credentials', $result);
    }

    public function testMissingStateCanRecoverOnlyAnAuthenticatedRegistrationResponse(): void
    {
        $state = str_repeat('r', 43);
        $session = ['stage' => 'registration'] + $this->session($state);
        $this->oauth->expects(self::exactly(3))->method('pendingRegistrations')->with(7, 5)
            ->willReturnOnConsecutiveCalls([$session], [$session], []);
        $this->oauth->expects(self::once())->method('currencyForState')->with(hash('sha256', $state), 7, 5)->willReturn(11);
        $this->oauth->expects(self::once())->method('claim')->with(hash('sha256', $state), 7, 5, 'registration')->willReturn($session);
        $this->secrets->method('decryptFor')->willReturn(json_encode([
            'state' => $state, 'encryption_key' => base64_encode(str_repeat('K', 32)),
            'oauth_api_key' => 'synthetic-oauth-key', 'adaa_api_key' => 'synthetic-adaa-key',
            'batchda_api_key' => '', 'redirect_uri' => 'https://example.invalid/callback',
        ], JSON_THROW_ON_ERROR));
        $this->secrets->method('encryptFor')->willReturn('enc:v2:synthetic');
        $this->api->method('authorizationUrl')->willReturn('https://login.kb.cz/autfe/ssologin?state=synthetic');
        $this->oauth->expects(self::once())->method('finish')->with(hash('sha256', $state), true);
        $service = new KbPlusOnboardingService(
            $this->oauth, $this->connections, $this->registrationClient, new KbPlusRegistrationService(),
            $this->api, $this->vault, $this->calls, $this->secrets, $this->config, $this->flow,
        );
        $payload = [
            'application_type' => 'web', 'redirect_uris' => ['https://example.invalid/callback'],
            'scope' => 'adaa', 'response_types' => ['code'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_method' => 'client_secret_post',
            'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-client-secret',
            'client_id_issued_at' => 1788750000,
        ];
        $salt = str_repeat('I', 12);
        $tag = '';
        $encrypted = openssl_encrypt(json_encode($payload, JSON_THROW_ON_ERROR), 'aes-256-gcm', str_repeat('K', 32), OPENSSL_RAW_DATA, $salt, $tag);
        self::assertIsString($encrypted);
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $callback = ['salt' => $encode($salt), 'encryptedData' => $encode($encrypted . $tag)];
        try {
            $service->completeRegistration(7, 5, '', ['salt' => $encode(str_repeat('J', 12))] + $callback);
            self::fail('Tampered response must not claim the pending session.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('kb_plus_onboarding_used_or_expired', $e->errorCode);
        }
        self::assertSame('authorization_pending', $service->completeRegistration(7, 5, '', $callback)['status']);
        $this->expectException(BankConnectorOperationException::class);
        $service->completeRegistration(7, 5, '', $callback);
    }

    public function testExplicitInvalidStateDoesNotUseRegistrationRecovery(): void
    {
        $this->oauth->expects(self::never())->method('pendingRegistrations');
        $this->oauth->expects(self::never())->method('claim');
        $this->expectException(BankConnectorOperationException::class);
        $this->service->completeRegistration(7, 5, '', ['state' => '', 'salt' => 'synthetic', 'encryptedData' => 'synthetic']);
    }

    public function testServerPrerequisiteFailureDoesNotCreateSessionOrCallBank(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('get')->willReturnCallback(static fn (string $key, mixed $default = null): mixed =>
            $key === 'app.url' ? 'http://unsafe.invalid' : $default
        );
        $service = new KbPlusOnboardingService(
            $this->oauth, $this->connections, $this->registrationClient, $this->registration,
            $this->api, $this->vault, $this->calls, $this->secrets, $this->config, $this->flow,
        );
        $this->registrationClient->expects(self::never())->method('createSoftwareStatement');
        $this->flow->expects(self::never())->method('store');

        try {
            $service->start(7, 11, 5, $this->registrationInput());
            self::fail('Neplatná canonical URL musí onboarding zablokovat před bankou.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('app_url_invalid', $e->errorCode);
        }
    }

    public function testOAuthAccountMismatchTerminatesAttemptWithoutSavingConnection(): void
    {
        $state = str_repeat('s', 43);
        $this->oauth->method('currencyForState')->willReturn(11);
        $this->oauth->method('claim')->willReturn($this->session($state));
        $this->secrets->method('decryptFor')->willReturn(json_encode($this->oauthSecret(), JSON_THROW_ON_ERROR));
        $this->api->method('exchangeAuthorizationCode')->willReturn($this->tokens());
        $this->api->method('accounts')->willReturn([[
            'accountId' => 'different-account',
            'currency' => 'CZK',
            'iban' => 'CZ6501000000001000000005',
        ]]);
        $this->oauth->method('account')->willReturn($this->account());
        $this->connections->expects(self::never())->method('ensure');
        $this->connections->expects(self::never())->method('saveValidated');
        $this->oauth->expects(self::once())->method('finish')
            ->with(hash('sha256', $state), false, 'kb_plus_account_not_found');

        try {
            $this->service->completeOAuth(7, 5, $state, 'synthetic_authorization_code_001');
            self::fail('Cizí účet nesmí vytvořit bankovní spojení.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('kb_plus_account_not_found', $e->errorCode);
        }
    }

    public function testOAuthRejectsMatchingIbanWhenConfiguredNationalAccountConflicts(): void
    {
        $state = str_repeat('c', 43);
        $remoteIban = 'CZ0401000000191000000005';
        $this->oauth->method('currencyForState')->willReturn(11);
        $this->oauth->method('claim')->willReturn($this->session($state));
        $this->secrets->method('decryptFor')->willReturn(json_encode($this->oauthSecret(), JSON_THROW_ON_ERROR));
        $this->api->method('exchangeAuthorizationCode')->willReturn($this->tokens());
        $this->api->method('accounts')->willReturn([[
            'accountId' => 'synthetic-account-id',
            'currency' => 'CZK',
            'iban' => $remoteIban,
        ]]);
        $this->oauth->method('account')->willReturn(array_replace($this->account(), [
            'iban' => $remoteIban,
            'account_number' => '1000000005',
        ]));
        $this->connections->expects(self::never())->method('ensure');
        $this->connections->expects(self::never())->method('saveValidated');
        $this->oauth->expects(self::once())->method('finish')
            ->with(hash('sha256', $state), false, 'kb_plus_account_not_found');

        try {
            $this->service->completeOAuth(7, 5, $state, 'synthetic_authorization_code_001');
            self::fail('Shodný IBAN nesmí skrýt konflikt uloženého národního čísla účtu.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('kb_plus_account_not_found', $e->errorCode);
        }
    }

    public function testBusyConnectionDoesNotConsumeSingleUseOAuthState(): void
    {
        $state = str_repeat('b', 43);
        $this->oauth->method('currencyForState')->willReturn(11);
        $this->calls = $this->createMock(BankConnectorCallGuard::class);
        $this->calls->method('withConnectionLock')->willThrowException(
            new BankConnectorOperationException('bank_connection_busy'),
        );
        $this->oauth->expects(self::never())->method('claim');
        $service = new KbPlusOnboardingService(
            $this->oauth, $this->connections, $this->registrationClient, $this->registration,
            $this->api, $this->vault, $this->calls, $this->secrets, $this->config, $this->flow,
        );

        try {
            $service->completeOAuth(7, 5, $state, 'synthetic_authorization_code_001');
            self::fail('Lokální lock nesmí spotřebovat jednorázový OAuth state.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('bank_connection_busy', $e->errorCode);
        }
    }

    public function testOAuthSuccessStoresContextBoundCredentialAndCompletesSession(): void
    {
        $state = str_repeat('t', 43);
        $this->oauth->method('currencyForState')->willReturn(11);
        $this->oauth->method('claim')->willReturn($this->session($state));
        $this->secrets->method('decryptFor')->willReturn(json_encode($this->oauthSecret(), JSON_THROW_ON_ERROR));
        $this->api->method('exchangeAuthorizationCode')->willReturn($this->tokens());
        $this->api->method('accounts')->willReturn([[
            'accountId' => 'synthetic-account-id',
            'currency' => 'CZK',
            'iban' => 'CZ0401000000191000000005',
        ]]);
        $this->oauth->method('account')->willReturn($this->account());
        $this->connections->expects(self::once())->method('ensure')->with(7, 11, 'kb_plus')->willReturn(19);
        $this->vault->expects(self::once())->method('encode')->with(self::callback(static fn (array $value): bool =>
            $value['supplier_id'] === 7 && $value['connection_id'] === 19
            && $value['refresh_token'] === 'synthetic-refresh-token-0001'
            && $value['api_plan'] === 'plus'
            && !array_key_exists('client_registration_api_key', $value)
        ))->willReturn('{"synthetic":"credential"}');
        $this->secrets->expects(self::once())->method('encryptFor')
            ->with('{"synthetic":"credential"}', KbPlusCredentialVault::context(7, 19))
            ->willReturn('enc:v2:connection');
        $this->connections->expects(self::once())->method('saveValidated')
            ->with(7, 19, 'kb_plus', 'enc:v2:connection', true, 'CZ0401000000191000000005', '0100', 'CZK');
        $this->oauth->expects(self::once())->method('finish')->with(hash('sha256', $state), true);

        self::assertSame(11, $this->service->completeOAuth(7, 5, $state, 'synthetic_authorization_code_001'));
    }

    public function testSharedFlowConsentIsCompletedWithoutTouchingLegacySessions(): void
    {
        $state = str_repeat('n', 43);
        $session = new OAuthSession(hash('sha256', $state), 7, 5, 'bank', 'kb_plus', 11, OAuthClientFlow::FLOW_REDIRECT, 'oauth',
            'processing', false, $this->oauthSecret(), null);
        $this->flow->expects(self::once())->method('find')->with($state)->willReturn(['supplier_id' => 7, 'user_id' => 5, 'domain' => 'bank',
            'provider' => 'kb_plus', 'subject_id' => 11]);
        $this->flow->expects(self::once())->method('claim')->with($state, 7, 5, 'bank', 'kb_plus', 'oauth')->willReturn($session);
        $this->oauth->expects(self::never())->method('currencyForState');
        $this->oauth->expects(self::never())->method('claim');
        $this->oauth->expects(self::never())->method('finish');
        $this->api->expects(self::once())->method('exchangeAuthorizationCode')
            ->with(self::callback(static fn (array $secret): bool => $secret['client_id'] === 'synthetic-client'), 'synthetic_authorization_code_001')
            ->willReturn($this->tokens());
        $this->api->method('accounts')->willReturn([['accountId' => 'synthetic-account-id', 'currency' => 'CZK', 'iban' => 'CZ0401000000191000000005']]);
        $this->oauth->method('account')->willReturn($this->account());
        $this->connections->method('ensure')->willReturn(19);
        $this->vault->method('encode')->willReturn('{"synthetic":"credential"}');
        $this->secrets->method('encryptFor')->willReturn('enc:v2:connection');
        $this->flow->expects(self::once())->method('finish')->with($session, true, null);

        self::assertSame(11, $this->service->completeCallback(new OAuthCallback(
            ['supplier_id' => 7, 'user_id' => 5, 'domain' => 'bank', 'provider' => 'kb_plus', 'subject_id' => 11],
            $state, 'synthetic_authorization_code_001', null, [],
            (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/oauth/callback'),
        )));
    }

    public function testStateOfAnotherUserInSharedFlowHasNoAccount(): void
    {
        $state = str_repeat('o', 43);
        $this->flow->method('find')->willReturn(['supplier_id' => 7, 'user_id' => 6, 'domain' => 'bank', 'provider' => 'kb_plus', 'subject_id' => 11]);
        $this->oauth->expects(self::never())->method('currencyForState');

        self::assertNull($this->service->currencyForState(7, 5, $state));
    }

    /** @param list<string> $scopes */
    #[\PHPUnit\Framework\Attributes\DataProvider('batchScopes')]
    public function testRegistrationScopesFollowBatchChoiceOrKey(string $batchKey, ?bool $paymentBatches, array $scopes): void
    {
        $this->oauth->method('account')->willReturn($this->account());
        $this->oauth->method('client')->willReturn(null);
        $this->registrationClient->expects(self::once())->method('createSoftwareStatement')
            ->with(self::callback(static fn (array $credentials): bool => !array_key_exists('payment_batches', $credentials)), self::anything())
            ->willReturn('eyJhbGciOiJIUzI1NiJ9.e30.c2lnbmF0dXJl');
        $this->registration->expects(self::once())->method('begin')
            ->with(self::anything(), self::callback(static fn (array $application): bool => $application['scopes'] === $scopes), self::anything())
            ->willReturnCallback(static fn (string $statement, array $application, string $state): array => [
                'url' => 'https://api-gateway.kb.cz/client-registration-ui/v2/saml/register?state=' . $state,
                'state' => $state,
                'encryption_key' => base64_encode(str_repeat('K', 32)),
            ]);
        $this->flow->expects(self::once())->method('store')
            ->with(self::anything(), 7, 5, 'bank', 'kb_plus', 11, OAuthClientFlow::FLOW_REDIRECT,
                self::callback(static fn (array $secret): bool => $secret['payment_batches'] === ($paymentBatches === true)), 1800, 'registration');
        $input = ['batchda_api_key' => $batchKey] + $this->registrationInput();
        if ($paymentBatches !== null) {
            $input['payment_batches'] = $paymentBatches;
        }

        $result = $this->service->start(7, 11, 5, $input);

        self::assertSame('registration_pending', $result['status']);
    }

    public static function batchScopes(): iterable
    {
        yield 'bez volby a bez klíče jen čtení' => ['  ', null, ['adaa']];
        yield 'dávky nezvolené jen čtení' => ['', false, ['adaa']];
        yield 'dávky zvolené bez klíče BATCHDA' => ['', true, ['adaa', 'bpisp']];
        yield 'samostatný klíč BATCHDA stále zapíná dávky' => ['synthetic-batchda-key', false, ['adaa', 'bpisp']];
    }

    public function testBasicPlanRegistersStatementsOnlyEvenWithBatchChoiceAndKey(): void
    {
        $this->oauth->method('account')->willReturn($this->account());
        $this->oauth->method('client')->willReturn(null);
        $this->registrationClient->expects(self::once())->method('createSoftwareStatement')
            ->with(self::callback(static fn (array $credentials): bool => !array_key_exists('api_plan', $credentials)), self::anything())
            ->willReturn('eyJhbGciOiJIUzI1NiJ9.e30.c2lnbmF0dXJl');
        $this->registration->expects(self::once())->method('begin')
            ->with(self::anything(), self::callback(static fn (array $application): bool => $application['scopes'] === ['statda']), self::anything())
            ->willReturnCallback(static fn (string $statement, array $application, string $state): array => [
                'url' => 'https://api-gateway.kb.cz/client-registration-ui/v2/saml/register?state=' . $state,
                'state' => $state,
                'encryption_key' => base64_encode(str_repeat('K', 32)),
            ]);
        $this->flow->expects(self::once())->method('store')
            ->with(self::anything(), 7, 5, 'bank', 'kb_plus', 11, OAuthClientFlow::FLOW_REDIRECT,
                self::callback(static fn (array $secret): bool => $secret['api_plan'] === 'basic'), 1800, 'registration');

        $result = $this->service->start(7, 11, 5, ['api_plan' => 'basic', 'payment_batches' => true] + $this->registrationInput());

        self::assertSame('registration_pending', $result['status']);
    }

    public function testExistingClientWithBasicPlanRequestsStatementConsentOnly(): void
    {
        $this->oauth->method('account')->willReturn($this->account());
        $this->oauth->method('client')->willReturn(['credentials_ciphertext' => 'enc:v2:client']);
        $this->secrets->method('decryptFor')->willReturn(json_encode(
            ['client_id' => 'synthetic-client', 'scope' => 'adaa statda', 'redirect_uri' => 'https://example.invalid/callback'],
            JSON_THROW_ON_ERROR,
        ));
        $this->registrationClient->expects(self::never())->method('createSoftwareStatement');
        $this->api->expects(self::once())->method('authorizationUrl')
            ->with(self::callback(static fn (array $secret): bool => $secret['scope'] === 'statda' && $secret['api_plan'] === 'basic'), self::anything())
            ->willReturn('https://login.kb.cz/autfe/ssologin?state=synthetic');
        $this->flow->expects(self::once())->method('store')
            ->with(self::anything(), 7, 5, 'bank', 'kb_plus', 11, OAuthClientFlow::FLOW_REDIRECT,
                self::callback(static fn (array $secret): bool => $secret['api_plan'] === 'basic' && $secret['scope'] === 'statda'), 300, 'oauth');

        $result = $this->service->start(7, 11, 5, ['api_plan' => 'basic']);

        self::assertSame('authorization_pending', $result['status']);
    }

    /** Registrace z varianty Plus statda nezná; souhlas by KB odmítla, je třeba registrovat znovu. */
    public function testExistingClientRegisteredWithoutStatdaCannotStartBasicConsent(): void
    {
        $this->oauth->method('account')->willReturn($this->account());
        $this->oauth->method('client')->willReturn(['credentials_ciphertext' => 'enc:v2:client']);
        $this->secrets->method('decryptFor')->willReturn(json_encode(
            ['client_id' => 'synthetic-client', 'scope' => 'adaa bpisp', 'redirect_uri' => 'https://example.invalid/callback'],
            JSON_THROW_ON_ERROR,
        ));
        $this->api->expects(self::never())->method('authorizationUrl');
        $this->flow->expects(self::never())->method('store');

        try {
            $this->service->start(7, 11, 5, ['api_plan' => 'basic']);
            self::fail('Basic bez registrace statda nesmí otevřít souhlas v KB.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('kb_plus_registration_plan_mismatch', $e->errorCode);
        }
    }

    public function testBasicOAuthMatchesStatementAccountAndKeepsLocalCurrency(): void
    {
        $state = str_repeat('u', 43);
        $this->oauth->method('currencyForState')->willReturn(11);
        $this->oauth->method('claim')->willReturn($this->session($state));
        $this->secrets->method('decryptFor')->willReturn(json_encode(
            ['api_plan' => 'basic', 'scope' => 'statda'] + $this->oauthSecret(),
            JSON_THROW_ON_ERROR,
        ));
        $this->api->method('exchangeAuthorizationCode')->willReturn(['scope' => 'statda'] + $this->tokens());
        $this->api->expects(self::never())->method('accounts');
        $this->api->expects(self::once())->method('statementAccounts')->with('synthetic-access-token-0001')->willReturn([[
            'accountId' => 'synthetic-statement-account',
            'iban' => 'CZ0401000000191000000005',
        ]]);
        $this->oauth->method('account')->willReturn($this->account());
        $this->connections->method('ensure')->willReturn(19);
        $this->vault->expects(self::once())->method('encode')->with(self::callback(static fn (array $value): bool =>
            $value['api_plan'] === 'basic'
            && $value['scope'] === 'statda'
            && $value['account_id'] === 'synthetic-statement-account'
            && $value['account_currency'] === 'CZK'
        ))->willReturn('{"synthetic":"credential"}');
        $this->secrets->method('encryptFor')->willReturn('enc:v2:connection');
        $this->connections->expects(self::once())->method('saveValidated')
            ->with(7, 19, 'kb_plus', 'enc:v2:connection', true, 'CZ0401000000191000000005', '0100', 'CZK');

        self::assertSame(11, $this->service->completeOAuth(7, 5, $state, 'synthetic_authorization_code_001'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('basicStatementConsents')]
    public function testConnectedBasicAccountReportsWhetherConsentCoversStatements(string $registered, string $granted, string $expected): void
    {
        $this->oauth->method('account')->willReturn($this->account());
        $this->connections->method('findPublicByCurrency')->willReturn(['provider' => 'kb_plus', 'has_token' => true]);
        $this->connections->method('findWithCredentialByCurrency')->willReturn(['id' => 19, 'token_ciphertext' => 'enc:v2:connection']);
        $this->oauth->method('client')->willReturn(['credentials_ciphertext' => 'enc:v2:client']);
        $this->oauth->method('publicStatus')->willReturn(null);
        $this->secrets->method('decryptFor')->willReturnCallback(static fn (string $stored, string $context): string =>
            $context === KbPlusCredentialVault::context(7, 19)
                ? '{"synthetic":"connection"}'
                : json_encode(['batchda_api_key' => '', 'scope' => $registered], JSON_THROW_ON_ERROR)
        );
        $this->vault->method('decode')->willReturn(['scope' => $granted, 'api_plan' => 'basic']);

        $status = $this->service->status(7, 11);

        self::assertSame($expected, $status['capabilities']['statement_import_status']);
    }

    public static function basicStatementConsents(): iterable
    {
        yield 'registrace z Plus bez statda' => ['adaa bpisp', 'adaa bpisp', 'registration_scope_missing'];
        yield 'registrace se statda, souhlas ještě z Plus' => ['adaa statda', 'adaa', 'authorization_scope_missing'];
        yield 'souhlas k výpisům' => ['statda', 'statda', 'available'];
    }

    public function testUnknownPlanIsRejectedBeforeBank(): void
    {
        $this->oauth->method('account')->willReturn($this->account());
        $this->oauth->method('client')->willReturn(null);
        $this->registrationClient->expects(self::never())->method('createSoftwareStatement');
        $this->flow->expects(self::never())->method('store');

        try {
            $this->service->start(7, 11, 5, ['api_plan' => 'pro'] + $this->registrationInput());
            self::fail('Neznámá varianta API Business se nesmí přijmout.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('kb_plus_registration_input_invalid', $e->errorCode);
        }
    }

    public function testChangePlanRewritesConnectionCredentialWithoutCallingBank(): void
    {
        $this->oauth->method('account')->willReturn($this->account());
        $this->connections->expects(self::exactly(2))->method('findWithCredentialByCurrency')->with(7, 11)
            ->willReturn(['id' => 19, 'provider' => 'kb_plus', 'token_ciphertext' => 'enc:v2:connection']);
        $this->connections->method('findPublicByCurrency')->willReturn(['provider' => 'kb_plus', 'has_token' => true]);
        $this->oauth->method('client')->willReturn(null);
        $this->oauth->method('publicStatus')->willReturn(null);
        $this->secrets->expects(self::exactly(2))->method('decryptFor')->with('enc:v2:connection', KbPlusCredentialVault::context(7, 19))
            ->willReturn('{"synthetic":"connection"}');
        $this->vault->method('decode')->willReturnOnConsecutiveCalls(
            ['scope' => 'adaa bpisp'],
            ['scope' => 'adaa bpisp', 'api_plan' => 'basic'],
        );
        $this->vault->expects(self::once())->method('encode')
            ->with(['scope' => 'adaa bpisp', 'api_plan' => 'basic'])->willReturn('{"synthetic":"basic"}');
        $this->secrets->expects(self::once())->method('encryptFor')
            ->with('{"synthetic":"basic"}', KbPlusCredentialVault::context(7, 19))->willReturn('enc:v2:basic');
        $this->oauth->expects(self::once())->method('replaceConnectionCredential')->with(7, 19, 'enc:v2:basic')->willReturn(true);
        $this->api->expects(self::never())->method(self::anything());

        $status = $this->service->changePlan(7, 11, 'basic');

        self::assertSame('basic', $status['api_plan']);
        self::assertFalse($status['capabilities']['payment_batch_submission']);
        self::assertSame('plan_basic', $status['capabilities']['payment_batch_status']);
    }

    public function testChangePlanRejectsUnknownPlanAndMissingConnection(): void
    {
        $this->oauth->method('account')->willReturn($this->account());
        $this->connections->method('findWithCredentialByCurrency')->willReturn(null);
        $this->oauth->expects(self::never())->method('replaceConnectionCredential');

        foreach ([['pro', 'kb_plus_plan_invalid'], [null, 'kb_plus_plan_invalid'], ['basic', 'kb_plus_not_connected']] as [$plan, $code]) {
            try {
                $this->service->changePlan(7, 11, $plan);
                self::fail('Změna varianty musí selhat: ' . $code);
            } catch (BankConnectorOperationException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    public function testNonBooleanPaymentBatchChoiceIsRejectedBeforeBank(): void
    {
        $this->oauth->method('account')->willReturn($this->account());
        $this->oauth->method('client')->willReturn(null);
        $this->registrationClient->expects(self::never())->method('createSoftwareStatement');
        $this->flow->expects(self::never())->method('store');

        try {
            $this->service->start(7, 11, 5, ['payment_batches' => 'yes'] + $this->registrationInput());
            self::fail('Volba dávek musí být boolean.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('kb_plus_registration_input_invalid', $e->errorCode);
        }
    }

    public function testRegistrationCallbackWithBatchChoiceExpectsBpispWithoutBatchKey(): void
    {
        $state = str_repeat('p', 43);
        $this->oauth->method('currencyForState')->willReturn(11);
        $this->oauth->method('claim')->willReturn(['stage' => 'registration'] + $this->session($state));
        $this->secrets->method('decryptFor')->willReturn(json_encode([
            'state' => $state, 'encryption_key' => base64_encode(str_repeat('K', 32)), 'payment_batches' => true,
            'oauth_api_key' => 'synthetic-oauth-key', 'adaa_api_key' => 'synthetic-adaa-key',
            'batchda_api_key' => '', 'redirect_uri' => 'https://example.invalid/callback',
        ], JSON_THROW_ON_ERROR));
        $this->registration->expects(self::once())->method('complete')
            ->with(self::anything(), $state, 'https://example.invalid/callback', ['adaa', 'bpisp'], self::anything())
            ->willReturn([
                'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-client-secret',
                'scope' => 'adaa bpisp', 'client_id_issued_at' => 1,
            ]);
        $this->secrets->method('encryptFor')->willReturn('enc:v2:synthetic');
        $this->api->expects(self::once())->method('authorizationUrl')
            ->with(self::callback(static fn (array $client): bool => $client['scope'] === 'adaa bpisp'), self::anything())
            ->willReturn('https://login.kb.cz/autfe/ssologin?state=synthetic');

        $result = $this->service->completeRegistration(7, 5, $state, ['salt' => 'synthetic', 'encryptedData' => 'synthetic']);

        self::assertSame('authorization_pending', $result['status']);
    }

    public function testRegistrationCallbackWithoutBatchKeyExpectsReadOnlyScope(): void
    {
        $state = str_repeat('r', 43);
        $this->oauth->method('currencyForState')->willReturn(11);
        $this->oauth->method('claim')->willReturn(['stage' => 'registration'] + $this->session($state));
        $this->secrets->method('decryptFor')->willReturn(json_encode([
            'state' => $state, 'encryption_key' => base64_encode(str_repeat('K', 32)),
            'oauth_api_key' => 'synthetic-oauth-key', 'adaa_api_key' => 'synthetic-adaa-key',
            'batchda_api_key' => '', 'redirect_uri' => 'https://example.invalid/callback',
        ], JSON_THROW_ON_ERROR));
        $this->registration->expects(self::once())->method('complete')
            ->with(self::anything(), $state, 'https://example.invalid/callback', ['adaa'], self::anything())
            ->willReturn([
                'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-client-secret',
                'scope' => 'adaa', 'client_id_issued_at' => 1,
            ]);
        $this->secrets->method('encryptFor')->willReturn('enc:v2:synthetic');
        $this->api->method('authorizationUrl')->willReturn('https://login.kb.cz/autfe/ssologin?state=synthetic');
        $this->oauth->expects(self::once())->method('finish')->with(hash('sha256', $state), true);

        $result = $this->service->completeRegistration(7, 5, $state, ['salt' => 'synthetic', 'encryptedData' => 'synthetic']);

        self::assertSame('authorization_pending', $result['status']);
    }

    public function testReadOnlyRegistrationReportsMissingRegistrationScopeAndAllowsReentry(): void
    {
        $this->oauth->method('account')->willReturn($this->account());
        $this->connections->method('findPublicByCurrency')->willReturn(null);
        $this->oauth->method('client')->willReturn(['credentials_ciphertext' => 'enc:v2:client']);
        $this->oauth->method('publicStatus')->willReturn(null);
        $this->secrets->method('decryptFor')->willReturn(json_encode(['batchda_api_key' => 'synthetic-batchda-key', 'scope' => 'adaa'], JSON_THROW_ON_ERROR));

        $status = $this->service->status(7, 11);

        self::assertSame('registered', $status['status']);
        self::assertSame([], $status['required_fields']);
        self::assertContains('batchda_api_key', $status['registration_fields']);
        self::assertContains('batchda_api_key', $status['optional_fields']);
        self::assertFalse($status['capabilities']['payment_batch_submission']);
        self::assertSame('registration_scope_missing', $status['capabilities']['payment_batch_status']);
    }

    public function testBpispRegistrationWithoutBatchKeyOffersBatchesBeforeConsent(): void
    {
        $this->oauth->method('account')->willReturn($this->account());
        $this->connections->method('findPublicByCurrency')->willReturn(null);
        $this->oauth->method('client')->willReturn(['credentials_ciphertext' => 'enc:v2:client']);
        $this->oauth->method('publicStatus')->willReturn(null);
        $this->secrets->method('decryptFor')->willReturn(json_encode(['batchda_api_key' => '', 'scope' => 'adaa bpisp'], JSON_THROW_ON_ERROR));

        $status = $this->service->status(7, 11);

        self::assertTrue($status['capabilities']['payment_batch_submission']);
        self::assertSame('available', $status['capabilities']['payment_batch_status']);
    }

    /** @param 'available'|'authorization_scope_missing' $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('connectedConsents')]
    public function testConnectedAccountReportsWhetherGrantedConsentIncludesBpisp(string $tokenScope, string $expected): void
    {
        $this->oauth->method('account')->willReturn($this->account());
        $this->connections->method('findPublicByCurrency')->willReturn(['provider' => 'kb_plus', 'has_token' => true]);
        $this->connections->expects(self::once())->method('findWithCredentialByCurrency')->with(7, 11)
            ->willReturn(['id' => 19, 'token_ciphertext' => 'enc:v2:connection']);
        $this->oauth->method('client')->willReturn(['credentials_ciphertext' => 'enc:v2:client']);
        $this->oauth->method('publicStatus')->willReturn(null);
        $this->secrets->method('decryptFor')->willReturnCallback(static fn (string $stored, string $context): string =>
            $context === KbPlusCredentialVault::context(7, 19)
                ? '{"synthetic":"connection"}'
                : json_encode(['batchda_api_key' => '', 'scope' => 'adaa bpisp'], JSON_THROW_ON_ERROR)
        );
        $this->vault->expects(self::once())->method('decode')->with('{"synthetic":"connection"}')
            ->willReturn(['scope' => $tokenScope]);

        $status = $this->service->status(7, 11);

        self::assertSame('connected', $status['status']);
        self::assertSame($expected === 'available', $status['capabilities']['payment_batch_submission']);
        self::assertSame($expected, $status['capabilities']['payment_batch_status']);
        self::assertStringNotContainsString('synthetic', json_encode($status, JSON_THROW_ON_ERROR));
    }

    public static function connectedConsents(): iterable
    {
        yield 'souhlas jen ke čtení čeká na nový autorizační kód' => ['adaa', 'authorization_scope_missing'];
        yield 'souhlas s bpisp umí dávky' => ['adaa bpisp', 'available'];
    }

    /** @return array<string,mixed> */
    private function account(): array
    {
        return [
            'id' => 11, 'supplier_id' => 7, 'code' => 'CZK', 'is_active' => true,
            'account_number' => '19-1000000005', 'bank_code' => '0100', 'iban' => null,
        ];
    }

    /** @return array<string,string> */
    private function registrationInput(): array
    {
        return [
            'client_registration_api_key' => 'synthetic-registration-key',
            'oauth_api_key' => 'synthetic-oauth-key',
            'adaa_api_key' => 'synthetic-adaa-key',
            'batchda_api_key' => 'synthetic-batchda-key',
            'certificate_p12' => 'synthetic-certificate',
            'certificate_password' => '',
        ];
    }

    /** @return array<string,mixed> */
    private function session(string $state): array
    {
        return [
            'state_hash' => hash('sha256', $state), 'supplier_id' => 7, 'currency_id' => 11,
            'user_id' => 5, 'stage' => 'oauth', 'secret_ciphertext' => 'enc:v2:session',
        ];
    }

    /** @return array<string,mixed> */
    private function oauthSecret(): array
    {
        return [
            'state' => str_repeat('x', 43), 'call_guard_key' => str_repeat('g', 43),
            'oauth_api_key' => 'synthetic-oauth-key', 'adaa_api_key' => 'synthetic-adaa-key',
            'batchda_api_key' => 'synthetic-batchda-key', 'client_id' => 'synthetic-client',
            'client_secret' => 'synthetic-client-secret', 'redirect_uri' => 'https://example.invalid/callback',
            'scope' => 'adaa bpisp',
        ];
    }

    /** @return array<string,mixed> */
    private function tokens(): array
    {
        return [
            'access_token' => 'synthetic-access-token-0001', 'token_type' => 'Bearer',
            'expires_in' => 180, 'scope' => 'adaa bpisp', 'refresh_token' => 'synthetic-refresh-token-0001',
        ];
    }
}
