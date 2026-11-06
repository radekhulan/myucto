<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Eet;

use MyInvoice\Service\Eet\EetIdentifiers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EetIdentifiersTest extends TestCase
{
    /** @return iterable<string,array{string,bool}> */
    public static function eics(): iterable
    {
        yield 'osm číslic' => ['CZ12345678', true];
        yield 'deset číslic' => ['CZ1234567890', true];
        yield 'sedm číslic' => ['CZ1234567', false];
        yield 'jedenáct číslic' => ['CZ12345678901', false];
        yield 'malé písmeno' => ['cz12345678', false];
        yield 'cizí stát' => ['SK12345678', false];
        yield 'nový řádek na konci' => ["CZ12345678\n", false];
    }

    #[DataProvider('eics')]
    public function testEicFormat(string $value, bool $valid): void
    {
        self::assertSame($valid, EetIdentifiers::isEic($value));
    }

    public function testEicNormalisation(): void
    {
        self::assertSame('CZ12345678', EetIdentifiers::normalizeEic(' cz 1234 5678 '));
    }

    /** @return iterable<string,array{string,bool}> */
    public static function deviceCodes(): iterable
    {
        yield 'jednoduchý' => ['POKLADNA-1', true];
        yield 'povolené znaky' => ['a.b,c:d;e/f#g-h_i j', true];
        yield 'dvacet znaků' => [str_repeat('A', 20), true];
        yield 'jednadvacet znaků' => [str_repeat('A', 21), false];
        yield 'prázdný' => ['', false];
        yield 'diakritika' => ['Pokladna č.1', false];
        yield 'zakázaný znak' => ['POKLADNA@1', false];
        yield 'nový řádek' => ["POKLADNA\n", false];
    }

    #[DataProvider('deviceCodes')]
    public function testDeviceCodeFollowsXsdString20(string $value, bool $valid): void
    {
        self::assertSame($valid, EetIdentifiers::isDeviceCode($value));
    }
}
