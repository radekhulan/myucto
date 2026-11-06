<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Service\Tax\Return\TaxReturnException;

final class NonprofitDppoComputation
{
    public static function validate(array $data,array $inputs):array
    {
        $context=$data['nonprofit']??null;
        if(!is_array($context)||($context['schema']??null)!==NonprofitDppoLegalRules::VERSION||!is_string($context['proof_hash']??null))self::invalid();
        $hash=$context['proof_hash'];unset($context['proof_hash']);if(!hash_equals($hash,NonprofitTaxRules::hash($context)))self::invalid();
        $elections=$inputs['nonprofit']??[];if(!is_array($elections)||array_diff(array_keys($elections),['reduction_kc','reduction_evidence','documented_lines','adjustments'])!==[]||!hash_equals($context['elections_hash']??'',NonprofitTaxRules::hash($elections)))self::invalid();
        if(($inputs['manual_increase_items']??[])!==[]||($inputs['manual_decrease_items']??[])!==[])self::invalid();
        if(!is_array($context['legal_profile']['profile']??null)||!is_array($context['assessment']['result']??null)||!is_int($context['assessment']['id']??null)||$context['assessment']['id']<=0)self::invalid();
        $profile=NonprofitDppoLegalRules::profile($context['legal_profile']['profile']);
        if(($context['assessment']['result']['profile']??null)!==$profile['kind'])self::invalid();
        $bridge=(new NonprofitDppoClassificationBridge())->build($context['assessment']['result'],$elections['documented_lines']??[]);
        if(NonprofitTaxRules::hash($bridge)!==NonprofitTaxRules::hash($context['bridge']??[]))self::invalid();
        $lines=$bridge['line_cents']+[20=>0,30=>0,50=>0,62=>0,111=>0,112=>0,140=>0,150=>0,160=>0,162=>0];
        foreach($context['adjustments']??[]as$adjustment){
            if(!is_array($adjustment)||!in_array($adjustment['line']??null,[20,30,40,50,62,100,101,109,110,111,112,120,140,150,160,162],true)||!is_int($adjustment['amount_cents']??null)||!is_array($adjustment['proof']??null))self::invalid();
            $lines[$adjustment['line']]+=$adjustment['amount_cents'];
        }
        $addback=$context['unused_reduction_addback_kc']??null;if(!is_int($addback)||$addback<0)self::invalid();$lines[30]+=$addback*100;
        $claim=$elections['reduction_kc']??0;if(!is_int($claim)||$claim<0)self::invalid();
        if($claim>0){if($profile['reduction_band']==='ineligible')self::invalid();NonprofitTaxRules::evidence($elections['reduction_evidence']??null);}
        if(in_array($profile['kind'],['narrow_vpp','broad_vpp'],true)&&((float)($inputs['donations']??0)>0||($inputs['donation_items']??[])!==[]))throw new TaxReturnException('nonprofit_dppo_donation_ineligible','Veřejně prospěšný poplatník nemůže uplatnit odpočet podle § 20 odst. 8.',422);
        return $context+['proof_hash'=>$hash,'line_cents'=>$lines,'reduction_claim_kc'=>$claim,'reduction_band'=>$profile['reduction_band'],'filing_obligation'=>NonprofitDppoLegalRules::obligation($profile['kind'],$context['assessment']['result']['rows'],$addback)];
    }

    public static function seal(array $context):array
    {unset($context['proof_hash']);return$context+['proof_hash'=>NonprofitTaxRules::hash($context)];}

    private static function invalid():never
    {throw new TaxReturnException('nonprofit_dppo_inputs_invalid','Výpočet 504 vyžaduje aktuální potvrzené posouzení, právní profil a konkrétní průkazné úpravy bez opakovaného uplatnění.',422);}
}
