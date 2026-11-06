<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final readonly class LegacyArrayJ6DecisionProvider implements J6AssessmentDecisionProvider
{
    private J6DecisionContext $context;

    public function __construct(private array $assessment, int $supplierId, int $periodId, string $sourceInputHash)
    {
        $this->context = new J6DecisionContext($supplierId, $periodId, J6ReadMode::CURRENT, $sourceInputHash, 1, 1, 1);
    }

    public function context(): J6DecisionContext { return $this->context; }

    public function header(): array
    {
        return array_diff_key($this->assessment, array_flip(['activities', 'decisions', 'funding_sources', 'funding_costs', 'funding']));
    }

    public function decisionPages(int $limit = 500): iterable
    {
        yield from $this->records((array) ($this->assessment['decisions'] ?? []), $limit,
            static fn (string $id, array $payload): array => ['source_id' => $id, 'payload' => $payload,
                'record_hash' => NonprofitTaxRules::hash(['domain' => 'J6LEGACYDECISION1', 'id' => $id, 'payload' => $payload])]);
    }

    public function activityPages(int $limit = 500): iterable
    {
        yield from $this->records((array) ($this->assessment['activities'] ?? []), $limit,
            static fn (string $id, array $payload): array => ['activity_kind_id' => $id, 'payload' => $payload,
                'record_hash' => NonprofitTaxRules::hash(['domain' => 'J6LEGACYACTIVITY1', 'id' => $id, 'payload' => $payload])]);
    }

    public function fundingPoolPages(int $limit = 500): iterable
    {
        $rows = [];
        foreach (['revenue' => 'funding_sources', 'expense' => 'funding_costs'] as $kind => $field) {
            foreach ((array) ($this->assessment[$field] ?? []) as $pool) {
                $rows[] = ['pool_kind' => $kind, 'anchor' => (string) ($pool['anchor'] ?? ''), 'payload' => $pool,
                    'record_hash' => NonprofitTaxRules::hash(['domain' => 'J6LEGACYPOOL1', 'kind' => $kind, 'payload' => $pool])];
                if (count($rows) === $limit) { yield $rows; $rows = []; }
            }
        }
        if ($rows !== []) yield $rows;
    }

    public function fundingMemberPages(int $limit = 500): iterable
    {
        $rows = [];
        foreach (['revenue' => 'funding_sources', 'expense' => 'funding_costs'] as $kind => $field) {
            foreach ((array) ($this->assessment[$field] ?? []) as $pool) foreach ((array) ($pool['members'] ?? []) as $member) {
                $rows[] = ['pool_kind' => $kind, 'anchor' => (string) ($pool['anchor'] ?? ''), 'member_id' => (string) $member];
                if (count($rows) === $limit) { yield $rows; $rows = []; }
            }
        }
        if ($rows !== []) yield $rows;
    }

    public function fundingLinkPages(int $limit = 500): iterable
    {
        $rows = [];
        foreach ((array) ($this->assessment['funding'] ?? []) as $link) {
            $rows[] = ['source_id' => (string) ($link['source_id'] ?? ''), 'cost_id' => (string) ($link['cost_id'] ?? ''),
                'payload' => $link, 'record_hash' => NonprofitTaxRules::hash(['domain' => 'J6LEGACYLINK1', 'payload' => $link])];
            if (count($rows) === $limit) { yield $rows; $rows = []; }
        }
        if ($rows !== []) yield $rows;
    }

    private function records(array $values, int $limit, \Closure $map): iterable
    {
        if ($limit < 1 || $limit > 500) throw new \InvalidArgumentException('Legacy decision page is invalid.');
        $page = [];
        foreach ($values as $id => $payload) {
            if (!is_array($payload)) NonprofitTaxRules::invalid('Legacy decision record is invalid.');
            $page[] = $map((string) $id, $payload);
            if (count($page) === $limit) { yield $page; $page = []; }
        }
        if ($page !== []) yield $page;
    }
}
