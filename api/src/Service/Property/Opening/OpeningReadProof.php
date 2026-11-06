<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Opening;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use PDO;
final class OpeningReadProof
{
    public function __construct(private readonly Connection $db){}
    public function batch(int $supplier,int $id,bool $current):array
    {
        $row=$this->one('SELECT * FROM property_opening_batches WHERE supplier_id=? AND id=?',[$supplier,$id],$current);if(!$row)OpeningException::reject('history_missing');
        $snapshot=self::decode($row,'snapshot');$request=self::decode($row,'request');
        if(PropertyInput::hash(['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']])!==$row['request_hash'])OpeningException::reject('history_corrupt');
        foreach(['mode','accounting_date'] as $field)if($row[$field]!==$request[$field])OpeningException::reject('history_corrupt');
        if(PropertyInstant::normalize(str_replace(' ','T',$row['effective_at']).'Z')!==$request['effective_at']||$snapshot['request']!==$request||(int)$row['period_id']!==$snapshot['period_id']||(int)$row['revision_no']!==$request['expected_revision']+1||(int)($row['previous_id']??0)!==(int)($snapshot['previous']['id']??0))OpeningException::reject('history_corrupt');
        foreach(['id','supplier_id','period_id','previous_id','journal_entry_id','reversal_entry_id','revision_no','created_by'] as $key)if($row[$key]!==null)$row[$key]=(int)$row[$key];
        unset($row['snapshot_json'],$row['request_json']);$row['effective_at']=$request['effective_at'];$row['recorded_at']=PropertyInstant::normalize(str_replace(' ','T',$row['recorded_at']).'Z');
        return $row+['snapshot'=>$snapshot,'request'=>$request];
    }
    public function verifyCurrentBatchItems(int $supplier,int $batchId,array $itemIds):void
    {
        $this->currentItemsInScope($supplier,$batchId,$itemIds,false);
    }
    public function currentBatchItems(int $supplier,int $batchId,array $itemIds):array
    {
        return $this->currentItemsInScope($supplier,$batchId,$itemIds,true);
    }
    private function currentItemsInScope(int $supplier,int $batchId,array $itemIds,bool $retainResults):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Opening proof requires a stable snapshot.');
        $contexts=[];$items=[];
        foreach(array_chunk($itemIds,500) as $chunk){$scope=$this->scope($supplier,$chunk,true);foreach($chunk as $id){$item=$this->itemInScope($supplier,$id,true,$contexts,$batchId,$scope);if($retainResults)$items[$id]=$item;}}
        return $items;
    }
    public function items(int $supplier,array $ids,bool $current):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Opening proof requires a stable snapshot.');
        $contexts=[];$items=[];
        foreach(array_chunk($ids,500) as $chunk){$scope=$this->scope($supplier,$chunk,$current);foreach($chunk as $id)$items[$id]=$this->itemInScope($supplier,$id,$current,$contexts,null,$scope);}
        return $items;
    }
    public function item(int $supplier,int $id,bool $current):array
    {
        return $this->items($supplier,[$id],$current)[$id];
    }
    private function itemInScope(int $supplier,int $id,bool $current,array &$contexts,?int $batchId,array $scope):array
    {
        $row=$scope['items'][$id]??null;if(!$row)OpeningException::reject('item_missing');$fact=self::decode($row,'snapshot');
        foreach(['kind','case_key','amount_cents','period_from','period_to','due_date'] as $key)if($row[$key]!==$fact[$key])OpeningException::reject('item_corrupt');
        if((int)$row['unit_id']!==$fact['unit_id']||($row['service_id']===null?null:(int)$row['service_id'])!==$fact['service_id']||PropertyInstant::normalize(str_replace(' ','T',$row['origin_at']).'Z')!==$fact['origin_at'])OpeningException::reject('item_corrupt');
        foreach($fact['registry'] as $registry)if(PropertyInput::hash($registry['revision']['state'])!==$registry['revision']['state_hash'])OpeningException::reject('registry_corrupt');
        if($batchId!==null&&(int)$row['batch_id']!==$batchId)OpeningException::reject('item_corrupt');
        $context=&$contexts[(int)$row['batch_id']];
        if($context===null){
            $batch=$this->batch($supplier,(int)$row['batch_id'],$current);if(!in_array($batch['status'],['posted','reversed'],true))OpeningException::reject('unposted');
            $planned=[];foreach($batch['snapshot']['items'] as $candidate)$planned[serialize($candidate['case_key'])]=$candidate;
            $context=['batch'=>$batch,'planned'=>$planned];
        }
        $batch=$context['batch'];$planned=$context['planned'][serialize($fact['case_key'])]??null;
        if($planned===null||PropertyInput::hash($planned)!==$row['snapshot_hash'])OpeningException::reject('item_corrupt');
        if(!isset($context['lines'])){
            $ledger=(new PaymentJournalProof($this->db))->snapshot($supplier,$batch['journal_entry_id'],$current);$expectedReversal=$batch['status']==='reversed'?$batch['reversal_entry_id']:null;
            if($ledger['entry']['reversed_by']!==$expectedReversal||$ledger['entry']['source_type']!=='opening'||$ledger['entry']['entry_date']!==$batch['accounting_date'])OpeningException::reject('ledger_changed');
            if($expectedReversal!==null){$mirror=(new PaymentJournalProof($this->db))->snapshot($supplier,$expectedReversal,$current);$expected=$ledger['lines'];foreach($expected as &$line)$line['side']=$line['side']==='debit'?'credit':'debit';unset($line);if(PropertyInput::hash(PaymentJournalProof::canonicalLines($expected))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($mirror['lines'])))OpeningException::reject('reversal_changed');}
            $context['ledger']=$ledger;$context['lines']=array_column($ledger['lines'],null,'id');
        }
        $components=$scope['components'][$id]??[];if(count($components)!==count($fact['components']))OpeningException::reject('component_missing');
        $parts=[];$expectedParts=array_column($fact['components'],null,'key');$lines=$context['lines'];
        foreach($components as $component){$part=self::decode($component,'fact');$expected=$expectedParts[$component['component_key']]??null;
            if(!$expected||$component['role']!==$expected['role']||$component['amount_cents']!==$expected['amount_cents']||$part['component']!==$expected||(int)$component['journal_entry_id']!==$batch['journal_entry_id']||(int)$component['journal_line_id']!==$part['ledger_line']['id'])OpeningException::reject('component_corrupt');
            $line=$lines[(int)$component['journal_line_id']]??null;if(!$line||PropertyInput::hash($line)!==PropertyInput::hash($part['ledger_line'])||bccomp(bcmul($line['amount'],'100',0),$component['amount_cents'],0)<0)OpeningException::reject('ledger_changed');
            $parts[]=['id'=>(int)$component['id'],'role'=>$component['role'],'amount_cents'=>$component['amount_cents'],'line'=>$line,'component'=>$expected];
        }
        $actual=[];foreach(($scope['parties'][$id]??[]) as $party){$value=json_decode($party['fact_json'],true,flags:JSON_THROW_ON_ERROR);if($value['client_id']!==(int)$party['client_id']||$value['group_key']!==$party['group_key']||$value['role']!==$party['role'])OpeningException::reject('party_corrupt');$actual[]=$value;}
        if(PropertyInput::hash($actual)!==PropertyInput::hash(self::parties($fact)))OpeningException::reject('party_corrupt');
        $this->state($id,$fact,$scope);
        return ['id'=>$id,'batch'=>$batch,'snapshot'=>$fact,'snapshot_hash'=>$row['snapshot_hash'],'components'=>$parts,'ledger'=>$context['ledger'],'active'=>$batch['status']==='posted'];
    }
    private function state(int $id,array $fact,array $scope):void
    {
        $state=$fact['advance_state']??$fact['service_state']??null;
        $row=$scope['states'][$id]??null;if($row!==null)unset($row['item_id']);
        $expected=$state===null?null:['profile'=>$state['profile']??null,'purpose_ref'=>$state['purpose_ref']??$state['allocation_ref'],'counterpart_ref'=>$state['counterpart_ref']??null,'settlement_due_on'=>$state['settlement_due_on']??null,'prescribed_cents'=>$state['prescribed_cents'],'paid_cents'=>$state['paid_cents'],'used_cents'=>$state['used_cents']??'0','settled_cents'=>$state['settled_cents']??'0','cancelled_unpaid_cents'=>$state['cancelled_unpaid_cents']??'0'];
        if(PropertyInput::hash([$row])!==PropertyInput::hash([$expected]))OpeningException::reject('state_corrupt');
        $recipients=array_map('intval',array_column(($scope['recipients'][$id]??[]),'client_id'));$expectedRecipients=$state['recipient_ids']??[];sort($expectedRecipients,SORT_NUMERIC);if($recipients!==$expectedRecipients)OpeningException::reject('recipient_corrupt');
        $links=[];foreach($scope['links'][$id]??[] as $link){unset($link['item_id']);$links[]=$link;}
        $expectedLinks=[];foreach($state['unpaid_cases']??[] as $link)$expectedLinks[]=['case_key'=>PropertyInput::hash(['case_ref'=>trim($link['case_ref']),'occurrence_ref'=>trim($link['occurrence_ref'])]),'amount_cents'=>$link['amount_cents']];usort($expectedLinks,fn($a,$b)=>$a['case_key']<=>$b['case_key']);
        if(count($scope['raw_links'][$id]??[])!==count($links)||PropertyInput::hash($links)!==PropertyInput::hash($expectedLinks))OpeningException::reject('case_links_corrupt');
    }
    private function scope(int $supplier,array $ids,bool $current):array
    {
        $scope=['items'=>[],'components'=>[],'parties'=>[],'states'=>[],'recipients'=>[],'links'=>[],'raw_links'=>[]];
        foreach(array_chunk(array_values(array_unique($ids)),500) as $chunk){
            $marks=implode(',',array_fill(0,count($chunk),'?'));$params=[$supplier,...$chunk];
            foreach($this->all("SELECT * FROM property_opening_items WHERE supplier_id=? AND id IN ($marks) ORDER BY id",$params,$current) as $row)$scope['items'][(int)$row['id']]=$row;
            foreach(['components'=>'component_key','parties'=>'group_key,client_id','recipients'=>'client_id'] as $table=>$order){
                foreach($this->all("SELECT * FROM property_opening_$table WHERE supplier_id=? AND item_id IN ($marks) ORDER BY item_id,$order",$params,$current) as $row)$scope[$table][(int)$row['item_id']][]=$row;
            }
            foreach($this->all("SELECT item_id,profile,purpose_ref,counterpart_ref,settlement_due_on,prescribed_cents,paid_cents,used_cents,settled_cents,cancelled_unpaid_cents FROM property_opening_states WHERE supplier_id=? AND item_id IN ($marks) ORDER BY item_id",$params,$current) as $row)$scope['states'][(int)$row['item_id']]=$row;
            foreach($this->all("SELECT item_id,debt_item_id,amount_cents FROM property_opening_case_links WHERE supplier_id=? AND item_id IN ($marks) ORDER BY item_id,debt_item_id",$params,$current) as $row)$scope['raw_links'][(int)$row['item_id']][]=$row;
            foreach($this->all("SELECT l.item_id,d.case_key,l.amount_cents FROM property_opening_case_links l JOIN property_opening_items i ON i.id=l.item_id AND i.supplier_id=l.supplier_id JOIN property_opening_items d ON d.id=l.debt_item_id AND d.supplier_id=l.supplier_id AND d.batch_id=i.batch_id AND d.unit_id=i.unit_id AND d.service_id<=>i.service_id AND d.kind='debt' WHERE l.supplier_id=? AND l.item_id IN ($marks) ORDER BY l.item_id,d.case_key",$params,$current) as $row)$scope['links'][(int)$row['item_id']][]=$row;
        }
        return $scope;
    }
    public static function parties(array $fact):array
    {
        $out=[];foreach($fact['liability_groups']??[] as $group)foreach($group['members'] as $id)$out[]=['client_id'=>$id,'group_key'=>$group['group_key'],'role'=>$group['role'],'group'=>$group];
        foreach($fact['creditor_ids']??[] as $id)$out[]=['client_id'=>$id,'group_key'=>'creditor','role'=>'creditor'];
        usort($out,static fn($a,$b)=>[$a['group_key'],$a['client_id']]<=>[$b['group_key'],$b['client_id']]);return $out;
    }
    public function events(array $item,string $identity,string $kind,string $amount,bool $current=false):array
    {
        $batch=$item['batch'];$events=[['id'=>$identity,'kind'=>$kind,'amount_cents'=>$amount,'date'=>$batch['accounting_date'],'effective_at'=>$batch['effective_at'],'recorded_at'=>$batch['recorded_at']]];
        if($batch['status']==='reversed'){
            $next=$this->one("SELECT * FROM property_opening_batches WHERE supplier_id=? AND previous_id=? AND status IN ('posted','reversed')",[$batch['supplier_id'],$batch['id']],$current);if(!$next)OpeningException::reject('reversal_missing');
            $events[]=['id'=>$identity.':reversal','kind'=>$kind,'amount_cents'=>'-'.$amount,'date'=>$next['accounting_date'],'effective_at'=>PropertyInstant::normalize(str_replace(' ','T',$next['effective_at']).'Z'),'recorded_at'=>PropertyInstant::normalize(str_replace(' ','T',$next['recorded_at']).'Z')];
        }return $events;
    }
    private static function decode(array $row,string $field):array
    {
        $value=json_decode($row[$field.'_json'],true,flags:JSON_THROW_ON_ERROR);
        if($field!=='request'&&!hash_equals($row[$field.'_hash'],$field==='snapshot'?PropertyInput::hashLists($value,['items']):PropertyInput::hash($value)))OpeningException::reject('history_corrupt');return $value;
    }
    private function one(string $sql,array $params,bool $current):?array{return $this->all($sql,$params,$current)[0]??null;}
    private function all(string $sql,array $params,bool $current):array{if(!$this->db->pdo()->inTransaction())throw new \LogicException('Opening proof requires a stable snapshot.');$q=$this->db->pdo()->prepare($sql.($current?' FOR UPDATE':''));$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);}
}
