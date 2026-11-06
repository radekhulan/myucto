<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\TestCase;

final class PohodaSchedulePermissionTest extends TestCase
{
    public function testSavingScheduleNeedsCompanySettingsWriteAndRunNeedsExportRight(): void
    {
        $map = new RoutePermissionMap();
        $put = $map->match('PUT', '/api/reports/pohoda-schedule');
        $this->assertSame('settings.company.write', $put?->key);
        $this->assertSame(AccessLevel::WRITE, $put?->minimum);

        $this->assertSame('utilities.export', $map->match('GET', '/api/reports/pohoda-schedule')?->key);
        $run = $map->match('POST', '/api/reports/pohoda-schedule/run');
        $this->assertSame('utilities.export', $run?->key);
        $this->assertSame(AccessLevel::WRITE, $run?->minimum);
    }

    public function testClientRoleTypeCannotHoldRecipientRight(): void
    {
        $catalog = new \MyInvoice\Security\PermissionCatalog();
        $this->assertFalse($catalog->allowsRoleType('settings.company.write', 'client'));
        $this->assertTrue($catalog->allowsRoleType('settings.company', 'client'), 'settings.company by klienta pustilo, proto se nepoužívá.');
    }
}
