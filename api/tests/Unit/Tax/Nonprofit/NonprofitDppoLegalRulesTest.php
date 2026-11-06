<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoLegalRules;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NonprofitDppoLegalRulesTest extends TestCase
{
    private function profile(string $kind='narrow_vpp',string $type='association'):array
    {return ['kind'=>$kind,'entity_type'=>$type,'main_activity_nonbusiness'=>true,'evidence'=>'Synthetic founding deed','legal_basis'=>'zdp_17a'];}

    public static function bands():array
    {return [['narrow_vpp','association','standard'],['broad_vpp','public_university','university_research'],['broad_vpp','public_research_institution','university_research'],['broad_vpp','private_university_ops','university_research'],['broad_vpp','private_university_ustav','university_research'],['broad_vpp','public_benefit_company','standard'],['broad_vpp','ustav','standard'],['broad_vpp','healthcare_provider','ineligible'],['narrow_vpp','municipality','ineligible'],['narrow_vpp','region','ineligible'],['narrow_vpp','professional_chamber','ineligible'],['narrow_vpp','business_interest_body','ineligible'],['narrow_vpp','employer_organisation','standard'],['svj','svj','ineligible'],['non_vpp','commercial_corporation','ineligible']];}

    #[DataProvider('bands')]
    public function testReductionBandFollowsDocumentedLegalEntity(string $kind,string $type,string $band):void
    {self::assertSame($band,NonprofitDppoLegalRules::profile($this->profile($kind,$type))['reduction_band']);}

    public static function conflicts():array
    {return [['narrow_vpp','svj'],['broad_vpp','association'],['narrow_vpp','public_university'],['svj','association'],['narrow_vpp','commercial_corporation'],['narrow_vpp','family_foundation'],['narrow_vpp','unknown']];}

    #[DataProvider('conflicts')]
    public function testLegalEntityCannotBeRelabelledToObtainVppBenefits(string $kind,string $type):void
    {$this->expectException(TaxReturnException::class);NonprofitDppoLegalRules::profile($this->profile($kind,$type));}

    public function testAnActiveOrPreviouslyConfirmedSvjModuleConflictsWithVpp():void
    {$this->expectException(TaxReturnException::class);NonprofitDppoLegalRules::profile($this->profile(),true);}

    public function testSvjBankWithholdingOnlyDoesNotRequireReturn():void
    {self::assertFalse(NonprofitDppoLegalRules::obligation('svj',[['kind'=>'revenue','amount_cents'=>7900,'classification'=>['regime'=>'subject','method'=>'final_withholding']]],0)['required']);}

    public function testOneHalerOfTaxableSvjInterestRequiresReturnRegardlessOfZeroTax():void
    {self::assertTrue(NonprofitDppoLegalRules::obligation('svj',[['kind'=>'revenue','amount_cents'=>1,'classification'=>['regime'=>'subject','method'=>'return']]],0)['required']);}

    public function testVppUnusedSavingRequiresReturnWithoutTaxableIncome():void
    {self::assertTrue(NonprofitDppoLegalRules::obligation('narrow_vpp',[],100)['required']);}

    public function testOrdinaryNonVppDoesNotReceiveSection38mbException():void
    {self::assertTrue(NonprofitDppoLegalRules::obligation('non_vpp',[],0)['required']);}

    public function testUnresolvedIncomeCannotBecomeFilingExemption():void
    {$this->expectException(TaxReturnException::class);NonprofitDppoLegalRules::obligation('svj',[['kind'=>'revenue','amount_cents'=>100,'classification'=>['regime'=>'pending','method'=>'none']]],0);}

    public static function savings():array
    {return [[300000,300000,0,63000],[300000,300000,48000,15000],[300000,300000,70000,0],[300499,300000,0,63000],[2100000,630000,0,132300],[1000,1,0,210]];}

    #[DataProvider('savings')]
    public function testActualSavingUsesSameCreditsAndFormRounding(int $base,int $reduction,int $credits,int $expected):void
    {self::assertSame($expected,NonprofitDppoLegalRules::saving($base,$reduction,$credits,2100,10000,1000)['saving_kc']);}
}
