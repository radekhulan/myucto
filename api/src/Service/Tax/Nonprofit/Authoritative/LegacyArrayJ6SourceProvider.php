<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotCursor;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotHeaderV2;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotPageV2;

final readonly class LegacyArrayJ6SourceProvider implements J6AuthoritativeSourceProvider
{
    private SnapshotHeaderV2 $header;
    private J6ReadContext $context;
    private string $inputHash;

    public function __construct(private array $snapshot)
    {
        $supplierId = (int) ($snapshot['supplier_id'] ?? 1);
        $periodId = (int) ($snapshot['period_id'] ?? $snapshot['period']['id'] ?? 1);
        $data = ['schema' => SnapshotHeaderV2::SCHEMA, 'scope' => 'current-period-source',
            'supplier_id' => $supplierId, 'period_id' => $periodId, 'period' => (array) ($snapshot['period'] ?? []),
            'frameworks' => (array) ($snapshot['frameworks'] ?? ['cz_504']),
            'ruleset' => (string) ($snapshot['ruleset'] ?? NonprofitTaxRules::VERSION),
            'source_fact_schemas' => (array) ($snapshot['source_fact_schemas'] ?? [])];
        foreach (['legal_profile', 'svj_module_proof'] as $field) if (array_key_exists($field, $snapshot)) $data[$field] = $snapshot[$field];
        $this->header = new SnapshotHeaderV2($data);
        $this->context = new J6ReadContext($supplierId, $periodId, J6ReadMode::CURRENT);
        $hasher = new NonprofitTaxSnapshotHasher();
        $root = $hasher->startRoot();
        $ordinal = $rows = 0;
        foreach (array_chunk(array_values((array) ($snapshot['sources'] ?? [])), 500) as $pageRows) {
            $page = new SnapshotPageV2($pageRows, $ordinal === 0 ? (array) ($snapshot['findings'] ?? []) : [], null, null, null, count($pageRows));
            $chunk = $hasher->chunk($ordinal, $page);
            $hasher->updateRoot($root, $chunk['descriptor']);
            $rows += count($pageRows);
            ++$ordinal;
        }
        $this->inputHash = $hasher->input($this->header, $hasher->header($this->header),
            $hasher->finishRoot($root, $ordinal), $rows, count((array) ($snapshot['findings'] ?? [])), $ordinal);
    }

    public function context(): J6ReadContext { return $this->context; }

    public function header(): SnapshotHeaderV2 { return $this->header; }

    public function inputHash(): string { return $this->inputHash; }

    public function pages(?SnapshotCursor $after = null, int $limit = 500): iterable
    {
        if ($after !== null || $limit < 1 || $limit > 500) throw new \InvalidArgumentException('Legacy array source page is invalid.');
        $findings = (array) ($this->snapshot['findings'] ?? []);
        foreach (array_chunk(array_values((array) ($this->snapshot['sources'] ?? [])), $limit) as $ordinal => $rows) {
            yield new SnapshotPageV2($rows, $ordinal === 0 ? $findings : [], null, null, null, count($rows));
        }
    }

    public function fundingReferencePages(int $limit = 500): iterable
    {
        if ($limit < 1 || $limit > 500) throw new \InvalidArgumentException('Legacy funding reference page is invalid.');
        foreach (array_chunk(array_values((array) ($this->snapshot['funding_reference_sources'] ?? [])), $limit) as $page) yield $page;
    }

    public function fundingHistoryPages(int $limit = 500): iterable
    {
        if ($limit < 1 || $limit > 500) throw new \InvalidArgumentException('Legacy funding history page is invalid.');
        $page = [];
        foreach ((array) ($this->snapshot['funding_history'] ?? []) as $revisionIndex => $revision) {
            foreach ((array) ($revision['usage'] ?? []) as $usage) {
                $members = array_values((array) ($usage['members'] ?? []));
                foreach ($members as $member) {
                    $page[] = ['assessment_id' => (int) ($revision['assessment_id'] ?? $revisionIndex + 1),
                        'period_id' => (int) ($revision['period_id'] ?? $revisionIndex + 1),
                        'status' => (string) ($revision['status'] ?? ''), 'current' => ($revision['current'] ?? false) === true,
                        'anchor' => (string) ($usage['anchor'] ?? ''), 'member_id' => (string) $member,
                        'net_source_cents' => (int) ($usage['net_source_cents'] ?? 0),
                        'prior_drawdown_cents' => (int) ($usage['prior_drawdown_cents'] ?? 0),
                        'drawdown_cents' => (int) ($usage['drawdown_cents'] ?? 0),
                        'remaining_cents' => (int) ($usage['remaining_cents'] ?? 0)];
                    if (count($page) === $limit) { yield $page; $page = []; }
                }
            }
        }
        if ($page !== []) yield $page;
    }
}
