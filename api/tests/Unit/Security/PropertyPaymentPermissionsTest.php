<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PermissionNotAllowedForRoleType;
use MyInvoice\Repository\RoleRepository;
use MyInvoice\Security\PermissionCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PropertyPaymentPermissionsTest extends TestCase
{
    public static function permissions(): array
    {
        return ['manage' => ['property.payments.manage'], 'post' => ['property.payments.post'], 'reverse' => ['property.payments.reverse']];
    }

    #[DataProvider('permissions')]
    public function testStaffCanBeAssignedPaymentPermission(string $permission): void
    {
        $catalog = new PermissionCatalog();
        self::assertTrue($catalog->has($permission));
        self::assertTrue($catalog->allowsRoleType($permission, 'staff'));
        $repo = new RoleRepository($this->createStub(Connection::class), $catalog);
        $method = new \ReflectionMethod($repo, 'validatePermissions');
        self::assertSame([$permission => 2], $method->invoke($repo, 'staff', [$permission => 2]));
    }

    #[DataProvider('permissions')]
    public function testClientCannotReceiveAccountingPaymentPermission(string $permission): void
    {
        $catalog = new PermissionCatalog();
        self::assertTrue($catalog->has($permission));
        $repo = new RoleRepository($this->createStub(Connection::class), $catalog);
        $method = new \ReflectionMethod($repo, 'validatePermissions');
        $this->expectException(PermissionNotAllowedForRoleType::class);
        $method->invoke($repo, 'client', [$permission => 2]);
    }

    public function testLegacyAccountantCanManageAndPostButNeedsExplicitReversalGrant(): void
    {
        $permissions = (new PermissionCatalog())->legacyPreset('accountant');
        self::assertSame(2, $permissions['property.payments.manage'] ?? 0);
        self::assertSame(2, $permissions['property.payments.post'] ?? 0);
        self::assertSame(0, $permissions['property.payments.reverse'] ?? 0);
    }
}
