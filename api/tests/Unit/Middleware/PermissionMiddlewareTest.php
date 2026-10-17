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
    public function testClientBankActionsAreIndependentAndRequireBankRead(): void
    {
        $operations = [
            ['bank.import', '/api/bank-statements/upload'],
            ['bank.import', '/api/bank-statements/upload-pdf'],
            ['bank.match', '/api/bank-transactions/7/match'],
            ['bank.match', '/api/bank-transactions/7/unmatch'],
            ['bank.match', '/api/bank-match-suggestions/7/accept'],
            ['bank.match', '/api/bank-match-suggestions/7/reject'],
            ['bank.ignore', '/api/bank-transactions/7/ignore'],
            ['bank.ignore', '/api/bank-transactions/7/unignore'],
        ];
        foreach ([null, 'bank.import', 'bank.match', 'bank.ignore'] as $granted) {
            foreach ([0, 1] as $read) {
                $permissions = ['bank' => $read];
                if ($granted !== null) $permissions[$granted] = 2;
                $role = new EffectiveRole(8, 'Synthetic bank client', 'client', true, $permissions);
                $middleware = $this->middleware($role, new SupplierAccess(1, false, null));
                foreach ($operations as [$required, $path]) {
                    $expected = $read === 1 && $granted === $required ? 204 : 403;
                    self::assertSame($expected, $middleware->process($this->request('POST', $path), $this->handler())->getStatusCode(), "$granted / bank=$read / $path");
                }
                self::assertSame($read === 1 ? 204 : 403, $middleware->process($this->request('GET', '/api/bank-statements'), $this->handler())->getStatusCode());
            }
        }
    }

    public function testStaffCustomBankRolesKeepIndependentActionsAndOriginalUnmatchPermission(): void
    {
        foreach (['bank.import' => '/api/bank-statements/upload', 'bank.match' => '/api/bank-transactions/7/match', 'bank.ignore' => '/api/bank-transactions/7/ignore'] as $key => $path) {
            $role = new EffectiveRole(8, 'Synthetic custom staff', 'staff', true, [$key => 2]);
            $middleware = $this->middleware($role, new SupplierAccess(1, false, null));
            self::assertSame(204, $middleware->process($this->request('POST', $path), $this->handler())->getStatusCode(), $key);
            self::assertSame(403, $middleware->process($this->request('GET', '/api/bank-statements'), $this->handler())->getStatusCode());
        }
        foreach ([['bank' => 2], ['bank.match' => 2]] as $permissions) {
            $role = new EffectiveRole(8, 'Synthetic custom staff', 'staff', true, $permissions);
            $middleware = $this->middleware($role, new SupplierAccess(1, false, null));
            self::assertSame(isset($permissions['bank']) ? 204 : 403,
                $middleware->process($this->request('POST', '/api/bank-transactions/7/unmatch'), $this->handler())->getStatusCode());
        }
        self::assertSame('bank', (new RoutePermissionMap())->match('POST', '/api/bank-transactions/7/unmatch')->key);
    }

    public function testClientBroadBankWriteCannotOpenStaffOperationsOnEitherHost(): void
    {
        $permissions = ['bank' => 2, 'bank.import' => 2, 'bank.match' => 2, 'bank.ignore' => 2];
        $middleware = $this->middleware(new EffectiveRole(8, 'Synthetic bank client', 'client', true, $permissions), new SupplierAccess(1, false, null));
        $domainPolicy = new \MyInvoice\Service\Tenant\ClientRoutePolicy();
        foreach ([
            ['POST', '/api/bank-statements/scan'],
            ['DELETE', '/api/bank-statements/7'],
            ['POST', '/api/bank-statements/7/pdf'],
            ['DELETE', '/api/bank-statements/7/pdf'],
            ['POST', '/api/bank-statements/7/rematch'],
            ['POST', '/api/bank-statements/7/notice-confirmations'],
            ['POST', '/api/bank-transactions/7/notice-confirmation'],
            ['GET', '/api/bank-statements/7/unmatched-recipients'],
            ['POST', '/api/bank-statements/7/send-unmatched'],
            ['GET', '/api/bank-statements/export-unmatched'],
            ['POST', '/api/bank-transactions/7/match-document'],
            ['GET', '/api/bank-transactions/payment-candidates'],
            ['POST', '/api/bank-transactions/7/create-purchase-invoice'],
            ['POST', '/api/bank-transactions/7/document-request'],
            ['POST', '/api/bank-transactions/7/post'],
            ['POST', '/api/bank-transactions/7/unpost'],
            ['GET', '/api/accounting/gopay/clearings'],
            ['POST', '/api/accounting/gopay/clearings/import'],
            ['GET', '/api/credit-cards'],
            ['POST', '/api/credit-cards/import'],
            ['POST', '/api/payment-cards/unmatched-payments/7/rematch'],
        ] as [$method, $path]) {
            self::assertSame(403, $middleware->process($this->request($method, $path), $this->handler())->getStatusCode(), "$method $path");
            self::assertFalse($domainPolicy->allowsApiRequest($method, $path), "$method $path");
        }
        foreach ([
            '/api/bank-statements', '/api/bank-statements/account-balances',
            '/api/bank-statements/7', '/api/bank-statements/7/pdf', '/api/bank-statements/7/download',
            '/api/bank-statements/7/export-gpc', '/api/bank-statements/7/match-suggestions',
            '/api/bank-transactions', '/api/bank-transactions/7/match-candidates',
            '/api/bank-transactions/7/split-suggestions',
        ] as $path) {
            self::assertSame(204, $middleware->process($this->request('GET', $path), $this->handler())->getStatusCode(), $path);
            self::assertTrue($domainPolicy->allowsApiRequest('GET', $path), $path);
        }
    }

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

    public function testOnlyAdminPlusAndSuperadminCanCreateSupplier(): void
    {
        $access = new SupplierAccess(0, true, null);
        $admin = new EffectiveRole(2, 'Admin', 'staff', true, ['settings.company.write' => 2], 'admin');
        $adminPlus = new EffectiveRole(3, 'Admin Plus', 'staff', true, ['settings.company.write' => 2], 'admin_plus');
        $superadmin = new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin');

        self::assertSame(403, $this->middleware($admin, $access)
            ->process($this->request('POST', '/api/suppliers'), $this->handler())->getStatusCode());
        self::assertSame(204, $this->middleware($adminPlus, $access)
            ->process($this->request('POST', '/api/suppliers'), $this->handler())->getStatusCode());
        self::assertSame(204, $this->middleware($superadmin, $access)
            ->process($this->request('POST', '/api/suppliers'), $this->handler())->getStatusCode());
    }

    public function testBankTemplateAdminRouteUsesCompanyPermission(): void
    {
        $reader = new EffectiveRole(2, 'Reader', 'staff', true, ['bank.rules' => 1]);
        $writer = new EffectiveRole(3, 'Writer', 'staff', true, ['bank.rules' => 2]);
        $access = new SupplierAccess(1, false, null);

        self::assertSame(204, $this->middleware($reader, $access)
            ->process($this->request('GET', '/api/admin/bank-rule-templates'), $this->handler())->getStatusCode());
        self::assertSame(403, $this->middleware($reader, $access)
            ->process($this->request('POST', '/api/admin/bank-rule-templates'), $this->handler())->getStatusCode());
        self::assertSame(204, $this->middleware($writer, $access)
            ->process($this->request('POST', '/api/admin/bank-rule-templates'), $this->handler())->getStatusCode());
    }

    private function middleware(EffectiveRole $role, SupplierAccess $access): PermissionMiddleware
    {
        $roles = $this->createStub(PermissionResolver::class);
        $roles->method('resolve')->willReturn($role);
        $roles->method('resolveDefault')->willReturn($role);
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
