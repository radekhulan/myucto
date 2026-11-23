<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\PurchaseInvoice;

use MyInvoice\Service\PurchaseInvoice\PurchaseNumberSeries;
use MyInvoice\Service\PurchaseInvoice\PurchaseNumberingDate;
use PHPUnit\Framework\TestCase;

final class PurchaseNumberingDateTest extends TestCase
{
    private const DOC = ['issue_date' => '2026-10-28', 'received_at' => '2026-11-03', 'tax_date' => '2026-12-01'];

    public function testChosenDateGivesNumberPeriod(): void
    {
        self::assertSame('202610', PurchaseNumberingDate::period(self::DOC, 'issue'));
        self::assertSame('202611', PurchaseNumberingDate::period(self::DOC, 'received'));
        self::assertSame('202612', PurchaseNumberingDate::period(self::DOC, 'tax'));
        self::assertFalse(PurchaseNumberingDate::usesFallback(self::DOC, 'received'));
    }

    public function testMissingDateFallsBackToIssueDate(): void
    {
        foreach ([null, '', '0000-00-00'] as $empty) {
            $doc = ['tax_date' => $empty, 'received_at' => $empty] + self::DOC;
            self::assertSame('2026-10-28', PurchaseNumberingDate::of($doc, 'tax'));
            self::assertSame('2026-10-28', PurchaseNumberingDate::of($doc, 'received'));
            self::assertTrue(PurchaseNumberingDate::usesFallback($doc, 'tax'));
        }
        self::assertFalse(PurchaseNumberingDate::usesFallback(['issue_date' => '2026-10-28'], 'issue'));
    }

    public function testSqlExpressionFallsBackToIssueDate(): void
    {
        self::assertSame('p.issue_date', PurchaseNumberingDate::sql('issue', 'p.'));
        self::assertSame('issue_date', PurchaseNumberingDate::sql('due'));
        self::assertSame(
            "CASE WHEN p.received_at >= '1000-01-01' THEN p.received_at ELSE p.issue_date END",
            PurchaseNumberingDate::sql('received', 'p.'),
        );
    }

    public function testValidation(): void
    {
        self::assertTrue(PurchaseNumberingDate::isValid('received'));
        self::assertFalse(PurchaseNumberingDate::isValid('due'));
        self::assertFalse(PurchaseNumberingDate::isValid(['tax']));
    }

    public function testNumberPeriodReadsYearAndMonthFromNumber(): void
    {
        self::assertSame(['year' => '2026', 'month' => '10'], PurchaseNumberSeries::numberPeriod('{PP}{YY}{MM}{CCC}', 'PF2610007'));
        self::assertSame(['year' => '2026', 'month' => null], PurchaseNumberSeries::numberPeriod('PAR{YYYY}{CCCC}', 'PAR20260012'));
        self::assertSame(['year' => null, 'month' => null], PurchaseNumberSeries::numberPeriod('X{CCCC}', 'X0001'));
        self::assertNull(PurchaseNumberSeries::numberPeriod('{PP}{YY}{MM}{CCC}', 'RUCNI-7'), 'Ruční číslo šabloně neodpovídá.');
        self::assertNull(PurchaseNumberSeries::numberPeriod('{PP}{YY}{MM}{CCC}', 'UC2610001'), 'Číslo řady účtenek není číslo faktur.');
        self::assertNull(PurchaseNumberSeries::numberPeriod('{PP}{YY}{MM}{CCC}', 'PF2613001'), 'Měsíc 13 neexistuje.');
    }
}
