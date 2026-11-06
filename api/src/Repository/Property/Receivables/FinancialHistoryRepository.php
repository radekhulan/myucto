<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Receivables;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\Receivables\ReceivableException;
use PDO;
final class FinancialHistoryRepository
{
    public function __construct(private readonly Connection $db){}
    public function read(int $supplier,bool $currentRead=false):array
    {
        return $this->readHistory($supplier,$currentRead,false);
    }
    public function readProjection(int $supplier,bool $currentRead=false):array
    {
        return $this->readHistory($supplier,$currentRead,true);
    }
    private function readHistory(int $supplier,bool $currentRead,bool $projection):array
    {
        if($currentRead&&!$this->db->pdo()->inTransaction())throw new \LogicException('Current financial history requires owner transaction.');
        $pdo=$this->db->pdo();$owned=!$pdo->inTransaction();
        if($owned){$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('START TRANSACTION READ ONLY');}
        try {
            $charges=[];
            foreach((new \MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository($this->db))->stream($supplier,$currentRead) as $charge) {
                $charges[$charge['id']]=array_intersect_key($charge,array_flip(['id','unit_id','accounting_date','snapshot_hash']));
                $charges[$charge['id']]['snapshot']=array_intersect_key($charge['snapshot'],array_flip(['liability_groups','claim_origin_at','due_date','reason']));
            }
            unset($charge);
            $result=\MyInvoice\Service\Property\Services\Settlement\SettlementHistory::inReadScope(fn()=>$this->project($charges,fn(string $table)=>$this->rows($table,$supplier,$currentRead),$projection,$currentRead,$supplier));
            if($owned)$pdo->commit();return $result;
        }catch(\Throwable $e){if($owned&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function readForDebts(int $supplier,array $debtIds):array
    {
        return $this->readForSources($supplier,$debtIds,[]);
    }
    public function readForCharges(int $supplier,array $chargeIds,array $debtIds=[]):array
    {
        return $this->readForSources($supplier,$debtIds,$chargeIds);
    }
    private function readForSources(int $supplier,array $debtIds,array $chargeIds):array
    {
        $closure=(new SelectedFinancialClosure($this->db))->currentForSources($supplier,$debtIds,$chargeIds);
        if($closure['debt_ids']!==[])(new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->currentMany($supplier,$closure['debt_ids']);
        $charges=(new \MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository($this->db))->findMany($supplier,$closure['charge_ids'],true);
        $result=$this->project($charges,fn(string $table)=>$closure['rows'][$table]??[],false,true,$supplier);
        $this->assertOperationLedgers($supplier,$closure['rows']['unit_payment_operations']);
        return $result;
    }
    private function assertOperationLedgers(int $supplier,array $operations):void
    {
        $ids=[];foreach($operations as $op)if(in_array($op['status'],['posted','reversed'],true)){$ids[]=(int)$op['journal_entry_id'];if($op['status']==='reversed')$ids[]=(int)$op['reversal_entry_id'];}
        $ledgers=(new \MyInvoice\Service\Property\Payments\PaymentJournalProof($this->db))->currentMany($supplier,$ids);
        foreach($operations as $op){
            if(!in_array($op['status'],['posted','reversed'],true))continue;
            $snapshot=self::decode($op);$actual=$ledgers[(int)$op['journal_entry_id']];
            if($actual['entry']['source_type']!=='unit_payment'||$actual['entry']['source_id']!==($op['kind']==='reversal'?null:(int)$op['id'])||$actual['entry']['reversed_by']!==($op['status']==='reversed'?(int)$op['reversal_entry_id']:null)||$actual['entry']['entry_date']!==$op['accounting_date']||PropertyInput::hash(\MyInvoice\Service\Property\Payments\PaymentJournalProof::canonicalLines($actual['lines']))!==PropertyInput::hash(\MyInvoice\Service\Property\Payments\PaymentJournalProof::canonicalLines($snapshot['lines'])))ReceivableException::reject('history_corrupt');
            if($op['status']==='reversed'){
                $mirror=$ledgers[(int)$op['reversal_entry_id']];$lines=$snapshot['lines'];foreach($lines as &$line)$line['side']=$line['side']==='debit'?'credit':'debit';unset($line);
                if($mirror['entry']['source_type']!=='unit_payment'||$mirror['entry']['source_id']!==null||$mirror['entry']['reversed_by']!==null||PropertyInput::hash(\MyInvoice\Service\Property\Payments\PaymentJournalProof::canonicalLines($mirror['lines']))!==PropertyInput::hash(\MyInvoice\Service\Property\Payments\PaymentJournalProof::canonicalLines($lines)))ReceivableException::reject('history_corrupt');
            }
        }
    }
    public function reduceReadHistory(array $charges,callable $rows):array
    {
        $context=[];foreach(\MyInvoice\Service\Property\Services\Settlement\SettlementHistory::SELECTED_TABLES as $table){$selected=$rows($table);$context[$table]=is_array($selected)?$selected:iterator_to_array($selected,false);}
        $supplier=\MyInvoice\Service\Property\Services\Settlement\SettlementHistory::selectedMaterializations($context);
        return $this->project($charges,$rows,true,false,$supplier);
    }
    private function project(array $charges,callable $rows,bool $projection=false,bool $currentRead=false,?int $supplier=null):array
    {
        $chargeAudit=[];
        foreach($rows('unit_charge_issue_audit') as $r){self::decode($r,'payload');if($r['action']==='posted')$chargeAudit[$r['charge_id']]=$r['recorded_at'];}
        $adjustments=[];foreach($rows('unit_charge_adjustments') as $r){
            $request=json_decode($r['request_json'],true,flags:JSON_THROW_ON_ERROR);
            if(!hash_equals($r['request_hash'],PropertyInput::hash(['charge_id'=>(int)$r['charge_id'],'request'=>$request,'idempotency_key'=>$r['idempotency_key'],'actor'=>(int)$r['created_by']])))ReceivableException::reject('history_corrupt');
            if($r['kind']==='reversal'){if(isset($adjustments[$r['charge_id']]))ReceivableException::reject('history_corrupt');$adjustments[$r['charge_id']]=$r;}
        }
        $debts=[];$openingDebts=[];$lineDebt=[];$chargeLines=[];foreach($rows('unit_charge_lines') as $r)$chargeLines[$r['id']]=$r;
        foreach($rows('unit_payment_debts') as $r){
            $fact=self::decode($r);self::request($r);
            if(($r['settlement_effect_id']??null)!==null){
                $history=new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($this->db);$application=$history->debt((int)$r['supplier_id'],$r,$currentRead);$events=$history->events((int)$r['supplier_id'],$application,'claim:'.$r['id'],'claim',$fact['amount_cents'],$currentRead);
                $debts[(int)$r['id']]=['id'=>(int)$r['id'],'unit_id'=>$fact['unit_id'],'snapshot'=>$fact,'snapshot_hash'=>$r['snapshot_hash'],'events'=>$events];continue;
            }
            if(($r['opening_item_id']??null)!==null){
                $openingDebts[(int)$r['id']]=$r;$events=[];
                $debts[(int)$r['id']]=['id'=>(int)$r['id'],'unit_id'=>$fact['unit_id'],'snapshot'=>$fact,'snapshot_hash'=>$r['snapshot_hash'],'events'=>$events];continue;
            }
            $charge=$charges[$r['charge_id']]??null;
            if(!$charge)ReceivableException::reject('history_corrupt');$chargeFact=$charge['snapshot'];
            if($fact['charge_id']!==(int)$charge['id']||PropertyInput::hash($fact['liability_groups'])!==PropertyInput::hash($chargeFact['liability_groups']))ReceivableException::reject('history_corrupt');
            $events=[];
            if(isset($chargeAudit[$charge['id']])){
                $events[]=self::event('claim:'.$r['id'],'claim',$fact['amount_cents'],$charge['accounting_date'],$chargeFact['claim_origin_at'],$chargeAudit[$charge['id']]);
                if(isset($adjustments[$charge['id']])){$a=$adjustments[$charge['id']];$request=json_decode($a['request_json'],true,flags:JSON_THROW_ON_ERROR);$events[]=self::event('claim-reversal:'.$r['id'],'claim','-'.$fact['amount_cents'],$request['accounting_date'],self::instant($a['effective_at']),$a['recorded_at']);}
            }
            $debts[(int)$r['id']]=['id'=>(int)$r['id'],'unit_id'=>$fact['unit_id'],'snapshot'=>$fact,'snapshot_hash'=>$r['snapshot_hash'],'events'=>$events];
            $principal='0';foreach($fact['lines'] as $line){$actual=$chargeLines[$line['id']]??null;if(!$actual||(int)$actual['charge_id']!==(int)$r['charge_id']||PropertyInput::hash(json_decode($actual['line_json'],true,flags:JSON_THROW_ON_ERROR))!==PropertyInput::hash($line['line'])||isset($lineDebt[$line['id']]))ReceivableException::reject('history_corrupt');$principal=bcadd($principal,$line['line']['amount_cents'],0);$lineDebt[$line['id']]=(int)$r['id'];}
            if($principal!==$fact['amount_cents'])ReceivableException::reject('history_corrupt');
            if($projection)$debts[(int)$r['id']]['snapshot']=['liability_groups'=>$fact['liability_groups']===$chargeFact['liability_groups']?$chargeFact['liability_groups']:$fact['liability_groups'],'due_date'=>$fact['due_date'],'title_ref'=>$fact['title_ref']];
        }
        $openingReader=new \MyInvoice\Service\Property\Opening\OpeningOriginProof($this->db);$eventReader=new \MyInvoice\Service\Property\Opening\OpeningReadProof($this->db);$openingGroups=[];
        foreach($openingDebts as $row)$openingGroups[(int)$row['supplier_id']][]=$row;
        foreach($openingGroups as $openingSupplier=>$group)foreach($openingReader->debts($openingSupplier,$group,$currentRead) as $id=>$opening)$debts[$id]['events']=$eventReader->events($opening,'claim:'.$id,'claim',$debts[$id]['snapshot']['amount_cents'],$currentRead);
        unset($openingGroups,$opening,$group);
        $accessories=[];$openingAccessories=[];$openingAccessoryAmounts=[];
        foreach($rows('unit_claim_accessory_receipts') as $r){
            $fact=self::decode($r,'receipt');$id=(int)$r['debt_id'];if(!isset($debts[$id])||$fact['debt_id']!==$id)ReceivableException::reject('history_corrupt');
            if(($r['opening_component_id']??null)!==null){
                if(!isset($openingDebts[$id]))ReceivableException::reject('history_corrupt');
                $openingAccessories[(int)$r['supplier_id']][]=$r;$openingAccessoryAmounts[(int)$r['id']]=$r['amount_cents'];$debts[$id]['events'][]=['opening_accessory_slot'=>(int)$r['id']];
            }else $debts[$id]['events'][]=self::event('accessory:'.$r['id'],'claim',$r['amount_cents'],$fact['ledger']['entry']['entry_date'],null,$r['recorded_at']);$accessories[(int)$r['id']]=$id;
        }
        $openingAccessoryEvents=[];
        foreach($openingAccessories as $supplier=>$group)foreach($openingReader->accessories($supplier,array_values($openingDebts),$group,$currentRead) as $id=>$opening)$openingAccessoryEvents[$id]=$eventReader->events($opening,'accessory:'.$id,'claim',$openingAccessoryAmounts[$id],$currentRead);
        foreach($openingDebts as $id=>$row){$events=[];foreach($debts[$id]['events'] as $event){if(isset($event['opening_accessory_slot']))array_push($events,...$openingAccessoryEvents[$event['opening_accessory_slot']]);else $events[]=$event;}$debts[$id]['events']=$events;}
        unset($openingAccessories,$openingAccessoryAmounts,$openingAccessoryEvents,$opening,$group,$events);
        $operations=[];$reversals=[];
        foreach($rows('unit_payment_operations') as $r){
            $r['snapshot']=self::decode($r);$r['request']=self::request($r);
            if(PropertyInput::hash($r['request'])!==PropertyInput::hash($r['snapshot']['request'])||$r['kind']!==$r['request']['kind']||$r['amount_cents']!==$r['request']['amount_cents']||$r['accounting_date']!==$r['request']['accounting_date'])ReceivableException::reject('history_corrupt');
            if($r['status']==='pending')continue;
            $r=array_intersect_key($r,array_flip(['id','kind','status','original_operation_id','journal_entry_id','reversal_entry_id','amount_cents','accounting_date','recorded_at','snapshot_hash','snapshot','request']));
            $r['snapshot']=array_intersect_key($r['snapshot'],array_flip(['allocations','credit','original_operation_id','original_snapshot_hash']));
            $r['request']=array_intersect_key($r['request'],array_flip(['legal_effective_at','payment']));
            if(isset($r['request']['payment']))$r['request']['payment']=array_intersect_key($r['request']['payment'],array_flip(['received_at','third_party','payer_id']));
            $operations[(int)$r['id']]=$r;
            if($r['kind']==='reversal'){if(isset($reversals[(int)$r['original_operation_id']]))ReceivableException::reject('history_corrupt');$reversals[(int)$r['original_operation_id']]=$r;}
        }
        foreach($reversals as $id=>$reverse){$original=$operations[$id]??null;
            if(!$original||$original['status']!=='reversed'||$reverse['status']!=='posted'||$original['amount_cents']!==$reverse['amount_cents']||(int)$original['reversal_entry_id']!==(int)$reverse['journal_entry_id']||$reverse['snapshot']['original_operation_id']!==$id||$reverse['snapshot']['original_snapshot_hash']!==$original['snapshot_hash'])ReceivableException::reject('history_corrupt');
        }
        foreach($operations as $id=>$operation)if($operation['status']==='reversed'&&!isset($reversals[$id]))ReceivableException::reject('history_corrupt');
        $operationEvents=function(array $op,string $id,string $kind,string $amount)use($reversals):array{
            $at=$kind==='reserved'?self::instant($op['recorded_at']):($op['request']['legal_effective_at']??$op['request']['payment']['received_at']??null);
            $events=[self::event($id,$kind,$amount,$op['accounting_date'],$at,$op['recorded_at'])];
            if(isset($reversals[$op['id']])){$r=$reversals[$op['id']];$events[]=self::event($id.':reversal',$kind,bcsub('0',$amount,0),$r['accounting_date'],$kind==='reserved'?self::instant($r['recorded_at']):($r['request']['legal_effective_at']??null),$r['recorded_at']);}
            return $events;
        };
        $allocations=[];$allocationFacts=[];
        foreach($rows('unit_payment_allocations') as $r){
            $fact=json_decode($r['allocation_json'],true,flags:JSON_THROW_ON_ERROR);$op=$operations[(int)$r['operation_id']]??null;
            if(!$op||!in_array($fact,$op['snapshot']['allocations']??[],true))ReceivableException::reject('history_corrupt');
            foreach(['debt_id','charge_id','charge_line_id','accessory_id','debtor_id','liability_group','amount_cents'] as $key)if((is_int($fact[$key])?(int)$r[$key]:$r[$key])!==$fact[$key])ReceivableException::reject('history_corrupt');
            if(($fact['opening_component_id']??null)!==(($r['opening_component_id']??null)===null?null:(int)$r['opening_component_id']))ReceivableException::reject('history_corrupt');
            if(($fact['settlement_effect_id']??null)!==(($r['settlement_effect_id']??null)===null?null:(int)$r['settlement_effect_id']))ReceivableException::reject('history_corrupt');
            $allocationFacts[(int)$r['operation_id']][]=$fact;
            $id=(int)$r['debt_id'];if(!isset($debts[$id]))ReceivableException::reject('history_corrupt');
            $events=$operationEvents($op,'allocation:'.$r['id'],'paid',$r['amount_cents']);
            foreach($events as &$e){$e['group_key']=$r['liability_group'];$e['payer_id']=($op['request']['payment']['third_party']['basis']??null)==='guarantor'?($op['request']['payment']['payer_id']??null):null;}unset($e);
            array_push($debts[$id]['events'],...$events);$allocations[(int)$r['id']]=array_intersect_key($r,array_flip(['id','debt_id','liability_group']));
        }
        foreach($operations as $id=>$operation)if(PropertyInput::hash($operation['snapshot']['allocations']??[])!==PropertyInput::hash($allocationFacts[$id]??[]))ReceivableException::reject('history_corrupt');
        $audit=[];$correctionReversals=[];
        foreach($rows('unit_payment_audit') as $r){$fact=self::decode($r,'payload');if($r['object_kind']==='correction'&&$r['action']==='reversed')$correctionReversals[$r['object_id']]=['request'=>$fact['request'],'recorded_at'=>$r['recorded_at']];$audit[]=array_intersect_key($r,array_flip(['id','object_kind','object_id','action','recorded_at']));}
        $anchored=[];foreach($rows('unit_payment_corrections') as $r){
            $fact=self::decode($r);$request=self::request($r);if($r['status']==='pending')continue;
            $changes=[];
            foreach($fact['anchors'] as $p){$debt=$lineDebt[$p['original_line_id']]??null;if(!$debt)ReceivableException::reject('history_corrupt');$changes[]=[$debt,'anchor:'.$p['original_line_id'],'claim','-'.$p['amount_cents'],null];$anchored[$p['adjustment_line_id']]=true;}
            foreach($fact['reliefs'] as $p){$a=$allocations[$p['allocation_id']]??null;if(!$a)ReceivableException::reject('history_corrupt');$changes[]=[(int)$a['debt_id'],'relief:'.$a['id'],'paid','-'.$p['amount_cents'],$a['liability_group']];}
            foreach($changes as [$debt,$key,$kind,$amount,$group]){
                $e=self::event('correction:'.$r['id'].':'.$key,$kind,$amount,$request['accounting_date'],$request['legal_effective_at']??null,$r['recorded_at']);if($group!==null)$e['group_key']=$group;$debts[$debt]['events'][]=$e;
                if(isset($correctionReversals[$r['id']])){$reverse=$correctionReversals[$r['id']];$e=self::event($e['id'].':reversal',$kind,bcsub('0',$amount,0),$reverse['request']['accounting_date'],$reverse['request']['effective_at'],$reverse['recorded_at']);if($group!==null)$e['group_key']=$group;$debts[$debt]['events'][]=$e;}
            }
        }
        if($supplier!==null)foreach((new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($this->db))->debtEvents($supplier,array_keys($debts),$currentRead) as $id=>$events)array_push($debts[$id]['events'],...$events);
        $creditParties=[];foreach($rows('unit_credit_parties') as $r){$party=json_decode($r['party_json'],true,flags:JSON_THROW_ON_ERROR);if($party['id']!==(int)$r['client_id'])ReceivableException::reject('history_corrupt');$creditParties[(int)$r['credit_id']][$party['id']]=$party;}
        $credits=[];$openingCredits=[];
        foreach($rows('unit_credit_lots') as $r){$fact=self::decode($r);
            if(($r['settlement_effect_id']??null)!==null){$serviceHistory=new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($this->db);$service=$serviceHistory->credit((int)$r['supplier_id'],$r,$currentRead);$creditOriginValid=true;$creditOriginEvents=$serviceHistory->events((int)$r['supplier_id'],$service,'credit:'.$r['id'],'credit',$r['amount_cents'],$currentRead);}
            elseif(($r['opening_item_id']??null)!==null){$openingCredits[(int)$r['supplier_id']][]=$r;$creditOriginValid=true;$creditOriginEvents=[];}
            else{$op=$operations[(int)$r['operation_id']]??null;$creditOriginValid=$op&&PropertyInput::hash($op['snapshot']['credit'])===$r['snapshot_hash'];$creditOriginEvents=$op?$operationEvents($op,'credit:'.$r['id'],'credit',$r['amount_cents']):[];}
            if(!$creditOriginValid||$fact['unit_id']!==(int)$r['unit_id']||$fact['amount_cents']!==$r['amount_cents']||$fact['due_date']!==$r['due_date']||PropertyInput::hash(array_column($fact['parties'],null,'id'))!==PropertyInput::hash($creditParties[(int)$r['id']]??[]))ReceivableException::reject('history_corrupt');$credits[(int)$r['id']]=['id'=>(int)$r['id'],'unit_id'=>(int)$r['unit_id'],'snapshot'=>$fact,'snapshot_hash'=>$r['snapshot_hash'],'events'=>$creditOriginEvents];}
        foreach($openingCredits as $supplier=>$group)foreach($openingReader->credits($supplier,$group,$currentRead) as $id=>$opening)$credits[$id]['events']=$eventReader->events($opening,'credit:'.$id,'credit',$credits[$id]['snapshot']['amount_cents'],$currentRead);
        unset($openingCredits,$opening,$group);
        $creditEvents=[];foreach($rows('unit_credit_events') as $r)$creditEvents[]=$r;foreach($creditEvents as $r){$op=$operations[(int)$r['operation_id']]??null;$id=(int)$r['credit_id'];if(!$op||!isset($credits[$id])||$r['amount_cents']!=='-'.$op['amount_cents']||$op['snapshot']['credit']['id']!==$id)ReceivableException::reject('history_corrupt');array_push($credits[$id]['events'],...$operationEvents($op,'credit-use:'.$r['id'],'credit',$r['amount_cents']));}
        $reservations=[];foreach($rows('unit_credit_reservations') as $r)$reservations[$r['id']]=$r;
        foreach($reservations as $r){$request=json_decode($r['request_json'],true,flags:JSON_THROW_ON_ERROR);if(!isset($credits[(int)$r['credit_id']])||$r['amount_cents']!==$request['request']['amount_cents']||!hash_equals($r['request_hash'],PropertyInput::hash(['credit_id'=>(int)$r['credit_id'],'expected_version'=>$request['expected_version'],'request'=>$request['request'],'token'=>$r['idempotency_key'],'actor'=>(int)$r['created_by']])))ReceivableException::reject('history_corrupt');}

        foreach($audit as $r)if($r['object_kind']==='reservation'&&in_array($r['action'],['reserved','cancelled'],true)){
            $reservation=$reservations[$r['object_id']]??null;if(!$reservation)ReceivableException::reject('history_corrupt');$amount=$reservation['amount_cents'];$id=(int)$reservation['credit_id'];
            if($r['action']==='cancelled'){
                $paid='0';foreach($creditEvents as $usage)if((int)$usage['reservation_id']===(int)$reservation['id']){
                    $op=$operations[(int)$usage['operation_id']];if($op['recorded_at']>$r['recorded_at'])continue;$paid=bcsub($paid,$usage['amount_cents'],0);
                    if(isset($reversals[$op['id']])&&$reversals[$op['id']]['recorded_at']<=$r['recorded_at'])$paid=bcadd($paid,$usage['amount_cents'],0);
                }$amount=bcsub($paid,$amount,0);
            }
            $credits[$id]['events'][]=self::event('reservation:'.$r['id'],'reserved',$amount,substr($r['recorded_at'],0,10),self::instant($r['recorded_at']),$r['recorded_at']);
        }
        $cancelledAt=[];foreach($audit as $r)if($r['object_kind']==='reservation'&&$r['action']==='cancelled')$cancelledAt[(int)$r['object_id']]=$r['recorded_at'];
        foreach($creditEvents as $r)if($r['kind']==='refund'){
            $op=$operations[(int)$r['operation_id']];$events=$operationEvents($op,'reserve-paid:'.$r['id'],'reserved',$r['amount_cents']);
            $cancelled=$cancelledAt[(int)$r['reservation_id']]??null;$reverse=$reversals[(int)$op['id']]??null;
            if($cancelled!==null&&$reverse!==null&&$reverse['recorded_at']>$cancelled)array_pop($events);
            array_push($credits[(int)$r['credit_id']]['events'],...$events);
        }
        $unregistered=[];$unresolved=[];$unresolvedLines=[];
        foreach($chargeLines as $r)if(!isset($lineDebt[$r['id']])&&!isset($anchored[$r['id']])&&isset($chargeAudit[$r['charge_id']])){
            $charge=$charges[$r['charge_id']];$fact=$charge['snapshot'];
            if(bccomp($r['amount_cents'],'0',0)<0){$unresolved[]=(int)$r['id'];$unresolvedLines[]=['id'=>(int)$r['id'],'unit_id'=>(int)$charge['unit_id'],'event'=>self::event('unresolved:'.$r['id'],'claim',$r['amount_cents'],$charge['accounting_date'],$fact['claim_origin_at'],$chargeAudit[$r['charge_id']])];continue;}
            $id=-(int)$r['id'];$events=[self::event('unregistered:'.$r['id'],'claim',$r['amount_cents'],$charge['accounting_date'],$fact['claim_origin_at'],$chargeAudit[$r['charge_id']])];
            if(isset($adjustments[$charge['id']])){$a=$adjustments[$charge['id']];$request=json_decode($a['request_json'],true,flags:JSON_THROW_ON_ERROR);$events[]=self::event('unregistered-reversal:'.$r['id'],'claim','-'.$r['amount_cents'],$request['accounting_date'],self::instant($a['effective_at']),$a['recorded_at']);}
            $unregistered[$id]=['id'=>$id,'unit_id'=>(int)$charge['unit_id'],'charge_line_id'=>(int)$r['id'],'snapshot'=>['liability_groups'=>$fact['liability_groups'],'due_date'=>$fact['due_date'],'title_ref'=>$fact['reason']??'','charge_id'=>(int)$charge['id']],'snapshot_hash'=>$charge['snapshot_hash'],'events'=>$events];
        }
        return ['debts'=>$debts,'credits'=>$credits,'unregistered_claims'=>$unregistered,'unregistered_charge_line_ids'=>array_column($unregistered,'charge_line_id'),'unresolved_negative_charge_line_ids'=>$unresolved,'unresolved_negative_charge_lines'=>$unresolvedLines];
    }
    private function rows(string $table,int $supplier,bool $current):\Generator
    {
        $after=0;$client=0;$parties=$table==='unit_credit_parties';
        do {
            $where=$parties?' AND (credit_id>? OR (credit_id=? AND client_id>?))':' AND id>?';
            $args=$parties?[$supplier,$after,$after,$client]:[$supplier,$after];
            $q=$this->db->pdo()->prepare('SELECT * FROM '.$table.' WHERE supplier_id=?'.$where.' ORDER BY '.($parties?'credit_id,client_id':'id').' LIMIT 500'.($current?' FOR UPDATE':''));
            $q->execute($args);$count=0;
            while($row=$q->fetch(PDO::FETCH_ASSOC)) {
                $after=(int)$row[$parties?'credit_id':'id'];if($parties)$client=(int)$row['client_id'];
                $count++;yield $row;
            }
            $q->closeCursor();
        }while($count===500);
    }
    public static function decode(array $row,string $key='snapshot'):array{$value=json_decode($row[$key.'_json'],true,flags:JSON_THROW_ON_ERROR);if(!hash_equals($row[$key.'_hash'],PropertyInput::hash($value)))ReceivableException::reject('history_corrupt');return $value;}
    private static function request(array $row):array{$request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);if(!hash_equals($row['request_hash'],PropertyInput::hash(['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']])))ReceivableException::reject('history_corrupt');return $request;}
    public static function instant(string $sql):string{return PropertyInstant::normalize(str_replace(' ','T',$sql).'Z');}
    private static function event(string $id,string $kind,string $amount,string $date,?string $at,string $recorded):array{return ['id'=>$id,'kind'=>$kind,'amount_cents'=>$amount,'date'=>$date,'effective_at'=>$at===null?null:PropertyInstant::normalize($at),'recorded_at'=>self::instant($recorded)];}
}
