<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Receivables;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Receivables\PartyEventRepository;
use MyInvoice\Service\Property\PropertyInstant;
final class PropertyLiabilityProjection
{
    public function __construct(private readonly Connection $db){}
    public function at(int $supplier,array $debts,string $asOf,string $knownAt,bool $currentRead=false):array
    {
        $events=(new PartyEventRepository($this->db))->history($supplier,$currentRead);$out=[];
        foreach($debts as $debt)$out[$debt['id']]=self::project($debt,$events,$asOf,$knownAt);
        return $out;
    }
    public function forPayment(int $supplier,string $at,int $debtor,array $wanted):array
    {
        $at=PropertyInstant::normalize($at);$events=(new PartyEventRepository($this->db))->history($supplier,true);
        if($events===[])return [];
        $financial=(new \MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository($this->db))->read($supplier,true);$known=PartyEventRepository::now();$affected=[];
        foreach($events as $event)foreach($event['snapshot']['debts'] as $part)if(in_array($part['debt_id'],$wanted,true)||array_any($part['groups'],fn($g)=>$g['role']==='debtor'&&in_array($debtor,$g['members'],true)))$affected[$part['debt_id']]=true;
        $out=[];foreach(array_keys($affected) as $id){
            $debt=$financial['debts'][$id]??null;if(!$debt)ReceivableException::reject('history_corrupt');
            $projection=self::project($debt,$events,$at,$known);
            if($projection['event_id']!==$projection['head_event_id'])ReceivableException::reject('subsequent_party_event');
            foreach($debt['events'] as $effect)if(ReceivableTimeline::inclusion($effect,$at,$known)!==true)ReceivableException::reject('subsequent_financial_event_review',['event_id'=>$effect['id']]);
            if(!$projection['complete'])ReceivableException::reject('liability_review');$out[$id]=$projection;
        }return $out;
    }
    public function forSelectedPayment(int $supplier,string $at,array $debtIds):array
    {
        $at=PropertyInstant::normalize($at);$repository=new PartyEventRepository($this->db);$history=$repository->historyForDebts($supplier,$debtIds);
        do{
            $financial=(new \MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository($this->db))->readForDebts($supplier,$history['debt_ids']);
            $ids=array_map('intval',array_keys($financial['debts']));sort($ids,SORT_NUMERIC);$history=$repository->historyForDebts($supplier,$ids);
        }while($ids!==$history['debt_ids']);
        $events=$history['events'];$known=PartyEventRepository::now();$affected=[];
        foreach($events as $event)foreach($event['snapshot']['debts'] as $part){
            $affected[$part['debt_id']]=true;$debt=$financial['debts'][$part['debt_id']]??null;if(!$debt)ReceivableException::reject('history_corrupt');
            $effects=array_column($debt['events'],null,'id');
            foreach($part['financial_events'] as $effect)if(!isset($effects[$effect['id']])||\MyInvoice\Service\Property\PropertyInput::hash($effect)!==\MyInvoice\Service\Property\PropertyInput::hash($effects[$effect['id']]))ReceivableException::reject('party_source_changed');
        }
        $out=[];foreach(array_keys($affected) as $id){
            $debt=$financial['debts'][$id]??null;if(!$debt)ReceivableException::reject('history_corrupt');
            $projection=self::project($debt,$events,$at,$known);
            if($projection['event_id']!==$projection['head_event_id'])ReceivableException::reject('subsequent_party_event');
            foreach($debt['events'] as $effect)if(ReceivableTimeline::inclusion($effect,$at,$known)!==true)ReceivableException::reject('subsequent_financial_event_review',['event_id'=>$effect['id']]);
            if(!$projection['complete'])ReceivableException::reject('liability_review');
            if(in_array($id,$debtIds,true))$out[$id]=$projection;
        }
        return $out;
    }
    public static function project(array $debt,array $events,string $asOf,string $knownAt):array
    {
        $asOf=PropertyInstant::normalize($asOf);$knownAt=PropertyInstant::normalize($knownAt);$selected=null;$head=null;
        foreach($events as $event){
            if($event['recorded_at']>$knownAt)continue;
            foreach($event['snapshot']['debts'] as $part)if($part['debt_id']===$debt['id']){
                if($part['predecessor_event_id']!==$head)ReceivableException::reject('party_history_chain');$head=$event['id'];
                if($event['effective_at']<=$asOf)$selected=['event'=>$event,'part'=>$part];
            }
        }
        $totals=ReceivableTimeline::sum($debt['events'],$asOf,$knownAt);$groups=$selected['part']['groups']??$debt['snapshot']['liability_groups'];$complete=$totals['complete'];
        if($selected!==null&&$selected['part']['source_snapshot_hash']!==$debt['snapshot_hash'])ReceivableException::reject('party_source_changed');
        $unresolved=$selected!==null&&$selected['event']['snapshot']['request']['decision']==='review_required';
        $pinned=$selected===null?[]:array_fill_keys(array_column($selected['part']['financial_events'],'id'),true);
        $groupPaid=[];$payerPaid=[];$original='0';
        foreach($debt['events'] as $event){
            if(ReceivableTimeline::inclusion($event,$asOf,$knownAt)!==true)continue;
            if($event['kind']==='claim')$original=bcadd($original,$event['amount_cents'],0);
            if($selected!==null&&$event['kind']==='claim'&&bccomp($event['amount_cents'],'0',0)>0&&!isset($pinned[$event['id']]))$unresolved=true;
            if($event['kind']==='paid'&&isset($event['group_key']))$groupPaid[$event['group_key']]=bcadd($groupPaid[$event['group_key']]??'0',$event['amount_cents'],0);
            if($event['kind']==='paid'&&isset($event['payer_id']))$payerPaid[$event['payer_id']]=bcadd($payerPaid[$event['payer_id']]??'0',$event['amount_cents'],0);
        }
        foreach($groups as &$group){
            $basis=$selected===null?$original:bcadd($selected['part']['remaining_cents'],bcsub($original,$selected['part']['claim_baseline_cents'],0),0);$num=bcmul($basis,$group['extent']['num'],0);
            if(bcmod($num,$group['extent']['den'],0)!=='0'){$group['remaining_cap_cents']=null;$unresolved=true;continue;}
            $paid=$groupPaid[$group['group_key']]??'0';
            if($selected!==null)$paid=bcsub($paid,$selected['part']['group_paid_baseline'][$group['group_key']]??'0',0);
            if($group['role']==='guarantor'){$paid='0';foreach($group['members'] as $member)$paid=bcadd($paid,bcsub($payerPaid[$member]??'0',$selected['part']['payer_paid_baseline'][$member]??'0',0),0);}
            $cap=bcsub(bcdiv($num,$group['extent']['den'],0),$paid,0);
            if(bccomp($cap,'0',0)<0)ReceivableException::reject('party_capacity_corrupt');
            $remaining=$totals['gross_receivable_cents'];$group['remaining_cap_cents']=$complete?(bccomp($cap,$remaining,0)>0?$remaining:$cap):null;
            if(!PropertyInstant::contains($group['valid_from'],$group['valid_to'],$asOf))$unresolved=true;
        }unset($group);
        return ['debt_id'=>$debt['id'],'event_id'=>$selected['event']['id']??null,'event_hash'=>$selected['event']['snapshot_hash']??null,'head_event_id'=>$head,'groups'=>$groups,'complete'=>$complete&&!$unresolved,'review_required'=>$unresolved,'precision'=>$totals['precision'],'remaining_cents'=>$totals['gross_receivable_cents']];
    }
}
