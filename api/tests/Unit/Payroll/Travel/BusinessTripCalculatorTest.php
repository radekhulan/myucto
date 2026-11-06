<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Travel;

use MyInvoice\Service\Payroll\Calculation\CalculationStep;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Travel\BusinessTrip;
use MyInvoice\Service\Payroll\Travel\BusinessTripCalculation;
use MyInvoice\Service\Payroll\Travel\BusinessTripCalculator;
use MyInvoice\Service\Payroll\Travel\TravelExpenseItem;
use MyInvoice\Service\Payroll\Travel\TravelExpenseItemKind;
use MyInvoice\Service\Payroll\Travel\TravelFuelKind;
use MyInvoice\Service\Payroll\Travel\TravelVehicleKind;
use PHPUnit\Framework\TestCase;

final class BusinessTripCalculatorTest extends TestCase
{
    private BusinessTripCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new BusinessTripCalculator(CzechPayrollRulesets2026::provider());
    }

    /**
     * Hranice časových pásem podle § 163 odst. 1 ZP: 5 až 12 hodin, déle než 12
     * a nejdéle 18 hodin, déle než 18 hodin. Sazby jsou zákonná minima z vyhlášky
     * č. 573/2025 Sb. (155 / 236 / 370 Kč).
     */
    public function testMealAllowanceTimeBandBoundaries(): void
    {
        $cases = [
            ['2026-03-10 06:00', '2026-03-10 10:59', 0, 0],
            ['2026-03-10 06:00', '2026-03-10 11:00', 1, 15_500],
            ['2026-03-10 08:00', '2026-03-10 20:00', 1, 15_500],
            ['2026-03-10 08:00', '2026-03-10 20:01', 2, 23_600],
            ['2026-03-10 00:00', '2026-03-10 18:00', 2, 23_600],
            ['2026-03-10 00:00', '2026-03-10 18:01', 3, 37_000],
        ];

        foreach ($cases as [$from, $to, $band, $expected]) {
            $result = $this->calculator->calculate(new BusinessTrip($from, $to));
            self::assertTrue($result->isSupported(), "{$from} → {$to}: " . implode(', ', $result->blockers));
            self::assertCount(1, $result->mealDays);
            self::assertSame($band, $result->mealDays[0]['band'], "{$from} → {$to}");
            self::assertSame($expected, $result->entitlementTotalMinor, "{$from} → {$to}");
            self::assertSame($expected, $result->exemptTotalMinor);
            self::assertSame(0, $result->taxableTotalMinor);
        }
    }

    /** § 163 odst. 2 ZP — krácení o 70 / 35 / 25 % za každé bezplatné jídlo. */
    public function testFreeMealsReduceEntitlementAndTaxExemptCeiling(): void
    {
        $result = $this->calculator->calculate(new BusinessTrip(
            '2026-03-10 08:00',
            '2026-03-10 16:00',
            freeMeals: ['2026-03-10' => 1],
        ));

        self::assertTrue($result->isSupported());
        // 155,00 − 70 % ze 155,00 = 46,50 Kč, zaokrouhleno nahoru na 47,00 Kč.
        self::assertSame(4_650, $result->mealDays[0]['entitlement_minor']);
        self::assertSame(4_700, $result->entitlementTotalMinor);
        self::assertSame(4_700, $result->exemptTotalMinor);
        self::assertSame(0, $result->taxableTotalMinor);
    }

    public function testThreeFreeMealsInLongBandCannotProduceNegativeAllowance(): void
    {
        $result = $this->calculator->calculate(new BusinessTrip(
            '2026-03-10 00:00',
            '2026-03-10 23:00',
            freeMeals: ['2026-03-10' => 3],
        ));

        self::assertTrue($result->isSupported());
        // 370,00 − 3 × 25 % = 92,50 Kč → nahoru 93,00 Kč.
        self::assertSame(9_250, $result->mealDays[0]['entitlement_minor']);
        self::assertSame(9_300, $result->entitlementTotalMinor);
    }

    /**
     * § 163 odst. 3 ZP — u cesty spadající do dvou kalendářních dnů se od
     * odděleného posouzení upustí, je-li to pro zaměstnance výhodnější.
     */
    public function testTripAcrossMidnightUsesTwoDayMergeWhenMoreFavourable(): void
    {
        $result = $this->calculator->calculate(new BusinessTrip(
            '2026-03-10 23:00',
            '2026-03-11 12:00',
        ));

        self::assertTrue($result->isSupported());
        self::assertCount(1, $result->mealDays);
        self::assertSame('two-calendar-day-merge', $result->mealDays[0]['rule']);
        self::assertSame(['2026-03-10', '2026-03-11'], $result->mealDays[0]['merged_dates']);
        self::assertSame(780, $result->mealDays[0]['minutes']);
        // Odděleně: 0 + 155 Kč; sloučeně 13 h → 236 Kč.
        self::assertSame(23_600, $result->entitlementTotalMinor);
    }

    public function testTripAcrossMidnightKeepsSeparateDaysWhenMoreFavourable(): void
    {
        $result = $this->calculator->calculate(new BusinessTrip(
            '2026-03-10 18:00',
            '2026-03-11 08:00',
        ));

        self::assertTrue($result->isSupported());
        self::assertCount(2, $result->mealDays);
        self::assertSame(31_000, $result->entitlementTotalMinor);
    }

    /** Cesta přes hranici měsíce se posuzuje po kalendářních dnech. */
    public function testTripAcrossMonthBoundarySplitsIntoCalendarDays(): void
    {
        $result = $this->calculator->calculate(new BusinessTrip(
            '2026-03-31 08:00',
            '2026-04-02 10:00',
        ));

        self::assertTrue($result->isSupported());
        self::assertSame(
            ['2026-03-31', '2026-04-01', '2026-04-02'],
            array_column($result->mealDays, 'date'),
        );
        self::assertSame([960, 1_440, 600], array_column($result->mealDays, 'minutes'));
        self::assertSame([2, 3, 1], array_column($result->mealDays, 'band'));
        self::assertSame(76_100, $result->entitlementTotalMinor);
    }

    /**
     * § 157 odst. 3 a § 158 ZP — základní náhrada za km plus náhrada za
     * spotřebované pohonné hmoty; obojí v celých haléřích.
     */
    public function testPrivateVehicleCombinesBasicCompensationAndFuel(): void
    {
        $result = $this->calculator->calculate(new BusinessTrip(
            '2026-03-15 08:00',
            '2026-03-15 11:00',
            items: [$this->vehicleItem('2026-03-15', 150_000, 6_500, TravelFuelKind::PETROL_95)],
        ));

        self::assertTrue($result->isSupported(), implode(', ', $result->blockers));
        $item = $result->items[0];
        // 150 km × 5,90 Kč = 885,00 Kč.
        self::assertSame(88_500, $item['basic_compensation_minor']);
        // 150 km × 6,5 l/100 km = 9,75 l × 34,70 Kč = 338,325 → 338,33 Kč.
        self::assertSame(9_750, $item['fuel_volume_ml']);
        self::assertSame(33_833, $item['fuel_cost_minor']);
        self::assertSame(122_333, $item['entitlement_minor']);
        // 1 223,33 Kč zaokrouhleno nahoru na celé koruny = 1 224,00 Kč.
        self::assertSame(122_400, $result->entitlementTotalMinor);
        self::assertSame(122_400, $result->exemptTotalMinor);
        self::assertSame(0, $result->taxableTotalMinor);
    }

    /** Vyhláška č. 78/2026 Sb. zvedla průměrnou cenu nafty od 1. 6. 2026. */
    public function testDieselPriceFollowsTheRulesetVersionEffectiveOnTheSpendDate(): void
    {
        $before = $this->calculator->calculate(new BusinessTrip(
            '2026-05-31 08:00',
            '2026-05-31 11:00',
            items: [$this->vehicleItem('2026-05-31', 100_000, 6_000, TravelFuelKind::DIESEL)],
        ));
        $after = $this->calculator->calculate(new BusinessTrip(
            '2026-06-01 08:00',
            '2026-06-01 11:00',
            items: [$this->vehicleItem('2026-06-01', 100_000, 6_000, TravelFuelKind::DIESEL)],
        ));

        self::assertTrue($before->isSupported());
        self::assertTrue($after->isSupported());
        // 6 l × 34,10 Kč vs. 6 l × 44,50 Kč.
        self::assertSame(20_460, $before->items[0]['fuel_cost_minor']);
        self::assertSame(26_700, $after->items[0]['fuel_cost_minor']);
        self::assertContains('cz-payroll-2026.travel-allowances.v1', $before->rulesetIds);
        self::assertContains('cz-payroll-2026.travel-allowances.v2', $after->rulesetIds);
    }

    public function testPetrolAveragePriceChangesOnFirstOctober2026(): void
    {
        $before = $this->calculator->calculate(new BusinessTrip(
            '2026-09-30 08:00',
            '2026-09-30 11:00',
            items: [$this->vehicleItem('2026-09-30', 100_000, 6_000, TravelFuelKind::PETROL_95)],
        ));
        $after = $this->calculator->calculate(new BusinessTrip(
            '2026-10-01 08:00',
            '2026-10-01 11:00',
            items: [$this->vehicleItem('2026-10-01', 100_000, 6_000, TravelFuelKind::PETROL_95)],
        ));

        self::assertTrue($before->isSupported());
        self::assertTrue($after->isSupported());
        // Vyhláška č. 175/2026 Sb.: 6 l × 34,70 Kč vs. 6 l × 41,80 Kč.
        self::assertSame(20_820, $before->items[0]['fuel_cost_minor']);
        self::assertSame(25_080, $after->items[0]['fuel_cost_minor']);
        self::assertContains('cz-payroll-2026.travel-allowances.v2', $before->rulesetIds);
        self::assertContains('cz-payroll-2026.travel-allowances.v3', $after->rulesetIds);
    }

    public function testDocumentedFuelPriceOverridesTheAveragePrice(): void
    {
        $result = $this->calculator->calculate(new BusinessTrip(
            '2026-03-15 08:00',
            '2026-03-15 11:00',
            items: [new TravelExpenseItem(
                TravelExpenseItemKind::PRIVATE_VEHICLE,
                '2026-03-15',
                'Doložená cena PHM',
                vehicleKind: TravelVehicleKind::CAR,
                distanceMetres: 100_000,
                consumptionMlPer100Km: 6_000,
                fuelKind: TravelFuelKind::PETROL_95,
                documentedFuelPriceMinor: 3_990,
            )],
        ));

        self::assertTrue($result->isSupported());
        self::assertTrue($result->items[0]['fuel_price_documented']);
        self::assertSame(23_940, $result->items[0]['fuel_cost_minor']);
    }

    /**
     * Sazba stravného nad daňový limit § 6 odst. 7 písm. a) ZDP se rozdělí
     * na osvobozenou a nadlimitní část.
     */
    public function testAboveCeilingMealRateSplitsIntoExemptAndTaxablePart(): void
    {
        $result = $this->calculator->calculate(new BusinessTrip(
            '2026-03-10 08:00',
            '2026-03-10 16:00',
            mealRateBand1Minor: 20_000,
        ));

        self::assertTrue($result->isSupported());
        self::assertSame(20_000, $result->entitlementTotalMinor);
        self::assertSame(18_500, $result->exemptTotalMinor);
        self::assertSame(1_500, $result->taxableTotalMinor);
    }

    public function testUndocumentedIncidentalExpenseIsFullyTaxable(): void
    {
        $result = $this->calculator->calculate(new BusinessTrip(
            '2026-03-10 08:00',
            '2026-03-10 16:00',
            items: [new TravelExpenseItem(
                TravelExpenseItemKind::INCIDENTAL,
                '2026-03-10',
                'Nedoložený vedlejší výdaj',
                amountMinor: 12_000,
                documented: false,
            )],
        ));

        self::assertTrue($result->isSupported());
        self::assertSame(27_500, $result->entitlementTotalMinor);
        self::assertSame(15_500, $result->exemptTotalMinor);
        self::assertSame(12_000, $result->taxableTotalMinor);
    }

    public function testMealRateBelowStatutoryMinimumFailsClosed(): void
    {
        $result = $this->calculator->calculate(new BusinessTrip(
            '2026-03-10 08:00',
            '2026-03-10 16:00',
            mealRateBand1Minor: 10_000,
        ));

        self::assertFalse($result->isSupported());
        self::assertSame(BusinessTripCalculation::STATUS_MANUAL_REVIEW, $result->status);
        self::assertStringContainsString('§ 163 odst. 1', $result->blockers[0]);
        self::assertSame(0, $result->entitlementTotalMinor);
    }

    public function testForeignBusinessTripIsNotSupported(): void
    {
        $result = $this->calculator->calculate(new BusinessTrip(
            '2026-03-10 08:00',
            '2026-03-11 16:00',
            countryCode: 'DE',
        ));

        self::assertFalse($result->isSupported());
        self::assertSame(['foreign_business_trip_not_supported'], $result->blockers);
        self::assertSame(0, $result->entitlementTotalMinor);
        self::assertSame(0, $result->exemptTotalMinor);
        self::assertSame(0, $result->taxableTotalMinor);
    }

    public function testMissingEffectiveRulesetFailsClosedInsteadOfGuessing(): void
    {
        $result = $this->calculator->calculate(new BusinessTrip(
            '2025-03-10 08:00',
            '2025-03-10 16:00',
        ));

        self::assertFalse($result->isSupported());
        self::assertStringContainsString('travel_allowances', $result->blockers[0]);
        self::assertSame(0, $result->entitlementTotalMinor);
    }

    public function testTraceRecordsInputRateOutputAndRoundingDirection(): void
    {
        $result = $this->calculator->calculate(new BusinessTrip(
            '2026-03-15 08:00',
            '2026-03-15 16:00',
            freeMeals: ['2026-03-15' => 1],
            items: [$this->vehicleItem('2026-03-15', 150_000, 6_500, TravelFuelKind::PETROL_95)],
        ));

        self::assertTrue($result->isSupported());
        self::assertNotSame([], $result->steps);
        foreach ($result->steps as $step) {
            self::assertInstanceOf(CalculationStep::class, $step);
            $serialized = $step->jsonSerialize();
            self::assertNotSame('', $serialized['label']);
            self::assertArrayHasKey('decimal', $serialized['rate']);
            self::assertArrayHasKey('rounding_mode', $serialized);
            self::assertIsInt($serialized['input_minor_units']);
            self::assertIsInt($serialized['output_minor_units']);
        }
        $labels = array_map(
            static fn (CalculationStep $step): string => $step->label,
            $result->steps,
        );
        self::assertContains('travel.meal_allowance.2026-03-15.free_meal_1.reduction', $labels);
        self::assertContains('travel.vehicle.0.basic_compensation', $labels);
        self::assertContains('travel.vehicle.0.fuel_volume', $labels);
        self::assertContains('travel.vehicle.0.fuel_cost', $labels);
    }

    public function testAdvanceProducesSettlementDifference(): void
    {
        $result = $this->calculator->calculate(new BusinessTrip(
            '2026-03-10 08:00',
            '2026-03-10 16:00',
            advanceMinor: 10_000,
        ));

        self::assertTrue($result->isSupported());
        self::assertSame(15_500, $result->entitlementTotalMinor);
        self::assertSame(10_000, $result->advanceMinor);
        self::assertSame(5_500, $result->settlementDifferenceMinor);
    }

    private function vehicleItem(
        string $date,
        int $distanceMetres,
        int $consumption,
        TravelFuelKind $fuel,
    ): TravelExpenseItem {
        return new TravelExpenseItem(
            TravelExpenseItemKind::PRIVATE_VEHICLE,
            $date,
            'Jízda soukromým vozidlem',
            vehicleKind: TravelVehicleKind::CAR,
            distanceMetres: $distanceMetres,
            consumptionMlPer100Km: $consumption,
            fuelKind: $fuel,
        );
    }
}
