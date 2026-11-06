<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Tax\Nonprofit;
use MyInvoice\Tests\Support\J6CalculatorFactory;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxCalculator;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use PHPUnit\Framework\TestCase;

final class NonprofitSvjTaxRulesTest extends TestCase
{
    public function testSvjBankAccountUsesFinalWithholdingWithoutFoundationFacts():void
    {
        self::assertSame('svj',NonprofitTaxRules::profile(['kind'=>'svj','evidence'=>'Synthetic SVJ articles'],['starts_on'=>'2026-01-01','ends_on'=>'2026-12-31']));
        $r=NonprofitTaxRules::revenue('svj',['title'=>'bank_interest','evidence'=>'Synthetic bank statement','facts'=>['domestic_bank_account'=>true]]);self::assertSame(['regime'=>'subject','method'=>'final_withholding','legal_basis'=>'zdp_36_5b'],$r);
    }
    public function testSvjCannotElectVppGiftExemption():void
    {$this->expectException(NonprofitTaxAssessmentException::class);NonprofitTaxRules::revenue('svj',['title'=>'gift','evidence'=>'Synthetic gift','facts'=>['claim_exemption'=>true,'qualifying_purpose'=>true,'election_evidence'=>'Synthetic election']]);}
    public function testSvjHasNoVppMembershipExemption():void
    {self::assertSame('subject',NonprofitTaxRules::revenue('svj',['title'=>'membership','evidence'=>'Synthetic payment','facts'=>['qualifying_membership'=>true]])['regime']);}
    public function testSvjCannotDeductCostsRelatedToFinalWithholdingThroughVppException():void
    {
        $snapshot=['period'=>['starts_on'=>'2026-01-01','ends_on'=>'2026-12-31'],'sources'=>[['id'=>'cost','kind'=>'expense','amount_cents'=>100,'activity_kind_id'=>1]]];
        $assessment=['profile'=>['kind'=>'svj','evidence'=>'Synthetic statutes'],'activities'=>[1=>['evidence'=>'Synthetic activity']],'decisions'=>['cost'=>['title'=>'withholding_interest_cost','evidence'=>'Synthetic bank costs','legal_basis'=>'zdp_25_1_i','deductible_cents'=>100]],'funding'=>[]];
        $this->expectException(NonprofitTaxAssessmentException::class);J6CalculatorFactory::legacy()->calculate($snapshot,$assessment);
    }
    public function testSvjPublicBudgetGrantIsNotTaxable():void
    {self::assertSame('zdp_18_2f_1',NonprofitTaxRules::revenue('svj',['title'=>'public_operating_grant','evidence'=>'Synthetic grant','facts'=>['public_budget'=>true]])['legal_basis']);}
    private function serviceAssessment(int $allowed=0,string $title='svj_service_payment'):array
    {
        $snapshot=['period'=>['starts_on'=>'2026-01-01','ends_on'=>'2026-12-31'],'sources'=>[
            ['id'=>'income','kind'=>'revenue','amount_cents'=>10000,'activity_kind_id'=>1,'svj_owner_facts'=>['kind'=>'revenue','case_id'=>1,'legal_basis'=>'zdp_18_2f_3']],
            ['id'=>'cost','kind'=>'expense','amount_cents'=>10000,'activity_kind_id'=>1,'svj_owner_facts'=>['kind'=>'expense','case_id'=>1,'legal_basis'=>'zdp_18_2f_3']]]];
        $assessment=['profile'=>['kind'=>'svj','evidence'=>'Synthetic statutes'],'activities'=>[1=>['evidence'=>'Synthetic service']],'decisions'=>[
            'income'=>['title'=>$title,'evidence'=>'Synthetic native service'],'cost'=>['title'=>'excluded_cost','evidence'=>'Synthetic native service cost','legal_basis'=>'zdp_25_1_i','deductible_cents'=>$allowed]],'funding'=>[]];
        return[$snapshot,$assessment];
    }
    public function testNativeServiceIncomeAndRelatedCostHaveSymmetricTaxTreatment():void
    {
        [$snapshot,$assessment]=$this->serviceAssessment();$r=J6CalculatorFactory::legacy()->calculate($snapshot,$assessment);
        self::assertSame(0,$r['assessed_result_cents']);self::assertSame('zdp_18_2f_3',$r['rows'][0]['classification']['legal_basis']);self::assertSame(10000,$r['cost_addbacks_cents']);
        self::assertSame(10000,(new \MyInvoice\Service\Tax\Nonprofit\NonprofitDppoClassificationBridge())->build($r)['line_cents'][100]);
    }
    public function testNativeServiceIncomeCannotBeRelabelledTaxable():void
    {[$s,$a]=$this->serviceAssessment(0,'other_taxable');$this->expectException(NonprofitTaxAssessmentException::class);J6CalculatorFactory::legacy()->calculate($s,$a);}
    public function testManualIncomeCannotClaimNativeServiceExclusion():void
    {[$s,$a]=$this->serviceAssessment();unset($s['sources'][0]['svj_owner_facts']);$this->expectException(NonprofitTaxAssessmentException::class);J6CalculatorFactory::legacy()->calculate($s,$a);}
    public function testRelatedServiceCostCannotBeDeductedAgainstOtherIncome():void
    {[$s,$a]=$this->serviceAssessment(10000);$a['decisions']['cost']['title']='cost';$this->expectException(NonprofitTaxAssessmentException::class);J6CalculatorFactory::legacy()->calculate($s,$a);}
}
