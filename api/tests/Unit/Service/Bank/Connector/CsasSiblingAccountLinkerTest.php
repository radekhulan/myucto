<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Repository\{BankConnectionRepository, CsasOAuthRepository};
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Bank\Connector\{BankConnectorCallGuard, BankConnectorException, CsasApiClient, CsasCredentialVault, CsasSharedConsent,
    CsasSiblingAccountLinker};
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
        $this->secrets = $secrets;
        $this->calls = $this->createMock(BankConnectorCallGuard::class);
        $this->calls->method('withConnectionLock')->willReturnCallback(static fn (int $s, int $c, callable $fn): mixed => $fn());
        $this->build();
    }

    private SecretEncryption $secrets;
    private BankConnectorCallGuard $calls;

    /** Trezor a propojení s nastavením sdílení (null = výchozí, sdílí každý souhlas). */
    private function build(mixed $sharing = null): void
    {
        $api = $this->createMock(CsasApiClient::class);
        $api->method('environment')->willReturn('production');
        $shared = new CsasSharedConsent(new Config($sharing === null ? [] : ['bank_connectors' => ['csas' => ['shared_consent' => $sharing]]]));
        $this->vault = new CsasCredentialVault($api, $this->oauth, $this->secrets, $this->inlineRotationFlow(), $shared);
        $this->linker = new CsasSiblingAccountLinker($this->oauth, $this->connections, $this->vault, $this->secrets, $this->calls, $shared);
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
            ['id' => 'remote-eur', 'identification' => ['iban' => self::EUR_IBAN], 'currency' => 'EUR', 'suitableScope' => ['AISP' => ''],
                'ownersNames' => ['FIRMA-12, s.r.o.']],
            ['id' => 'remote-czk-2', 'identification' => ['iban' => self::SECOND_CZK_IBAN], 'currency' => 'CZK', 'suitableScope' => ['AISP' => '', 'PISP' => '']],
        ];
    }

    private static function local(int $currencyId, string $code, string $number, ?int $connectionId = null, ?string $cipher = null,
        bool $disconnected = false, ?int $supplierId = null): array
    {
        return ($supplierId === null ? [] : ['supplier_id' => $supplierId, 'company_name' => 'Firma ' . $supplierId . ' s.r.o.']) + ['currency_id' => $currencyId, 'code' => $code, 'account_number' => $number, 'connection_id' => $connectionId,
            'provider' => $connectionId === null ? null : 'csas', 'token_ciphertext' => $cipher, 'disconnected' => $disconnected];
    }

    /** Uložený přístup napojení, jak by ho uložila aplikace. */
    private function storedCredential(int $connectionId, string $refresh, string $iban, string $currency, int $supplierId = 7,
        ?int $consentOwner = null): string
    {
        $data = ['supplier_id' => $supplierId, 'connection_id' => $connectionId, 'refresh_token' => $refresh, 'account_iban' => $iban,
            'account_currency' => $currency] + ($consentOwner === null ? [] : ['consent_supplier_id' => $consentOwner]) + $this->primary();
        $key = 'enc:v2:stored-' . $supplierId . '-' . $connectionId;
        $this->sealed[$key] = [CsasCredentialVault::context($supplierId, $connectionId), $this->vault->encode($data)];
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

    /**
     * Sdílený souhlas nosné firmy 7 napojí účty ostatních firem: kopie je vázaná na firmu
     * účtu, nese vlastníka souhlasu a přebírá jeho rozsah včetně plateb, je-li účet pro platby
     * vhodný. Vlastní souhlas jiné firmy ani odpojený účet se nepřebírá.
     */
    public function testSharedConsentLinksAccountsOfOtherCompaniesWithTheConsentScope(): void
    {
        $remote = self::remote();
        $remote[1]['suitableScope']['PISP'] = '';
        $this->oauth->expects(self::once())->method('otherSuppliersAccounts')->with(7)->willReturn([
            self::local(40, 'EUR', '2000000018', null, null, false, 12),
            self::local(50, 'CZK', '3000000004', 51, $this->storedCredential(51, 'own-refresh', self::SECOND_CZK_IBAN, 'CZK', 13), false, 13),
            self::local(60, 'CZK', '4000000009', null, null, false, 14),
            self::local(70, 'CZK', '1000000005', 71, null, true, 15),
            self::local(80, 'CZK', '3000000004', null, null, false, 16),
        ]);
        $this->connections->method('findWithCredentialByCurrency')->willReturn(null);
        $this->connections->expects(self::once())->method('ensure')->with(12, 40, 'csas')->willReturn(41);
        $saved = null;
        $this->connections->expects(self::once())->method('saveValidated')->willReturnCallback(
            function (int $supplier, int $connection, string $provider, string $cipher, bool $enabled, string $account) use (&$saved): void {
                [$context, $plain] = $this->sealed[$cipher];
                self::assertSame(CsasCredentialVault::context(12, 41), $context, 'Šifra je vázaná na firmu a napojení účtu.');
                $saved = [$supplier, $connection, $enabled, $account, json_decode($plain, true)];
            });

        $results = $this->linker->linkShared($this->primary(), $remote);

        self::assertSame([
            ['supplier_id' => 12, 'currency_id' => 40, 'status' => 'linked', 'connection_id' => 41],
            ['supplier_id' => 13, 'currency_id' => 50, 'status' => 'separate_consent'],
            ['supplier_id' => 14, 'currency_id' => 60, 'status' => 'not_in_consent'],
            ['supplier_id' => 15, 'currency_id' => 70, 'status' => 'disconnected'],
            ['supplier_id' => 16, 'currency_id' => 80, 'status' => 'owner_mismatch'],
        ], $results);
        [$supplier, $connection, $enabled, $account, $data] = $saved;
        self::assertSame([12, 41, true, self::EUR_IBAN], [$supplier, $connection, $enabled, $account]);
        self::assertSame([12, 41, 7, 'remote-eur', 'synthetic-refresh'],
            [$data['supplier_id'], $data['connection_id'], $data['consent_supplier_id'], $data['account_id'], $data['refresh_token']]);
        self::assertSame(['siblings.accounts siblings.payments', true], [$data['scope'], $data['payment_account_suitable']],
            'Firma účtu přebírá rozsah souhlasu včetně plateb.');
        self::assertSame('available', CsasCredentialVault::paymentAvailability($data));
        self::assertSame('account_not_suitable', CsasCredentialVault::paymentAvailability(['payment_account_suitable' => false] + $data),
            'Účet, který banka pro platby neoznačí, platit nemůže.');
        self::assertSame(CsasCredentialVault::consentLockKey($this->primary()), CsasCredentialVault::consentLockKey($data),
            'Obnova tokenu běží pod zámkem souhlasu nosné firmy.');
    }

    public function testSharingCanBeDisabledOrPinnedAndCopiesNeverShareFurther(): void
    {
        $this->oauth->expects(self::never())->method('otherSuppliersAccounts');
        $copy = ['supplier_id' => 12, 'consent_supplier_id' => 7] + $this->primary();
        self::assertSame([], $this->linker->linkShared($copy, self::remote()), 'Kopie sama další firmy nepropojuje.');
        $this->build(['supplier_id' => 7]);
        self::assertSame([], $this->linker->linkShared(['supplier_id' => 8] + $this->primary(), self::remote()), 'Připnutá je jen firma 7.');
        $this->build(false);
        self::assertSame([], $this->linker->linkShared($this->primary(), self::remote()), 'Vypnuté sdílení.');
        $this->build('neplatné');
        self::assertSame([], $this->linker->linkShared($this->primary(), self::remote()), 'Chybné nastavení sdílení vypne.');
    }

    /**
     * Nosná je firma, jejíž souhlas účet napojil první: další souhlas, který účet také pokrývá,
     * ho nepřebírá, dokud nosná firma souhlas má. Vlastní souhlas firmy převzatý ale nahradí.
     */
    public function testFirstCarrierKeepsTheAccountUntilTheCompanyConnectsItsOwnConsent(): void
    {
        $cipher = $this->storedCredential(41, 'synthetic-refresh', self::EUR_IBAN, 'EUR', 12, 7);
        $owner = ['id' => 9, 'currency_id' => 3, 'token_ciphertext' => $this->storedCredential(9, 'synthetic-refresh', self::PRIMARY_IBAN, 'CZK')];
        $this->oauth->method('connectedCredentials')->willReturn([$owner]);
        $this->oauth->method('otherSuppliersAccounts')->willReturn([self::local(40, 'EUR', '2000000018', 41, $cipher, false, 12)]);
        $this->oauth->method('siblingAccounts')->willReturn([self::local(40, 'EUR', '2000000018', 41, $cipher)]);
        $this->connections->method('findWithCredentialByCurrency')->willReturn(['id' => 41, 'has_token' => true, 'token_ciphertext' => $cipher,
            'enabled' => true]);
        $this->connections->method('ensure')->willReturn(41);
        $this->connections->expects(self::once())->method('saveValidated')->with(12, 41, 'csas', self::anything(), true, self::EUR_IBAN, '0800', 'EUR');

        $other = ['supplier_id' => 8, 'refresh_token' => 'other-refresh'] + $this->primary();
        self::assertSame([['supplier_id' => 12, 'currency_id' => 40, 'status' => 'separate_consent']],
            $this->linker->linkShared($other, self::remote()), 'Druhý souhlas účet nosné firmě nebere.');
        $own = ['supplier_id' => 12, 'connection_id' => 50, 'refresh_token' => 'own-refresh'] + $this->primary();
        self::assertSame([['currency_id' => 40, 'status' => 'relinked', 'connection_id' => 41]],
            $this->linker->link(3, $own, self::remote()), 'Vlastní souhlas firmy převzatý nahradí.');
    }

    public function testCopyFromAnOlderConsentOfTheOwnerMovesToTheCurrentOne(): void
    {
        $cipher = $this->storedCredential(41, 'old-refresh', self::EUR_IBAN, 'EUR', 12, 7);
        $this->oauth->method('otherSuppliersAccounts')->willReturn([self::local(40, 'EUR', '2000000018', 41, $cipher, false, 12)]);
        $this->connections->method('findWithCredentialByCurrency')->willReturn(['id' => 41, 'has_token' => true, 'token_ciphertext' => $cipher,
            'enabled' => true]);
        $this->connections->method('ensure')->willReturn(41);
        $this->connections->expects(self::once())->method('saveValidated')->with(12, 41, 'csas', self::anything(), true, self::EUR_IBAN, '0800', 'EUR');

        self::assertSame([['supplier_id' => 12, 'currency_id' => 40, 'status' => 'relinked', 'connection_id' => 41]],
            $this->linker->linkShared($this->primary('new-refresh'), self::remote()));
    }

    /**
     * Kopie se použije jen, dokud nosná firma tentýž souhlas pořád má připojený a sdílení je
     * nastavené. Odpojení nosné firmy nebo vypnutí sdílení zastaví i ostatní firmy.
     */
    public function testSharedCopyStopsWhenTheOwnerDisconnectsOrSharingIsOff(): void
    {
        $copy = $this->vault->encode(['supplier_id' => 12, 'connection_id' => 41, 'consent_supplier_id' => 7, 'scope' => 'siblings.accounts',
            'payment_account_suitable' => false] + $this->primary());
        $owner = ['id' => 9, 'currency_id' => 3, 'token_ciphertext' => $this->storedCredential(9, 'synthetic-refresh', self::PRIMARY_IBAN, 'CZK')];
        $this->oauth->expects(self::exactly(2))->method('connectedCredentials')->with(7)->willReturnOnConsecutiveCalls([$owner], [], [$owner]);

        self::assertSame(12, $this->vault->access($copy)['supplier_id']);
        foreach (['odpojená nosná firma', 'vypnuté sdílení'] as $case) {
            if ($case === 'vypnuté sdílení') $this->build(false);
            try {
                $this->vault->access($copy);
                self::fail($case);
            } catch (BankConnectorException $e) {
                self::assertSame('csas_shared_consent_unavailable', $e->errorCode, $case);
            }
        }
    }
}
