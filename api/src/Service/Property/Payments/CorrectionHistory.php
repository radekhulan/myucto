<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceJournalProof;
use MyInvoice\Service\Property\PropertyInput;
use PDO;
final class CorrectionHistory
{
    public function __construct(private readonly Connection $db){}
    public function current(int $supplier):array
    {
        return $this->read($supplier,null);
    }
    public function currentForDebts(int $supplier,array $debtIds):array
    {
        foreach($debtIds as $id)PropertyInput::id($id,'debt_id');
        return $this->read($supplier,array_values(array_unique($debtIds)));
    }
    private function read(int $supplier,?array $debtIds):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Current correction proof requires owner transaction.');
        $lines=[];$allocationIds=[];
        if($debtIds!==null)foreach(array_chunk($debtIds,500) as $chunk){
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            foreach($this->rows('SELECT charge_line_id FROM unit_payment_debt_lines WHERE supplier_id=? AND debt_id IN ('.$marks.') ORDER BY charge_line_id FOR UPDATE',[$supplier,...$chunk]) as $row)$lines[(int)$row['charge_line_id']]=true;
            foreach($this->rows('SELECT id FROM unit_payment_allocations WHERE supplier_id=? AND debt_id IN ('.$marks.') ORDER BY id FOR UPDATE',[$supplier,...$chunk]) as $row)$allocationIds[(int)$row['id']]=true;
        }
        $anchorsByCorrection=[];$partsByCorrection=[];
        foreach($this->rows('SELECT * FROM unit_payment_correction_anchors WHERE supplier_id=? ORDER BY correction_id,adjustment_line_id,original_line_id FOR UPDATE',[$supplier]) as $part)$anchorsByCorrection[(int)$part['correction_id']][]=['adjustment_line_id'=>(int)$part['adjustment_line_id'],'original_line_id'=>(int)$part['original_line_id'],'amount_cents'=>$part['amount_cents']];
        foreach($this->rows('SELECT * FROM unit_payment_reliefs WHERE supplier_id=? ORDER BY correction_id,allocation_id FOR UPDATE',[$supplier]) as $part)$partsByCorrection[(int)$part['correction_id']][]=['allocation_id'=>(int)$part['allocation_id'],'amount_cents'=>$part['amount_cents']];
        $records=[];$chargeIds=[];$operationIds=[];$reductions=[];$reliefs=[];
        foreach($this->rows('SELECT * FROM unit_payment_corrections WHERE supplier_id=? ORDER BY id FOR UPDATE',[$supplier]) as $row){
            $snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);$request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);
            if(!hash_equals($row['snapshot_hash'],PropertyInput::hash($snapshot))||!hash_equals($row['request_hash'],PropertyInput::hash(['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]))||PropertyInput::hash($request)!==PropertyInput::hash($snapshot['request']))PaymentException::reject('history_corrupt');
            $anchors=$anchorsByCorrection[(int)$row['id']]??[];$parts=$partsByCorrection[(int)$row['id']]??[];
            if(PropertyInput::hash($anchors)!==PropertyInput::hash($snapshot['anchors'])||PropertyInput::hash($parts)!==PropertyInput::hash($snapshot['reliefs']))PaymentException::reject('history_corrupt');
            if($row['status']==='pending')continue;
            $relevant=$debtIds===null||array_any($anchors,fn($a)=>isset($lines[$a['original_line_id']]))||array_any($parts,fn($p)=>isset($allocationIds[$p['allocation_id']]));
            if($relevant){$records[]=[$row,$snapshot];$chargeIds[]=(int)$row['adjustment_charge_id'];if($row['operation_id']!==null)$operationIds[]=(int)$row['operation_id'];}
            if($row['status']!=='posted')continue;
            foreach($anchors as $anchor)$reductions[$anchor['original_line_id']]=bcadd($reductions[$anchor['original_line_id']]??'0',$anchor['amount_cents'],0);
            foreach($parts as $part)$reliefs[$part['allocation_id']]=bcadd($reliefs[$part['allocation_id']]??'0',$part['amount_cents'],0);
        }
        $charges=(new IssuanceReadRepository($this->db))->findMany($supplier,$chargeIds,true);$proofs=[];$operations=[];$entryIds=[];
        foreach(array_chunk(array_values(array_unique($operationIds)),500) as $chunk){
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            foreach($this->rows('SELECT * FROM unit_payment_operations WHERE supplier_id=? AND id IN ('.$marks.') ORDER BY id FOR UPDATE',[$supplier,...$chunk]) as $op){$operations[(int)$op['id']]=$op;$entryIds[]=(int)$op['journal_entry_id'];}
        }
        $ledgers=(new PaymentJournalProof($this->db))->currentMany($supplier,$entryIds);
        foreach($records as [$row,$snapshot]){
            $charge=$charges[(int)$row['adjustment_charge_id']];
            if($charge['snapshot_hash']!==$snapshot['adjustment']['snapshot_hash']||$charge['status']!==$row['status'])PaymentException::reject('correction_source_changed');
            $proofs[]=['entry_id'=>$charge['journal_entry_id'],'charge'=>$charge,'reversal'=>false];
            if($row['status']==='reversed')$proofs[]=['entry_id'=>$charge['reversal_entry_id'],'charge'=>$charge,'reversal'=>true];
            if($row['operation_id']!==null){
                $op=$operations[(int)$row['operation_id']]??null;
                if(!$op||$op['status']!==$row['status']||$op['kind']!=='correction')PaymentException::reject('history_corrupt');
                $fact=json_decode($op['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
                if(!hash_equals($op['snapshot_hash'],PropertyInput::hash($fact))||($fact['correction_id']??null)!==(int)$row['id']||PropertyInput::hash($fact['lines'])!==PropertyInput::hash($snapshot['lines']))PaymentException::reject('history_corrupt');
                $ledger=$ledgers[(int)$op['journal_entry_id']];
                if($ledger['entry']['source_type']!=='unit_payment'||$ledger['entry']['source_id']!==(int)$op['id']||$ledger['entry']['entry_date']!==$snapshot['request']['accounting_date']||$ledger['entry']['reversed_by']!==($row['status']==='reversed'?(int)$op['reversal_entry_id']:null)||PropertyInput::hash(PaymentJournalProof::canonicalLines($ledger['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($snapshot['lines'])))PaymentException::reject('ledger_mismatch');
            }elseif($snapshot['relief_cents']!=='0')PaymentException::reject('history_corrupt');
        }
        (new IssuanceJournalProof($this->db))->assertEntries($supplier,$proofs);
        $services=(new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($this->db))->currentForDebts($supplier,$debtIds);
        foreach($services['reductions'] as $id=>$amount)$reductions[$id]=bcadd($reductions[$id]??'0',$amount,0);
        foreach($services['reliefs'] as $id=>$amount)$reliefs[$id]=bcadd($reliefs[$id]??'0',$amount,0);
        return ['reductions'=>$reductions,'reliefs'=>$reliefs,'opening_reductions'=>$services['opening_reductions']];
    }
    private function rows(string $sql,array $params):array{$q=$this->db->pdo()->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);}
}
