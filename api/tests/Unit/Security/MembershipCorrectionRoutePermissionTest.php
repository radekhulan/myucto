<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Opravy členské evidence (krok 7 #321) mění údaje, ze kterých vzniká účetní předpis, proto vyžadují
 * stejné právo jako zápis revize a předpisu. Čtení detailu člena zůstává na právu čtení deníku.
 */
final class MembershipCorrectionRoutePermissionTest extends TestCase
{
    #[DataProvider('routes')]
    public function testMembershipCorrectionRoutesUseExpectedPermission(string $method, string $path, string $key, AccessLevel $level): void
    {
        $match = (new RoutePermissionMap())->match($method, $path);

        self::assertNotNull($match, "$method $path");
        self::assertSame(RoutePermissionMap::PERMISSION, $match->kind, "$method $path");
        self::assertSame($key, $match->key, "$method $path");
        self::assertSame($level, $match->minimum, "$method $path");
    }

    /** @return iterable<string,array{string,string,string,AccessLevel}> */
    public static function routes(): iterable
    {
        yield 'oprava kontaktu a čísla' => ['PUT', '/api/accounting/nonprofit-membership/members/7', 'accounting.journal.post', AccessLevel::WRITE];
        yield 'smazání člena' => ['DELETE', '/api/accounting/nonprofit-membership/members/7', 'accounting.journal.post', AccessLevel::WRITE];
        yield 'zrušení revize' => ['POST', '/api/accounting/nonprofit-membership/members/7/revisions/3/void', 'accounting.journal.post', AccessLevel::WRITE];
        yield 'zápis revize' => ['POST', '/api/accounting/nonprofit-membership/members/7/revisions', 'accounting.journal.post', AccessLevel::WRITE];
    }

    public function testCorrectionRoutesDoNotMatchOtherShapes(): void
    {
        $map = new RoutePermissionMap();
        foreach ([['PUT', '/api/accounting/nonprofit-membership/members/7/revisions'], ['DELETE', '/api/accounting/nonprofit-membership/members/7/revisions/3'],
            ['POST', '/api/accounting/nonprofit-membership/members/7/revisions/3/void/x']] as [$method, $path]) {
            $match = $map->match($method, $path);
            self::assertTrue($match === null || $match->key !== 'accounting.journal.post' || $match->minimum !== AccessLevel::WRITE, "$method $path");
        }
    }
}
