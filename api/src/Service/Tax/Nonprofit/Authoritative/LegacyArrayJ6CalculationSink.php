<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final class LegacyArrayJ6CalculationSink implements J6CalculationSink, J6LegacyProjectionSink
{
    private ?J6CalculationHeader $header = null;
    private array $decisions = [];
    private array $results = [];
    private array $coverage = [];
    private array $usage = [];
    private array $activities = [];
    private array $externalAdjustments = [];
    private array $references = [];
    private array $history = [];
    private ?array $result = null;

    public function __construct(
        private readonly array $assessment,
        private readonly array $sourceOrder,
        private readonly array $groupOrder,
        private readonly array $fundingPoolOrder,
    ) {}

    public function begin(J6CalculationHeader $header): void { $this->header = $header; }

    public function appendDecisionPage(array $rows): void { array_push($this->decisions, ...$rows); }

    public function appendResultPage(array $rows): void { array_push($this->results, ...$rows); }

    public function appendCoveragePage(array $rows): void { array_push($this->coverage, ...$rows); }

    public function appendFundingUsagePage(array $rows): void { array_push($this->usage, ...$rows); }

    public function appendFundingReferencePage(array $rows): void { array_push($this->references, ...$rows); }

    public function appendFundingHistoryPage(array $rows): void { array_push($this->history, ...$rows); }

    public function appendLegacyActivityPage(array $rows): void { array_push($this->activities, ...$rows); }

    public function appendLegacyExternalAdjustmentPage(array $rows): void { array_push($this->externalAdjustments, ...$rows); }

    public function complete(array $summary): J6CalculationReceipt
    {
        if (!$this->header instanceof J6CalculationHeader) throw new \LogicException('Legacy J6 sink was not started.');
        $rows = [];
        foreach ($this->results as $row) {
            unset($row['result_row_hash']);
            $rows[(string) ($row['id'] ?? '')] = $row;
        }
        $orderedRows = [];
        foreach ($this->sourceOrder as $id) if (isset($rows[$id])) { $orderedRows[] = $rows[$id]; unset($rows[$id]); }
        foreach ($rows as $row) $orderedRows[] = $row;
        $activities = [];
        foreach ($this->activities as $row) {
            $group = (string) ($row['group'] ?? '');
            unset($row['group']);
            $activities[$group] = $row;
        }
        $orderedActivities = [];
        foreach ($this->groupOrder as $group) if (isset($activities[$group])) { $orderedActivities[] = $activities[$group]; unset($activities[$group]); }
        foreach ($activities as $row) $orderedActivities[] = $row;
        $usage = [];
        foreach ($this->usage as $row) {
            $anchor = (string) ($row['anchor'] ?? '');
            $usage[$anchor] ??= ['anchor' => $anchor, 'members' => [],
                'net_source_cents' => (int) ($row['net_source_cents'] ?? 0),
                'prior_drawdown_cents' => (int) ($row['prior_drawdown_cents'] ?? 0),
                'drawdown_cents' => (int) ($row['drawdown_cents'] ?? 0),
                'remaining_cents' => (int) ($row['remaining_cents'] ?? 0)];
            $usage[$anchor]['members'][] = (string) ($row['member_id'] ?? '');
        }
        $orderedUsage = [];
        foreach ($this->fundingPoolOrder as $anchor) if (isset($usage[$anchor])) { $orderedUsage[] = $usage[$anchor]; unset($usage[$anchor]); }
        foreach ($usage as $row) $orderedUsage[] = $row;
        $this->result = ['ruleset' => $summary['ruleset'], 'profile' => $summary['profile'],
            'assessment_method' => $summary['assessment_method'],
            'accounting_result_cents' => $summary['accounting_result_cents'],
            'excluded_revenue_cents' => $summary['excluded_revenue_cents'],
            'cost_addbacks_cents' => $summary['cost_addbacks_cents'],
            'assessed_result_cents' => $summary['assessed_result_cents'], 'scope' => $summary['scope'],
            'dppo_supported' => $summary['dppo_supported'], 'activities' => $orderedActivities, 'rows' => $orderedRows,
            'funding' => array_values((array) ($this->assessment['funding'] ?? [])), 'funding_usage' => $orderedUsage,
            'external_adjustments' => $this->orderedExternalAdjustments()];
        $counts = ['decision' => count($this->decisions), 'result' => count($this->results),
            'coverage' => count($this->coverage), 'usage' => count($this->usage)];
        $roots = [];
        foreach (['decision' => $this->decisions, 'result' => $this->results, 'coverage' => $this->coverage, 'usage' => $this->usage] as $kind => $values) {
            $roots[$kind] = NonprofitTaxRules::hash(['domain' => 'J6LEGACYSINK1.' . $kind, 'rows' => $values]);
        }
        $evidenceRoots = ['funding_reference' => NonprofitTaxRules::hash(['domain' => 'J6LEGACYSINK1.reference', 'rows' => $this->references]),
            'funding_history' => NonprofitTaxRules::hash(['domain' => 'J6LEGACYSINK1.history', 'rows' => $this->history])];
        return new J6CalculationReceipt($this->header, [], $roots, $counts, $summary, null, [], $evidenceRoots,
            ['funding_reference' => count($this->references), 'funding_history' => count($this->history)]);
    }

    public function abort(): void
    {
        $this->result = null;
    }

    public function result(): array
    {
        if ($this->result === null) throw new \LogicException('Legacy J6 result is incomplete.');
        return $this->result;
    }

    private function orderedExternalAdjustments(): array
    {
        $rows = [];
        foreach ($this->externalAdjustments as $row) $rows[(string) ($row['funding_anchor'] ?? '')] = $row;
        $ordered = [];
        foreach ($this->fundingPoolOrder as $anchor) if (isset($rows[$anchor])) { $ordered[] = $rows[$anchor]; unset($rows[$anchor]); }
        foreach ($rows as $row) $ordered[] = $row;
        return $ordered;
    }
}
