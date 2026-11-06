<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Payments;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
use MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest;
use MyInvoice\Service\Property\Payments\{PaymentLocks,PaymentPostingDate,PaymentException,PaymentJournalProof,ClaimAllocationPlanner,StatutoryAllocationPolicy};
use PDO;
final class CreditSetoffRepository
{
    public function __construct(private readonly Connection $db,private readonly PostingService $posting,private readonly PaymentRepository $payments){}
    public function preview(int $supplier,array $request):array{return $this->transaction($supplier,function()use($supplier,$request){$snapshot=$this->plan($supplier,$request);(new \MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowJournalPlan($this->db))->currentPayment(\MyInvoice\Service\Property\Payments\PaymentHistoricalCarrierProof::fromCurrentSetoff($this->db,$this,$supplier,$request));return ['snapshot'=>$snapshot,'preview_hash'=>PropertyInput::hash($snapshot)];});}
    public function apply(int $supplier,array $request,string $previewHash,string $token,int $actor):array
    {
        PropertyInput::id($actor,'actor');PropertyInput::code($token,'idempotency_key');
        return $this->transaction($supplier,function()use($supplier,$request,$previewHash,$token,$actor){
            $hash=PropertyInput::hash(['request'=>$request,'token'=>$token,'actor'=>$actor]);$q=$this->execute('SELECT id,request_hash,snapshot_hash FROM unit_payment_operations WHERE supplier_id=? AND idempotency_key=? FOR UPDATE',[$supplier,$token]);$old=$q->fetch(PDO::FETCH_ASSOC);
            if($old){if(!hash_equals($hash,$old['request_hash'])||!hash_equals($previewHash,$old['snapshot_hash']))PaymentException::reject('idempotency_conflict');return $this->payments->operation($supplier,(int)$old['id']);}
            $snapshot=$this->plan($supplier,$request);if(!hash_equals($previewHash,PropertyInput::hash($snapshot)))PaymentException::reject('preview_stale');$credit=$snapshot['credit'];
            $this->execute("INSERT INTO unit_payment_operations (supplier_id,kind,amount_cents,accounting_date,request_json,snapshot_json,request_hash,snapshot_hash,idempotency_key,created_by,recorded_at) VALUES (?,'setoff',?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))",[$supplier,$request['amount_cents'],$request['accounting_date'],self::json($request),self::json($snapshot),$hash,$previewHash,$token,$actor]);$id=(int)$this->db->pdo()->lastInsertId();
            $intent=\MyInvoice\Service\Property\Payments\Workflow\PaymentSetoffIntent::fromPending($this->db,$this,$supplier,$id);
            (new \MyInvoice\Repository\JournalEntryRepository($this->db,new \MyInvoice\Service\ActivityLogger($this->db)))->insertPropertySetoff($intent);
            return $this->payments->operation($supplier,$id);
        });
    }
    public function currentCarrierSnapshot(Connection $connection,int $supplier,array $request,?int $id=null):array
    {
        if($connection->pdo()!==$this->db->pdo()||!$connection->pdo()->inTransaction())throw new \LogicException('Setoff proof requires the owner transaction.');
        (new PaymentLocks($this->db))->acquire($supplier);
        if($id===null)return $this->plan($supplier,$request);
        $q=$this->execute('SELECT * FROM unit_payment_operations WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['kind']!=='setoff'||$row['status']!=='pending'||$row['journal_entry_id']!==null||$row['source_id']!==null||(int)$row['row_version']!==1)PaymentException::reject('setoff_intent');
        $request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);$snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
        if(!hash_equals($row['request_hash'],PropertyInput::hash(['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']])))PaymentException::reject('setoff_intent');
        foreach(['kind','accounting_date','amount_cents'] as $field)if($row[$field]!==$request[$field])PaymentException::reject('setoff_intent');
        $current=$this->plan($supplier,$request);
        if(!hash_equals($row['snapshot_hash'],PropertyInput::hash($snapshot))||!hash_equals($row['snapshot_hash'],PropertyInput::hash($current)))PaymentException::reject('setoff_intent');
        return $current;
    }
    public function provePendingSetoff(Connection $connection,int $supplier,int $id):array
    {
        $proof=\MyInvoice\Service\Property\Payments\PaymentHistoricalCarrierProof::fromPendingSetoff($connection,$this,$supplier,$id);
        $journal=(new \MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowJournalPlan($this->db))->currentPayment($proof);
        $q=$this->execute('SELECT request_json,created_by FROM unit_payment_operations WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);
        $journal['header']+=['posted_at'=>gmdate('Y-m-d H:i:s'),'posted_by'=>(int)$row['created_by'],'description'=>$request['reason']];return $journal;
    }
    public function completePendingSetoff(Connection $connection,int $supplier,int $id,int $entry):void
    {
        $snapshot=$this->currentCarrierSnapshot($connection,$supplier,[],$id);$request=$snapshot['request'];$credit=$snapshot['credit'];$previewHash=PropertyInput::hash($snapshot);
        $q=$this->execute('SELECT created_by FROM unit_payment_operations WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);$actor=(int)$q->fetchColumn();
        $actual=(new PaymentJournalProof($this->db))->current($supplier,$entry);
        if($actual['entry']['source_type']!=='unit_payment'||$actual['entry']['source_id']!==$id||$actual['entry']['entry_date']!==$request['accounting_date']||$actual['entry']['reversed_by']!==null
            ||PropertyInput::hash(PaymentJournalProof::canonicalLines($actual['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($snapshot['lines'])))PaymentException::reject('ledger_mismatch');
            foreach($snapshot['allocations'] as $allocation)$this->execute('INSERT INTO unit_payment_allocations (supplier_id,operation_id,debt_id,charge_id,charge_line_id,opening_component_id,settlement_effect_id,accessory_id,debtor_id,liability_group,amount_cents,allocation_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$supplier,$id,$allocation['debt_id'],$allocation['charge_id'],$allocation['charge_line_id'],$allocation['opening_component_id']??null,$allocation['settlement_effect_id']??null,$allocation['accessory_id'],$allocation['debtor_id'],$allocation['liability_group'],$allocation['amount_cents'],self::json($allocation)]);
            $event=['credit_id'=>$credit['id'],'amount_cents'=>$request['amount_cents'],'credit_snapshot_hash'=>PropertyInput::hash($credit['snapshot'])];
            $this->execute("INSERT INTO unit_credit_events (supplier_id,credit_id,operation_id,amount_cents,kind,event_json) VALUES (?,?,?,?,'setoff',?)",[$supplier,$credit['id'],$id,'-'.$request['amount_cents'],self::json($event)]);
            $this->cas('UPDATE unit_credit_lots SET row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=?',[$supplier,$credit['id'],$credit['row_version']]);
            $this->cas("UPDATE unit_payment_operations SET status='posted',journal_entry_id=?,row_version=2 WHERE supplier_id=? AND id=? AND status='pending' AND row_version=1 AND journal_entry_id IS NULL",[$entry,$supplier,$id]);
            $audit=['snapshot_hash'=>$previewHash,'credit_id'=>$credit['id'],'journal_entry_id'=>$entry];$this->execute("INSERT INTO unit_payment_audit (supplier_id,object_kind,object_id,action,payload_json,payload_hash,created_by,recorded_at) VALUES (?,'operation',?,'setoff',?,?,?,UTC_TIMESTAMP(6))",[$supplier,$id,self::json($audit),PropertyInput::hash($audit),$actor]);return;
    }
    private function plan(int $supplier,array $request):array
    {
        PropertyInput::fields($request,['kind','credit_id','expected_credit_version','amount_cents','accounting_date','reason','debts','payment','legal']);if(($request['kind']??null)!=='setoff')PropertyInput::invalid('kind');
        $amount=StatutoryAllocationPolicy::cents($request['amount_cents']??null);if($amount==='0')PropertyInput::invalid('amount_cents');$date=IssuanceRequest::date($request['accounting_date']??null);PropertyInput::text($request['reason']??null,'reason',4000);
        (new PaymentPostingDate($this->db))->assertOpen($supplier,$date);$credit=$this->payments->credit($supplier,PropertyInput::id($request['credit_id']??null,'credit_id'));
        if($credit['row_version']!==($request['expected_credit_version']??null))PaymentException::reject('version_conflict');if(bccomp($amount,$credit['available_cents'],0)>0)PaymentException::reject('credit_capacity');
        $legal=$request['legal']??null;if(!is_array($legal))PropertyInput::invalid('legal');PropertyInput::fields($legal,['mutuality_evidence','eligibility_evidence','declaration_evidence','delivery_evidence','delivered_at','effect_evidence','scope_cents','scope_1984_evidence','early_performance_evidence','certain','unconditional']);
        foreach(['mutuality_evidence','eligibility_evidence','declaration_evidence','delivery_evidence','effect_evidence'] as $field)PropertyInput::text($legal[$field]??null,$field,4000);
        PropertyInstant::normalize($legal['delivered_at']??null);if(($legal['certain']??null)!==true||($legal['unconditional']??null)!==true)PaymentException::reject('setoff_eligibility');
        if(bccomp($amount,StatutoryAllocationPolicy::cents($legal['scope_cents']??null),0)>0)PaymentException::reject('setoff_scope');
        if($credit['snapshot']['due_date']>$date)PropertyInput::text($legal['early_performance_evidence']??null,'early_performance_evidence',4000);
        $q=$this->execute('SELECT COALESCE(o.accounting_date,b.accounting_date) FROM unit_credit_lots c LEFT JOIN unit_payment_operations o ON o.supplier_id=c.supplier_id AND o.id=c.operation_id LEFT JOIN property_opening_items i ON i.supplier_id=c.supplier_id AND i.id=c.opening_item_id LEFT JOIN property_opening_batches b ON b.supplier_id=i.supplier_id AND b.id=i.batch_id WHERE c.supplier_id=? AND c.id=? FOR UPDATE',[$supplier,$credit['id']]);if($date<$q->fetchColumn())PaymentException::reject('backdated_payment');
        $parties=array_column($credit['snapshot']['parties'],'id');sort($parties,SORT_NUMERIC);$payment=$request['payment']??null;if(!is_array($payment)||!in_array($payment['debtor_id']??null,$parties,true)||($payment['payer_id']??null)!==($payment['debtor_id']??null))PaymentException::reject('setoff_mutuality');
        \MyInvoice\Service\Property\Opening\OpeningPaymentAvailability::assertAvailable($credit,$date,PropertyInstant::normalize($payment['received_at']??null));
        $plan=(new ClaimAllocationPlanner($this->db))->current($supplier,$request,['receipt'=>['date'=>$date]]);if($plan['resolution']['credit_cents']!=='0'||$plan['resolution']['allocated_cents']!==$amount)PaymentException::reject('setoff_unallocated');
        foreach($plan['debt_receipts'] as $debt){$members=$debt['group']['members'];sort($members,SORT_NUMERIC);if($members!==$parties)PaymentException::reject('setoff_mutuality');if($debt['debt']['snapshot']['due_date']>$date)PaymentException::reject('setoff_claim_not_due');}
        if(count($parties)>1)PropertyInput::text($legal['scope_1984_evidence']??null,'scope_1984_evidence',4000);
        $line=$credit['snapshot']['line'];$line['side']='debit';$line['amount']=bcdiv($amount,'100',2);
        return ['request'=>$request,'credit'=>$credit,'lines'=>[$line,...$plan['lines']],'allocations'=>$plan['allocations'],'resolution'=>$plan['resolution'],'debt_receipts'=>$plan['debt_receipts'],'payment_parties'=>$plan['payment_parties']];
    }
    private function transaction(int $supplier,callable $work):mixed{return (new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$work){(new PaymentLocks($this->db))->acquire($supplier);return $work();});}
    private function execute(string $sql,array $params):\PDOStatement{$q=$this->db->pdo()->prepare($sql);$q->execute($params);return $q;}
    private function cas(string $sql,array $params):void{if($this->execute($sql,$params)->rowCount()!==1)PaymentException::reject('version_conflict');}
    private static function json(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
}
