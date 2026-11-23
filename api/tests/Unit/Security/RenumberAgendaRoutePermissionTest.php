<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Přečíslování agend (pokladna, OP/OZ, interní doklady): provedení chce právo zápisu
 * dané agendy, náhled jen její čtení. Pravidlo se nesmí propadnout do obecného
 * `accounting` ani na jiné právo.
 */
final class RenumberAgendaRoutePermissionTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, AccessLevel}> */
    public static function routes(): iterable
    {
        yield 'pokladna náhled'   => ['GET', '/api/accounting/cash-documents/renumber', 'cash', AccessLevel::READ];
        yield 'pokladna provést'  => ['POST', '/api/accounting/cash-documents/renumber', 'cash.document.write', AccessLevel::WRITE];
        yield 'OP/OZ náhled'      => ['GET', '/api/accounting/other-items/renumber', 'other_items', AccessLevel::READ];
        yield 'OP/OZ provést'     => ['POST', '/api/accounting/other-items/renumber', 'other_items', AccessLevel::WRITE];
        yield 'interní náhled'    => ['GET', '/api/accounting/internal-documents/renumber', 'accounting', AccessLevel::READ];
        yield 'interní provést'   => ['POST', '/api/accounting/internal-documents/renumber', 'accounting.journal.write', AccessLevel::WRITE];
        yield 'přijaté provést'   => ['POST', '/api/purchase-invoices/renumber', 'purchase_invoices', AccessLevel::WRITE];
    }

    #[DataProvider('routes')]
    public function testRenumberRouteRequiresAgendaPermission(string $method, string $path, string $key, AccessLevel $minimum): void
    {
        $route = (new RoutePermissionMap())->match($method, $path);

        self::assertNotNull($route, $method . ' ' . $path);
        self::assertSame($key, $route->key, $method . ' ' . $path);
        self::assertSame($minimum, $route->minimum, $method . ' ' . $path);
    }
}
