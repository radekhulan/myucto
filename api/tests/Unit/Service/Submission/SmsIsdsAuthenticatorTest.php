<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\Isds\SmsIsdsAuthenticator;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Tests\Support\InMemoryIsdsAuthFlowStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SmsIsdsAuthenticatorTest extends TestCase
{
    public function testSmsIsRequestedThenCodeCreatesTransientSession(): void
    {
        $calls = [];
        $authenticator = new SmsIsdsAuthenticator(
            $this->crypto(),
            new InMemoryIsdsAuthFlowStore(),
            static function (string $operation, string $url, array $options) use (&$calls): array {
                $calls[] = [$operation, $url, $options];
                if ($operation === 'send_sms') {
                    return [
                        'status' => 302,
                        'body' => '',
                        'cookies' => [],
                        'headers' => ['x-response-message-code' => 'authentication.info.totpSended'],
                    ];
                }
                return [
                    'status' => 302,
                    'body' => '',
                    'cookies' => ['IPCZ-X-COOKIE' => 'sms-session-cookie-123'],
                    'headers' => [],
                ];
            },
        );

        $start = $authenticator->start(7, 11, 'test', 'synthetic-user', 'synthetic-password');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $start['flow_token']);
        self::assertStringNotContainsString('synthetic-password', $start['flow_token']);

        $context = $authenticator->complete($start['flow_token'], '123456', 7, 11, 'test');

        self::assertSame('sms', $context->credentials->authMode);
        self::assertSame('sms-session-cookie-123', $context->credentials->sessionCookie?->reveal());
        self::assertStringContainsString('type=totp&sendSms=true', $calls[0][1]);
        self::assertSame('synthetic-password', $calls[0][2]['password']);
        self::assertStringContainsString('type=totp&uri=', $calls[1][1]);
        self::assertSame('synthetic-password123456', $calls[1][2]['password']);
    }

    public function testFlowIsBoundToSupplierAndUserBeforeSecondIsdsCall(): void
    {
        $calls = 0;
        $authenticator = new SmsIsdsAuthenticator(
            $this->crypto(),
            new InMemoryIsdsAuthFlowStore(),
            static function () use (&$calls): array {
                $calls++;
                return ['status' => 302, 'body' => '', 'cookies' => [], 'headers' => []];
            },
        );
        $start = $authenticator->start(7, 11, 'test', 'synthetic-user', 'synthetic-password');

        try {
            $authenticator->complete($start['flow_token'], '123456', 8, 11, 'test');
            self::fail('Tok jiné firmy nesmí pokračovat.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_sms_flow_expired', $e->errorCode);
        }
        try {
            $authenticator->complete($start['flow_token'], '123456', 7, 12, 'test');
            self::fail('Tok jiného uživatele nesmí pokračovat.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_sms_flow_expired', $e->errorCode);
        }
        self::assertSame(1, $calls);
    }

    public function testSuccessfulSmsFlowCannotBeReplayed(): void
    {
        $authenticator = new SmsIsdsAuthenticator(
            $this->crypto(),
            new InMemoryIsdsAuthFlowStore(),
            static fn (string $operation): array => $operation === 'send_sms'
                ? ['status' => 302, 'body' => '', 'cookies' => [], 'headers' => []]
                : ['status' => 302, 'body' => '', 'cookies' => ['IPCZ-X-COOKIE' => 'sms-session-cookie-123'], 'headers' => []],
        );
        $start = $authenticator->start(7, 11, 'test', 'synthetic-user', 'synthetic-password');
        $authenticator->complete($start['flow_token'], '123456', 7, 11, 'test');

        $this->expectException(SubmissionChannelException::class);
        $authenticator->complete($start['flow_token'], '123456', 7, 11, 'test');
    }

    public function testSmsFlowBlocksAfterFiveRejectedCodesWithoutSixthIsdsCall(): void
    {
        $calls = 0;
        $authenticator = new SmsIsdsAuthenticator(
            $this->crypto(),
            new InMemoryIsdsAuthFlowStore(),
            static function (string $operation) use (&$calls): array {
                $calls++;
                return $operation === 'send_sms'
                    ? ['status' => 302, 'body' => '', 'cookies' => [], 'headers' => []]
                    : ['status' => 401, 'body' => '', 'cookies' => [], 'headers' => []];
            },
        );
        $start = $authenticator->start(7, 11, 'test', 'synthetic-user', 'synthetic-password');
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $authenticator->complete($start['flow_token'], '123456', 7, 11, 'test');
                self::fail('Odmítnutý SMS kód nesmí vytvořit relaci.');
            } catch (SubmissionChannelException $e) {
                self::assertSame('isds_sms_code_rejected', $e->errorCode);
            }
        }

        try {
            $authenticator->complete($start['flow_token'], '123456', 7, 11, 'test');
            self::fail('Šestý pokus musí být zastaven lokálně.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_sms_flow_expired', $e->errorCode);
        }
        self::assertSame(6, $calls, 'Po pěti kódech už se další požadavek nesmí dotknout ISDS.');
    }

    private function crypto(): SecretEncryption
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty($config, 'data');
        $property->setValue($config, [
            'app' => [
                'secret_encryption_key' => base64_encode(random_bytes(32)),
                'secret_encryption_previous_keys' => [],
                'pepper' => '',
            ],
        ]);
        return new SecretEncryption($config);
    }
}
