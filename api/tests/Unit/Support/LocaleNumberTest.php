<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Support;

use MyInvoice\Support\LocaleNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocaleNumberTest extends TestCase
{
    /** @return iterable<string, array{0:string|int|float|null, 1:?string}> */
    public static function values(): iterable
    {
        yield 'český desetinný' => ['128,5', '128.5'];
        yield 'anglický desetinný' => ['128.5', '128.5'];
        yield 'celé číslo' => ['100150', '100150'];
        yield 'mezera tisíců' => ['1 234,50', '1234.5'];
        yield 'nezlomitelná mezera' => ["45\u{00A0}678,5", '45678.5'];
        yield 'tečka tisíců + čárka' => ['1.234,50', '1234.5'];
        yield 'čárka tisíců + tečka' => ['1,234.50', '1234.5'];
        yield 'apostrof tisíců' => ["1'234.50", '1234.5'];
        yield 'opakovaná tečka = tisíce' => ['1.234.567', '1234567'];
        yield 'opakovaná čárka = tisíce' => ['1,234,567', '1234567'];
        yield 'měna' => ['71 875,00 Kč', '71875'];
        yield 'jednotka km' => ['128,5 km', '128.5'];
        yield 'záporné' => ['-3,5', '-3.5'];
        yield 'závorky' => ['(12,00)', '-12'];
        yield 'int' => [42, '42'];
        yield 'float' => [128.5, '128.5'];
        yield 'prázdné' => ['', null];
        yield 'null' => [null, null];
        yield 'text' => ['abc', null];
        yield 'jen pomlčka' => ['-', null];
        yield 'dvě desetinné části' => ['1.2,3.4', null];
    }

    #[DataProvider('values')]
    public function testParse(string|int|float|null $input, ?string $expected): void
    {
        self::assertSame($expected, LocaleNumber::parse($input));
    }

    public function testToIntRoundsHalfUpInsteadOfDroppingSeparator(): void
    {
        self::assertSame(129, LocaleNumber::toInt('128,5'));
        self::assertSame(128, LocaleNumber::toInt('128.4'));
        self::assertSame(45679, LocaleNumber::toInt('45 678,5'));
        self::assertNull(LocaleNumber::toInt(''));
    }

    public function testToFloat(): void
    {
        self::assertSame(1234.5, LocaleNumber::toFloat('1.234,50'));
        self::assertNull(LocaleNumber::toFloat('x'));
    }
}
