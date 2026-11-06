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

    private function abo(): string
    {
        return (new \MyInvoice\Service\Payment\AboPaymentOrderWriter())->build([
            'client_name' => 'Synthetic Client', 'payer_account_number' => '1000000005', 'payer_bank_code' => '0800',
            'payment_date' => '2026-11-02',
            'items' => [
                ['account_number' => '1000000005', 'bank_code' => '0100', 'amount_minor' => 100, 'variable_symbol' => '1'],
                ['account_number' => '1000000005', 'bank_code' => '0300', 'amount_minor' => 200, 'variable_symbol' => '2'],
            ],
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

    public function rejected(int $lineNo, string $errorCode): void { $this->move($lineNo, 'sending', 'rejected'); }

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
