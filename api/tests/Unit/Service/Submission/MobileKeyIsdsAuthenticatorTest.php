<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\Isds\MobileKeyIsdsAuthenticator;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Tests\Support\InMemoryIsdsAuthFlowStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MobileKeyIsdsAuthenticatorTest extends TestCase
{
    public function testOneSessionRequiresMobileConfirmationAndReturnsTransientCookie(): void
    {
        $calls = [];
        $http = static function (string $operation, string $url, array $options) use (&$calls): array {
            $calls[] = [$operation, $url, $options];
            if ($operation === 'status') {
                return ['status' => 200, 'body' => '{"status":2,"description":"Potvrzeno"}', 'cookies' => []];
            }
            if ($operation === 'logout') {
                return ['status' => 302, 'body' => '', 'cookies' => []];
            }
            $secondLogin = isset($options['cookie']);
            return [
                'status' => 302,
                'body' => '',
                'cookies' => $secondLogin
                    ? ['IPCZ-X-COOKIE' => 'session-cookie-123']
                    : ['S-COOKIE' => 'state-cookie-123'],
            ];
        };
        $authenticator = new MobileKeyIsdsAuthenticator($this->crypto(), new InMemoryIsdsAuthFlowStore(), $http);

        $start = $authenticator->start(7, 11, 'test', 'synthetic-user', 'synthetic-app-password');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $start['flow_token']);
        self::assertStringNotContainsString('synthetic-app-password', $start['flow_token']);

        $completed = $authenticator->continue($start['flow_token'], 7, 11, 'test');
        self::assertSame(2, $completed['state']);
        self::assertNotNull($completed['context']);
        self::assertSame('mobile_key', $completed['context']->credentials->authMode);
        self::assertSame('session-cookie-123', $completed['context']->credentials->sessionCookie?->reveal());
        self::assertCount(3, $calls);
        self::assertSame('status', $calls[1][0]);
        self::assertSame('login', $calls[2][0]);
    }

    public function testFlowTokenIsBoundToSupplierAndUser(): void
    {
        $calls = 0;
        $authenticator = new MobileKeyIsdsAuthenticator(
            $this->crypto(),
            new InMemoryIsdsAuthFlowStore(),
            static function () use (&$calls): array {
                $calls++;
                return ['status' => 302, 'body' => '', 'cookies' => ['S-COOKIE' => 'state-cookie-123']];
            },
        );
        $start = $authenticator->start(7, 11, 'test', 'synthetic-user', 'synthetic-app-password');

        try {
            $authenticator->continue($start['flow_token'], 8, 11, 'test');
            self::fail('Tok jiné firmy nesmí pokračovat.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_mobile_flow_expired', $e->errorCode);
        }
        try {
            $authenticator->continue($start['flow_token'], 7, 12, 'test');
            self::fail('Tok jiného uživatele nesmí pokračovat.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_mobile_flow_expired', $e->errorCode);
        }
        self::assertSame(1, $calls, 'Cizí tenant ani uživatel se nesmí dotknout stavového endpointu ISDS.');
    }

    public function testPendingConfirmationDoesNotCreateIsdsSession(): void
    {
        $operations = [];
        $authenticator = new MobileKeyIsdsAuthenticator(
            $this->crypto(),
            new InMemoryIsdsAuthFlowStore(),
            static function (string $operation) use (&$operations): array {
                $operations[] = $operation;
                return $operation === 'status'
                    ? ['status' => 200, 'body' => '{"status":1,"description":"Čeká"}', 'cookies' => []]
                    : ['status' => 302, 'body' => '', 'cookies' => ['S-COOKIE' => 'state-cookie-123']];
            },
        );
        $start = $authenticator->start(7, 11, 'test', 'synthetic-user', 'synthetic-app-password');

        $pending = $authenticator->continue($start['flow_token'], 7, 11, 'test');

        self::assertSame(1, $pending['state']);
        self::assertNull($pending['context']);
        self::assertSame(['login', 'status'], $operations);
    }

    public function testCanStartFromDeferredEncryptedCredentials(): void
    {
        $authenticator = new MobileKeyIsdsAuthenticator(
            $this->crypto(),
            new InMemoryIsdsAuthFlowStore(),
            static fn (): array => ['status' => 302, 'body' => '', 'cookies' => ['S-COOKIE' => 'state-cookie-123']],
        );
        $credentials = new ChannelCredentials(
            boxId: '',
            authMode: 'mobile_key',
            username: SensitiveValue::fromProducer(static fn (): string => 'saved-user'),
            password: SensitiveValue::fromProducer(static fn (): string => 'saved-code'),
        );

        $start = $authenticator->startWithCredentials(7, 11, 'test', $credentials);

        self::assertSame(1, $start['state']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $start['flow_token']);
        self::assertStringNotContainsString('saved-code', $start['flow_token']);
    }

    public function testApprovedFlowCannotBeReplayed(): void
    {
        $authenticator = new MobileKeyIsdsAuthenticator(
            $this->crypto(),
            new InMemoryIsdsAuthFlowStore(),
            static function (string $operation, string $url, array $options): array {
                if ($operation === 'status') {
                    return ['status' => 200, 'body' => '{"status":2}', 'cookies' => []];
                }
                return ['status' => 302, 'body' => '', 'cookies' => isset($options['cookie'])
                    ? ['IPCZ-X-COOKIE' => 'session-cookie-123']
                    : ['S-COOKIE' => 'state-cookie-123']];
            },
        );
        $start = $authenticator->start(7, 11, 'test', 'synthetic-user', 'synthetic-app-password');
        self::assertNotNull($authenticator->continue($start['flow_token'], 7, 11, 'test')['context']);

        $this->expectException(SubmissionChannelException::class);
        $authenticator->continue($start['flow_token'], 7, 11, 'test');
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
