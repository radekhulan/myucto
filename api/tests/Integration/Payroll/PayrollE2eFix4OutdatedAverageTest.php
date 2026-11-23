<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Absence\AverageEarningBatchService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Schválený průměrný výdělek, který po opravě výpočtu (svátek u měsíční mzdy
 * patří do odpracované doby, § 353 odst. 2 zákoníku práce) neodpovídá
 * podkladům: přehled průměrů řekne proč a o kolik, hromadný přepočet založí
 * novou schválenou revizi, vyplacené náhrady se nemění a běh to nezastaví.
 *
 * Měsíční mzda 44 000 Kč, leden až březen 2026 odpracované celé:
 * 132 000 Kč / 512 h (176 + 160 + 176, leden včetně 8 h svátku 1. 1.)
 * = 257,81 Kč/h. Před opravou se dělilo 504 h: 132 000 / 504 = 261,90 Kč/h.
 * Dovolená 13. a 14. 4. (16 h): náhrada ze starého průměru 16 × 261,90
 * = 4 190,40 → 4 191 Kč; mzda 44 000 × 160 / 176 = 40 000 Kč.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eFix4OutdatedAverageTest extends TestCase
{
    use PayrollE2eHpp2VacationFlowTrait;

    protected function setUp(): void
    {
        $this->bootLeaveFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testOutdatedApprovedAverageIsExplainedRecalculatedInBulkAndPaidCompensationStays(): void
    {
        $person = $this->hireMonthly('Syntetická Průměrná', 'female', '1988-04-12', 4_400_000);
        $employmentId = $person['employment_id'];
        foreach (['2026-01', '2026-02', '2026-03'] as $period) {
            $this->workedMonth([$person], $period, 'outdated');
        }
        // Průměr schválený před opravou výpočtu: bez 8 h svátku 1. 1.
        $old = $this->manualAverage($employmentId, 2, [
            'gross_earnings_minor' => 13_200_000,
            'worked_minutes' => 504 * 60,
            'worked_days' => 63,
        ]);
        self::assertSame(26_190, (int) $old['average_hourly_minor']);
        // Dubnová dovolená schválená a zúčtovaná ze starého průměru.
        $this->createApprovedAbsence($employmentId, 'vacation', '2026-04-13', '2026-04-14', (int) $old['id']);
        self::assertSame(4_000_000, $this->materializeWage($person, '2026-04'));
        self::assertSame(419_100, $this->inputAmount($employmentId, '2026-04', 'NAHRADA_MZDY_DOVOLENA'));

        // Přehled průměrů: proč je průměr neaktuální a o kolik se liší.
        $batch = $this->container->get(AverageEarningBatchService::class);
        self::assertInstanceOf(AverageEarningBatchService::class, $batch);
        $candidate = $this->candidate($batch, $employmentId);
        self::assertFalse($candidate['ready']);
        self::assertSame(['average_already_exists'], $candidate['blockers']);
        self::assertTrue($candidate['existing_outdated']);
        self::assertSame('holiday_hours', $candidate['existing_outdated_reason']);
        self::assertSame(26_190, $candidate['existing']['average_hourly_minor']);
        self::assertSame(25_781, $candidate['suggested_average_hourly_minor']);

        // Bez výslovné žádosti se schválený průměr nepřepisuje.
        $untouched = $batch->createBatch($this->supplierId, 2026, 2, [[
            'employment_id' => $employmentId,
            'input_version' => (string) $candidate['input_version'],
        ]], $this->actors[0]);
        self::assertSame([], $untouched['approved']);
        self::assertSame(['average_already_exists'], $untouched['skipped'][0]['blockers']);

        // Hromadný přepočet: nová schválená revize z dnešních podkladů.
        $recalculated = $batch->createBatch($this->supplierId, 2026, 2, [[
            'employment_id' => $employmentId,
            'input_version' => (string) $candidate['input_version'],
            'recalculate_outdated' => true,
        ]], $this->actors[0]);
        self::assertCount(1, $recalculated['approved'], CanonicalJson::encode($recalculated));
        $new = $this->approvedAverage($employmentId, 2);
        self::assertNotSame((int) $old['id'], (int) $new['id']);
        self::assertSame(25_781, (int) $new['average_hourly_minor']);
        self::assertSame(512 * 60, (int) $new['worked_minutes']);
        self::assertSame((int) $old['revision_no'] + 1, (int) $new['revision_no']);
        self::assertSame('superseded', $this->scalar(
            'SELECT status FROM payroll_average_earning_snapshots WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, (int) $old['id']],
        ));
        $after = $this->candidate($batch, $employmentId);
        self::assertFalse($after['existing_outdated']);
        self::assertNull($after['suggested_average_hourly_minor']);

        // Už vyplacená (zúčtovaná) náhrada se zpětně nemění a duben se uzavře.
        self::assertSame(419_100, $this->inputAmount($employmentId, '2026-04', 'NAHRADA_MZDY_DOVOLENA'));
        $this->approveAttendance($person, '2026-04', ['2026-04-13', '2026-04-14']);
        $april = $this->approvedRun('2026-04', 'outdated');
        // Hrubá 40 000 + 4 191 = 44 191.
        self::assertSame(4_419_100, $april['people'][0]['income_tax']['advance_tax']['taxable_income_minor_units']);
    }

    /**
     * Neaktuální průměr není nález mzdového běhu: měsíc, ve kterém se průměr
     * nepoužije, se spočítá a schválí bez blokace i bez varování.
     */
    public function testOutdatedAverageDoesNotStopARunThatDoesNotUseIt(): void
    {
        $person = $this->hireMonthly('Syntetický Nedotčený', 'male', '1985-05-15', 4_400_000);
        foreach (['2026-01', '2026-02', '2026-03'] as $period) {
            $this->workedMonth([$person], $period, 'untouched');
        }
        $this->manualAverage($person['employment_id'], 2, [
            'gross_earnings_minor' => 13_200_000,
            'worked_minutes' => 504 * 60,
            'worked_days' => 63,
        ]);
        $batch = $this->container->get(AverageEarningBatchService::class);
        self::assertInstanceOf(AverageEarningBatchService::class, $batch);
        self::assertTrue($this->candidate($batch, $person['employment_id'])['existing_outdated']);

        // `workedMonth` selže na jakékoli blokaci nebo varování s potvrzením.
        $april = $this->workedMonth([$person], '2026-04', 'untouched');

        self::assertSame(3_486_600, $april['people'][0]['net_payable_minor_units']);
    }

    /** @return array<string,mixed> */
    private function candidate(AverageEarningBatchService $batch, int $employmentId): array
    {
        foreach ($batch->page($this->supplierId, 2026, 2, 100)['items'] as $item) {
            if ((int) $item['employment_id'] === $employmentId) {
                return $item;
            }
        }
        self::fail("Vztah {$employmentId} není v přehledu průměrů.");
    }
}
