<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\{AccessLevel,PermissionCatalog,RoutePermissionMap};
use MyInvoice\Service\Property\Services\Settlement\ServiceLifecycleWorkflow;
use PHPUnit\Framework\TestCase;

/** Rozpouštění dohadů služeb má vlastní právo; výchozí účetní ho má, storno ne. */
final class ServiceLifecycleUnwindPermissionTest extends TestCase
{
    public function testUnwindNeedsOwnPermissionThatAccountantHasByDefault(): void
    {
        self::assertSame('property.settlements.unwind', ServiceLifecycleWorkflow::permission('unwind'));
        $catalog = new PermissionCatalog();
        $accountant = $catalog->legacyPreset('accountant');
        self::assertSame(2, $accountant['property.settlements.unwind'] ?? 0);
        self::assertSame(0, $accountant['property.settlements.reverse'] ?? 0);
        foreach (['/api/property/service-lifecycles/preview/unwind', '/api/property/service-lifecycles/commands/unwind'] as $path) {
            $match = (new RoutePermissionMap())->match('POST', $path);
            self::assertSame('property.settlements.unwind', $match?->key);
            self::assertSame(AccessLevel::WRITE, $match?->minimum);
        }
    }
}
