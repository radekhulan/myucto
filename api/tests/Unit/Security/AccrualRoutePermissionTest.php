<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Agenda Časové rozlišení: čtení `accounting.accruals`, správa položek
 * `accounting.accruals.write`, zaúčtování (rozpuštění, ukončení a jejich storno) jako
 * zápis do deníku `accounting.journal.post`, roční rozpuštění v uzávěrce
 * `accounting.periods.close`. Nic se nesmí propadnout do obecného `accounting`.
 */
final class AccrualRoutePermissionTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, AccessLevel}> */
    public static function routes(): iterable
    {
        yield 'seznam' => ['GET', '/api/accounting/accruals', 'accounting.accruals', AccessLevel::READ];
        yield 'detail' => ['GET', '/api/accounting/accruals/5', 'accounting.accruals', AccessLevel::READ];
        yield 'odsouhlasení' => ['GET', '/api/accounting/accruals/reconciliation', 'accounting.accruals', AccessLevel::READ];
        yield 'náhled ukončení' => ['GET', '/api/accounting/accruals/5/termination-preview', 'accounting.accruals', AccessLevel::READ];
        yield 'náhled plánu' => ['POST', '/api/accounting/accruals/plan-preview', 'accounting.accruals', AccessLevel::READ];
        yield 'založení' => ['POST', '/api/accounting/accruals', 'accounting.accruals.write', AccessLevel::WRITE];
        yield 'úprava' => ['PUT', '/api/accounting/accruals/5', 'accounting.accruals.write', AccessLevel::WRITE];
        yield 'vazba' => ['POST', '/api/accounting/accruals/5/links', 'accounting.accruals.write', AccessLevel::WRITE];
        yield 'převzetí' => ['POST', '/api/accounting/accruals/takeover/pohoda', 'accounting.accruals.write', AccessLevel::WRITE];
        yield 'zaúčtovat řádek' => ['POST', '/api/accounting/accruals/5/schedule/9/post', 'accounting.journal.post', AccessLevel::WRITE];
        yield 'storno řádku' => ['POST', '/api/accounting/accruals/5/schedule/9/unpost', 'accounting.journal.post', AccessLevel::WRITE];
        yield 'ukončení' => ['POST', '/api/accounting/accruals/5/terminate', 'accounting.journal.post', AccessLevel::WRITE];
        yield 'zrušení ukončení' => ['POST', '/api/accounting/accruals/5/cancel-termination', 'accounting.journal.post', AccessLevel::WRITE];
        yield 'uzávěrka' => ['POST', '/api/accounting/accruals/closing/3/post', 'accounting.periods.close', AccessLevel::WRITE];
    }

    #[DataProvider('routes')]
    public function testRouteRequiresAccrualPermission(string $method, string $path, string $key, AccessLevel $minimum): void
    {
        $route = (new RoutePermissionMap())->match($method, $path);

        self::assertNotNull($route, $method . ' ' . $path);
        self::assertSame($key, $route->key, $method . ' ' . $path);
        self::assertSame($minimum, $route->minimum, $method . ' ' . $path);
    }

    public function testPresetsReadForReadonlyAndWriteForAccountant(): void
    {
        $catalog = new PermissionCatalog();
        $readonly = $catalog->legacyPreset('readonly');
        self::assertSame(AccessLevel::READ->value, $readonly['accounting.accruals'] ?? null);
        self::assertArrayNotHasKey('accounting.accruals.write', $readonly);

        $accountant = $catalog->legacyPreset('accountant');
        self::assertSame(AccessLevel::WRITE->value, $accountant['accounting.accruals'] ?? null);
        self::assertSame(AccessLevel::WRITE->value, $accountant['accounting.accruals.write'] ?? null);
        self::assertSame(AccessLevel::WRITE->value, $accountant['accounting.journal.post'] ?? null);
    }
}
