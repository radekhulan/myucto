<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\CsobBusinessConnectorClient;
use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsobBusinessConnectorClientTest extends TestCase
{
    private const GUID = '00000000-0000-4000-8000-000000000001';
    private const DOWNLOAD = 'https://ceb-bc.csob.cz/ExtFileHubDown/v2/download?id=synthetic';
    private const UPLOAD = 'https://ceb-bc.csob.cz/ExtFileHubUp/v2/upload?id=synthetic';

    public static function transportFailures(): array
    {
        return [[6, 'bank_dns_failed'], [7, 'bank_connect_failed'], [28, 'bank_timeout'],
            [35, 'bank_tls_handshake_failed'], [58, 'bank_client_certificate_failed'],
            [60, 'bank_server_certificate_failed'], [77, 'bank_ca_configuration_failed'],
            [56, 'remote_unavailable']];
    }

    #[DataProvider('transportFailures')]
    public function testTransportDiagnosticsDoNotExposeCredentials(int $errno, string $code): void
    {
        $history = [];
        $calls = 0;
        $client = $this->client([static function ($request, array $options) use ($errno, &$calls): never {
            $calls++;
            $options['on_stats'](new \GuzzleHttp\TransferStats($request, null, 0.01, $errno));
            throw new ConnectException('synthetic-private-value', $request);
        }], $history);
        try {
            $client->listFiles($this->credentials());
            self::fail('Failure accepted');
        } catch (BankConnectorException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertStringNotContainsString('synthetic-private-value', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        self::assertSame(1, $calls);
    }

    public function testListsFilesWithClientManagedTimestampAndExplicitFilters(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], $this->listXml())], $history);
        $result = $client->listFiles($this->credentials(), [
            'prev_query_timestamp' => '2026-09-01T00:00:00+02:00',
            'file_types' => ['VYPIS'], 'file_formats' => ['BBGPC'],
            'created_after' => '2026-09-01T00:00:00+02:00', 'created_before' => '2026-09-08T00:00:00+02:00',
        ]);
        self::assertSame('2026-09-07T12:00:00+02:00', $result['query_timestamp']);
        self::assertSame('D', $result['files'][0]['status']);
        self::assertSame(self::DOWNLOAD, $result['files'][0]['url']);
        self::assertSame(4, $result['files'][0]['size']);
        self::assertSame('BBGPC', $result['files'][0]['format']);
        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame('https://ceb-bc.csob.cz/cebbc/api', (string) $request->getUri());
        self::assertSame('"GetDownloadFileList_v4"', $request->getHeaderLine('SOAPAction'));
        $body = (string) $request->getBody();
        self::assertStringContainsString('<b:ContractNumber>10000001</b:ContractNumber>', $body);
        self::assertStringContainsString('<b:PrevQueryTimestamp>2026-09-01T00:00:00+02:00</b:PrevQueryTimestamp>', $body);
        self::assertStringContainsString('<b:FileFormat>BBGPC</b:FileFormat>', $body);
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertTrue($history[0]['options']['verify']);
        self::assertFalse($history[0]['options']['stream']);
        self::assertFalse($history[0]['options']['debug']);
        self::assertSame(CURL_SSLVERSION_TLSv1_2, $history[0]['options']['curl'][CURLOPT_SSLVERSION]);
        self::assertSame('synthetic-private-key', $history[0]['options']['curl'][CURLOPT_SSLKEY_BLOB]);
    }

    public function testPreservesNotReadyFilesWithoutInventingDownloadAcknowledgement(): void
    {
        $xml = str_replace('<b:Url>' . self::DOWNLOAD . '</b:Url>', '', $this->listXml());
        $xml = str_replace('<b:Status>D</b:Status>', '<b:Status>R</b:Status>', $xml);
        $history = [];
        $client = $this->client([new Response(200, [], $xml)], $history);
        $result = $client->listFiles($this->credentials());
        self::assertSame('R', $result['files'][0]['status']);
        self::assertNull($result['files'][0]['url']);
        self::assertCount(1, $history);
        self::assertStringNotContainsString('PrevQueryTimestamp', (string) $history[0]['request']->getBody());
    }

    public function testDownloadsCompleteRawBytesAndUsesCertificateOnTransfer(): void
    {
        $chunks = ['AB', 'CD'];
        $stream = new PumpStream(static function () use (&$chunks): ?string { return array_shift($chunks); });
        $history = [];
        $client = $this->client([new Response(200, [], $stream)], $history);
        self::assertSame('ABCD', $client->downloadFile($this->credentials(), ['status' => 'D', 'url' => self::DOWNLOAD, 'size' => 4]));
        self::assertSame('GET', $history[0]['request']->getMethod());
        self::assertArrayHasKey(CURLOPT_SSLCERT_BLOB, $history[0]['options']['curl']);
    }

    #[DataProvider('unsafeUrls')]
    public function testRejectsUnsafeTransferUrlsWithoutMakingAnyRequest(string $url): void
    {
        $history = [];
        $client = $this->client([], $history);
        try {
            $client->downloadFile($this->credentials(), ['status' => 'D', 'url' => $url, 'size' => 4]);
            self::fail('Unsafe URL accepted.');
        } catch (BankConnectorException $e) {
            self::assertFalse($e->ambiguousPaymentOutcome);
            self::assertStringNotContainsString($url, $e->getMessage());
        }
        self::assertSame([], $history);
    }

    public static function unsafeUrls(): iterable
    {
        yield ['http://ceb-bc.csob.cz/ExtFileHubDown/v2/download?id=synthetic'];
        yield ['https://127.0.0.1/ExtFileHubDown/v2/download?id=synthetic'];
        yield ['https://ceb-bc.csob.cz.attacker.invalid/ExtFileHubDown/v2/download?id=synthetic'];
        yield ['https://ceb-bc.csob.cz:8443/ExtFileHubDown/v2/download?id=synthetic'];
        yield ['https://user@ceb-bc.csob.cz/ExtFileHubDown/v2/download?id=synthetic'];
        yield ['https://ceb-bc.csob.cz/ExtFileHubDown/v2/../download?id=synthetic'];
        yield ['https://ceb-bc.csob.cz/ExtFileHubUp/v2/upload?id=synthetic'];
        yield ['https://ceb-bc.csob.cz/ExtFileHubDown/v2/download?id=synthetic#fragment'];
        yield ['https://testceb-bc.csob.cz/ceb-mock/download?id=synthetic'];
    }

    public function testSandboxUsesSeparateAllowlist(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], 'ABCD')], $history, true);
        self::assertSame('ABCD', $client->downloadFile($this->credentials(), [
            'status' => 'D', 'url' => 'https://testceb-bc.csob.cz/ceb-mock/download?id=synthetic', 'size' => 4,
        ]));
    }

    public function testRejectsUnexpectedCurlOptionsBeforeNetwork(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $credentials = $this->credentials();
        $credentials['curl_options'][CURLOPT_SSL_VERIFYPEER] = false;
        $this->expectException(BankConnectorException::class);
        try { $client->listFiles($credentials); }
        finally { self::assertSame([], $history); }
    }

    public function testRejectsDoctypeAndWrongNamespaceAndDuplicateFields(): void
    {
        foreach ([
            '<!DOCTYPE Envelope [<!ENTITY private SYSTEM "file:///synthetic-secret">]>' . $this->listXml(),
            str_replace('GetDownloadFileList_v4', 'Incorrect_v4', $this->listXml()),
            str_replace('<b:Size>4</b:Size>', '<b:Size>4</b:Size><b:Size>5</b:Size>', $this->listXml()),
        ] as $xml) {
            $history = [];
            $client = $this->client([new Response(200, [], $xml)], $history);
            try { $client->listFiles($this->credentials()); self::fail('Invalid XML accepted.'); }
            catch (BankConnectorException $e) { self::assertSame(BankConnectorException::INVALID_RESPONSE, $e->errorCode); }
        }
    }

    public function testBoundsChunkedResponsesAndRejectsTruncatedFiles(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], new PumpStream(static fn (): string => str_repeat('X', 8192)))], $history);
        try { $client->listFiles($this->credentials()); self::fail('Oversized response accepted.'); }
        catch (BankConnectorException $e) { self::assertSame(BankConnectorException::RESPONSE_TOO_LARGE, $e->errorCode); }
        $client = $this->client([new Response(200, [], 'ABC')], $history);
        $this->expectException(BankConnectorException::class);
        $client->downloadFile($this->credentials(), ['status' => 'D', 'url' => self::DOWNLOAD, 'size' => 4]);
    }

    public function testMapsRateLimitFaultWithoutLeakingServerText(): void
    {
        $fault = '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault><detail>'
            . '<b:CEBBCError xmlns:b="http://ceb-bc.csob.cz/CEBBCWS/CEBBCError_v2"><b:Code>1101</b:Code><b:Text>synthetic-private-value</b:Text></b:CEBBCError>'
            . '</detail></s:Fault></s:Body></s:Envelope>';
        $history = [];
        $client = $this->client([new Response(500, [], $fault)], $history);
        try { $client->listFiles($this->credentials()); self::fail('SOAP fault ignored.'); }
        catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::RATE_LIMITED, $e->errorCode);
            self::assertFalse($e->ambiguousPaymentOutcome);
            self::assertStringNotContainsString('synthetic-private-value', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        self::assertCount(1, $history);
    }

    public function testUnsignedUploadUsesSha256MultipartAllOrNothingAndOnlyReportsImportStarted(): void
    {
        $history = [];
        $client = $this->client($this->uploadResponses(), $history);
        $result = $client->submitUnsignedAbo($this->credentials(), $this->abo(), self::GUID);
        self::assertSame('import_started', $result['status']);
        self::assertSame(hash('sha256', $this->abo()), $result['upload_file_hash']);
        self::assertSame(self::GUID, $result['client_app_guid']);
        self::assertCount(3, $history);
        self::assertSame('"StartUploadFileList_v3"', $history[0]['request']->getHeaderLine('SOAPAction'));
        $start = (string) $history[0]['request']->getBody();
        self::assertStringContainsString('<b:Mode>AllOrNothing</b:Mode>', $start);
        self::assertStringContainsString('<b:SkipCheckDuplicates>false</b:SkipCheckDuplicates>', $start);
        self::assertStringNotContainsString('SignedAllOrNothing', $start);
        self::assertSame(self::UPLOAD, (string) $history[1]['request']->getUri());
        self::assertStringContainsString('multipart/form-data', $history[1]['request']->getHeaderLine('Content-Type'));
        self::assertStringContainsString('name="fileupload"', (string) $history[1]['request']->getBody());
        self::assertStringContainsString($this->abo(), (string) $history[1]['request']->getBody());
        self::assertSame('"FinishUploadFileList_v2"', $history[2]['request']->getHeaderLine('SOAPAction'));
        self::assertStringContainsString('<b:NewFileId>synthetic-file-id</b:NewFileId>', (string) $history[2]['request']->getBody());
    }

    public function testRejectedStartDoesNotUpload(): void
    {
        $responses = $this->uploadResponses();
        $responses[0] = new Response(200, [], str_replace('<b:Status>U</b:Status>', '<b:Status>R</b:Status>', (string) $responses[0]->getBody()));
        $history = [];
        $client = $this->client($responses, $history);
        self::assertSame('rejected', $client->submitUnsignedAbo($this->credentials(), $this->abo(), self::GUID)['status']);
        self::assertCount(1, $history);
    }

    public function testTimeoutAfterUploadingIsAmbiguousAndIsNeverRetried(): void
    {
        $responses = $this->uploadResponses();
        $responses[2] = new ConnectException('synthetic-private-value', new Request('POST', 'https://ceb-bc.csob.cz/cebbc/api'));
        $history = [];
        $client = $this->client($responses, $history);
        try { $client->submitUnsignedAbo($this->credentials(), $this->abo(), self::GUID); self::fail('Timeout was accepted.'); }
        catch (BankConnectorException $e) {
            self::assertTrue($e->ambiguousPaymentOutcome);
            self::assertStringNotContainsString('synthetic-private-value', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        self::assertCount(3, $history);
    }

    public function testRejectsWrongHashOrUploadUrlAndDoesNotTransferPaymentBytes(): void
    {
        foreach ([
            str_replace(hash('sha256', $this->abo()), str_repeat('0', 64), (string) $this->uploadResponses()[0]->getBody()),
            str_replace(self::UPLOAD, 'https://attacker.invalid/upload?id=synthetic', (string) $this->uploadResponses()[0]->getBody()),
        ] as $xml) {
            $history = [];
            $client = $this->client([new Response(200, [], $xml)], $history);
            try { $client->submitUnsignedAbo($this->credentials(), $this->abo(), self::GUID); self::fail('Invalid upload response accepted.'); }
            catch (BankConnectorException $e) { self::assertTrue($e->ambiguousPaymentOutcome); }
            self::assertCount(1, $history);
        }
    }

    public function testExtendedJsonFailureCannotStartImport(): void
    {
        $responses = $this->uploadResponses();
        $responses[1] = new Response(200, [], '{"Status":"450","NewFileId":"synthetic-file-id"}');
        $history = [];
        $client = $this->client($responses, $history);
        try { $client->submitUnsignedAbo($this->credentials(), $this->abo(), self::GUID); self::fail('HTTP 200 body failure ignored.'); }
        catch (BankConnectorException $e) { self::assertTrue($e->ambiguousPaymentOutcome); }
        self::assertCount(2, $history);
    }

    private function credentials(): array
    {
        return ['contract_number' => '10000001', 'curl_options' => [
            CURLOPT_SSLCERTTYPE => 'PEM', CURLOPT_SSLKEYTYPE => 'PEM',
            CURLOPT_SSLCERT_BLOB => 'synthetic-certificate', CURLOPT_SSLKEY_BLOB => 'synthetic-private-key',
        ]];
    }

    private function client(array $responses, array &$history, bool $sandbox = false): CsobBusinessConnectorClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create(static function ($request, array $options) use ($mock) {
            unset($options['sink']);
            return $mock($request, $options);
        });
        $stack->push(Middleware::history($history));
        return new CsobBusinessConnectorClient(new Client(['handler' => $stack]), $sandbox);
    }

    #[DataProvider('transportLimitPaths')]
    public function testTransportRejectsLargeHeadersProgressAndWritesBeforeBuffering(string $path): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], $this->listXml())], $history);
        $client->listFiles($this->credentials());
        $options = $history[0]['options'];
        $this->expectException(BankConnectorException::class);
        match ($path) {
            'headers' => $options['on_headers'](new Response(200, ['Content-Length' => (string) (2 * 1024 * 1024 + 1)])),
            'progress' => $options['progress'](0, 2 * 1024 * 1024 + 1, 0, 0),
            'sink' => $options['sink']->write(str_repeat('x', 2 * 1024 * 1024 + 1)),
        };
    }

    public static function transportLimitPaths(): iterable
    {
        yield ['headers'];
        yield ['progress'];
        yield ['sink'];
    }

    private function envelope(string $operation, string $body): string
    {
        $name = str_replace('_v', 'Response_v', $operation);
        return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><b:' . $name
            . ' xmlns:b="http://ceb-bc.csob.cz/CEBBCWS/' . $operation . '">' . $body
            . '</b:' . $name . '></s:Body></s:Envelope>';
    }

    private function listXml(): string
    {
        return $this->envelope('GetDownloadFileList_v4', '<b:QueryTimestamp>2026-09-07T12:00:00+02:00</b:QueryTimestamp>'
            . '<b:FileList><b:FileDetail><b:Url>' . self::DOWNLOAD . '</b:Url><b:Filename>synthetic.gpc</b:Filename>'
            . '<b:Type>VYPIS</b:Type><b:Format>BBGPC</b:Format><b:CreationDateTime>2026-09-07T06:00:00+02:00</b:CreationDateTime>'
            . '<b:Size>4</b:Size><b:Status>D</b:Status></b:FileDetail></b:FileList><b:TicketId>synthetic-ticket</b:TicketId>');
    }

    private function uploadResponses(): array
    {
        $hash = hash('sha256', $this->abo());
        $identity = '<b:Filename>myucto-' . substr($hash, 0, 32) . '.abo</b:Filename><b:Hash>' . $hash . '</b:Hash>';
        return [
            new Response(200, [], $this->envelope('StartUploadFileList_v3', '<b:FileList><b:FileUrl>' . $identity
                . '<b:Status>U</b:Status><b:Url>' . self::UPLOAD . '</b:Url></b:FileUrl></b:FileList><b:TicketId>synthetic-start</b:TicketId>')),
            new Response(200, [], '{"Status":"201","NewFileId":"synthetic-file-id","ExtFileUrl":""}'),
            new Response(200, [], $this->envelope('FinishUploadFileList_v2', '<b:FileList><b:FileStatus>' . $identity
                . '<b:Status>I</b:Status></b:FileStatus></b:FileList><b:TicketId>synthetic-finish</b:TicketId>')),
        ];
    }

    private function abo(): string
    {
        return (new AboPaymentOrderWriter())->build([
            'client_name' => 'SYNTHETIC COMPANY', 'payer_account_number' => '1000000005', 'payer_bank_code' => '0300',
            'payment_date' => '2026-09-07', 'items' => [[
                'account_number' => '2000000018', 'bank_code' => '0100', 'amount_minor' => 1000, 'variable_symbol' => '111',
            ]],
        ]);
    }
}
