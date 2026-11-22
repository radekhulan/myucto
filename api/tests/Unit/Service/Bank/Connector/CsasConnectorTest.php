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
    use \MyInvoice\Tests\Support\InlineOAuthRotation;

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
        $vault = new CsasCredentialVault($api, $repo, $secret, $this->inlineRotationFlow());
        $data = $this->credentials();
        $data['access_expires_at'] = time() - 1;
        $repo->expects(self::once())->method('credential')->with(7, 9)->willReturn('enc:v2:stored');
        $secret->method('decryptFor')->willReturn($vault->encode($data));
        $api->expects(self::once())->method('refresh')->willReturn(['access_token' => 'rotated-synthetic', 'refresh_token' => 'rotated-refresh', 'expires_in' => 300]);
        $secret->expects(self::once())->method('encryptFor')->with(self::callback(static fn (string $raw): bool => str_contains($raw, 'rotated-refresh')),
            CsasCredentialVault::context(7, 9))->willReturn('enc:v2:synthetic');
        $repo->expects(self::once())->method('rotate')->with(7, 9, 'enc:v2:synthetic')->willReturn(false);
        $this->expectException(BankConnectorException::class);
        $vault->access($vault->encode($data));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refreshScopes')]
    public function testRefreshNeverAddsPaymentsToReadOnlyConnectionButReflectsRevocation(?string $stored, string $refreshed, string $expected): void
    {
        $api = $this->createMock(CsasApiClient::class);
        $repo = $this->createMock(CsasOAuthRepository::class);
        $secret = $this->createMock(SecretEncryption::class);
        $vault = new CsasCredentialVault($api, $repo, $secret, $this->inlineRotationFlow());
        $data = $this->credentials() + ['environment' => 'production'];
        if ($stored !== null) $data['scope'] = $stored;
        $data['access_expires_at'] = time() - 1;
        $repo->method('credential')->willReturn('enc:v2:stored');
        $secret->method('decryptFor')->willReturn($vault->encode($data));
        $api->method('refresh')->willReturn(['access_token' => 'rotated-synthetic', 'expires_in' => 300, 'scope' => $refreshed]);
        $secret->method('encryptFor')->willReturn('enc:v2:synthetic');
        $repo->method('rotate')->willReturn(true);

        self::assertSame($expected, $vault->access($vault->encode($data))['scope']);
    }

    public static function refreshScopes(): iterable
    {
        yield 'starý přístup bez scope' => [null, 'siblings.accounts siblings.payments', 'siblings.accounts'];
        yield 'připojení jen pro čtení' => ['siblings.accounts', 'siblings.accounts siblings.payments', 'siblings.accounts'];
        yield 'platby zůstávají' => ['siblings.accounts siblings.payments', 'siblings.accounts siblings.payments', 'siblings.accounts siblings.payments'];
        yield 'odvolání plateb' => ['siblings.accounts siblings.payments', 'siblings.accounts', 'siblings.accounts'];
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
        self::assertTrue($entry['capabilities']['payment_order_submission']);
    }

    public function testAccountsOfOneConsentAreListedOncePerAccessToken(): void
    {
        $api = $this->createMock(CsasApiClient::class);
        $vault = $this->createMock(CsasCredentialVault::class);
        $eur = ['account_id' => 'remote-eur', 'account_iban' => 'CZ6708000000002000000018', 'account_currency' => 'EUR', 'connection_id' => 10]
            + $this->credentials();
        $renewed = ['access_token' => 'synthetic-access-2'] + $this->credentials();
        $vault->method('access')->willReturnOnConsecutiveCalls($this->credentials(), $eur, $renewed);
        $api->expects(self::exactly(2))->method('accounts')->willReturn([
            ['id' => 'remote-czk', 'identification' => ['iban' => 'CZ7508000000001000000005'], 'currency' => 'CZK'],
            ['id' => 'remote-eur', 'identification' => ['iban' => 'CZ6708000000002000000018'], 'currency' => 'EUR'],
        ]);
        $api->expects(self::exactly(3))->method('transactions')->willReturn([]);
        $connector = new CsasBankConnector($api, $vault, new CsasTransactionParser());

        $connector->downloadStatement('synthetic-czk', '2026-01-01', '2026-01-31');
        $connector->downloadStatement('synthetic-eur', '2026-01-01', '2026-01-31');
        // Nový access token (obnova, jiný souhlas) seznam účtů vždy načte znovu.
        $connector->downloadStatement('synthetic-czk', '2026-01-01', '2026-01-31');
    }

    public function testRotationIsSharedWithAccountsOfTheSameConsentOnly(): void
    {
        $api = $this->createMock(CsasApiClient::class);
        $repo = $this->createMock(CsasOAuthRepository::class);
        $secret = $this->createMock(SecretEncryption::class);
        $flow = $this->inlineRotationFlow();
        $vault = new CsasCredentialVault($api, $repo, $secret, $flow);
        $expired = ['access_expires_at' => time() - 1, 'environment' => 'production'] + $this->credentials();
        $sibling = ['connection_id' => 10, 'account_id' => 'remote-eur', 'account_iban' => 'CZ6708000000002000000018', 'account_currency' => 'EUR',
            'call_guard_key' => str_repeat('b', 64), 'scope' => 'siblings.accounts'] + $expired;
        $foreign = ['connection_id' => 11, 'refresh_token' => 'other-consent'] + $sibling;
        $plain = ['enc:v2:own' => $vault->encode($expired), 'enc:v2:sibling' => $vault->encode($sibling), 'enc:v2:foreign' => $vault->encode($foreign)];
        $secret->method('decryptFor')->willReturnCallback(static fn (string $cipher, string $context): string => $plain[$cipher]);
        $repo->method('credential')->willReturn('enc:v2:own');
        $repo->expects(self::once())->method('connectedCredentials')->with(7)->willReturn([
            ['id' => 9, 'currency_id' => 3, 'token_ciphertext' => 'enc:v2:own'],
            ['id' => 10, 'currency_id' => 4, 'token_ciphertext' => 'enc:v2:sibling'],
            ['id' => 11, 'currency_id' => 5, 'token_ciphertext' => 'enc:v2:foreign'],
        ]);
        $api->expects(self::once())->method('refresh')->willReturn(['access_token' => 'rotated-access', 'expires_in' => 300,
            'scope' => 'siblings.accounts siblings.payments']);
        $sealed = [];
        $secret->method('encryptFor')->willReturnCallback(static function (string $raw, string $context) use (&$sealed): string {
            $sealed[$context] = json_decode($raw, true);
            return 'enc:v2:' . count($sealed);
        });
        $repo->expects(self::exactly(2))->method('rotate')->willReturn(true);

        $vault->access($plain['enc:v2:own']);

        $shared = $sealed[CsasCredentialVault::context(7, 10)];
        self::assertSame(['rotated-access', 'synthetic-refresh', 10, 'remote-eur', str_repeat('b', 64), 'siblings.accounts'],
            [$shared['access_token'], $shared['refresh_token'], $shared['connection_id'], $shared['account_id'], $shared['call_guard_key'], $shared['scope']],
            'Další účet převezme tokeny, ale ponechá si vlastní účet, hlídání volání a rozsah bez plateb.');
        self::assertArrayNotHasKey(CsasCredentialVault::context(7, 11), $sealed, 'Účet jiného souhlasu se nemění.');
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

    public function testSubmissionWithoutJournalCannotMakeNetworkCalls(): void
    {
        $api = $this->createMock(CsasApiClient::class);
        $vault = $this->createMock(CsasCredentialVault::class);
        $vault->expects(self::never())->method('access');
        $this->expectException(BankConnectorException::class);
        (new CsasBankConnector($api, $vault, new CsasTransactionParser()))->submitPaymentOrder('synthetic', 'synthetic');
    }

    public function testReadOnlyCredentialStillDecodesButCannotSubmitPayments(): void
    {
        $vault = new CsasCredentialVault($this->createMock(CsasApiClient::class), $this->createMock(CsasOAuthRepository::class),
            $this->createMock(SecretEncryption::class), $this->inlineRotationFlow());
        $connector = new CsasBankConnector($this->createMock(CsasApiClient::class), $vault, new CsasTransactionParser());
        $legacy = $this->credentials() + ['environment' => 'production'];

        self::assertSame('authorization_scope_missing', CsasCredentialVault::paymentAvailability($vault->decode($vault->encode($legacy))));
        self::assertFalse($connector->canSubmitPaymentOrder($vault->encode($legacy)), 'Přístup z doby jen čtení neumí platit.');
        $readOnly = $legacy + ['scope' => 'siblings.accounts', 'payment_account_suitable' => true];
        self::assertFalse($connector->canSubmitPaymentOrder($vault->encode($readOnly)));
        $unsuitable = $legacy + ['scope' => 'siblings.accounts siblings.payments', 'payment_account_suitable' => false];
        self::assertSame('account_not_suitable', CsasCredentialVault::paymentAvailability($unsuitable));
        self::assertFalse($connector->canSubmitPaymentOrder($vault->encode($unsuitable)));
        $payments = $legacy + ['scope' => 'siblings.accounts siblings.payments', 'payment_account_suitable' => true];
        self::assertTrue($connector->canSubmitPaymentOrder($vault->encode($payments)));
    }

    public function testStagedSubmissionWithoutPaymentsScopeCallsNoBank(): void
    {
        $api = $this->createMock(CsasApiClient::class);
        $api->expects(self::never())->method('createPayment');
        $vault = $this->createMock(CsasCredentialVault::class);
        $vault->method('decode')->willReturn($this->credentials() + ['scope' => 'siblings.accounts', 'payment_account_suitable' => true]);
        $vault->expects(self::never())->method('access');
        $journal = new MemoryJournal();
        try {
            (new CsasBankConnector($api, $vault, new CsasTransactionParser()))->submitStagedPaymentOrder('synthetic', $this->abo(), $journal);
            self::fail('Bez scope siblings.payments se nesmí nic poslat.');
        } catch (BankConnectorException $e) {
            self::assertSame('csas_payments_scope_missing', $e->errorCode);
            self::assertFalse($e->ambiguousPaymentOutcome);
        }
        self::assertSame([], $journal->events);
    }

    public function testAmbiguousSecondPaymentStopsBeforeBatchAndReportsCreatedCount(): void
    {
        $api = $this->createMock(CsasApiClient::class);
        $api->expects(self::exactly(2))->method('createPayment')->willReturnOnConsecutiveCalls(
            ['sign_id' => 'SYNTHETIC-SIGN-1', 'hash' => 'h1', 'instruction_status' => 'ACTC'],
            self::throwException(new BankConnectorException(BankConnectorException::REMOTE_UNAVAILABLE, 'synthetic', true)),
        );
        $api->expects(self::never())->method('createBatch');
        $credentials = $this->credentials() + ['scope' => 'siblings.accounts siblings.payments', 'payment_account_suitable' => true];
        $vault = $this->createMock(CsasCredentialVault::class);
        $vault->method('decode')->willReturn($credentials);
        $vault->method('access')->willReturn($credentials);
        $journal = new MemoryJournal();
        try {
            (new CsasBankConnector($api, $vault, new CsasTransactionParser()))->submitStagedPaymentOrder('synthetic', $this->abo(), $journal);
            self::fail('Nejasná platba musí odeslání zastavit.');
        } catch (BankConnectorException $e) {
            self::assertTrue($e->ambiguousPaymentOutcome);
            self::assertSame(1, $e->acceptedCount);
        }
        self::assertSame(['plan', 'sending:1', 'created:1', 'sending:2', 'unknown:2'], $journal->events);
    }

    /**
     * #393: smíšený příkaz, kde banka dvě platby odmítne (WRONG_DECIMAL_PLACES) a jednu přijme.
     * Odeslání zaznamená důvod u každé odmítnuté položky, dávku nezakládá a výsledek je jisté
     * odmítnutí (přijatá platba bez podpisu se neprovede).
     */
    public function testMixedOrderRecordsBankReasonForEachRejectedItemAndCreatesNoBatch(): void
    {
        $history = [];
        $connector = $this->bankConnector([$this->bankRejection(), $this->bankCreated('166609107206666'), $this->bankRejection()], $history);
        $journal = new MemoryJournal();
        try {
            $connector->submitStagedPaymentOrder('synthetic', $this->abo([235, 100000, 123456]), $journal);
            self::fail('Odmítnuté položky musí odeslání ukončit chybou.');
        } catch (BankConnectorException $e) {
            self::assertFalse($e->ambiguousPaymentOutcome, 'Jisté odmítnutí není nejasný výsledek.');
            self::assertSame(BankConnectorException::PAYMENT_REJECTED, $e->errorCode);
            self::assertSame([1, 2], [$e->acceptedCount, $e->rejectedCount]);
            self::assertSame(self::WRONG_DECIMAL_PLACES, $e->bankError);
        }
        self::assertSame(['plan', 'sending:1', 'rejected:1', 'sending:2', 'created:2', 'sending:3', 'rejected:3'], $journal->events);
        self::assertSame([1 => self::WRONG_DECIMAL_PLACES, 3 => self::WRONG_DECIMAL_PLACES], $journal->bankErrors);
        self::assertCount(3, $history, 'Dávka se po odmítnutí položek nezakládá.');
    }

    /**
     * #393: příkaz s jedinou přijatou platbou čeká na podpis té platby. Dávka se nezakládá
     * (dřív její odmítnutí vedlo na „výsledek není známý“ a podpis nešlo spustit).
     */
    public function testSingleAcceptedPaymentAwaitsSignatureWithoutBatch(): void
    {
        $history = [];
        $connector = $this->bankConnector([$this->bankCreated('166609107206666')], $history);
        $journal = new MemoryJournal();

        $result = $connector->submitStagedPaymentOrder('synthetic', $this->abo([100000]), $journal);

        self::assertSame(['accepted' => true, 'reference' => '166609107206666', 'status' => 'awaiting_signature',
            'sign_hash' => 'synthetic-payment-hash', 'sign_scope' => CsasApiClient::SIGN_SCOPE_PAYMENT], $result);
        self::assertSame(['plan', 'sending:1', 'created:1'], $journal->events);
        self::assertCount(1, $history);
        self::assertSame('https://www.csas.cz/webapi/api/v1/payments/my/payments', (string) $history[0]['request']->getUri());
    }

    /** Jisté odmítnutí dávky (4xx) dávku nezaložilo: výsledek je jistý a nese důvod banky. */
    public function testRejectedBatchIsDefiniteAndCarriesBankReason(): void
    {
        $history = [];
        $connector = $this->bankConnector([$this->bankCreated('166609107206666'), $this->bankCreated('166609107206667'),
            new \GuzzleHttp\Psr7\Response(400, [], (string) file_get_contents(self::FIXTURES . '/batch-rejected-not-signable.json'))], $history);
        try {
            $connector->submitStagedPaymentOrder('synthetic', $this->abo([100, 200]), new MemoryJournal());
            self::fail('Odmítnutá dávka musí skončit chybou.');
        } catch (BankConnectorException $e) {
            self::assertFalse($e->ambiguousPaymentOutcome);
            self::assertSame(2, $e->acceptedCount);
            self::assertSame(['code' => 'NOT_SIGNABLE', 'scope' => 'payments', 'message' => null], $e->bankError);
        }
        self::assertCount(3, $history);
    }

    private const FIXTURES = __DIR__ . '/../../../../Fixtures/Bank/Csas';
    private const WRONG_DECIMAL_PLACES = ['code' => 'WRONG_DECIMAL_PLACES', 'scope' => 'amount.instructedAmount.value',
        'message' => 'Chybný počet desetinných míst v částce.'];

    /** Konektor se skutečným klientem ČS nad atrapou HTTP s odpověďmi z fixtures. */
    private function bankConnector(array $responses, array &$history): CsasBankConnector
    {
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($responses));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $credentials = $this->credentials() + ['environment' => 'production', 'scope' => 'siblings.accounts siblings.payments',
            'payment_account_suitable' => true];
        $vault = $this->createStub(CsasCredentialVault::class);
        $vault->method('decode')->willReturn($credentials);
        $vault->method('access')->willReturn($credentials);
        return new CsasBankConnector(new CsasApiClient(new \GuzzleHttp\Client(['handler' => $stack])), $vault, new CsasTransactionParser());
    }

    private function bankCreated(string $signId): \GuzzleHttp\Psr7\Response
    {
        $data = json_decode((string) file_get_contents(self::FIXTURES . '/payment-created.json'), true, 32, JSON_THROW_ON_ERROR);
        $data['signInfo']['signId'] = $signId;
        return new \GuzzleHttp\Psr7\Response(200, [], json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function bankRejection(): \GuzzleHttp\Psr7\Response
    {
        return new \GuzzleHttp\Psr7\Response(400, [], (string) file_get_contents(self::FIXTURES . '/payment-rejected-wrong-decimal-places.json'));
    }

    /** @param list<int> $amounts částky položek v haléřích */
    private function abo(array $amounts = [100, 200]): string
    {
        $items = [];
        foreach (array_values($amounts) as $index => $minor) {
            $items[] = ['account_number' => '1000000005', 'bank_code' => $index % 2 === 0 ? '0100' : '0300', 'amount_minor' => $minor,
                'variable_symbol' => (string) ($index + 1)];
        }
        return (new \MyInvoice\Service\Payment\AboPaymentOrderWriter())->build([
            'client_name' => 'Synthetic Client', 'payer_account_number' => '1000000005', 'payer_bank_code' => '0800',
            'payment_date' => '2026-11-02', 'items' => $items,
        ]);
    }
}

/** Evidence položek v paměti; zaznamenává pořadí kroků konektoru. */
final class MemoryJournal implements \MyInvoice\Service\Bank\Connector\BankPaymentSubmissionJournal
{
    /** @var list<string> */
    public array $events = [];
    /** @var array<int,array{status:string,provider_sign_id:?string,provider_hash:?string}> */
    public array $rows = [];
    public bool $batchStarted = false;

    public function plan(array $items): array
    {
        $this->events[] = 'plan';
        if ($this->rows === []) {
            foreach ($items as $item) {
                $this->rows[$item['line_no']] = ['status' => 'planned', 'provider_sign_id' => null, 'provider_hash' => null];
            }
        }
        return $this->rows;
    }

    public function sending(int $lineNo): void { $this->move($lineNo, 'planned', 'sending'); }

    public function created(int $lineNo, string $signId, string $hash, string $instructionStatus): void
    {
        $this->move($lineNo, 'sending', 'created');
        $this->rows[$lineNo]['provider_sign_id'] = $signId;
        $this->rows[$lineNo]['provider_hash'] = $hash;
    }

    /** @var array<int,array{code:string,scope:?string,message:?string}|null> */
    public array $bankErrors = [];

    public function rejected(int $lineNo, string $errorCode, ?array $bankError = null): void
    {
        $this->move($lineNo, 'sending', 'rejected');
        $this->bankErrors[$lineNo] = $bankError;
    }

    public function unknown(int $lineNo, string $errorCode): void { $this->move($lineNo, 'sending', 'unknown'); }

    public function batchSending(): void
    {
        if ($this->batchStarted) throw new BankConnectorException('csas_batch_outcome_unknown', 'synthetic', true);
        $this->batchStarted = true;
        $this->events[] = 'batch';
    }

    private function move(int $lineNo, string $from, string $to): void
    {
        if (($this->rows[$lineNo]['status'] ?? null) !== $from) throw new BankConnectorException('payment_journal_conflict', 'synthetic', true);
        $this->rows[$lineNo]['status'] = $to;
        $this->events[] = $to . ':' . $lineNo;
    }
}
