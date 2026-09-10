<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\Ms3Journal;
use PHPUnit\Framework\TestCase;

/**
 * Převod zná jen kalendářní účetní rok. Agenda s hospodářským rokem (červenec–červen)
 * by jinak skončila stovkami chyb „zápis mimo období" místo jedné srozumitelné.
 */
final class FiscalYearShapeTest extends TestCase
{
    public function testHalfOfEntriesInNextCalendarYearIsNotACalendarYear(): void
    {
        $rows = [];
        foreach (['2024-07-15', '2024-08-15', '2024-09-15', '2024-10-15', '2024-11-15', '2024-12-15',
            '2025-01-15', '2025-02-15', '2025-03-15', '2025-04-15', '2025-05-15', '2025-06-15'] as $date) {
            $rows[] = ['Zdroj' => 'FP', 'Datum' => $date];
        }

        self::assertFalse(Ms3Journal::isCalendarYear($rows, (int) Ms3Journal::fiscalYear($rows)));
    }

    public function testSingleStrayEntryDoesNotMakeItAFiscalYear(): void
    {
        $rows = [['Zdroj' => 'XP', 'Datum' => '2023-12-31']];
        for ($m = 1; $m <= 12; $m++) {
            $rows[] = ['Zdroj' => 'BK', 'Datum' => sprintf('2024-%02d-10', $m)];
        }
        $rows[] = ['Zdroj' => 'ID', 'Datum' => '2025-01-01'];

        self::assertTrue(Ms3Journal::isCalendarYear($rows, 2024));
    }
}
