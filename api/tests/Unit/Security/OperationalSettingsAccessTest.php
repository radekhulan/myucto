<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\OperationalSettingsAccess;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class OperationalSettingsAccessTest extends TestCase
{
    public function testClientNeedsCompanyWriteForDelegatedAreas(): void
    {
        $reader = $this->request(new EffectiveRole(4, 'Klient', 'client', true, [
            'settings.company' => AccessLevel::READ->value,
        ]));
        self::assertFalse(OperationalSettingsAccess::emailProfiles($reader));
        self::assertFalse(OperationalSettingsAccess::branding($reader));
        self::assertFalse(OperationalSettingsAccess::paymentQr($reader));

        $writer = $this->request(new EffectiveRole(4, 'Klient', 'client', true, [
            'settings.company' => AccessLevel::WRITE->value,
        ]));
        self::assertTrue(OperationalSettingsAccess::emailProfiles($writer));
        self::assertTrue(OperationalSettingsAccess::branding($writer));
        self::assertTrue(OperationalSettingsAccess::paymentQr($writer));
    }

    public function testClientCannotSubstituteStaffOnlyPermissions(): void
    {
        $request = $this->request(new EffectiveRole(4, 'Klient', 'client', true, [
            'settings.company' => AccessLevel::READ->value,
            'settings.company.write' => AccessLevel::WRITE->value,
            'settings.branding' => AccessLevel::WRITE->value,
        ]));

        self::assertFalse(OperationalSettingsAccess::emailProfiles($request));
        self::assertFalse(OperationalSettingsAccess::branding($request));
        self::assertFalse(OperationalSettingsAccess::paymentQr($request));
    }

    public function testStaffAndSuperadminKeepTheirExistingPermissions(): void
    {
        $staff = $this->request(new EffectiveRole(2, 'Správce', 'staff', true, [
            'settings.company.write' => AccessLevel::WRITE->value,
            'settings.branding' => AccessLevel::WRITE->value,
        ]));
        self::assertTrue(OperationalSettingsAccess::emailProfiles($staff));
        self::assertTrue(OperationalSettingsAccess::branding($staff));
        self::assertTrue(OperationalSettingsAccess::paymentQr($staff));

        $superadmin = $this->request(new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin'));
        self::assertTrue(OperationalSettingsAccess::emailProfiles($superadmin));
        self::assertTrue(OperationalSettingsAccess::branding($superadmin));
        self::assertTrue(OperationalSettingsAccess::paymentQr($superadmin));
    }

    private function request(EffectiveRole $role): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/settings/client/branding')
            ->withAttribute('auth.effective_role', $role);
    }
}
