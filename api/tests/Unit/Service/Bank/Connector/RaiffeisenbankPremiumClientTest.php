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
use MyInvoice\Service\Bank\Connector\RaiffeisenbankPremiumClient;
use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use PHPUnit\Framework\TestCase;

final class RaiffeisenbankPremiumClientTest extends TestCase
{
    private const ACCOUNT = '1000000005';

    public function testAccountUsesOfficialEndpointHeadersAndHardenedTransport(): void
    {
        $history = [];
        $payload = $this->accountPayload();
        $client = $this->clientWith([
            $this->jsonResponse($payload),
        ], $history);

        $result = $client->account($this->credentials(), self::ACCOUNT);

        self::assertSame($payload, $result);
        self::assertSame(
            'https://api.rb.cz/rbcz/premium/api/accounts/' . self::ACCOUNT . '/balance',
            (string) $history[0]['request']->getUri(),
        );
        self::assertSame('synthetic-client-id', $history[0]['request']->getHeaderLine('X-IBM-Client-Id'));
        self::assertMatchesRegularExpression('/^myucto-[a-f0-9]{32}$/D', $history[0]['request']->getHeaderLine('X-Request-Id'));
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertTrue($history[0]['options']['verify']);
        self::assertFalse($history[0]['options']['debug']);
        self::assertFalse($history[0]['options']['stream']);
        self::assertInstanceOf(\Psr\Http\Message\StreamInterface::class, $history[0]['options']['sink']);
        self::assertIsCallable($history[0]['options']['on_headers']);
        self::assertIsCallable($history[0]['options']['progress']);
        self::assertSame($this->credentials()['curl_options'], $history[0]['options']['curl']);
        try {
            $history[0]['options']['sink']->write(str_repeat('x', 10 * 1024 * 1024));
            self::fail('Bounded response sink should reject an oversized write.');
        } catch (\RuntimeException $e) {
            self::assertSame('Response size limit exceeded.', $e->getMessage());
        }
    }

    public function testAccountRejectsMismatchedAccountIdentity(): void
    {
        $payload = $this->accountPayload();
        $payload['numberPart2'] = '2000000018';
        $client = $this->clientWith([$this->jsonResponse($payload)]);

        try {
            $client->account($this->credentials(), self::ACCOUNT);
            self::fail('Mismatched account should be rejected.');
        } catch (BankConnectorException $e) {
            self::assertSame(RaiffeisenbankPremiumClient::ERROR_ACCOUNT_MISMATCH, $e->errorCode);
            self::assertFalse($e->ambiguousPaymentOutcome);
        }
    }

    public function testAccountRejectsBalanceCurrencyOutsideItsFolder(): void
    {
        $payload = $this->accountPayload();
        $payload['currencyFolders'][0]['balances'][0]['currency'] = 'EUR';
        $client = $this->clientWith([$this->jsonResponse($payload)]);

        try {
            $client->account($this->credentials(), self::ACCOUNT);
            self::fail('Mismatched balance currency should be rejected.');
        } catch (BankConnectorException $e) {
            self::assertSame(RaiffeisenbankPremiumClient::ERROR_CURRENCY_MISMATCH, $e->errorCode);
        }
    }

    public function testTransactionsReturnsCompleteRawListAcrossAllPages(): void
    {
        $history = [];
        $first = $this->transaction('tx-1', 'CZK', ['rawExtension' => ['keep' => true]]);
        $second = $this->transaction('tx-2', 'CZK', ['bookingDate' => '2026-09-07T12:00:00+02:00']);
        $client = $this->clientWith([
            $this->jsonResponse(['lastPage' => false, 'transactions' => [$first]]),
            $this->jsonResponse(['lastPage' => true, 'transactions' => [$second]]),
        ], $history);
        [$from, $to] = $this->recentRange();

        $result = $client->transactions($this->credentials(), self::ACCOUNT, 'CZK', $from, $to);

        self::assertSame([$first, $second], $result);
        self::assertCount(2, $history);
        foreach ($history as $index => $transaction) {
            self::assertSame(
                'https://api.rb.cz/rbcz/premium/api/accounts/' . self::ACCOUNT . '/CZK/transactions',
                $transaction['request']->getUri()->withQuery('')->__toString(),
            );
            parse_str($transaction['request']->getUri()->getQuery(), $query);
            self::assertSame(['from' => $from, 'to' => $to, 'page' => (string) ($index + 1)], $query);
        }
        self::assertNotSame(
            $history[0]['request']->getHeaderLine('X-Request-Id'),
            $history[1]['request']->getHeaderLine('X-Request-Id'),
        );
    }

    public function testTransactionsTreatsDocumented204AsEmptyEndPage(): void
    {
        $client = $this->clientWith([new Response(204)]);
        [$from, $to] = $this->recentRange();

        self::assertSame([], $client->transactions(
            $this->credentials(),
            self::ACCOUNT,
            'CZK',
            $from,
            $to,
        ));
    }

    public function testTransactionsRejectsWrongAmountCurrency(): void
    {
        $client = $this->clientWith([$this->jsonResponse([
            'lastPage' => true,
            'transactions' => [$this->transaction('tx-1', 'EUR')],
        ])]);
        [$from, $to] = $this->recentRange();

        try {
            $client->transactions($this->credentials(), self::ACCOUNT, 'CZK', $from, $to);
            self::fail('Wrong transaction currency should be rejected.');
        } catch (BankConnectorException $e) {
            self::assertSame(RaiffeisenbankPremiumClient::ERROR_CURRENCY_MISMATCH, $e->errorCode);
        }
    }

    public function testTransactionsRejectsDuplicateReferenceAcrossPages(): void
    {
        $transaction = $this->transaction('same-reference', 'CZK');
        $client = $this->clientWith([
            $this->jsonResponse(['lastPage' => false, 'transactions' => [$transaction]]),
            $this->jsonResponse(['lastPage' => true, 'transactions' => [$transaction]]),
        ]);
        [$from, $to] = $this->recentRange();

        $this->expectException(BankConnectorException::class);
        $this->expectExceptionMessage('duplicitní transakci');
        $client->transactions($this->credentials(), self::ACCOUNT, 'CZK', $from, $to);
    }

    public function testTransactionsRejectsMalformedPageSchema(): void
    {
        $client = $this->clientWith([$this->jsonResponse([
            'transactions' => [$this->transaction('tx-1', 'CZK')],
        ])]);
        [$from, $to] = $this->recentRange();

        $this->expectException(BankConnectorException::class);
        $this->expectExceptionMessage('neplatnou stránku');
        $client->transactions($this->credentials(), self::ACCOUNT, 'CZK', $from, $to);
    }

    public function testTransactionsStopsAtConfiguredPageBound(): void
    {
        $responses = [];
        for ($page = 1; $page <= 100; ++$page) {
            $responses[] = $this->jsonResponse([
                'lastPage' => false,
                'transactions' => [$this->transaction('tx-' . $page, 'CZK')],
            ]);
        }
        $history = [];
        $client = $this->clientWith($responses, $history);
        [$from, $to] = $this->recentRange();

        try {
            $client->transactions($this->credentials(), self::ACCOUNT, 'CZK', $from, $to);
            self::fail('Pagination bound should be enforced.');
        } catch (BankConnectorException $e) {
            self::assertSame(RaiffeisenbankPremiumClient::ERROR_PAGINATION_LIMIT, $e->errorCode);
            self::assertCount(100, $history);
        }
    }

    public function testRejectsInvalidTransactionInputsBeforeHttpCall(): void
    {
        $history = [];
        $client = $this->clientWith([], $history);
        $today = new \DateTimeImmutable('today');
        $cases = [
            ['0001', 'CZK', $today->format('Y-m-d'), $today->format('Y-m-d'), RaiffeisenbankPremiumClient::ERROR_INVALID_ACCOUNT],
            [self::ACCOUNT, 'czk', $today->format('Y-m-d'), $today->format('Y-m-d'), RaiffeisenbankPremiumClient::ERROR_INVALID_CURRENCY],
            [self::ACCOUNT, 'CZK', '2026-02-30', $today->format('Y-m-d'), BankConnectorException::INVALID_DATE],
            [self::ACCOUNT, 'CZK', $today->format('Y-m-d'), $today->modify('+1 day')->format('Y-m-d'), BankConnectorException::INVALID_DATE_RANGE],
            [self::ACCOUNT, 'CZK', $today->modify('-91 days')->format('Y-m-d'), $today->format('Y-m-d'), BankConnectorException::HISTORY_LOCKED],
        ];

        foreach ($cases as [$account, $currency, $from, $to, $expected]) {
            try {
                $client->transactions($this->credentials(), $account, $currency, $from, $to);
                self::fail('Invalid input should be rejected.');
            } catch (BankConnectorException $e) {
                self::assertSame($expected, $e->errorCode);
            }
        }
        self::assertSame([], $history);
    }

    public function testRejectsUnsafeOrIncompleteCurlOptionsWithoutLeakingThem(): void
    {
        $history = [];
        $client = $this->clientWith([], $history);
        $secret = 'synthetic-private-key-marker';
        $credentials = $this->credentials();
        $credentials['curl_options'][CURLOPT_SSL_VERIFYPEER] = '0';
        $credentials['curl_options'][CURLOPT_SSLKEY_BLOB] = $secret;

        try {
            $client->account($credentials, self::ACCOUNT);
            self::fail('Unsafe cURL option should be rejected.');
        } catch (BankConnectorException $e) {
            self::assertSame(RaiffeisenbankPremiumClient::ERROR_INVALID_CREDENTIALS, $e->errorCode);
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        self::assertSame([], $history);
    }

    public function testSubmitBatchPostsWriterBytesAndReturnsAwaitingAuthorizationReference(): void
    {
        $history = [];
        $client = $this->clientWith([$this->jsonResponse(['batchFileId' => 200])], $history);
        $abo = $this->abo();

        $result = $client->submitBatch($this->credentials(), $abo);

        self::assertSame(['accepted' => true, 'reference' => '200'], $result);
        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertSame(
            'https://api.rb.cz/rbcz/premium/api/payments/batches',
            (string) $history[0]['request']->getUri(),
        );
        self::assertSame('text/plain', $history[0]['request']->getHeaderLine('Content-Type'));
        self::assertSame('ABO-KPC', $history[0]['request']->getHeaderLine('Batch-Import-Format'));
        self::assertSame('false', $history[0]['request']->getHeaderLine('Batch-Autocorrect'));
        self::assertSame($abo, (string) $history[0]['request']->getBody());
    }

    public function testDuplicateBatchRejectionIsAmbiguousAndSanitized(): void
    {
        $raw = '{"error":"BATCH_ALREADY_IMPORTED","error_description":"private raw detail"}';
        $client = $this->clientWith([new Response(400, ['Content-Type' => 'application/json'], $raw)]);

        try {
            $client->submitBatch($this->credentials(), $this->abo());
            self::fail('Duplicate batch should be rejected.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::PAYMENT_REJECTED, $e->errorCode);
            self::assertTrue($e->ambiguousPaymentOutcome);
            self::assertSame(400, $e->remoteHttpStatus);
            self::assertStringNotContainsString('private raw detail', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testSubmitTransportFailureIsAmbiguousAndNeverRetried(): void
    {
        $history = [];
        $client = $this->clientWith([
            new ConnectException('synthetic private transport detail', new Request('POST', 'https://api.rb.cz')),
            $this->jsonResponse(['batchFileId' => 201]),
        ], $history);

        try {
            $client->submitBatch($this->credentials(), $this->abo());
            self::fail('Transport failure expected.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::REMOTE_UNAVAILABLE, $e->errorCode);
            self::assertTrue($e->ambiguousPaymentOutcome);
            self::assertStringNotContainsString('private transport detail', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        self::assertCount(1, $history);
    }

    public function testInvalidSuccessResponseLeavesBatchOutcomeAmbiguous(): void
    {
        $client = $this->clientWith([$this->jsonResponse(['batchFileId' => '200'])]);

        try {
            $client->submitBatch($this->credentials(), $this->abo());
            self::fail('Invalid success response expected.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::INVALID_RESPONSE, $e->errorCode);
            self::assertTrue($e->ambiguousPaymentOutcome);
        }
    }

    /**
     * @param list<Response|ConnectException> $responses
     * @param array<int,array{request:\Psr\Http\Message\RequestInterface,options:array<mixed>}> $history
     */
    private function clientWith(array $responses, array &$history = []): RaiffeisenbankPremiumClient
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::tap(
            static function (\Psr\Http\Message\RequestInterface $request, array $options) use (&$history): void {
                $history[] = ['request' => $request, 'options' => $options];
            },
        ));
        return new RaiffeisenbankPremiumClient(new Client(['handler' => $handler]));
    }

    /** @return array{client_id:string,curl_options:array<int,string>} */
    private function credentials(): array
    {
        return [
            'client_id' => 'synthetic-client-id',
            'curl_options' => [
                CURLOPT_SSLCERT_BLOB => 'synthetic-certificate-blob',
                CURLOPT_SSLKEY_BLOB => 'synthetic-private-key-blob',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function accountPayload(): array
    {
        return [
            'numberPart1' => '19',
            'numberPart2' => self::ACCOUNT,
            'bankCode' => '5500',
            'currencyFolders' => [[
                'currency' => 'CZK',
                'status' => 'ACTIVE',
                'balances' => [[
                    'balanceType' => 'CLAB',
                    'currency' => 'CZK',
                    'value' => 1234.50,
                ]],
            ]],
        ];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function transaction(string $reference, string $currency, array $extra = []): array
    {
        return array_merge([
            'entryReference' => $reference,
            'amount' => ['value' => -123.45, 'currency' => $currency],
            'creditDebitIndication' => 'DBIT',
            'bankTransactionCode' => ['code' => '10000401000'],
        ], $extra);
    }

    /** @param array<string,mixed> $payload */
    private function jsonResponse(array $payload): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{string,string} */
    private function recentRange(): array
    {
        $today = new \DateTimeImmutable('today');
        return [$today->modify('-2 days')->format('Y-m-d'), $today->format('Y-m-d')];
    }

    private function abo(): string
    {
        return (new AboPaymentOrderWriter())->build([
            'client_name' => 'Synthetic Company',
            'payer_account_number' => self::ACCOUNT,
            'payer_bank_code' => '5500',
            'payment_date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'items' => [[
                'account_number' => '2000000018',
                'bank_code' => '0100',
                'amount_minor' => 12345,
                'variable_symbol' => '202600001',
                'message' => 'Synthetic payment',
            ]],
        ]);
    }
}
