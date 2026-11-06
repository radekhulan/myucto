<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Service\Tax\Return\TaxReturnException;

final class NonprofitDppoLegalRules
{
    public const VERSION = 'cz-dppo504-2026-v1';
    public const KINDS = ['narrow_vpp','broad_vpp','non_vpp','svj'];
    public const ENTITY_TYPES = ['association','foundation','endowment_fund','church','political_party','municipality','region','professional_chamber','business_interest_body','employer_organisation','public_university','public_research_institution','private_university_ops','private_university_ustav','public_benefit_company','ustav','healthcare_provider','svj','commercial_corporation','family_foundation','public_broadcaster','health_insurance_company','other_non_vpp'];
    private const BROAD = ['public_university','public_research_institution','private_university_ops','private_university_ustav','public_benefit_company','ustav','healthcare_provider'];
    private const NON_VPP = ['commercial_corporation','family_foundation','public_broadcaster','health_insurance_company','other_non_vpp'];

    public static function profile(array $profile,bool $svjModuleEvidence=false):array
    {
        $kind=$profile['kind']??null;$type=$profile['entity_type']??null;
        if(!in_array($kind,self::KINDS,true)||!in_array($type,self::ENTITY_TYPES,true)||!is_bool($profile['main_activity_nonbusiness']??null))self::invalid();
        foreach(['evidence','legal_basis'] as $field)if(!is_string($profile[$field]??null)||trim($profile[$field])===''||mb_strlen($profile[$field])>2000)self::invalid();
        if(($type==='svj')!==($kind==='svj')||($svjModuleEvidence&&$kind!=='svj'))self::invalid();
        $vpp=in_array($kind,['narrow_vpp','broad_vpp'],true);
        if($vpp&&(!$profile['main_activity_nonbusiness']||in_array($type,self::NON_VPP,true)))self::invalid();
        if($vpp&&(($kind==='broad_vpp')!==in_array($type,self::BROAD,true)))self::invalid();
        $band='ineligible';
        if($vpp&&!in_array($type,['municipality','region','healthcare_provider','professional_chamber','business_interest_body'],true)){
            $band=in_array($type,['public_university','public_research_institution','private_university_ops','private_university_ustav'],true)?'university_research':'standard';
        }
        return ['kind'=>$kind,'entity_type'=>$type,'main_activity_nonbusiness'=>$profile['main_activity_nonbusiness'],'evidence'=>trim($profile['evidence']),'legal_basis'=>trim($profile['legal_basis']),'reduction_band'=>$band,'ruleset'=>self::VERSION];
    }

    public static function obligation(string $kind,array $rows,int $unusedReductionAddbackKc):array
    {
        if(!in_array($kind,self::KINDS,true)||$unusedReductionAddbackKc<0)self::invalid();
        $taxable=[];
        foreach($rows as $row){
            if(!is_array($row)||!in_array($row['kind']??null,['revenue','expense'],true)||!is_int($row['amount_cents']??null))self::invalid();
            if($row['kind']!=='revenue')continue;
            $regime=$row['classification']['regime']??null;$method=$row['classification']['method']??null;
            if(!(($regime==='subject'&&in_array($method,['return','final_withholding'],true))||(in_array($regime,['out_of_scope','exempt'],true)&&$method==='none')))self::invalid();
            if($regime==='subject'&&$method==='return'&&$row['amount_cents']!==0)$taxable[]=$row['id']??null;
        }
        $vpp=in_array($kind,['narrow_vpp','broad_vpp'],true);
        $required=$kind==='non_vpp'||$taxable!==[]||($vpp&&$unusedReductionAddbackKc>0);
        return ['required'=>$required,'legal_basis'=>$kind==='svj'?'zdp_38mb_b':($vpp?'zdp_38mb_a':'zdp_38m'),'taxable_sources'=>$taxable,'unused_reduction_addback_kc'=>$unusedReductionAddbackKc];
    }

    public static function saving(int $baseKc,int $reductionKc,int $creditsKc,int $rateNumerator,int $rateDenominator,int $roundingKc):array
    {
        foreach([$baseKc,$reductionKc,$creditsKc] as $amount)if($amount<0||$amount>99999999999999)self::invalid();
        if($reductionKc>$baseKc||$rateNumerator<=0||$rateDenominator<=0||$rateNumerator>$rateDenominator||$roundingKc<1)self::invalid();
        $tax=static function(int $base)use($rateNumerator,$rateDenominator,$roundingKc,$creditsKc):int{
            $rounded=$base-$base%$roundingKc;
            $scaled=bcmul((string)$rounded,(string)$rateNumerator,0);
            $whole=bcdiv($scaled,(string)$rateDenominator,0);
            $up=bccomp(bcmul(bcmod($scaled,(string)$rateDenominator,0),'2',0),(string)$rateDenominator,0)>=0;
            return max(0,(int)$whole+(int)$up-$creditsKc);
        };
        $without=$tax($baseKc);$with=$tax($baseKc-$reductionKc);
        return ['base_before_reduction_kc'=>$baseKc,'reduction_kc'=>$reductionKc,'credits_entitlement_kc'=>$creditsKc,'tax_without_reduction_kc'=>$without,'tax_with_reduction_kc'=>$with,'saving_kc'=>$without-$with,'rate_numerator'=>$rateNumerator,'rate_denominator'=>$rateDenominator,'rounding_kc'=>$roundingKc];
    }

    private static function invalid():never
    {throw new TaxReturnException('nonprofit_dppo_legal_profile_invalid','Právní postavení a daňové zacházení musí odpovídat doložené právní formě a účelu činnosti.',422);}
}
