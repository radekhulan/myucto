<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Hourly;

use MyInvoice\Service\Payroll\PayrollHourlyEntitlementContextLoader as Loader;
use MyInvoice\Service\Payroll\PayrollHourlyNativePremiums;
use MyInvoice\Service\Payroll\PayrollHourlyScheduleProof;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeRuleset;
use PHPUnit\Framework\TestCase;

final class PayrollHourlyEvidenceProofTest extends TestCase
{
    public function testActualScheduleAndUnknownDailyManualWorkNeverBecomeEightHours(): void
    {
        $proof = PayrollHourlyScheduleProof::forDate('2026-07-06', [$this->shift()], [], $this->source());
        self::assertSame(12000, $proof['scheduled_duration_units']);
        self::assertSame(12000, $proof['available_duration_units']);
        $source = $this->source();
        $source['manual_summary']['segments'][0]['worked_duration_units'] = 3000;
        $this->expectException(\InvalidArgumentException::class);
        PayrollHourlyScheduleProof::forDate('2026-07-06', [$this->shift()], [], $source);
    }

    public function testHolidaySelectionDoesNotDoublePayOtherAbsenceAndNeedsNoAverageAtSave(): void
    {
        $data = ['shifts' => [$this->shift()], 'absences' => [$this->absence('vacation', 60)]];
        $payload = $this->emptyPayload();
        $payload['nonworked_holidays'] = [['date' => '2026-07-06', 'duration_units' => 9000]];
        $result = Loader::selectionProofs($payload, $this->source(), $data, ['entitlements' => []], ['dates' => ['2026-07-06']], $this->terms(), null);
        self::assertSame(3000, $result['nonworked_holidays'][0]['other_absence_duration_units']);
        self::assertSame(9000, $result['nonworked_holidays'][0]['duration_units']);
        $payload['nonworked_holidays'][0]['duration_units'] = 12000;
        $this->expectException(\InvalidArgumentException::class);
        Loader::selectionProofs($payload, $this->source(), $data, ['entitlements' => []], ['dates' => ['2026-07-06']], $this->terms(), null);
    }

    public function testHolidayGrantRequiresCorrectPaidAbsenceAndAggregateAvailableEntitlement(): void
    {
        $payload = $this->emptyPayload();
        $payload['holiday_time_off_grants'] = [['entitlement_id' => 30, 'entitlement_revision' => 2, 'granted_on' => '2026-07-06', 'duration_units' => 3000, 'absence_id' => 7]];
        $data = ['shifts' => [$this->shift()], 'absences' => [$this->absence('holiday_compensatory_time_off')]];
        $ledger = ['entitlements' => [['id' => 30, 'revision' => 2, 'kind' => 'holiday', 'work_date' => '2026-05-01', 'available_duration_units' => 3000]]];
        $result = Loader::selectionProofs($payload, $this->source(), $data, $ledger, ['dates' => ['2026-05-01']], $this->terms(), null);
        self::assertNull($result['holiday_time_off_grants'][0]['average_hourly_minor']);
        self::assertSame(3000, $result['holiday_time_off_grants'][0]['available_duration_units']);
        $payload['holiday_time_off_grants'][] = $payload['holiday_time_off_grants'][0];
        $this->expectException(\InvalidArgumentException::class);
        Loader::selectionProofs($payload, $this->source(), $data, $ledger, ['dates' => ['2026-05-01']], $this->terms(), null);
    }

    public function testUnpaidOvertimeAbsenceCannotProvePaidHolidayGrant(): void
    {
        $payload = $this->emptyPayload();
        $payload['holiday_time_off_grants'] = [['entitlement_id' => 30, 'entitlement_revision' => 2, 'granted_on' => '2026-07-06', 'duration_units' => 3000, 'absence_id' => 7]];
        $this->expectException(\InvalidArgumentException::class);
        Loader::selectionProofs($payload, $this->source(), ['shifts' => [$this->shift()], 'absences' => [$this->absence('compensatory_time_off')]], ['entitlements' => []], ['dates' => []], $this->terms(), null);
    }

    public function testPublishedScheduleCannotBeReplacedByDraftOrMidnightBreakGuess(): void
    {
        $shift = [...$this->shift(), 'status' => 'draft'];
        $this->expectException(\InvalidArgumentException::class);
        PayrollHourlyScheduleProof::forDate('2026-07-06', [$shift], [], $this->source());
    }

    public function testPolicyIntervalsIncludeStatutoryGapsAndStableBoundedAgreementScope(): void
    {
        $rules = PayrollSurchargeRuleset::forDate(PayrollRulesetRegistry::defaults(), '2026-07-01');
        $agreement = ['kind' => 'night', 'agreement_reference' => str_repeat('Ž', 500), 'date_from' => '2026-07-01', 'date_to' => '2026-12-31', 'opening_as_of' => '2026-07-01'];
        $scope = Loader::agreementScope($agreement);
        self::assertLessThan(100, strlen($scope));
        self::assertSame($scope, Loader::agreementScope($agreement));
        $row = ['id' => 9, 'row_version' => 2, 'valid_from' => '2026-07-15', 'valid_to' => '2026-07-20', 'hourly_terms' => ['included_agreement_premiums' => [$agreement]]];
        $policies = Loader::policyVersions([$row], [], $rules, [['scope_key' => $scope, 'accounting_period_start' => '2026-07-01', 'duration_units' => 6000]], '2026-07-01', '2026-07-31');
        self::assertSame(['2026-07-01', '2026-07-15', '2026-07-21'], array_column($policies, 'date_from'));
        self::assertSame('statutory', $policies[0]['source']);
        self::assertNull($policies[0]['id']);
        self::assertSame(6000, $policies[1]['included_agreement_premiums'][0]['prior_consumed_duration_units']);
        self::assertSame(2500, $policies[0]['rates']['overtime']['statutory_rate_bp']);
    }

    /**
     * Víkend bez překryvného záznamu se odvodí ze směny (rozhodnutí
     * maintainera); výslovný překryv dál platí přednostně.
     */
    public function testMissingWeekendLayerIsDerivedAndExplicitOverlayRestoresExactUnits(): void
    {
        $rules = PayrollSurchargeRuleset::forDate(PayrollRulesetRegistry::defaults(), '2026-07-01');
        $policies = Loader::policyVersions([], [], $rules, [], '2026-07-01', '2026-07-31');
        $work = [...$this->shift(), 'category' => 'regular', 'starts_at_utc' => '2026-07-04 06:00:00', 'ends_at_utc' => '2026-07-04 10:00:00', 'status' => 'approved'];
        $segments = [['date_from' => '2026-07-04', 'date_to' => '2026-07-04', 'worked_duration_units' => 12000, 'overtime_duration_units' => 0]];
        $project = fn(array $entries) => PayrollHourlyNativePremiums::project($entries, $segments, $policies, $rules, '2026-07-01', '2026-07-31', ['dates' => ['2026-07-05', '2026-07-06']]);
        $derived = $project([$work])[0];
        self::assertSame(12000, $derived['weekend_duration_units']);
        self::assertTrue($derived['weekend_derived_from_shifts']);
        self::assertSame(0, $derived['night_duration_units']);
        self::assertArrayNotHasKey('night_derived_from_shifts', $derived);
        $withFlag = $project([$work, [...$work, 'id' => 2, 'category' => 'weekend']]);
        self::assertSame(12000, $withFlag[0]['weekend_duration_units']);
        self::assertArrayNotHasKey('weekend_derived_from_shifts', $withFlag[0]);
        self::assertSame(0, $withFlag[0]['night_duration_units']);
        self::assertSame([], $withFlag[0]['difficult_exposures']);
    }

    /**
     * Směna So 18:00 až Ne 02:30 (místní čas) s přestávkou 30 min: noc 4:30 h,
     * víkend 8:00 h. Přestávka s neznámou polohou leží mimo příplatkovou dobu,
     * takže noc zůstává celá; víkend omezí čistá doba záznamu.
     */
    public function testNightAndWeekendAreDerivedFromShiftWithBreakOutsidePremiumTime(): void
    {
        $rules = PayrollSurchargeRuleset::forDate(PayrollRulesetRegistry::defaults(), '2026-07-01');
        $policies = Loader::policyVersions([], [], $rules, [], '2026-07-01', '2026-07-31');
        $work = [...$this->shift(), 'category' => 'regular', 'starts_at_utc' => '2026-07-04 16:00:00', 'ends_at_utc' => '2026-07-05 00:30:00', 'break_minutes' => 30, 'status' => 'approved'];
        $segments = [['date_from' => '2026-07-04', 'date_to' => '2026-07-05', 'worked_duration_units' => 24000, 'overtime_duration_units' => 0]];
        $project = fn(array $entries) => PayrollHourlyNativePremiums::project($entries, $segments, $policies, $rules, '2026-07-01', '2026-07-31', ['dates' => []]);

        $withBreak = $project([$work])[0];
        self::assertSame(13500, $withBreak['night_duration_units']);
        self::assertSame(24000, $withBreak['weekend_duration_units']);
        self::assertTrue($withBreak['night_derived_from_shifts']);
        self::assertTrue($withBreak['weekend_derived_from_shifts']);

        $withoutBreak = $project([[...$work, 'break_minutes' => 0]])[0];
        self::assertSame(13500, $withoutBreak['night_duration_units']);
        self::assertSame(25500, $withoutBreak['weekend_duration_units']);

        // Výslovný překryv noci 22:00 až 02:00 je ruční přebití: platí jeho 4 h.
        $night = [...$work, 'id' => 2, 'category' => 'night', 'starts_at_utc' => '2026-07-04 20:00:00', 'ends_at_utc' => '2026-07-05 00:00:00', 'break_minutes' => 0];
        $overridden = $project([$work, $night])[0];
        self::assertSame(12000, $overridden['night_duration_units']);
        self::assertArrayNotHasKey('night_derived_from_shifts', $overridden);
        self::assertSame(24000, $overridden['weekend_duration_units']);
    }

    public function testMissingDifficultyFactorsBlocksEvidenceInsteadOfThrowingWholeBatch(): void
    {
        $rules = PayrollSurchargeRuleset::forDate(PayrollRulesetRegistry::defaults(), '2026-07-01');
        $policies = Loader::policyVersions([], [], $rules, [], '2026-07-01', '2026-07-31');
        $work = [...$this->shift(), 'category' => 'regular', 'status' => 'approved'];
        $segments = [['date_from' => '2026-07-06', 'date_to' => '2026-07-06', 'worked_duration_units' => 12000, 'overtime_duration_units' => 0]];
        $result = PayrollHourlyNativePremiums::project([$work, [...$work, 'category' => 'difficult_environment']], $segments, $policies, $rules, '2026-07-01', '2026-07-31', ['dates' => ['2026-07-06']]);
        self::assertNull($result[0]['difficult_exposures']);
    }

    public function testStatedWeeklyMinutesDistinguishPartTimeFromStatutorySchedule(): void
    {
        $term = ['effective_from' => '2026-01-01', 'weekly_hours' => '20.00', 'workload_basis_points' => 5000];
        self::assertSame(2400, Loader::statedWeeklyMinutes([$term]));
        self::assertSame(2250, Loader::statedWeeklyMinutes([[...$term, 'weekly_hours' => '37.50', 'workload_basis_points' => 10000]]));
        self::assertNull(Loader::statedWeeklyMinutes([[...$term, 'workload_basis_points' => null]]));
        self::assertNull(Loader::statedWeeklyMinutes([[...$term, 'weekly_hours' => null]]));
    }

    public function testSettlementSourceFreezesOnlyExistingClaimAndNeverInventsWorkedZero(): void
    {
        $state = ['entitlements' => [['id' => 8, 'revision' => 3, 'kind' => 'overtime', 'available_duration_units' => 3000]]];
        $source = Loader::buildSettlementSource(9, '2026-07-01', [], $state);
        self::assertSame('settlement_only', $source['work_source']);
        self::assertArrayNotHasKey('worked_duration_units', $source);
        self::assertArrayNotHasKey('status', $source);
        self::assertSame($source, Loader::buildSettlementSource(9, '2026-07-01', [], $state));
        self::assertNull(Loader::buildSettlementSource(9, '2026-07-01', $this->terms(), $state));
        self::assertNull(Loader::buildSettlementSource(9, '2026-07-01', [], ['entitlements' => []]));
        $state['entitlements'][0]['revision'] = 4;
        self::assertNotSame($source['source_sha256'], Loader::buildSettlementSource(9, '2026-07-01', [], $state)['source_sha256']);
    }

    private function shift(): array { return ['id' => 1, 'revision_no' => 1, 'starts_at_utc' => '2026-07-06 06:00:00', 'ends_at_utc' => '2026-07-06 10:00:00', 'timezone_name' => 'Europe/Prague', 'break_minutes' => 0, 'status' => 'published']; }
    private function source(): array { return ['work_source' => 'manual_summary', 'manual_summary' => ['segments' => [['date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'worked_duration_units' => 0, 'overtime_duration_units' => 0]]]]; }
    private function terms(): array { return [['effective_from' => '2026-07-01', 'effective_to' => null, 'remuneration_type' => 'hourly']]; }
    private function emptyPayload(): array { return ['premium_segments' => [], 'nonworked_holidays' => [], 'holiday_time_off_grants' => [], 'overtime_time_off_allocations' => []]; }
    private function absence(string $type, ?int $minutes = null): array { return ['id' => 7, 'absence_type' => $type, 'compensation_policy' => $type === 'holiday_compensatory_time_off' ? 'hourly_claim' : 'none', 'date_from' => '2026-07-06', 'date_to' => '2026-07-06', 'status' => 'approved', 'correction_pending' => 0, 'partial_first_minutes' => $minutes, 'partial_last_minutes' => null]; }
}
