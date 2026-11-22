<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Repository\{BankConnectionRepository, CsasOAuthRepository};
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\{BankConnectorCallGuard, CsasApiClient, CsasCredentialVault, CsasSiblingAccountLinker};
use MyInvoice\Tests\Support\InlineOAuthRotation;
use PHPUnit\Framework\TestCase;

/**
 * Další účty firmy z téhož souhlasu ČS: propojí se jen účty, které banka v souhlasu vrací
 * se stejným číslem i měnou a které nemají vlastní napojení. Každá kopie přístupu nese
 * vlastní napojení, účet a klíč hlídání volání, tokeny sdílí se souhlasem.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class CsasSiblingAccountLinkerTest extends TestCase
{
    use InlineOAuthRotation;

    private const PRIMARY_IBAN = 'CZ7508000000001000000005';
    private const EUR_IBAN = 'CZ6708000000002000000018';
    private const SECOND_CZK_IBAN = 'CZ1208000000003000000004';

    private CsasOAuthRepository&\PHPUnit\Framework\MockObject\MockObject $oauth;
    private BankConnectionRepository&\PHPUnit\Framework\MockObject\MockObject $connections;
    private CsasCredentialVault $vault;
    /** @var array<string,string> šifrování atrapou: ciphertext => [kontext, otevřený text] */
    private array $sealed = [];

    protected function setUp(): void
    {
        $this->oauth = $this->createMock(CsasOAuthRepository::class);
        $this->connections = $this->createMock(BankConnectionRepository::class);
        $api = $this->createMock(CsasApiClient::class);
        $api->method('environment')->willReturn('production');
        $secrets = $this->createMock(SecretEncryption::class);
        $secrets->method('encryptFor')->willReturnCallback(function (string $plain, string $context): string {
            $key = 'enc:v2:' . count($this->sealed);
            $this->sealed[$key] = [$context, $plain];
            return $key;
        });
        $secrets->method('decryptFor')->willReturnCallback(function (string $cipher, string $context): string {
            [$expected, $plain] = $this->sealed[$cipher] ?? throw new \RuntimeException('unknown');
            if ($expected !== $context) throw new \RuntimeException('context');
            return $plain;
        });
        $this->vault = new CsasCredentialVault($api, $this->oauth, $secrets, $this->inlineRotationFlow());
        $calls = $this->createMock(BankConnectorCallGuard::class);
        $calls->method('withConnectionLock')->willReturnCallback(static fn (int $s, int $c, callable $fn): mixed => $fn());
        $this->linker = new CsasSiblingAccountLinker($this->oauth, $this->connections, $this->vault, $secrets, $calls);
    }

    private CsasSiblingAccountLinker $linker;

    private function primary(string $refresh = 'synthetic-refresh'): array
    {
        return ['version' => 1, 'supplier_id' => 7, 'connection_id' => 9, 'api_key' => 'synthetic-key', 'client_id' => 'synthetic-client',
            'client_secret' => 'synthetic-secret', 'redirect_uri' => 'https://example.invalid/callback', 'access_token' => 'synthetic-access',
            'refresh_token' => $refresh, 'access_expires_at' => time() + 300, 'account_id' => 'remote-czk', 'account_iban' => self::PRIMARY_IBAN,
            'account_currency' => 'CZK', 'call_guard_key' => str_repeat('a', 64), 'environment' => 'production',
            'scope' => 'siblings.accounts siblings.payments', 'payment_account_suitable' => true];
    }

    private static function remote(): array
    {
        return [
            ['id' => 'remote-czk', 'identification' => ['iban' => self::PRIMARY_IBAN], 'currency' => 'CZK', 'suitableScope' => ['AISP' => '', 'PISP' => '']],
            ['id' => 'remote-eur', 'identification' => ['iban' => self::EUR_IBAN], 'currency' => 'EUR', 'suitableScope' => ['AISP' => '']],
            ['id' => 'remote-czk-2', 'identification' => ['iban' => self::SECOND_CZK_IBAN], 'currency' => 'CZK', 'suitableScope' => ['AISP' => '', 'PISP' => '']],
        ];
    }

    private static function local(int $currencyId, string $code, string $number, ?int $connectionId = null, ?string $cipher = null,
        bool $disconnected = false): array
    {
        return ['currency_id' => $currencyId, 'code' => $code, 'account_number' => $number, 'connection_id' => $connectionId,
            'provider' => $connectionId === null ? null : 'csas', 'token_ciphertext' => $cipher, 'disconnected' => $disconnected];
    }

    /** Uložený přístup napojení, jak by ho uložila aplikace. */
    private function storedCredential(int $connectionId, string $refresh, string $iban, string $currency): string
    {
        $data = ['connection_id' => $connectionId, 'refresh_token' => $refresh, 'account_iban' => $iban, 'account_currency' => $currency] + $this->primary();
        $key = 'enc:v2:stored-' . $connectionId;
        $this->sealed[$key] = [CsasCredentialVault::context(7, $connectionId), $this->vault->encode($data)];
        return $key;
    }

    public function testLinksEveryUnconnectedAccountOfTheConsentWithItsOwnIdentity(): void
    {
        $this->oauth->expects(self::once())->method('siblingAccounts')->with(7, 3)->willReturn([
            self::local(4, 'EUR', '2000000018/0800'),
            self::local(5, 'CZK', self::SECOND_CZK_IBAN),
        ]);
        $this->connections->method('findWithCredentialByCurrency')->willReturn(null);
        $this->connections->method('ensure')->willReturnMap([[7, 4, 'csas', 21], [7, 5, 'csas', 22]]);
        $saved = [];
        $this->connections->expects(self::exactly(2))->method('saveValidated')->willReturnCallback(
            function (int $supplier, int $connection, string $provider, string $cipher, bool $enabled, string $account, string $bank, string $currency) use (&$saved): void {
                [$context, $plain] = $this->sealed[$cipher];
                self::assertSame(CsasCredentialVault::context($supplier, $connection), $context, 'Šifra je vázaná na napojení účtu.');
                $saved[$connection] = ['data' => json_decode($plain, true), 'enabled' => $enabled, 'account' => $account, 'bank' => $bank,
                    'currency' => $currency, 'provider' => $provider];
            });

        $results = $this->linker->link(3, $this->primary(), self::remote());

        self::assertSame([
            ['currency_id' => 4, 'status' => 'linked', 'connection_id' => 21],
            ['currency_id' => 5, 'status' => 'linked', 'connection_id' => 22],
        ], $results);
        $eur = $saved[21];
        self::assertSame(['csas', true, self::EUR_IBAN, '0800', 'EUR'], [$eur['provider'], $eur['enabled'], $eur['account'], $eur['bank'], $eur['currency']]);
        self::assertSame([21, 'remote-eur', self::EUR_IBAN, 'EUR', false],
            [$eur['data']['connection_id'], $eur['data']['account_id'], $eur['data']['account_iban'], $eur['data']['account_currency'],
                $eur['data']['payment_account_suitable']]);
        self::assertSame(['synthetic-access', 'synthetic-refresh', 'siblings.accounts siblings.payments'],
            [$eur['data']['access_token'], $eur['data']['refresh_token'], $eur['data']['scope']], 'Tokeny a udělený rozsah sdílí souhlas.');
        self::assertNotSame(str_repeat('a', 64), $eur['data']['call_guard_key'], 'Každý účet má vlastní hlídání četnosti volání.');
        self::assertNotSame($eur['data']['call_guard_key'], $saved[22]['data']['call_guard_key']);
        self::assertTrue($saved[22]['data']['payment_account_suitable']);
    }

    public function testSkipsAccountsTheConsentDoesNotCoverOrUserDisconnected(): void
    {
        $this->oauth->method('siblingAccounts')->willReturn([
            self::local(4, 'USD', '2000000018'),
            self::local(5, 'CZK', ''),
            self::local(6, 'CZK', '4000000009'),
            self::local(7, 'CZK', '3000000004', 30, null, true),
            self::local(8, 'CZK', '1000000005'),
        ]);
        $this->connections->expects(self::never())->method('saveValidated');

        self::assertSame([
            ['currency_id' => 4, 'status' => 'not_in_consent'],
            ['currency_id' => 5, 'status' => 'not_in_consent'],
            ['currency_id' => 6, 'status' => 'not_in_consent'],
            ['currency_id' => 7, 'status' => 'disconnected'],
            ['currency_id' => 8, 'status' => 'not_in_consent'],
        ], $this->linker->link(3, $this->primary(), self::remote()));
    }

    public function testKeepsAccountsConnectedThroughAnotherConsentAndRecognisesTheSameOne(): void
    {
        $this->oauth->method('siblingAccounts')->willReturn([
            self::local(4, 'EUR', '2000000018', 21, $this->storedCredential(21, 'other-refresh', self::EUR_IBAN, 'EUR')),
            self::local(5, 'CZK', '3000000004', 22, $this->storedCredential(22, 'synthetic-refresh', self::SECOND_CZK_IBAN, 'CZK')),
        ]);
        $this->connections->expects(self::never())->method('saveValidated');

        self::assertSame([
            ['currency_id' => 4, 'status' => 'separate_consent'],
            ['currency_id' => 5, 'status' => 'already_linked'],
        ], $this->linker->link(3, $this->primary(), self::remote()));
    }

    public function testNewLoginTakesOverAccountsOfThePreviousConsentAndKeepsTheirSwitch(): void
    {
        $cipher = $this->storedCredential(21, 'old-refresh', self::EUR_IBAN, 'EUR');
        $this->oauth->method('siblingAccounts')->willReturn([self::local(4, 'EUR', '2000000018', 21, $cipher)]);
        $this->connections->method('findWithCredentialByCurrency')->willReturn(['id' => 21, 'has_token' => true, 'token_ciphertext' => $cipher,
            'enabled' => false]);
        $this->connections->method('ensure')->willReturn(21);
        $this->connections->expects(self::once())->method('saveValidated')->with(7, 21, 'csas', self::anything(), false, self::EUR_IBAN, '0800', 'EUR');

        self::assertSame([['currency_id' => 4, 'status' => 'relinked', 'connection_id' => 21]],
            $this->linker->link(3, $this->primary('new-refresh'), self::remote(), $this->primary('old-refresh')));
    }

    public function testConcurrentConnectionWinsAndDryRunSavesNothing(): void
    {
        $this->oauth->method('siblingAccounts')->willReturn([self::local(4, 'EUR', '2000000018')]);
        $this->connections->method('findWithCredentialByCurrency')->willReturn(['id' => 21, 'has_token' => true, 'token_ciphertext' => 'enc:v2:fresh',
            'enabled' => true]);
        $this->connections->expects(self::never())->method('saveValidated');

        self::assertSame([['currency_id' => 4, 'status' => 'linked']], $this->linker->link(3, $this->primary(), self::remote(), null, false));
        self::assertSame([['currency_id' => 4, 'status' => 'failed']], $this->linker->link(3, $this->primary(), self::remote()));
    }
}
