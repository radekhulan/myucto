<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Hourly;

use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageCalculator;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageContext;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class PayrollHourlyEntitlementTest extends TestCase
{
    public function testMissingPremiumEvidencePreservesReadyBaseButNeverApprovesFinalResult(): void
    {
        $result = (new PayrollHourlyWageCalculator())->calculate($this->context(null));
        self::assertFalse($result->complete);
        self::assertSame(160000, $this->claim($result, 'ordinary_base')['payable_minor']);
        self::assertTrue($this->claim($result, 'ordinary_base')['ready']);
        self::assertContains('hourly_premium_evidence_missing', array_column($result->blockers, 'code'));
        self::assertTrue((new PayrollHourlyWageCalculator())->calculate($this->context($this->evidence()))->complete);
    }

    public function testIncludedOvertimeConsumesAnnualCarryAcrossRateChange(): void
    {
        $segments = [$this->segment('2026-07-01', '2026-07-15', 30000, 6000), $this->segment('2026-07-16', '2026-07-31', 30000, 6000)];
        $terms = [[...$this->term(), 'effective_to' => '2026-07-15'], [...$this->term(), 'id' => 2, 'effective_from' => '2026-07-16', 'hourly_rate_minor' => 30000]];
        $evidence = $this->evidence($segments);
        $evidence['policy_versions'][0]['overtime_mode'] = 'included';
        $evidence['policy_versions'][0]['included_overtime'] = $this->includedOvertime();
        $result = (new PayrollHourlyWageCalculator())->calculate($this->context($evidence, $segments, $terms));
        self::assertTrue($result->complete, json_encode($result->blockers));
        $claim = $this->claim($result, 'achieved_overtime');
        self::assertSame(30000, $claim['payable_minor']);
        self::assertSame(12000, $claim['worked_duration_units']);
        self::assertSame(5300, $this->claim($result, 'premium_overtime')['payable_minor']);
        self::assertSame(9000, array_sum(array_column($result->includedAllocations, 'duration_units')));
        $evidence['policy_versions'][0]['included_overtime']['agreement_kind'] = 'wage_statement';
        $invalid = (new PayrollHourlyWageCalculator())->calculate($this->context($evidence, $segments, $terms));
        self::assertFalse($invalid->complete);
        self::assertTrue($this->claim($invalid, 'ordinary_base')['ready']);
        self::assertFalse($this->claim($invalid, 'achieved_overtime')['ready']);
        self::assertNull($this->claim($invalid, 'achieved_overtime')['payable_minor']);
    }

    public function testDppIncludedNightPremiumPaysOnlyExcessAndTracksMinimumExclusion(): void
    {
        $evidence = $this->evidence();
        $evidence['premium_segments'][0]['night_duration_units'] = 24000;
        $evidence['policy_versions'][0]['included_agreement_premiums'] = [[
            'kind' => 'night', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31',
            'scope_duration_units' => 15000, 'included_hourly_minor' => 2100,
            'agreement_reference' => 'Syntetická dohoda', 'opening_used_duration_units' => 0,
            'opening_as_of' => '2026-07-01', 'opening_reference' => 'Počátek sjednání', 'prior_consumed_duration_units' => 0, 'prior_claims_hash' => str_repeat('b', 64),
        ]];
        $result = (new PayrollHourlyWageCalculator())->calculate($this->context($evidence, employmentType: 'dpp'));
        self::assertTrue($result->complete, json_encode($result->blockers));
        self::assertSame(6300, $this->claim($result, 'premium_night')['payable_minor']);
        self::assertSame(10500, $result->includedAllocations[0]['analytical_minor']);
        self::assertSame(0, $this->claim($result, 'premium_night')['worked_duration_units']);
        $evidence['policy_versions'][0]['included_agreement_premiums'][0]['kind'] = 'holiday';
        self::assertFalse((new PayrollHourlyWageCalculator())->calculate($this->context($evidence, employmentType: 'dpp'))->complete);
    }

    public function testHolidayWorkCreatesTimeOffOrRequiresAgreementForPremium(): void
    {
        $evidence = $this->evidence();
        $evidence['premium_segments'][0]['holiday_work_duration_units'] = 24000;
        $evidence['premium_segments'][0]['holiday_work_days'] = [['date' => '2026-07-06', 'duration_units' => 24000]];
        $evidence['average_earning'] = null;
        $calculator = new PayrollHourlyWageCalculator();
        $timeOff = $calculator->calculate($this->context($evidence));
        self::assertTrue($timeOff->complete, json_encode($timeOff->blockers));
        self::assertSame('holiday_time_off_accrual', $timeOff->entitlements[0]['kind']);
        self::assertSame('2026-10-31', $timeOff->entitlements[0]['due_on']);
        self::assertSame(24000, $timeOff->entitlements[0]['duration_units']);
        self::assertSame(0, $this->claim($timeOff, 'premium_holiday')['payable_minor']);
        $evidence['policy_versions'][0]['holiday_mode'] = 'surcharge';
        self::assertFalse($calculator->calculate($this->context($evidence))->complete);
        $evidence['policy_versions'][0]['holiday_surcharge_agreement_reference'] = 'Syntetická dohoda';
        $evidence['average_earning'] = ['id' => 1, 'revision_no' => 1, 'hourly_minor' => 21000];
        $premium = $calculator->calculate($this->context($evidence));
        self::assertTrue($premium->complete);
        self::assertSame(168000, $this->claim($premium, 'premium_holiday')['payable_minor']);
    }

    public function testNonworkedHolidayUsesActualFourHourScheduleWithoutDoubleAbsence(): void
    {
        $evidence = $this->evidence();
        $evidence['nonworked_holidays'] = [[...$this->schedule('2026-07-06'), 'duration_units' => 6000,
            'scheduled_duration_units' => 12000, 'other_absence_duration_units' => 6000, 'reason' => 'holiday']];
        $calculator = new PayrollHourlyWageCalculator();
        $good = $calculator->calculate($this->context($evidence));
        self::assertTrue($good->complete, json_encode($good->blockers));
        self::assertSame(42000, $this->claim($good, 'holiday_nonworked_compensation')['payable_minor']);
        self::assertSame(0, $this->claim($good, 'holiday_nonworked_compensation')['worked_duration_units']);
        $evidence['nonworked_holidays'][0]['duration_units'] = 12000;
        self::assertFalse($calculator->calculate($this->context($evidence))->complete);
        unset($evidence['nonworked_holidays'][0]['scheduled_duration_units']);
        self::assertFalse($calculator->calculate($this->context($evidence))->complete);
    }

    public function testHolidayTimeOffGrantUsesCurrentAverageAndCannotOverdraw(): void
    {
        $evidence = $this->evidence();
        $evidence['holiday_time_off_grants'] = [[...$this->schedule('2026-07-07'),
            'entitlement_id' => 20, 'entitlement_revision' => 1, 'work_date' => '2026-05-01',
            'granted_on' => '2026-07-07', 'duration_units' => 6000, 'available_duration_units' => 6000,
            'absence_id' => 30, 'average_snapshot_id' => 40, 'average_hourly_minor' => 23000]];
        $calculator = new PayrollHourlyWageCalculator();
        $good = $calculator->calculate($this->context($evidence));
        self::assertTrue($good->complete, json_encode($good->blockers));
        self::assertSame(46000, $this->claim($good, 'holiday_time_off_compensation')['payable_minor']);
        self::assertSame('holiday_time_off_allocation', $good->entitlements[0]['kind']);
        $evidence['holiday_time_off_grants'][0]['duration_units'] = 9000;
        self::assertFalse($calculator->calculate($this->context($evidence))->complete);
    }

    /**
     * Noční práce nového vztahu bez schváleného průměru: dřív jen „Chybí
     * povinný kladný údaj nároku". Teď kód, čtvrtletí a vztah pro proklik
     * na formulář průměru.
     */
    public function testNightPremiumWithoutAverageNamesTheMissingQuarter(): void
    {
        $evidence = $this->evidence();
        $evidence['premium_segments'][0]['night_duration_units'] = 6000;
        $evidence['average_earning'] = null;
        $result = (new PayrollHourlyWageCalculator())->calculate($this->context($evidence));
        self::assertFalse($result->complete);
        $blockers = array_values(array_filter($result->blockers, static fn (array $blocker): bool => $blocker['context']['purpose'] === 'premium_night'));
        self::assertCount(1, $blockers, json_encode($result->blockers));
        self::assertSame('hourly_average_earning_missing', $blockers[0]['code']);
        self::assertStringContainsString('Chybí schválený průměrný výdělek za 3. čtvrtletí 2026', $blockers[0]['message']);
        self::assertSame(2026, $blockers[0]['context']['applicable_year']);
        self::assertSame(3, $blockers[0]['context']['applicable_quarter']);
        self::assertTrue($this->claim($result, 'ordinary_base')['ready']);
    }

    public function testNightDerivedFromShiftsIsMarkedInClaimTrace(): void
    {
        $evidence = $this->evidence();
        $evidence['premium_segments'][0]['night_duration_units'] = 6000;
        $evidence['premium_segments'][0]['night_derived_from_shifts'] = true;
        $result = (new PayrollHourlyWageCalculator())->calculate($this->context($evidence));
        self::assertTrue($result->complete, json_encode($result->blockers));
        self::assertTrue($this->claim($result, 'premium_night')['segments'][0]['derived_from_shifts'] ?? false);
        unset($evidence['premium_segments'][0]['night_derived_from_shifts']);
        $explicit = (new PayrollHourlyWageCalculator())->calculate($this->context($evidence));
        self::assertArrayNotHasKey('derived_from_shifts', $this->claim($explicit, 'premium_night')['segments'][0]);
    }

    public function testUnknownNightDoesNotEraseKnownBaseOrWeekendClaim(): void
    {
        $evidence = $this->evidence();
        $evidence['premium_segments'][0]['night_duration_units'] = null;
        $evidence['premium_segments'][0]['weekend_duration_units'] = 3000;
        $result = (new PayrollHourlyWageCalculator())->calculate($this->context($evidence));
        self::assertFalse($result->complete);
        self::assertTrue($this->claim($result, 'ordinary_base')['ready']);
        self::assertSame(2100, $this->claim($result, 'premium_weekend')['payable_minor']);
        self::assertFalse($this->claim($result, 'premium_night')['ready']);
        self::assertNull($this->claim($result, 'premium_night')['payable_minor']);
    }

    public function testIncludedOvertimeNeedsFiniteCapManagerProofAndCompleteCarry(): void
    {
        $segments = [$this->segment(worked: 30000, overtime: 6000)];
        $evidence = $this->evidence($segments);
        $evidence['policy_versions'][0]['overtime_mode'] = 'included';
        $agreement = [...$this->includedOvertime(), 'prior_consumed_duration_units' => 0];
        $calculator = new PayrollHourlyWageCalculator();
        foreach ([['annual_limit_duration_units' => 0], ['annual_limit_duration_units' => 453000], ['prior_consumed_duration_units' => null]] as $invalid) {
            $evidence['policy_versions'][0]['included_overtime'] = [...$agreement, ...$invalid];
            $result = $calculator->calculate($this->context($evidence, $segments));
            self::assertFalse($result->complete);
            self::assertFalse($this->claim($result, 'achieved_overtime')['ready']);
        }
        $evidence['policy_versions'][0]['included_overtime'] = [...$agreement, 'scope_kind' => 'manager_statutory',
            'annual_limit_duration_units' => 600000, 'manager_lawful_scope_duration_units' => 600000, 'manager_lawful_scope_reference' => 'Syntetický zákonný rozsah', 'manager_proof_reference' => 'Syntetické doložení vedoucího a rozsahu'];
        $evidence['average_earning'] = null;
        $covered = $calculator->calculate($this->context($evidence, $segments));
        self::assertTrue($covered->complete, json_encode($covered->blockers));
        self::assertSame(0, $this->claim($covered, 'achieved_overtime')['payable_minor']);
        self::assertSame(6000, $this->claim($covered, 'achieved_overtime')['worked_duration_units']);
        self::assertSame(0, $this->claim($covered, 'premium_overtime')['payable_minor']);
    }

    public function testIncludedDifficultPremiumCannotUnderpayStatutoryFloor(): void
    {
        $evidence = $this->evidence();
        $evidence['premium_segments'][0]['difficult_exposures'] = [['duration_units' => 3000, 'factors' => 2]];
        $evidence['policy_versions'][0]['included_agreement_premiums'] = [[
            'kind' => 'difficult_environment', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31',
            'scope_duration_units' => 3000, 'included_hourly_minor' => 1000,
            'agreement_reference' => 'Syntetická dohoda', 'opening_used_duration_units' => 0,
            'opening_as_of' => '2026-07-01', 'opening_reference' => 'Počátek sjednání', 'prior_consumed_duration_units' => 0, 'prior_claims_hash' => str_repeat('b', 64),
        ]];
        $evidence['average_earning'] = null;
        $result = (new PayrollHourlyWageCalculator())->calculate($this->context($evidence, employmentType: 'dpc'));
        self::assertTrue($result->complete, json_encode($result->blockers));
        self::assertSame(1700, $this->claim($result, 'premium_difficult_environment')['payable_minor']);
        self::assertSame(1000, $result->includedAllocations[0]['analytical_minor']);
    }

    public function testExplicitFixedNightRateNeedsAgreementButNoAverage(): void
    {
        $evidence = $this->evidence();
        $evidence['premium_segments'][0]['night_duration_units'] = 3000;
        $evidence['policy_versions'][0]['rates']['night']['agreed_fixed_hourly_minor'] = 1000;
        $evidence['average_earning'] = null;
        $calculator = new PayrollHourlyWageCalculator();
        self::assertFalse($calculator->calculate($this->context($evidence))->complete);
        $evidence['policy_versions'][0]['rates']['night']['agreement_reference'] = 'Doložená pevná sazba';
        $result = $calculator->calculate($this->context($evidence));
        self::assertTrue($result->complete);
        self::assertSame(1000, $this->claim($result, 'premium_night')['payable_minor']);
    }

    public function testHolidayGrantCannotSpendSameBalanceTwiceOrUseUnverifiedCalendar(): void
    {
        $evidence = $this->evidence();
        $grant = [...$this->schedule('2026-07-07'), 'entitlement_id' => 20, 'entitlement_revision' => 1,
            'work_date' => '2026-05-01', 'granted_on' => '2026-07-07', 'duration_units' => 6000,
            'available_duration_units' => 6000, 'absence_id' => 30, 'average_snapshot_id' => 40, 'average_hourly_minor' => 23000];
        $evidence['holiday_time_off_grants'] = [$grant, [...$grant, 'granted_on' => '2026-07-08', 'date' => '2026-07-08']];
        $calculator = new PayrollHourlyWageCalculator();
        $overdrawn = $calculator->calculate($this->context($evidence));
        self::assertFalse($overdrawn->complete);
        self::assertFalse($overdrawn->entitlements[0]['ready']);
        $evidence['holiday_time_off_grants'] = [$grant];
        $evidence['holiday_calendar']['content_sha256'] = str_repeat('0', 64);
        self::assertFalse($calculator->calculate($this->context($evidence))->complete);
    }

    public function testOvertimeTimeOffHasExplicitWorkDaysAndPaysPremiumAfterDeadline(): void
    {
        $segments = [$this->segment(worked: 30000, overtime: 6000)];
        $evidence = $this->evidence($segments);
        $evidence['policy_versions'][0]['overtime_mode'] = 'time_off';
        $evidence['premium_segments'][0]['overtime_work_days'] = [['date' => '2026-07-07', 'duration_units' => 6000]];
        $calculator = new PayrollHourlyWageCalculator();
        $pending = $calculator->calculate($this->context($evidence, $segments));
        self::assertTrue($pending->complete);
        self::assertSame(40000, $this->claim($pending, 'achieved_overtime')['payable_minor']);
        self::assertSame(0, $this->claim($pending, 'premium_overtime')['payable_minor']);
        self::assertSame('overtime_time_off_accrual', $pending->entitlements[0]['kind']);
        $evidence['as_of'] = '2026-11-01';
        $expired = $calculator->calculate($this->context($evidence, $segments));
        self::assertTrue($expired->complete);
        self::assertSame(10500, $this->claim($expired, 'premium_overtime')['payable_minor']);
    }

    public function testHistoricalOvertimeSettlesAtDeadlineWithWorkQuarterAverageAndCurrentFloor(): void
    {
        $evidence = $this->evidence();
        $historical = ['id' => 51, 'revision' => 4, 'kind' => 'overtime', 'work_date' => '2026-04-10',
            'due_on' => '2026-07-31', 'available_duration_units' => 3000, 'average_basis' => 'work_quarter',
            'current_minimum_hourly_minor' => 13440, 'average_earning' => ['id' => 2, 'revision_no' => 1, 'hourly_minor' => 10000],
            'ruleset' => $evidence['ruleset'], 'policy' => [...$evidence['policy_versions'][0], 'overtime_mode' => 'time_off']];
        $evidence['historical_overtime'] = [$historical];
        $base = $this->context($evidence);
        $context = new PayrollHourlyWageContext(...[...get_object_vars($base), 'terms' => [], 'segments' => [], 'workedTimeEvidence' => null, 'settlementOnly' => true]);
        $result = (new PayrollHourlyWageCalculator())->calculate($context);
        self::assertTrue($result->complete, json_encode($result->blockers));
        self::assertSame(['premium_overtime', 'holiday_time_off_compensation'], array_column($result->claims, 'purpose'));
        $claim = $this->claim($result, 'premium_overtime');
        self::assertSame(3400, $claim['payable_minor']);
        self::assertSame(0, $claim['worked_duration_units']);
        self::assertSame('work_quarter', $claim['segments'][0]['average_basis']);
        self::assertSame(13440, $claim['segments'][0]['average_hourly_minor']);
        self::assertSame('overtime_premium_settlement', $result->entitlements[0]['kind']);
        self::assertSame(51, $result->entitlements[0]['entitlement_id']);
        $evidence['historical_overtime'][0]['available_duration_units'] = 0;
        $again = (new PayrollHourlyWageCalculator())->calculate(new PayrollHourlyWageContext(...[...get_object_vars($context), 'entitlementContext' => $evidence]));
        self::assertSame(0, $this->claim($again, 'premium_overtime')['payable_minor']);
        self::assertSame([], $again->entitlements);
    }

    public function testHistoricalOvertimeAllocationNeedsNoAverageAndUnknownAllocationIsNotZero(): void
    {
        $evidence = $this->evidence();
        $evidence['historical_overtime'] = [['id' => 51, 'revision' => 4, 'kind' => 'overtime', 'work_date' => '2026-04-10', 'due_on' => '2026-07-31', 'available_duration_units' => 3000]];
        $evidence['overtime_time_off_allocations'] = [[...$this->schedule('2026-07-10'), 'work_date' => '2026-04-10', 'granted_on' => '2026-07-10', 'duration_units' => 3000, 'absence_id' => 9, 'reference' => 'Syntetické čerpání']];
        $base = $this->context($evidence);
        $context = new PayrollHourlyWageContext(...[...get_object_vars($base), 'settlementOnly' => true]);
        $calculator = new PayrollHourlyWageCalculator();
        $result = $calculator->calculate($context);
        self::assertTrue($result->complete, json_encode($result->blockers));
        self::assertSame(0, $this->claim($result, 'premium_overtime')['payable_minor']);
        self::assertSame('overtime_time_off_allocation', $result->entitlements[0]['kind']);
        $evidence['overtime_time_off_allocations'] = null;
        self::assertFalse($calculator->calculate(new PayrollHourlyWageContext(...[...get_object_vars($context), 'entitlementContext' => $evidence]))->complete);
        $evidence['overtime_time_off_allocations'] = [];
        self::assertFalse($calculator->calculate(new PayrollHourlyWageContext(...[...get_object_vars($context), 'entitlementContext' => $evidence]))->complete);
    }

    public function testIncludedOvertimeRequiresSalaryItselfToBeContractuallyAgreed(): void
    {
        $segments = [$this->segment(worked: 30000, overtime: 3000)];
        $evidence = $this->evidence($segments);
        $evidence['policy_versions'][0]['overtime_mode'] = 'included';
        $evidence['policy_versions'][0]['included_overtime'] = $this->includedOvertime();
        $calculator = new PayrollHourlyWageCalculator();
        foreach (['wage_statement', 'internal_regulation', null] as $basis) {
            $result = $calculator->calculate($this->context($evidence, $segments, [[...$this->term(), 'remuneration_basis_kind' => $basis]]));
            self::assertFalse($result->complete);
            self::assertContains('hourly_included_overtime_salary_not_agreed', array_column($result->blockers, 'code'));
        }
        foreach (['contract', 'collective_agreement', 'agreement'] as $basis) {
            self::assertTrue($calculator->calculate($this->context($evidence, $segments, [[...$this->term(), 'remuneration_basis_kind' => $basis]]))->complete);
        }
    }

    private function context(?array $evidence, ?array $segments = null, ?array $terms = null, string $employmentType = 'employment'): PayrollHourlyWageContext
    {
        $segments ??= [$this->segment()];
        $source = ['schema_version' => 'payroll-worked-time.v1', 'duration_units_per_hour' => 3000,
            'time_month_id' => 9, 'revision_no' => 1, 'row_version' => 3, 'status' => 'approved',
            'work_source' => 'manual_summary', 'period_start' => '2026-07-01',
            'manual_summary' => ['id' => 11, 'time_month_id' => 9, 'time_month_revision_no' => 1, 'segments' => $segments]];
        $source['source_sha256'] = hash('sha256', CanonicalJson::encode($source));
        return new PayrollHourlyWageContext(7, '2026-07-01', $employmentType, $terms ?? [$this->term()], $segments, $source, entitlementContext: $evidence);
    }

    public function testMinimumAndAverageFloorFollowEachEffectiveSegment(): void
    {
        $segments = [$this->segment('2026-07-01', '2026-07-15', 3000), $this->segment('2026-07-16', '2026-07-31', 3000)];
        $evidence = $this->evidence($segments);
        $evidence['average_earning']['hourly_minor'] = 10000;
        $evidence['minimum_floor_segments'] = [
            ['date_from' => '2026-07-01', 'date_to' => '2026-07-15', 'minimum_hourly_minor' => 13440, 'minimum_base40_minor' => 13440],
            ['date_from' => '2026-07-16', 'date_to' => '2026-07-31', 'minimum_hourly_minor' => 14340, 'minimum_base40_minor' => 13440],
        ];
        foreach ($evidence['premium_segments'] as &$row) {
            $row['night_duration_units'] = 3000;
            $row['difficult_exposures'] = [['duration_units' => 3000, 'factors' => 1]];
        }
        unset($row);
        $result = (new PayrollHourlyWageCalculator())->calculate($this->context($evidence, $segments));
        self::assertTrue($result->complete, json_encode($result->blockers));
        self::assertSame(2800, $this->claim($result, 'premium_night')['payable_minor']);
        // § 118 ZP: ztížené prostředí ze základní sazby pro 40 h, ne z přepočtu na kratší týden.
        self::assertSame(2688, $this->claim($result, 'premium_difficult_environment')['analytical_minor']);
        self::assertSame(2778, $this->claim($result, 'premium_night')['analytical_minor']);
        // Jeden nerozdělený podklad nesmí přebrat minimum z poslední verze.
        $crossing = $this->evidence();
        $crossing['minimum_floor_segments'] = $evidence['minimum_floor_segments'];
        $crossing['premium_segments'][0]['night_duration_units'] = 3000;
        $invalid = (new PayrollHourlyWageCalculator())->calculate($this->context($crossing));
        self::assertFalse($invalid->complete);
        self::assertContains('hourly_minimum_floor_partition_missing', array_column($invalid->blockers, 'code'));
        self::assertTrue($this->claim($invalid, 'ordinary_base')['ready']);
    }

    public function testHolidayCompensationsUseFloorOnTheActualDay(): void
    {
        $evidence = $this->evidence();
        $evidence['average_earning']['hourly_minor'] = 10000;
        $evidence['minimum_floor_segments'] = [['date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'minimum_hourly_minor' => 14340]];
        $evidence['nonworked_holidays'] = [[...$this->schedule('2026-07-06'), 'duration_units' => 3000, 'reason' => 'holiday']];
        $evidence['holiday_time_off_grants'] = [[...$this->schedule('2026-07-07'), 'entitlement_id' => 20, 'entitlement_revision' => 1,
            'work_date' => '2026-05-01', 'granted_on' => '2026-07-07', 'duration_units' => 3000, 'available_duration_units' => 3000,
            'absence_id' => 30, 'average_snapshot_id' => 40, 'average_hourly_minor' => 10000]];
        $result = (new PayrollHourlyWageCalculator())->calculate($this->context($evidence));
        self::assertTrue($result->complete, json_encode($result->blockers));
        self::assertSame(14400, $this->claim($result, 'holiday_nonworked_compensation')['payable_minor']);
        self::assertSame(14400, $this->claim($result, 'holiday_time_off_compensation')['payable_minor']);
        self::assertSame(14340, $this->claim($result, 'holiday_nonworked_compensation')['segments'][0]['applied_average_hourly_minor']);
        self::assertSame(14340, $this->claim($result, 'holiday_time_off_compensation')['segments'][0]['applied_average_hourly_minor']);
        $evidence['minimum_floor_segments'] = [];
        self::assertFalse((new PayrollHourlyWageCalculator())->calculate($this->context($evidence))->complete);
    }

    private function evidence(?array $segments = null): array
    {
        $segments ??= [$this->segment()];
        $rates = [];
        foreach (['night' => 1000, 'weekend' => 1000, 'holiday' => 10000, 'overtime' => 2500, 'difficult_environment' => 1000] as $kind => $bp) {
            $rates[$kind] = ['statutory_basis' => $kind === 'difficult_environment' ? 'minimum' : 'average', 'statutory_rate_bp' => $bp,
                'agreed_rate_bp' => null, 'agreed_fixed_hourly_minor' => null, 'agreement_reference' => null];
        }
        return ['schema_version' => 'hourly-entitlements.v1', 'as_of' => '2026-07-31',
            'holiday_calendar' => ['dates' => ['2026-05-01', '2026-07-05', '2026-07-06'], 'content_sha256' => hash('sha256', CanonicalJson::encode(['2026-05-01', '2026-07-05', '2026-07-06']))],
            'ruleset' => ['id' => 1, 'content_sha256' => str_repeat('a', 64), 'holiday_time_off_months' => 3, 'overtime_time_off_months' => 3],
            'average_earning' => ['id' => 1, 'revision_no' => 1, 'hourly_minor' => 21000], 'minimum_hourly_minor' => 13440,
            'policy_versions' => [['id' => 1, 'revision_no' => 1, 'content_sha256' => str_repeat('c', 64), 'date_from' => '2026-07-01', 'date_to' => '2026-07-31',
                'document_reference' => 'Syntetická politika', 'overtime_mode' => 'surcharge', 'holiday_mode' => 'time_off',
                'holiday_surcharge_agreement_reference' => null, 'included_overtime' => null, 'included_agreement_premiums' => [], 'rates' => $rates]],
            'premium_segments' => array_map(static fn(array $s): array => ['date_from' => $s['date_from'], 'date_to' => $s['date_to'], 'policy_version_id' => 1,
                'night_duration_units' => 0, 'weekend_duration_units' => 0, 'holiday_work_duration_units' => 0, 'difficult_exposures' => []], $segments),
            'nonworked_holidays' => [], 'holiday_time_off_grants' => [], 'overtime_time_off_allocations' => []];
    }

    private function includedOvertime(): array
    {
        return ['agreement_kind' => 'contract', 'agreement_reference' => 'Syntetická smlouva', 'scope_kind' => 'annual_hours',
            'calendar_year' => 2026, 'annual_limit_duration_units' => 450000, 'manager_proof_reference' => null,
            'opening_used_duration_units' => 0, 'opening_as_of' => '2026-01-01', 'opening_reference' => 'Počátek roku',
            'prior_consumed_duration_units' => 441000, 'prior_claims_hash' => str_repeat('d', 64)];
    }

    private function schedule(string $date): array
    {
        return ['date' => $date, 'schedule_revision_id' => 4, 'schedule_hash' => str_repeat('e', 64),
            'absence_coverage_hash' => str_repeat('f', 64), 'scheduled_duration_units' => 24000,
            'worked_duration_units' => 0, 'other_absence_duration_units' => 0];
    }

    private function segment(string $from = '2026-07-01', string $to = '2026-07-31', int $worked = 24000, int $overtime = 0): array
    {
        return ['date_from' => $from, 'date_to' => $to, 'worked_duration_units' => $worked, 'overtime_duration_units' => $overtime, 'worked_days' => null];
    }

    private function term(): array
    {
        return ['id' => 1, 'row_version' => 1, 'effective_from' => '2026-01-01', 'effective_to' => null,
            'remuneration_type' => 'hourly', 'hourly_rate_minor' => 20000, 'monthly_gross_minor' => null, 'remuneration_basis_kind' => 'contract'];
    }

    private function claim(object $result, string $purpose): array
    {
        return array_values(array_filter($result->claims, static fn(array $claim): bool => $claim['purpose'] === $purpose))[0];
    }
}
