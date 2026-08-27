<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\TestCase;

final class PayrollRunHistorySecurityContractTest extends TestCase
{
    public function testHistoryRouteRequiresPayrollRead(): void
    {
        $route = (new RoutePermissionMap())->match(
            'GET',
            '/api/payroll/runs/17/history',
        );

        self::assertNotNull($route);
        self::assertSame('payroll', $route->key);
        self::assertSame(AccessLevel::READ, $route->minimum);
    }
}
