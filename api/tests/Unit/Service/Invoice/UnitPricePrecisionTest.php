<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\UnitPricePrecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnitPricePrecisionTest extends TestCase
{
    public static function decimalStrings(): iterable
    {
        yield 'haléřová cena z DB' => ['12.500000', '12.50'];
        yield 'celá cena' => ['100.000000', '100.00'];
        yield 'cena pod haléř' => ['0.010800', '0.0108'];
        yield 'šest míst' => ['333.333333', '333.333333'];
        yield 'nula' => ['0.000000', '0.00'];
        yield 'záporná' => ['-0.004000', '-0.004'];
        yield 'float' => [0.004, '0.004'];
        yield 'int' => [5, '5.00'];
    }

    #[DataProvider('decimalStrings')]
    public function testDecimalStringShowsTwoPlacesUnlessThePriceHasMore(mixed $raw, string $expected): void
    {
        self::assertSame($expected, UnitPricePrecision::toDecimalString($raw));
    }

    public function testNullAndNonNumericPassThrough(): void
    {
        self::assertNull(UnitPricePrecision::toDecimalString(null));
        self::assertSame('', UnitPricePrecision::toDecimalString(''));
        self::assertSame('abc', UnitPricePrecision::toDecimalString('abc'));
    }

    public static function derivedPrices(): iterable
    {
        yield 'běžná přirážka zůstane v haléřích' => ['133.3333333333', '133.33'];
        yield 'sleva 5 % z 999,99' => ['949.9905', '949.99'];
        yield 'přirážka z průměrné nákupní ceny' => ['40.0000004', '40.00'];
        yield 'levná položka by se zkreslila' => ['0.0108', '0.0108'];
        yield 'na haléře by se vynulovala' => ['0.0038', '0.0038'];
        yield 'polovina haléře nahoru' => ['0.125', '0.125'];
        yield 'nula' => ['0', '0.00'];
    }

    #[DataProvider('derivedPrices')]
    public function testDerivedPriceKeepsCentsUnlessTheyDistortIt(string $raw, string $expected): void
    {
        self::assertSame($expected, UnitPricePrecision::roundDerived($raw));
    }

    public function testInputPrecisionIsLimitedToSixPlaces(): void
    {
        self::assertTrue(UnitPricePrecision::isWithinPrecision('0.004'));
        self::assertTrue(UnitPricePrecision::isWithinPrecision('0,0108'));
        self::assertTrue(UnitPricePrecision::isWithinPrecision('1.1234560'));
        self::assertTrue(UnitPricePrecision::isWithinPrecision(null));
        self::assertFalse(UnitPricePrecision::isWithinPrecision('0.0000001'));
        self::assertFalse(UnitPricePrecision::isWithinPrecision('1.1234567'));
    }
}
