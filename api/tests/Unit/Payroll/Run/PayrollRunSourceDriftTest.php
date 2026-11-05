<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunSourceDrift;
use PHPUnit\Framework\TestCase;

final class PayrollRunSourceDriftTest extends TestCase
{
    public function testManualTimeRevisionChangesAreDetectedWithoutJmhz(): void
    {
        $frozen = ['people' => [['employee' => ['id' => 1], 'employments' => [[
            'employment' => ['id' => 2],
            'worked_time_evidence' => ['work_source' => 'manual_summary', 'revision_no' => 1, 'source_sha256' => 'before'],
        ]]]]];
        $current = ['eligible_employment_ids' => [2], 'inputs' => [], 'absences' => [], 'statutory_evidence' => null,
            'worked_time_evidence' => [2 => ['work_source' => 'manual_summary', 'revision_no' => 2, 'source_sha256' => 'after']]];
        $drift = PayrollRunSourceDrift::between($frozen, $current);
        self::assertSame(1, $drift->total());
        self::assertSame(1, $drift->toArray()['worked_time_changed']);
        $current['worked_time_evidence'][2] = $frozen['people'][0]['employments'][0]['worked_time_evidence'];
        self::assertFalse(PayrollRunSourceDrift::between($frozen, $current)->hasChanges());
        $current['worked_time_evidence'] = [];
        self::assertSame(1, PayrollRunSourceDrift::between($frozen, $current)->workedTimeChanged, 'Opuštění hodinového zdroje nesmí skrýt změnu.');
    }

    public function testMonthlyManualOptInUsesExistingTimeMonthWithoutChangingLegacyDefaults(): void
    {
        $month = ['id' => 9, 'status' => 'approved', 'revision_no' => 1, 'row_version' => 3, 'jmhz_work_summary' => null];
        $frozen = ['people' => [['employee' => ['id' => 1], 'employments' => [[
            'employment' => ['id' => 2], 'time_month' => $month,
        ]]]]];
        $current = ['eligible_employment_ids' => [2], 'inputs' => [], 'absences' => [], 'statutory_evidence' => null,
            'worked_time_evidence' => [], 'manual_time_month_state' => []];
        self::assertFalse(PayrollRunSourceDrift::between($frozen, $current)->hasChanges());
        $state = ['id' => 9, 'status' => 'approved', 'revision_no' => 1, 'row_version' => 3, 'summary_sha256' => null];
        $current['manual_time_month_state'][2] = $state;
        self::assertFalse(PayrollRunSourceDrift::between($frozen, $current)->hasChanges());
        foreach (['revision_no' => 2, 'row_version' => 4, 'status' => 'open', 'summary_sha256' => 'changed'] as $key => $value) {
            $current['manual_time_month_state'][2] = [...$state, $key => $value];
            self::assertSame(1, PayrollRunSourceDrift::between($frozen, $current)->workedTimeChanged);
        }
        $frozen['people'][0]['employments'][0]['time_month'] = null;
        self::assertSame(1, PayrollRunSourceDrift::between($frozen, $current)->workedTimeChanged, 'Ruční zdroj založený po zmrazení nesmí projít.');
        $frozen['people'][0]['employments'][0]['worked_time_evidence'] = ['source_sha256' => 'before'];
        $current['worked_time_evidence'][2] = ['source_sha256' => 'after'];
        self::assertSame(1, PayrollRunSourceDrift::between($frozen, $current)->workedTimeChanged, 'Hodinový podklad se započte právě jednou.');
    }

    public function testHourlyTermsAddedAfterLockPreventApprovalOfOldMonthlySnapshot(): void
    {
        $current = $this->current();
        $current['hourly_remuneration_terms'] = [7 => [[
            'id' => 21, 'effective_from' => '2026-06-01', 'effective_to' => '2026-06-15',
            'hourly_rate_minor' => 20_000, 'remuneration_basis' => 'Syntetický výměr',
        ]]];
        $drift = PayrollRunSourceDrift::between($this->frozen(), $current);
        self::assertSame(1, $drift->total());
        self::assertSame(1, $drift->remunerationChanged);

        $frozen = $this->frozen();
        $frozen['people'][0]['employments'][0]['hourly_remuneration_terms'] = $current['hourly_remuneration_terms'][7];
        self::assertFalse(PayrollRunSourceDrift::between($frozen, $current)->hasChanges());
        $current['hourly_remuneration_terms'][7][0]['hourly_rate_minor'] = 21_000;
        self::assertSame(1, PayrollRunSourceDrift::between($frozen, $current)->remunerationChanged);
    }

    public function testIdenticalSourcesReportNoChange(): void
    {
        $drift = PayrollRunSourceDrift::between(
            $this->frozen(),
            $this->current(),
        );

        self::assertFalse($drift->hasChanges());
        self::assertSame(0, $drift->toArray()['total']);
    }

    public function testCountsEveryKindOfChange(): void
    {
        $current = $this->current();
        $current['eligible_employment_ids'] = [7, 8];
        $current['inputs'] = [
            7 => [
                ['id' => 1, 'amount_minor' => 125_000],
                ['id' => 2, 'amount_minor' => 10_000],
            ],
            8 => [],
        ];
        $current['absences'] = [7 => [], 8 => []];
        $current['statutory_evidence'] = [10 => ['income_tax' => ['declaration' => 'signed']]];

        $drift = PayrollRunSourceDrift::between($this->frozen(), $current)->toArray();

        self::assertSame(1, $drift['inputs_added']);
        self::assertSame(1, $drift['inputs_changed']);
        self::assertSame(0, $drift['inputs_removed']);
        self::assertSame(1, $drift['absences_removed']);
        self::assertSame(1, $drift['employments_added']);
        self::assertSame(0, $drift['employments_removed']);
        self::assertSame(1, $drift['statutory_evidence_changed']);
        self::assertSame(5, $drift['total']);
    }

    /** Bez repozitáře evidence (volitelná závislost) se evidence neporovnává. */
    public function testMissingEvidenceSourceIsNotReportedAsChange(): void
    {
        $current = $this->current();
        $current['statutory_evidence'] = null;

        self::assertFalse(PayrollRunSourceDrift::between($this->frozen(), $current)->hasChanges());
    }

    public function testCancelledInputAndEndedEmploymentAreReported(): void
    {
        $current = $this->current();
        $current['eligible_employment_ids'] = [];
        $current['inputs'] = [7 => []];

        $drift = PayrollRunSourceDrift::between($this->frozen(), $current);

        self::assertSame(1, $drift->inputsRemoved);
        self::assertSame(1, $drift->employmentsRemoved);
    }

    /** @return array<string,mixed> */
    private function frozen(): array
    {
        return [
            'period_start' => '2026-06-01',
            'payment_date' => '2026-07-15',
            'office_id' => null,
            'people' => [[
                'employee' => ['id' => 10],
                'statutory_evidence' => ['income_tax' => ['declaration' => 'not-signed']],
                'employments' => [[
                    'employment' => ['id' => 7],
                    'inputs' => [['id' => 1, 'amount_minor' => 120_000]],
                    'absences' => [['id' => 5, 'absence_type' => 'vacation']],
                ]],
            ]],
        ];
    }

    /**
     * @return array{
     *   eligible_employment_ids:list<int>,
     *   inputs:array<int,list<array<string,mixed>>>,
     *   absences:array<int,list<array<string,mixed>>>,
     *   statutory_evidence:array<int,mixed>|null
     * }
     */
    private function current(): array
    {
        return [
            'eligible_employment_ids' => [7],
            'inputs' => [7 => [['id' => 1, 'amount_minor' => 120_000]]],
            'absences' => [7 => [['id' => 5, 'absence_type' => 'vacation']]],
            'statutory_evidence' => [10 => ['income_tax' => ['declaration' => 'not-signed']]],
        ];
    }
}
