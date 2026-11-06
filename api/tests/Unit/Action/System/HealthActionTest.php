<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\System;

use MyInvoice\Action\System\HealthAction;
use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\System\AppUrlConfiguration;
use MyInvoice\Service\System\InstanceSecurityConfigurationCheck;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Tenant\TenantDomainContext;
use MyInvoice\Service\Update\VersionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class HealthActionTest extends TestCase
{
    public function testAuthenticatedHealthReportsUnavailablePasskeyConfiguration(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('ping')->willReturn(true);
        $redis = $this->createMock(RedisProbe::class);
        $redis->method('isAvailable')->willReturn(false);
        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->method('validateKey')->willReturn(null);
        $version = $this->createMock(VersionService::class);
        $version->method('getCurrentVersion')->willReturn('test');
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::once())->method('isAvailable')->willReturn(false);
        $passkeys->expects(self::once())
            ->method('configurationError')
            ->willReturn('WebAuthn vyžaduje HTTPS.');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::once())->method('isMethodAllowed')->with('passkey')->willReturn(true);
        $action = new HealthAction(
            $db,
            $redis,
            $crypto,
            $version,
            $passkeys,
            $policy,
            new SessionLockPolicy(new Config([])),
            $this->appUrl('https://invoice.example.test'),
        );

        $response = $action(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/health')
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17]),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([[
            'code' => 'webauthn_configuration',
            'message' => 'WebAuthn vyžaduje HTTPS.',
        ]], $body['warnings']);
    }

    public function testAnonymousHealthDoesNotResolveSensitiveWarnings(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('ping')->willReturn(true);
        $redis = $this->createMock(RedisProbe::class);
        $redis->method('isAvailable')->willReturn(false);
        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->expects(self::never())->method('validateKey');
        $version = $this->createMock(VersionService::class);
        $version->method('getCurrentVersion')->willReturn('test');
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::never())->method('isAvailable');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::never())->method('isMethodAllowed');
        $sensitiveUrl = 'https://health-user:health-password@example.test/'
            . '?token=health-secret';
        $action = new HealthAction(
            $db,
            $redis,
            $crypto,
            $version,
            $passkeys,
            $policy,
            new SessionLockPolicy(new Config([])),
            $this->appUrl($sensitiveUrl),
        );

        $response = $action(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/health'),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('warnings', $body);
        self::assertSame([
            'state' => AppUrlConfiguration::STATE_INVALID,
            'reason_code' => AppUrlConfiguration::REASON_INVALID_ORIGIN,
            'routing_compatible' => false,
            'webauthn_compatible' => false,
        ], $body['configuration']['app_url']);
        foreach ([$sensitiveUrl, 'health-user', 'health-password', 'health-secret', 'example.test'] as $secret) {
            self::assertStringNotContainsString($secret, (string) $response->getBody());
        }
    }

    public function testAuthenticatedHealthReportsInvalidSessionLockConfiguration(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('ping')->willReturn(true);
        $redis = $this->createMock(RedisProbe::class);
        $redis->method('isAvailable')->willReturn(false);
        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->method('validateKey')->willReturn(null);
        $version = $this->createMock(VersionService::class);
        $version->method('getCurrentVersion')->willReturn('test');
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::never())->method('isAvailable');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::once())
            ->method('isMethodAllowed')
            ->with('passkey')
            ->willReturn(false);
        $action = new HealthAction(
            $db,
            $redis,
            $crypto,
            $version,
            $passkeys,
            $policy,
            new SessionLockPolicy(new Config([
                'session' => ['lock_after_minutes' => 'invalid'],
            ])),
            $this->appUrl('http://192.168.10.20:8080'),
        );

        $response = $action(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/health')
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17]),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('session_lock_configuration', $body['warnings'][0]['code']);
        self::assertStringContainsString(
            'výchozí automatický zámek byl vypnut',
            $body['warnings'][0]['message'],
        );
    }

    public function testHealthReportsCanonicalHostnameConflictWithoutLeakingHostname(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('ping')->willReturn(true);
        $redis = $this->createStub(RedisProbe::class);
        $redis->method('isAvailable')->willReturn(false);
        $version = $this->createStub(VersionService::class);
        $version->method('getCurrentVersion')->willReturn('test');
        $action = new HealthAction(
            $db,
            $redis,
            $this->createStub(SecretEncryption::class),
            $version,
            $this->createStub(PasskeyService::class),
            $this->createStub(MfaPolicyService::class),
            new SessionLockPolicy(new Config([])),
            $this->appUrl('https://private-portal.example.test'),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/health')
            ->withAttribute(
                TenantDomainMiddleware::ATTR_CONTEXT,
                new TenantDomainContext(
                    TenantDomainContext::CONFIGURATION_ERROR,
                    'private-portal.example.test',
                    'https://private-portal.example.test',
                ),
            );

        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([
            'state' => AppUrlConfiguration::STATE_HOSTNAME_CONFLICT,
            'reason_code' => AppUrlConfiguration::REASON_HOSTNAME_CONFLICT,
            'routing_compatible' => false,
            'webauthn_compatible' => false,
        ], $body['configuration']['app_url']);
        self::assertStringNotContainsString('private-portal.example.test', (string) $response->getBody());
    }

    public function testAuthenticatedHealthReportsInsecureProductionDefaults(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('ping')->willReturn(true);
        $redis = $this->createStub(RedisProbe::class);
        $redis->method('isAvailable')->willReturn(false);
        $crypto = $this->createStub(SecretEncryption::class);
        $crypto->method('validateKey')->willReturn(null);
        $version = $this->createStub(VersionService::class);
        $version->method('getCurrentVersion')->willReturn('test');
        $policy = $this->createStub(MfaPolicyService::class);
        $policy->method('isMethodAllowed')->willReturn(false);
        $config = new Config([
            'app' => ['env' => 'development', 'url' => 'https://invoice.example.test'],
            'session' => ['cookie_secure' => false],
        ]);
        $action = new HealthAction(
            $db,
            $redis,
            $crypto,
            $version,
            $this->createStub(PasskeyService::class),
            $policy,
            new SessionLockPolicy(new Config([])),
            $this->appUrl('https://invoice.example.test'),
            null,
            new InstanceSecurityConfigurationCheck($config),
        );

        $authenticated = $action(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/health')
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17]),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $authenticated->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([
            InstanceSecurityConfigurationCheck::CODE_COOKIE_INSECURE,
            InstanceSecurityConfigurationCheck::CODE_ENV_DEVELOPMENT,
            InstanceSecurityConfigurationCheck::CODE_PAYROLL_HASH_KEY,
        ], array_column($body['warnings'], 'code'));

        $anonymous = $action(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/health'),
            (new ResponseFactory())->createResponse(),
        );
        self::assertArrayNotHasKey(
            'warnings',
            json_decode((string) $anonymous->getBody(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    private function appUrl(string $url): AppUrlConfiguration
    {
        return new AppUrlConfiguration(
            new Config(['app' => ['url' => $url]]),
            new HostnameNormalizer(),
            new NullLogger(),
        );
    }
}
