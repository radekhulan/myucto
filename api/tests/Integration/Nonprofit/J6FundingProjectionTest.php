<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\NonprofitTaxAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxCalculationManifestRepository;
use MyInvoice\Repository\NonprofitTaxPagedAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Repository\NonprofitTaxSourceEvidenceManifestRepository;
use MyInvoice\Service\Accounting\Funding\CostCoverage;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonSpool;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentGraphReceipt;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationHeader;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationPageHasher;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ImmutableManifestSink;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6SourceEvidencePageHasher;
use MyInvoice\Service\Tax\Nonprofit\J6FundingCapacity;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;
use MyInvoice\Tests\Support\NonprofitResourceCaseFixture;
use MyInvoice\Tests\Support\ResourceReadBearerFixture;
use PHPUnit\Framework\TestCase;

/**
 * Krytí nákladu, navazující čerpání a politiky zdrojů musí vidět stránkovanou revizi J6
 * stejně jako legacy JSON (J6FundingProjection).
 */
final class J6FundingProjectionTest extends TestCase
{
    use ResourceReadBearerFixture;
    use NonprofitResourceCaseFixture;

    public function testCostClaimsCountPagedDrawdownSoTheCostCannotBeCoveredTwice(): void
    {
        $this->fundingSource(false);
        $assessment = $this->pagedFundingAssessment(60000);
        $capacity = $this->container->get(J6FundingCapacity::class);

        $claims = $capacity->costClaims($this->supplier, $this->expense);

        self::assertCount(1, $claims);
        self::assertSame($assessment, $claims[0]['assessment_id']);
        self::assertSame('60000', $claims[0]['amount_cents']);
        self::assertSame(60000, $claims[0]['links'][0]['amount_cents']);
        self::assertSame('0', $capacity->claimedCost($this->supplier, $this->expense, $this->period));
        self::assertSame(20000, (new CostCoverage($this->db))->capacity($this->supplier, $this->expense)['available_cents']);

        $this->nativePost('518', '10.00', 'debit');
        try {
            $capacity->claimedCost($this->supplier, $this->expense);
            self::fail('Stale paged drawdown must block cost capacity, never disappear.');
        } catch (NonprofitTaxAssessmentException $failure) {
            self::assertSame('nonprofit_tax_funding_changed', $failure->errorCode);
        }
    }

    public function testCostEnvelopeReadsDeclaredCostPoolFromPagedAssessment(): void
    {
        $this->fundingSource(false);
        $assessment = $this->pagedFundingAssessment(0, true);

        $envelope = $this->container->get(J6FundingCapacity::class)->costEnvelope($this->supplier, $this->expense);

        self::assertNotNull($envelope);
        self::assertSame($assessment, $envelope['assessment_id']);
        self::assertSame($this->expense, $envelope['anchor']);
        self::assertSame([$this->expense], $envelope['members']);
        self::assertSame([$this->expense => 80000], $envelope['line_amounts']);
        self::assertSame(80000, $envelope['net_cents']);
        self::assertSame(self::PRIVATE_SENTINEL, $envelope['proof']['declared']['evidence']);
        self::assertSame(['line:' . $this->expense], array_keys($envelope['proof']['rows']));
        self::assertNull($this->container->get(J6FundingCapacity::class)->costEnvelope($this->supplier, $this->expense, $this->period));
    }

    public function testLegacyWithdrawalAndConfirmationSeeLaterPagedDrawdown(): void
    {
        $this->fundingSource(false);
        $this->laterPagedUsage('line:' . $this->income);
        $tax = $this->container->get(NonprofitTaxAssessmentService::class);

        try {
            $tax->withdraw($this->supplier, $this->period, $this->taxReceipt['id'], self::PRIVATE_SENTINEL, 'withdraw-later-paged', $this->actor);
            self::fail('Later paged drawdown must block withdrawal of the source assessment.');
        } catch (NonprofitTaxAssessmentException $failure) {
            self::assertSame('nonprofit_tax_review_required', $failure->errorCode);
        }
        $snapshot = $tax->read($this->supplier, $this->period)['snapshot'];
        try {
            $tax->confirm($this->supplier, $this->period, $this->assessment, $snapshot['input_hash'], self::PRIVATE_SENTINEL,
                'reconfirm-later-paged', $this->actor, $this->taxReceipt['id']);
            self::fail('Later paged drawdown must block reconfirmation of the source assessment.');
        } catch (NonprofitTaxAssessmentException $failure) {
            self::assertSame('nonprofit_tax_review_required', $failure->errorCode);
        }
    }

    public function testPagedConfirmationSeesLaterDrawdown(): void
    {
        $this->fundingSource(false);
        $this->laterPagedUsage('line:' . $this->income);

        try {
            $this->pagedFundingAssessment(0);
            self::fail('Later drawdown must block paged reconfirmation of the source assessment.');
        } catch (NonprofitTaxAssessmentException $failure) {
            self::assertSame('nonprofit_tax_review_required', $failure->errorCode);
        }
        self::assertSame($this->taxReceipt['id'],
            $this->container->get(NonprofitTaxAssessmentRepository::class)->latest($this->supplier, $this->period)['id']);
    }

    public function testPagedConfirmationEnforcesFundingSourcePolicy(): void
    {
        $this->fundingSource(false);

        try {
            $this->pagedFundingAssessment(75000);
            self::fail('Paged confirmation must not exceed the confirmed budget of the funding source.');
        } catch (NonprofitTaxAssessmentException $failure) {
            self::assertSame('nonprofit_funding_budget_exceeded', $failure->errorCode);
        }
        self::assertSame($this->taxReceipt['id'],
            $this->container->get(NonprofitTaxAssessmentRepository::class)->latest($this->supplier, $this->period)['id']);
        self::assertIsInt($this->pagedFundingAssessment(70000));
    }

    public function testLegacyConfirmationSeesGiftElectionFromPagedRevision(): void
    {
        $this->fundingSource(false);
        $this->laterPagedUsage('later:unrelated', ['id' => 'later:gift', 'kind' => 'revenue', 'amount_cents' => 5000,
            'origin' => ['line_id' => $this->income],
            'decision' => ['title' => 'gift', 'facts' => ['claim_exemption' => false]], 'classification' => ['regime' => 'subject'],
            'result_row_hash' => hash('sha256', 'result-row:later-gift')]);
        $tax = $this->container->get(NonprofitTaxAssessmentService::class);
        $snapshot = $tax->read($this->supplier, $this->period)['snapshot'];

        try {
            $tax->confirm($this->supplier, $this->period, $this->assessment, $snapshot['input_hash'], self::PRIVATE_SENTINEL,
                'reconfirm-paged-election', $this->actor, $this->taxReceipt['id']);
            self::fail('Gift election confirmed in a paged revision must stay locked for legacy confirmation.');
        } catch (NonprofitTaxAssessmentException $failure) {
            self::assertSame('nonprofit_tax_gift_election_locked', $failure->errorCode);
        }
    }

    public function testPagedConfirmationCannotCoverDirectlyCoveredCostAgain(): void
    {
        $this->fundingSource(false);
        $dimensions = $this->dimensions;
        $origins = array_fill_keys(array_keys($dimensions), ['origin' => 'explicit', 'ref' => null]);
        $entry = $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => '800.00', 'dimensions' => $dimensions, 'dimension_origins' => $origins],
            ['account_code' => '648', 'side' => 'credit', 'amount' => '200.00', 'dimensions' => $dimensions, 'dimension_origins' => $origins],
            ['account_code' => '221', 'side' => 'credit', 'amount' => '600.00', 'dimensions' => $dimensions, 'dimension_origins' => $origins],
        ], ['entry_date' => '2026-01-01', 'description' => self::PRIVATE_SENTINEL, 'posted' => true, 'posted_by' => $this->actor]);
        $lines = $this->db->pdo()->prepare('SELECT a.account_code,l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id
            WHERE l.supplier_id=? AND l.entry_id=?');
        $lines->execute([$this->supplier, $entry]);
        $lines = array_map('intval', $lines->fetchAll(\PDO::FETCH_KEY_PAIR));
        self::assertSame(20000, (new \MyInvoice\Service\Accounting\Funding\NativeCostCoverage($this->db))->forCost($this->supplier, $lines['518'])['covered_cents']);
        $this->assessment['decisions']['line:' . $lines['518']] = ['title' => 'cost', 'evidence' => self::PRIVATE_SENTINEL,
            'legal_basis' => 'zdp_24_1', 'deductible_cents' => 80000];
        $this->assessment['decisions']['line:' . $lines['648']] = ['title' => 'other_taxable', 'evidence' => self::PRIVATE_SENTINEL];
        $this->expense = $lines['518'];

        try {
            $this->pagedFundingAssessment(65000);
            self::fail('Paged J6 must not cover a cost that is already directly covered by its own 648 line.');
        } catch (NonprofitTaxAssessmentException $failure) {
            self::assertSame('nonprofit_funding_cost_overcovered', $failure->errorCode);
        }
        self::assertSame($this->taxReceipt['id'],
            $this->container->get(NonprofitTaxAssessmentRepository::class)->latest($this->supplier, $this->period)['id']);
        self::assertIsInt($this->pagedFundingAssessment(60000));
    }

    /** Syntetická potvrzená stránkovaná revize pozdějšího období, která čerpá daný zdroj. */
    private function laterPagedUsage(string $member, ?array $resultRow = null): void
    {
        $resultRow ??= ['id' => 'later:cost', 'kind' => 'expense', 'amount_cents' => 5000,
            'decision' => ['title' => 'cost'], 'classification' => ['regime' => 'exempt'],
            'result_row_hash' => hash('sha256', 'result-row:later')];
        $period = $this->container->get(AccountingPeriodRepository::class)->create($this->supplier, 2027, '2027-01-01', '2027-12-31');
        $snapshot = (new NonprofitTaxSnapshotManifestRepository($this->db, new NonprofitTaxSnapshotHasher()))->appendManifest([
            'supplier_id' => $this->supplier, 'period_id' => $period, 'ruleset' => NonprofitTaxRules::VERSION,
            'header' => ['period' => ['id' => $period]], 'header_hash' => hash('sha256', "header:{$period}"),
            'root_hash' => hash('sha256', "root:{$period}"), 'input_hash' => $inputHash = hash('sha256', "snapshot:{$period}"),
            'row_count' => 1, 'finding_count' => 0, 'chunk_count' => 0, 'membership_fact_count' => 0,
        ], []);
        $header = new J6CalculationHeader($this->supplier, $period, NonprofitTaxRules::VERSION, $inputHash,
            hash('sha256', "decision:{$period}"), hash('sha256', "calculation:{$period}"), $snapshot->id, null,
            ['profile' => ['kind' => 'narrow_vpp', 'evidence' => self::PRIVATE_SENTINEL]], $inputHash);
        $sink = new J6ImmutableManifestSink(new NonprofitTaxCalculationManifestRepository($this->db),
            new J6CalculationPageHasher(new CanonicalJsonSpool()), new NonprofitTaxSourceEvidenceManifestRepository($this->db),
            new J6SourceEvidencePageHasher(new CanonicalJsonSpool()));
        $sink->begin($header);
        $sink->appendDecisionPage([['record_kind' => 'source', 'source_id' => $resultRow['id'],
            'payload' => ['title' => $resultRow['decision']['title']], 'decision_record_hash' => hash('sha256', 'decision-row:later')]]);
        $sink->appendResultPage([$resultRow]);
        $sink->appendCoveragePage([['source_id' => $resultRow['id'], 'result_row_hash' => hash('sha256', 'coverage-row:later')]]);
        $sink->appendFundingUsagePage([['anchor' => $member, 'member_id' => $member, 'net_source_cents' => 100000,
            'prior_drawdown_cents' => 0, 'drawdown_cents' => 5000, 'remaining_cents' => 95000,
            'usage_record_hash' => hash('sha256', 'later-usage-' . $this->supplier)]]);
        $calculation = $sink->complete(['source_row_count' => 1, 'decision_record_count' => 1, 'funding_usage_count' => 1,
            'accounting_result_cents' => -5000, 'excluded_revenue_cents' => 0, 'cost_addbacks_cents' => 0, 'assessed_result_cents' => -5000]);
        (new NonprofitTaxPagedAssessmentRepository($this->db))->appendConfirmed($this->supplier, $period, null,
            self::PRIVATE_SENTINEL, 'later-paged-' . $this->supplier, hash('sha256', 'later-request:' . $this->supplier),
            $header->decisionHeader, J6AssessmentGraphReceipt::fromCalculation($snapshot, 0, $calculation), $this->actor);
    }
}
