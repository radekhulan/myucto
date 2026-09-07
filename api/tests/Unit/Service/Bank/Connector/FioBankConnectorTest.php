<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Bank\Connector\BankConnector;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\FioBankConnector;
use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FioBankConnectorTest extends TestCase
{
    private const TOKEN = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    public function testImplementsGenericContractAndIdentifiesProvider(): void
    {
        $connector = $this->connectorWith(new Response(200, [], $this->gpc()));

        self::assertInstanceOf(BankConnector::class, $connector);
        self::assertSame('fio', $connector->provider());
    }

    public function testDownloadsRawGpcFromPeriodsEndpointWithHardenedOptions(): void
    {
        $history = [];
        $raw = $this->gpc(chr(0xE1));
        $connector = $this->connectorWith(new Response(200, ['Content-Type' => 'text/plain'], $raw), $history);

        $result = $connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31');

        self::assertSame($raw, $result);
        self::assertCount(1, $history);
        self::assertSame('GET', $history[0]['request']->getMethod());
        self::assertSame(
            'https://fioapi.fio.cz/v1/rest/periods/' . self::TOKEN . '/2026-08-01/2026-08-31/transactions.gpc',
            (string) $history[0]['request']->getUri(),
        );
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertTrue($history[0]['options']['verify']);
        self::assertTrue($history[0]['options']['stream']);
        self::assertSame(5.0, $history[0]['options']['connect_timeout']);
        self::assertSame(30.0, $history[0]['options']['timeout']);
    }

    #[DataProvider('invalidDateProvider')]
    public function testRejectsInvalidOrReversedDateRange(string $from, string $to, string $errorCode): void
    {
        $history = [];
        $connector = $this->connectorWith(new Response(200, [], $this->gpc()), $history);

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
        $connector = $this->connectorWith(new Response(200, [], $this->gpc()));

        try {
            $connector->downloadStatement($secret, '2026-08-01', '2026-08-31');
            self::fail('Malformed token should be rejected.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::INVALID_TOKEN, $e->errorCode);
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testRejectsMalformedGpcInsteadOfPassingAnErrorPageToParser(): void
    {
        $connector = $this->connectorWith(new Response(200, [], '<html>synthetic error</html>'));

        $this->expectException(BankConnectorException::class);
        $this->expectExceptionMessage('neplatný formát GPC');
        $connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31');
    }

    public function testReadsEntireChunkedStatementResponse(): void
    {
        $chunks = [substr($this->gpc(), 0, 40), substr($this->gpc(), 40)];
        $stream = new PumpStream(static function () use (&$chunks): ?string {
            return array_shift($chunks);
        });
        $connector = $this->connectorWith(new Response(200, [], $stream));

        self::assertSame(
            $this->gpc(),
            $connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31'),
        );
    }

    public function testRejectsSecondHeaderAndTransactionFromDifferentAccount(): void
    {
        foreach ([
            $this->gpc() . $this->gpc(),
            $this->gpc() . $this->gpcTransaction('0000002000000018'),
        ] as $invalid) {
            $connector = $this->connectorWith(new Response(200, [], $invalid));
            try {
                $connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31');
                self::fail('Inconsistent GPC should be rejected.');
            } catch (BankConnectorException $e) {
                self::assertSame(BankConnectorException::INVALID_RESPONSE, $e->errorCode);
            }
        }
    }

    public function testAcceptsTransactionsOnlyForHeaderAccount(): void
    {
        $raw = $this->gpc() . $this->gpcTransaction('0000001000000005');
        $connector = $this->connectorWith(new Response(200, [], $raw));

        self::assertSame(
            $raw,
            $connector->downloadStatement(self::TOKEN, '2026-08-01', '2026-08-31'),
        );
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

    private function gpc(string $lastByte = ' '): string
    {
        return '074' . '0000001000000005' . str_repeat(' ', 108) . $lastByte . "\r\n";
    }

    private function gpcTransaction(string $account): string
    {
        return '075' . $account . str_repeat('0', 109) . "\r\n";
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
