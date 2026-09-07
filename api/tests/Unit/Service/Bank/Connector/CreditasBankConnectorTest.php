<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\CreditasBankConnector;
use MyInvoice\Service\Bank\Connector\CreditasPremiumClient;
use MyInvoice\Service\Bank\CreditasTransactionParser;
use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use MyInvoice\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\TestCase;

final class CreditasBankConnectorTest extends TestCase
{
    use OpensslConfigTrait;

    private const ACCOUNT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const IBAN = 'CZ3022500000001000000005';

    public function testTokenAndAccountIdWorkWithoutClientCertificate(): void
    {
        $today = date('Y-m-d');
        $history = [];
        $connector = $this->connector([
            $this->response(['currentAccount' => $this->account()]),
            $this->response(['transactions' => [], 'itemCount' => 0]),
        ], $history);
        $input = ['bearer_token' => str_repeat('A', 64), 'account_id' => self::ACCOUNT_ID, 'account_type' => 'current'];
        $token = $connector->credentials($input, $this->configuredAccount(), null);
        self::assertSame($token, $connector->credentials([], $this->configuredAccount(), $token));
        $content = $connector->downloadStatement($token, $today, $today);
        self::assertSame([], json_decode($content, true, 64, JSON_THROW_ON_ERROR)['transactions']);
        self::assertCount(2, $history);
    }

    public function testCredentialsStayLocalAndDownloadProducesCredentialFreeVerifiedStatement(): void
    {
        $today = date('Y-m-d');
        $account = $this->account();
        $transaction = $this->transaction($today);
        $history = [];
        $connector = $this->connector([
            $this->response(['currentAccount' => $account]),
            $this->response(['transactions' => [$transaction], 'itemCount' => 1]),
        ], $history);

        $token = $connector->credentials($this->input(), $this->configuredAccount(), null);
        $saved = json_decode($token, true, 64, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('account_info', $saved);
        self::assertCount(0, $history);
        self::assertSame($token, $connector->credentials([], $this->configuredAccount(), $token));

        $content = $connector->downloadStatement($token, $today, $today);
        self::assertStringNotContainsString('certificate', $content);
        self::assertStringNotContainsString('password', $content);
        self::assertStringNotContainsString(str_repeat('A', 64), $content);
        self::assertSame($account, json_decode($content, true, 64, JSON_THROW_ON_ERROR)['account_info']);
        $parsed = $connector->parseStatement($content);
        self::assertSame('1000000005', $parsed['header']['account_number']);
        self::assertNull($parsed['header']['curr_balance']);
        self::assertSame(125.25, $parsed['transactions'][0]['amount']);
        self::assertSame($transaction, $parsed['transactions'][0]['metadata']);
    }

    public function testDownloadFailClosedOnBankIdentityMismatch(): void
    {
        $account = $this->account();
        $account['iban'] = 'CZ3022500000002000000018';
        $account['bban'] = '2000000018';
        $history = [];
        $connector = $this->connector([$this->response(['currentAccount' => $account])], $history);
        $token = $connector->credentials($this->input(), $this->configuredAccount(), null);
        self::assertCount(0, $history);
        $this->expectException(BankConnectorOperationException::class);
        $this->expectExceptionMessage('statement_account_mismatch');
        $connector->downloadStatement($token, date('Y-m-d'), date('Y-m-d'));
    }

    public function testCredentialsRejectMutuallyInconsistentConfiguredIbanAndBbanWithoutHttp(): void
    {
        $history = [];
        $connector = $this->connector([], $history);
        $configured = $this->configuredAccount();
        $configured['account_number'] = '2000000018';
        try {
            $connector->credentials($this->input(), $configured, null);
            self::fail('Inconsistent configured identities must be rejected.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('provider_account_mismatch', $e->errorCode);
        }
        self::assertSame([], $history);
    }

    public function testDownloadRejectsBankResponseWhoseIbanAndBbanDescribeDifferentAccounts(): void
    {
        $account = $this->account();
        $account['bban'] = '2000000018';
        $history = [];
        $connector = $this->connector([$this->response(['currentAccount' => $account])], $history);
        $token = $connector->credentials($this->input(), $this->configuredAccount(), null);
        try {
            $connector->downloadStatement($token, date('Y-m-d'), date('Y-m-d'));
            self::fail('Inconsistent bank identities must be rejected.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('statement_account_mismatch', $e->errorCode);
        }
        self::assertCount(1, $history);
    }

    public function testDownloadRejectsTransactionCurrencyMismatch(): void
    {
        $today = date('Y-m-d');
        $account = $this->account();
        $transaction = $this->transaction($today);
        $transaction['amount']['currency'] = 'EUR';
        $connector = $this->connector([
            $this->response(['currentAccount' => $account]),
            $this->response(['transactions' => [$transaction], 'itemCount' => 1]),
        ]);
        $token = $connector->credentials($this->input(), $this->configuredAccount(), null);
        $this->expectException(BankConnectorOperationException::class);
        $this->expectExceptionMessage('statement_currency_mismatch');
        $connector->downloadStatement($token, $today, $today);
    }

    public function testSubmitVerifiesAccountAndStartsImportWithoutAuthorization(): void
    {
        $history = [];
        $account = $this->account();
        $connector = $this->connector([
            $this->response(['currentAccount' => $account]),
            $this->response(['importId' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']),
        ], $history);
        $token = $connector->credentials($this->input(), $this->configuredAccount(), null);

        self::assertSame([
            'accepted' => true,
            'reference' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'status' => 'import_started',
        ], $connector->submitPaymentOrder($token, $this->abo()));
        self::assertCount(2, $history);
        $payload = json_decode((string) $history[1]->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('KPC', $payload['format']);
        self::assertMatchesRegularExpression('/^[a-f0-9-]{36}$/D', $payload['reference']);
    }

    /**
     * @param list<Response> $responses
     * @param list<\Psr\Http\Message\RequestInterface> $history
     */
    private function connector(array $responses, array &$history = []): CreditasBankConnector
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::tap(static function (\Psr\Http\Message\RequestInterface $request) use (&$history): void {
            $history[] = $request;
        }));
        return new CreditasBankConnector(
            new CreditasPremiumClient(new Client(['handler' => $handler])),
            new CreditasTransactionParser(),
        );
    }

    /** @return array<string,mixed> */
    private function account(): array
    {
        return [
            'accountId' => self::ACCOUNT_ID,
            'bban' => '1000000005',
            'bankCode' => '2250',
            'iban' => self::IBAN,
            'currency' => 'CZK',
            'status' => 'ACTIVE',
            'alias' => 'Synthetic account',
        ];
    }

    /** @return array<string,mixed> */
    private function transaction(string $date): array
    {
        return [
            'transactionId' => 'TX-1',
            'category' => 'DOMESTIC',
            'type' => 'CREDIT',
            'code' => '76',
            'amount' => ['value' => '125.25', 'currency' => 'CZK'],
            'effectiveDate' => $date,
            'variableSymbol' => '202600001',
            'rawExtension' => ['preserved' => true],
        ];
    }

    /** @return array<string,string> */
    private function configuredAccount(): array
    {
        return ['account_number' => '1000000005', 'iban' => self::IBAN, 'account_code' => 'CZK'];
    }

    /** @return array<string,string> */
    private function input(): array
    {
        return ['bearer_token' => str_repeat('A', 64), 'account_id' => self::ACCOUNT_ID, 'account_type' => 'current'] + $this->certificate();
    }

    /** @param array<string,mixed> $data */
    private function response(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return array{certificate:string,password:string} */
    private function certificate(): array
    {
        $options = ['private_key_bits' => 2048] + self::opensslConfigArgs();
        $key = openssl_pkey_new($options);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'synthetic.example.invalid'], $key, $options);
        self::assertInstanceOf(\OpenSSLCertificateSigningRequest::class, $csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_pkcs12_export($certificate, $bytes, $key, 'synthetic-password'));
        return ['certificate' => base64_encode($bytes), 'password' => 'synthetic-password'];
    }

    private function abo(): string
    {
        return (new AboPaymentOrderWriter())->build([
            'client_name' => 'Synthetic Company', 'payer_account_number' => '1000000005', 'payer_bank_code' => '2250',
            'payment_date' => date('Y-m-d'),
            'items' => [[
                'account_number' => '2000000018', 'bank_code' => '0100', 'amount_minor' => 12345,
                'variable_symbol' => '202600001', 'message' => 'Synthetic payment',
            ]],
        ]);
    }
}
