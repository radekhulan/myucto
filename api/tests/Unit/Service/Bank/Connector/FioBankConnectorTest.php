<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\FioBankConnector;
use MyInvoice\Service\Bank\Connector\StructuredBankConnector;
use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FioBankConnectorTest extends TestCase
{
    private const TOKEN = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    public function testImplementsStructuredContractAndIdentifiesProvider(): void
    {
        $connector = $this->connectorWith(new Response(200, [], $this->xml()));

        self::assertInstanceOf(StructuredBankConnector::class, $connector);
        self::assertSame('fio', $connector->provider());
        self::assertSame('json', $connector->statementFormat());
    }

    public function testDownloadsFioXmlFromPeriodsEndpointWithHardenedOptions(): void
    {
        $history = [];
        $connector = $this->connectorWith(new Response(200, ['Content-Type' => 'text/xml'], $this->xml()), $history);

        $result = $connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31');

        self::assertCount(1, $history);
        self::assertSame('GET', $history[0]['request']->getMethod());
        self::assertSame(
            'https://fioapi.fio.cz/v1/rest/periods/' . self::TOKEN . '/2026-08-01/2026-08-31/transactions.xml',
            (string) $history[0]['request']->getUri(),
        );
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertTrue($history[0]['options']['verify']);
        self::assertTrue($history[0]['options']['stream']);
        self::assertSame(5.0, $history[0]['options']['connect_timeout']);
        self::assertSame(30.0, $history[0]['options']['timeout']);
        self::assertStringNotContainsString('<Transaction>', $result, 'Ukládá se kompaktní JSON, ne syrové XML.');

        $parsed = $connector->parseStatement($result);
        self::assertSame('1000000005', $parsed['header']['account_number']);
        self::assertSame('CZK', $parsed['header']['currency']);
        self::assertSame('2026-08-31', $parsed['header']['statement_date']);
        self::assertSame(1500.0, $parsed['header']['curr_balance']);
        self::assertSame([
            'posted_at' => '2026-08-15',
            'amount' => 1250.5,
            'currency' => 'CZK',
            'variable_symbol' => '2026001',
            'constant_symbol' => '0308',
            'specific_symbol' => null,
            'counterparty_account' => '2000000018',
            'counterparty_bank' => '0100',
            'counterparty_name' => 'Synteticka firma',
            'card_last4' => null,
            'description' => 'Platba faktury | Faktura 2026001',
            'bank_ref' => '10000000001',
        ], $parsed['transactions'][0]);
    }

    /**
     * #222: GPC u zahraniční platby nese IBAN jen v 20znakovém poli názvu, ořízne ho,
     * a zprávu pro příjemce nenese vůbec. Z XML musí projít celé.
     */
    public function testForeignPaymentKeepsFullIbanNameAndMessage(): void
    {
        $iban = 'SK3112000000198742637541';
        $connector = $this->connectorWith(new Response(200, [], $this->xml($this->xmlTransaction('10000000002', [
            1 => '99.90', 14 => 'EUR', 2 => $iban, 10 => 'Synthetic Payer s.r.o.', 16 => 'Faktura 2026002',
            8 => 'Okamžitá příchozí platba', 26 => 'GIBASKBX',
        ]), 'EUR')));

        $tx = $connector->parseStatement($connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31'))['transactions'][0];

        self::assertSame($iban, $tx['counterparty_account']);
        self::assertNull($tx['counterparty_bank']);
        self::assertSame('Synthetic Payer s.r.o.', $tx['counterparty_name']);
        self::assertSame('Synthetic Payer s.r.o. | Faktura 2026002', $tx['description']);
        self::assertSame('EUR', $tx['currency']);
    }

    public function testCzechIbanCounterpartyIsStoredInDomesticForm(): void
    {
        $connector = $this->connectorWith(new Response(200, [], $this->xml($this->xmlTransaction('10000000003', [
            1 => '-10.00', 14 => 'CZK', 2 => 'CZ6508000000192000145399', 7 => 'Nákup: SYNTH SHOP, PK: 516844******1234',
        ]))));

        $tx = $connector->parseStatement($connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31'))['transactions'][0];

        self::assertSame('19-2000145399', $tx['counterparty_account']);
        self::assertSame('0800', $tx['counterparty_bank']);
        self::assertSame('1234', $tx['card_last4']);
    }

    public function testCredentialsKeepPlainTokenSoExistingConnectionsStayValid(): void
    {
        $connector = $this->connectorWith(new Response(200, [], $this->xml()));

        self::assertSame(self::TOKEN, $connector->credentials([], [], ' ' . self::TOKEN . ' '));
        foreach ([[[], null, 'token_required'], [[], 'short', 'token_invalid'], [['x' => 1], self::TOKEN, 'credential_format_invalid']] as [$input, $token, $code]) {
            try {
                $connector->credentials($input, [], $token);
                self::fail('Neplatné přihlašovací údaje.');
            } catch (BankConnectorOperationException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    #[DataProvider('invalidDateProvider')]
    public function testRejectsInvalidOrReversedDateRange(string $from, string $to, string $errorCode): void
    {
        $history = [];
        $connector = $this->connectorWith(new Response(200, [], $this->xml()), $history);

        try {
            $connector->downloadStatement(self::TOKEN, $from, $to);
            self::fail('Invalid date range should be rejected.');
        } catch (BankConnectorException $e) {
            self::assertSame($errorCode, $e->errorCode);
            self::assertFalse($e->ambiguousPaymentOutcome);
        }
        self::assertSame([], $history);
    }

    /**
     * @return iterable<string,array{string,string,string}>
     */
    public static function invalidDateProvider(): iterable
    {
        yield 'invalid calendar date' => ['2026-02-30', '2026-03-01', BankConnectorException::INVALID_DATE];
        yield 'non canonical date' => ['01.08.2026', '2026-08-31', BankConnectorException::INVALID_DATE];
        yield 'reversed range' => ['2026-09-01', '2026-08-31', BankConnectorException::INVALID_DATE_RANGE];
    }

    public function testRejectsMalformedTokenWithoutDisclosingIt(): void
    {
        $secret = 'token-that-must-not-appear';
        $connector = $this->connectorWith(new Response(200, [], $this->xml()));

        try {
            $connector->downloadStatement($secret, '2026-08-01', '2026-08-31');
            self::fail('Malformed token should be rejected.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::INVALID_TOKEN, $e->errorCode);
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testRejectsMalformedXmlInsteadOfPassingAnErrorPageToParser(): void
    {
        $connector = $this->connectorWith(new Response(200, [], '<html>synthetic error</html>'));

        $this->expectException(BankConnectorException::class);
        $this->expectExceptionMessage('neplatný výpis Fio XML');
        $connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31');
    }

    public function testLogsOnlyStructuralReasonForInvalidStatementResponse(): void
    {
        $raw = '<html>synthetic private response</html>';
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'fio_statement_failed',
            self::callback(static function (array $context) use ($raw): bool {
                $serialized = json_encode($context);
                return $context['stage'] === 'xml'
                    && $context['reason'] === 'xml_invalid'
                    && $context['http_status'] === 200
                    && $context['response_bytes'] === strlen($raw)
                    && !str_contains($serialized, $raw)
                    && !str_contains($serialized, self::TOKEN);
            }),
        );
        $http = new Client(['handler' => new MockHandler([new Response(200, [], $raw)])]);
        $connector = new FioBankConnector($http, $logger);

        $this->expectException(BankConnectorException::class);
        $connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31');
    }

    public function testReadsEntireChunkedStatementResponse(): void
    {
        $chunks = [substr($this->xml(), 0, 40), substr($this->xml(), 40)];
        $stream = new PumpStream(static function () use (&$chunks): ?string {
            return array_shift($chunks);
        });
        $connector = $this->connectorWith(new Response(200, [], $stream));

        self::assertSame(
            $this->connectorWith(new Response(200, [], $this->xml()))->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31'),
            $connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31'),
        );
    }

    public function testToleratesTransientEmptyReadsBeforeEndOfStream(): void
    {
        $raw = $this->xml($this->xmlTransaction('10000000004', [1 => '1.00']) . $this->xmlTransaction('10000000005', [1 => '2.00']));
        $inner = Utils::streamFor($raw);
        $empty = true;
        $stream = FnStream::decorate($inner, [
            'read' => static function (int $length) use ($inner, &$empty): string {
                $empty = !$empty;
                return $empty ? '' : $inner->read(min($length, 50));
            },
        ]);
        $connector = $this->connectorWith(new Response(200, ['Transfer-Encoding' => 'chunked'], $stream));

        self::assertCount(2, $connector->parseStatement($connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31'))['transactions']);
    }

    public function testLogsExactReadFailureReasonAndTransferHeaders(): void
    {
        $inner = Utils::streamFor($this->xml());
        $stream = FnStream::decorate($inner, [
            'read' => static fn (int $length): string => throw new \RuntimeException('Unable to read from stream'),
        ]);
        $logged = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'fio_statement_failed',
            self::callback(static function (array $context) use (&$logged): bool {
                $logged = $context;
                return true;
            }),
        );
        $http = new Client(['handler' => new MockHandler([new Response(200, [
            'Content-Type' => 'text/xml; charset=utf-8',
            'Transfer-Encoding' => 'chunked',
        ], $stream)])]);
        $connector = new FioBankConnector($http, $logger);

        try {
            $connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31');
            self::fail('Read failure expected.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::INVALID_RESPONSE, $e->errorCode);
        }
        self::assertSame('read', $logged['stage']);
        self::assertSame('stream_error', $logged['reason']);
        self::assertSame('unable_to_read', $logged['stream_error']);
        self::assertSame(0, $logged['response_bytes']);
        self::assertSame('text/xml', $logged['content_type']);
        self::assertSame('utf-8', $logged['content_charset']);
        self::assertSame('chunked', $logged['transfer_encoding']);
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($logged));
    }

    public function testLogsEmptyBodyAsDistinctReadFailure(): void
    {
        $logged = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'fio_statement_failed',
            self::callback(static function (array $context) use (&$logged): bool {
                $logged = $context;
                return true;
            }),
        );
        $http = new Client(['handler' => new MockHandler([new Response(200, ['Content-Length' => '0'], '')])]);
        $connector = new FioBankConnector($http, $logger);

        try {
            $connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31');
            self::fail('Empty body expected.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::INVALID_RESPONSE, $e->errorCode);
        }
        self::assertSame('read', $logged['stage']);
        self::assertSame('empty_body', $logged['reason']);
        self::assertSame(0, $logged['content_length']);
    }

    public function testRejectsDuplicateMovementIdEntitiesAndMissingAccount(): void
    {
        foreach ([
            $this->xml($this->xmlTransaction('10000000006', [1 => '1.00']) . $this->xmlTransaction('10000000006', [1 => '2.00'])),
            '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "x">]><AccountStatement/>',
            str_replace('<accountId>1000000005</accountId>', '', $this->xml()),
        ] as $invalid) {
            $connector = $this->connectorWith(new Response(200, [], $invalid));
            try {
                $connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31');
                self::fail('Inconsistent XML should be rejected.');
            } catch (BankConnectorException $e) {
                self::assertSame(BankConnectorException::INVALID_RESPONSE, $e->errorCode);
            }
        }
    }

    public function testMapsFioCooldownWithoutReturningRawResponse(): void
    {
        $rawError = 'raw bank error containing private detail';
        $connector = $this->connectorWith(new Response(409, [], $rawError));

        try {
            $connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31');
            self::fail('Cooldown response expected.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::RATE_LIMITED, $e->errorCode);
            self::assertSame(409, $e->remoteHttpStatus);
            self::assertStringNotContainsString($rawError, $e->getMessage());
        }
    }

    public function testPostsAboAsDocumentedMultipartAndReturnsBankReference(): void
    {
        $history = [];
        $connector = $this->connectorWith(new Response(200, ['Content-Type' => 'application/xml'], $this->successXml()), $history);

        $result = $connector->submitPaymentOrder(self::TOKEN, $this->abo());

        self::assertSame(['accepted' => true, 'reference' => '700001'], $result);
        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertSame('https://fioapi.fio.cz/v1/rest/import/', (string) $history[0]['request']->getUri());
        $contentType = $history[0]['request']->getHeaderLine('Content-Type');
        self::assertStringStartsWith('multipart/form-data; boundary=', $contentType);
        $body = (string) $history[0]['request']->getBody();
        self::assertStringContainsString('name="type"', $body);
        self::assertStringContainsString("\r\n\r\nabo\r\n", $body);
        self::assertStringContainsString('name="token"', $body);
        self::assertStringContainsString('name="file"; filename="payment-order.abo"', $body);
        self::assertStringContainsString($this->abo(), $body);
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertTrue($history[0]['options']['verify']);
    }

    public function testWarningResponseIsAcceptedBecauseFioDocumentsWarningsAsImported(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<responseImport><result><errorCode>2</errorCode><idInstruction>700002</idInstruction>'
            . '<status>warning</status></result><ordersDetails><detail id="1"><messages>'
            . '<message status="warning" errorCode="100">Synthetic warning</message>'
            . '</messages></detail></ordersDetails></responseImport>';
        $connector = $this->connectorWith(new Response(200, [], $xml));

        self::assertSame(
            ['accepted' => true, 'reference' => '700002'],
            $connector->submitPaymentOrder(self::TOKEN, $this->abo()),
        );
    }

    public function testUploadsWriterBytesWithoutTransformation(): void
    {
        $abo = (new AboPaymentOrderWriter())->build([
            'client_name' => 'Synthetic Company',
            'payer_account_number' => '1000000005',
            'payer_bank_code' => '2010',
            'payment_date' => '2026-09-07',
            'items' => [[
                'account_number' => '2000000018',
                'bank_code' => '0100',
                'amount_minor' => 12345,
                'variable_symbol' => '202600001',
                'message' => 'Synthetic payment',
            ]],
        ]);
        $history = [];
        $connector = $this->connectorWith(new Response(200, [], $this->successXml()), $history);

        $connector->submitPaymentOrder(self::TOKEN, $abo);

        self::assertMatchesRegularExpression('/^UHL1[^\r\n]+\r\n1 1501 /', $abo);
        self::assertStringContainsString($abo, (string) $history[0]['request']->getBody());
    }

    public function testAnyDetailErrorRejectsPartialResponseAndReportsSafeCounts(): void
    {
        $secretRawMessage = 'private rejected beneficiary';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<responseImport><result><errorCode>1</errorCode><idInstruction>700003</idInstruction>'
            . '<status>error</status></result><ordersDetails>'
            . '<detail id="1"><messages><message status="warning">Accepted with warning</message></messages></detail>'
            . '<detail id="2"><messages><message status="error" errorCode="2001">'
            . $secretRawMessage . '</message></messages></detail>'
            . '</ordersDetails></responseImport>';
        $connector = $this->connectorWith(new Response(200, [], $xml));

        try {
            $connector->submitPaymentOrder(self::TOKEN, $this->abo());
            self::fail('Partial rejection must not be reported as success.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::PAYMENT_REJECTED, $e->errorCode);
            self::assertTrue($e->ambiguousPaymentOutcome);
            self::assertSame(1, $e->acceptedCount);
            self::assertSame(1, $e->rejectedCount);
            self::assertStringNotContainsString($secretRawMessage, $e->getMessage());
        }
    }

    public function testMalformedSuccessResponseHasAmbiguousPaymentOutcome(): void
    {
        $connector = $this->connectorWith(new Response(200, [], '<not-fio>secret raw body</not-fio>'));

        try {
            $connector->submitPaymentOrder(self::TOKEN, $this->abo());
            self::fail('Malformed response expected.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::INVALID_RESPONSE, $e->errorCode);
            self::assertTrue($e->ambiguousPaymentOutcome);
            self::assertStringNotContainsString('secret raw body', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testUnknownDetailStatusIsAnAmbiguousInvalidResponse(): void
    {
        $xml = '<responseImport><result><errorCode>0</errorCode><idInstruction>700004</idInstruction>'
            . '<status>ok</status></result><ordersDetails><detail id="1"><messages>'
            . '<message status="unexpected">Synthetic</message>'
            . '</messages></detail></ordersDetails></responseImport>';
        $connector = $this->connectorWith(new Response(200, [], $xml));

        try {
            $connector->submitPaymentOrder(self::TOKEN, $this->abo());
            self::fail('Invalid status expected.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::INVALID_RESPONSE, $e->errorCode);
            self::assertTrue($e->ambiguousPaymentOutcome);
        }
    }

    public function testRejectsXmlEntitiesBeforeParsing(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE responseImport ['
            . '<!ENTITY xxe SYSTEM "https://invalid.example.test/private">]>'
            . '<responseImport><result><errorCode>0</errorCode><idInstruction>1</idInstruction>'
            . '<status>&xxe;</status></result></responseImport>';
        $connector = $this->connectorWith(new Response(200, [], $xml));

        try {
            $connector->submitPaymentOrder(self::TOKEN, $this->abo());
            self::fail('Entity-bearing XML must be rejected.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::INVALID_RESPONSE, $e->errorCode);
            self::assertTrue($e->ambiguousPaymentOutcome);
        }
    }

    public function testTransportFailureAfterPaymentRequestIsAmbiguousAndSanitized(): void
    {
        $request = new Request('POST', 'https://fioapi.fio.cz/v1/rest/import/');
        $connector = $this->connectorWith(new ConnectException(
            'Synthetic failure ' . self::TOKEN,
            $request,
        ));

        try {
            $connector->submitPaymentOrder(self::TOKEN, $this->abo());
            self::fail('Transport failure expected.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::REMOTE_UNAVAILABLE, $e->errorCode);
            self::assertTrue($e->ambiguousPaymentOutcome);
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    #[DataProvider('invalidAboProvider')]
    public function testRejectsInvalidAboBeforeSending(string $abo): void
    {
        $history = [];
        $connector = $this->connectorWith(new Response(200, [], $this->successXml()), $history);

        try {
            $connector->submitPaymentOrder(self::TOKEN, $abo);
            self::fail('Invalid ABO should be rejected.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::INVALID_PAYMENT_ORDER, $e->errorCode);
            self::assertFalse($e->ambiguousPaymentOutcome);
        }
        self::assertSame([], $history);
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function invalidAboProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'LF line endings' => [str_replace("\r\n", "\n", self::aboFixture())];
        yield 'wrong structure' => ["not-an-abo-file\r\n"];
        yield 'larger than official two MiB limit' => [str_repeat('X', 2 * 1024 * 1024 + 1)];
    }

    public function testOversizedStatementResponseIsRejected(): void
    {
        $connector = $this->connectorWith(new Response(200, [], str_repeat('X', 10 * 1024 * 1024 + 1)));

        try {
            $connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31');
            self::fail('Oversized response expected.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::RESPONSE_TOO_LARGE, $e->errorCode);
            self::assertFalse($e->ambiguousPaymentOutcome);
        }
    }

    /**
     * @param array<int,array{request:\Psr\Http\Message\RequestInterface,response:?\Psr\Http\Message\ResponseInterface,error:mixed,options:array<mixed>}> $history
     * @param-out array<int,array{request:\Psr\Http\Message\RequestInterface,response:?\Psr\Http\Message\ResponseInterface,error:mixed,options:array<mixed>}> $history
     */
    private function connectorWith(Response|ConnectException $item, array &$history = []): FioBankConnector
    {
        $handler = HandlerStack::create(new MockHandler([$item]));
        $handler->push(Middleware::history($history));

        return new FioBankConnector(new Client(['handler' => $handler]));
    }

    private function xml(?string $transactions = null, string $currency = 'CZK'): string
    {
        $transactions ??= $this->xmlTransaction('10000000001', [
            1 => '1250.50', 14 => 'CZK', 2 => '2000000018', 3 => '0100', 10 => 'Synteticka firma',
            4 => '0308', 5 => '2026001', 7 => 'Platba faktury', 16 => 'Faktura 2026001', 8 => 'Bezhotovostní příjem',
        ]);

        return '<?xml version="1.0" encoding="UTF-8"?><AccountStatement><Info>'
            . '<accountId>1000000005</accountId><bankId>2010</bankId><currency>' . $currency . '</currency>'
            . '<iban>CZ5520100000001000000005</iban><bic>FIOBCZPPXXX</bic>'
            . '<openingBalance>249.50</openingBalance><closingBalance>1500.00</closingBalance>'
            . '<dateStart>2026-08-01+0200</dateStart><dateEnd>2026-08-31+0200</dateEnd></Info>'
            . '<TransactionList>' . $transactions . '</TransactionList></AccountStatement>';
    }

    /** @param array<int,string> $columns */
    private function xmlTransaction(string $id, array $columns): string
    {
        $xml = '<Transaction><column_22 name="ID pohybu" id="22">' . $id . '</column_22>'
            . '<column_0 name="Datum" id="0">2026-08-15+0200</column_0>';
        foreach ($columns as $column => $value) {
            $xml .= '<column_' . $column . ' name="Sloupec" id="' . $column . '">' . htmlspecialchars($value, ENT_XML1) . '</column_' . $column . '>';
        }

        return $xml . '</Transaction>';
    }

    private function abo(): string
    {
        return self::aboFixture();
    }

    private static function aboFixture(): string
    {
        $header = 'UHL1' . '010926' . str_pad('SYNTHETIC COMPANY', 20) . '0000000000' . '001999';

        return implode("\r\n", [
            $header,
            '1 1501 001000 2010',
            '2 000000-1000000005 000000000001000 070926',
            '000000-2000000018 000000001000 1234567890 01000558 0000000000 AV:Synthetic payment',
            '3 +',
            '5 +',
        ]) . "\r\n";
    }

    private function successXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<responseImport><result><errorCode>0</errorCode><idInstruction>700001</idInstruction>'
            . '<status>ok</status></result><ordersDetails><detail id="1"/></ordersDetails></responseImport>';
    }
}
