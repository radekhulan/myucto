<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyInput;
use PDO;
final readonly class PaymentReversalIntent
{
    private function __construct(private Connection $db,private \MyInvoice\Repository\Property\Payments\PaymentRepository $payments,private array $completion,public int $supplierId,public int $intentId,public int $sourceEntryId,public int $expectedVersion,public string $accountingDate,public string $description,public int $actor) {}
    public function __serialize(): array { throw new \LogicException('Payment reversal intents cannot be exported.'); }
    private function __clone() {}
    public function current(Connection $db): self
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Payment reversal requires its owning transaction.');
        return self::fromCurrentRequest($db,$this->supplierId,$this->intentId,$this->payments);
    }
    public function completion(Connection $db): array
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Payment reversal requires its owning transaction.');
        return $this->completion;
    }
    public function complete(Connection $db,int $entry): void
    {
        $this->completion($db);
        $this->payments->completePendingReversal($this,$entry);
    }
    public static function fromCurrentRequest(Connection $db,int $supplier,int $id,\MyInvoice\Repository\Property\Payments\PaymentRepository $payments):self
    {
        (new PaymentLocks($db))->acquire($supplier);
        $q=$db->pdo()->prepare('SELECT * FROM unit_payment_operations WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['kind']!=='reversal'||$row['status']!=='pending'||$row['journal_entry_id']!==null)PaymentException::reject('reversal_intent');
        $request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);$snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
        if(!hash_equals($row['request_hash'],PropertyInput::hash(['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]))||!hash_equals($row['snapshot_hash'],PropertyInput::hash($snapshot))||PropertyInput::hash($request)!==PropertyInput::hash($snapshot['request']))PaymentException::reject('reversal_intent');
        $q->execute([$supplier,$row['original_operation_id']]);$original=$q->fetch(PDO::FETCH_ASSOC);
        if(!$original||$original['status']!=='posted'||$original['kind']==='reversal'||(int)$original['row_version']!==$request['expected_version']||$original['snapshot_hash']!==$snapshot['original_snapshot_hash']||$row['accounting_date']<$original['accounting_date'])PaymentException::reject('reversal_intent');
        $before=json_decode($original['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);if(!hash_equals($original['snapshot_hash'],PropertyInput::hash($before)))PaymentException::reject('reversal_intent');
        $current=(new PaymentJournalProof($db))->current($supplier,(int)$original['journal_entry_id']);
        if($current['entry']['reversed_by']!==null||PropertyInput::hash(PaymentJournalProof::canonicalLines($current['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($before['lines'])))PaymentException::reject('reversal_intent');
        $expected=$before['lines'];foreach($expected as &$line)$line['side']=$line['side']==='debit'?'credit':'debit';unset($line);
        if(PropertyInput::hash(PaymentJournalProof::canonicalLines($expected))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($snapshot['lines'])))PaymentException::reject('reversal_intent');
        if($current['entry']['source_type']!=='unit_payment'||$current['entry']['source_id']!==(int)$original['id']||$current['entry']['entry_date']!==$original['accounting_date'])PaymentException::reject('reversal_intent');
        if($original['kind']==='correction'){
            \MyInvoice\Service\Property\Payments\Workflow\PaymentReliefReversalProof::assertPending($db,$supplier,$row,$original,$request);
        }elseif($original['kind']==='setoff'){$credit=$payments->credit($supplier,$before['credit']['id']);if(PropertyInput::hash($credit['snapshot'])!==PropertyInput::hash($before['credit']['snapshot']))PaymentException::reject('reversal_intent');}else{$source=$before['source']['receipt'];(new PaymentSourceProof($db))->current($supplier,$source['kind'],$source['id']);}
        if(in_array($original['kind'],['allocation','correction'],true)){
            $q=$db->pdo()->prepare('SELECT id,amount_cents FROM unit_credit_lots WHERE supplier_id=? AND operation_id=? FOR UPDATE');$q->execute([$supplier,$original['id']]);$credit=$q->fetch(PDO::FETCH_ASSOC);
            if($credit){
                $q=$db->pdo()->prepare("SELECT e.amount_cents FROM unit_credit_events e JOIN unit_payment_operations o ON o.supplier_id=e.supplier_id AND o.id=e.operation_id WHERE e.supplier_id=? AND e.credit_id=? AND o.status='posted' ORDER BY e.id FOR UPDATE");$q->execute([$supplier,$credit['id']]);$net='0';foreach($q->fetchAll(PDO::FETCH_COLUMN) as $value)$net=bcadd($net,$value,0);if($net!=='0')PaymentException::reject('credit_in_use');
                $q=$db->pdo()->prepare("SELECT id FROM unit_credit_reservations WHERE supplier_id=? AND credit_id=? AND status IN ('reserved','submitted') AND amount_cents>paid_cents ORDER BY id FOR UPDATE");$q->execute([$supplier,$credit['id']]);if($q->fetchColumn()!==false)PaymentException::reject('credit_in_use');
            }
        }
        (new PaymentUseGuard($db))->assertOperationReversible($supplier,(int)$original['id']);
        (new PaymentPostingDate($db))->assertOpen($supplier,$row['accounting_date']);
        $completion=$payments->reversalCompletionContext($supplier,$id);
        return new self($db,$payments,$completion,$supplier,$id,(int)$original['journal_entry_id'],(int)$original['row_version'],$row['accounting_date'],PropertyInput::text($request['reason'],'reason',4000),(int)$row['created_by']);
    }
}
