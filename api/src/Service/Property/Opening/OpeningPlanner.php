<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Opening;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\{PropertyReadRepository,PropertyProfileRepository};
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
use MyInvoice\Service\Property\Charges\Issuance\{IssuanceLocks,LiabilityPolicy};
use MyInvoice\Service\Property\Payments\{PaymentLocks,PaymentJournalProof};
use MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowJournalPlan;
use MyInvoice\Repository\{AccountingPeriodRepository,ClosingRepository};
use PDO;
final class OpeningPlanner
{
    public function __construct(private readonly Connection $db){}
    public function current(int $supplier,array $input,?int $pendingId=null):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Opening plan requires the owner transaction.');
        (new PaymentLocks($this->db))->acquire($supplier);$request=OpeningRequest::normalize($input);
        (new IssuanceLocks($this->db))->assertFramework($supplier,$request['accounting_date']);
        $profile=(new PropertyProfileRepository($this->db))->at($supplier,$request['effective_at'],true);
        if(!($profile['enabled']??false))OpeningException::reject('profile_required');
        $period=(new AccountingPeriodRepository($this->db))->findForDateForUpdate($supplier,$request['accounting_date']);
        if(!$period||$period['status']!=='open')OpeningException::reject('period_not_open');
        $other=$this->one('SELECT id FROM property_opening_batches WHERE supplier_id=? AND period_id<>? ORDER BY id LIMIT 1 FOR UPDATE',[$supplier,$period['id']]);if($other)OpeningException::reject('takeover_period_already_exists');
        $head=$this->one('SELECT * FROM property_opening_heads WHERE supplier_id=? AND period_id=? FOR UPDATE',[$supplier,$period['id']]);
        if((int)($head['row_version']??0)!==$request['expected_revision'])OpeningException::reject('revision_conflict');
        $previous=null;
        if($head&&$head['batch_id']!==null){
            $previous=(new OpeningReadProof($this->db))->batch($supplier,(int)$head['batch_id'],true);
            if($previous['status']!=='posted'||$previous['mode']==='reversal')OpeningException::reject('inactive_head');
            if($request['effective_at']<$previous['effective_at'])OpeningException::reject('revision_effective_at');
            (new OpeningUseGuard($this->db))->assertUnused($supplier,$previous['id']);
        }
        $live=(new ClosingRepository($this->db))->openingEntriesInPeriod($supplier,(int)$period['id']);
        $existing=null;
        if($request['mode']==='post_new'&&$live!==[])OpeningException::reject('opening_exists');
        if($request['mode']==='bind_existing'){
            if(count($live)!==1||$live[0]['id']!==$request['journal_entry_id'])OpeningException::reject('opening_scope');
            $header=$this->one('SELECT row_version FROM journal_entries WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$request['journal_entry_id']]);
            if((int)($header['row_version']??0)!==$request['journal_version'])OpeningException::reject('journal_version');
            $existing=(new PaymentJournalProof($this->db))->current($supplier,$request['journal_entry_id']);
            if($existing['entry']['source_type']!=='opening'||$existing['entry']['entry_date']!==$request['accounting_date']||$existing['entry']['reversed_by']!==null)OpeningException::reject('opening_scope');
            (new \MyInvoice\Service\Property\Payments\PaymentUseGuard($this->db))->assertEntriesMutable($supplier,[$request['journal_entry_id']]);
            (new \MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard($this->db))->assertEntriesMutable($supplier,[$request['journal_entry_id']]);
        }
        if($previous!==null&&(count($live)!==1||$live[0]['id']!==$previous['journal_entry_id']))OpeningException::reject('opening_scope');
        if($previous!==null)$existing=(new PaymentJournalProof($this->db))->current($supplier,$previous['journal_entry_id']);
        $openingAccount=\MyInvoice\Service\Accounting\AccountRoleResolver::fromConnection($this->db)->forDate($supplier,$request['accounting_date'])->account('closing.opening_balance');
        $registry=new PropertyReadRepository($this->db);$items=[];$journal=[];$carried=[];$used=[];$balance='0';$profiles=[];
        foreach($request['items'] as $item){
            $unit=$registry->find($supplier,$item['unit_id'],$item['origin_at'],null,true);
            if($unit['kind']!=='unit'||!($unit['revision']['state']['active']??false)||($unit['ownership_review_required']??true))OpeningException::reject('historical_unit',['unit_id'=>$item['unit_id']]);
            $historical=['unit'=>$unit];$dimensions=$this->dimension($supplier,$unit);
            if($item['service_id']!==null){
                $service=$registry->find($supplier,$item['service_id'],$item['origin_at'],null,true);
                if($service['kind']!=='service'||!($service['revision']['state']['active']??false)||!in_array($service['id'],$unit['revision']['state']['services']??[],true))OpeningException::reject('historical_service');
                $dimensions+=$this->dimension($supplier,$service);$historical['service']=$service;
            }
            $ids=[];foreach($item['liability_groups']??[] as $group)foreach($group['members']??[] as $id)$ids[]=$id;
            foreach($item['creditor_ids']??$item['service_state']['recipient_ids']??[] as $id)$ids[]=$id;
            foreach($item['reminders']??[] as $notice)foreach($notice['recipient_ids'] as $id)$ids[]=$id;
            $ids=array_values(array_unique($ids));$parties=$registry->parties($supplier,$ids,true);
            if(count($parties)!==count($ids))OpeningException::reject('foreign_party');
            if($item['kind']==='debt'){
                $liabilityUnit=$registry->find($supplier,$item['unit_id'],$request['effective_at'],null,true);
                $item['liability_groups']=LiabilityPolicy::validate($item['liability_groups'],$liabilityUnit['revision']['state']??[],$request['effective_at'],array_column($parties,'id'));
                foreach($item['liability_groups'] as $group){$fraction=bcmul($item['amount_cents'],$group['extent']['num'],0);if(bcmod($fraction,$group['extent']['den'],0)!=='0')OpeningException::reject('fractional_liability');}
                $historical['liability_unit']=$liabilityUnit;
            }
            if($item['kind']==='service_carry'){
                $key=$item['service_id'].':'.$item['period_from'].':'.$item['period_to'];$profile=$item['service_state']['profile'];
                if(isset($profiles[$key])&&$profiles[$key]!==$profile)OpeningException::reject('mixed_service_profiles');$profiles[$key]=$profile;
            }
            foreach($item['components'] as &$part){
                $line=['account_code'=>$part['account_code'],'side'=>$part['side'],'amount'=>bcdiv($part['amount_cents'],'100',2),'dimensions'=>$dimensions];
                $balance=bcadd($balance,$part['side']==='debit'?$part['amount_cents']:'-'.$part['amount_cents'],0);
                if($request['mode']==='bind_existing'){
                    $actual=null;foreach($existing['lines'] as $candidate)if($candidate['id']===$part['source_line_id'])$actual=$candidate;
                    if(!$actual||$actual['account_code']!==$line['account_code']||$actual['side']!==$line['side']||$actual['is_red_storno']||$actual['currency_code']!==null||$actual['amount_foreign']!==null||$actual['fx_rate']!==null||($actual['dimension_splits']??[])!==[]||PropertyInput::hash($actual['dimensions'])!==PropertyInput::hash($dimensions))OpeningException::reject('line_mismatch');
                    $used[$actual['id']]=bcadd($used[$actual['id']]??'0',$part['amount_cents'],0);$part['line']=$actual;$part['line']['amount']=$line['amount'];
                }else{
                    $part['journal_index']=count($journal);$carried[count($journal)]=true;$journal[]=$line;$part['line']=$line;
                    $journal[]=['account_code'=>$openingAccount,'side'=>$part['side']==='debit'?'credit':'debit','amount'=>$line['amount']];
                }
            }unset($part);
            $items[]=$item+['registry'=>$historical,'parties'=>$parties,'dimensions'=>$dimensions];
        }
        if($request['mode']==='bind_existing')foreach($existing['lines'] as $line)if(OpeningRolePolicy::relevant($line['account_code'])&&($used[$line['id']]??'0')!==bcmul($line['amount'],'100',0))OpeningException::reject('incomplete_scope',['line_id'=>$line['id']]);
        foreach($request['other_balances'] as $row){$amount=bcdiv($row['amount_cents'],'100',2);$balance=bcadd($balance,$row['side']==='debit'?$row['amount_cents']:'-'.$row['amount_cents'],0);$journal[]=['account_code'=>$row['account_code'],'side'=>$row['side'],'amount'=>$amount,'dimensions'=>$row['dimensions']];$journal[]=['account_code'=>$openingAccount,'side'=>$row['side']==='debit'?'credit':'debit','amount'=>$amount];}
        if(!in_array($request['mode'],['bind_existing','reversal'],true)&&$balance!=='0')OpeningException::reject('unbalanced',['difference_cents'=>$balance]);
        $this->caseLinks($items);
        $new=$journal===[]?null:(new OpeningJournalPlan($this->db))->resolve($supplier,$request['accounting_date'],'opening',$journal);
        $reverse=null;if($previous!==null){$mirror=$existing['lines'];foreach($mirror as &$line){unset($line['id']);$line['side']=$line['side']==='debit'?'credit':'debit';}unset($line);$reverse=(new OpeningJournalPlan($this->db))->resolve($supplier,$request['accounting_date'],'opening',$mirror);}
        return ['request'=>$request,'period_id'=>(int)$period['id'],'previous'=>$previous,'existing'=>$existing,'existing_version'=>$existing===null?null:(int)$this->one('SELECT row_version FROM journal_entries WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$existing['entry']['id']])['row_version'],'items'=>$items,'journal'=>$new,'reversal'=>$reverse,'carried_indices'=>$carried];
    }
    private function dimension(int $supplier,array $object):array
    {
        $row=$this->one('SELECT v.id,v.type_id,t.system_key FROM dimension_values v JOIN dimension_types t ON t.id=v.type_id AND t.supplier_id=v.supplier_id WHERE v.supplier_id=? AND v.id=? FOR UPDATE',[$supplier,$object['dimension_value_id']]);
        if(!$row||$row['system_key']!==$object['kind'])OpeningException::reject('dimension_binding');return [(int)$row['type_id']=>(int)$row['id']];
    }
    private function caseLinks(array $items):void
    {
        $cases=array_column($items,null,'case_key');$consumed=[];
        foreach($items as $item){$state=$item['advance_state']??$item['service_state']??null;if($state===null)continue;$total='0';$seen=[];
            foreach($state['unpaid_cases'] as $link){if(!is_array($link))PropertyInput::invalid('unpaid_cases');PropertyInput::fields($link,['case_ref','occurrence_ref','amount_cents']);$key=PropertyInput::hash(['case_ref'=>PropertyInput::text($link['case_ref']??null,'case_ref',190),'occurrence_ref'=>PropertyInput::text($link['occurrence_ref']??null,'occurrence_ref',190)]);if(isset($seen[$key]))OpeningException::reject('duplicate_unpaid_case');$seen[$key]=true;$debt=$cases[$key]??null;$amount=OpeningRequest::cents($link['amount_cents']??null);
                if(!$debt||$debt['kind']!=='debt'||$debt['unit_id']!==$item['unit_id']||$debt['service_id']!==$item['service_id']||$amount==='0'||bccomp($amount,$debt['amount_cents'],0)>0)OpeningException::reject('unpaid_case');
                $total=bcadd($total,$amount,0);if($item['kind']==='advance'){$consumed[$key]=bcadd($consumed[$key]??'0',$amount,0);if(bccomp($consumed[$key],$debt['amount_cents'],0)>0)OpeningException::reject('unpaid_case_capacity');}
            }
            $unpaid=bcsub(bcsub($state['prescribed_cents'],$state['paid_cents'],0),$state['cancelled_unpaid_cents']??'0',0);
            if($total!==$unpaid)OpeningException::reject('unpaid_case_total');
        }
    }
    private function one(string $sql,array $params):?array{$q=$this->db->pdo()->prepare($sql);$q->execute($params);return $q->fetch(PDO::FETCH_ASSOC)?:null;}
}
