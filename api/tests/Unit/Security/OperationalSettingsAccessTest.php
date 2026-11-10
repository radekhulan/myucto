<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\OperationalSettingsAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class OperationalSettingsAccessTest extends TestCase
{
    private const AREAS = [
        'emailProfiles' => 'settings.email_profiles',
        'emailTemplates' => 'settings.email_templates',
        'branding' => 'settings.branding',
        'invoiceDefaults' => 'settings.invoice_defaults',
        'paymentQr' => 'settings.payment_qr',
        'clientSigningProfiles' => 'settings.signing_profiles',
    ];

    public static function grants(): iterable
    {
        foreach (['client', 'staff'] as $type) {
            foreach (self::AREAS as $area => $permission) {
                foreach ([0, 1, 2] as $level) yield "$type $area $level" => [$type, $area, $permission, $level];
            }
        }
    }

    #[DataProvider('grants')]
    public function testOnlyTargetedWriteGrantOpensEachEditor(string $type, string $area, string $permission, int $level): void
    {
        $request = $this->request(new EffectiveRole(4, 'Delegovaná role', $type, true, [
            'settings.company' => 2,
            'settings.company.write' => 2,
            'settings.signing' => 2,
            $permission => $level,
        ]));
        foreach (self::AREAS as $checkedArea => $checkedPermission) {
            self::assertSame(
                // Firemní podpisy dostane klient i interní role, a to jen vlastním právem:
                // široké `settings.signing` ani `settings.company.write` úzký editor neotevřou.
                $checkedPermission === $permission && $level === 2,
                OperationalSettingsAccess::$checkedArea($request),
                $checkedArea,
            );
        }
    }

    public function testRevocationCannotFallBackToBroadCompanyPermission(): void
    {
        $request = $this->request(new EffectiveRole(4, 'Klient', 'client', true, [
            'settings.company' => 2,
            'settings.company.write' => 2,
            'settings.signing' => 2,
        ]));
        foreach (self::AREAS as $area => $_) self::assertFalse(OperationalSettingsAccess::$area($request));
    }

    public function testInactiveRoleCannotOpenEditors(): void
    {
        $request = $this->request(new EffectiveRole(4, 'Klient', 'client', false, array_fill_keys(array_values(self::AREAS), 2)));
        foreach (self::AREAS as $area => $_) self::assertFalse(OperationalSettingsAccess::$area($request));
    }

    public function testSuperadminKeepsNativeSettingsAccess(): void
    {
        $request = $this->request(new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin'));
        foreach (self::AREAS as $area => $_) {
            self::assertSame($area !== 'clientSigningProfiles', OperationalSettingsAccess::$area($request));
        }
    }

    private function request(EffectiveRole $role): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/settings/client/branding')
            ->withAttribute('auth.effective_role', $role);
    }
}
