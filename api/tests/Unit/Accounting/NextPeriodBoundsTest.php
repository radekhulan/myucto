<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Repository\AccountingPeriodRepository;
use PHPUnit\Framework\TestCase;

/**
 * Hranice navazujícího účetního období (R5) — čistá funkce bez DB.
 *
 * Sdílí ji uzávěrkový krok `open_next` ({@see \MyInvoice\Service\Accounting\Closing\ClosingService})
 * a automatické otevření na přelomu roku
 * ({@see \MyInvoice\Service\Accounting\AccountingPeriodProvisioner}). Dva opisy téhož
 * výpočtu by se rozešly na přestupném nebo hospodářském roce — proto SSOT a proto
 * tenhle test.
 */
final class NextPeriodBoundsTest extends TestCase
{
    public function testCalendarYearFollowsCalendarYear(): void
    {
        self::assertSame(
            ['fiscal_year' => 2027, 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'],
            AccountingPeriodRepository::nextPeriodBounds('2026-12-31'),
        );
    }

    /**
     * Hospodářský rok (§21a ZDP) si tvar DĚDÍ — navazující období začíná 1. dnem
     * téhož měsíce, ne 1. ledna. Kdyby se zakládal kalendářní rok, vznikl by
     * v řadě překryv a firma by měla dvě období nad týmž datem.
     */
    public function testFiscalYearKeepsItsShape(): void
    {
        self::assertSame(
            ['fiscal_year' => 2027, 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31'],
            AccountingPeriodRepository::nextPeriodBounds('2027-03-31'),
        );
    }

    /** Label období = kalendářní rok jeho ZAČÁTKU, i když období přesahuje do dalšího. */
    public function testLabelIsYearOfStartNotOfEnd(): void
    {
        $bounds = AccountingPeriodRepository::nextPeriodBounds('2030-09-30');
        self::assertSame(2030, $bounds['fiscal_year']);
        self::assertSame('2030-10-01', $bounds['starts_on']);
        self::assertSame('2031-09-30', $bounds['ends_on']);
    }

    /** Přestupný rok: konec období je vždy „začátek + 1 rok − 1 den", ne pevných 365 dnů. */
    public function testLeapYearEndsOnLastDayOfPeriod(): void
    {
        self::assertSame(
            ['fiscal_year' => 2028, 'starts_on' => '2028-01-01', 'ends_on' => '2028-12-31'],
            AccountingPeriodRepository::nextPeriodBounds('2027-12-31'),
        );
        self::assertSame('2029-02-28', AccountingPeriodRepository::nextPeriodBounds('2028-02-29')['ends_on']);
    }
}
