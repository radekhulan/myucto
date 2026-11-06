<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\{PaymentRepository,CreditSetoffRepository};
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Payments\Workflow\{PaymentSourcePreview,PaymentSourceProjection,PaymentWorkflowJournalPlan};

final class PaymentHistoricalCarrierProof
{
    private readonly string $savepoint;
    private readonly array $expectedJournal;
    private function __construct(private readonly Connection $db,private readonly PaymentRepository|CreditSetoffRepository $owner,
        private readonly int $supplier,private readonly array $request,private readonly PaymentSourcePreview|PaymentSourceProjection|null $prospective,
        private readonly ?int $operationId,private readonly array $snapshot)
    {
        $this->expectedJournal=$this->resolve($snapshot);
        $this->savepoint='payment_carrier_'.bin2hex(random_bytes(10));
        $db->pdo()->exec('SAVEPOINT '.$this->savepoint);
    }
    public static function fromCurrentPayment(Connection $db,PaymentRepository $owner,int $supplier,array $request,PaymentSourcePreview|PaymentSourceProjection|null $prospective=null):self
    {
        $snapshot=$owner->currentCarrierSnapshot($db,$supplier,$request,$prospective);
        return new self($db,$owner,$supplier,$request,$prospective,null,$snapshot);
    }
    public static function fromPendingOperation(Connection $db,PaymentRepository $owner,int $supplier,int $operationId):self
    {
        $snapshot=$owner->currentCarrierSnapshot($db,$supplier,[],null,$operationId);
        return new self($db,$owner,$supplier,$snapshot['request'],null,$operationId,$snapshot);
    }
    public static function fromCurrentSetoff(Connection $db,CreditSetoffRepository $owner,int $supplier,array $request):self
    {
        $snapshot=$owner->currentCarrierSnapshot($db,$supplier,$request);
        return new self($db,$owner,$supplier,$request,null,null,$snapshot);
    }
    public static function fromPendingSetoff(Connection $db,CreditSetoffRepository $owner,int $supplier,int $id):self
    {
        $snapshot=$owner->currentCarrierSnapshot($db,$supplier,[],$id);
        return new self($db,$owner,$supplier,$snapshot['request'],null,$id,$snapshot);
    }
    private function __clone() {}
    public function __serialize():array {throw new \LogicException('Payment carriers cannot be serialized.');}
    public function __unserialize(array $data):void {throw new \LogicException('Payment carriers cannot be restored.');}

    public function journal(Connection $db):array
    {
        return $this->resolve($this->current($db));
    }
    public function indices(Connection $db,array $header,array $lines):array
    {
        $snapshot=$this->current($db);$journal=$this->resolve($snapshot);
        if(PropertyInput::hash(self::header($header))!==PropertyInput::hash(self::header($journal['header']))
            ||PropertyInput::hash(self::lines($lines))!==PropertyInput::hash(self::lines($journal['lines'])))PaymentException::reject('carrier_target_mismatch');
        $sources=[];
        if(isset($snapshot['source']['receipt']['ledger'])) {
            $receipt=$snapshot['source']['receipt'];
            foreach($snapshot['source']['clearing_cells']??$receipt['clearing_cells']??[['line'=>$receipt['clearing_line']]] as $cell)$sources[]=$cell['line'];
        }
        $openingIds=[];foreach($snapshot['debt_receipts']??[] as $detail)if(isset($detail['debt']['snapshot']['opening_item_id']))$openingIds[]=$detail['debt']['snapshot']['opening_item_id'];
        $openingItems=$openingIds===[]?[]:(new \MyInvoice\Service\Property\Opening\OpeningReadProof($db))->items($this->supplier,$openingIds,true);
        foreach($snapshot['debt_receipts']??[] as $detail){
            $entry=$detail['debt']['snapshot']['journal_entry_id'];
            if(isset($detail['debt']['snapshot']['settlement_effect_id'])){
                foreach($detail['debt']['snapshot']['lines'] as $part)$sources[]=$part['ledger_line'];
            }elseif(isset($detail['debt']['snapshot']['opening_item_id'])){
                $opening=$openingItems[$detail['debt']['snapshot']['opening_item_id']];
                foreach($opening['components'] as $component)if($component['role']==='principal'){$line=$component['line'];$line['amount']=bcdiv($component['amount_cents'],'100',2);$sources[]=$line;}
            }else{$ledger=(new PaymentJournalProof($db))->current($this->supplier,$entry);foreach($ledger['lines'] as $line)if(substr($line['account_code'],0,3)==='315'&&$line['side']==='debit'&&!$line['is_red_storno'])$sources[]=$line;}
            foreach($detail['accessories']??[] as $accessory)if(substr($accessory['receipt']['line']['account_code'],0,3)==='315')$sources[]=$accessory['receipt']['line'];
        }
        if(in_array($snapshot['request']['kind'],['refund','setoff'],true))$sources[]=$snapshot['credit']['snapshot']['line'];
        $indices=[];
        foreach($lines as $index=>$line)foreach($sources as $source)if(self::reduction($line,$source)){$indices[$index]=true;break;}
        return $indices;
    }
    private function current(Connection $db):array
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Payment carrier requires its current owner transaction.');
        try{$db->pdo()->exec('RELEASE SAVEPOINT '.$this->savepoint);}catch(\PDOException $e){if((int)($e->errorInfo[1]??0)===1305)throw new \LogicException('Payment carrier belongs to a different or rolled back transaction.',0,$e);throw $e;}
        $db->pdo()->exec('SAVEPOINT '.$this->savepoint);
        $current=$this->owner instanceof CreditSetoffRepository
            ?$this->owner->currentCarrierSnapshot($db,$this->supplier,$this->request,$this->operationId)
            :$this->owner->currentCarrierSnapshot($db,$this->supplier,$this->request,$this->prospective,$this->operationId);
        if(PropertyInput::hash($current)!==PropertyInput::hash($this->snapshot))PaymentException::reject('carrier_source_changed');
        $journal=$this->resolve($current);
        if(PropertyInput::hash($journal)!==PropertyInput::hash($this->expectedJournal))PaymentException::reject('carrier_target_mismatch');
        return $current;
    }
    private function resolve(array $snapshot):array
    {
        $journal=(new PaymentWorkflowJournalPlan($this->db))->resolve($this->supplier,$snapshot['request']['accounting_date'],'unit_payment',$snapshot['lines']);
        if($this->operationId!==null)$journal['header']['source_id']=$this->operationId;
        return $journal;
    }
    private static function reduction(array $line,array $source):bool
    {
        if(!in_array(substr($source['account_code'],0,3),['315','379'],true)
            ||(substr($source['account_code'],0,3)==='379'&&!in_array($source['account_code'],['379.900','379.910'],true))
            ||$line['side']===$source['side']||bccomp($line['amount'],'0',2)<=0||bccomp($line['amount'],$source['amount'],2)>0)return false;
        $a=PaymentJournalProof::canonicalLines([$line])[0];$b=PaymentJournalProof::canonicalLines([$source])[0];unset($a['side'],$a['amount'],$b['side'],$b['amount']);
        if($b['currency_code']!==null){
            if($a['currency_code']!==$b['currency_code']||$a['fx_rate']!==$b['fx_rate']||$a['amount_foreign']===null||$b['amount_foreign']===null
                ||bccomp($a['amount_foreign'],'0',2)<=0||bccomp($a['amount_foreign'],$b['amount_foreign'],2)>0)return false;
            unset($a['amount_foreign'],$b['amount_foreign']);
        }
        return PropertyInput::hash($a)===PropertyInput::hash($b);
    }
    private static function header(array $header):array
    {
        $out=[];foreach(['supplier_id','period_id','entry_date','document_date','source_type','source_id'] as $key)$out[$key]=$header[$key]??null;return $out;
    }
    private static function lines(array $lines):array
    {
        $out=PaymentJournalProof::canonicalLines($lines);foreach($out as $index=>&$line){$line['account_id']=$lines[$index]['account_id']??null;$line['line_no']=$lines[$index]['line_no']??null;}unset($line);return $out;
    }
}
