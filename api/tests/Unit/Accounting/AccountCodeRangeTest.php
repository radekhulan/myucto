<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\AccountCodeRange;
use MyInvoice\Service\Accounting\AccountCodeRangeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountCodeRangeTest extends TestCase
{
    public function testBoundsAreTrimmedUppercasedAndZeroIsValid(): void
    {
        $range = AccountCodeRange::parse(' 0 ', ' 221.kb ');
        self::assertSame('0', $range->from);
        self::assertSame('221.KB', $range->to);
        self::assertSame('221.KA', AccountCodeRange::parse(' 221.ka ', '221.kb')->from, 'Dolní mez se převádí stejně jako horní.');
        self::assertSame(['', 'ZZZZZZZZZZ'], AccountCodeRange::parse('', '')->sqlBounds());
        self::assertSame(['5', '5ZZZZZZZZZZ'], AccountCodeRange::parse('5', '5')->sqlBounds());
    }

    public function testLowercaseCodeMatchesUppercaseBound(): void
    {
        self::assertTrue(AccountCodeRange::parse('221.KB', '221.KB')->contains('221.kb'));
        self::assertFalse(AccountCodeRange::parse('221.KB', '221.KB')->contains('221.kc'));
        // Syntetika s písmenem pod analytickou dolní mezí: porovnání začátku také bez ohledu na velikost.
        self::assertTrue(AccountCodeRange::parse('2a1.100', '')->contains('2a1', syntheticOnly: true));
    }

    public function testSyntheticOnlyLowerAnalyticBoundKeepsItsSynthetic(): void
    {
        $range = AccountCodeRange::parse('501.100', '518.200');
        self::assertFalse($range->contains('501'));
        self::assertTrue($range->contains('501', syntheticOnly: true));
        self::assertTrue($range->contains('518', syntheticOnly: true));
        self::assertFalse($range->contains('519', syntheticOnly: true));
    }

    /** @return iterable<string,array{string,string}> */
    public static function valid(): iterable
    {
        yield 'analytika pod svou syntetikou' => ['501.100', '501'];
        yield 'kódy z importů' => ['518-1', '518/1'];
        // Kolace řadí podtržítko před pomlčku (v ASCII je až za písmeny).
        yield 'podtržítko před pomlčkou' => ['518_1', '518-1'];
        yield 'jen dolní mez' => ['6', ''];
        yield 'jen horní mez' => ['', '3'];
    }

    #[DataProvider('valid')]
    public function testValidRange(string $from, string $to): void
    {
        self::assertSame(strtoupper($from), AccountCodeRange::parse($from, $to)->from);
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalid(): iterable
    {
        yield 'zástupný znak' => ['5%', ''];
        yield 'mezera uvnitř' => ['5 01', ''];
        yield 'delší než kód' => ['12345678901', ''];
        yield 'od za do' => ['6', '5'];
        yield 'analytika za horní mezí' => ['502.100', '501'];
        yield 'pomlčka za podtržítkem' => ['518-1', '518_1'];
    }

    #[DataProvider('invalid')]
    public function testInvalidRangeIsRejected(string $from, string $to): void
    {
        $this->expectException(AccountCodeRangeException::class);
        AccountCodeRange::parse($from, $to);
    }

    /** Kód z databáze (hledání účtu v deníku) se znovu nevaliduje: převzatý kód s mezerou nesmí dát 500. */
    public function testSqlBoundsForStoredCodeDoNotRevalidate(): void
    {
        self::assertSame(['518 1', '518 1ZZZZZZZZZZ'], AccountCodeRange::sqlBoundsFor('518 1', ' 518 1 '));
        self::assertSame(AccountCodeRange::parse('501', '518')->sqlBounds(), AccountCodeRange::sqlBoundsFor('501', '518'));
    }
}
