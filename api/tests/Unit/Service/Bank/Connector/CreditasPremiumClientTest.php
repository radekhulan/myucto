<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\CreditasPremiumClient;
use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use PHPUnit\Framework\TestCase;

final class CreditasPremiumClientTest extends TestCase
{
    private const ACCOUNT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testTokenOnlyKeepsTlsVerificationAndDisablesRedirects(): void
    {
        $history = [];
        $account = ['accountId' => self::ACCOUNT_ID, 'currency' => 'CZK'];
        $client = $this->clientWith([$this->json(['currentAccount' => $account])], $history);
        self::assertSame($account, $client->currentAccount(['bearer_token' => str_repeat('A', 64)], self::ACCOUNT_ID));
        self::assertSame([], $history[0]['options']['curl']);
        self::assertTrue($history[0]['options']['verify']);
        self::assertFalse($history[0]['options']['allow_redirects']);
    }

    public function testCurrentAccountUsesOfficialEndpointBearerAndHardenedTransport(): void
    {
        $history = [];
        $account = ['accountId' => self::ACCOUNT_ID, 'iban' => 'CZ3022500000001000000005', 'bban' => '1000000005', 'currency' => 'CZK'];
        $client = $this->clientWith([$this->json(['currentAccount' => $account])], $history);

        self::assertSame($account, $client->currentAccount($this->credentials(), self::ACCOUNT_ID));
        self::assertSame('https://api.creditas.cz/oam/v1/account/current/get', (string) $history[0]['request']->getUri());
        self::assertSame('Bearer ' . str_repeat('A', 64), $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame(['accountId' => self::ACCOUNT_ID], $this->body($history[0]['request']));
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertTrue($history[0]['options']['verify']);
        self::assertFalse($history[0]['options']['debug']);
        self::assertFalse($history[0]['options']['stream']);
        self::assertInstanceOf(\Psr\Http\Message\StreamInterface::class, $history[0]['options']['sink']);
        self::assertIsCallable($history[0]['options']['on_headers']);
        self::assertIsCallable($history[0]['options']['progress']);
        self::assertSame($this->credentials()['curl_options'], $history[0]['options']['curl']);
        try {
            $history[0]['options']['sink']->write(str_repeat('x', 16 * 1024 * 1024));
            self::fail('Bounded response sink should reject an oversized write.');
        } catch (\RuntimeException $e) {
            self::assertSame('Response size limit exceeded.', $e->getMessage());
        }
    }

    public function testSavingsAccountRejectsMismatchedIdentity(): void
    {
        $client = $this->clientWith([$this->json(['savingsAccount' => ['accountId' => str_repeat('b', 40), 'currency' => 'CZK']])]);
        try {
            $client->savingsAccount($this->credentials(), self::ACCOUNT_ID);
            self::fail('Mismatched account expected.');
        } catch (BankConnectorException $e) {
            self::assertSame(CreditasPremiumClient::ERROR_ACCOUNT_MISMATCH, $e->errorCode);
        }
    }

    public function testTransactionsReturnsAllRawPagesUsingZeroBasedPaging(): void
    {
        $history = [];
        $first = $this->transaction('TX-1', '10.00');
        $second = $this->transaction('TX-2', '20.00');
        $client = $this->clientWith([
            $this->json(['transactions' => [$first], 'itemCount' => 2]),
            $this->json(['transactions' => [$second], 'itemCount' => 2]),
        ], $history);
        [$from, $to] = $this->range();

        self::assertSame([$first, $second], $client->transactions($this->credentials(), self::ACCOUNT_ID, $from, $to));
        self::assertSame(0, $this->body($history[0]['request'])['pageIndex']);
        self::assertSame(1, $this->body($history[1]['request'])['pageIndex']);
        self::assertSame(100, $this->body($history[0]['request'])['pageItemCount']);
        self::assertSame(['dateFrom' => $from, 'dateTo' => $to], $this->body($history[0]['request'])['filter']);
    }

    public function testTransactionsRejectsDuplicateAndStopsWithoutThirdRequest(): void
    {
        $history = [];
        $transaction = $this->transaction('TX-DUP', '10.00');
        $client = $this->clientWith([
            $this->json(['transactions' => [$transaction], 'itemCount' => 2]),
            $this->json(['transactions' => [$transaction], 'itemCount' => 2]),
        ], $history);
        [$from, $to] = $this->range();
        $this->expectExceptionMessage('duplicitní transakci');
        try {
            $client->transactions($this->credentials(), self::ACCOUNT_ID, $from, $to);
        } finally {
            self::assertCount(2, $history);
        }
    }

    public function testTransactionsRejectsAmountThatWouldRequireDatabaseRounding(): void
    {
        $client = $this->clientWith([$this->json([
            'transactions' => [$this->transaction('TX-precision', '1.001')],
            'itemCount' => 1,
        ])]);
        [$from, $to] = $this->range();
        $this->expectException(BankConnectorException::class);
        $this->expectExceptionMessage('neplatnou transakci');
        $client->transactions($this->credentials(), self::ACCOUNT_ID, $from, $to);
    }

    public function testExportReturnsExactDecodedCamtBytes(): void
    {
        $history = [];
        $xml = '<?xml version="1.0"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt/></Document>';
        $client = $this->clientWith([$this->json(['export' => base64_encode($xml)])], $history);
        [$from, $to] = $this->range();

        self::assertSame($xml, $client->exportTransactions($this->credentials(), self::ACCOUNT_ID, $from, $to));
        self::assertSame('XML', $this->body($history[0]['request'])['format']);
    }

    public function testExportRejectsEntityBearingXml(): void
    {
        $xml = '<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///synthetic">]><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"/>';
        $client = $this->clientWith([$this->json(['export' => base64_encode($xml)])]);
        [$from, $to] = $this->range();
        $this->expectException(BankConnectorException::class);
        $this->expectExceptionMessage('neplatný export');
        $client->exportTransactions($this->credentials(), self::ACCOUNT_ID, $from, $to);
    }

    public function testStartImportUsesDocumentedKpcPayloadAndReturnsAsyncState(): void
    {
        $history = [];
        $client = $this->clientWith([$this->json(['importId' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'])], $history);
        $abo = $this->abo();

        self::assertSame(
            ['status' => 'import_started', 'reference' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],
            $client->startPaymentImport($this->credentials(), self::ACCOUNT_ID, $abo, 'batch-20260907'),
        );
        $body = $this->body($history[0]['request']);
        self::assertSame('https://api.creditas.cz/oam/v1/payment/import', (string) $history[0]['request']->getUri());
        self::assertSame('KPC', $body['format']);
        self::assertSame('DOMESTIC', $body['type']);
        self::assertSame('CANCEL_ON_ERROR', $body['importMode']);
        self::assertSame($abo, base64_decode($body['data'], true));
    }

    public function testStartImportTransportFailureIsAmbiguousAndNotRetried(): void
    {
        $history = [];
        $client = $this->clientWith([
            new ConnectException('synthetic private detail', new Request('POST', 'https://api.creditas.cz')),
            $this->json(['importId' => str_repeat('b', 40)]),
        ], $history);
        try {
            $client->startPaymentImport($this->credentials(), self::ACCOUNT_ID, $this->abo(), 'batch-1');
            self::fail('Transport failure expected.');
        } catch (BankConnectorException $e) {
            self::assertTrue($e->ambiguousPaymentOutcome);
            self::assertStringNotContainsString('synthetic private detail', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        self::assertCount(1, $history);
    }

    public function testPaymentImportStatusIsNormalizedWithoutRawErrorLog(): void
    {
        $client = $this->clientWith([$this->json([
            'status' => 'IN_PROGRESS', 'itemCount' => 2, 'itemCountError' => 0,
            'reference' => 'batch-1', 'bulkPaymentOrderId' => null, 'errorLog' => 'synthetic private raw log',
        ])]);
        self::assertSame([
            'status' => 'IN_PROGRESS', 'item_count' => 2, 'error_count' => 0,
            'reference' => 'batch-1', 'bulk_payment_order_id' => null,
        ], $client->paymentImportStatus($this->credentials(), str_repeat('b', 40)));
    }

    public function testCompletedImportWithErrorsIsNotReportedAsSuccess(): void
    {
        $client = $this->clientWith([$this->json(['status' => 'COMPLETE', 'itemCount' => 2, 'itemCountError' => 1])]);
        try {
            $client->paymentImportStatus($this->credentials(), str_repeat('b', 40));
            self::fail('Partial import expected.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::PAYMENT_REJECTED, $e->errorCode);
            self::assertTrue($e->ambiguousPaymentOutcome);
            self::assertSame(1, $e->acceptedCount);
            self::assertSame(1, $e->rejectedCount);
        }
    }

    /**
     * @param list<Response|ConnectException> $responses
     * @param array<int,array{request:\Psr\Http\Message\RequestInterface,options:array<mixed>}> $history
     */
    private function clientWith(array $responses, array &$history = []): CreditasPremiumClient
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::tap(static function (\Psr\Http\Message\RequestInterface $request, array $options) use (&$history): void {
            $history[] = ['request' => $request, 'options' => $options];
        }));
        return new CreditasPremiumClient(new Client(['handler' => $handler]));
    }

    /** @return array{bearer_token:string,curl_options:array<int,string>} */
    private function credentials(): array
    {
        return ['bearer_token' => str_repeat('A', 64), 'curl_options' => [
            CURLOPT_SSLCERT_BLOB => 'synthetic-certificate',
            CURLOPT_SSLKEY_BLOB => 'synthetic-private-key',
        ]];
    }

    /** @return array<string,mixed> */
    private function transaction(string $id, string $amount): array
    {
        return [
            'transactionId' => $id, 'category' => 'DOMESTIC', 'type' => 'CREDIT', 'code' => '76',
            'amount' => ['value' => $amount, 'currency' => 'CZK'], 'effectiveDate' => '2026-09-06',
            'rawExtension' => ['preserved' => true],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload): Response
    {
        return new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function body(\Psr\Http\Message\RequestInterface $request): array
    {
        $data = json_decode((string) $request->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /** @return array{string,string} */
    private function range(): array
    {
        $today = new \DateTimeImmutable('today');
        return [$today->modify('-2 days')->format('Y-m-d'), $today->format('Y-m-d')];
    }

    private function abo(): string
    {
        return (new AboPaymentOrderWriter())->build([
            'client_name' => 'Synthetic Company', 'payer_account_number' => '1000000005', 'payer_bank_code' => '2250',
            'payment_date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'items' => [[
                'account_number' => '2000000018', 'bank_code' => '0100', 'amount_minor' => 12345,
                'variable_symbol' => '202600001', 'message' => 'Synthetic payment',
            ]],
        ]);
    }
}
