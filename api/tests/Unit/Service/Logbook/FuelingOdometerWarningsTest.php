<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook;

use MyInvoice\Service\Logbook\FuelingOdometerWarnings;
use PHPUnit\Framework\TestCase;

final class FuelingOdometerWarningsTest extends TestCase
{
    /** @param list<?int> $odometers */
    private static function rows(array $odometers, array $extra = []): array
    {
        $out = [];
        foreach ($odometers as $i => $odo) {
            $out[] = ['id' => $i + 1, 'date' => sprintf('2099-01-%02d', $i + 1), 'time' => null, 'odometer' => $odo] + ($extra[$i] ?? []);
        }
        return $out;
    }

    public function testContinuousSeriesHasNoWarnings(): void
    {
        $a = FuelingOdometerWarnings::analyze(self::rows([1000, 1500, 2000]), 500);

        self::assertSame([], $a['missing']);
        self::assertSame([], $a['regressions']);
        self::assertSame(3, $a['fuelings']);
    }

    public function testMissingOdometerIsReported(): void
    {
        $a = FuelingOdometerWarnings::analyze(self::rows([1000, null, 2000]), null);

        self::assertSame([['id' => 2, 'date' => '2099-01-02']], $a['missing']);
        self::assertSame([], $a['regressions']);
    }

    public function testLowerOdometerThanPreviousFuelingIsRegression(): void
    {
        $a = FuelingOdometerWarnings::analyze(self::rows([1000, 900, 1500]), null);

        self::assertCount(1, $a['regressions']);
        self::assertSame(2, $a['regressions'][0]['id']);
        self::assertSame(1, $a['regressions'][0]['prev_id']);
        self::assertSame(1000, $a['regressions'][0]['prev_odometer']);
    }

    public function testSingleWrongValueDoesNotFlagAllFollowingFuelings(): void
    {
        // Překlep 9 000 místo 1 900 nesmí z dalších tankování (2 000, 2 500) udělat regrese.
        $a = FuelingOdometerWarnings::analyze(self::rows([1000, 900, 2000, 2500]), null);

        self::assertSame([2], array_column($a['regressions'], 'id'));
    }

    public function testOdometerBelowCarStartIsRegression(): void
    {
        $a = FuelingOdometerWarnings::analyze(self::rows([4000]), 5000);

        self::assertCount(1, $a['regressions']);
        self::assertNull($a['regressions'][0]['prev_id']);
        self::assertSame(5000, $a['regressions'][0]['prev_odometer']);
    }

    public function testVatMismatchAgainstVehiclePolicy(): void
    {
        $car = ['vat_deduction_mode' => 'proportional', 'vat_deduction_percent' => 60.0];
        $rows = self::rows([1000, 1100, 1200, 1300], [
            ['doc_deduction' => 'full', 'doc_percent' => 100.0],
            ['doc_deduction' => 'none', 'doc_percent' => 0.0],
            ['doc_deduction' => 'proportional', 'doc_percent' => 60.0],
            ['doc_deduction' => 'reduced', 'doc_percent' => 80.0],
        ]);

        $a = FuelingOdometerWarnings::analyze($rows, null, $car);

        self::assertSame([[1, 'over'], [2, 'under']], array_map(
            static fn (array $m): array => [$m['id'], $m['direction']],
            $a['vat_mismatches'],
        ));
    }
}
