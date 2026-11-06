<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\System\InstanceSecurityConfigurationCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstanceSecurityConfigurationCheckTest extends TestCase
{
    private const HASH_KEY = 'aGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGg=';

    public function testProductionHttpsWithSecureCookieAndHashKeyHasNoWarning(): void
    {
        self::assertSame([], $this->codes([
            'app' => ['env' => 'production', 'url' => 'https://ucto.example.test', 'payroll_hash_key' => self::HASH_KEY],
            'session' => ['cookie_secure' => true],
        ]));
    }

    public function testLocalHttpInstallFromDockerInstallerHasNoCookieOrEnvWarning(): void
    {
        self::assertSame([], $this->codes([
            'app' => ['env' => 'production', 'url' => 'http://localhost:8080', 'payroll_hash_key' => self::HASH_KEY],
            'session' => ['cookie_secure' => false],
        ]));
        self::assertSame([], $this->codes([
            'app' => ['env' => 'development', 'url' => 'http://127.0.0.1:8080', 'payroll_hash_key' => self::HASH_KEY],
            'session' => ['cookie_secure' => false],
        ]));
    }

    public function testHttpsWithInsecureCookieWarns(): void
    {
        self::assertSame([InstanceSecurityConfigurationCheck::CODE_COOKIE_INSECURE], $this->codes([
            'app' => ['env' => 'production', 'url' => 'https://ucto.example.test', 'payroll_hash_key' => self::HASH_KEY],
            'session' => ['cookie_secure' => false],
        ]));
    }

    public function testCookieSecureFromEnvStringIsHonoured(): void
    {
        self::assertSame([InstanceSecurityConfigurationCheck::CODE_COOKIE_INSECURE], $this->codes([
            'app' => ['url' => 'https://ucto.example.test', 'payroll_hash_key' => self::HASH_KEY],
            'session' => ['cookie_secure' => 'false'],
        ]));
    }

    /** @return iterable<string,array{string}> */
    public static function publicUrls(): iterable
    {
        yield 'https' => ['https://ucto.example.test'];
        yield 'public http' => ['http://ucto.example.test'];
        yield 'lan ip' => ['http://192.168.10.20:8080'];
    }

    #[DataProvider('publicUrls')]
    public function testDevelopmentEnvOnPublicAddressWarns(string $url): void
    {
        self::assertContains(InstanceSecurityConfigurationCheck::CODE_ENV_DEVELOPMENT, $this->codes([
            'app' => ['env' => 'development', 'url' => $url, 'payroll_hash_key' => self::HASH_KEY],
            'session' => ['cookie_secure' => true],
        ]));
    }

    public function testMissingPayrollHashKeyWarnsWithoutLeakingSecrets(): void
    {
        $check = new InstanceSecurityConfigurationCheck(new Config([
            'app' => ['env' => 'production', 'url' => 'https://ucto.example.test', 'pepper' => 'pepper-secret-value'],
            'session' => ['cookie_secure' => true],
        ]));

        $warnings = $check->warnings();

        self::assertSame([InstanceSecurityConfigurationCheck::CODE_PAYROLL_HASH_KEY], array_column($warnings, 'code'));
        self::assertStringNotContainsString('pepper-secret-value', json_encode($warnings, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string,mixed> $config
     * @return list<string>
     */
    private function codes(array $config): array
    {
        return array_column((new InstanceSecurityConfigurationCheck(new Config($config)))->warnings(), 'code');
    }
}
