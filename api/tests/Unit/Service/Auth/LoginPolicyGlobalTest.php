<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;
use MyInvoice\Tests\Support\FakeOidcIdp;
use PHPUnit\Framework\TestCase;

/**
 * Vynucení poskytovatele pro celou instalaci (`auth.oidc.required_provider`).
 * Jiné vynucení neexistuje: firmy ani role (správce instance) výjimku nemají.
 */
final class LoginPolicyGlobalTest extends TestCase
{
    private function policy(string $required, bool $oidcEnabled = true, bool $skipMfa = true): LoginPolicyService
    {
        $config = new Config([
            'app' => ['url' => 'https://ucto.example.test'],
            'auth' => ['oidc' => [
                'enabled' => $oidcEnabled,
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
        return new LoginPolicyService($config, new OidcProviderRegistry($config));
    }

    public function testEnforcedProviderAllowsOnlyItsSessions(): void
    {
        $policy = $this->policy('entra');

        self::assertSame('entra', $policy->requiredProvider());
        self::assertTrue($policy->isEnforced());
        self::assertTrue($policy->authMethodAllowed('oidc:entra'));
        self::assertFalse($policy->authMethodAllowed('password'));
        self::assertFalse($policy->authMethodAllowed('passkey'));
        self::assertFalse($policy->authMethodAllowed('oidc:seznam'));
        self::assertFalse($policy->authMethodAllowed(null));
    }

    public function testStrongProviderConfirmsOperationsOnlyItself(): void
    {
        $policy = $this->policy('entra');

        self::assertTrue($policy->stepUpMethodAllowed('oidc'));
        self::assertFalse($policy->stepUpMethodAllowed('password'));
        self::assertFalse($policy->stepUpMethodAllowed('passkey'));
        self::assertFalse($policy->stepUpMethodAllowed('totp'));
        self::assertFalse($policy->stepUpMethodAllowed('recovery'));
    }

    public function testFirstFactorEntraKeepsLocalFactorsButNeverPassword(): void
    {
        $policy = $this->policy('entra', skipMfa: false);

        self::assertSame('entra', $policy->requiredProvider());
        self::assertFalse($policy->authMethodAllowed('password'));
        self::assertTrue($policy->authMethodAllowed('oidc:entra'));
        self::assertFalse($policy->stepUpMethodAllowed('password'));
        self::assertFalse($policy->stepUpMethodAllowed('oidc'));
        self::assertTrue($policy->stepUpMethodAllowed('passkey'));
        self::assertTrue($policy->stepUpMethodAllowed('totp'));
    }

    public function testFirstFactorOrUnknownProviderNeverEnforces(): void
    {
        // Seznam nese jen první faktor, překlep nezná registr. Ani jedno nesmí
        // zamknout instalaci.
        self::assertNull($this->policy('seznam')->requiredProvider());
        self::assertNull($this->policy('microsoft')->requiredProvider());
        self::assertNull($this->policy('')->requiredProvider());
    }

    public function testDisabledOidcOrNoEnforcementAllowsEverything(): void
    {
        foreach ([$this->policy('entra', oidcEnabled: false), $this->policy('')] as $policy) {
            self::assertNull($policy->requiredProvider());
            self::assertFalse($policy->isEnforced());
            self::assertTrue($policy->authMethodAllowed('password'));
            self::assertTrue($policy->stepUpMethodAllowed('password'));
            self::assertTrue($policy->stepUpMethodAllowed('passkey'));
        }
    }
}
