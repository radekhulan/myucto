<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Nonprofit\Funding\FundingService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentConfirmationService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDraftService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6DraftContext;
use MyInvoice\Tests\Support\NonprofitResourceCaseFixture;
use MyInvoice\Tests\Support\ResourceReadBearerFixture;
use PHPUnit\Framework\TestCase;

final class FundingPagedJ6Test extends TestCase
{
    use ResourceReadBearerFixture;
    use NonprofitResourceCaseFixture;

    public function testReadOverPagedAssessmentReportsCurrentSourceAndActualUse(): void
    {
        $this->fundingSource(false);
        $assessment = $this->pagedFundingAssessment(60000);

        $report = $this->container->get(FundingService::class)->read($this->supplier);

        self::assertSame([], $report['findings']);
        self::assertTrue($report['complete']);
        self::assertCount(1, $report['sources']);
        $source = $report['sources'][0];
        self::assertTrue($source['source_current']);
        self::assertSame(60000, $source['drawdown_cents']);
        self::assertSame(10000, $source['budget_remaining_cents']);
        self::assertSame(10000, $source['available_cents']);
        self::assertCount(1, $source['uses']);
        self::assertSame($assessment, $source['uses'][0]['assessment_id']);
        self::assertSame('line:' . $this->expense, $source['uses'][0]['cost']['id']);
        self::assertSame(60000, $source['uses'][0]['amount_cents']);
    }

    public function testSourceRevisionUsesPagedAssessmentAsBasisAndBudgetGuard(): void
    {
        $this->fundingSource(false);
        $assessment = $this->pagedFundingAssessment(60000);
        $service = $this->container->get(FundingService::class);

        $policy = $this->fundingPolicy;
        $policy['budget_cents'] = 75000;
        $preview = $service->preview($this->supplier, $this->period, 'PRIVATE-SOURCE-KEY', $policy);
        self::assertSame(1, $preview['expected_revision']);
        self::assertSame(60000, $preview['report']['drawdown_cents']);
        $revision = $service->confirm($this->supplier, $this->period, 'PRIVATE-SOURCE-KEY', $policy, $preview['input_hash'],
            $preview['expected_revision'], self::PRIVATE_SENTINEL, 'paged-budget', $this->actor);
        self::assertSame(2, $revision['revision_no']);
        self::assertSame($assessment, $revision['basis']['assessment_id']);

        $policy['budget_cents'] = 50000;
        try {
            $service->preview($this->supplier, $this->period, 'PRIVATE-SOURCE-KEY', $policy);
            self::fail('Budget below the confirmed paged drawdown was accepted.');
        } catch (NonprofitTaxAssessmentException $failure) {
            self::assertSame('nonprofit_funding_budget_exceeded', $failure->errorCode);
        }
    }

    public function testChoicesOfferGiftFromPagedAssessment(): void
    {
        $this->fundingSource(false);
        $this->pagedFundingAssessment(0);

        $choices = $this->container->get(FundingService::class)->choices($this->supplier, $this->period);

        self::assertTrue($choices['assessment_current']);
        self::assertFalse($choices['sources_more']);
        self::assertSame([[
            'anchor' => 'line:' . $this->income, 'members' => ['line:' . $this->income], 'kind' => 'gift',
            'date' => '2026-01-01', 'account_code' => '682', 'amount_cents' => 100000, 'evidence' => self::PRIVATE_SENTINEL,
        ]], $choices['sources']);
    }

    public function testLegacyAssessmentAfterPagedPeriodIsNotCurrentInsteadOfConflict(): void
    {
        $this->fundingSource(false);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework)VALUES(?,'2025-01-01','cz_504')")
            ->execute([$this->supplier]);
        $earlier = $this->container->get(AccountingPeriodRepository::class)->create($this->supplier, 2025, '2025-01-01', '2025-12-31');
        $drafts = $this->container->get(J6AssessmentDraftService::class);
        $draft = $drafts->openCurrent($this->supplier, $earlier, $this->assessment['profile'], null, $this->actor);
        $context = new J6DraftContext($this->supplier, $earlier, $draft['id'], $draft['revision_no'], $this->actor, $draft['source_input_hash']);
        $this->container->get(J6AssessmentConfirmationService::class)->confirm($context, 'paged-earlier', self::PRIVATE_SENTINEL, null);

        $report = $this->container->get(FundingService::class)->read($this->supplier);

        self::assertFalse($report['complete']);
        self::assertSame([['code' => 'funding_source_not_current', 'source_key' => 'PRIVATE-SOURCE-KEY']], $report['findings']);
        self::assertFalse($report['sources'][0]['source_current']);
        self::assertNull($report['sources'][0]['available_cents']);
    }
}
