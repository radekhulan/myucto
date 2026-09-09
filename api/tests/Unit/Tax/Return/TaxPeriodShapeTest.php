<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\TaxPeriodShape;
use PHPUnit\Framework\TestCase;

/**
 * `typ_zo` (§ 21a ZDP) a rozpoznání tvaru zdaňovacího období. Do P-1 žila tahle
 * úvaha jako `private` větvení uvnitř TaxReturnService a atypické zkrácené období
 * v ní končilo tichým fallbackem na „A" — přiznání pak o zdaňovacím období tvrdilo
 * nepravdu a nikdo se to nedozvěděl.
 */
final class TaxPeriodShapeTest extends TestCase
{
    public function testCalendarYear(): void
    {
        self::assertSame(TaxPeriodShape::CALENDAR, TaxPeriodShape::classify('2025-01-01', '2025-12-31'));
        self::assertSame('A', TaxPeriodShape::typZo(TaxPeriodShape::CALENDAR));
    }

    /** Zkrácený první rok končící 31. 12. je pořád kalendářní režim. */
    public function testShortFirstYearEndingOnNewYearsEveIsCalendar(): void
    {
        self::assertSame(TaxPeriodShape::CALENDAR, TaxPeriodShape::classify('2025-03-15', '2025-12-31'));
    }

    public function testFiscalYear(): void
    {
        self::assertSame(TaxPeriodShape::FISCAL, TaxPeriodShape::classify('2025-04-01', '2026-03-31'));
        self::assertSame('B', TaxPeriodShape::typZo(TaxPeriodShape::FISCAL));
    }

    /**
     * Období delší než dvanáct měsíců je „D" i tehdy, když končí 31. 12. — XSD nese
     * u typ_zo kritickou kontrolu „pokud je hodnota A nebo B, nesmí být ZO delší než
     * 1 rok", takže dřívější pořadí testů (nejdřív 31. 12. → A) posílalo do přiznání
     * neprůchodnou kombinaci.
     */
    public function testOverlongPeriodIsLongEvenWhenItEndsOnNewYearsEve(): void
    {
        self::assertSame(TaxPeriodShape::LONG, TaxPeriodShape::classify('2024-10-01', '2025-12-31'));
        self::assertSame('D', TaxPeriodShape::typZo(TaxPeriodShape::LONG));
    }

    /** Atypické zkrácené období — žádné písmeno § 21a ho nevystihuje. */
    public function testAtypicalShortPeriod(): void
    {
        self::assertSame(TaxPeriodShape::ATYPICAL, TaxPeriodShape::classify('2025-01-01', '2025-06-30'));
        self::assertSame(TaxPeriodShape::ATYPICAL, TaxPeriodShape::classify('2025-05-15', '2025-11-30'));
        // Fallback zůstává nejbezpečnější „A", ale detektor z téhož tvaru dělá blokující nález.
        self::assertSame('A', TaxPeriodShape::typZo(TaxPeriodShape::ATYPICAL));
    }

    public function testMissingOrBrokenPeriod(): void
    {
        self::assertSame(TaxPeriodShape::MISSING, TaxPeriodShape::classify(null, null));
        self::assertSame(TaxPeriodShape::MISSING, TaxPeriodShape::classify('', '2025-12-31'));
        self::assertSame(TaxPeriodShape::MISSING, TaxPeriodShape::classify('nesmysl', '2025-12-31'));
        self::assertSame(TaxPeriodShape::MISSING, TaxPeriodShape::classify('2025-12-31', '2025-01-01'));
    }

    /** Datetime z DB (`2025-01-01 00:00:00`) se ořízne na datum. */
    public function testAcceptsDatetimeStrings(): void
    {
        self::assertSame(TaxPeriodShape::CALENDAR, TaxPeriodShape::classify('2025-01-01 00:00:00', '2025-12-31 00:00:00'));
    }
}
