<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\PurchaseInvoice;

use MyInvoice\Service\PurchaseInvoice\StoredPurchaseItemColumns;
use PHPUnit\Framework\TestCase;

final class StoredPurchaseItemColumnsTest extends TestCase
{
    public function testUsesIdForMovedOrRenamedItemAndPositionOnlyWithoutId(): void
    {
        $stored = [
            ['id' => 100, 'description' => 'Zboží'],
            ['id' => 200, 'description' => 'Doprava'],
            ['id' => 300, 'description' => 'Služba'],
        ];
        $items = [
            ['id' => '300', 'description' => 'Jiný popis'],
            ['description' => ' Doprava '],
            ['id' => 999, 'description' => 'Služba'],
            ['description' => 'Zboží'],
        ];

        self::assertSame([0 => 2, 1 => 1], StoredPurchaseItemColumns::match($items, $stored));
    }

    public function testDuplicateClaimsDoNotSelectAnArbitrarySuccessor(): void
    {
        $stored = [
            ['id' => 100, 'description' => 'Zboží'],
            ['id' => 200, 'description' => 'Doprava'],
        ];
        $items = [
            ['description' => 'Zboží'],
            ['id' => 100, 'description' => 'Jiný popis'],
            ['id' => 200, 'description' => 'Doprava'],
        ];

        self::assertSame([2 => 1], StoredPurchaseItemColumns::match($items, $stored));
    }
}
