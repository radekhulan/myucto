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
        $this->registrationClient->expects(self::once())->method('createSoftwareStatement')
            ->willReturn('eyJhbGciOiJIUzI1NiJ9.e30.c2lnbmF0dXJl');
        $this->registration->expects(self::once())->method('begin')
            ->willReturnCallback(static fn (string $statement, array $application, string $state): array => [
                'url' => 'https://api-gateway.kb.cz/client-registration-ui/v2/saml/register?registrationRequest=synthetic&state=' . $state,
                'state' => $state,
                'encryption_key' => base64_encode(str_repeat('K', 32)),
            ]);
        $this->secrets->expects(self::once())->method('encryptFor')
            ->with(self::callback(static fn (string $plaintext): bool =>
                str_contains($plaintext, 'synthetic-oauth-key')
                && !str_contains($plaintext, 'synthetic-certificate')
            ), self::stringContains('supplier:7:currency:11:user:5'))
            ->willReturn('enc:v2:synthetic');
        $this->oauth->expects(self::once())->method('replacePending')
            ->with(self::matchesRegularExpression('/^[a-f0-9]{64}$/'), 7, 11, 5, 'registration', 'enc:v2:synthetic', 1800);

        $result = $this->service->start(7, 11, 5, $this->registrationInput());

        self::assertSame('registration_pending', $result['status']);
        self::assertStringStartsWith('https://api-gateway.kb.cz/client-registration-ui/v2/saml/register?', $result['redirect_url']);
        self::assertArrayNotHasKey('state', $result);
        self::assertArrayNotHasKey('credentials', $result);
    }

    public function testServerPrerequisiteFailureDoesNotCreateSessionOrCallBank(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('get')->willReturnCallback(static fn (string $key, mixed $default = null): mixed =>
            $key === 'app.url' ? 'http://unsafe.invalid' : $default
        );
        $service = new KbPlusOnboardingService(
            $this->oauth, $this->connections, $this->registrationClient, $this->registration,
            $this->api, $this->vault, $this->calls, $this->secrets, $this->config,
        );
        $this->registrationClient->expects(self::never())->method('createSoftwareStatement');
        $this->oauth->expects(self::never())->method('replacePending');

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
            $this->api, $this->vault, $this->calls, $this->secrets, $this->config,
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
