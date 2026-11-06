<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
final class PaymentUseGuard
{
    public function __construct(private readonly Connection $db) {}
    public function assertEntriesMutable(int $supplier,array $entryIds):void
    {
        (new PaymentLocks($this->db))->acquire($supplier);
        $this->assertCurrentReferences($supplier,$entryIds,false);
    }
    public function assertAssignmentInsertsMutable(int $supplier,array $lineIds):void
    {
        (new \MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard($this->db))->assertAssignmentInsertsMutable($supplier,$lineIds);
    }
    private function assertCurrentReferences(int $supplier,array $ids,bool $lines):void
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Payment references require current owner locks.');
        $ids=array_values(array_unique(array_map('intval',$ids)));sort($ids,SORT_NUMERIC);
        foreach(array_chunk($ids,500) as $chunk){
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $from=$lines?'journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id AND e.supplier_id=l.supplier_id':'journal_entries e';
            $id=$lines?'l.id':'e.id';
            $q=$this->db->pdo()->prepare(\MyInvoice\Infrastructure\Database\SubqueryCache::OFF."SELECT e.id FROM {$from} WHERE e.supplier_id=? AND {$id} IN ({$marks}) AND (".self::entryReferenceSql().") ORDER BY e.id FOR UPDATE");
            $q->execute([$supplier,...$chunk]);if($q->fetchColumn()!==false)self::reject();
        }
    }
    public static function entryReferenceSql():string
    {
        return \MyInvoice\Service\Property\Funds\FundingUseGuard::entryReferenceSql()." OR ".\MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard::entryReferenceSql()." OR ".\MyInvoice\Service\Property\Opening\OpeningUseGuard::entryReferenceSql()." OR EXISTS (SELECT 1 FROM unit_payment_sources s WHERE s.supplier_id=e.supplier_id AND s.journal_entry_id=e.id FOR UPDATE)
                OR EXISTS (SELECT 1 FROM unit_payment_operations o WHERE o.supplier_id=e.supplier_id AND (o.journal_entry_id=e.id OR o.reversal_entry_id=e.id) FOR UPDATE)
                OR EXISTS (SELECT 1 FROM unit_claim_accessory_receipts r WHERE r.supplier_id=e.supplier_id AND r.journal_entry_id=e.id FOR UPDATE)
                OR EXISTS (SELECT 1 FROM unit_payment_source_revisions r WHERE r.supplier_id=e.supplier_id AND r.journal_entry_id=e.id FOR UPDATE)
                OR EXISTS (SELECT 1 FROM unit_charges c JOIN unit_payment_allocations a ON a.supplier_id=c.supplier_id AND a.charge_id=c.id JOIN unit_payment_operations o ON o.supplier_id=a.supplier_id AND o.id=a.operation_id WHERE c.supplier_id=e.supplier_id AND c.journal_entry_id=e.id AND o.status='posted' FOR UPDATE)";
    }
    public static function externalOpeningReferencesSql():string
    {
        return \MyInvoice\Service\Property\Funds\FundingUseGuard::entryReferenceSql()." OR EXISTS (SELECT 1 FROM unit_payment_sources s WHERE s.supplier_id=e.supplier_id AND s.journal_entry_id=e.id FOR UPDATE)
                OR EXISTS (SELECT 1 FROM unit_payment_operations o WHERE o.supplier_id=e.supplier_id AND (o.journal_entry_id=e.id OR o.reversal_entry_id=e.id) FOR UPDATE)
                OR EXISTS (SELECT 1 FROM unit_claim_accessory_receipts r WHERE r.supplier_id=e.supplier_id AND r.journal_entry_id=e.id AND r.opening_component_id IS NULL FOR UPDATE)
                OR EXISTS (SELECT 1 FROM unit_payment_source_revisions r WHERE r.supplier_id=e.supplier_id AND r.journal_entry_id=e.id FOR UPDATE)
                OR EXISTS (SELECT 1 FROM unit_charges c JOIN unit_payment_allocations a ON a.supplier_id=c.supplier_id AND a.charge_id=c.id JOIN unit_payment_operations o ON o.supplier_id=a.supplier_id AND o.id=a.operation_id WHERE c.supplier_id=e.supplier_id AND c.journal_entry_id=e.id AND o.status='posted' FOR UPDATE)";
    }
    public static function assertEntryReferenceMutable(bool $referenced):void
    {
        if(!$referenced)return;
        try { self::reject(); }
        catch(PaymentException $e){throw new \MyInvoice\Service\Accounting\PostingException($e->errorCode,$e->getMessage(),$e->httpStatus,$e->details);}
    }
    public function assertLinesMutable(int $supplier,array $ids):void
    {
        (new PaymentLocks($this->db))->acquire($supplier);if($ids===[])return;
        foreach($ids as $id)\MyInvoice\Service\Property\PropertyInput::id($id,'line_id');
        $q=$this->db->pdo()->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY entry_id,id FOR UPDATE');$q->execute([$supplier,...$ids]);
        $this->assertEntriesMutable($supplier,array_values(array_unique(array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)))));
    }
    public function assertSourceMutable(int $supplier,string $kind,int $id):void
    {
        (new PaymentLocks($this->db))->acquire($supplier);if(!in_array($kind,['bank','cash'],true))throw new \InvalidArgumentException('source_kind');
        $this->fundingGuard(fn()=>\MyInvoice\Service\Property\Funds\FundingUseGuard::assertSourceUnclaimed($this->db,$supplier,$kind,$id));
        $q=$this->db->pdo()->prepare('SELECT id FROM unit_payment_sources WHERE supplier_id=? AND '.($kind==='bank'?'bank_transaction_id':'cash_document_id').'=? FOR UPDATE');$q->execute([$supplier,$id]);if($q->fetchColumn()!==false)self::reject();
    }
    public function assertBankStatementMutable(int $supplier,int $statement):void
    {
        (new PaymentLocks($this->db))->acquire($supplier);
        $sources=$this->db->pdo()->prepare('SELECT t.id FROM bank_transactions t JOIN bank_statements b ON b.id=t.statement_id WHERE b.supplier_id=? AND b.id=? ORDER BY t.id FOR UPDATE');$sources->execute([$supplier,$statement]);
        foreach($sources->fetchAll(PDO::FETCH_COLUMN) as $source)$this->fundingGuard(fn()=>\MyInvoice\Service\Property\Funds\FundingUseGuard::assertSourceUnclaimed($this->db,$supplier,'bank',(int)$source));
        $q=$this->db->pdo()->prepare('SELECT s.id FROM unit_payment_sources s JOIN bank_transactions t ON t.id=s.bank_transaction_id WHERE s.supplier_id=? AND t.statement_id=? ORDER BY s.id FOR UPDATE');
        $q->execute([$supplier,$statement]);if($q->fetchColumn()!==false)self::reject();
    }
    public function assertChargeCorrectable(int $supplier,int $charge):void
    {
        (new PaymentLocks($this->db))->acquire($supplier);
        (new \MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard($this->db))->assertChargeMutable($supplier,$charge);
        $this->fundingGuard(fn()=>\MyInvoice\Service\Property\Funds\FundingUseGuard::assertChargeMutable($this->db,$supplier,$charge));
        $q=$this->db->pdo()->prepare("SELECT a.id FROM unit_payment_allocations a JOIN unit_payment_operations o ON o.supplier_id=a.supplier_id AND o.id=a.operation_id WHERE a.supplier_id=? AND a.charge_id=? AND o.status='posted' ORDER BY a.id FOR UPDATE");$q->execute([$supplier,$charge]);if($q->fetchColumn()!==false)self::reject();
        $q=$this->db->pdo()->prepare("SELECT id FROM unit_payment_corrections WHERE supplier_id=? AND adjustment_charge_id=? AND status='posted' ORDER BY id FOR UPDATE");$q->execute([$supplier,$charge]);if($q->fetchColumn()!==false)self::reject();
    }
    public function assertOperationReversible(int $supplier,int $operation):void
    {
        (new PaymentLocks($this->db))->acquire($supplier);
        (new \MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard($this->db))->assertOperationReversible($supplier,$operation);
        $q=$this->db->pdo()->prepare("SELECT r.allocation_id FROM unit_payment_reliefs r JOIN unit_payment_corrections c ON c.supplier_id=r.supplier_id AND c.id=r.correction_id JOIN unit_payment_allocations a ON a.supplier_id=r.supplier_id AND a.id=r.allocation_id WHERE r.supplier_id=? AND a.operation_id=? AND c.status='posted' ORDER BY r.allocation_id FOR UPDATE");$q->execute([$supplier,$operation]);if($q->fetchColumn()!==false)PaymentException::reject('allocation_has_relief');
    }
    public function assertChargePostingAllowed(int $supplier,int $id):void
    {
        (new PaymentLocks($this->db))->acquire($supplier);$reader=new \MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository($this->db);$charge=$reader->find($supplier,$id,true);
        $negative=false;foreach($charge['snapshot']['lines'] as $line)if(bccomp($line['amount_cents'],'0',0)<0)$negative=true;if(!$negative)return;
        $seen=[];$next=$charge['previous_charge_id'];
        while($next!==null){if(isset($seen[$next]))PaymentException::reject('history_corrupt');$seen[$next]=true;
            $this->fundingGuard(fn()=>\MyInvoice\Service\Property\Funds\FundingUseGuard::assertChargeMutable($this->db,$supplier,$next));
            $q=$this->db->pdo()->prepare('SELECT id FROM unit_payment_debts WHERE supplier_id=? AND charge_id=? ORDER BY id FOR UPDATE');$q->execute([$supplier,$next]);if($q->fetchColumn()!==false)PaymentException::reject('correction_owner_required');
            $next=$reader->find($supplier,$next,true)['previous_charge_id'];
        }
    }
    public function assertPositiveChargePostingBatchAllowed(int $supplier,array $charges):void
    {
        (new PaymentLocks($this->db))->acquire($supplier);
        if(!$this->db->pdo()->inTransaction()||$charges===[])throw new \LogicException('Positive charge posting batch requires owner locks.');
        $expected=[];
        foreach($charges as $charge){
            $id=(int)($charge['id']??0);
            if($id<1||(int)($charge['supplier_id']??0)!==$supplier||isset($expected[$id]))throw new \LogicException('Invalid positive charge posting batch.');
            foreach($charge['snapshot']['lines']??[] as $line)if(bccomp((string)$line['amount_cents'],'0',0)<0)throw new \LogicException('Negative charge requires scalar posting guard.');
            $expected[$id]=[(int)$charge['row_version'],(string)$charge['snapshot_hash']];
        }
        ksort($expected,SORT_NUMERIC);$actual=[];$ids=array_keys($expected);
        foreach(array_chunk($ids,500)as$chunk){$marks=implode(',',array_fill(0,count($chunk),'?'));
            $q=$this->db->pdo()->prepare('SELECT id,row_version,snapshot_hash FROM unit_charges WHERE supplier_id=? AND id IN ('.$marks.') ORDER BY id FOR UPDATE');
            $q->execute([$supplier,...$chunk]);foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row)$actual[(int)$row['id']]=[(int)$row['row_version'],(string)$row['snapshot_hash']];
        }
        if($actual!==$expected)throw new \LogicException('Positive charge posting batch changed after current read.');
    }
    private function fundingGuard(callable $check):void
    {
        try{$check();}catch(\MyInvoice\Service\Property\Funds\FundingException $e){self::reject();}
    }
    private static function reject():never{PaymentException::reject('source_immutable');}
}
