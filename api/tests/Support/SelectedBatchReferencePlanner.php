<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\SelectedBatchReferenceDebt as DebtRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use PDO;
final class SelectedBatchReferencePlanner
{
    public function __construct(private readonly Connection $db) {}
    public function current(int $supplier,array $request,array $source):array
    {
        $payment=$request['payment']??null;if(!is_array($payment))PropertyInput::invalid('payment');
        PropertyInput::fields($payment,['payer_id','debtor_id','received_at','third_party','choice','security_comparison','reminders','principal_distribution','accessory_distribution']);
        if(isset($payment['third_party'])){if(!is_array($payment['third_party']))PropertyInput::invalid('third_party');PropertyInput::fields($payment['third_party'],['basis','evidence']);PropertyInput::text($payment['third_party']['evidence']??null,'third_party.evidence',4000);}
        if(isset($payment['choice'])){if(!is_array($payment['choice']))PropertyInput::invalid('choice');PropertyInput::fields($payment['choice'],['claim_ids','evidence']);PropertyInput::text($payment['choice']['evidence']??null,'choice.evidence',4000);foreach(PropertyInput::list($payment['choice']['claim_ids']??null,'claim_ids') as $id)PropertyInput::id($id,'claim_id');}
        if(isset($payment['security_comparison'])){if(!is_array($payment['security_comparison']))PropertyInput::invalid('security_comparison');PropertyInput::fields($payment['security_comparison'],['tiers','facts','evidence']);PropertyInput::text($payment['security_comparison']['evidence']??null,'security_comparison.evidence',4000);foreach($payment['security_comparison']['facts']??[] as $fact){if(!is_array($fact))PropertyInput::invalid('security_fact');PropertyInput::fields($fact,['kind','evidence']);PropertyInput::text($fact['evidence']??null,'security_fact.evidence',4000);}}
        foreach($payment['reminders']??[] as $notice){if(!is_array($notice))PropertyInput::invalid('reminder');PropertyInput::fields($notice,['debt_id','evidence','recipient_ids','delivered_at']);PropertyInput::id($notice['debt_id']??null,'debt_id');foreach(PropertyInput::list($notice['recipient_ids']??null,'recipient_ids') as $id)PropertyInput::id($id,'recipient_id');}
        foreach($payment['principal_distribution']??[] as $distribution){if(!is_array($distribution))PropertyInput::invalid('principal_distribution');PropertyInput::fields($distribution,['lines','evidence']);PropertyInput::text($distribution['evidence']??null,'principal_distribution.evidence',4000);foreach(PropertyInput::list($distribution['lines']??null,'lines') as $part){if(!is_array($part))PropertyInput::invalid('line');PropertyInput::fields($part,['line_id','amount_cents']);}}
        foreach($payment['accessory_distribution']??[] as $components){if(!is_array($components))PropertyInput::invalid('accessory_distribution');PropertyInput::fields($components,['determined_cost','default_interest','interest']);foreach($components as $distribution){if(!is_array($distribution))PropertyInput::invalid('accessory_distribution');PropertyInput::fields($distribution,['parts','evidence']);PropertyInput::text($distribution['evidence']??null,'accessory_distribution.evidence',4000);foreach(PropertyInput::list($distribution['parts']??null,'parts') as $part){if(!is_array($part))PropertyInput::invalid('part');PropertyInput::fields($part,['id','amount_cents']);PropertyInput::id($part['id']??null,'accessory_id');StatutoryAllocationPolicy::cents($part['amount_cents']??null);}}}
        $debtor=PropertyInput::id($payment['debtor_id']??null,'debtor_id');$payer=PropertyInput::id($payment['payer_id']??null,'payer_id');
        $parties=(new PropertyReadRepository($this->db))->parties($supplier,array_values(array_unique([$debtor,$payer])),true);
        if(count($parties)!==count(array_unique([$debtor,$payer])))PaymentException::reject('foreign_party');
        $at=PropertyInstant::normalize($payment['received_at']??null);
        if((new \DateTimeImmutable($at))->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d')!==$source['receipt']['date'])PaymentException::reject('payment_instant_date');
        $wanted=PropertyInput::list($request['debts']??null,'debts');foreach($wanted as $id)PropertyInput::id($id,'debt_id');
        if($wanted===[]||count(array_unique($wanted))!==count($wanted))PropertyInput::invalid('debts');
        $all=(new DebtRepository($this->db))->allCurrent($supplier);$byId=array_column($all,null,'id');
        foreach($wanted as $id)if(!isset($byId[$id]))PaymentException::reject('debt_not_found');
        $choice=$payment['choice']??null;
        try{$liabilities=(new \MyInvoice\Service\Property\Receivables\PropertyLiabilityProjection($this->db))->forPayment($supplier,$at,$debtor,$wanted);}
        catch(\MyInvoice\Service\Property\PropertyException $e){PaymentException::reject('liability_review',['reason'=>$e->errorCode]+$e->details);}
        $claims=[];$details=[];$bound=[];
        foreach($all as $debt) {
            $fact=$debt['snapshot'];foreach($fact['lines'] as $line)$bound[$line['id']]=true;
            if(!$debt['active']){if(in_array($debt['id'],$wanted,true))PaymentException::reject('inactive_debt');continue;}
            $group=null;
            foreach(($liabilities[$debt['id']]['groups']??$fact['liability_groups']) as $candidate)if($candidate['role']==='debtor'&&in_array($debtor,$candidate['members'],true))$group=$candidate;
            if($group===null)continue;
            $legalProjection=$liabilities[$debt['id']]??null;
            if($legalProjection!==null&&($payment['third_party']['basis']??null)==='guarantor'){
                $guarantee=null;foreach($legalProjection['groups'] as $candidate)if($candidate['role']==='guarantor'&&in_array($payer,$candidate['members'],true))$guarantee=$candidate;
                if($guarantee===null)PaymentException::reject('guarantee_unproven',['debt_id'=>$debt['id']]);
            }
            $remaining='0';$lines=[];
            $effectivePrincipal='0';foreach($fact['lines'] as $line){$effective=bcsub($line['line']['amount_cents'],$debt['reductions'][$line['id']]??'0',0);$effectivePrincipal=bcadd($effectivePrincipal,$effective,0);$line['remaining_cents']=bcsub($effective,$debt['paid']['principal:'.$line['id']]??'0',0);if(bccomp($line['remaining_cents'],'0',0)<0)PaymentException::reject('claim_capacity_corrupt');$remaining=bcadd($remaining,$line['remaining_cents'],0);$lines[]=$line;}
            $components=['principal'=>$remaining,'determined_cost'=>'0','default_interest'=>'0','interest'=>'0'];$original=$effectivePrincipal;$accessories=[];
            foreach($debt['accessories'] as $accessory){$accessory['remaining_cents']=bcsub($accessory['amount_cents'],$debt['paid']['accessory:'.$accessory['id']]??'0',0);if(bccomp($accessory['remaining_cents'],'0',0)<0)PaymentException::reject('claim_capacity_corrupt');$components[$accessory['component']]=bcadd($components[$accessory['component']],$accessory['remaining_cents'],0);$original=bcadd($original,$accessory['amount_cents'],0);$accessories[]=$accessory;}
            if(($fact['legal']['component_disposition']['old_interest_effect']['resolution']??null)==='separately_evidenced'&&!array_any($accessories,fn($a)=>($a['receipt']['request']['purpose']??null)==='old_1932_interest_effect'))PaymentException::reject('old_interest_receipt_required',['debt_id'=>$debt['id']]);
            $remaining=array_reduce($components,fn($sum,$value)=>bcadd($sum,$value,0),'0');
            $numerator=bcmul($original,$group['extent']['num'],0);
            if(bcmod($numerator,$group['extent']['den'],0)!=='0')PaymentException::reject('fractional_liability_title_required',['debt_id'=>$debt['id'],'group_key'=>$group['group_key'],'basis_cents'=>$original,'numerator'=>$group['extent']['num'],'denominator'=>$group['extent']['den'],'review_required'=>true]);
            $cap=bcsub(bcdiv($numerator,$group['extent']['den'],0),$debt['group_paid'][$group['group_key']]??'0',0);
            if($legalProjection!==null){$cap=$group['remaining_cap_cents'];if(($payment['third_party']['basis']??null)==='guarantor'&&bccomp($guarantee['remaining_cap_cents'],$cap,0)<0)$cap=$guarantee['remaining_cap_cents'];}
            if(bccomp($cap,'0',0)<0)PaymentException::reject('claim_capacity_corrupt');
            if(bccomp($remaining,$cap,0)<0)$cap=$remaining;
            if($choice===null&&$cap!=='0'&&!in_array($debt['id'],$wanted,true))PaymentException::reject('debt_inventory_incomplete');
            if(!in_array($debt['id'],$wanted,true))continue;
            $reminder=null;
            foreach($payment['reminders']??[] as $notice)if(($notice['debt_id']??null)===$debt['id']){
                PropertyInput::text($notice['evidence']??null,'reminder.evidence',4000);
                if(!in_array($debtor,$notice['recipient_ids']??[],true))PaymentException::reject('reminder_recipient_unproven');
                $time=PropertyInstant::normalize($notice['delivered_at']??null);if($time<$at&&($reminder===null||$time<$reminder))$reminder=$time;
            }
            $claims[]=['id'=>$debt['id'],'unit_id'=>$fact['unit_id'],'debtor_ids'=>$group['members'],'eligible_cents'=>$cap,'due_date'=>$fact['due_date'],'reminder_at'=>$reminder,
                'security'=>$fact['security'],'damages_for'=>$fact['damages_for'],'legal'=>$fact['legal'],'components'=>$components];
            $details[$debt['id']]=['debt'=>$debt,'group'=>$group,'lines'=>$lines,'accessories'=>$accessories];
            if($legalProjection!==null){unset($legalProjection['event_hash']);$details[$debt['id']]['liability_projection']=$legalProjection;}
        }
        if($choice===null)foreach((new IssuanceReadRepository($this->db))->charges($supplier,true) as $charge){
            if($charge['status']!=='posted'||bccomp($charge['amount_cents'],'0',0)<=0)continue;
            $liable=false;foreach($charge['snapshot']['liability_groups'] as $g)if($g['role']==='debtor'&&in_array($debtor,$g['members'],true))$liable=true;
            if(!$liable)continue;
            $q=$this->db->pdo()->prepare('SELECT id FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? AND amount_cents>0 ORDER BY id FOR UPDATE');$q->execute([$supplier,$charge['id']]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)if(!isset($bound[$id]))PaymentException::reject('debt_inventory_incomplete',['charge_id'=>$charge['id'],'charge_line_id'=>(int)$id]);
        }
        $payment['amount_cents']=$request['amount_cents'];$payment['payment_date']=$source['receipt']['date'];$payment['credit_entitlement']=$request['credit_entitlement']??null;
        $resolution=StatutoryClaimSelector::preview($payment,$claims);if($resolution['status']!=='ready')PaymentException::reject('legal_review',['reason'=>$resolution['reason']]);
        $allocations=[];$journal=[];
        foreach($resolution['allocations'] as $allocation){
            $id=$allocation['claim_id'];$detail=$details[$id];$principal=$allocation['components']['principal'];$remaining=array_reduce($detail['lines'],fn($sum,$line)=>bcadd($sum,$line['remaining_cents'],0),'0');
            $declared=$payment['principal_distribution'][$id]??null;
            if($declared!==null)$declared=['evidence'=>$declared['evidence'],'parts'=>array_map(fn($p)=>['id'=>$p['line_id'],'amount_cents'=>$p['amount_cents']],$declared['lines'])];
            try{$distribution=ClaimPortionAllocator::distribute($principal,array_column($detail['lines'],'remaining_cents','id'),$declared,true);}catch(\DomainException $e){PaymentException::reject('principal_'.$e->getMessage(),['debt_id'=>$id,'principal_cents'=>$principal]);}
            $seen=[];
            foreach($detail['lines'] as $sourceLine){$lineId=$sourceLine['id'];$value=$distribution[$lineId]??'0';$seen[$lineId]=true;if(bccomp($value,$sourceLine['remaining_cents'],0)>0)PaymentException::reject('principal_distribution_capacity');if($value==='0')continue;
                $fact=$sourceLine['line'];$origins=[];foreach($fact['dimensions'] as $type=>$dimension)$origins[$type]=['origin'=>'module','ref'=>'unit_charge:'.$detail['debt']['snapshot']['charge_id']];
                $journal[]=['account_code'=>$fact['account']['receivable_account'],'side'=>'credit','amount'=>bcdiv($value,'100',2),'dimensions'=>$fact['dimensions'],'dimension_origins'=>$origins];
                $allocations[]=['debt_id'=>$id,'charge_id'=>$detail['debt']['snapshot']['charge_id'],'charge_line_id'=>$lineId,'accessory_id'=>null,'debtor_id'=>$debtor,'liability_group'=>$detail['group']['group_key'],'amount_cents'=>$value,'component'=>'principal'];
            }
            if(array_diff_key($distribution,$seen)!==[])PaymentException::reject('foreign_charge_line');
            foreach(['determined_cost','default_interest','interest'] as $component){
                $value=$allocation['components'][$component];if($value==='0')continue;
                $candidates=array_values(array_filter($detail['accessories'],fn($a)=>$a['component']===$component&&$a['remaining_cents']!=='0'));
                $sum=array_reduce($candidates,fn($total,$a)=>bcadd($total,$a['remaining_cents'],0),'0');
                $declared=$payment['accessory_distribution'][$id][$component]??null;
                try{$distribution=ClaimPortionAllocator::distribute($value,array_column($candidates,'remaining_cents','id'),$declared);}catch(\DomainException $e){PaymentException::reject('accessory_'.$e->getMessage(),['debt_id'=>$id,'component'=>$component]);}
                foreach($candidates as $accessory){$part=$distribution[$accessory['id']]??'0';if($part==='0')continue;$line=$accessory['receipt']['line'];unset($line['id']);$line['side']='credit';$line['amount']=bcdiv($part,'100',2);$journal[]=$line;
                    $allocations[]=['debt_id'=>$id,'charge_id'=>$detail['debt']['snapshot']['charge_id'],'charge_line_id'=>null,'accessory_id'=>$accessory['id'],'debtor_id'=>$debtor,'liability_group'=>$detail['group']['group_key'],'amount_cents'=>$part,'component'=>$component];}
            }
        }
        return ['resolution'=>$resolution,'allocations'=>$allocations,'lines'=>$journal,'debt_receipts'=>array_values($details),'payment_parties'=>$parties];
    }
}
