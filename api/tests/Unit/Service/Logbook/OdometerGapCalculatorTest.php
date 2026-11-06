<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook;

use MyInvoice\Service\Logbook\OdometerGapCalculator;
use PHPUnit\Framework\TestCase;

final class OdometerGapCalculatorTest extends TestCase
{
    private static function reading(int $id, string $date, int $odometer, string $source = 'manual'): array
    {
        return ['id' => $id, 'date' => $date, 'odometer' => $odometer, 'source' => $source];
    }

    private static function trip(string $date, ?int $end): array
    {
        return ['date' => $date, 'odometer_end' => $end];
    }

    public function testReadingAboveCarStartWithoutTripsIsWholeGap(): void
    {
        $gaps = OdometerGapCalculator::gaps(
            [self::reading(1, '2099-01-10', 1087)],
            [],
            ['odometer' => 1000, 'date' => '2099-01-01'],
        );

        self::assertSame([[
            'reading_id' => 1, 'date_from' => '2099-01-01', 'date_to' => '2099-01-10',
            'odometer_from' => 1000, 'odometer_to' => 1087, 'km' => 87,
        ]], $gaps);
    }

    public function testTripsCoverPartOfTheGap(): void
    {
        $gaps = OdometerGapCalculator::gaps(
            [self::reading(1, '2099-01-10', 1087)],
            [self::trip('2099-01-05', 1040)],
            ['odometer' => 1000, 'date' => '2099-01-01'],
        );

        self::assertCount(1, $gaps);
        self::assertSame('2099-01-05', $gaps[0]['date_from']);
        self::assertSame(1040, $gaps[0]['odometer_from']);
        self::assertSame(47, $gaps[0]['km']);
    }

    public function testFullyCoveredReadingHasNoGap(): void
    {
        $gaps = OdometerGapCalculator::gaps(
            [self::reading(1, '2099-01-10', 1087)],
            [self::trip('2099-01-10', 1087)],
            ['odometer' => 1000, 'date' => null],
        );

        self::assertSame([], $gaps);
    }

    public function testDailyReadingsSplitGapsPerDay(): void
    {
        $gaps = OdometerGapCalculator::gaps(
            [self::reading(2, '2099-01-11', 1150), self::reading(1, '2099-01-10', 1087), self::reading(3, '2099-01-12', 1150)],
            [],
            ['odometer' => 1000, 'date' => '2099-01-09'],
        );

        self::assertSame([1, 2], array_column($gaps, 'reading_id'));
        self::assertSame([87, 63], array_column($gaps, 'km'));
        self::assertSame(['2099-01-10', '2099-01-11'], array_column($gaps, 'date_to'));
        self::assertSame('2099-01-10', $gaps[1]['date_from']);
        self::assertSame(1087, $gaps[1]['odometer_from']);
    }

    public function testTripsWithoutOdometerDoNotMoveCoverage(): void
    {
        $gaps = OdometerGapCalculator::gaps(
            [self::reading(1, '2099-01-10', 1087)],
            [self::trip('2099-01-05', null)],
            ['odometer' => 1000, 'date' => null],
        );

        self::assertSame(87, $gaps[0]['km']);
        self::assertNull($gaps[0]['date_from']);
    }

    public function testFirstReadingWithoutAnyBaselineOnlyStartsTheSeries(): void
    {
        $gaps = OdometerGapCalculator::gaps(
            [self::reading(1, '2099-01-10', 5000), self::reading(2, '2099-01-11', 5040)],
            [],
            null,
        );

        self::assertSame([['reading_id' => 2, 'date_from' => '2099-01-10', 'date_to' => '2099-01-11',
            'odometer_from' => 5000, 'odometer_to' => 5040, 'km' => 40]], $gaps);
    }

    public function testTripAfterReadingDateIsIgnoredForThatReading(): void
    {
        $gaps = OdometerGapCalculator::gaps(
            [self::reading(1, '2099-01-10', 1087)],
            [self::trip('2099-01-11', 1200)],
            ['odometer' => 1000, 'date' => null],
        );

        self::assertSame(87, $gaps[0]['km']);
    }

    public function testManualReadingOfTheDayTakesPrecedenceOverAutomaticOne(): void
    {
        $gaps = OdometerGapCalculator::gaps(
            [self::reading(1, '2099-01-10', 1100, 'fake_source'), self::reading(2, '2099-01-10', 1050),
                self::reading(3, '2099-01-11', 1120, 'fake_source')],
            [],
            ['odometer' => 1000, 'date' => '2099-01-09'],
        );

        self::assertSame([[2, 1000, 1050], [3, 1050, 1120]],
            array_map(static fn (array $g): array => [$g['reading_id'], $g['odometer_from'], $g['odometer_to']], $gaps));
    }
}
