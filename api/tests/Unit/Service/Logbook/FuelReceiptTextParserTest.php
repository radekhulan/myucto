<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook;

use MyInvoice\Service\Logbook\Fuel\FuelReceiptTextParser;
use PHPUnit\Framework\TestCase;

final class FuelReceiptTextParserTest extends TestCase
{
    public function testParsesLitersPriceOdometerPlateAndFuel(): void
    {
        $r = FuelReceiptTextParser::parse('Nafta 42,50 l á 38,90 Kč/l, tach. 123 456, SPZ 1AB 2345');

        self::assertSame(42.5, $r['quantity']);
        self::assertSame('l', $r['unit']);
        self::assertSame(38.9, $r['unit_price']);
        self::assertSame('Nafta', $r['fuel_type']);
        self::assertSame('1AB 2345', $r['plate']);
        self::assertSame(123456, $r['odometer']);
    }

    public function testOdometerWithoutThousandsSeparator(): void
    {
        self::assertSame(98765, FuelReceiptTextParser::parse('Natural 95, stav km 98765')['odometer']);
    }

    public function testElectricChargingInKwh(): void
    {
        $r = FuelReceiptTextParser::parse('Nabíjení vozidla 35,2 kWh');

        self::assertSame('kWh', $r['unit']);
        self::assertSame(35.2, $r['quantity']);
        self::assertSame('Elektřina', $r['fuel_type']);
    }

    public function testTextWithoutFuelDataStaysEmpty(): void
    {
        $r = FuelReceiptTextParser::parse('Kancelářské potřeby');

        self::assertNull($r['quantity']);
        self::assertNull($r['unit_price']);
        self::assertNull($r['fuel_type']);
        self::assertNull($r['plate']);
        self::assertNull($r['odometer']);
    }
}
