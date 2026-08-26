<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\PermissionMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Tenant\SupplierAccess;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PermissionMiddlewareTest extends TestCase
{
    public function testUnknownRouteFailsClosed(): void
    {
        $response = $this->middleware(new EffectiveRole(2, 'Staff', 'staff', true), new SupplierAccess(1, false, null))
            ->process($this->request('GET', '/api/future-feature'), $this->handler());
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('forbidden_permission', (string) $response->getBody());
    }

    public function testMappedPermissionAllowsOnlyRequiredLevel(): void
    {
        $read = new EffectiveRole(2, 'Reader', 'staff', true, ['invoices' => 1]);
        $middleware = $this->middleware($read, new SupplierAccess(1, false, null));
        self::assertSame(204, $middleware->process($this->request('GET', '/api/invoices'), $this->handler())->getStatusCode());
        self::assertSame(403, $middleware->process($this->request('POST', '/api/invoices'), $this->handler())->getStatusCode());
    }

    public function testPayrollAccountOptionsRequirePayrollSettingsWithoutAccountingPermission(): void
    {
        $payrollOnly = new EffectiveRole(2, 'Mzdová účetní', 'staff', true, ['payroll.settings' => 1]);
        self::assertSame(
            204,
            $this->middleware($payrollOnly, new SupplierAccess(1, false, null))
                ->process($this->request('GET', '/api/payroll/settings/account-options'), $this->handler())
                ->getStatusCode(),
        );

        $accountingOnly = new EffectiveRole(3, 'Účetní', 'staff', true, ['accounting' => 2]);
        self::assertSame(
            403,
            $this->middleware($accountingOnly, new SupplierAccess(1, false, null))
                ->process($this->request('GET', '/api/payroll/settings/account-options'), $this->handler())
                ->getStatusCode(),
        );
    }

    public function testProductionQualificationRequiresPayrollSettingsWrite(): void
    {
        $access = new SupplierAccess(1, false, null);
        $path = '/api/payroll/settings/activation/production-qualification';
        $writer = new EffectiveRole(
            2,
            'Správce mzdového nastavení',
            'staff',
            true,
            ['payroll.settings' => AccessLevel::WRITE->value],
        );
        self::assertSame(
            204,
            $this->middleware($writer, $access)
                ->process($this->request('POST', $path), $this->handler())
                ->getStatusCode(),
        );

        foreach ([
            new EffectiveRole(
                3,
                'Čtenář mzdového nastavení',
                'staff',
                true,
                ['payroll.settings' => AccessLevel::READ->value],
            ),
            new EffectiveRole(
                4,
                'Schvalovatel mezd',
                'staff',
                true,
                ['payroll.approve' => AccessLevel::WRITE->value],
            ),
        ] as $role) {
            self::assertSame(
                403,
                $this->middleware($role, $access)
                    ->process($this->request('POST', $path), $this->handler())
                    ->getStatusCode(),
            );
        }
    }

    public function testMissingMembershipHasDedicatedError(): void
    {
        $role = new EffectiveRole(2, 'Writer', 'staff', true, ['invoices' => 2]);
        $response = $this->middleware($role, new SupplierAccess(9, true, null))
            ->process($this->request('GET', '/api/invoices'), $this->handler());
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('forbidden_supplier', (string) $response->getBody());
    }

    public function testClosingWorkflowRoutesRequirePeriodsCloseNotManage(): void
    {
        // EP-14: rodina /periods/{id}/(closing|close|open-next|revert) běží na
        // accounting.periods.close; role s pouhým periods.manage (schvalování) sem NESMÍ.
        $closer = new EffectiveRole(2, 'Closer', 'staff', true, ['accounting.periods.close' => 2]);
        $mwClose = $this->middleware($closer, new SupplierAccess(1, false, null));
        self::assertSame(204, $mwClose->process($this->request('POST', '/api/accounting/periods/5/close'), $this->handler())->getStatusCode());
        self::assertSame(204, $mwClose->process($this->request('POST', '/api/accounting/periods/5/closing/start'), $this->handler())->getStatusCode());
        self::assertSame(204, $mwClose->process($this->request('POST', '/api/accounting/periods/5/open-next'), $this->handler())->getStatusCode());
        // Změna stavu období (schválení) vyžaduje periods.manage → 403 pro closera.
        self::assertSame(403, $mwClose->process($this->request('POST', '/api/accounting/periods/5/status'), $this->handler())->getStatusCode());

        $manager = new EffectiveRole(3, 'Manager', 'staff', true, ['accounting.periods.manage' => 2]);
        $mwManage = $this->middleware($manager, new SupplierAccess(1, false, null));
        self::assertSame(204, $mwManage->process($this->request('POST', '/api/accounting/periods/5/status'), $this->handler())->getStatusCode());
        // A naopak: manager bez periods.close se nedostane do uzávěrkového workflow.
        self::assertSame(403, $mwManage->process($this->request('POST', '/api/accounting/periods/5/close'), $this->handler())->getStatusCode());
        self::assertSame(403, $mwManage->process($this->request('POST', '/api/accounting/periods/5/closing/start'), $this->handler())->getStatusCode());
    }

    public function testAdminRouteRequiresSuperadmin(): void
    {
        $staff = new EffectiveRole(2, 'Staff', 'staff', true, ['profile' => 2]);
        self::assertSame(403, $this->middleware($staff, new SupplierAccess(0, true, null))
            ->process($this->request('GET', '/api/admin/users'), $this->handler())->getStatusCode());

        $superadmin = new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin');
        self::assertSame(204, $this->middleware($superadmin, new SupplierAccess(0, false, null))
            ->process($this->request('GET', '/api/admin/users'), $this->handler())->getStatusCode());
    }

    private function middleware(EffectiveRole $role, SupplierAccess $access): PermissionMiddleware
    {
        $roles = $this->createStub(PermissionResolver::class);
        $roles->method('resolve')->willReturn($role);
        $suppliers = $this->createStub(SupplierAccessResolver::class);
        $suppliers->method('resolve')->willReturn($access);
        $catalog = new PermissionCatalog();
        return new PermissionMiddleware(
            new ResponseFactory(),
            new RoutePermissionMap(),
            $roles,
            new PermissionChecker($catalog),
            $suppliers,
        );
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 5, 'role_id' => 2]);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
