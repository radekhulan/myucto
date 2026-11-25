<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\{BankConnectionRepository, CsasOAuthRepository};
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\{BankConnectorCallGuard, CsasApiClient, CsasBankConnector, CsasCredentialVault, CsasSharedConsent,
    CsasSharedConsentSync, CsasSiblingAccountLinker, CsasTransactionParser};
use MyInvoice\Tests\Support\InlineOAuthRotation;
use PHPUnit\Framework\TestCase;

/**
 * Napojení účtů firem instalace přes souhlas jiné firmy z cronu: nosnou se stává firma
 * připojená dřív, banka se volá jen při čekajícím účtu a vypnuté sdílení nic nedělá.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class CsasSharedConsentSyncTest extends TestCase
{
    use InlineOAuthRotation;

    private const EUR_IBAN = 'CZ6708000000002000000018';

    private CsasOAuthRepository&\PHPUnit\Framework\MockObject\MockObject $oauth;
    private CsasApiClient&\PHPUnit\Framework\MockObject\MockObject $api;
    /** @var array<string,array{0:string,1:string}> */
    private array $sealed = [];
    private CsasCredentialVault $vault;

    private function sync(mixed $sharing = null): CsasSharedConsentSync
    {
        $this->oauth = $this->createMock(CsasOAuthRepository::class);
        $this->api = $this->createMock(CsasApiClient::class);
        $this->api->method('environment')->willReturn('production');
        $secrets = $this->createMock(SecretEncryption::class);
        $secrets->method('encryptFor')->willReturnCallback(function (string $plain, string $context): string {
            $key = 'enc:v2:new-' . count($this->sealed);
            $this->sealed[$key] = [$context, $plain];
            return $key;
        });
        $secrets->method('decryptFor')->willReturnCallback(function (string $cipher, string $context): string {
            [$expected, $plain] = $this->sealed[$cipher] ?? throw new \RuntimeException('unknown');
            if ($expected !== $context) throw new \RuntimeException('context');
            return $plain;
        });
        $calls = $this->createMock(BankConnectorCallGuard::class);
        $calls->method('withConnectionLock')->willReturnCallback(static fn (int $s, int $c, callable $fn): mixed => $fn());
        $shared = new CsasSharedConsent(new Config($sharing === null ? [] : ['bank_connectors' => ['csas' => ['shared_consent' => $sharing]]]));
        $this->vault = new CsasCredentialVault($this->api, $this->oauth, $secrets, $this->inlineRotationFlow(), $shared);
        $linker = new CsasSiblingAccountLinker($this->oauth, $this->createMock(BankConnectionRepository::class), $this->vault, $secrets, $calls, $shared);
        return new CsasSharedConsentSync($shared, $this->oauth, $this->vault, $secrets,
            new CsasBankConnector($this->api, $this->vault, new CsasTransactionParser()), $linker);
    }

    private function credential(int $supplierId, int $connectionId, string $refresh): array
    {
        return ['version' => 1, 'supplier_id' => $supplierId, 'connection_id' => $connectionId, 'api_key' => 'synthetic-key',
            'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret', 'redirect_uri' => 'https://example.invalid/callback',
            'access_token' => 'access-' . $refresh, 'refresh_token' => $refresh, 'access_expires_at' => time() + 300, 'account_id' => 'remote-' . $connectionId,
            'account_iban' => 'CZ7508000000001000000005', 'account_currency' => 'CZK', 'call_guard_key' => str_repeat('a', 64),
            'environment' => 'production', 'scope' => 'siblings.accounts'];
    }

    private function stored(int $supplierId, int $connectionId, int $currencyId, string $refresh): array
    {
        $key = 'enc:v2:' . $supplierId . '-' . $connectionId;
        $this->sealed[$key] = [CsasCredentialVault::context($supplierId, $connectionId), $this->vault->encode($this->credential($supplierId, $connectionId, $refresh))];
        return ['id' => $connectionId, 'supplier_id' => $supplierId, 'currency_id' => $currencyId, 'token_ciphertext' => $key];
    }

    private static function pendingAccount(): array
    {
        return ['supplier_id' => 12, 'company_name' => 'Dceřiná s.r.o.', 'currency_id' => 40, 'code' => 'EUR', 'account_number' => '2000000018',
            'connection_id' => null, 'provider' => null, 'token_ciphertext' => null, 'disconnected' => false];
    }

    public function testEarlierConnectedConsentBecomesTheCarrierAndDryRunReportsEachAccountOnce(): void
    {
        $sync = $this->sync();
        // Firma 8 se připojila dřív (napojení 5) než firma 7 (napojení 9); pořadí řádků to nesmí změnit.
        $this->oauth->method('allConnectedCredentials')->willReturn([
            $this->stored(7, 9, 3, 'refresh-7'),
            $this->stored(8, 5, 4, 'refresh-8'),
        ]);
        $this->oauth->method('otherSuppliersAccounts')->willReturn([self::pendingAccount()]);
        $this->api->expects(self::exactly(2))->method('accounts')->willReturn([
            ['id' => 'remote-eur', 'identification' => ['iban' => self::EUR_IBAN], 'currency' => 'EUR', 'ownersNames' => ['DCEŘINÁ, s.r.o.']],
        ]);

        $results = $sync->linkPending(false, true);

        self::assertSame([['owner_supplier_id' => 8, 'supplier_id' => 12, 'currency_id' => 40, 'status' => 'linked']], $results);
    }

    public function testNothingPendingMeansNoBankCallAndDisabledSharingDoesNothing(): void
    {
        $sync = $this->sync();
        $this->oauth->method('allConnectedCredentials')->willReturn([$this->stored(7, 9, 3, 'refresh-7')]);
        $this->oauth->method('otherSuppliersAccounts')->willReturn([['disconnected' => true] + self::pendingAccount()]);
        $this->api->expects(self::never())->method('accounts');
        self::assertSame([], $sync->linkPending());

        $sync = $this->sync(false);
        $this->oauth->expects(self::never())->method('allConnectedCredentials');
        self::assertNull($sync->linkPending());
    }
}
