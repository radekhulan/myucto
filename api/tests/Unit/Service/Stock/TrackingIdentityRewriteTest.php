<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Stock;

use MyInvoice\Service\Stock\TrackingIdentityRewrite;
use PHPUnit\Framework\TestCase;

/**
 * Čistá funkce přepisu identity šarže v JSON kopiích alokací — bez databáze.
 */
final class TrackingIdentityRewriteTest extends TestCase
{
    public function testRewritesRowByUnitId(): void
    {
        $rows = [['stock_tracking_unit_id' => 7, 'quantity' => '2.000', 'lot_code' => 'L-OLD', 'expires_on' => '2027-01-31']];
        $out = TrackingIdentityRewrite::rewrite($rows, 7, 'L-OLD', '2027-01-31', 'L-NEW', '2027-03-01');
        self::assertSame([['stock_tracking_unit_id' => 7, 'quantity' => '2.000', 'lot_code' => 'L-NEW', 'expires_on' => '2027-03-01']], $out);
    }

    public function testRewritesRowWithoutIdByTextIgnoringLetterCase(): void
    {
        $rows = [['quantity' => '1.000', 'serial_number' => null, 'lot_code' => 'abc', 'expires_on' => null]];
        $out = TrackingIdentityRewrite::rewrite($rows, 7, 'ABC', null, 'ABD', null);
        self::assertSame('ABD', $out[0]['lot_code']);
        self::assertSame(['quantity', 'serial_number', 'lot_code', 'expires_on'], array_keys($out[0]), 'Pořadí klíčů zůstává.');
    }

    public function testKeepsOtherRowsUntouched(): void
    {
        $other = ['stock_tracking_unit_id' => 8, 'quantity' => '1.000', 'lot_code' => 'L-OLD', 'expires_on' => '2027-01-31'];
        $differentDate = ['quantity' => '1.000', 'lot_code' => 'L-OLD', 'expires_on' => '2027-02-28'];
        $differentLot = ['quantity' => '1.000', 'lot_code' => 'L-X', 'expires_on' => '2027-01-31'];
        $mine = ['quantity' => '3.000', 'lot_code' => 'L-OLD', 'expires_on' => '2027-01-31'];
        $out = TrackingIdentityRewrite::rewrite([$other, $differentDate, $differentLot, $mine], 7, 'L-OLD', '2027-01-31', 'L-NEW', '2027-01-31');
        self::assertSame([$other, $differentDate, $differentLot], array_slice($out, 0, 3));
        self::assertSame('L-NEW', $out[3]['lot_code']);
    }

    public function testRowWithForeignIdIsNotRewrittenEvenWhenTextMatches(): void
    {
        $rows = [['stock_tracking_unit_id' => 8, 'quantity' => '1.000', 'lot_code' => 'L-OLD', 'expires_on' => null]];
        self::assertNull(TrackingIdentityRewrite::rewrite($rows, 7, 'L-OLD', null, 'L-NEW', null));
    }

    public function testSerialRowIsNeverRewritten(): void
    {
        $rows = [['quantity' => '1.000', 'serial_number' => 'SN-1', 'lot_code' => 'L-OLD', 'expires_on' => null]];
        self::assertNull(TrackingIdentityRewrite::rewrite($rows, 7, 'L-OLD', null, 'L-NEW', null));
    }

    public function testReturnsNullWhenNothingChanges(): void
    {
        self::assertNull(TrackingIdentityRewrite::rewrite([], 7, 'A', null, 'B', null));
        self::assertNull(TrackingIdentityRewrite::rewrite([['quantity' => '1.000', 'lot_code' => 'Z']], 7, 'A', null, 'B', null));
        $already = [['stock_tracking_unit_id' => 7, 'quantity' => '1.000', 'lot_code' => 'B', 'expires_on' => null]];
        self::assertNull(TrackingIdentityRewrite::rewrite($already, 7, 'A', null, 'B', null), 'Už přepsaná kopie se podruhé nemění.');
    }

    public function testExpiryKeyIsAddedOnlyWhenNeededAndClearedToNull(): void
    {
        $noExpiry = [['stock_tracking_unit_id' => 7, 'quantity' => '1.000', 'lot_code' => 'A']];
        $out = TrackingIdentityRewrite::rewrite($noExpiry, 7, 'A', null, 'B', null);
        self::assertArrayNotHasKey('expires_on', $out[0], 'Kopie bez klíče expirace ho u šarže bez expirace nezískává.');
        $out = TrackingIdentityRewrite::rewrite($noExpiry, 7, 'A', null, 'B', '2028-01-01');
        self::assertSame('2028-01-01', $out[0]['expires_on']);
        $withExpiry = [['stock_tracking_unit_id' => 7, 'quantity' => '1.000', 'lot_code' => 'A', 'expires_on' => '2028-01-01']];
        $out = TrackingIdentityRewrite::rewrite($withExpiry, 7, 'A', '2028-01-01', 'A', null);
        self::assertNull($out[0]['expires_on']);
    }

    public function testNonArrayEntriesArePassedThrough(): void
    {
        $out = TrackingIdentityRewrite::rewrite(['x', ['stock_tracking_unit_id' => 7, 'lot_code' => 'A']], 7, 'A', null, 'B', null);
        self::assertSame('x', $out[0]);
        self::assertSame('B', $out[1]['lot_code']);
    }
}
