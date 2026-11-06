<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Tests\Support\J6CalculatorFactory;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDecisionProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AuthoritativeSourceProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationHeader;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationReceipt;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationSink;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6DraftContext;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6DecisionContext;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadContext;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadMode;
use MyInvoice\Service\Tax\Nonprofit\J6AuthoritativeCalculator;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxCalculator;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotCursor;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotHeaderV2;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotPageV2;
use PHPUnit\Framework\TestCase;

final class J6AuthoritativePagedCalculatorTest extends TestCase
{
    public function testPagedCalculatorMatchesArraySsotForPendingActivityAndSubjectRows(): void
    {
        $sources = [
            $this->source('line:3', 'expense', 15000, 1),
            $this->source('line:1', 'revenue', 10000, 1),
            $this->source('line:4', 'expense', 5000, 2),
            $this->source('line:2', 'revenue', 10000, 2),
            $this->source('line:5', 'revenue', 7000, 3),
        ];
        $activities = [
            '1' => ['evidence' => 'Syntetická činnost 1'],
            '2' => ['evidence' => 'Syntetická činnost 2'],
            '3' => ['evidence' => 'Syntetická činnost 3'],
        ];
        $decisions = [
            'line:1' => $this->decision('nonbusiness', 10000),
            'line:2' => $this->decision('nonbusiness', 10000),
            'line:3' => $this->decision('nonbusiness_cost', 15000),
            'line:4' => $this->decision('nonbusiness_cost', 5000),
            'line:5' => $this->decision('rent', 7000),
        ];
        $assessment = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy'],
            'activities' => $activities, 'decisions' => $decisions, 'funding' => []];
        $legacy = J6CalculatorFactory::legacy()->calculate(['period' => $this->period(), 'sources' => $sources], $assessment);
        $sink = $this->sink();
        $receipt = J6CalculatorFactory::authoritative()->calculate($this->sourceProvider($sources),
            $this->decisionProvider($assessment, $sources), $sink);

        self::assertSame($legacy['accounting_result_cents'], $receipt->summary['accounting_result_cents']);
        self::assertSame($legacy['excluded_revenue_cents'], $receipt->summary['excluded_revenue_cents']);
        self::assertSame($legacy['cost_addbacks_cents'], $receipt->summary['cost_addbacks_cents']);
        self::assertSame($legacy['assessed_result_cents'], $receipt->summary['assessed_result_cents']);
        $legacyRows = array_column($legacy['rows'], null, 'id');
        $pagedRows = array_column($sink->results, null, 'id');
        foreach ($pagedRows as &$row) unset($row['result_row_hash']);
        unset($row);
        ksort($legacyRows, SORT_STRING);
        ksort($pagedRows, SORT_STRING);
        self::assertSame(NonprofitTaxRules::canonicalJson($legacyRows), NonprofitTaxRules::canonicalJson($pagedRows));
        self::assertCount(5, $sink->coverage);
        self::assertSame(8, $receipt->counts['decision']);
    }

    public function testExtraDecisionFailsClosed(): void
    {
        $sources = [$this->source('line:1', 'revenue', 10000, 1)];
        $assessment = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy'],
            'activities' => ['1' => ['evidence' => 'Syntetická činnost']],
            'decisions' => ['line:1' => $this->decision('rent', 10000), 'line:2' => $this->decision('rent', 10000)],
            'funding' => []];
        try {
            J6CalculatorFactory::authoritative()->calculate($this->sourceProvider($sources), $this->decisionProvider($assessment, $sources), $this->sink());
            self::fail('Extra decision must fail.');
        } catch (NonprofitTaxAssessmentException $failure) {
            self::assertSame('nonprofit_tax_facts_incomplete', $failure->errorCode);
        }

    }

    public function testPagedFundingMatchesArraySsotAndEmitsBoundUsage(): void
    {
        $sources = [
            $this->source('line:1', 'revenue', 10000, 1),
            $this->source('line:2', 'expense', 6000, 1),
        ];
        $assessment = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy'],
            'activities' => ['1' => ['evidence' => 'Syntetická činnost']],
            'decisions' => [
                'line:1' => $this->decision('gift', 10000) + ['facts' => [
                    'claim_exemption' => true, 'qualifying_purpose' => true,
                    'election_evidence' => 'Syntetická volba osvobození',
                ]],
                'line:2' => $this->decision('cost', 6000),
            ],
            'funding_sources' => [['anchor' => 'line:1', 'members' => ['line:1'], 'evidence' => 'Syntetický fond']],
            'funding_costs' => [],
            'funding' => [['source_id' => 'line:1', 'cost_id' => 'line:2', 'amount_cents' => 6000,
                'evidence' => 'Syntetické čerpání']],
        ];
        $legacy = J6CalculatorFactory::legacy()->calculate(['period' => $this->period(), 'sources' => $sources], $assessment);
        $sink = $this->sink();
        $receipt = J6CalculatorFactory::authoritative()->calculate($this->sourceProvider($sources),
            $this->decisionProvider($assessment, $sources), $sink);

        foreach (['accounting_result_cents', 'excluded_revenue_cents', 'cost_addbacks_cents', 'assessed_result_cents'] as $field) {
            self::assertSame($legacy[$field], $receipt->summary[$field], $field);
        }
        $legacyRows = array_column($legacy['rows'], null, 'id');
        $pagedRows = array_column($sink->results, null, 'id');
        foreach ($pagedRows as &$row) unset($row['result_row_hash']);
        unset($row);
        ksort($legacyRows, SORT_STRING);
        ksort($pagedRows, SORT_STRING);
        self::assertSame(NonprofitTaxRules::canonicalJson($legacyRows), NonprofitTaxRules::canonicalJson($pagedRows));
        self::assertSame(6, $receipt->counts['decision']);
        self::assertSame(1, $receipt->counts['usage']);
        self::assertSame(['anchor' => 'line:1', 'member_id' => 'line:1', 'net_source_cents' => 10000,
            'prior_drawdown_cents' => 0, 'drawdown_cents' => 6000, 'remaining_cents' => 4000],
            array_diff_key($sink->usage[0], ['usage_record_hash' => true]));
    }

    public function testCircularFundingAcceptsTheSameUniqueClassificationAsArraySsot(): void
    {
        $sources = [
            $this->source('line:1', 'revenue', 10000, 1),
            $this->source('line:2', 'revenue', 10000, 1),
            $this->source('line:3', 'expense', 15000, 1),
        ];
        $assessment = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy'],
            'activities' => ['1' => ['evidence' => 'Syntetická činnost']],
            'decisions' => [
                'line:1' => $this->pendingGiftDecision(),
                'line:2' => $this->decision('public_operating_grant', 10000) + ['facts' => ['public_budget' => true]],
                'line:3' => $this->decision('nonbusiness_cost', 15000),
            ],
            'funding' => [
                ['source_id' => 'line:2', 'cost_id' => 'line:3', 'amount_cents' => 10000, 'evidence' => 'Syntetická dotace'],
                ['source_id' => 'line:1', 'cost_id' => 'line:3', 'amount_cents' => 5000, 'evidence' => 'Syntetický dar'],
            ],
        ];
        $legacy = J6CalculatorFactory::legacy()->calculate(['period' => $this->period(), 'sources' => $sources], $assessment);
        $sink = $this->sink();
        $receipt = J6CalculatorFactory::authoritative()->calculate($this->sourceProvider($sources),
            $this->decisionProvider($assessment, $sources), $sink);

        self::assertSame($legacy['assessed_result_cents'], $receipt->summary['assessed_result_cents']);
        $rows = array_column($sink->results, null, 'id');
        self::assertSame('subject', $rows['line:1']['classification']['regime']);
        self::assertSame(5000, $receipt->summary['assessed_result_cents']);
    }

    public function testCircularFundingWithTwoConsistentClassificationsStillRequiresReview(): void
    {
        $sources = [
            $this->source('line:1', 'revenue', 10000, 1),
            $this->source('line:2', 'expense', 15000, 1),
            $this->source('line:3', 'revenue', 10000, 2),
            $this->source('line:4', 'expense', 15000, 2),
        ];
        $assessment = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy'],
            'activities' => ['1' => ['evidence' => 'Syntetická činnost 1'], '2' => ['evidence' => 'Syntetická činnost 2']],
            'decisions' => ['line:1' => $this->pendingGiftDecision(), 'line:2' => $this->decision('nonbusiness_cost', 15000),
                'line:3' => $this->pendingGiftDecision(), 'line:4' => $this->decision('nonbusiness_cost', 15000)],
            'funding' => [
                ['source_id' => 'line:1', 'cost_id' => 'line:4', 'amount_cents' => 10000, 'evidence' => 'Syntetické první čerpání'],
                ['source_id' => 'line:3', 'cost_id' => 'line:2', 'amount_cents' => 10000, 'evidence' => 'Syntetické druhé čerpání'],
            ],
        ];

        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->expectExceptionMessage('Kruhová závislost');
        J6CalculatorFactory::authoritative()->calculate($this->sourceProvider($sources),
            $this->decisionProvider($assessment, $sources), $this->sink());
    }

    public function testChangedFinancialSourceRejectsTheBoundDraftBeforePublication(): void
    {
        $original = [$this->source('line:1', 'revenue', 10000, 1)];
        $changed = $original;
        $changed[0]['amount_cents'] = 10001;
        $assessment = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy'],
            'activities' => ['1' => ['evidence' => 'Syntetická činnost']],
            'decisions' => ['line:1' => $this->decision('rent', 10001)], 'funding' => []];

        try {
            J6CalculatorFactory::authoritative()->calculate($this->sourceProvider($changed),
                $this->decisionProvider($assessment, $original), $this->sink());
            self::fail('Changed source must reject the bound draft.');
        } catch (NonprofitTaxAssessmentException $failure) {
            self::assertSame('nonprofit_tax_draft_changed', $failure->errorCode);
            self::assertSame(409, $failure->httpStatus);
        }
    }

    private function source(string $id, string $kind, int $amount, int $activity): array
    {
        return ['id' => $id, 'line_id' => (int) substr($id, 5), 'kind' => $kind, 'amount_cents' => $amount,
            'activity' => 'main', 'activity_kind_id' => $activity, 'tax_regime' => 'subject',
            'mandatory_non_deductible' => false, 'module_facts' => []];
    }

    private function decision(string $title, int $amount): array
    {
        return ['title' => $title, 'evidence' => 'Syntetický doklad', 'deductible_cents' => $amount, 'legal_basis' => 'zdp_24_1'];
    }

    private function pendingGiftDecision(): array
    {
        return $this->decision('gift', 10000) + ['facts' => ['claim_exemption' => false,
            'income_treatment' => 'nonbusiness_activity',
            'income_attribution_evidence' => 'Syntetické přiřazení původní činnosti']];
    }

    private function period(): array
    {
        return ['id' => 13, 'fiscal_year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'];
    }

    private function sourceProvider(array $rows): J6AuthoritativeSourceProvider
    {
        return new class($rows, $this->period()) implements J6AuthoritativeSourceProvider {
            public function __construct(private array $rows, private array $period) {}
            public function context(): J6ReadContext { return new J6ReadContext(11, 13, J6ReadMode::CURRENT); }
            public function header(): SnapshotHeaderV2
            {
                return new SnapshotHeaderV2(['schema' => SnapshotHeaderV2::SCHEMA, 'scope' => 'current-period-source',
                    'supplier_id' => 11, 'period_id' => 13, 'period' => $this->period, 'frameworks' => ['cz_504'],
                    'ruleset' => NonprofitTaxRules::VERSION, 'source_fact_schemas' => []]);
            }
            public function pages(?SnapshotCursor $after = null, int $limit = 500): iterable
            {
                foreach (array_chunk($this->rows, $limit) as $rows) yield new SnapshotPageV2($rows, [], null, null, null, count($rows));
            }
            public function fundingReferencePages(int $limit = 500): iterable { yield []; }
            public function fundingHistoryPages(int $limit = 500): iterable { yield []; }
        };
    }

    private function decisionProvider(array $assessment, array $sources): J6AssessmentDecisionProvider
    {
        return new class($assessment, $this->sourceInputHash($sources)) implements J6AssessmentDecisionProvider {
            public function __construct(private array $assessment, private string $sourceHash) {}
            public function context(): J6DecisionContext { return new J6DecisionContext(11, 13, J6ReadMode::CURRENT,
                $this->sourceHash, 17, 1, 19); }
            public function header(): array { return array_diff_key($this->assessment, array_flip(['activities', 'decisions', 'funding_sources', 'funding_costs', 'funding'])); }
            public function decisionPages(int $limit = 500): iterable
            {
                $rows = [];
                foreach ($this->assessment['decisions'] as $id => $payload) $rows[] = ['source_id' => $id, 'payload' => $payload,
                    'record_hash' => NonprofitTaxRules::hash(['domain' => 'test-decision', 'id' => $id, 'payload' => $payload])];
                foreach (array_chunk($rows, $limit) as $page) yield $page;
            }
            public function activityPages(int $limit = 500): iterable
            {
                $rows = [];
                foreach ($this->assessment['activities'] as $id => $payload) $rows[] = ['activity_kind_id' => (string) $id, 'payload' => $payload,
                    'record_hash' => NonprofitTaxRules::hash(['domain' => 'test-activity', 'id' => (string) $id, 'payload' => $payload])];
                foreach (array_chunk($rows, $limit) as $page) yield $page;
            }
            public function fundingPoolPages(int $limit = 500): iterable
            {
                $rows = [];
                foreach (['revenue' => $this->assessment['funding_sources'] ?? [], 'expense' => $this->assessment['funding_costs'] ?? []] as $kind => $pools) {
                    foreach ($pools as $pool) $rows[] = ['pool_kind' => $kind, 'anchor' => $pool['anchor'],
                        'payload' => $pool, 'record_hash' => NonprofitTaxRules::hash(['domain' => 'test-pool', 'kind' => $kind, 'pool' => $pool])];
                }
                foreach (array_chunk($rows, $limit) as $page) yield $page;
            }
            public function fundingMemberPages(int $limit = 500): iterable
            {
                $rows = [];
                foreach (['revenue' => $this->assessment['funding_sources'] ?? [], 'expense' => $this->assessment['funding_costs'] ?? []] as $kind => $pools) {
                    foreach ($pools as $pool) foreach ($pool['members'] ?? [] as $member) {
                        $rows[] = ['pool_kind' => $kind, 'anchor' => $pool['anchor'], 'member_id' => $member];
                    }
                }

                foreach (array_chunk($rows, $limit) as $page) yield $page;
            }
            public function fundingLinkPages(int $limit = 500): iterable
            {
                $rows = [];
                foreach ($this->assessment['funding'] ?? [] as $link) $rows[] = ['source_id' => $link['source_id'],
                    'cost_id' => $link['cost_id'], 'payload' => $link,
                    'record_hash' => NonprofitTaxRules::hash(['domain' => 'test-link', 'link' => $link])];
                foreach (array_chunk($rows, $limit) as $page) yield $page;
            }
        };
    }

    private function sourceInputHash(array $rows): string
    {
        $header = $this->sourceProvider($rows)->header();
        $hasher = new NonprofitTaxSnapshotHasher();
        $root = $hasher->startRoot();
        $ordinal = $count = 0;
        foreach (array_chunk($rows, 500) as $pageRows) {
            $chunk = $hasher->chunk($ordinal, new SnapshotPageV2($pageRows, [], null, null, null, count($pageRows)));
            $hasher->updateRoot($root, $chunk['descriptor']);
            $count += count($pageRows);
            ++$ordinal;
        }
        return $hasher->input($header, $hasher->header($header), $hasher->finishRoot($root, $ordinal), $count, 0, $ordinal);
    }

    private function sink(): object
    {
        return new class implements J6CalculationSink {
            public ?J6CalculationHeader $header = null;
            public array $decisions = [];
            public array $results = [];
            public array $coverage = [];
            public array $usage = [];
            public array $references = [];
            public array $history = [];
            public function begin(J6CalculationHeader $header): void { $this->header = $header; }
            public function appendDecisionPage(array $rows): void { array_push($this->decisions, ...$rows); }
            public function appendResultPage(array $rows): void { array_push($this->results, ...$rows); }
            public function appendCoveragePage(array $rows): void { array_push($this->coverage, ...$rows); }
            public function appendFundingUsagePage(array $rows): void { array_push($this->usage, ...$rows); }
            public function appendFundingReferencePage(array $rows): void { array_push($this->references, ...$rows); }
            public function appendFundingHistoryPage(array $rows): void { array_push($this->history, ...$rows); }
            public function complete(array $summary): J6CalculationReceipt
            {
                $counts = ['decision' => count($this->decisions), 'result' => count($this->results),
                    'coverage' => count($this->coverage), 'usage' => count($this->usage)];
                $roots = array_fill_keys(['decision', 'result', 'coverage', 'usage'], hash('sha256', 'test-root'));
                $evidenceRoots = array_fill_keys(['funding_reference', 'funding_history'], hash('sha256', 'test-evidence-root'));
                return new J6CalculationReceipt($this->header, [], $roots, $counts, $summary, null, [], $evidenceRoots,
                    ['funding_reference' => count($this->references), 'funding_history' => count($this->history)]);
            }
            public function abort(): void {}
        };
    }
}
