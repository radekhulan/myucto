<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tenant;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use MyInvoice\Service\Tenant\TenantDomainContext;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SupplierAccessResolverDynamicRolesTest extends TestCase
{
    public function testNonSuperadminWithoutMembershipIsDenied(): void
    {
        $memberships = $this->createStub(UserSupplierRepository::class);
        $memberships->method('assignmentsForUser')->willReturn([]);
        $resolver = new SupplierAccessResolver($this->createStub(Connection::class), $memberships);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 10,
                'role_id' => 2,
                'role' => ['id' => 2, 'type' => 'staff'],
                'is_superadmin' => false,
            ]);
        $access = $resolver->resolve($request);
        self::assertTrue($access->denied);
        self::assertSame(0, $access->supplierId);
    }

    public function testBoundTokenOutsideMembershipIsDenied(): void
    {
        $memberships = $this->createStub(UserSupplierRepository::class);
        $memberships->method('assignmentsForUser')->willReturn([11 => null]);
        $resolver = new SupplierAccessResolver($this->createStub(Connection::class), $memberships);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 10, 'role_id' => 2, 'role' => ['type' => 'staff']])
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['supplier_id' => 12]);
        $access = $resolver->resolve($request);
        self::assertTrue($access->denied);
        self::assertSame(12, $access->supplierId);
    }

    public function testBoundTokenReturnsNumericRoleOverride(): void
    {
        $memberships = $this->createStub(UserSupplierRepository::class);
        $memberships->method('assignmentsForUser')->willReturn([11 => 7]);
        $resolver = new SupplierAccessResolver($this->createStub(Connection::class), $memberships);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 10, 'role_id' => 2, 'role' => ['type' => 'staff']])
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['supplier_id' => 11]);
        $access = $resolver->resolve($request);
        self::assertFalse($access->denied);
        self::assertSame(7, $access->roleIdOverride);
    }

    public function testDomainSupplierCannotBeOverriddenByHeader(): void
    {
        $memberships = $this->createStub(UserSupplierRepository::class);
        $memberships->method('assignmentsForUser')->willReturn([11 => null, 12 => null]);
        $resolver = new SupplierAccessResolver($this->createStub(Connection::class), $memberships);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withHeader(SupplierScopeMiddleware::HEADER_NAME, '12')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 10, 'role_id' => 2])
            ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, $this->domain(11));

        $access = $resolver->resolve($request);

        self::assertTrue($access->denied);
        self::assertSame(11, $access->supplierId);
    }

    public function testDomainSupplierCannotBeOverriddenByQueryParameter(): void
    {
        $memberships = $this->createStub(UserSupplierRepository::class);
        $memberships->method('assignmentsForUser')->willReturn([11 => null, 12 => null]);
        $resolver = new SupplierAccessResolver($this->createStub(Connection::class), $memberships);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices?supplier_id=12')
            ->withQueryParams(['supplier_id' => '12'])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 10, 'role_id' => 2])
            ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, $this->domain(11));

        $access = $resolver->resolve($request);

        self::assertTrue($access->denied);
        self::assertSame(11, $access->supplierId);
    }

    public function testCustomDomainDeniesOrdinaryUserWithoutSupplierMembership(): void
    {
        $memberships = $this->createStub(UserSupplierRepository::class);
        $memberships->method('assignmentsForUser')->willReturn([12 => null]);
        $resolver = new SupplierAccessResolver($this->createStub(Connection::class), $memberships);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 10, 'role_id' => 2])
            ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, $this->domain(11));

        $access = $resolver->resolve($request);

        self::assertTrue($access->denied);
        self::assertSame(11, $access->supplierId);
    }

    public function testCustomDomainAllowsSuperadminWithoutSupplierMembership(): void
    {
        $memberships = $this->createStub(UserSupplierRepository::class);
        $memberships->method('assignmentsForUser')->willReturn([]);
        $resolver = new SupplierAccessResolver($this->createStub(Connection::class), $memberships);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 10,
                'role_id' => 1,
                'is_superadmin' => true,
                'role_summary' => ['system_key' => 'superadmin'],
            ])
            ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, $this->domain(11));

        $access = $resolver->resolve($request);

        self::assertFalse($access->denied);
        self::assertSame(11, $access->supplierId);
        self::assertNull($access->roleIdOverride);
    }

    public function testCustomDomainSuperadminKeepsGlobalRoleWhenMembershipAlsoExists(): void
    {
        $memberships = $this->createStub(UserSupplierRepository::class);
        $memberships->method('assignmentsForUser')->willReturn([11 => 7]);
        $resolver = new SupplierAccessResolver($this->createStub(Connection::class), $memberships);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 10,
                'role_id' => 1,
                'is_superadmin' => true,
                'role_summary' => ['system_key' => 'superadmin'],
            ])
            ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, $this->domain(11));

        $access = $resolver->resolve($request);

        self::assertFalse($access->denied);
        self::assertSame(11, $access->supplierId);
        self::assertNull($access->roleIdOverride);
    }

    public function testSuperadminKeepsBoundTokenAccessOutsideCustomDomain(): void
    {
        $memberships = $this->createMock(UserSupplierRepository::class);
        $memberships->expects(self::never())->method('assignmentsForUser');
        $resolver = new SupplierAccessResolver($this->createStub(Connection::class), $memberships);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 10,
                'role_id' => 1,
                'is_superadmin' => true,
                'role_summary' => ['system_key' => 'superadmin'],
            ])
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['supplier_id' => 11]);

        $access = $resolver->resolve($request);

        self::assertFalse($access->denied);
        self::assertSame(11, $access->supplierId);
        self::assertNull($access->roleIdOverride);
    }

    public function testDomainSupplierCannotBeOverriddenByBoundPat(): void
    {
        $memberships = $this->createStub(UserSupplierRepository::class);
        $memberships->method('assignmentsForUser')->willReturn([11 => null, 12 => null]);
        $resolver = new SupplierAccessResolver($this->createStub(Connection::class), $memberships);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 10, 'role_id' => 2])
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['supplier_id' => 12])
            ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, $this->domain(11));

        $access = $resolver->resolve($request);

        self::assertTrue($access->denied);
        self::assertSame(11, $access->supplierId);
    }

    public function testMatchingDomainUsesMembershipRoleOverride(): void
    {
        $memberships = $this->createStub(UserSupplierRepository::class);
        $memberships->method('assignmentsForUser')->willReturn([11 => 7]);
        $resolver = new SupplierAccessResolver($this->createStub(Connection::class), $memberships);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withHeader(SupplierScopeMiddleware::HEADER_NAME, '11')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 10, 'role_id' => 2])
            ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, $this->domain(11));

        $access = $resolver->resolve($request);

        self::assertFalse($access->denied);
        self::assertSame(11, $access->supplierId);
        self::assertSame(7, $access->roleIdOverride);
    }

    private function domain(int $supplierId): TenantDomainContext
    {
        return new TenantDomainContext(
            TenantDomainContext::CUSTOM,
            'portal.example.test',
            'https://portal.example.test',
            1,
            $supplierId,
            'all',
            'active',
        );
    }
}
