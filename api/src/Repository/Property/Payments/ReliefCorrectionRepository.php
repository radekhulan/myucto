<?php

declare(strict_types=1);
namespace MyInvoice\Repository\Property\Payments;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\{IssuanceReadRepository,IssuanceRepository};
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\Payments\{PaymentLocks,PaymentException,PaymentJournalProof,PaymentPostingDate,ReliefCorrectionPlanner,CorrectionHistory};
use PDO;
final class ReliefCorrectionRepository
{
    public function __construct(private readonly Connection $db,private readonly PostingService $posting,private readonly IssuanceRepository $issuance,private readonly PaymentRepository $payments){}
    public function preview(int $supplier,array $request):array
    {
        return $this->transaction($supplier,function()use($supplier,$request){$snapshot=(new ReliefCorrectionPlanner($this->db))->current($supplier,$request);return ['snapshot'=>$snapshot,'preview_hash'=>PropertyInput::hash($snapshot)];});
    }
    public function apply(int $supplier,array $request,string $previewHash,string $token,int $actor):array
    {
        PropertyInput::id($actor,'actor');PropertyInput::code($token,'idempotency_key');
        return $this->transaction($supplier,function()use($supplier,$request,$previewHash,$token,$actor){
            $hash=PropertyInput::hash(['request'=>$request,'token'=>$token,'actor'=>$actor]);
            $old=$this->one('SELECT id,request_hash,snapshot_hash FROM unit_payment_corrections WHERE supplier_id=? AND idempotency_key=? FOR UPDATE',[$supplier,$token]);
            if($old){
                if(!hash_equals($old['request_hash'],$hash)||!hash_equals($old['snapshot_hash'],$previewHash))PaymentException::reject('idempotency_conflict');
                $existing=$this->findLocked($supplier,(int)$old['id']);if($existing['status']!=='pending')return $existing;
                return $this->issuance->postPaymentCorrection(\MyInvoice\Service\Property\Payments\PaymentCorrectionIntent::fromCurrentRequest($this->db,$this,$supplier,(int)$old['id']));
            }
            $snapshot=(new ReliefCorrectionPlanner($this->db))->current($supplier,$request);
            if(!hash_equals($previewHash,PropertyInput::hash($snapshot)))PaymentException::reject('preview_stale');
            if($this->one('SELECT id FROM unit_payment_corrections WHERE supplier_id=? AND adjustment_charge_id=? FOR UPDATE',[$supplier,$request['adjustment_charge_id']]))PaymentException::reject('correction_already_owned');
            foreach($snapshot['allocation_receipts'] as $receipt){
                $operation=$this->payments->operation($supplier,(int)$receipt['operation_id']);if($operation['status']!=='posted'||!in_array($operation['kind'],['allocation','setoff'],true))PaymentException::reject('relief_allocation');
                if($operation['kind']==='setoff'){$this->payments->credit($supplier,$operation['snapshot']['credit']['id']);}else{$source=$operation['snapshot']['source']['receipt'];(new \MyInvoice\Service\Property\Payments\PaymentSourceProof($this->db))->current($supplier,$source['kind'],$source['id']);}
            }
            (new \MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard($this->db))->assertChargeLinesMutable($supplier,array_column($snapshot['anchors'],'original_line_id'));
            (new \MyInvoice\Service\Property\Receivables\PartyHistoryUseGuard($this->db))->assertChargeLinesMutable($supplier,array_column($snapshot['anchors'],'original_line_id'));
            $this->execute('INSERT INTO unit_payment_corrections (supplier_id,adjustment_charge_id,request_json,snapshot_json,request_hash,snapshot_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$supplier,$request['adjustment_charge_id'],self::json($request),self::json($snapshot),$hash,$previewHash,$token,$actor]);$id=(int)$this->db->pdo()->lastInsertId();
            foreach($snapshot['anchors'] as $part)$this->execute('INSERT INTO unit_payment_correction_anchors (supplier_id,correction_id,adjustment_line_id,original_line_id,amount_cents) VALUES (?,?,?,?,?)',[$supplier,$id,$part['adjustment_line_id'],$part['original_line_id'],$part['amount_cents']]);
            foreach($snapshot['reliefs'] as $part)$this->execute('INSERT INTO unit_payment_reliefs (supplier_id,correction_id,allocation_id,amount_cents) VALUES (?,?,?,?)',[$supplier,$id,$part['allocation_id'],$part['amount_cents']]);
            $intent=\MyInvoice\Service\Property\Payments\PaymentCorrectionIntent::fromCurrentRequest($this->db,$this,$supplier,$id);
            return $this->issuance->postPaymentCorrection($intent);
        });
    }
    public function reverse(int $supplier,int $id,array $request,string $token,int $actor):array
    {
        PropertyInput::fields($request,['expected_version','accounting_date','effective_at','reason','evidence']);PropertyInput::code($token,'idempotency_key');PropertyInput::id($actor,'actor');
        PropertyInput::text($request['reason']??null,'reason',2000);PropertyInput::text($request['evidence']??null,'evidence',2000);
        $date=\MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest::date($request['accounting_date']??null);$at=PropertyInstant::normalize($request['effective_at']??null);
        return $this->transaction($supplier,function()use($supplier,$id,$request,$token,$actor,$date,$at){
            $receipt=['request'=>$request,'token'=>$token,'actor'=>$actor];$original=$this->findLocked($supplier,$id);
            foreach($this->all("SELECT payload_json,payload_hash FROM unit_payment_audit WHERE supplier_id=? AND object_kind='correction' AND object_id=? AND action='reversed' ORDER BY id FOR UPDATE",[$supplier,$id]) as $event){$payload=json_decode($event['payload_json'],true,flags:JSON_THROW_ON_ERROR);if(PropertyInput::hash($payload)!==$event['payload_hash'])PaymentException::reject('history_corrupt');if($payload['token']===$token){if(PropertyInput::hash($payload)!==PropertyInput::hash($receipt))PaymentException::reject('idempotency_conflict');return $original;}}
            if($original['status']!=='posted'||$original['row_version']!==($request['expected_version']??null))PaymentException::reject('version_conflict');
            if($date<$original['request']['accounting_date'])PaymentException::reject('backdated_payment');(new PaymentPostingDate($this->db))->assertOpen($supplier,$date);
            $charge=(new IssuanceReadRepository($this->db))->find($supplier,$original['adjustment_charge_id'],true);$operation=null;
            if($original['operation_id']!==null){
                $operation=$this->payments->operation($supplier,$original['operation_id']);$credit=$this->payments->credit($supplier,$operation['credit_id']);
                if($credit['balance_cents']!==$credit['snapshot']['amount_cents']||$credit['reserved_cents']!=='0')PaymentException::reject('credit_in_use');
            }
            (new \MyInvoice\Service\Property\Receivables\PartyHistoryUseGuard($this->db))->assertCorrectionMutable($supplier,$id);
            $this->audit($supplier,$id,'reversal_requested',$receipt+['snapshot_hash'=>$original['snapshot_hash'],'charge'=>['id'=>$charge['id'],'row_version'=>$charge['row_version'],'snapshot_hash'=>$charge['snapshot_hash']]],$actor);
            $intent=\MyInvoice\Service\Property\Payments\PaymentCorrectionReversalIntent::fromCurrentRequest($this->db,$this,$supplier,(int)$this->db->pdo()->lastInsertId());
            return $this->issuance->reversePaymentCorrection($intent);
        });
    }

    public function provePendingCorrection(Connection $db,int $supplier,int $id):array
    {
        $this->assertOwnerConnection($db);(new PaymentLocks($db))->acquire($supplier);
        $row=$this->one('SELECT * FROM unit_payment_corrections WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);
        if(!$row||$row['status']!=='pending'||$row['operation_id']!==null||(int)$row['row_version']!==1)PaymentException::reject('correction_intent');
        $request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);$snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
        if(!hash_equals($row['request_hash'],PropertyInput::hash(['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]))||!hash_equals($row['snapshot_hash'],PropertyInput::hash($snapshot)))PaymentException::reject('history_corrupt');
        $current=(new ReliefCorrectionPlanner($db))->current($supplier,$request);
        if(!hash_equals($row['snapshot_hash'],PropertyInput::hash($current))||(int)$row['adjustment_charge_id']!==$current['adjustment']['id'])PaymentException::reject('correction_intent');
        foreach($snapshot['allocation_receipts'] as $receipt){
            $operation=$this->payments->operation($supplier,(int)$receipt['operation_id']);
            if($operation['status']!=='posted'||!in_array($operation['kind'],['allocation','setoff'],true))PaymentException::reject('relief_allocation');
            if($operation['kind']==='setoff')$this->payments->credit($supplier,$operation['snapshot']['credit']['id']);
            else{$source=$operation['snapshot']['source']['receipt'];(new \MyInvoice\Service\Property\Payments\PaymentSourceProof($db))->current($supplier,$source['kind'],$source['id']);}
        }
        (new \MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard($db))->assertChargeLinesMutable($supplier,array_column($snapshot['anchors'],'original_line_id'));
        (new \MyInvoice\Service\Property\Receivables\PartyHistoryUseGuard($db))->assertChargeLinesMutable($supplier,array_column($snapshot['anchors'],'original_line_id'));
        return ['id'=>$id,'supplier'=>$supplier,'snapshot'=>$snapshot,'snapshot_hash'=>$row['snapshot_hash'],'request_hash'=>$row['request_hash'],'actor'=>(int)$row['created_by']];
    }
    public function completePendingCorrection(\MyInvoice\Service\Property\Payments\PaymentCorrectionIntent $intent):array
    {
        $context=$intent->completion($this->db);$supplier=$context['supplier'];$id=$context['id'];$snapshot=$context['snapshot'];
        $snapshotHash=$context['snapshot_hash'];$actor=$context['actor'];$request=$snapshot['request'];$charge=$snapshot['adjustment'];
        (new CorrectionHistory($this->db))->current($supplier);
        $row=$this->one('SELECT * FROM unit_payment_corrections WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);
        if(!$row||$row['status']!=='pending'||(int)$row['row_version']!==1||$row['operation_id']!==null||$row['snapshot_hash']!==$snapshotHash||$row['request_hash']!==$context['request_hash']||(int)$row['created_by']!==$actor)PaymentException::reject('correction_intent');
        $this->assertCorrectionSnapshot($row,$snapshot);
        $posted=(new IssuanceReadRepository($this->db))->find($supplier,$charge['id'],true);
        if($posted['snapshot_hash']!==$charge['snapshot_hash']||$posted['status']!=='posted'||$posted['row_version']!==$charge['row_version']+1)PaymentException::reject('correction_source_changed');
        (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceJournalProof($this->db))->assertEntry($supplier,$posted['journal_entry_id'],$posted);
        $operationId=null;
        if($snapshot['relief_cents']!=='0'){
            $operationRequest=['kind'=>'correction','amount_cents'=>$snapshot['relief_cents'],'accounting_date'=>$request['accounting_date'],'correction_id'=>$id,'reason'=>$request['reason']];
            $operationSnapshot=['request'=>$operationRequest,'correction_id'=>$id,'lines'=>$snapshot['lines'],'credit'=>$snapshot['credit'],'allocations'=>[]];$operationToken='correction-'.$id;
            $this->execute("INSERT INTO unit_payment_operations (supplier_id,kind,amount_cents,accounting_date,request_json,snapshot_json,request_hash,snapshot_hash,idempotency_key,created_by,recorded_at) VALUES (?,'correction',?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))",[$supplier,$snapshot['relief_cents'],$request['accounting_date'],self::json($operationRequest),self::json($operationSnapshot),PropertyInput::hash(['request'=>$operationRequest,'token'=>$operationToken,'actor'=>$actor]),PropertyInput::hash($operationSnapshot),$operationToken,$actor]);$operationId=(int)$this->db->pdo()->lastInsertId();
            $entry=$this->posting->postDocument($supplier,'unit_payment',$operationId,$snapshot['lines'],['entry_date'=>$request['accounting_date'],'description'=>$request['reason'],'posted_by'=>$actor]);
            $this->assertLedger($supplier,$entry,$snapshot['lines']);
            $credit=$snapshot['credit'];$this->execute('INSERT INTO unit_credit_lots (supplier_id,operation_id,unit_id,amount_cents,due_date,snapshot_json,snapshot_hash) VALUES (?,?,?,?,?,?,?)',[$supplier,$operationId,$credit['unit_id'],$credit['amount_cents'],$credit['due_date'],self::json($credit),PropertyInput::hash($credit)]);$creditId=(int)$this->db->pdo()->lastInsertId();
            foreach($credit['parties'] as $party)$this->execute('INSERT INTO unit_credit_parties (supplier_id,credit_id,client_id,party_json) VALUES (?,?,?,?)',[$supplier,$creditId,$party['id'],self::json($party)]);
            $this->cas("UPDATE unit_payment_operations SET status='posted',journal_entry_id=?,row_version=2 WHERE supplier_id=? AND id=? AND status='pending'",[$entry,$supplier,$operationId]);
        }
        $this->cas("UPDATE unit_payment_corrections SET status='posted',operation_id=?,row_version=2 WHERE supplier_id=? AND id=? AND status='pending'",[$operationId,$supplier,$id]);
        $this->audit($supplier,$id,'posted',['snapshot_hash'=>$snapshotHash,'adjustment_charge_id'=>$charge['id'],'operation_id'=>$operationId],$actor);
        return $this->findLocked($supplier,$id);
    }
    public function proveCorrectionReversal(Connection $db,int $supplier,int $auditId):array
    {
        $this->assertOwnerConnection($db);(new PaymentLocks($db))->acquire($supplier);
        $audit=$this->one("SELECT * FROM unit_payment_audit WHERE supplier_id=? AND id=? AND object_kind='correction' AND action='reversal_requested' FOR UPDATE",[$supplier,$auditId]);
        if(!$audit)PaymentException::reject('reversal_intent');
        $payload=json_decode($audit['payload_json'],true,flags:JSON_THROW_ON_ERROR);
        if(PropertyInput::hash($payload)!==$audit['payload_hash']||$payload['actor']!==(int)$audit['created_by'])PaymentException::reject('reversal_intent');
        $id=(int)$audit['object_id'];$original=$this->findLocked($supplier,$id);
        if($original['status']!=='posted'||$original['row_version']!==$payload['request']['expected_version']||$original['snapshot_hash']!==$payload['snapshot_hash'])PaymentException::reject('reversal_intent');
        $charge=(new IssuanceReadRepository($db))->find($supplier,$original['adjustment_charge_id'],true);
        if($charge['status']!=='posted'||$charge['snapshot_hash']!==$payload['charge']['snapshot_hash']||$charge['id']!==$payload['charge']['id']||$charge['row_version']!==$payload['charge']['row_version'])PaymentException::reject('reversal_intent');
        $request=$payload['request'];PropertyInput::fields($request,['expected_version','accounting_date','effective_at','reason','evidence']);
        $date=\MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest::date($request['accounting_date']??null);PropertyInstant::normalize($request['effective_at']??null);
        PropertyInput::text($request['reason']??null,'reason',2000);PropertyInput::text($request['evidence']??null,'evidence',2000);PropertyInput::code($payload['token'],'idempotency_key');
        if($date<$original['request']['accounting_date'])PaymentException::reject('backdated_payment');(new PaymentPostingDate($db))->assertOpen($supplier,$date);
        $operation=null;
        if($original['operation_id']!==null){
            $operation=$this->payments->operation($supplier,$original['operation_id']);$credit=$this->payments->credit($supplier,$operation['credit_id']);
            if($operation['status']!=='posted'||$credit['balance_cents']!==$credit['snapshot']['amount_cents']||$credit['reserved_cents']!=='0')PaymentException::reject('credit_in_use');
        }
        (new \MyInvoice\Service\Property\Receivables\PartyHistoryUseGuard($db))->assertCorrectionMutable($supplier,$id);
        (new \MyInvoice\Service\Property\Receivables\PartyHistoryUseGuard($db))->assertChargeMutable($supplier,$charge['id']);
        return ['supplier'=>$supplier,'id'=>$id,'audit_id'=>$auditId,'audit_hash'=>$audit['payload_hash'],'audit'=>$payload,'original'=>$original,'charge'=>$charge,'operation'=>$operation];
    }
    public function completeCorrectionReversal(\MyInvoice\Service\Property\Payments\PaymentCorrectionReversalIntent $intent):array
    {
        $context=$intent->completion($this->db);$supplier=$context['supplier'];$id=$context['id'];$original=$context['original'];$operation=$context['operation'];
        $request=$context['audit']['request'];$actor=$context['audit']['actor'];$token=$context['audit']['token'];$date=$request['accounting_date'];$receipt=['request'=>$request,'token'=>$token,'actor'=>$actor];
        $audit=$this->one("SELECT * FROM unit_payment_audit WHERE supplier_id=? AND id=? AND object_kind='correction' AND action='reversal_requested' FOR UPDATE",[$supplier,$context['audit_id']]);
        if(!$audit||(int)$audit['object_id']!==$id||(int)$audit['created_by']!==$actor||$audit['payload_hash']!==$context['audit_hash']||PropertyInput::hash(json_decode($audit['payload_json'],true,flags:JSON_THROW_ON_ERROR))!==$context['audit_hash'])PaymentException::reject('reversal_intent');
        $current=$this->one('SELECT * FROM unit_payment_corrections WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);
        if(!$current||$current['status']!=='posted'||(int)$current['row_version']!==$original['row_version']||$current['snapshot_hash']!==$original['snapshot_hash']||$current['request_hash']!==$original['request_hash'])PaymentException::reject('reversal_intent');
        $this->assertCorrectionSnapshot($current,$original['snapshot']);
        $charge=(new IssuanceReadRepository($this->db))->find($supplier,$context['charge']['id'],true);
        if($charge['status']!=='reversed'||$charge['row_version']!==$context['charge']['row_version']+1||$charge['snapshot_hash']!==$context['charge']['snapshot_hash'])PaymentException::reject('reversal_intent');
        (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceJournalProof($this->db))->assertEntry($supplier,$charge['reversal_entry_id'],$charge,true);
        if($operation!==null){
            $lines=$operation['snapshot']['lines'];foreach($lines as &$line)$line['side']=$line['side']==='debit'?'credit':'debit';unset($line);
            $opRequest=['kind'=>'reversal','amount_cents'=>$operation['amount_cents'],'accounting_date'=>$date,'expected_version'=>$operation['row_version'],'reason'=>$request['reason'],'correction_id'=>$id,'correction_audit_id'=>$intent->auditId];
            $snapshot=['request'=>$opRequest,'original_operation_id'=>$operation['id'],'original_snapshot_hash'=>$operation['snapshot_hash'],'lines'=>$lines];$opToken='correction-reversal-'.$id;
            $this->execute("INSERT INTO unit_payment_operations (supplier_id,original_operation_id,kind,amount_cents,accounting_date,request_json,snapshot_json,request_hash,snapshot_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,'reversal',?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))",[$supplier,$operation['id'],$operation['amount_cents'],$date,self::json($opRequest),self::json($snapshot),PropertyInput::hash(['request'=>$opRequest,'token'=>$opToken,'actor'=>$actor]),PropertyInput::hash($snapshot),$opToken,$actor]);$reversalId=(int)$this->db->pdo()->lastInsertId();
            $mirror=\MyInvoice\Service\Property\Payments\PaymentReversalIntent::fromCurrentRequest($this->db,$supplier,$reversalId,$this->payments);
            $entry=(new \MyInvoice\Repository\JournalEntryRepository($this->db,new \MyInvoice\Service\ActivityLogger($this->db)))->insertPropertyPaymentReversal($mirror);$this->assertLedger($supplier,$entry,$lines);
            return $this->findLocked($supplier,$id);
        }
        $this->cas("UPDATE unit_payment_corrections SET status='reversed',row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=?",[$supplier,$id,$original['row_version']]);
        $this->audit($supplier,$id,'reversed',$receipt,$actor);return $this->findLocked($supplier,$id);
    }
    private function assertCorrectionSnapshot(array $row,array $snapshot):void
    {
        $current=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);$request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);
        if(PropertyInput::hash($current)!==$row['snapshot_hash']||PropertyInput::hash($current)!==PropertyInput::hash($snapshot)
            ||PropertyInput::hash($request)!==PropertyInput::hash($snapshot['request'])
            ||PropertyInput::hash(['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']])!==$row['request_hash'])PaymentException::reject('history_corrupt');
        $anchors=[];foreach($this->all('SELECT adjustment_line_id,original_line_id,amount_cents FROM unit_payment_correction_anchors WHERE supplier_id=? AND correction_id=? ORDER BY adjustment_line_id,original_line_id FOR UPDATE',[$row['supplier_id'],$row['id']]) as $part)
            $anchors[]=['adjustment_line_id'=>(int)$part['adjustment_line_id'],'original_line_id'=>(int)$part['original_line_id'],'amount_cents'=>$part['amount_cents']];
        $reliefs=[];foreach($this->all('SELECT allocation_id,amount_cents FROM unit_payment_reliefs WHERE supplier_id=? AND correction_id=? ORDER BY allocation_id FOR UPDATE',[$row['supplier_id'],$row['id']]) as $part)
            $reliefs[]=['allocation_id'=>(int)$part['allocation_id'],'amount_cents'=>$part['amount_cents']];
        if(PropertyInput::hash($anchors)!==PropertyInput::hash($snapshot['anchors'])||PropertyInput::hash($reliefs)!==PropertyInput::hash($snapshot['reliefs']))PaymentException::reject('history_corrupt');
    }
    private function assertOwnerConnection(Connection $db):void
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Correction requires its current owner transaction.');
    }
    public function find(int $supplier,int $id):array{return $this->transaction($supplier,fn()=> $this->findLocked($supplier,$id));}
    private function findLocked(int $supplier,int $id):array
    {
        (new CorrectionHistory($this->db))->current($supplier);$row=$this->one('SELECT * FROM unit_payment_corrections WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);if(!$row)PaymentException::reject('correction_not_found');
        foreach(['id','adjustment_charge_id','operation_id','row_version','created_by'] as $key)if($row[$key]!==null)$row[$key]=(int)$row[$key];
        $row['request']=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);$row['snapshot']=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);unset($row['request_json'],$row['snapshot_json']);
        $row['credit_id']=$row['operation_id']===null?null:$this->payments->operation($supplier,$row['operation_id'])['credit_id'];return $row;
    }
    private function assertLedger(int $supplier,int $entry,array $lines):void{$actual=(new PaymentJournalProof($this->db))->current($supplier,$entry);if(PropertyInput::hash(PaymentJournalProof::canonicalLines($actual['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($lines)))PaymentException::reject('ledger_mismatch');}
    private function transaction(int $supplier,callable $work):mixed{return (new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$work){(new PaymentLocks($this->db))->acquire($supplier);return $work();});}
    private function execute(string $sql,array $params):\PDOStatement{$q=$this->db->pdo()->prepare($sql);$q->execute($params);return $q;}
    private function one(string $sql,array $params):?array{return $this->execute($sql,$params)->fetch(PDO::FETCH_ASSOC)?:null;}
    private function all(string $sql,array $params):array{return $this->execute($sql,$params)->fetchAll(PDO::FETCH_ASSOC);}
    private function cas(string $sql,array $params):void{if($this->execute($sql,$params)->rowCount()!==1)PaymentException::reject('version_conflict');}
    private function audit(int $supplier,int $id,string $action,array $payload,int $actor):void{$this->execute("INSERT INTO unit_payment_audit (supplier_id,object_kind,object_id,action,payload_json,payload_hash,created_by,recorded_at) VALUES (?,'correction',?,?,?,?,?,UTC_TIMESTAMP(6))",[$supplier,$id,$action,self::json($payload),PropertyInput::hash($payload),$actor]);}
    private static function json(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
}
