<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Opening;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
use MyInvoice\Service\Property\Opening\{OpeningException,OpeningPlanner,OpeningReadProof,OpeningOwnerProof};
use MyInvoice\Service\Property\Payments\{PaymentLocks,PaymentJournalProof};
use PDO;
final class OpeningRepository
{
    public function __construct(private readonly Connection $db,private readonly OpeningBalanceService $opening){}
    public function preview(int $supplier,array $request):array
    {
        return $this->transaction($supplier,function()use($supplier,$request){$snapshot=$this->plan($supplier,$request);return ['snapshot'=>$snapshot,'preview_hash'=>PropertyInput::hash($snapshot)];});
    }
    public function apply(int $supplier,array $request,string $previewHash,string $token,int $actor):array
    {
        PropertyInput::code($token,'idempotency_key');PropertyInput::id($actor,'actor');
        return $this->transaction($supplier,function()use($supplier,$request,$previewHash,$token,$actor){
            $normalized=\MyInvoice\Service\Property\Opening\OpeningRequest::normalize($request);$hash=PropertyInput::hash(['request'=>$normalized,'token'=>$token,'actor'=>$actor]);
            $old=$this->one('SELECT id,request_hash,snapshot_hash FROM property_opening_batches WHERE supplier_id=? AND idempotency_key=? FOR UPDATE',[$supplier,$token]);
            if($old){if(!hash_equals($old['request_hash'],$hash)||!hash_equals($old['snapshot_hash'],$previewHash))OpeningException::reject('idempotency_conflict');return $this->find($supplier,(int)$old['id']);}
            $snapshot=$this->plan($supplier,$request);if(!hash_equals($previewHash,PropertyInput::hash($snapshot)))OpeningException::reject('preview_stale');$request=$snapshot['request'];
            $this->execute("INSERT INTO property_opening_batches (supplier_id,period_id,previous_id,revision_no,mode,accounting_date,effective_at,request_json,request_hash,snapshot_json,snapshot_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))",[$supplier,$snapshot['period_id'],$snapshot['previous']['id']??null,$request['expected_revision']+1,$request['mode'],$request['accounting_date'],self::sqlTime($request['effective_at']),self::json($request),$hash,self::json($snapshot),$previewHash,$token,$actor]);
            $id=(int)$this->db->pdo()->lastInsertId();$proof=OpeningOwnerProof::fromPending($this->db,$this,$supplier,$id);
            if($request['mode']==='bind_existing'){$entry=$snapshot['existing']['entry']['id'];$reverse=null;}
            else{$written=$this->opening->postPropertyOpening($proof);$entry=$written['journal_entry_id']??$written['reversal_entry_id'];$reverse=$written['reversal_entry_id'];}
            $this->execute("UPDATE property_opening_batches SET status='posted',journal_entry_id=? WHERE supplier_id=? AND id=? AND status='pending'",[$entry,$supplier,$id]);
            $ledger=(new PaymentJournalProof($this->db))->current($supplier,$entry);$linesById=$request['mode']==='bind_existing'?array_column($ledger['lines'],null,'id'):[];$itemIds=[];
            foreach($snapshot['items'] as $item){
                $this->execute('INSERT INTO property_opening_items (supplier_id,batch_id,case_key,kind,unit_id,service_id,origin_at,due_date,period_from,period_to,amount_cents,snapshot_json,snapshot_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[$supplier,$id,$item['case_key'],$item['kind'],$item['unit_id'],$item['service_id'],self::sqlTime($item['origin_at']),$item['due_date'],$item['period_from'],$item['period_to'],$item['amount_cents'],self::json($item),PropertyInput::hash($item)]);
                $itemId=(int)$this->db->pdo()->lastInsertId();$itemIds[$item['case_key']]=$itemId;$components=[];
                foreach($item['components'] as $part){
                    $line=$request['mode']==='bind_existing'?($linesById[$part['source_line_id']]??null):($ledger['lines'][$part['journal_index']]??null);
                    if(!$line||$line['account_code']!==$part['account_code']||$line['side']!==$part['side']||PropertyInput::hash($line['dimensions'])!==PropertyInput::hash($item['dimensions'])||bccomp(bcmul($line['amount'],'100',0),$part['amount_cents'],0)<0)OpeningException::reject('posted_ledger_mismatch');
                    $fact=['component'=>$part,'ledger_line'=>$line];$this->execute('INSERT INTO property_opening_components (supplier_id,item_id,component_key,role,amount_cents,journal_entry_id,journal_line_id,fact_json,fact_hash) VALUES (?,?,?,?,?,?,?,?,?)',[$supplier,$itemId,$part['key'],$part['role'],$part['amount_cents'],$entry,$line['id'],self::json($fact),PropertyInput::hash($fact)]);
                    $components[]=['id'=>(int)$this->db->pdo()->lastInsertId(),'role'=>$part['role'],'amount_cents'=>$part['amount_cents'],'line'=>$line];
                }
                foreach(OpeningReadProof::parties($item) as $party)$this->execute('INSERT INTO property_opening_parties (supplier_id,item_id,client_id,group_key,role,fact_json) VALUES (?,?,?,?,?,?)',[$supplier,$itemId,$party['client_id'],$party['group_key'],$party['role'],self::json($party)]);
                if($item['kind']==='debt')$this->createDebt($supplier,$id,$previewHash,$itemId,$item,$components,$ledger,$request,$actor);
                if($item['kind']==='credit')$this->createCredit($supplier,$itemId,$item,$components[0]);
                $this->createState($supplier,$itemId,$item);
            }
            foreach($snapshot['items'] as $item)foreach(($item['advance_state']??$item['service_state']??[])['unpaid_cases']??[] as $link){$key=PropertyInput::hash(['case_ref'=>trim($link['case_ref']),'occurrence_ref'=>trim($link['occurrence_ref'])]);$this->execute('INSERT INTO property_opening_case_links (supplier_id,item_id,debt_item_id,amount_cents) VALUES (?,?,?,?)',[$supplier,$itemIds[$item['case_key']],$itemIds[$key],$link['amount_cents']]);}
            if($snapshot['previous']!==null)$this->execute("UPDATE property_opening_batches SET status='reversed',reversal_entry_id=? WHERE supplier_id=? AND id=? AND status='posted'",[$reverse,$supplier,$snapshot['previous']['id']]);
            $this->execute('INSERT IGNORE INTO property_opening_heads (supplier_id,period_id) VALUES (?,?)',[$supplier,$snapshot['period_id']]);
            $q=$this->execute('UPDATE property_opening_heads SET batch_id=?,row_version=row_version+1 WHERE supplier_id=? AND period_id=? AND row_version=?',[$id,$supplier,$snapshot['period_id'],$request['expected_revision']]);if($q->rowCount()!==1)OpeningException::reject('revision_conflict');
            (new OpeningReadProof($this->db))->verifyCurrentBatchItems($supplier,$id,array_values($itemIds));
            return $this->find($supplier,$id);
        });
    }
    public function pendingSnapshot(Connection $connection,int $supplier,int $id):array
    {
        if($connection->pdo()!==$this->db->pdo()||!$connection->pdo()->inTransaction())throw new \LogicException('Opening intent requires its owner connection.');
        $row=$this->one('SELECT * FROM property_opening_batches WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);if(!$row||$row['status']!=='pending'||$row['journal_entry_id']!==null)OpeningException::reject('intent_state');
        $request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);$snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
        if(PropertyInput::hash(['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']])!==$row['request_hash']||PropertyInput::hash($snapshot)!==$row['snapshot_hash'])OpeningException::reject('intent_corrupt');
        $current=$this->plan($supplier,\MyInvoice\Service\Property\Opening\OpeningRequest::replay($request),$id);if(PropertyInput::hash($current)!==$row['snapshot_hash'])OpeningException::reject('intent_stale');
        return $snapshot+['actor'=>(int)$row['created_by'],'recorded_at'=>$row['recorded_at']];
    }
    public function find(int $supplier,int $id):array
    {
        return $this->transaction($supplier,function()use($supplier,$id){$batch=(new OpeningReadProof($this->db))->batch($supplier,$id,true);$rows=$this->all('SELECT id,kind,unit_id,service_id,amount_cents,case_key FROM property_opening_items WHERE supplier_id=? AND batch_id=? ORDER BY id',[$supplier,$id]);foreach($rows as &$row)foreach(['id','unit_id','service_id'] as $key)if($row[$key]!==null)$row[$key]=(int)$row[$key];unset($row);return $batch+['items'=>$rows];});
    }
    public function list(int $supplier):array{return $this->transaction($supplier,fn()=>array_map(fn($row)=>$this->find($supplier,(int)$row['id']),$this->all('SELECT id FROM property_opening_batches WHERE supplier_id=? ORDER BY id DESC',[$supplier])));}
    /**
     * Živé otevírací zápisy otevřených období, ke kterým lze převzetí navázat (režim bind_existing).
     * Uživatel zápis vybírá, číslo a verzi zápisu tedy nevypisuje ručně. Otevřených období je jen pár.
     */
    public function bindCandidates(int $supplier):array
    {
        $closing=new \MyInvoice\Repository\ClosingRepository($this->db);$entries=[];
        foreach($this->all("SELECT id FROM accounting_periods WHERE supplier_id=? AND status='open' ORDER BY starts_on",[$supplier]) as $period)
            foreach($closing->openingEntriesInPeriod($supplier,(int)$period['id']) as $entry)$entries[$entry['id']]=$entry;
        if($entries===[])return [];
        $versions=[];
        foreach($this->all('SELECT id,row_version FROM journal_entries WHERE supplier_id=? AND id IN ('.implode(',',array_fill(0,count($entries),'?')).')',[$supplier,...array_keys($entries)]) as $row)$versions[(int)$row['id']]=(int)$row['row_version'];
        $out=[];
        foreach($entries as $id=>$entry)$out[]=['journal_entry_id'=>$id,'journal_version'=>$versions[$id],'document_no'=>$entry['document_no'],'entry_date'=>$entry['entry_date'],'description'=>$entry['description']];
        return $out;
    }
    public function catalog(int $supplier,int $after,int $limit):array
    {
        return $this->transaction($supplier,function()use($supplier,$after,$limit){$rows=$this->all('SELECT id FROM property_opening_batches WHERE supplier_id=? AND id>? ORDER BY id LIMIT '.($limit+1),[$supplier,$after]);$more=count($rows)>$limit;if($more)array_pop($rows);$items=[];
            foreach($rows as $row){$batch=$this->find($supplier,(int)$row['id']);$public=array_intersect_key($batch,array_flip(['id','revision_no','status','mode','accounting_date','effective_at','recorded_at','journal_entry_id','reversal_entry_id','previous_id']));$public['items']=[];
                $provenItems=(new OpeningReadProof($this->db))->currentBatchItems($supplier,$batch['id'],array_column($batch['items'],'id'));
                foreach($batch['items'] as $item){$proven=$provenItems[$item['id']];$public['items'][]=array_intersect_key($item,array_flip(['id','kind','unit_id','service_id','amount_cents']))+['roles'=>array_map(fn($part)=>['role'=>$part['role'],'amount_cents'=>$part['amount_cents'],'account_code'=>$part['line']['account_code']],$proven['components'])];}$items[]=$public;
            }return ['items'=>$items,'next_cursor'=>$more?$items[array_key_last($items)]['id']:null,'scope'=>'recorded_opening_batches','complete_financial_position'=>false];});
    }
    private function plan(int $supplier,array $request,?int $pending=null):array
    {
        $snapshot=(new OpeningPlanner($this->db))->current($supplier,$request,$pending);$blocker=$this->opening->postBlocker($supplier,$snapshot['request']['accounting_date']);
        if($blocker!==null&&($blocker['code']!=='opening_already_posted'||$snapshot['request']['mode']==='post_new'))OpeningException::reject($blocker['code']);return $snapshot;
    }
    private function createDebt(int $supplier,int $batchId,string $batchHash,int $itemId,array $item,array $components,array $ledger,array $request,int $actor):void
    {
        $snapshot=\MyInvoice\Service\Property\Opening\OpeningOriginProof::debtSnapshot($itemId,$item,$components,$ledger['entry']['id']);
        $payload=['opening_item_id'=>$itemId];$token='opening.'.$itemId;
        $this->execute('INSERT INTO unit_payment_debts (supplier_id,charge_id,opening_item_id,request_json,snapshot_json,request_hash,snapshot_hash,idempotency_key,created_by,recorded_at) VALUES (?,NULL,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$supplier,$itemId,self::json($payload),self::json($snapshot),PropertyInput::hash(['request'=>$payload,'token'=>$token,'actor'=>$actor]),PropertyInput::hash($snapshot),$token,$actor]);$debt=(int)$this->db->pdo()->lastInsertId();
        foreach($components as $component)if($component['role']!=='principal'){
            $line=$component['line'];$line['amount']=bcdiv($component['amount_cents'],'100',2);$receipt=['debt_id'=>$debt,'opening_component_id'=>$component['id'],'request'=>['component'=>$component['role'],'evidence'=>$item['evidence_ref'],'liability_evidence'=>$item['title_ref']],'liability_groups'=>$item['liability_groups'],'opening_origin'=>['version'=>1,'batch_id'=>$batchId,'item_id'=>$itemId,'component_id'=>$component['id'],'journal_entry_id'=>$ledger['entry']['id'],'journal_line_id'=>$line['id'],'batch_snapshot_hash'=>$batchHash,'item_snapshot_hash'=>PropertyInput::hash($item)],'line'=>$line,'actor'=>$actor,'opening_effective_at'=>$request['effective_at']];
            $this->execute('INSERT INTO unit_claim_accessory_receipts (supplier_id,charge_id,debt_id,opening_component_id,journal_entry_id,journal_line_id,component,amount_cents,receipt_json,receipt_hash,created_by,recorded_at) VALUES (?,NULL,?,?,?,NULL,?,?,?,?,?,UTC_TIMESTAMP(6))',[$supplier,$debt,$component['id'],$ledger['entry']['id'],$component['role'],$component['amount_cents'],self::json($receipt),PropertyInput::hash($receipt),$actor]);
        }
    }
    private function createCredit(int $supplier,int $itemId,array $item,array $component):void
    {
        $line=$component['line'];$line['amount']=bcdiv($component['amount_cents'],'100',2);$parties=array_values(array_filter($item['parties'],fn($party)=>in_array($party['id'],$item['creditor_ids'],true)));
        $snapshot=['opening_item_id'=>$itemId,'unit_id'=>$item['unit_id'],'amount_cents'=>$item['amount_cents'],'due_date'=>$item['due_date'],'parties'=>$parties,'line'=>$line,'entitlement'=>['basis'=>'documented_opening','evidence'=>$item['evidence_ref'],'title_ref'=>$item['title_ref']]];
        $this->execute('INSERT INTO unit_credit_lots (supplier_id,operation_id,opening_item_id,unit_id,amount_cents,due_date,snapshot_json,snapshot_hash) VALUES (?,NULL,?,?,?,?,?,?)',[$supplier,$itemId,$item['unit_id'],$item['amount_cents'],$item['due_date'],self::json($snapshot),PropertyInput::hash($snapshot)]);$id=(int)$this->db->pdo()->lastInsertId();
        foreach($parties as $party)$this->execute('INSERT INTO unit_credit_parties (supplier_id,credit_id,client_id,party_json) VALUES (?,?,?,?)',[$supplier,$id,$party['id'],self::json($party)]);
    }
    private function createState(int $supplier,int $itemId,array $item):void
    {
        $state=$item['advance_state']??$item['service_state']??null;if($state===null)return;
        $this->execute('INSERT INTO property_opening_states (supplier_id,item_id,profile,purpose_ref,counterpart_ref,settlement_due_on,prescribed_cents,paid_cents,used_cents,settled_cents,cancelled_unpaid_cents) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$supplier,$itemId,$state['profile']??null,$state['purpose_ref']??$state['allocation_ref'],$state['counterpart_ref']??null,$state['settlement_due_on']??null,$state['prescribed_cents'],$state['paid_cents'],$state['used_cents']??'0',$state['settled_cents']??'0',$state['cancelled_unpaid_cents']??'0']);
        foreach($state['recipient_ids']??[] as $client)$this->execute('INSERT INTO property_opening_recipients (supplier_id,item_id,client_id) VALUES (?,?,?)',[$supplier,$itemId,$client]);
    }
    private function transaction(int $supplier,callable $work):mixed{return (new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$work){(new PaymentLocks($this->db))->acquire($supplier);return $work();});}
    private function execute(string $sql,array $params):\PDOStatement{$q=$this->db->pdo()->prepare($sql);$q->execute($params);return $q;}
    private function all(string $sql,array $params):array{return $this->execute($sql,$params)->fetchAll(PDO::FETCH_ASSOC);}
    private function one(string $sql,array $params):?array{return $this->all($sql,$params)[0]??null;}
    private static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
    private static function sqlTime(string $at):string{return (new \DateTimeImmutable($at))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');}
}
