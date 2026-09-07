<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\RaiffeisenbankConnector;
use MyInvoice\Service\Bank\Connector\RaiffeisenbankPremiumClient;
use MyInvoice\Service\Bank\Connector\RaiffeisenbankTransactionParser;
use MyInvoice\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\TestCase;

final class RaiffeisenbankConnectorTest extends TestCase
{
    use OpensslConfigTrait;

    public function testCertificateCredentialsVerifyAccountAndImportWithoutFabricatedBalance(): void
    {
        $today = date('Y-m-d');
        $connector = $this->connector([
            $this->response(['numberPart2' => '1000000005', 'bankCode' => '5500', 'currencyFolders' => [['currency' => 'CZK', 'status' => 'ACTIVE']]]),
            $this->response(['lastPage' => true, 'transactions' => [[
                'entryReference' => 'synthetic-001', 'amount' => ['value' => 125.25, 'currency' => 'CZK'],
                'creditDebitIndication' => 'CRDT', 'bankTransactionCode' => ['code' => '10000101000'], 'bookingDate' => $today,
            ]]]),
        ]);
        $token = $connector->credentials($this->certificate(), ['account_number' => '1000000005', 'account_code' => 'CZK'], null);
        $content = $connector->downloadStatement($token, $today, $today);
        self::assertStringNotContainsString('certificate', $content);
        self::assertStringNotContainsString('password', $content);
        $parsed = $connector->parseStatement($content);
        self::assertSame('1000000005', $parsed['header']['account_number']);
        self::assertNull($parsed['header']['curr_balance']);
        self::assertSame(125.25, $parsed['transactions'][0]['amount']);
        self::assertSame('synthetic-001', $parsed['transactions'][0]['bank_ref']);
        self::assertSame($token, $connector->credentials([], ['account_number' => '1000000005', 'account_code' => 'CZK'], $token));
    }

    public function testBankAccountPrefixMismatchBlocksReadingTransactions(): void
    {
        $connector = $this->connector([
            $this->response(['numberPart1' => '19', 'numberPart2' => '1000000005', 'bankCode' => '5500', 'currencyFolders' => [['currency' => 'CZK', 'status' => 'ACTIVE']]]),
        ]);
        $token = $connector->credentials($this->certificate(), ['account_number' => '1000000005', 'account_code' => 'CZK'], null);
        $this->expectException(BankConnectorOperationException::class);
        $this->expectExceptionMessage('statement_account_mismatch');
        $connector->downloadStatement($token, date('Y-m-d'), date('Y-m-d'));
    }

    private function connector(array $responses): RaiffeisenbankConnector
    {
        return new RaiffeisenbankConnector(new RaiffeisenbankPremiumClient(new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ])), new RaiffeisenbankTransactionParser());
    }

    private function response(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function certificate(): array
    {
        $options = ['private_key_bits' => 2048] + self::opensslConfigArgs();
        $key = openssl_pkey_new($options);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'synthetic.example.invalid'], $key, $options);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_pkcs12_export($certificate, $bytes, $key, 'synthetic-password'));
        return ['client_id' => 'synthetic-client', 'certificate' => base64_encode($bytes), 'password' => 'synthetic-password'];
    }
}
