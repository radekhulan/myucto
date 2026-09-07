<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\CsobBankConnector;
use MyInvoice\Service\Bank\Connector\CsobBusinessConnectorClient;
use MyInvoice\Service\Bank\Connector\CsobGpcStatementSplitter;
use MyInvoice\Service\Bank\GpcParser;
use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use MyInvoice\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsobBankConnectorTest extends TestCase
{
    use OpensslConfigTrait;

    private static ?string $certificate = null;

    public function testCredentialsPreserveCertificatePasswordAndStableClientGuid(): void
    {
        $history = [];
        $connector = $this->connector([], $history);
        $token = $this->credentials($connector);
        $again = $connector->credentials([], $this->account(), $token);
        self::assertSame($token, $again);
        $data = json_decode($again, true, 4, JSON_THROW_ON_ERROR);
        self::assertSame('0000001000000005', $data['account_number']);
        self::assertSame('CZK', $data['currency']);
        self::assertSame('synthetic-password', $data['password']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $data['client_app_guid']);
        self::assertSame([], $history);
    }

    public function testCredentialsRejectArbitraryServerUrl(): void
    {
        $history = [];
        $connector = $this->connector([], $history);
        $this->expectException(BankConnectorOperationException::class);
        $connector->credentials(['url' => 'https://attacker.invalid'], $this->account(), null);
    }

    public function testVerifyChecksContractAccessWithoutDownloadingStatements(): void
    {
        $gpc = self::gpc();
        $history = [];
        $connector = $this->connector([$this->listResponse([['content' => $gpc]]), new Response(200, [], $gpc)], $history);
        self::assertSame(['account_number' => '0000001000000005', 'bank_code' => '0300', 'currency' => 'CZK'],
            $connector->verifyAccount($this->credentials($connector)));
        self::assertCount(1, $history);
        self::assertArrayHasKey(CURLOPT_SSLKEY_BLOB, $history[0]['options']['curl']);
    }

    public function testNewAccountCanConnectWithoutStatements(): void
    {
        $history = [];
        $connector = $this->connector([$this->listResponse([])], $history);
        self::assertSame(['account_number' => '0000001000000005', 'bank_code' => '0300', 'currency' => 'CZK'],
            $connector->verifyAccount($this->credentials($connector)));
        self::assertCount(1, $history);
    }

    public function testOtherCurrencyStatementsDoNotBlockContractConnection(): void
    {
        $gpc = self::gpc(currency: '00978');
        $history = [];
        $connector = $this->connector([$this->listResponse([['content' => $gpc]]), new Response(200, [], $gpc)], $history);
        self::assertSame('CZK', $connector->verifyAccount($this->credentials($connector))['currency']);
        self::assertCount(1, $history);
    }

    public function testBatchPreservesOriginalBlocksAndExcludesOtherAccountsAndCurrencies(): void
    {
        $first = self::gpc(reference: '1000000000001');
        $foreign = self::gpc(account: '2000000018');
        $eur = self::gpc(currency: '00978');
        $last = self::gpc(reference: '1000000000002');
        $raw = $first . $foreign . $eur . $last;
        $history = [];
        $connector = $this->connector([$this->listResponse([['content' => $raw]]), new Response(200, [], $raw)], $history);
        $today = $this->today()->format('Y-m-d');
        $envelope = $connector->downloadStatement($this->credentials($connector), $today, $today);
        $files = $connector->statementFiles($envelope);
        self::assertCount(2, $files);
        self::assertSame($first, $files[0]['content']);
        self::assertSame($last, $files[1]['content']);
        self::assertNotSame($files[0]['filename'], $files[1]['filename']);
        self::assertSame('0000001000000005', $connector->parseStatement($envelope)['header']['account_number']);
        self::assertSame([], $connector->parseStatement($envelope)['transactions']);
        self::assertStringNotContainsString(base64_encode($foreign), $envelope);
        $body = (string) $history[0]['request']->getBody();
        self::assertStringNotContainsString('PrevQueryTimestamp', $body);
        self::assertStringContainsString('<b:CreatedAfter>' . $this->today()->format(\DateTimeInterface::ATOM), $body);
        self::assertStringContainsString('<b:CreatedBefore>' . $this->today()->modify('+1 day')->format(\DateTimeInterface::ATOM), $body);
        self::assertStringContainsString('010126', $files[0]['content']);
    }

    public function testEmptySyncIsAllowedOnlyAsEmptyEnvelopeNotInventedGpc(): void
    {
        $history = [];
        $connector = $this->connector([$this->listResponse([])], $history);
        $today = $this->today()->format('Y-m-d');
        $result = $connector->downloadStatement($this->credentials($connector), $today, $today);
        self::assertSame([], $connector->statementFiles($result));
        self::assertSame('CZK', $connector->parseStatement($result)['header']['currency']);
    }

    #[DataProvider('notReadyStatuses')]
    public function testPendingOrFailedFilesPreventPartialCheckpoint(string $status, string $code): void
    {
        $history = [];
        $connector = $this->connector([$this->listResponse([['content' => self::gpc()], ['status' => $status]])], $history);
        $today = $this->today()->format('Y-m-d');
        try {
            $connector->downloadStatement($this->credentials($connector), $today, $today);
            self::fail('Incomplete list accepted.');
        } catch (BankConnectorException $e) {
            self::assertSame($code, $e->errorCode);
        }
        self::assertCount(1, $history);
    }

    public static function notReadyStatuses(): iterable
    {
        yield ['R', 'csob_files_pending'];
        yield ['F', 'csob_file_failed'];
    }

    public function testOldHistoryIsRejectedBeforeNetworkCall(): void
    {
        $history = [];
        $connector = $this->connector([], $history);
        try {
            $connector->downloadStatement($this->credentials($connector), $this->today()->modify('-45 days')->format('Y-m-d'), $this->today()->format('Y-m-d'));
            self::fail('Unavailable history silently accepted.');
        } catch (BankConnectorException $e) {
            self::assertSame('history_gap', $e->errorCode);
        }
        self::assertSame([], $history);
    }

    public function testTooManyFilesAreRejectedBeforeDownloads(): void
    {
        $history = [];
        $connector = $this->connector([$this->listResponse(array_fill(0, 101, ['content' => self::gpc()]))], $history);
        $today = $this->today()->format('Y-m-d');
        try {
            $connector->downloadStatement($this->credentials($connector), $today, $today);
            self::fail('Unbounded list accepted.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::STATEMENT_TOO_LARGE, $e->errorCode);
        }
        self::assertCount(1, $history);
    }

    #[DataProvider('invalidStatements')]
    public function testSplitterRejectsAmbiguousOrMalformedBankEvidence(string $raw): void
    {
        $this->expectException(BankConnectorException::class);
        (new CsobGpcStatementSplitter(new GpcParser()))->split($raw);
    }

    public static function invalidStatements(): iterable
    {
        $valid = self::gpc();
        yield 'wrong transaction account' => [substr_replace($valid, '0000002000000018', 133, 16)];
        yield 'truncated transaction' => [substr($valid, 0, -7)];
        yield 'missing currency' => [substr_replace($valid, '00000', 247, 5)];
        yield 'invalid posting date' => [substr_replace($valid, '310226', 252, 6)];
        yield 'invalid amount' => [substr_replace($valid, 'X', 178, 1)];
        yield 'empty header only' => [substr($valid, 0, 130)];
        yield 'foreign block malformed' => [$valid . substr_replace(self::gpc(account: '2000000018'), 'X', 178, 1)];
        yield 'mixed currency block' => [$valid . substr(self::gpc(currency: '00978'), 130)];
        yield 'zip instead of gpc' => ["PK\x03\x04" . $valid];
    }

    public function testEnvelopeCannotSmuggleAnotherAccount(): void
    {
        $history = [];
        $connector = $this->connector([], $history);
        $raw = self::gpc(account: '2000000018');
        $envelope = json_encode(['version' => 1, 'account' => ['account_number' => '0000001000000005', 'bank_code' => '0300', 'currency' => 'CZK'],
            'files' => [['content' => base64_encode($raw), 'filename' => 'csob-' . substr(hash('sha256', $raw), 0, 24) . '.gpc']]], JSON_THROW_ON_ERROR);
        $this->expectException(BankConnectorException::class);
        $connector->statementFiles($envelope);
    }

    public function testSubmissionReportsImportStartedNotExecutedOrAuthorized(): void
    {
        $abo = (new AboPaymentOrderWriter())->build(['client_name' => 'SYNTHETIC COMPANY', 'payer_account_number' => '1000000005',
            'payer_bank_code' => '0300', 'payment_date' => $this->today()->format('Y-m-d'),
            'items' => [['account_number' => '2000000018', 'bank_code' => '0100', 'amount_minor' => 1000, 'variable_symbol' => '111']]]);
        $hash = hash('sha256', $abo);
        $identity = '<b:Filename>myucto-' . substr($hash, 0, 32) . '.abo</b:Filename><b:Hash>' . $hash . '</b:Hash>';
        $history = [];
        $connector = $this->connector([
            new Response(200, [], $this->soap('StartUploadFileList_v3', '<b:FileList><b:FileUrl>' . $identity . '<b:Status>U</b:Status><b:Url>https://ceb-bc.csob.cz/ExtFileHubUp/v2/upload?id=synthetic</b:Url></b:FileUrl></b:FileList><b:TicketId>synthetic-start</b:TicketId>')),
            new Response(200, [], '{"Status":"201","NewFileId":"synthetic-id"}'),
            new Response(200, [], $this->soap('FinishUploadFileList_v2', '<b:FileList><b:FileStatus>' . $identity . '<b:Status>I</b:Status></b:FileStatus></b:FileList><b:TicketId>synthetic-finish</b:TicketId>')),
        ], $history);
        $result = $connector->submitPaymentOrder($this->credentials($connector), $abo);
        self::assertSame('import_started', $result['status']);
        self::assertSame('synthetic-finish', $result['reference']);
        self::assertSame($hash, $result['upload_file_hash']);
        self::assertCount(3, $history);
        self::assertStringContainsString('<b:Mode>AllOrNothing</b:Mode>', (string) $history[0]['request']->getBody());
        self::assertStringNotContainsString('SignedAllOrNothing', (string) $history[0]['request']->getBody());
    }

    private function connector(array $responses, array &$history): CsobBankConnector
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        return new CsobBankConnector(new CsobBusinessConnectorClient(new Client(['handler' => $stack])), new CsobGpcStatementSplitter(new GpcParser()));
    }

    private function credentials(CsobBankConnector $connector): string
    {
        if (self::$certificate === null) {
            $options = ['private_key_bits' => 2048] + self::opensslConfigArgs();
            $key = openssl_pkey_new($options);
            self::assertNotFalse($key);
            $csr = openssl_csr_new(['commonName' => 'synthetic.example.invalid'], $key, $options);
            self::assertNotFalse($csr);
            $cert = openssl_csr_sign($csr, null, $key, 1, $options);
            self::assertNotFalse($cert);
            self::assertTrue(openssl_pkcs12_export($cert, $p12, $key, 'synthetic-password'));
            self::$certificate = base64_encode($p12);
        }
        return $connector->credentials(['contract_number' => '10000001', 'certificate' => self::$certificate, 'password' => 'synthetic-password'], $this->account(), null);
    }

    private function account(): array
    {
        return ['account_number' => '1000000005', 'bank_code' => '0300', 'account_code' => 'CZK'];
    }

    private function listResponse(array $files): Response
    {
        $details = '';
        foreach ($files as $index => $file) {
            $status = $file['status'] ?? 'D';
            $details .= '<b:FileDetail>' . ($status === 'D' ? '<b:Url>https://ceb-bc.csob.cz/ExtFileHubDown/v2/download?id=synthetic' . $index . '</b:Url>' : '')
                . '<b:Filename>synthetic' . $index . '.gpc</b:Filename><b:Type>VYPIS</b:Type><b:Format>BBGPC</b:Format><b:CreationDateTime>'
                . $this->today()->format(\DateTimeInterface::ATOM) . '</b:CreationDateTime><b:Size>' . strlen($file['content'] ?? '')
                . '</b:Size><b:Status>' . $status . '</b:Status></b:FileDetail>';
        }
        return new Response(200, [], $this->soap('GetDownloadFileList_v4', '<b:QueryTimestamp>' . $this->today()->format(\DateTimeInterface::ATOM)
            . '</b:QueryTimestamp><b:FileList>' . $details . '</b:FileList><b:TicketId>synthetic-ticket</b:TicketId>'));
    }

    private function soap(string $operation, string $body): string
    {
        $name = str_replace('_v', 'Response_v', $operation);
        return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><b:' . $name . ' xmlns:b="http://ceb-bc.csob.cz/CEBBCWS/'
            . $operation . '">' . $body . '</b:' . $name . '></s:Body></s:Envelope>';
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague'));
    }

    private static function gpc(string $account = '1000000005', string $currency = '00203', string $reference = '1000000000001'): string
    {
        $own = str_pad($account, 16, '0', STR_PAD_LEFT);
        $header = '074' . $own . str_pad('SYNTHETIC COMPANY', 20) . '311225' . str_repeat('0', 14) . '+'
            . '00000000001000+' . str_repeat('0', 14) . '0' . '00000000001000' . '0' . '001' . '010126' . str_repeat(' ', 14);
        $transaction = '075' . $own . '0000002000000018' . $reference . '000000001000' . '2' . '0000000111' . '00'
            . '0100' . '0000' . '0000000000' . '010126' . str_pad('SYNTHETIC PARTNER', 20) . $currency . '010126';
        return $header . "\r\n" . $transaction . "\r\n";
    }
}
