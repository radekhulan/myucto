<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Repository\CsasOAuthRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\{CsasApiClient, CsasBankConnector, CsasCredentialVault, CsasTransactionParser, BankConnectorException, BankConnectorRegistry, BankConnectorOperationException};
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class CsasConnectorTest extends TestCase
{
    private function credentials(): array
    {
        return ['version' => 1, 'supplier_id' => 7, 'connection_id' => 9, 'api_key' => 'synthetic-key',
            'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret', 'redirect_uri' => 'https://example.invalid/callback',
            'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'access_expires_at' => time() + 300,
            'account_id' => 'old-id', 'account_iban' => 'CZ7508000000001000000005', 'account_currency' => 'CZK', 'call_guard_key' => str_repeat('a', 64)];
    }

    public function testRotationMustBePersistedBeforeUse(): void
    {
        $api = $this->createMock(CsasApiClient::class);
        $repo = $this->createMock(CsasOAuthRepository::class);
        $secret = $this->createMock(SecretEncryption::class);
        $vault = new CsasCredentialVault($api, $repo, $secret);
        $data = $this->credentials();
        $data['access_expires_at'] = time() - 1;
        $api->expects(self::once())->method('refresh')->willReturn(['access_token' => 'rotated-synthetic', 'refresh_token' => 'rotated-refresh', 'expires_in' => 300]);
        $secret->expects(self::once())->method('encryptFor')->with(self::callback(static fn (string $raw): bool => str_contains($raw, 'rotated-refresh')),
            CsasCredentialVault::context(7, 9))->willReturn('enc:v2:synthetic');
        $repo->expects(self::once())->method('rotate')->with(7, 9, 'enc:v2:synthetic')->willReturn(false);
        $this->expectException(BankConnectorException::class);
        $vault->access($vault->encode($data));
    }

    public function testAccountIdMayChangeButIbanAndCurrencyCannot(): void
    {
        $api = $this->createMock(CsasApiClient::class);
        $vault = $this->createMock(CsasCredentialVault::class);
        $vault->method('access')->willReturn($this->credentials());
        $api->method('accounts')->willReturn([['id' => 'new-id', 'identification' => ['iban' => 'CZ7508000000001000000005'], 'currency' => 'CZK']]);
        $api->expects(self::once())->method('transactions')->with(self::callback(static fn (array $data): bool => $data['account_id'] === 'new-id'), '2026-01-01', '2026-01-31')->willReturn([]);
        $connector = new CsasBankConnector($api, $vault, new CsasTransactionParser());
        self::assertSame([], $connector->parseStatement($connector->downloadStatement('synthetic', '2026-01-01', '2026-01-31'))['transactions']);
        $catalog = (new BankConnectorRegistry([$connector]))->catalog();
        $entry = array_values(array_filter($catalog, static fn (array $row): bool => $row['code'] === 'csas'))[0];
        self::assertTrue($entry['implemented']);
        self::assertFalse($entry['capabilities']['payment_order_submission']);
    }

    public function testChangedAccountFailsBeforeTransactions(): void
    {
        $api = $this->createMock(CsasApiClient::class);
        $vault = $this->createMock(CsasCredentialVault::class);
        $vault->method('access')->willReturn($this->credentials());
        $api->method('accounts')->willReturn([['id' => 'old-id', 'identification' => ['iban' => 'CZ7508000000001000000005'], 'currency' => 'EUR']]);
        $api->expects(self::never())->method('transactions');
        $this->expectException(BankConnectorException::class);
        (new CsasBankConnector($api, $vault, new CsasTransactionParser()))->downloadStatement('synthetic', '2026-01-01', '2026-01-31');
    }

    public function testCredentialContextCannotMoveAcrossTenants(): void
    {
        $api = $this->createMock(CsasApiClient::class);
        $vault = $this->createMock(CsasCredentialVault::class);
        $vault->method('decode')->willReturn($this->credentials());
        $this->expectException(BankConnectorOperationException::class);
        (new CsasBankConnector($api, $vault, new CsasTransactionParser()))->credentials([], ['supplier_id' => 8, 'id' => 9], 'synthetic');
    }

    public function testPaymentSubmissionCannotMakeNetworkCalls(): void
    {
        $api = $this->createMock(CsasApiClient::class);
        $vault = $this->createMock(CsasCredentialVault::class);
        $vault->expects(self::never())->method('access');
        $this->expectException(BankConnectorException::class);
        (new CsasBankConnector($api, $vault, new CsasTransactionParser()))->submitPaymentOrder('synthetic', 'synthetic');
    }
}
