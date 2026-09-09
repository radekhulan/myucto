<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Bank\Connector\BankHttpClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BankHttpClientFactoryTest extends TestCase
{
    public static function environments(): iterable
    {
        yield 'development' => ['development', true];
        yield 'production' => ['production', false];
        yield 'missing' => [null, false];
        yield 'other' => ['test', false];
    }

    #[DataProvider('environments')]
    public function testDiagnosticsAreEnabledOnlyInDevelopment(?string $env, bool $enabled): void
    {
        $log = new TestHandler();
        $factory = new BankHttpClientFactory(new Config(['app' => ['env' => $env]]), new Logger('test', [$log]));
        $error = new ConnectException('private-exception', new Request('GET', 'https://bank.example/private-token'));
        $client = $factory->create('fio', new MockHandler([new Response(200), new Response(403), $error]));
        foreach ([200, 403] as $status) {
            self::assertSame($status, $client->request('GET', 'https://bank.example/private-token', ['http_errors' => false])->getStatusCode());
        }
        try {
            $client->request('GET', 'https://bank.example/private-token');
            self::fail('Failure expected.');
        } catch (ConnectException $caught) {
            self::assertSame($error, $caught);
        }
        $factory->diagnosticLogger()->info('response_shape');
        self::assertCount($enabled ? 4 : 0, $log->getRecords());
        if ($enabled) {
            self::assertSame('bank_http_completed', $log->getRecords()[0]->message);
            self::assertSame(403, $log->getRecords()[1]->context['http_status']);
            self::assertSame('bank_http_transport_failed', $log->getRecords()[2]->message);
            self::assertStringNotContainsString('private-', json_encode($log->getRecords(), JSON_THROW_ON_ERROR));
        }
    }

    public static function failures(): iterable
    {
        yield 'synchronous' => [true];
        yield 'promise' => [false];
    }

    public function testRbErrorDiagnosticsPreserveResponseAndExcludeSensitiveContent(): void
    {
        $log = new TestHandler();
        $factory = new BankHttpClientFactory(new Config(['app' => ['env' => 'development']]), new Logger('test', [$log]));
        $body = '{"errorCode":"ERR_PAY_172","error_description":"private-account","token":"private-token"}';
        $response = new Response(500, ['X-Correlation-Id' => 'private-secret'], $body);
        $response->getBody()->seek(3);
        $client = $factory->create('raiffeisenbank', new MockHandler([$response]));
        $result = $client->request('POST', 'https://bank.example/payments/batches', [
            'http_errors' => false,
            'headers' => ['X-Request-Id' => 'myucto-' . str_repeat('a', 32)],
        ]);
        self::assertSame(3, $result->getBody()->tell());
        self::assertSame($body, (string) $result->getBody());
        $context = $log->getRecords()[0]->context;
        self::assertSame('ERR_PAY_172', $context['bank_error_code']);
        self::assertSame('myucto-' . str_repeat('a', 32), $context['bank_request_id']);
        self::assertSame('json', $context['error_body_format']);
        self::assertStringNotContainsString('private-', json_encode($context, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('failures')]
    public function testTransportDiagnosticsPreserveCallbackAndNeverLogSecrets(bool $synchronous): void
    {
        $log = new TestHandler();
        $factory = new BankHttpClientFactory(new Config(['app' => ['env' => 'development']]), new Logger('test', [$log]));
        $callbackCode = null;
        $error = new ConnectException('private-secret ssl/tls alert handshake failure', new Request('GET', 'https://bank.example/private-account'));
        $client = $factory->create('csob', static function ($request, $options) use ($error, $synchronous) {
            $options['on_stats'](new TransferStats($request, null, 0.25, 35, [
                'url' => 'https://bank.example/private-account', 'cert' => 'private-cert',
                'connect_time' => 0.12, 'ssl_verify_result' => 0,
            ]));
            if ($synchronous) {
                throw $error;
            }
            return Create::rejectionFor($error);
        });
        try {
            $client->request('GET', 'https://bank.example/private-account?token=private-token', [
                'headers' => ['Authorization' => 'Bearer private-auth'],
                'on_stats' => static function (TransferStats $stats) use (&$callbackCode): void {
                    $callbackCode = $stats->getHandlerErrorData();
                },
            ]);
            self::fail('Failure expected.');
        } catch (ConnectException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertSame(35, $callbackCode);
        self::assertCount(1, $log->getRecords());
        $context = $log->getRecords()[0]->context;
        self::assertSame(35, $context['errno']);
        self::assertSame('ssl/tls alert handshake failure', $context['tls_reason']);
        self::assertSame(0.12, $context['connect_time']);
        self::assertSame('csob', $context['provider']);
        self::assertStringNotContainsString('private-', json_encode($context, JSON_THROW_ON_ERROR));
    }
}
