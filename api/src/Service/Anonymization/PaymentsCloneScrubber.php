<?php

declare(strict_types=1);
namespace MyInvoice\Service\Anonymization;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Payments\{PaymentException,PaymentJournalProof,PaymentLocks,PaymentSourceProof};
use MyInvoice\Repository\Property\Payments\DebtRepository;
use PDO;
final class PaymentsCloneScrubber
{
    private const TABLES=['unit_payment_sources','unit_payment_operations','unit_claim_accessory_receipts','unit_payment_allocations','unit_credit_lots','unit_credit_parties','unit_credit_reservations','unit_credit_events','unit_payment_audit','unit_payment_debts','unit_payment_debt_lines','unit_payment_corrections','unit_payment_correction_anchors','unit_payment_reliefs'];
    private const TEXT=['case_ref','occurrence_ref','purpose_ref','counterpart_ref','allocation_ref','reason','title_ref','evidence','liability_evidence','disposition','debtor_evidence','creditor_consent','evidence_ref','legal_reason','basis_ref','description','name','unit_no','ref','recipient_evidence','mutuality_evidence','eligibility_evidence','declaration_evidence','delivery_evidence','effect_evidence','scope_1984_evidence','early_performance_evidence'];
    private array $hashes=[];
    public function __construct(private readonly Connection $db,private readonly TextScrubber $scrubber) {}
    public function run(array $issuanceMap=[],array $registryMap=[],array $settlementInputs=[]):array
    {
        $pdo=$this->db->pdo();$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',array_fill(0,count(self::TABLES),'?')).')');$q->execute(self::TABLES);$count=(int)$q->fetchColumn();
        if($count===0)return [];
        if($count!==count(self::TABLES)||$pdo->inTransaction())throw new \LogicException('Payment clone requires complete schema and its own transaction.');
        if(TriggerMetadata::any($pdo))throw new \LogicException('Payment clone must precede trigger installation.');
        $this->hashes=[];$pdo->beginTransaction();
        try{
            $rows=[];foreach(self::TABLES as $table){$order=match($table){'unit_credit_parties'=>',credit_id,client_id','unit_payment_debt_lines'=>',debt_id,charge_line_id','unit_payment_correction_anchors'=>',correction_id,adjustment_line_id,original_line_id','unit_payment_reliefs'=>',correction_id,allocation_id',default=>',id'};$rows[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY supplier_id'.$order.' FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);}
            foreach($rows['unit_payment_sources'] as $r)self::hash($r['receipt_hash'],self::decode($r['receipt_json']));
            foreach($rows['unit_claim_accessory_receipts'] as $r)self::hash($r['receipt_hash'],self::decode($r['receipt_json']));
            foreach($rows['unit_credit_lots'] as $r)self::hash($r['snapshot_hash'],self::decode($r['snapshot_json']));
            foreach($rows['unit_payment_audit'] as $r)self::hash($r['payload_hash'],self::decode($r['payload_json']));
            foreach(['unit_payment_operations','unit_payment_debts','unit_payment_corrections'] as $table)foreach($rows[$table] as $r){self::hash($r['snapshot_hash'],self::decode($r['snapshot_json']));self::hash($r['request_hash'],['request'=>self::decode($r['request_json']),'token'=>$r['idempotency_key'],'actor'=>(int)$r['created_by']]);}
            foreach($rows['unit_payment_corrections'] as $r){$snapshot=self::decode($r['snapshot_json']);foreach([$snapshot['adjustment'],...array_column($snapshot['originals'],'charge')] as $owner){
                if(($owner['supplier_id']??null)!==(int)$r['supplier_id'])PaymentException::reject('history_corrupt');$proof=$issuanceMap[$r['supplier_id']][$owner['id']]??null;
                if(!is_array($proof)||$proof['before_snapshot_hash']!==$owner['snapshot_hash'])PaymentException::reject('clone_issuance_proof');
                $q=$pdo->prepare('SELECT snapshot_hash FROM unit_charges WHERE supplier_id=? AND id=?');$q->execute([$r['supplier_id'],$owner['id']]);if($q->fetchColumn()!==$proof['after_snapshot_hash'])PaymentException::reject('clone_issuance_proof');
                $this->hashes[$proof['before_snapshot_hash']]=$proof['after_snapshot_hash'];
            }}
            foreach($issuanceMap as $supplier=>$proofs)foreach($proofs as $id=>$proof){$q=$pdo->prepare('SELECT snapshot_hash FROM unit_charges WHERE supplier_id=? AND id=?');$q->execute([$supplier,$id]);if($q->fetchColumn()!==$proof['after_snapshot_hash'])PaymentException::reject('clone_issuance_proof');$this->hashes[$proof['before_snapshot_hash']]=$proof['after_snapshot_hash'];}
            foreach($rows['unit_credit_reservations'] as $r){$data=self::decode($r['request_json']);self::hash($r['request_hash'],['credit_id'=>(int)$r['credit_id'],'expected_version'=>$data['expected_version'],'request'=>$data['request'],'token'=>$r['idempotency_key'],'actor'=>(int)$r['created_by']]);}
            (new OpeningCloneScrubber($this->db,$this->scrubber))->run($registryMap,fn(array $value):array=>$this->text($value),fn(array $value):array=>$this->references($value),fn(string $hash,array $value):string=>$this->map($hash,$value));
            $workflowClone=PaymentWorkflowCloneScrubber::capture($this->db,$this->scrubber,fn(array $value):array=>$this->text($value));
            foreach(['unit_payment_sources','unit_claim_accessory_receipts'] as $table)foreach($rows[$table] as $r){$receipt=$this->text(self::decode($r['receipt_json']));if($table==='unit_claim_accessory_receipts'&&($r['opening_component_id']??null)!==null)$receipt=$this->references($receipt);$hash=$this->map($r['receipt_hash'],$receipt);$this->update($table,$r,['receipt_json'=>self::json($receipt),'receipt_hash'=>$hash]);}
            foreach($rows['unit_payment_debts'] as $r){$request=$this->text(self::decode($r['request_json']));$snapshot=$this->text(self::decode($r['snapshot_json']));$this->update('unit_payment_debts',$r,['request_json'=>self::json($request),'snapshot_json'=>self::json($snapshot),'request_hash'=>$this->map($r['request_hash'],['request'=>$request,'token'=>$r['idempotency_key'],'actor'=>(int)$r['created_by']]),'snapshot_hash'=>$this->map($r['snapshot_hash'],$snapshot)]);}
            foreach($rows['unit_credit_lots'] as $r){$snapshot=$this->text(self::decode($r['snapshot_json']));$this->update('unit_credit_lots',$r,['snapshot_json'=>self::json($snapshot),'snapshot_hash'=>$this->map($r['snapshot_hash'],$snapshot)]);}
            foreach($rows['unit_credit_parties'] as $r)$this->update('unit_credit_parties',$r,['party_json'=>self::json($this->text(self::decode($r['party_json'])))]);
            foreach($rows['unit_credit_reservations'] as $r){$data=$this->text(self::decode($r['request_json']));$hash=$this->map($r['request_hash'],['credit_id'=>(int)$r['credit_id'],'expected_version'=>$data['expected_version'],'request'=>$data['request'],'token'=>$r['idempotency_key'],'actor'=>(int)$r['created_by']]);$this->update('unit_credit_reservations',$r,['request_json'=>self::json($data),'request_hash'=>$hash]);}
            foreach($rows['unit_payment_operations'] as $r){$request=$this->text(self::decode($r['request_json']));$snapshot=$this->references($this->text(self::decode($r['snapshot_json'])));$this->update('unit_payment_operations',$r,['request_json'=>self::json($request),'snapshot_json'=>self::json($snapshot),'request_hash'=>$this->map($r['request_hash'],['request'=>$request,'token'=>$r['idempotency_key'],'actor'=>(int)$r['created_by']]),'snapshot_hash'=>$this->map($r['snapshot_hash'],$snapshot)]);}
            foreach($rows['unit_payment_corrections'] as $r){$request=$this->text(self::decode($r['request_json']));$snapshot=$this->references($this->text(self::decode($r['snapshot_json'])));$this->update('unit_payment_corrections',$r,['request_json'=>self::json($request),'snapshot_json'=>self::json($snapshot),'request_hash'=>$this->map($r['request_hash'],['request'=>$request,'token'=>$r['idempotency_key'],'actor'=>(int)$r['created_by']]),'snapshot_hash'=>$this->map($r['snapshot_hash'],$snapshot)]);}
            foreach($rows['unit_credit_events'] as $r)$this->update('unit_credit_events',$r,['event_json'=>self::json($this->references($this->text(self::decode($r['event_json']))))]);
            foreach($rows['unit_payment_audit'] as $r){$payload=$this->references($this->text(self::decode($r['payload_json'])));$this->update('unit_payment_audit',$r,['payload_json'=>self::json($payload),'payload_hash'=>PropertyInput::hash($payload)]);}
            $workflowClone?->finish($this->hashes,fn(array $value):array=>$this->references($value));
            SettlementCloneScrubber::run($this->db,$this->scrubber,$registryMap,$this->hashes,fn(array $value):array=>$this->text($value),fn(string $hash,array $value):string=>$this->map($hash,$value),$settlementInputs);
            $suppliers=array_values(array_unique(array_merge(...array_values(array_map(fn($data)=>array_column($data,'supplier_id'),$rows)))));sort($suppliers,SORT_NUMERIC);
            foreach($suppliers as $supplier)(new PaymentLocks($this->db))->acquire((int)$supplier);
            foreach($rows['unit_payment_sources'] as $r)(new PaymentSourceProof($this->db))->current((int)$r['supplier_id'],$r['bank_transaction_id']!==null?'bank':'cash',(int)($r['bank_transaction_id']??$r['cash_document_id']));
            foreach($suppliers as $supplier)(new DebtRepository($this->db))->allCurrent((int)$supplier);
            foreach($suppliers as $supplier)(new \MyInvoice\Service\Property\Payments\CorrectionHistory($this->db))->current((int)$supplier);
            foreach($rows['unit_payment_operations'] as $r){
                $snapshot=$this->references($this->text(self::decode($r['snapshot_json'])));
                if($r['status']==='pending')continue;
                $ledger=(new PaymentJournalProof($this->db))->current((int)$r['supplier_id'],(int)$r['journal_entry_id']);
                if($ledger['entry']['source_type']!=='unit_payment'||$ledger['entry']['source_id']!==($r['kind']==='reversal'?null:(int)$r['id'])||$ledger['entry']['entry_date']!==$r['accounting_date']||$ledger['entry']['reversed_by']!==($r['status']==='reversed'?(int)$r['reversal_entry_id']:null)||PropertyInput::hash(PaymentJournalProof::canonicalLines($ledger['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($snapshot['lines'])))PaymentException::reject('ledger_mismatch');
            }
            $this->assertMaterialized();
            $pdo->commit();return $this->hashes;
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    private function text(array $data):array
    {
        $out=[];
        foreach($data as $key=>$value){
            if($key==='cost_center'&&is_string($value))$out[$key]=$this->scrubber->byStrategy('cost_center',$value);
            elseif(is_array($value))$out[$key]=$this->text($value);
            elseif(is_string($value)&&in_array((string)$key,self::TEXT,true))$out[$key]=$this->scrubber->scrub($value);
            elseif(is_string($value)&&in_array((string)$key,['company_name','first_name','last_name','main_email','counterparty_account','recipient_account','partner_name','recipient_name'],true)){
                $name=$key==='recipient_account'?'bank_account':$key;$decoded=self::decode($this->scrubber->scrubJson(self::json([$name=>$value])));$out[$key]=$decoded[$name];
            }else $out[$key]=$value;
        }
        if(isset($out['case_key'],$out['case_ref'],$out['occurrence_ref']))$out['case_key']=PropertyInput::hash(['case_ref'=>$out['case_ref'],'occurrence_ref'=>$out['occurrence_ref']]);
        return $out;
    }
    private function assertMaterialized():void
    {
        $pdo=$this->db->pdo();$operations=[];$credits=[];
        foreach($pdo->query('SELECT * FROM unit_payment_operations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row){
            $snapshot=self::decode($row['snapshot_json']);$request=self::decode($row['request_json']);foreach(['kind','amount_cents','accounting_date'] as $key)if($row[$key]!==$request[$key])PaymentException::reject('history_corrupt');
            if(PropertyInput::hash($request)!==PropertyInput::hash($snapshot['request']))PaymentException::reject('history_corrupt');$allocations=[];
            $q=$pdo->prepare('SELECT * FROM unit_payment_allocations WHERE supplier_id=? AND operation_id=? ORDER BY id');$q->execute([$row['supplier_id'],$row['id']]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $part){$fact=self::decode($part['allocation_json']);foreach(['debt_id','charge_id','charge_line_id','accessory_id','debtor_id','liability_group','amount_cents'] as $key)if((is_int($fact[$key])?(int)$part[$key]:$part[$key])!==$fact[$key])PaymentException::reject('history_corrupt');$allocations[]=$fact;}
            if(PropertyInput::hash($allocations)!==PropertyInput::hash($snapshot['allocations']??[]))PaymentException::reject('history_corrupt');$operations[$row['supplier_id']][$row['id']]=$row+['snapshot'=>$snapshot];
        }
        foreach($pdo->query('SELECT * FROM unit_credit_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row){
            $snapshot=self::decode($row['snapshot_json']);foreach(['unit_id','amount_cents','due_date'] as $key)if((is_int($snapshot[$key])?(int)$row[$key]:$row[$key])!==$snapshot[$key])PaymentException::reject('history_corrupt');
            if(($row['settlement_effect_id']??null)!==null)(new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($this->db))->credit((int)$row['supplier_id'],$row,true);elseif(($row['opening_item_id']??null)!==null)(new \MyInvoice\Service\Property\Opening\OpeningOriginProof($this->db))->credit((int)$row['supplier_id'],$row,true);else{$origin=$operations[$row['supplier_id']][$row['operation_id']]??null;if(!$origin||PropertyInput::hash($origin['snapshot']['credit']??[])!==$row['snapshot_hash'])PaymentException::reject('history_corrupt');}
            $q=$pdo->prepare('SELECT client_id,party_json FROM unit_credit_parties WHERE supplier_id=? AND credit_id=? ORDER BY client_id');$q->execute([$row['supplier_id'],$row['id']]);$parties=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $party){$fact=self::decode($party['party_json']);if($fact['id']!==(int)$party['client_id'])PaymentException::reject('history_corrupt');$parties[]=$fact;}
            if(PropertyInput::hash($parties)!==PropertyInput::hash($snapshot['parties']))PaymentException::reject('history_corrupt');$credits[$row['supplier_id']][$row['id']]=$row;
        }
        $paid=[];$effects=[];
        foreach($pdo->query('SELECT * FROM unit_credit_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row){
            $op=$operations[$row['supplier_id']][$row['operation_id']]??null;$credit=$credits[$row['supplier_id']][$row['credit_id']]??null;
            if(!$op||!$credit||!in_array($row['kind'],['refund','setoff'],true)||$op['kind']!==$row['kind']||$row['amount_cents']!=='-'.$op['amount_cents']||(int)$row['credit_id']!==$op['snapshot']['credit']['id'])PaymentException::reject('history_corrupt');
            if($row['kind']==='refund'){
                if((int)$row['reservation_id']!==$op['snapshot']['reservation']['id'])PaymentException::reject('history_corrupt');
                $expected=['reservation_id'=>(int)$row['reservation_id'],'amount_cents'=>$op['amount_cents'],'source_receipt_hash'=>PropertyInput::hash($op['snapshot']['source']['receipt'])];
                if($op['status']==='posted')$paid[$row['reservation_id']]=bcsub($paid[$row['reservation_id']]??'0',$row['amount_cents'],0);
            }else{
                if($row['reservation_id']!==null)PaymentException::reject('history_corrupt');
                $expected=['credit_id'=>(int)$row['credit_id'],'amount_cents'=>$op['amount_cents'],'credit_snapshot_hash'=>$credit['snapshot_hash']];
            }
            if(PropertyInput::hash(self::decode($row['event_json']))!==PropertyInput::hash($expected))PaymentException::reject('history_corrupt');
            if($op['status']==='posted')$effects[$row['credit_id']]=bcadd($effects[$row['credit_id']]??'0',$row['amount_cents'],0);
        }
        $reserved=[];foreach($pdo->query('SELECT * FROM unit_credit_reservations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row){$data=self::decode($row['request_json']);if($row['amount_cents']!==$data['request']['amount_cents']||$row['paid_cents']!==($paid[$row['id']]??'0'))PaymentException::reject('history_corrupt');
            if($row['status']==='paid'&&$row['paid_cents']!==$row['amount_cents'])PaymentException::reject('history_corrupt');if(in_array($row['status'],['reserved','submitted'],true))$reserved[$row['credit_id']]=bcadd($reserved[$row['credit_id']]??'0',bcsub($row['amount_cents'],$row['paid_cents'],0),0);}
        foreach($credits as $supplier=>$lots)foreach($lots as $id=>$row){$active=($row['settlement_effect_id']??null)!==null?(new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($this->db))->credit((int)$supplier,$row,true)['active']:(($row['opening_item_id']??null)!==null?(new \MyInvoice\Service\Property\Opening\OpeningOriginProof($this->db))->credit((int)$supplier,$row,true)['active']:$operations[$supplier][$row['operation_id']]['status']!=='reversed');$balance=bcadd($active?$row['amount_cents']:'0',$effects[$id]??'0',0);if(bccomp($balance,$reserved[$id]??'0',0)<0)PaymentException::reject('history_corrupt');}
    }
    private function references(array $data):array
    {
        foreach($data as $key=>&$value){if(is_array($value))$value=$this->references($value);elseif(is_string($value)&&in_array((string)$key,['request_hash','snapshot_hash','receipt_hash','original_snapshot_hash','source_receipt_hash','credit_snapshot_hash','batch_snapshot_hash','item_snapshot_hash'],true))$value=$this->hashes[$value]??$value;}unset($value);return $data;
    }
    private function map(string $old,array $value):string{$new=PropertyInput::hash($value);if(isset($this->hashes[$old])&&$this->hashes[$old]!==$new)throw new \LogicException('Conflicting payment clone proof.');return $this->hashes[$old]=$new;}
    private function update(string $table,array $row,array $changes):void
    {
        $keys=$table==='unit_credit_parties'?['supplier_id','credit_id','client_id']:['supplier_id','id'];$sql='UPDATE '.$table.' SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($changes))).' WHERE '.implode(' AND ',array_map(fn($k)=>$k.'=?',$keys));$this->db->pdo()->prepare($sql)->execute([...array_values($changes),...array_map(fn($k)=>$row[$k],$keys)]);
    }
    private static function decode(string $v):array{return json_decode($v,true,flags:JSON_THROW_ON_ERROR);}
    private static function json(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
    private static function hash(string $hash,array $v):void{if(!hash_equals($hash,PropertyInput::hash($v)))PaymentException::reject('history_corrupt');}
}
