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
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\BankConnectorRegistry;
use MyInvoice\Service\Bank\Connector\MonetaAboBatchMapper;
use MyInvoice\Service\Bank\Connector\MonetaApiClient;
use MyInvoice\Service\Bank\Connector\MonetaBankConnector;
use MyInvoice\Service\Bank\Connector\MonetaTransactionParser;
use MyInvoice\Service\Bank\Connector\StructuredBankConnector;
use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonetaBankConnectorTest extends TestCase
{
    private const TOKEN = '00000000-1111-2222-3333-444444444444';
    private const IBAN = 'CZ3106000000001000000005';
    private const OTHER_IBAN = 'CZ2306000000002000000018';

    public function testCatalogOffersStatementsAndPaymentsForMonetaBankCode(): void
    {
        $registry = new BankConnectorRegistry([$this->connector([])]);
        $entry = array_values(array_filter($registry->catalog(), static fn (array $row): bool => $row['code'] === 'moneta'))[0];

        self::assertTrue($entry['implemented']);
        self::assertSame(['0600'], $entry['bank_codes']);
        self::assertTrue($entry['capabilities']['statement_import']);
        self::assertTrue($entry['capabilities']['payment_order_submission']);
        self::assertTrue($registry->supportsBankCode('moneta', '0600'));
        self::assertFalse($registry->supportsBankCode('moneta', '2250'));
        self::assertInstanceOf(StructuredBankConnector::class, $this->connector([]));
    }

    public function testCredentialsBindRawTokenToConfiguredAccountAndSurviveResave(): void
    {
        $connector = $this->connector([]);
        $account = ['account_number' => '1000000005', 'iban' => '', 'account_code' => 'CZK'];

        $stored = $connector->credentials([], $account, self::TOKEN);

        self::assertSame(['token' => self::TOKEN, 'iban' => self::IBAN, 'currency' => 'CZK'], json_decode($stored, true));
        self::assertSame($stored, $connector->credentials([], $account, $stored));
    }

    public function testCredentialsRejectAccountOfAnotherBank(): void
    {
        $this->expectException(BankConnectorOperationException::class);
        $this->expectExceptionMessage('provider_account_mismatch');

        $this->connector([])->credentials([], ['account_number' => '2000000018/0100', 'iban' => 'CZ1001000000002000000018', 'account_code' => 'CZK'], self::TOKEN);
    }

    public function testCredentialsRejectMalformedToken(): void
    {
        $this->expectException(BankConnectorOperationException::class);
        $this->expectExceptionMessage('token_invalid');

        $this->connector([])->credentials([], ['account_number' => '1000000005', 'account_code' => 'CZK'], 'short token');
    }

    public function testDownloadsTransactionsOfMatchingAccountOnlyAndParsesThem(): void
    {
        $history = [];
        $connector = $this->connector([
            $this->json(['accounts' => [
                ['id' => 'other', 'identification' => ['iban' => self::OTHER_IBAN], 'currency' => 'CZK'],
                ['id' => 'eur', 'identification' => ['iban' => self::IBAN], 'currency' => 'EUR'],
                ['id' => '0001000000005', 'identification' => ['iban' => self::IBAN, 'other' => '1000000005/0600'], 'currency' => 'CZK'],
            ], 'pageCount' => 1, 'pageNumber' => 0, 'pageSize' => 100]),
            $this->json(['transactions' => [
                $this->incoming(),
                $this->outgoing(),
                ['status' => 'PDNG', 'entryReference' => 'blocked', 'amount' => ['value' => 99, 'currency' => 'CZK'], 'creditDebitIndicator' => 'DBIT'],
            ], 'pageCount' => 1, 'pageNumber' => 0, 'pageSize' => 100]),
        ], $history);

        $content = $connector->downloadStatement($this->credentials(), '2026-09-01', '2026-09-22');
        $parsed = $connector->parseStatement($content);

        self::assertCount(2, $history);
        self::assertSame('https://api.moneta.cz/api/v4/vip/aisp/my/accounts?size=100&page=0', (string) $history[0]['request']->getUri());
        self::assertSame(
            'https://api.moneta.cz/api/v4/vip/aisp/my/accounts/0001000000005/transactions?fromDate=2026-09-01&toDate=2026-09-22&size=100&page=0',
            (string) $history[1]['request']->getUri(),
        );
        foreach ($history as $call) {
            self::assertSame('Bearer ' . self::TOKEN, $call['request']->getHeaderLine('Authorization'));
            self::assertFalse($call['options']['allow_redirects']);
            self::assertTrue($call['options']['verify']);
        }

        self::assertSame(self::IBAN, $parsed['header']['account_number']);
        self::assertCount(2, $parsed['transactions']);
        [$in, $out] = $parsed['transactions'];
        self::assertSame('2026-09-18', $in['posted_at']);
        self::assertSame(10000.0, (float) $in['amount']);
        self::assertSame('19', $in['counterparty_account']);
        self::assertSame('2250', $in['counterparty_bank']);
        self::assertSame('Synthetic Payer s.r.o.', $in['counterparty_name']);
        self::assertSame('20260001', $in['variable_symbol']);
        self::assertNull($in['constant_symbol']);
        self::assertSame('Synthetic incoming', $in['description']);
        self::assertStringStartsWith('moneta:', $in['bank_ref']);
        self::assertLessThanOrEqual(40, strlen($in['bank_ref']));

        self::assertSame(-1234.5, (float) $out['amount']);
        self::assertSame('35-2000000018', $out['counterparty_account']);
        self::assertSame('0100', $out['counterparty_bank']);
        self::assertSame('308', $out['constant_symbol']);
        self::assertSame('77', $out['specific_symbol']);
    }

    /** OpenAPI MONETA zapisuje symboly odděleně mezerou: `VS:{…} KS:{…} SS:{…}`. */
    public function testSymbolsSeparatedBySpacesAreRead(): void
    {
        $row = $this->outgoing();
        $row['entryDetails']['transactionDetails']['remittanceInformation']['structured']['creditorReferenceInformation']['reference'] = 'VS:5 KS:0308 SS:77';
        $history = [];
        $connector = $this->connector([
            $this->account(),
            $this->json(['transactions' => [$row], 'pageCount' => 1, 'pageNumber' => 0, 'pageSize' => 100]),
        ], $history);

        $parsed = $connector->parseStatement($connector->downloadStatement($this->credentials(), '2026-09-01', '2026-09-22'));

        $tx = $parsed['transactions'][0];
        self::assertSame('5', $tx['variable_symbol']);
        self::assertSame('308', $tx['constant_symbol']);
        self::assertSame('77', $tx['specific_symbol']);
    }

    public function testTokenWithoutAccessToConfiguredAccountNeverReadsTransactions(): void
    {
        $history = [];
        $connector = $this->connector([
            $this->json(['accounts' => [['id' => 'other', 'identification' => ['iban' => self::OTHER_IBAN], 'currency' => 'CZK']], 'pageCount' => 1]),
        ], $history);

        try {
            $connector->downloadStatement($this->credentials(), '2026-09-01', '2026-09-22');
            self::fail('Expected account mismatch.');
        } catch (BankConnectorException $e) {
            self::assertSame('statement_account_mismatch', $e->errorCode);
        }
        self::assertCount(1, $history);
    }

    public function testFollowsPagination(): void
    {
        $history = [];
        $connector = $this->connector([
            $this->account(),
            $this->json(['transactions' => [$this->incoming()], 'pageCount' => 2, 'pageNumber' => 0, 'nextPage' => 1]),
            $this->json(['transactions' => [$this->outgoing()], 'pageCount' => 2, 'pageNumber' => 1]),
        ], $history);

        $parsed = $connector->parseStatement($connector->downloadStatement($this->credentials(), '2026-09-01', '2026-09-22'));

        self::assertCount(2, $parsed['transactions']);
        self::assertStringEndsWith('page=1', (string) $history[2]['request']->getUri());
    }

    /** @return iterable<string,array{Response,string}> */
    public static function errorProvider(): iterable
    {
        yield 'expired token' => [new Response(401, [], '{"errors":[{"error":"UNAUTHORISED","message":"The OAuth 2.0 token is invalid or missing"}]}'), BankConnectorException::INVALID_TOKEN];
        yield 'two factor needed' => [new Response(401, [], '{"errors":[{"error":"NARR"}]}'), BankConnectorException::HISTORY_LOCKED];
        yield 'rate limit' => [new Response(429, [], '{"errors":[{"error":"TOO_MANY"}]}'), BankConnectorException::RATE_LIMITED];
        yield 'outage' => [new Response(503, [], 'down'), BankConnectorException::REMOTE_UNAVAILABLE];
        yield 'html instead of json' => [new Response(200, [], '<html></html>'), BankConnectorException::INVALID_RESPONSE];
    }

    #[DataProvider('errorProvider')]
    public function testMapsBankErrorsToConnectorCodes(Response $response, string $code): void
    {
        try {
            $this->connector([$this->account(), $response])->downloadStatement($this->credentials(), '2026-09-01', '2026-09-22');
            self::fail('Expected connector exception.');
        } catch (BankConnectorException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testRejectsMismatchedCurrencyInTransactions(): void
    {
        $row = $this->incoming();
        $row['amount']['currency'] = 'EUR';

        $this->expectException(BankConnectorException::class);
        $this->connector([$this->account(), $this->json(['transactions' => [$row], 'pageCount' => 1])])
            ->downloadStatement($this->credentials(), '2026-09-01', '2026-09-22');
    }

    public function testSubmitsAboAsDomesticBatchAndReturnsBatchId(): void
    {
        $history = [];
        $connector = $this->connector([
            $this->account(),
            $this->json(['batchId' => 'synthetic-batch_1']),
        ], $history);

        $result = $connector->submitPaymentOrder($this->credentials(), $this->abo());

        self::assertSame(['accepted' => true, 'reference' => 'synthetic-batch_1'], $result);
        $request = $history[1]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.moneta.cz/api/v4/vip/pisp/my/payments/batch', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $body = json_decode((string) $request->getBody(), true);
        self::assertCount(2, $body['payments']);
        $first = $body['payments'][0];
        self::assertSame('1000000005/0600', $first['debtorAccount']['identification']['other']['identification']);
        self::assertSame('35-2000000018/0100', $first['creditorAccount']['identification']['other']['identification']);
        self::assertSame(['value' => 123.45, 'currency' => 'CZK'], $first['amount']['instructedAmount']);
        self::assertSame('2026-09-30', $first['requestedExecutionDate']);
        self::assertSame('VS:202600001,KS:0308,SS:77', $first['remittanceInformation']['structured']['creditorReferenceInformation']['reference']);
        self::assertSame('Synthetic payment', $first['remittanceInformation']['unstructured']);
        self::assertLessThanOrEqual(35, strlen($first['paymentIdentification']['instructionIdentification']));
        self::assertSame('VS:5', $body['payments'][1]['remittanceInformation']['structured']['creditorReferenceInformation']['reference']);
        self::assertNotSame(
            $first['paymentIdentification']['instructionIdentification'],
            $body['payments'][1]['paymentIdentification']['instructionIdentification'],
        );
    }

    /** #393: částka jde do JSON přesně i při serialize_precision=17 (některé hostingy). */
    public function testAmountKeepsTwoDecimalsWithHighSerializePrecision(): void
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');
        try {
            $history = [];
            $this->connector([$this->account(), $this->json(['batchId' => 'synthetic-batch_1'])], $history)
                ->submitPaymentOrder($this->credentials(), $this->abo(firstAmountMinor: 123456));
            $body = (string) $history[1]['request']->getBody();
            self::assertStringContainsString('"instructedAmount":{"value":1234.56,"currency":"CZK"}', $body);
            self::assertStringContainsString('"instructedAmount":{"value":5.0,"currency":"CZK"}', $body, 'Celé koruny jako dosud s nulou za tečkou.');
        } finally {
            ini_set('serialize_precision', (string) $previous);
        }
    }

    public function testValidationRejectionIsNotAmbiguous(): void
    {
        try {
            $this->connector([
                $this->account(),
                new Response(400, [], '{"errors":[{"error":"REC_SEND","message":"Cílový účet nesmí být účet plátce."}]}'),
            ])->submitPaymentOrder($this->credentials(), $this->abo());
            self::fail('Expected rejection.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::PAYMENT_REJECTED, $e->errorCode);
            self::assertFalse($e->ambiguousPaymentOutcome);
        }
    }

    /** @return iterable<string,array{Response|ConnectException}> */
    public static function ambiguousProvider(): iterable
    {
        yield 'server error' => [new Response(500, [], 'error')];
        yield 'transport failure' => [new ConnectException('timeout', new Request('POST', 'https://api.moneta.cz'))];
        yield 'missing batch id' => [new Response(200, [], '{}')];
    }

    #[DataProvider('ambiguousProvider')]
    public function testUnclearOutcomeAfterSendingBatchIsAmbiguous(Response|ConnectException $response): void
    {
        try {
            $this->connector([$this->account(), $response])->submitPaymentOrder($this->credentials(), $this->abo());
            self::fail('Expected exception.');
        } catch (BankConnectorException $e) {
            self::assertTrue($e->ambiguousPaymentOutcome);
        }
    }

    public function testAboOfDifferentPayerIsRejectedBeforeAnyNetworkCall(): void
    {
        $history = [];
        $abo = $this->abo('2000000018');

        try {
            $this->connector([], $history)->submitPaymentOrder($this->credentials(), $abo);
            self::fail('Expected invalid payment order.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::INVALID_PAYMENT_ORDER, $e->errorCode);
        }
        self::assertSame([], $history);
    }

    public function testMapperRejectsAboForAnotherBank(): void
    {
        $abo = (new AboPaymentOrderWriter())->build([
            'client_name' => 'Synthetic Company',
            'payer_account_number' => '1000000005',
            'payer_bank_code' => '0100',
            'payment_date' => '2026-09-30',
            'items' => [['account_number' => '2000000018', 'bank_code' => '0300', 'amount_minor' => 100, 'variable_symbol' => '1']],
        ]);

        $this->expectException(BankConnectorException::class);
        (new MonetaAboBatchMapper())->map($abo, self::IBAN);
    }

    private function abo(string $payer = '1000000005', int $firstAmountMinor = 12345): string
    {
        return (new AboPaymentOrderWriter())->build([
            'client_name' => 'Synthetic Company',
            'payer_account_number' => $payer,
            'payer_bank_code' => '0600',
            'payment_date' => '2026-09-30',
            'items' => [
                [
                    'account_number' => '35-2000000018',
                    'bank_code' => '0100',
                    'amount_minor' => $firstAmountMinor,
                    'variable_symbol' => '202600001',
                    'constant_symbol' => '0308',
                    'specific_symbol' => '77',
                    'message' => 'Synthetic payment',
                ],
                [
                    'account_number' => '19',
                    'bank_code' => '2250',
                    'amount_minor' => 500,
                    'variable_symbol' => '5',
                ],
            ],
        ]);
    }

    private function credentials(): string
    {
        return json_encode(['token' => self::TOKEN, 'iban' => self::IBAN, 'currency' => 'CZK'], JSON_THROW_ON_ERROR);
    }

    private function account(): Response
    {
        return $this->json(['accounts' => [['id' => '0001000000005', 'identification' => ['iban' => self::IBAN], 'currency' => 'CZK']], 'pageCount' => 1]);
    }

    /** @return array<string,mixed> */
    private function incoming(): array
    {
        return [
            'amount' => ['currency' => 'CZK', 'value' => 10000],
            'bankTransactionCode' => ['proprietary' => ['code' => '100', 'issuer' => 'CNB']],
            'bookingDate' => ['date' => '2026-09-18'],
            'creditDebitIndicator' => 'CRDT',
            'enteredDate' => ['date' => null],
            'entryDetails' => ['transactionDetails' => [
                'references' => ['clearingSystemReference' => 'PAYMENT_ORDER_DOMESTIC', 'endToEndIdentification' => '1', 'transactionDescription' => 'OKAMŽITÁ ÚHRADA'],
                'relatedParties' => [
                    'creditorAccount' => ['identification' => ['other' => ['identification' => '1000000005/0600']]],
                    'debtor' => ['name' => 'Synthetic Payer s.r.o.'],
                    'debtorAccount' => ['identification' => ['other' => ['identification' => '0 0000000019/2250']]],
                ],
                'remittanceInformation' => [
                    'structured' => ['creditorReferenceInformation' => ['reference' => 'VS:0020260001']],
                    'unstructured' => 'Synthetic incoming',
                ],
            ]],
            'entryReference' => '0001000000005:20260918:00001:0000000000000000001',
            'reversalIndicator' => false,
            'status' => 'BOOK',
            'valueDate' => ['date' => '2026-09-18'],
        ];
    }

    /** @return array<string,mixed> */
    private function outgoing(): array
    {
        return [
            'amount' => ['currency' => 'CZK', 'value' => 1234.5],
            'bookingDate' => ['date' => '2026-09-19'],
            'creditDebitIndicator' => 'DBIT',
            'entryDetails' => ['transactionDetails' => [
                'relatedParties' => [
                    'creditor' => ['name' => 'Synthetic Supplier'],
                    'creditorAccount' => ['identification' => ['iban' => 'CZ7101000000352000000018']],
                ],
                'remittanceInformation' => ['structured' => ['creditorReferenceInformation' => ['reference' => 'VS:5,KS:0308,SS:77']]],
            ]],
            'entryReference' => '0001000000005:20260919:00002:0000000000000000002',
            'status' => 'BOOK',
            'valueDate' => ['date' => '2026-09-19'],
        ];
    }

    /** @param array<string,mixed> $data */
    private function json(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param list<Response|ConnectException> $responses
     * @param array<int,array<string,mixed>> $history
     * @param-out array<int,array<string,mixed>> $history
     */
    private function connector(array $responses, array &$history = []): MonetaBankConnector
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($history));

        return new MonetaBankConnector(
            new MonetaApiClient(new Client(['handler' => $handler])),
            new MonetaTransactionParser(),
            new MonetaAboBatchMapper(),
        );
    }
}
