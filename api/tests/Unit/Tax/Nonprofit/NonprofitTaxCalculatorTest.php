<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Tests\Support\J6CalculatorFactory;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NonprofitTaxCalculatorTest extends TestCase
{
    private array $snapshot;
    private array $assessment;

    protected function setUp(): void
    {
        $this->snapshot = ['period' => ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'], 'sources' => []];
        $this->assessment = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy a doložené postavení'],
            'activities' => [], 'decisions' => [], 'funding' => []];
    }

    private function row(string $id, string $kind, int $amount, int $activityKind, string $title, array $extra = []): void
    {
        $this->snapshot['sources'][] = ['id' => $id, 'kind' => $kind, 'amount_cents' => $amount,
            'activity' => 'main', 'activity_kind_id' => $activityKind, 'tax_regime' => 'subject'];
        $this->assessment['activities'][$activityKind] ??= ['evidence' => 'Věcně samostatná činnost ' . $activityKind];
        $this->assessment['decisions'][$id] = $extra + ['title' => $title, 'evidence' => 'Syntetický doklad ' . $id,
            'deductible_cents' => $amount, 'legal_basis' => 'zdp_24_1'];
    }

    private function calculate(): array
    {
        return J6CalculatorFactory::legacy()->calculate($this->snapshot, $this->assessment);
    }

    private function pendingGift(string $id, int $activity): void
    {
        $this->row($id, 'revenue', 10000, $activity, 'gift', ['facts' => ['claim_exemption' => false,
            'income_treatment' => 'nonbusiness_activity', 'income_attribution_evidence' => 'Synthetic original activity attribution']]);
    }

    public function testAnnualOutOfScopeGiftExcludesTheBusinessCostItFunds(): void
    {
        $this->pendingGift('gift', 1);
        $this->row('activity-cost', 'expense', 20000, 1, 'nonbusiness_cost');
        $this->row('business-income', 'revenue', 30000, 2, 'business');
        $this->row('business-cost', 'expense', 10000, 2, 'cost');
        $this->assessment['funding'][] = ['source_id' => 'gift', 'cost_id' => 'business-cost', 'amount_cents' => 10000, 'evidence' => 'Synthetic actual drawdown'];
        $out = $this->calculate();
        self::assertSame(30000, $out['assessed_result_cents']);
        $rows = array_column($out['rows'], null, 'id');
        self::assertSame('out_of_scope', $rows['gift']['classification']['regime']);
        self::assertSame(0, $rows['business-cost']['deductible_cents']);
    }

    public function testConditionalFundingResolvesActivityDependenciesRegardlessOfInputOrder(): void
    {
        $this->pendingGift('first-gift', 1);
        $this->row('first-cost', 'expense', 20000, 1, 'nonbusiness_cost');
        $this->pendingGift('second-gift', 2);
        $this->row('second-cost', 'expense', 15000, 2, 'nonbusiness_cost');
        $this->row('business-income', 'revenue', 30000, 3, 'business');
        $this->row('business-cost', 'expense', 10000, 3, 'cost');
        $this->assessment['funding'] = [
            ['source_id' => 'first-gift', 'cost_id' => 'second-cost', 'amount_cents' => 10000, 'evidence' => 'Synthetic first drawdown'],
            ['source_id' => 'second-gift', 'cost_id' => 'business-cost', 'amount_cents' => 10000, 'evidence' => 'Synthetic second drawdown'],
        ];
        foreach ([false, true] as $reverse) {
            if ($reverse) $this->snapshot['sources'] = array_reverse($this->snapshot['sources']);
            $out = $this->calculate();
            self::assertSame(25000, $out['assessed_result_cents']);
            $rows = array_column($out['rows'], null, 'id');
            self::assertSame('out_of_scope', $rows['first-gift']['classification']['regime']);
            self::assertSame('subject', $rows['second-gift']['classification']['regime']);
            self::assertSame(10000, $rows['business-cost']['deductible_cents']);
        }
    }

    public function testCircularConditionalFundingRequiresAnIndependentAssessment(): void
    {
        $this->pendingGift('gift', 1);
        $this->row('activity-cost', 'expense', 20000, 1, 'nonbusiness_cost');
        $this->assessment['funding'][] = ['source_id' => 'gift', 'cost_id' => 'activity-cost', 'amount_cents' => 10000, 'evidence' => 'Synthetic circular drawdown'];
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->expectExceptionMessage('Kruhová závislost');
        $this->calculate();
    }

    public function testCircularFundingAcceptsOnlyItsUniqueConsistentClassification(): void
    {
        $this->pendingGift('gift', 1);
        $this->row('grant', 'revenue', 10000, 1, 'public_operating_grant', ['facts' => ['public_budget' => true]]);
        $this->row('cost', 'expense', 15000, 1, 'nonbusiness_cost');
        $this->assessment['funding'] = [
            ['source_id' => 'grant', 'cost_id' => 'cost', 'amount_cents' => 10000, 'evidence' => 'Synthetic grant drawdown'],
            ['source_id' => 'gift', 'cost_id' => 'cost', 'amount_cents' => 5000, 'evidence' => 'Synthetic gift drawdown'],
        ];
        $out = $this->calculate();
        self::assertSame(5000, $out['assessed_result_cents']);
        self::assertSame('subject', $out['activities'][0]['regime']);
    }

    public function testCircularFundingCannotChooseBetweenTwoConsistentClassifications(): void
    {
        foreach ([1, 2] as $activity) {
            $this->pendingGift('gift' . $activity, $activity);
            $this->row('cost' . $activity, 'expense', 15000, $activity, 'nonbusiness_cost');
        }
        $this->assessment['funding'] = [
            ['source_id' => 'gift1', 'cost_id' => 'cost2', 'amount_cents' => 10000, 'evidence' => 'Synthetic first drawdown'],
            ['source_id' => 'gift2', 'cost_id' => 'cost1', 'amount_cents' => 10000, 'evidence' => 'Synthetic second drawdown'],
        ];
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->expectExceptionMessage('Kruhová závislost');
        $this->calculate();
    }

    private function documentedMethod(string $from, string $to): void
    {
        $this->snapshot['period'] = ['starts_on' => $from, 'ends_on' => $to];
        $this->assessment['profile'] = ['kind' => 'documented', 'evidence' => 'Synthetic documented taxpayer position'];
        $this->assessment['method'] = ['mode' => 'documented_manual', 'valid_from' => $from, 'valid_to' => $to,
            'legal_basis' => 'Synthetic legislation applicable to this period', 'evidence' => 'Synthetic signed classification working paper'];
        foreach ($this->snapshot['sources'] as $row) {
            $this->assessment['decisions'][$row['id']]['final_regime'] = 'subject';
            if ($row['kind'] === 'revenue') $this->assessment['decisions'][$row['id']]['final_method'] = 'return';
        }
    }

    public function testDocumentedManualAssessmentSupportsHistoricalPeriodsWithoutCurrentRules(): void
    {
        $this->row('income', 'revenue', 10000, 1, 'other_taxable');
        $this->row('cost', 'expense', 4000, 1, 'cost');
        $this->documentedMethod('2023-01-01', '2023-12-31');
        $out = $this->calculate();
        self::assertSame(6000, $out['assessed_result_cents']);
        self::assertSame('documented_manual', $out['assessment_method']);
        self::assertSame([], $out['activities']);
        self::assertFalse($out['dppo_supported']);
    }

    public function testDocumentedManualAssessmentSupportsFiscalYearEndingAfterVerifiedRules(): void
    {
        $this->row('income', 'revenue', 10000, 1, 'rent');
        $this->row('cost', 'expense', 4000, 1, 'cost');
        $this->documentedMethod('2026-07-01', '2027-06-30');
        self::assertSame(6000, $this->calculate()['assessed_result_cents']);
    }

    public function testDocumentedManualAssessmentStillExcludesFundedCostsExactlyOnce(): void
    {
        $this->row('gift', 'revenue', 10000, 1, 'gift', ['facts' => ['claim_exemption' => true,
            'election_evidence' => 'Synthetic original exemption election']]);
        $this->row('income', 'revenue', 30000, 2, 'other_taxable');
        $this->row('cost', 'expense', 10000, 2, 'cost');
        $this->documentedMethod('2023-01-01', '2023-12-31');
        $this->assessment['decisions']['gift']['final_regime'] = 'exempt';
        $this->assessment['decisions']['gift']['final_method'] = 'none';
        $this->assessment['funding'][] = ['source_id' => 'gift', 'cost_id' => 'cost', 'amount_cents' => 5000, 'evidence' => 'Synthetic actual drawdown'];
        $out = $this->calculate();
        self::assertSame(25000, $out['assessed_result_cents']);
        self::assertSame(5000, array_column($out['rows'], null, 'id')['cost']['deductible_cents']);
        self::assertSame(5000, $out['cost_addbacks_cents']);
    }

    public function testManualMethodCannotOverrideVerifiedAutomaticRules(): void
    {
        $this->row('income', 'revenue', 10000, 1, 'rent');
        $this->documentedMethod('2026-01-01', '2026-12-31');
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->expectExceptionMessage('pouze pro období mimo');
        $this->calculate();
    }

    public function testManualEvidenceMustCoverTheWholeFiscalPeriod(): void
    {
        $this->row('income', 'revenue', 10000, 1, 'rent');
        $this->documentedMethod('2026-07-01', '2027-06-30');
        $this->assessment['method']['valid_to'] = '2026-12-31';
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->expectExceptionMessage('celé platné účetní období');
        $this->calculate();
    }

    public function testManualGiftRegimeCannotContradictTheExemptionElection(): void
    {
        $this->row('gift', 'revenue', 10000, 1, 'gift', ['facts' => ['claim_exemption' => true, 'election_evidence' => 'Synthetic election']]);
        $this->documentedMethod('2023-01-01', '2023-12-31');
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->expectExceptionMessage('souhlasit s výslovnou volbou');
        $this->calculate();
    }

    public function testManualExcludedCostCannotKeepADeduction(): void
    {
        $this->row('cost', 'expense', 10000, 1, 'cost');
        $this->documentedMethod('2023-01-01', '2023-12-31');
        $this->assessment['decisions']['cost']['final_regime'] = 'out_of_scope';
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->expectExceptionMessage('nemůže mít uznatelnou část');
        $this->calculate();
    }

    public function testManualWithholdingRequiresGrossAndNetEvidence(): void
    {
        $this->row('interest', 'revenue', 7900, 1, 'bank_interest', ['facts' => ['gross_interest_cents' => 10000, 'withholding_cents' => 2100]]);
        $this->documentedMethod('2023-01-01', '2023-12-31');
        $this->assessment['decisions']['interest']['final_method'] = 'final_withholding';
        self::assertSame(0, $this->calculate()['assessed_result_cents']);
        $this->assessment['decisions']['interest']['facts']['withholding_cents'] = 2000;
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->calculate();
    }

    public function testMalformedAssessmentReturnsDomainValidationInsteadOfRuntimeError(): void
    {
        $this->row('rent', 'revenue', 10000, 1, 'rent');
        $valid = $this->assessment;
        foreach ([['profile' => 'invalid'], ['decisions' => 'invalid'], ['activities' => [1 => 'invalid']],
            ['funding' => 'invalid'], ['funding' => ['invalid']], ['funding_sources' => ['invalid']],
            ['decisions' => ['rent' => ['title' => 'rent', 'evidence' => 'Doklad', 'cohort' => []]]],
            ['activities' => [1 => ['evidence' => 'Činnost', 'cohorts' => 'invalid']]]] as $invalid) {
            $this->assessment = array_replace($valid, $invalid);
            try {
                $this->calculate();
                self::fail('Malformed assessment must be rejected');
            } catch (NonprofitTaxAssessmentException $e) {
                self::assertSame('nonprofit_tax_facts_incomplete', $e->errorCode);
            }
        }
    }

    public function testReferenceBridgeAppliesFundingBeforeActivityTest(): void
    {
        $this->row('sport-income', 'revenue', 10000000, 1, 'nonbusiness');
        $this->row('sport-material', 'expense', 8000000, 1, 'nonbusiness_cost');
        $this->row('sport-other', 'expense', 7000000, 1, 'nonbusiness_cost');
        $this->row('education-income', 'revenue', 10000000, 2, 'nonbusiness');
        $this->row('education-cost', 'expense', 5000000, 2, 'nonbusiness_cost');
        $this->row('rent', 'revenue', 6000000, 3, 'rent');
        $this->row('rent-cost', 'expense', 1500000, 3, 'cost');
        $this->row('membership', 'revenue', 4000000, 4, 'membership', ['facts' => ['qualifying_membership' => true]]);
        $this->row('gift', 'revenue', 2500000, 5, 'gift', ['facts' => ['claim_exemption' => true, 'qualifying_purpose' => true, 'election_evidence' => 'Rozhodnutí před použitím']]);
        $this->row('grant', 'revenue', 3000000, 5, 'public_operating_grant', ['facts' => ['public_budget' => true]]);
        $this->row('depreciation', 'expense', 2400000, 6, 'excluded_cost', ['deductible_cents' => 0, 'legal_basis' => 'zdp_24_1_no_taxable_use']);
        $this->row('investment-release', 'revenue', 1440000, 6, 'public_investment_grant', ['facts' => ['public_budget' => true]]);
        foreach (['gift' => 2500000, 'grant' => 3000000] as $source => $amount) {
            $this->assessment['funding'][] = ['source_id' => $source, 'cost_id' => 'sport-material', 'amount_cents' => $amount, 'evidence' => 'Rozhodnutí a skutečné čerpání'];
        }
        $out = $this->calculate();
        self::assertSame(13040000, $out['accounting_result_cents']);
        self::assertSame(10000000, $out['assessed_result_cents']);
        $groups = array_column($out['activities'], null, 'activity_kind_id');
        self::assertSame(9500000, $groups[1]['cost_cents']);
        self::assertSame(500000, $groups[1]['contribution_cents']);
        self::assertSame(5000000, $groups[2]['contribution_cents']);
        self::assertSame(7900000, $out['cost_addbacks_cents']);
        self::assertFalse($out['dppo_supported']);
        $rows = array_column($out['rows'], null, 'id');
        foreach (['sport-income', 'education-income'] as $id) {
            self::assertSame('subject', $rows[$id]['classification']['regime'], $id);
            self::assertSame('return', $rows[$id]['classification']['method'], $id);
        }
        $bridge = (new \MyInvoice\Service\Tax\Nonprofit\NonprofitDppoClassificationBridge())->build($out);
        self::assertSame(13040000, $bridge['line_cents'][10]);
        self::assertSame(7900000, $bridge['line_cents'][40]);
        $this->assessment['decisions']['gift']['facts']['claim_exemption'] = false;
        $this->assessment['decisions']['gift']['facts']['income_treatment'] = 'nonbusiness_activity';
        $this->assessment['decisions']['gift']['facts']['income_attribution_evidence'] = 'Samostatná obecná podpora podle původních skutečností';
        self::assertSame(12000000, $this->calculate()['assessed_result_cents']);
        foreach ($this->snapshot['sources'] as &$source) {
            if ($source['id'] === 'gift') $source['activity_kind_id'] = 1;
        }
        unset($source);
        $this->assessment['decisions']['gift']['facts']['income_attribution_evidence'] = 'Odlišné skutkové zadání: příjem sportovní činnosti od přijetí daru';
        self::assertSame(10000000, $this->calculate()['assessed_result_cents']);
    }

    public function testActivityLossCannotReduceAnotherActivityProfit(): void
    {
        $this->row('a-income', 'revenue', 10000, 1, 'nonbusiness');
        $this->row('a-cost', 'expense', 15000, 1, 'nonbusiness_cost');
        $this->row('b-income', 'revenue', 10000, 2, 'nonbusiness');
        $this->row('b-cost', 'expense', 5000, 2, 'nonbusiness_cost');
        self::assertSame(5000, $this->calculate()['assessed_result_cents']);
    }

    public function testEqualityIsSubjectButMixedPriceCohortEqualityIsExcluded(): void
    {
        $this->row('income', 'revenue', 20000, 1, 'nonbusiness');
        $this->row('cost', 'expense', 20000, 1, 'nonbusiness_cost');
        self::assertSame('subject', $this->calculate()['activities'][0]['regime']);
        $this->assessment['activities'][1]['cohorts'] = ['member' => 'Předem doložený členský ceník', 'public' => 'Předem doložený veřejný ceník'];
        $this->assessment['decisions']['income']['cohort'] = 'member';
        $this->assessment['decisions']['cost']['cohort'] = 'member';
        $this->row('public-income', 'revenue', 30000, 1, 'nonbusiness', ['cohort' => 'public']);
        $this->row('public-cost', 'expense', 20000, 1, 'nonbusiness_cost', ['cohort' => 'public']);
        $out = $this->calculate();
        self::assertSame(10000, $out['assessed_result_cents']);
        self::assertSame('out_of_scope', $out['activities'][0]['regime']);
    }

    public function testFinalWithholdingInterestIsExcludedButItsVppCostsAreNotBlanketExcluded(): void
    {
        $this->row('interest', 'revenue', 79000, 1, 'bank_interest', ['facts' => ['domestic_bank_account' => true, 'foundation_endowment' => false,
            'gross_interest_cents' => 100000, 'withholding_cents' => 21000]]);
        $this->row('bank-fee', 'expense', 1000, 1, 'withholding_interest_cost', ['legal_basis' => 'zdp_25_1i_interest_exception']);
        $out = $this->calculate();
        self::assertSame(79000, $out['excluded_revenue_cents']);
        self::assertSame(0, $out['cost_addbacks_cents']);
        self::assertSame(-1000, $out['assessed_result_cents']);
    }

    public function testBroadTaxBaseDoesNotUseNarrowLossTestOrExcludeOperatingGrants(): void
    {
        $this->assessment['profile']['kind'] = 'broad_vpp';
        $this->row('income', 'revenue', 10000, 1, 'nonbusiness');
        $this->row('cost', 'expense', 15000, 1, 'nonbusiness_cost');
        $this->row('grant', 'revenue', 9000, 2, 'public_operating_grant', ['facts' => ['public_budget' => true]]);
        self::assertSame(4000, $this->calculate()['assessed_result_cents']);
    }

    public function testSignedReversalPreservesClassifiedAmounts(): void
    {
        $this->row('income', 'revenue', 12345, 1, 'rent');
        $this->row('reverse', 'revenue', -12345, 1, 'rent');
        $this->row('cost', 'expense', 675, 1, 'cost', ['deductible_cents' => 0]);
        $this->row('reverse-cost', 'expense', -675, 1, 'cost', ['deductible_cents' => 0]);
        $out = $this->calculate();
        self::assertSame(0, $out['accounting_result_cents']);
        self::assertSame(0, $out['assessed_result_cents']);
    }

    public function testFundingCannotExceedCostAcrossMultipleSources(): void
    {
        foreach (['g1', 'g2'] as $id) $this->row($id, 'revenue', 8000, 1, 'public_operating_grant', ['facts' => ['public_budget' => true]]);
        $this->row('cost', 'expense', 10000, 1, 'cost');
        foreach (['g1', 'g2'] as $id) $this->assessment['funding'][] = ['source_id' => $id, 'cost_id' => 'cost', 'amount_cents' => 8000, 'evidence' => 'Čerpání'];
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->calculate();
    }

    public function testPartiallyDeductibleFundingNeedsExplicitOverlap(): void
    {
        $this->row('grant', 'revenue', 5000, 1, 'public_operating_grant', ['facts' => ['public_budget' => true]]);
        $this->row('cost', 'expense', 10000, 1, 'cost', ['deductible_cents' => 6000]);
        $this->assessment['funding'][] = ['source_id' => 'grant', 'cost_id' => 'cost', 'amount_cents' => 5000, 'evidence' => 'Čerpání'];
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->calculate();
    }

    public static function fundingOverlapCases(): array
    {
        return ['single expense' => [false], 'expense correction pool' => [true]];
    }

    #[DataProvider('fundingOverlapCases')]
    public function testMultipleExemptSourcesCannotReuseTheSameNonDeductibleCost(bool $pool): void
    {
        $this->fundingOverlapCase($pool, 0);
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->calculate();
    }

    #[DataProvider('fundingOverlapCases')]
    public function testDocumentedCumulativeOverlapPreservesOnlyUnfundedDeductibleCost(bool $pool): void
    {
        $this->fundingOverlapCase($pool, 2000);
        $result = $this->calculate();
        $expenses = array_filter($result['rows'], static fn (array $row): bool => $row['kind'] === 'expense');
        self::assertSame(2000, array_sum(array_column($expenses, 'deductible_cents')));
        self::assertSame(-2000, $result['assessed_result_cents']);
    }

    private function fundingOverlapCase(bool $pool, int $overlap): void
    {
        foreach (['gift1', 'gift2'] as $gift) {
            $this->row($gift, 'revenue', 4000, 1, 'gift', ['facts' => ['claim_exemption' => true,
                'qualifying_purpose' => true, 'election_evidence' => 'Synthetic documented exemption election']]);
        }
        $this->row('cost', 'expense', 10000, 1, 'cost', ['deductible_cents' => 6000]);
        if ($pool) {
            $this->row('cost2', 'expense', 10000, 1, 'cost', ['deductible_cents' => 6000]);
            $this->row('credit', 'expense', -10000, 1, 'cost', ['deductible_cents' => -6000]);
            $this->assessment['funding_costs'] = [['anchor' => 'cost', 'members' => ['cost', 'cost2', 'credit'],
                'evidence' => 'Synthetic correction of the same expense']];
        }
        foreach (['gift1', 'gift2'] as $index => $gift) {
            $this->assessment['funding'][] = ['source_id' => $gift, 'cost_id' => $pool && $index === 1 ? 'cost2' : 'cost',
                'amount_cents' => 4000, 'deductible_funded_cents' => $overlap, 'evidence' => 'Synthetic documented funding'];
        }
    }

    public function testUnknownGiftChoiceCannotBeInventedFromAccountOrActivity(): void
    {
        $this->row('gift', 'revenue', 10000, 1, 'gift');
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->calculate();
    }

    public function testWaivedExemptionNeedsIndependentIncomeAttribution(): void
    {
        $this->row('gift', 'revenue', 10000, 1, 'gift', ['facts' => ['claim_exemption' => false]]);
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->calculate();
    }

    public function testPartiallyDeductibleFundingDoesNotSubtractDisallowedCostsTwice(): void
    {
        $this->row('grant', 'revenue', 5000, 1, 'public_operating_grant', ['facts' => ['public_budget' => true]]);
        $this->row('cost', 'expense', 10000, 1, 'cost', ['deductible_cents' => 6000]);
        $this->assessment['funding'][] = ['source_id' => 'grant', 'cost_id' => 'cost', 'amount_cents' => 5000,
            'deductible_funded_cents' => 1000, 'evidence' => '4000 financované části již není uznatelných'];
        self::assertSame(-5000, $this->calculate()['assessed_result_cents']);
    }

    public function testFundingCapUsesNetGrantAfterRefund(): void
    {
        $this->row('grant', 'revenue', 10000, 1, 'public_operating_grant', ['facts' => ['public_budget' => true]]);
        $this->row('refund', 'revenue', -4000, 1, 'public_operating_grant', ['facts' => ['public_budget' => true]]);
        $this->row('cost', 'expense', 10000, 1, 'cost');
        $this->assessment['funding_sources'] = [['anchor' => 'grant', 'members' => ['grant', 'refund'], 'evidence' => 'Vrácení části stejné dotace']];
        $this->assessment['funding'] = [['source_id' => 'grant', 'cost_id' => 'cost', 'amount_cents' => 6000, 'evidence' => 'Čisté čerpání']];
        self::assertSame(-4000, $this->calculate()['assessed_result_cents']);
        $this->assessment['funding'][0]['amount_cents'] = 6001;
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->calculate();
    }

    public function testFundingCapUsesNetCostAfterCredit(): void
    {
        $this->row('grant', 'revenue', 10000, 1, 'public_operating_grant', ['facts' => ['public_budget' => true]]);
        $this->row('cost', 'expense', 10000, 1, 'cost');
        $this->row('credit', 'expense', -4000, 1, 'cost');
        $this->assessment['funding_costs'] = [['anchor' => 'cost', 'members' => ['cost', 'credit'], 'evidence' => 'Oprava stejného nákladu']];
        $this->assessment['funding'] = [['source_id' => 'grant', 'cost_id' => 'cost', 'amount_cents' => 6000, 'evidence' => 'Čisté čerpání']];
        self::assertSame(0, $this->calculate()['assessed_result_cents']);
        $this->assessment['funding'][0]['amount_cents'] = 6001;
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->calculate();
    }

    public function testHistoricalDrawdownCannotBeResetByNewAssessmentOrPoolLabel(): void
    {
        $this->row('grant', 'revenue', 10000, 1, 'public_operating_grant', ['facts' => ['public_budget' => true]]);
        $this->row('cost', 'expense', 4000, 1, 'cost');
        $this->snapshot['funding_history'] = [['status' => 'confirmed', 'current' => true, 'usage' => [['anchor' => 'grant', 'members' => ['grant'], 'drawdown_cents' => 7000]]]];
        $this->assessment['funding'] = [['source_id' => 'grant', 'cost_id' => 'cost', 'amount_cents' => 3000, 'evidence' => 'Zbývající část']];
        self::assertSame(7000, $this->calculate()['funding_usage'][0]['prior_drawdown_cents']);
        $this->assessment['funding'][0]['amount_cents'] = 3001;
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->calculate();
    }

    public function testUnresolvedHistoricalRefundNeedsEvidenceAndNeverGuessesSection23Adjustment(): void
    {
        $this->row('grant', 'revenue', 10000, 1, 'public_operating_grant', ['facts' => ['public_budget' => true]]);
        $this->row('refund', 'revenue', -5000, 1, 'public_operating_grant', ['facts' => ['public_budget' => true]]);
        $this->snapshot['funding_history'] = [['status' => 'confirmed', 'current' => true, 'usage' => [['anchor' => 'grant', 'members' => ['grant'], 'drawdown_cents' => 7000]]]];
        $this->assessment['funding_sources'] = [['anchor' => 'grant', 'members' => ['grant', 'refund'], 'evidence' => 'Vrácení po dřívějším čerpání']];
        try { $this->calculate(); self::fail('Unresolved prior-period effect must block'); }
        catch (NonprofitTaxAssessmentException $e) { self::assertSame('nonprofit_tax_review_required', $e->errorCode); }
        $this->assessment['funding_sources'][0]['prior_period_review'] = ['evidence' => 'Vlastní doložené daňové rozhodnutí a doklady',
            'legal_basis' => 'Individuálně doložené posouzení ZDP §23', 'adjustment_cents' => -1234, 'included_in_bridge' => true];
        $out = $this->calculate();
        self::assertSame(0, $out['assessed_result_cents']);
        self::assertSame(-1234, $out['external_adjustments'][0]['adjustment_cents']);
        self::assertFalse($out['external_adjustments'][0]['included_in_bridge']);
    }

    public function testPoolCannotClaimSameRefundTwice(): void
    {
        $this->row('grant', 'revenue', 10000, 1, 'public_operating_grant', ['facts' => ['public_budget' => true]]);
        $this->row('refund', 'revenue', -1000, 1, 'public_operating_grant', ['facts' => ['public_budget' => true]]);
        $this->assessment['funding_sources'] = [['anchor' => 'grant', 'members' => ['grant', 'refund', 'refund'], 'evidence' => 'Duplicitní člen']];
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->calculate();
    }
}
