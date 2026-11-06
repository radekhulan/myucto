<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Hourly;

use MyInvoice\Service\Payroll\Absence\AverageEarningResult;
use MyInvoice\Service\Payroll\PayrollHourlyAverageFloorResolver as Resolver;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry;
use PHPUnit\Framework\TestCase;

final class PayrollHourlyAverageFloorResolverTest extends TestCase
{
    public function testLegacyResultAndSuggestionRemainIdentical(): void
    {
        Resolver::assertFloorKnown([], '2026-07-01', 13440);
        $suggestion = ['ready' => true, 'input_version' => str_repeat('a', 64)];
        self::assertSame($suggestion, Resolver::applyToSuggestion($suggestion, [], '2026-07-01'));
        self::assertSame(['hourly_minor' => 13440, 'hourly_proof' => null], Resolver::apply([$this->floor()], '2026-06-30', 13440));
    }

    public function testApprovedQuarterAverageIsRaisedAgainAtActualApplicationDate(): void
    {
        $rows = [[...$this->floor(), 'date_to' => '2026-07-15', 'minimum_hourly_minor' => 13440], [...$this->floor(), 'date_from' => '2026-07-16']];
        self::assertSame(13440, Resolver::apply($rows, '2026-07-15', 13440)['hourly_minor']);
        $later = Resolver::apply($rows, '2026-07-16', 13440);
        self::assertSame(14340, $later['hourly_minor']);
        self::assertSame(13440, $later['hourly_proof']['raw_average_hourly_minor']);
        self::assertSame('2026-07-16', $later['hourly_proof']['application_date']);
        self::assertSame(20000, Resolver::apply($rows, '2026-07-16', 20000)['hourly_minor']);
        Resolver::assertFloorKnown($rows, '2026-07-16', 13440);
        $this->expectException(\InvalidArgumentException::class);
        Resolver::assertFloorKnown([[...$this->floor(), 'minimum_hourly_minor' => null]], '2026-07-16', 13440);
    }

    public function testMissingOrOverlappingHourlyProofFailsClosed(): void
    {
        foreach ([[$this->floor(), $this->floor()], [[...$this->floor(), 'minimum_hourly_minor' => null]], [[...$this->floor(), 'weekly_hours_conflict' => true]]] as $rows) {
            try {
                Resolver::apply($rows, '2026-07-15', 13440);
                self::fail('Neúplná nebo rozporná hodinová spodní mez prošla.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('stanovenou týdenní dobu', $e->getMessage());
            }
        }
    }

    public function testHourlyProbableProposalKeepsRawEarningAndIncludesFloorInOptimisticVersion(): void
    {
        $proposal = ['ready' => true, 'blockers' => [], 'input_version' => str_repeat('a', 64), 'probable_hourly_minor' => 13000, 'probable_rationale' => 'Syntetický podklad'];
        $raised = Resolver::applyToSuggestion($proposal, [$this->floor()], '2026-07-01');
        // Minimum se přidává až ke dni použití průměru, schválený údaj zůstává surový.
        self::assertSame(13000, $raised['probable_hourly_minor']);
        self::assertSame('Syntetický podklad', $raised['probable_rationale']);
        self::assertNotSame($proposal['input_version'], $raised['input_version']);
        $blocked = Resolver::applyToSuggestion($proposal, [[...$this->floor(), 'minimum_hourly_minor' => null]], '2026-07-01');
        self::assertFalse($blocked['ready']);
        self::assertContains('hourly_average_minimum_missing', $blocked['blockers']);
        self::assertNotSame($raised['input_version'], $blocked['input_version']);
    }

    public function testSharedFloorUsesStatutoryWeekAndAgreementDefault(): void
    {
        $provider = PayrollRulesetRegistry::defaults();
        $term = ['id' => 1, 'row_version' => 2, 'effective_from' => '2026-01-01', 'effective_to' => null,
            'remuneration_type' => 'hourly', 'weekly_hours' => '37.50', 'workload_basis_points' => 10000];
        $hpp = Resolver::segments([$term], [], '2026-07-01', '2026-07-31', 'employment', $provider);
        self::assertSame(14340, $hpp[0]['minimum_hourly_minor']);
        self::assertSame(2, $hpp[0]['term_row_version']);
        $part = Resolver::segments([[...$term, 'weekly_hours' => '20.00', 'workload_basis_points' => 5000]], [], '2026-07-01', '2026-07-31', 'employment', $provider);
        self::assertSame(13440, $part[0]['minimum_hourly_minor']);
        $dpp = Resolver::segments([[...$term, 'weekly_hours' => '10.00']], [], '2026-07-01', '2026-07-31', 'dpp', $provider);
        self::assertSame(13440, $dpp[0]['minimum_hourly_minor']);
    }

    public function testShorterContractWithDefaultWorkloadDoesNotRaiseMinimum(): void
    {
        $provider = PayrollRulesetRegistry::defaults();
        $term = ['id' => 1, 'row_version' => 1, 'effective_from' => '2026-01-01', 'effective_to' => null,
            'remuneration_type' => 'hourly', 'weekly_hours' => '20.00', 'workload_basis_points' => 10000];
        $rows = Resolver::segments([$term], [], '2026-07-01', '2026-07-31', 'employment', $provider);
        self::assertSame(13440, $rows[0]['minimum_hourly_minor']);
        self::assertSame(2400, $rows[0]['stated_weekly_minutes']);
        self::assertFalse($rows[0]['weekly_hours_conflict']);
        $policy = ['valid_from' => '2026-01-01', 'valid_to' => null, 'hourly_terms' => ['minimum_wage' => [
            'assessment' => 'hourly', 'statutory_weekly_minutes' => 2250, 'statutory_weekly_reference' => 'Syntetický rozvrh', 'advance_proof' => null]]];
        $documented = Resolver::segments([$term], [$policy], '2026-07-01', '2026-07-31', 'employment', $provider);
        self::assertSame(14340, $documented[0]['minimum_hourly_minor']);
        self::assertFalse($documented[0]['weekly_hours_conflict']);
    }

    public function testRuntimeDateRangeIsPartitionedAtRulesetYearBoundary(): void
    {
        $term = ['id' => 1, 'row_version' => 1, 'effective_from' => '2025-01-01', 'effective_to' => null,
            'remuneration_type' => 'hourly', 'weekly_hours' => '40.00', 'workload_basis_points' => 10000];
        $rows = Resolver::segments([$term], [], '2025-12-31', '2026-01-01', 'employment', PayrollRulesetRegistry::defaults());
        self::assertCount(2, $rows);
        self::assertSame('2025-12-31', $rows[0]['date_to']);
        self::assertSame('2026-01-01', $rows[1]['date_from']);
        self::assertSame(13440, Resolver::apply($rows, '2026-01-01', 13000)['hourly_minor']);
        self::assertSame(13000, Resolver::apply($rows, '2025-12-31', 13000)['hourly_minor']);
    }

    private function floor(): array
    {
        return ['date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'minimum_hourly_minor' => 14340,
            'term_id' => 1, 'term_row_version' => 1, 'stated_weekly_minutes' => 2250, 'ruleset_id' => 'synthetic', 'weekly_hours_conflict' => false];
    }
}
