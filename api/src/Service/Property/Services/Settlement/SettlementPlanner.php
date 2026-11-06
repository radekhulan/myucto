<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Settlement;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
use MyInvoice\Service\Property\Payments\ReliefCalculation;
final class SettlementPlanner
{
    public static function plan(SettlementDocument $document,array $current,array $request):SettlementDispositionReceipt
    {
        return SettlementDispositionReceipt::fromEvidence($document,$current,$request);
    }
    public static function calculate(SettlementDocument $document,array $current,array $request):array
    {
        $current=SettlementEvidence::detached($current);$request=SettlementEvidence::detached($request);$doc=$document->snapshot();SettlementEvidence::fields($current,['supplier_id','segment_key','as_of','revision','application_id','origins','allocations','cost','parties']);
        if($current['supplier_id']!==$doc['supplier_id']||$current['segment_key']!==$doc['segment_key'])SettlementEvidence::reject('current_scope');
        PropertyInput::id($current['revision'],'revision');if($current['application_id']!==null)SettlementEvidence::reject('already_applied');
        $now=PropertyInstant::normalize($current['as_of']);if($now<$doc['known_at']||$now<$doc['cutoff_at'])SettlementEvidence::reject('current_before_document');
        SettlementEvidence::fields($request,['accounting_date','evidence','advances','reliefs','opening_credits','new_claim']);SettlementEvidence::date($request['accounting_date']);PropertyInput::text($request['evidence'],'disposition.evidence',4000);
        if($request['accounting_date']<substr($doc['cutoff_at'],0,10))SettlementEvidence::reject('backdated_application');
        $parties=[];foreach(PropertyInput::list($current['parties'],'parties',10000) as $party){SettlementEvidence::fields($party,['id','supplier_id']);PropertyInput::id($party['id'],'party_id');if($party['supplier_id']!==$doc['supplier_id']||isset($parties[$party['id']]))SettlementEvidence::reject('foreign_party');$parties[$party['id']]=true;}
        $originals=[];foreach($doc['origins'] as $source)$originals[$source['kind'].':'.$source['id']]=$source;
        $origins=[];foreach(PropertyInput::list($current['origins'],'current.origins',10000) as $origin){
            SettlementEvidence::fields($origin,['source','version','financial_head','party_head','prior_reduction_cents','advance_available_cents','active']);$source=$origin['source'];$key=SettlementEvidence::origin($source,$doc['supplier_id'],$doc['unit_id'],$doc['service_id']);
            if(isset($origins[$key])||!isset($originals[$key])||PropertyInput::hash($source)!==PropertyInput::hash($originals[$key]))SettlementEvidence::reject('source_changed');
            PropertyInput::id($origin['version'],'origin.version');SettlementEvidence::hash($origin['financial_head']);SettlementEvidence::hash($origin['party_head']);
            if($origin['active']!==true)SettlementEvidence::reject('inactive_source');
            if($origin['prior_reduction_cents']!=='0')SettlementEvidence::reject('prior_correction_review');
            SettlementEvidence::cents($origin['advance_available_cents']);if(bccomp($origin['advance_available_cents'],$source['prescribed_cents'],0)<0)SettlementEvidence::reject('advance_capacity');$origins[$key]=$origin;
        }
        if(count($origins)!==count($originals))SettlementEvidence::reject('missing_origin');ksort($origins,SORT_STRING);
        $cost=$current['cost'];SettlementEvidence::fields($cost,['source','version','available_cents']);PropertyInput::id($cost['version'],'cost.version');SettlementEvidence::cents($cost['available_cents']);
        if(PropertyInput::hash($cost['source'])!==PropertyInput::hash($doc['cost']))SettlementEvidence::reject('cost_changed');if(bccomp($cost['available_cents'],$doc['actual_cents'],0)<0)SettlementEvidence::reject('cost_capacity');
        $allocations=[];$paid=[];$totalPaid='0';foreach($originals as $origin)$totalPaid=bcadd($totalPaid,$origin['carried_paid_cents'],0);
        foreach(PropertyInput::list($current['allocations'],'current.allocations',10000) as $a){
            SettlementEvidence::fields($a,['id','origin_key','source_hash','amount_cents','operation_version','financial_head','relieved_cents','active','accounting_date']);PropertyInput::id($a['id'],'allocation_id');PropertyInput::id($a['operation_version'],'operation_version');SettlementEvidence::hash($a['source_hash']);SettlementEvidence::hash($a['financial_head']);SettlementEvidence::cents($a['amount_cents']);SettlementEvidence::cents($a['relieved_cents']);SettlementEvidence::date($a['accounting_date']);
            if(!is_bool($a['active'])||$a['amount_cents']==='0'||isset($allocations[$a['id']])||!isset($origins[$a['origin_key']]))SettlementEvidence::reject('allocation_origin');
            if($a['relieved_cents']!=='0')SettlementEvidence::reject('prior_correction_review');
            if($a['accounting_date']>$request['accounting_date'])SettlementEvidence::reject('backdated_payment');
            $available=$a['active']?$a['amount_cents']:'0';$allocations[$a['id']]=$a+['available_cents'=>$available];$key=$a['origin_key'];$paid[$key]=bcadd($paid[$key]??'0',$available,0);$totalPaid=bcadd($totalPaid,$available,0);
        }
        ksort($allocations,SORT_NUMERIC);$cutoffIds=[];$changed=[];
        foreach($doc['cutoff_allocations'] as $a){$id=$a['id'];$live=$allocations[$id]??null;
            if(!$live||$live['origin_key']!==$a['origin_key']||$live['source_hash']!==$a['source_hash']||$live['amount_cents']!==$a['amount_cents'])SettlementEvidence::reject('cutoff_source_changed');$cutoffIds[$id]=true;if(!$live['active'])$changed[]=$id;
        }
        $advances=[];$applied='0';foreach(PropertyInput::list($request['advances'],'advances',10000) as $a){SettlementEvidence::fields($a,['origin_key','applied_cents']);SettlementEvidence::cents($a['applied_cents']);$key=$a['origin_key'];if(isset($advances[$key])||!isset($origins[$key])||bccomp($a['applied_cents'],$originals[$key]['prescribed_cents'],0)>0)SettlementEvidence::reject('advance_origin');$advances[$key]=$a['applied_cents'];$applied=bcadd($applied,$a['applied_cents'],0);}
        if(count($advances)!==count($origins)||$applied!==$doc['amounts']['advance_applied_cents'])SettlementEvidence::reject('advance_margin');
        $openingCredits=[];$openingAmounts=[];
        foreach(PropertyInput::list($request['opening_credits'],'opening_credits',10000) as $credit){SettlementEvidence::fields($credit,['origin_key','amount_cents','entitlement']);SettlementEvidence::cents($credit['amount_cents']);$key=$credit['origin_key'];$source=$originals[$key]??null;
            if(!$source||$source['kind']!=='opening_component'||isset($openingAmounts[$key])||$credit['amount_cents']==='0'||bccomp($credit['amount_cents'],$source['carried_paid_cents'],0)>0)SettlementEvidence::reject('opening_credit_capacity');
            $credit['entitlement']=SettlementEvidence::entitlement($credit['entitlement'],array_keys($parties));$openingCredits[$key]=$credit;$openingAmounts[$key]=$credit['amount_cents'];
        }
        $costParts=isset($doc['cost']['sources'])?array_map(fn($part)=>$part+['remaining_cents'=>$part['amount_cents']],$doc['cost']['sources']):null;$movements=[];$reductions=[];$required=[];$totals=['advance_applied_cents'=>$applied,'new_claim_cents'=>$doc['amounts']['new_claim_cents'],'reduction_cents'=>'0','cancelled_unpaid_cents'=>'0','released_allocations_cents'=>'0','opening_paid_credit_cents'=>'0','new_credit_cents'=>'0','remaining_old_claim_cents'=>'0','retained_paid_cents'=>'0'];
        foreach($origins as $key=>$origin){$source=$origin['source'];$reduce=bcsub($source['prescribed_cents'],$advances[$key],0);
            $baselineCredit=$openingAmounts[$key]??'0';$debtReduction=bcsub($reduce,$baselineCredit,0);if(bccomp($debtReduction,'0',0)<0)SettlementEvidence::reject('opening_credit_capacity');$calc=ReliefCalculation::calculate($source['receivable_cents'],'0',$paid[$key]??'0',$debtReduction);$required[$key]=$calc['relief_cents'];
            self::costMovement($movements,'advance_application',$key,$advances[$key],$source['advance_line'],$doc['cost']['line'],$costParts);
            if($debtReduction!=='0')self::movement($movements,'principal_reduction',$key,$debtReduction,$source['advance_line'],$source['receivable_line']);
            $unpaid=bcsub($debtReduction,$calc['relief_cents'],0);
            $reductions[]=['origin_key'=>$key,'debt_id'=>$source['debt_id'],'advance_reduction_cents'=>$reduce,'reduction_cents'=>$debtReduction,'cancelled_unpaid_cents'=>$unpaid,'relief_cents'=>$calc['relief_cents'],'remaining_old_claim_cents'=>$calc['unpaid_cents'],'retained_paid_cents'=>bcadd($calc['paid_cents'],bcsub($source['carried_paid_cents'],$baselineCredit,0),0)];
            foreach(['reduction_cents'=>$reduce,'cancelled_unpaid_cents'=>$unpaid,'released_allocations_cents'=>$calc['relief_cents'],'opening_paid_credit_cents'=>$baselineCredit,'new_credit_cents'=>bcadd($baselineCredit,$calc['relief_cents'],0),'remaining_old_claim_cents'=>$calc['unpaid_cents'],'retained_paid_cents'=>bcadd($calc['paid_cents'],bcsub($source['carried_paid_cents'],$baselineCredit,0),0)] as $name=>$amount)$totals[$name]=bcadd($totals[$name],$amount,0);
        }
        $reliefs=[];$credits=[];$released=[];
        foreach(PropertyInput::list($request['reliefs'],'reliefs',10000) as $relief){SettlementEvidence::fields($relief,['allocation_id','amount_cents','entitlement']);PropertyInput::id($relief['allocation_id'],'allocation_id');SettlementEvidence::cents($relief['amount_cents']);$id=$relief['allocation_id'];$a=$allocations[$id]??null;
            if(!$a||isset($reliefs[$id])||$relief['amount_cents']==='0'||bccomp($relief['amount_cents'],$a['available_cents'],0)>0)SettlementEvidence::reject('relief_capacity');
            $ent=SettlementEvidence::entitlement($relief['entitlement'],array_keys($parties));$key=$a['origin_key'];$released[$key]=bcadd($released[$key]??'0',$relief['amount_cents'],0);
            self::movement($movements,'allocation_release',$key,$relief['amount_cents'],$originals[$key]['receivable_line'],$ent['line'],$id);
            $reliefs[$id]=['allocation_id'=>$id,'origin_key'=>$key,'amount_cents'=>$relief['amount_cents'],'source_hash'=>$a['source_hash']];$credits[]=$ent+['amount_cents'=>$relief['amount_cents'],'allocation_id'=>$id,'unit_id'=>$doc['unit_id']];
        }
        foreach($required as $key=>$amount)if(($released[$key]??'0')!==$amount)SettlementEvidence::reject('relief_margin');ksort($reliefs,SORT_NUMERIC);
        foreach($openingCredits as $key=>$credit){$ent=$credit['entitlement'];self::movement($movements,'opening_paid_credit',$key,$credit['amount_cents'],$originals[$key]['advance_line'],$ent['line']);$credits[]=$ent+['amount_cents'=>$credit['amount_cents'],'allocation_id'=>null,'origin_key'=>$key,'opening_advance_component_id'=>$originals[$key]['opening_advance_component_id'],'unit_id'=>$doc['unit_id']];}
        $claim=null;if($totals['new_claim_cents']!=='0'){
            if(!is_array($request['new_claim']))SettlementEvidence::reject('claim_title_required');$claim=SettlementEvidence::entitlement($request['new_claim'],array_keys($parties));
            if($claim['party_ids']!==$doc['recipient_ids'])SettlementEvidence::reject('claim_recipient_changed');$claim+=['amount_cents'=>$totals['new_claim_cents'],'unit_id'=>$doc['unit_id']];self::costMovement($movements,'new_claim',$doc['segment_key'],$claim['amount_cents'],$claim['line'],$doc['cost']['line'],$costParts);
        }elseif($request['new_claim']!==null)SettlementEvidence::reject('unexpected_new_claim');
        $balance=bcsub(bcadd($totals['remaining_old_claim_cents'],$totals['new_claim_cents'],0),$totals['new_credit_cents'],0);
        if($balance!==bcsub($doc['actual_cents'],$totalPaid,0)||bcadd($totals['cancelled_unpaid_cents'],$totals['new_credit_cents'],0)!==$totals['reduction_cents'])SettlementEvidence::reject('reconciliation');
        if($costParts!==null)foreach($costParts as $part)if($part['remaining_cents']!=='0')SettlementEvidence::reject('composite_cost_margin');$lines=self::journal($movements)['lines'];
        return ['version'=>1,'supplier_id'=>$doc['supplier_id'],'segment_key'=>$doc['segment_key'],'document_hash'=>$document->hash(),'document'=>$doc,'current_hash'=>PropertyInput::hash($current),'current'=>$current,'request_hash'=>PropertyInput::hash($request),'request'=>$request,'current_paid_cents'=>$totalPaid,'additional_allocation_ids'=>array_values(array_diff(array_keys($allocations),array_keys($cutoffIds))),'changed_cutoff_allocation_ids'=>$changed,'totals'=>$totals,'movements'=>$movements,'lines'=>$lines,'reductions'=>$reductions,'reliefs'=>array_values($reliefs),'opening_credits'=>array_values($openingCredits),'credits'=>$credits,'new_claim'=>$claim,'coverage'=>['scope'=>'documented_czk_segment_plan','write_authority'=>false,'db_current_proof'=>false,'complete_s8'=>false]];
    }
    public static function journal(array $movements):array
    {
        $lines=[];$starts=[];foreach($movements as $index=>$movement){$starts[$index]=count($lines);$lines[]=$movement['debit']+['side'=>'debit','amount'=>bcdiv($movement['amount_cents'],'100',2)];if(isset($movement['cost_credits'])){$sum='0';foreach($movement['cost_credits'] as $part){$sum=bcadd($sum,$part['amount_cents'],0);$lines[]=$part['line']+['side'=>'credit','amount'=>bcdiv($part['amount_cents'],'100',2)];}if($sum!==$movement['amount_cents'])SettlementEvidence::reject('composite_cost_margin');}else $lines[]=$movement['credit']+['side'=>'credit','amount'=>bcdiv($movement['amount_cents'],'100',2)];}return ['lines'=>$lines,'starts'=>$starts];
    }
    private static function costMovement(array &$out,string $kind,string $origin,string $amount,array $debit,array $credit,?array &$parts):void
    {
        if($amount==='0')return;if($parts===null){self::movement($out,$kind,$origin,$amount,$debit,$credit);return;}$remaining=$amount;$credits=[];foreach($parts as &$part){if($remaining==='0')break;if($part['remaining_cents']==='0')continue;$take=bccomp($remaining,$part['remaining_cents'],0)<0?$remaining:$part['remaining_cents'];$part['remaining_cents']=bcsub($part['remaining_cents'],$take,0);$remaining=bcsub($remaining,$take,0);$credits[]=['source_id'=>$part['source_id'],'amount_cents'=>$take,'line'=>$part['line']];}unset($part);if($remaining!=='0')SettlementEvidence::reject('composite_cost_capacity');self::movement($out,$kind,$origin,$amount,$debit,$credits[0]['line']);$out[array_key_last($out)]['cost_credits']=$credits;
    }
    private static function movement(array &$out,string $kind,string $origin,string $amount,array $debit,array $credit,?int $allocation=null):void
    {
        if($amount==='0')return;$out[]=['kind'=>$kind,'origin_key'=>$origin,'allocation_id'=>$allocation,'amount_cents'=>$amount,'debit'=>$debit,'credit'=>$credit];
    }
}
