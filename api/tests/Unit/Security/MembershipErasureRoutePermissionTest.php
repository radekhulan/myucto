<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Výmaz osobních údajů bývalých členů (krok 10 #321) je nevratný. Návrhy ukazují jména členů před výmazem,
 * proto i čtení vyžaduje samostatné právo, ne jen čtení účetnictví. Výchozí účetní role ho nedostane.
 */
final class MembershipErasureRoutePermissionTest extends TestCase
{
    #[DataProvider('routes')]
    public function testErasureRoutesUseTheErasurePermission(string $method, string $path, AccessLevel $level): void
    {
        $match = (new RoutePermissionMap())->match($method, $path);

        self::assertNotNull($match, "$method $path");
        self::assertSame(RoutePermissionMap::PERMISSION, $match->kind, "$method $path");
        self::assertSame('accounting.membership_erasure', $match->key, "$method $path");
        self::assertSame($level, $match->minimum, "$method $path");
    }

    /** @return iterable<string,array{string,string,AccessLevel}> */
    public static function routes(): iterable
    {
        yield 'posouzení' => ['GET', '/api/accounting/nonprofit-membership/erasure/assessment', AccessLevel::READ];
        yield 'seznam návrhů' => ['GET', '/api/accounting/nonprofit-membership/erasure', AccessLevel::READ];
        yield 'detail návrhu' => ['GET', '/api/accounting/nonprofit-membership/erasure/3', AccessLevel::READ];
        yield 'sestavení' => ['POST', '/api/accounting/nonprofit-membership/erasure', AccessLevel::WRITE];
        foreach (['approve', 'revoke', 'reject', 'execute'] as $action) {
            yield $action => ['POST', '/api/accounting/nonprofit-membership/erasure/3/' . $action, AccessLevel::WRITE];
        }
    }

    public function testDefaultAccountantRoleDoesNotGetErasure(): void
    {
        $catalog = new PermissionCatalog();
        self::assertTrue($catalog->has('accounting.membership_erasure'));
        self::assertTrue($catalog->allowsRoleType('accounting.membership_erasure', 'staff'));
        self::assertArrayNotHasKey('accounting.membership_erasure', $catalog->legacyPreset('accountant'));
        self::assertArrayHasKey('accounting.journal.post', $catalog->legacyPreset('accountant'), 'Účetní dál smí zapisovat členskou evidenci.');
    }
}
