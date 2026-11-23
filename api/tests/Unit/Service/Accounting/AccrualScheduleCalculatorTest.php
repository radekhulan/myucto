<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Accrual\AccrualScheduleCalculator;
use MyInvoice\Service\Accounting\Closing\AccrualDeferralService;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sdílený výpočet rozpouštění podle dnů: uzávěrka, výkazy i agenda časového rozlišení
 * čtou stejná čísla a dosavadní vstupní body na něj jen předávají.
 */
final class AccrualScheduleCalculatorTest extends TestCase
{
    public function testDaysInclusiveCountsBothEnds(): void
    {
        self::assertSame(1, AccrualScheduleCalculator::daysInclusive('2091-01-01', '2091-01-01'));
        self::assertSame(365, AccrualScheduleCalculator::daysInclusive('2091-01-01', '2091-12-31'));
        self::assertSame(366, AccrualScheduleCalculator::daysInclusive('2092-01-01', '2092-12-31'));
        self::assertSame(0, AccrualScheduleCalculator::daysInclusive('2091-02-01', '2091-01-31'));
    }

    public function testCumRecognizedAndDeferredAmount(): void
    {
        // 1 200 Kč za 1. 7. 2091 až 30. 6. 2092 (366 dní, přestupný únor 2092).
        $net = 1_200.00;
        $from = '2091-07-01';
        $to = '2092-06-30';
        self::assertSame(0.0, AccrualScheduleCalculator::cumRecognized($net, $from, $to, '2091-06-30'));
        self::assertSame(round(1_200 * 184 / 366, 2), AccrualScheduleCalculator::cumRecognized($net, $from, $to, '2091-12-31'));
        self::assertSame($net, AccrualScheduleCalculator::cumRecognized($net, $from, $to, '2093-01-01'));
        self::assertSame(round(1_200 - round(1_200 * 184 / 366, 2), 2), AccrualScheduleCalculator::deferredAmount($net, $from, $to, '2091-12-31'));
        self::assertSame(0.0, AccrualScheduleCalculator::deferredAmount($net, $to, $from, '2091-12-31'));
    }

    /** @return iterable<string,array{0:float,1:string,2:string}> */
    public static function periods(): iterable
    {
        yield 'víceleté' => [36_600.00, '2091-03-15', '2095-09-14'];
        yield 'haléře' => [0.05, '2091-12-01', '2093-01-31'];
        yield 'jeden rok' => [10.01, '2091-01-01', '2091-12-31'];
        yield 'dobropis' => [-1_234.57, '2091-07-01', '2092-06-30'];
    }

    /**
     * Roční tranše od roku po rozvahovém dni se sečtou přesně na odloženou částku a shodují
     * se s rozdílem kumulativních hodnot, ze kterého je dřív počítala uzávěrka.
     */
    #[DataProvider('periods')]
    public function testFiscalYearTranchesTelescopeToDeferral(float $net, string $from, string $to): void
    {
        $asOf = '2091-12-31';
        $tranches = AccrualScheduleCalculator::fiscalYearTranches($net, $from, $to, 2092);
        $sum = 0.0;
        foreach ($tranches as $tranche) {
            $fy = $tranche['fiscal_year'];
            self::assertSame(round(
                AccrualDeferralService::cumRecognized($net, $from, $to, $fy . '-12-31')
                - AccrualDeferralService::cumRecognized($net, $from, $to, ($fy - 1) . '-12-31'),
                2,
            ), $tranche['amount']);
            $sum = round($sum + $tranche['amount'], 2);
        }
        self::assertSame(AccrualScheduleCalculator::deferredAmount($net, $from, $to, $asOf), $sum);
    }

    #[DataProvider('periods')]
    public function testLegacyEntryPointsDelegate(float $net, string $from, string $to): void
    {
        foreach (['2091-06-30', '2091-12-31', '2093-05-17', '2099-01-01'] as $day) {
            self::assertSame(AccrualScheduleCalculator::cumRecognized($net, $from, $to, $day), AccrualDeferralService::cumRecognized($net, $from, $to, $day));
            self::assertSame(AccrualScheduleCalculator::deferredAmount($net, $from, $to, $day), AccrualDeferralService::deferredAmount($net, $from, $to, $day));
            self::assertSame(AccrualScheduleCalculator::deferredAmount($net, $from, $to, $day), ClosingService::deferredRevenueAmount($net, $from, $to, $day));
        }
        self::assertSame(AccrualScheduleCalculator::daysInclusive($from, $to), AccrualDeferralService::daysInclusive($from, $to));
    }

    public function testRecognizedBetweenIsDifferenceOfCumulative(): void
    {
        $net = 5_000.00;
        [$from, $to] = ['2091-01-07', '2091-12-31'];
        self::assertSame(
            round(AccrualScheduleCalculator::cumRecognized($net, $from, $to, '2091-02-28') - AccrualScheduleCalculator::cumRecognized($net, $from, $to, '2091-01-31'), 2),
            AccrualScheduleCalculator::recognizedBetween($net, $from, $to, '2091-01-31', '2091-02-28'),
        );
        self::assertSame($net, AccrualScheduleCalculator::recognizedBetween($net, $from, $to, '2090-12-31', '2092-12-31'));
    }
}
