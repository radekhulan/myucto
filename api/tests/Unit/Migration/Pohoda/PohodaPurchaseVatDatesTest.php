<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaPurchaseVatDates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DUZP („Datum KH DPH") a datum odpočtu („Datum odp./doruč.") přijatého dokladu z POHODY
 * v `tax_date` a ručním datu přijetí.
 */
final class PohodaPurchaseVatDatesTest extends TestCase
{
    /** @return iterable<string,array{0:?string,1:?string,2:bool,3:array{tax:?string,received:?string,kept_deduction:bool}}> */
    public static function cases(): iterable
    {
        yield 'odpočet po DUZP v dalším měsíci' => ['2026-02-28', '2026-03-01', false, ['tax' => '2026-02-28', 'received' => '2026-03-01', 'kept_deduction' => false]];
        yield 'shodná data' => ['2026-03-01', '2026-03-01', false, ['tax' => '2026-03-01', 'received' => null, 'kept_deduction' => false]];
        yield 'odpočet před DUZP nese jen DUZP' => ['2026-03-01', '2026-01-20', false, ['tax' => '2026-03-01', 'received' => null, 'kept_deduction' => false]];
        yield 'bez data KH DPH' => [null, '2026-03-01', false, ['tax' => '2026-03-01', 'received' => null, 'kept_deduction' => false]];
        yield 'zahraniční přenesení ve stejném měsíci' => ['2026-03-05', '2026-03-20', true, ['tax' => '2026-03-05', 'received' => '2026-03-20', 'kept_deduction' => false]];
        yield 'zahraniční přenesení v jiném měsíci drží období POHODY' => ['2026-02-26', '2026-03-02', true, ['tax' => '2026-03-02', 'received' => null, 'kept_deduction' => true]];
    }

    /** @param array{tax:?string,received:?string,kept_deduction:bool} $expected */
    #[DataProvider('cases')]
    public function testDates(?string $duzp, ?string $deduction, bool $foreignReverse, array $expected): void
    {
        self::assertSame($expected, PohodaPurchaseVatDates::fromPohoda($duzp, $deduction, $foreignReverse));
    }

    /** Ruční datum přijetí převod nikdy nezapíše před DUZP - podle toho oprava pozná původní převod. */
    public function testReceivedDateIsNeverBeforeTaxDate(): void
    {
        foreach (self::cases() as [$duzp, $deduction, $foreign]) {
            $dates = PohodaPurchaseVatDates::fromPohoda($duzp, $deduction, $foreign);
            self::assertTrue($dates['received'] === null || $dates['received'] > $dates['tax']);
        }
    }
}
