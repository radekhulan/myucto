<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\TestCase;

/** Faktura z příjemky jde přes právo skladových dokladů, právo na faktury hlídá akce. */
final class ReceiptInvoiceRoutePermissionTest extends TestCase
{
    public function testRoutesUseExpectedPermission(): void
    {
        $map = new RoutePermissionMap();

        $create = $map->match('POST', '/api/stock/documents/5/invoice');
        self::assertSame(['stock.documents.write', AccessLevel::WRITE], [$create?->key, $create?->minimum]);

        $sources = $map->match('GET', '/api/invoices/5/source-receipts');
        self::assertSame(['stock', AccessLevel::READ], [$sources?->key, $sources?->minimum]);
    }
}
