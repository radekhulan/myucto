<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Tax\Nonprofit;
use MyInvoice\Tests\Support\J6CalculatorFactory;
use MyInvoice\Service\Tax\Nonprofit\{NonprofitTaxCalculator,NonprofitTaxAssessmentException};
use PHPUnit\Framework\TestCase;
final class NonprofitSvjClassificationTest extends TestCase
{
    private function data(string $basis='zdp_18_2f_3',bool $manual=false):array
    {
        $period=$manual?['starts_on'=>'2023-01-01','ends_on'=>'2023-12-31']:['starts_on'=>'2026-01-01','ends_on'=>'2026-12-31'];
        $snapshot=['period'=>$period,'legal_profile'=>['profile'=>['kind'=>'svj']],'sources'=>[['id'=>'income','kind'=>'revenue','amount_cents'=>10000,'activity_kind_id'=>1,'svj_owner_facts'=>['kind'=>'revenue','legal_basis'=>$basis]]]];
        $decision=['title'=>$basis==='zdp_18_2f_2'?'svj_management_payment':'svj_service_payment','evidence'=>'Synthetic exact legal source','legal_basis'=>'Synthetic applicable section18 legal opinion'];
        $a=['profile'=>['kind'=>'svj','evidence'=>'Synthetic confirmed legal identity'],'activities'=>[1=>['evidence'=>'Synthetic documented purpose']],'decisions'=>['income'=>$decision]];
        if($manual){$a['profile']=['kind'=>'documented','entity_kind'=>'svj','evidence'=>'Synthetic historical SVJ identity'];$a['method']=['mode'=>'documented_manual','valid_from'=>$period['starts_on'],'valid_to'=>$period['ends_on'],'legal_basis'=>'Synthetic historical law','evidence'=>'Synthetic signed historical opinion'];$a['decisions']['income']+=['final_regime'=>'out_of_scope','final_method'=>'none'];}
        return[$snapshot,$a];
    }
    public function testManualServiceRegimeCannotOverrideItsCanonicalLegalNature():void
    {[$s,$a]=$this->data(manual:true);$a['decisions']['income']['final_regime']='subject';$a['decisions']['income']['final_method']='return';$this->expectException(NonprofitTaxAssessmentException::class);J6CalculatorFactory::legacy()->calculate($s,$a);}
    public function testManualManagementRegimeCannotOverrideItsCanonicalLegalNature():void
    {[$s,$a]=$this->data('zdp_18_2f_2',true);$a['decisions']['income']['final_regime']='subject';$a['decisions']['income']['final_method']='return';$this->expectException(NonprofitTaxAssessmentException::class);J6CalculatorFactory::legacy()->calculate($s,$a);}
    public function testAutomaticAndHistoricalManagementUseTheSameCanonicalClassification():void
    {foreach([false,true]as$manual){[$s,$a]=$this->data('zdp_18_2f_2',$manual);$out=J6CalculatorFactory::legacy()->calculate($s,$a);self::assertSame(['regime'=>'out_of_scope','method'=>'none','legal_basis'=>'zdp_18_2f_2'],$out['rows'][0]['classification']);self::assertSame(0,$out['assessed_result_cents']);}}
    public function testBankInterestAndSanctionsCannotUseAContributionTitle():void
    {[$s,$a]=$this->data('zdp_18_2f_2');unset($s['sources'][0]['svj_owner_facts']);$this->expectException(NonprofitTaxAssessmentException::class);J6CalculatorFactory::legacy()->calculate($s,$a);}
    public function testFundingDoesNotSubtractAnAlreadyExcludedManagementCostAgain():void
    {
        [$s,$a]=$this->data('zdp_18_2f_2');$s['sources'][]=['id'=>'management','kind'=>'expense','amount_cents'=>7000,'activity_kind_id'=>1,'svj_owner_facts'=>['kind'=>'expense','legal_basis'=>'zdp_18_2f_2','related_out_of_scope_cents'=>7000,'covered_cost_cents'=>5000]];$s['sources'][]=['id'=>'taxable-cost','kind'=>'expense','amount_cents'=>3000,'activity_kind_id'=>2];$a['activities'][2]=['evidence'=>'Synthetic separately measured work on taxable income'];$a['decisions']['management']=['title'=>'excluded_cost','deductible_cents'=>0,'legal_basis'=>'zdp_25_1i','evidence'=>'Synthetic exact management purpose'];$a['decisions']['taxable-cost']=['title'=>'cost','deductible_cents'=>3000,'legal_basis'=>'zdp_24_1','evidence'=>'Synthetic independent taxable activity'];$out=J6CalculatorFactory::legacy()->calculate($s,$a);self::assertSame(-3000,$out['assessed_result_cents']);self::assertSame(3000,$out['rows'][2]['deductible_cents']);self::assertSame(0,$out['rows'][1]['funded_excluded_cents']);
    }
}
