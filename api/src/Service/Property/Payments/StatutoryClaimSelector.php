<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;
use MyInvoice\Service\Property\PropertyInstant;

final class StatutoryClaimSelector
{
    public static function preview(array $payment,array $claims): array
    {
        $amount=StatutoryAllocationPolicy::cents($payment['amount_cents']??null);
        $at=PropertyInstant::normalize($payment['received_at']??null);
        $debtor=$payment['debtor_id']??null;
        $review=static fn(string $reason):array=>['status'=>'review_required','reason'=>$reason,'allocations'=>[],'allocated_cents'=>'0','credit_cents'=>'0','unallocated_cents'=>$amount];
        if (!is_int($debtor)||$debtor<=0) return $review('debtor_unproven');
        if (($payment['payer_id']??null)!==$debtor && (empty($payment['third_party']['evidence']) || !in_array($payment['third_party']['basis']??null,['debtor_consent','guarantor','security_provider'],true))) return $review('third_party_unproven');
        $pool=[]; $outstanding=[];
        foreach ($claims as $claim) {
            $id=$claim['id']??null;
            if (!is_int($id)||$id<=0||isset($pool[$id])) throw new \InvalidArgumentException('duplicate_or_invalid_claim');
            $pool[$id]=$claim;
            $outstanding[$id]='0';
            foreach ($claim['components'] as $value) $outstanding[$id]=bcadd($outstanding[$id],StatutoryAllocationPolicy::cents($value),0);
            StatutoryAllocationPolicy::cents($claim['eligible_cents']??null);
        }
        $pool=array_filter($pool,static fn(array $claim):bool=>in_array($debtor,$claim['debtor_ids'],true)&&bccomp($claim['eligible_cents'],'0',0)>0);
        $choice=$payment['choice']??null;
        if ($choice!==null && (empty($choice['evidence'])||!is_array($choice['claim_ids']??null)||$choice['claim_ids']===[]||count(array_unique($choice['claim_ids']))!==count($choice['claim_ids']))) return $review('debtor_choice_unproven');
        if ($choice!==null) foreach ($choice['claim_ids'] as $id) if (!isset($pool[$id])) return $review('debtor_choice_ineligible');
        $remaining=$amount; $allocations=[]; $choiceIndex=0;
        while (bccomp($remaining,'0',0)>0 && $pool!==[]) {
            if ($choice!==null) {
                $id=$choice['claim_ids'][$choiceIndex++]??null;
                if ($id===null) break;
            } else {
                $eligible=array_filter($pool,static fn(array $claim):bool=>$claim['damages_for']===null||(isset($outstanding[$claim['damages_for']])&&bccomp($outstanding[$claim['damages_for']],'0',0)===0));
                if ($eligible===[]) return $review('underlying_claim_unresolved');
                $reminded=array_filter($eligible,static fn(array $claim):bool=>$claim['reminder_at']!==null&&PropertyInstant::normalize($claim['reminder_at'])<$at);
                if ($reminded!==[]) $eligible=$reminded;
                if (count($eligible)>1) {
                    $allUnsecured=true;
                    foreach($eligible as $claim) if(($claim['security']['kind']??null)!=='unsecured'||empty($claim['security']['evidence']))$allUnsecured=false;
                    if(!$allUnsecured) {
                        $comparison=$payment['security_comparison']??null;$seen=[];
                        if(!is_array($comparison)||empty($comparison['evidence'])||!is_array($comparison['tiers']??null)||!array_is_list($comparison['tiers']))return $review('security_comparison_required');
                        foreach($comparison['tiers'] as $tier) {
                            if(!is_array($tier)||!array_is_list($tier)||$tier===[])return $review('security_comparison_required');
                            foreach($tier as $key) {
                                if(!is_int($key)||isset($seen[$key]))return $review('security_comparison_required');
                                $seen[$key]=true;
                            }
                        }
                        foreach($claims as $candidate) if(isset($seen[$candidate['id']])&&\MyInvoice\Service\Property\PropertyInput::hash($comparison['facts'][$candidate['id']]??[])!==\MyInvoice\Service\Property\PropertyInput::hash($candidate['security']))return $review('security_comparison_required');
                        foreach($eligible as $key=>$candidate)if(!isset($seen[$key]))return $review('security_comparison_required');
                        foreach($comparison['tiers'] as $tier) {
                            $selected=array_intersect_key($eligible,array_fill_keys($tier,true));
                            if($selected!==[]){$eligible=$selected;break;}
                        }
                    }
                    $due=min(array_column($eligible,'due_date'));
                    $eligible=array_filter($eligible,static fn(array $claim):bool=>$claim['due_date']===$due);
                }
                if (count($eligible)!==1) return $review('ambiguous_priority');
                $id=array_key_first($eligible);
            }
            $claim=$pool[$id];
            $cap=bccomp($remaining,$claim['eligible_cents'],0)<0?$remaining:$claim['eligible_cents'];
            try { $parts=StatutoryAllocationPolicy::allocate($cap,$claim['components'],$payment['payment_date'],$claim['legal']); }
            catch (\DomainException $e) { return $review($e->getMessage()); }
            $allocations[]=['claim_id'=>$id,'unit_id'=>$claim['unit_id'],'selection'=>$choice!==null?'debtor_choice':'cz_1933',...$parts];
            $remaining=bcsub($remaining,$parts['allocated_cents'],0);
            $outstanding[$id]=bcsub($outstanding[$id],$parts['allocated_cents'],0); unset($pool[$id]);
        }
        $entitlement=$payment['credit_entitlement']??null;
        if (bccomp($remaining,'0',0)>0 && (empty($entitlement['party_ids'])||empty($entitlement['evidence']))) {
            // Návrh z banky potřebuje vědět, kolik jde přiřadit bez rozhodnutí o kreditu.
            return ['allocated_cents'=>bcsub($amount,$remaining,0),'credit_cents'=>$remaining]+$review('credit_entitlement_required');
        }
        return ['status'=>'ready','allocations'=>$allocations,'allocated_cents'=>bcsub($amount,$remaining,0),'credit_cents'=>$remaining,'unallocated_cents'=>'0',
            'credit_entitlement'=>bccomp($remaining,'0',0)>0?$entitlement:null];
    }
}
