<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Receivables;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Receivables\PartyEventRepository;
use MyInvoice\Service\Property\Payments\PaymentException;
use PDO;
final class PartyHistoryUseGuard
{
    public function __construct(private readonly Connection $db){}
    public function assertOperationMutable(int $supplier,int $operation):void
    {
        $this->assertTransaction();$q=$this->db->pdo()->prepare('SELECT id FROM unit_payment_allocations WHERE supplier_id=? AND operation_id=? ORDER BY id FOR UPDATE');$q->execute([$supplier,$operation]);
        $references=array_map(fn($id)=>'allocation:'.$id,$q->fetchAll(PDO::FETCH_COLUMN));
        $this->assertReferencesMutable($supplier,$references);
    }
    public function assertReferencesMutable(int $supplier,array $references):void
    {
        $this->assertTransaction();if($references===[])return;$wanted=array_fill_keys($references,true);$pinned=[];
        foreach((new PartyEventRepository($this->db))->history($supplier,true) as $event)foreach($event['snapshot']['debts'] as $debt)foreach($debt['financial_events'] as $effect){
            if(isset($wanted[$effect['id']]))$pinned[]=['event_id'=>$event['id'],'debt_id'=>$debt['debt_id'],'source_reference'=>$effect['id']];
        }
        if($pinned!==[])PaymentException::reject('party_history_in_use',['references'=>$pinned,'required_process'=>'documented_atomic_liability_correction']);
    }
    public function assertChargeMutable(int $supplier,int $charge):void
    {
        $this->assertTransaction();$q=$this->db->pdo()->prepare('SELECT id FROM unit_payment_debts WHERE supplier_id=? AND charge_id=? ORDER BY id FOR UPDATE');$q->execute([$supplier,$charge]);
        $this->assertReferencesMutable($supplier,array_map(fn($id)=>'claim:'.$id,$q->fetchAll(PDO::FETCH_COLUMN)));
    }
    public function assertChargeLinesMutable(int $supplier,array $lineIds):void
    {
        $this->assertTransaction();if($lineIds===[])return;$q=$this->db->pdo()->prepare('SELECT DISTINCT debt_id FROM unit_payment_debt_lines WHERE supplier_id=? AND charge_line_id IN ('.implode(',',array_fill(0,count($lineIds),'?')).') ORDER BY debt_id FOR UPDATE');$q->execute([$supplier,...$lineIds]);
        $this->assertReferencesMutable($supplier,array_map(fn($id)=>'claim:'.$id,$q->fetchAll(PDO::FETCH_COLUMN)));
    }
    public function assertCorrectionMutable(int $supplier,int $correction):void
    {
        $this->assertTransaction();$q=$this->db->pdo()->prepare('SELECT original_line_id FROM unit_payment_correction_anchors WHERE supplier_id=? AND correction_id=? ORDER BY original_line_id FOR UPDATE');$q->execute([$supplier,$correction]);$references=array_map(fn($id)=>'correction:'.$correction.':anchor:'.$id,$q->fetchAll(PDO::FETCH_COLUMN));
        $q=$this->db->pdo()->prepare('SELECT allocation_id FROM unit_payment_reliefs WHERE supplier_id=? AND correction_id=? ORDER BY allocation_id FOR UPDATE');$q->execute([$supplier,$correction]);array_push($references,...array_map(fn($id)=>'correction:'.$correction.':relief:'.$id,$q->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertReferencesMutable($supplier,$references);
    }
    public function assertDebtMutable(int $supplier,int $debtId):void
    {
        $this->assertTransaction();foreach((new PartyEventRepository($this->db))->history($supplier,true) as $event)foreach($event['snapshot']['debts'] as $debt)if($debt['debt_id']===$debtId)PaymentException::reject('party_history_in_use',['event_id'=>$event['id'],'debt_id'=>$debtId,'required_process'=>'documented_atomic_liability_correction']);
    }
    private function assertTransaction():void{if(!$this->db->pdo()->inTransaction())throw new \LogicException('Party source guard requires current owner period locks.');}
}
