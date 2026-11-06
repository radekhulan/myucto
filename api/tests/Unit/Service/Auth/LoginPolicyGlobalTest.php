<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;
use MyInvoice\Tests\Support\FakeOidcIdp;
use PHPUnit\Framework\TestCase;

/**
 * Vynucení poskytovatele pro celou instalaci (`auth.oidc.required_provider`).
 * Rozhoduje dřív než nastavení firem, takže databázi nepotřebuje; kdyby na ni
 * sáhlo, stub bez PDO test shodí.
 */
final class LoginPolicyGlobalTest extends TestCase
{
    private function policy(string $required, bool $oidcEnabled = true, bool $skipMfa = true): LoginPolicyService
    {
        $config = new Config([
            'app' => ['url' => 'https://ucto.example.test'],
            'auth' => ['oidc' => [
                'enabled' => $oidcEnabled,
                'break_glass_roles' => ['instance_admin'],
                'required_provider' => $required,
                'providers' => [
                    'entra' => [
                        'enabled' => true,
                        'tenant_id' => FakeOidcIdp::TENANT,
                        'client_id' => FakeOidcIdp::CLIENT,
                        'client_secret' => 'test-secret',
                        'skip_mfa' => $skipMfa,
                    ],
                    'seznam' => [
                        'enabled' => true,
                        'client_id' => 'seznam-client',
                        'client_secret' => 'seznam-secret',
                    ],
                ],
            ]],
        ]);
        return new LoginPolicyService($this->createStub(Connection::class), $config, new OidcProviderRegistry($config));
    }

    public function testGlobalProviderAppliesToEveryCompanyAndUser(): void
    {
        $policy = $this->policy('entra');

        self::assertSame('entra', $policy->globalRequiredProvider());
        // I firma, která sama nic nevynucuje, a uživatel bez jediné firmy (superadmin).
        self::assertSame('entra', $policy->requiredProviderForSupplier(42));
        self::assertSame('entra', $policy->requiredProviderForUser(7, 'admin'));
        self::assertSame('entra', $policy->requiredProviderForUser(7, null));
        self::assertFalse($policy->sessionAllowedForSupplier(42, 'password', 'admin'));
        self::assertFalse($policy->sessionAllowedForSupplier(42, 'passkey', null));
        self::assertTrue($policy->sessionAllowedForSupplier(42, 'oidc:entra', 'admin'));
    }

    public function testInstanceAdminStaysOnPasswordAsBreakGlass(): void
    {
        $policy = $this->policy('entra');

        self::assertNull($policy->requiredProviderForUser(1, 'instance_admin'));
        self::assertTrue($policy->sessionAllowedForSupplier(42, 'password', 'instance_admin'));
    }

    public function testEntraWithLocalMfaStillEnforcesSso(): void
    {
        $policy = $this->policy('entra', skipMfa: false);

        self::assertSame('entra', $policy->globalRequiredProvider());
        self::assertSame('entra', $policy->requiredProviderForUser(7, null));
        self::assertSame('entra', $policy->requiredProviderForSupplier(42));
        self::assertFalse($policy->sessionAllowedForSupplier(42, 'password', null));
        self::assertTrue($policy->sessionAllowedForSupplier(42, 'oidc:entra', null));
    }

    public function testFirstFactorOrUnknownProviderNeverEnforcesGlobally(): void
    {
        // Seznam nese jen první faktor, překlep nezná registr. Ani jedno nesmí
        // zamknout instalaci; rozhodování pak padá na nastavení firem.
        self::assertNull($this->policy('seznam')->globalRequiredProvider());
        self::assertNull($this->policy('microsoft')->globalRequiredProvider());
        self::assertNull($this->policy('')->globalRequiredProvider());
    }

    public function testDisabledOidcDisablesGlobalEnforcement(): void
    {
        $policy = $this->policy('entra', oidcEnabled: false);

        self::assertNull($policy->globalRequiredProvider());
        self::assertNull($policy->requiredProviderForUser(7, null));
        self::assertNull($policy->requiredProviderForSupplier(42));
        self::assertTrue($policy->sessionAllowedForSupplier(42, 'password', null));
    }
}
