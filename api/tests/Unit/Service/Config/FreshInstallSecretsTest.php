<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Config;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Config\FreshInstallSecrets;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PHPUnit\Framework\TestCase;

final class FreshInstallSecretsTest extends TestCase
{
    public function testGeneratesStrongPayrollHashKeyWhenMissing(): void
    {
        $keys = FreshInstallSecrets::forFreshInstall(new Config(['app' => ['pepper' => 'p']]));

        self::assertArrayHasKey(FreshInstallSecrets::KEY_PAYROLL_HASH, $keys);
        $decoded = base64_decode($keys[FreshInstallSecrets::KEY_PAYROLL_HASH], true);
        self::assertIsString($decoded);
        self::assertSame(32, strlen($decoded));
        self::assertNotSame(
            $keys[FreshInstallSecrets::KEY_PAYROLL_HASH],
            FreshInstallSecrets::forFreshInstall(new Config([]))[FreshInstallSecrets::KEY_PAYROLL_HASH],
        );
    }

    public function testNeverReplacesConfiguredKey(): void
    {
        $configured = base64_encode(str_repeat('k', 32));

        self::assertSame([], FreshInstallSecrets::forFreshInstall(
            new Config(['app' => ['payroll_hash_key' => $configured]]),
        ));
    }

    /**
     * Generovaný klíč musí projít validací, kterou PayrollSensitiveData dělá
     * při každém otisku, jinak by čerstvá instalace mzdy vůbec nespustila.
     */
    public function testGeneratedKeyIsAcceptedByPayrollHashing(): void
    {
        $keys = FreshInstallSecrets::forFreshInstall(new Config([]));
        $config = new Config(['app' => [
            'pepper' => 'p',
            'payroll_hash_key' => $keys[FreshInstallSecrets::KEY_PAYROLL_HASH],
        ]]);
        $data = new PayrollSensitiveData(new SecretEncryption($config), $config);

        self::assertNotSame('', $data->lookupHash('8001011234', PayrollSensitiveField::PERSONAL_IDENTIFIER, 1));
    }
}
