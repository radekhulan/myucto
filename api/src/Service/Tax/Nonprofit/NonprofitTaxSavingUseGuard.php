<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{DimensionAssignmentRepository,JournalEntryRepository};
use MyInvoice\Service\Accounting\{JournalLineComparison,PostingException};
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;

final class NonprofitTaxSavingUseGuard
{
    private readonly JournalEntryRepository $journal;
    public function __construct(private readonly Connection $db,?JournalEntryRepository $journal=null)
    {$this->journal=$journal??new JournalEntryRepository($db,new \MyInvoice\Service\ActivityLogger($db));}
    public function assertEntryChange(int $supplier,int $entry,?array $lines,?string $date,?array $header=null):bool
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Tax saving mutation guard requires caller transaction.');
        if(!$this->entryHasActiveUse($supplier,$entry))return false;
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);$ledger=new NonprofitTaxSavingsLedger($this->db);$protected=[];
        foreach($ledger->sources($supplier)as$source){$events=$ledger->events($supplier,$source['id']);$reversed=array_column(array_filter($events,static fn($row)=>$row['operation']==='reverse_use'),'original_id');
            foreach($events as$event)if($event['operation']==='use'&&!in_array($event['id'],$reversed,true))$protected[$event['proof']['cost_native']['ledger']['entry']['id']]=true;
        }
        if(!isset($protected[$entry]))return false;
        $current=$this->journal->findForUpdate($entry,$supplier);if($current===null||$lines===null||$date!==$current['entry_date'])$this->used();
        $repo=new DimensionAssignmentRepository($this->db);$ids=array_column($current['lines'],'id');$dims=$repo->lineDimensions($supplier,$ids,true);$splits=$repo->lineSplits($supplier,$ids,true);
        foreach($current['lines']as&$line){$line['dimensions']=$dims[$line['id']]??[];$line['dimension_splits']=$splits[$line['id']]??[];}unset($line);
        if(!JournalLineComparison::sameLines($current['lines'],$lines))$this->used();
        if($header!==null){foreach(['period_id','source_type','source_id','document_date','document_no','description']as$key)if((string)($header[$key]??'')!==(string)($current[$key]??''))$this->used();if(($header['posted_at']??null)===null)$this->used();}
        return true;
    }
    public function assertEntriesMutable(int $supplier,array $entries):void
    {if($entries===[]||!$this->supplierHasActiveUse($supplier))return;foreach(array_unique(array_map('intval',$entries))as$entry)$this->assertEntryChange($supplier,$entry,null,null);}
    public function assertLinesMutable(int $supplier,array $lines):void
    {
        if($lines===[]||!$this->supplierHasActiveUse($supplier))return;
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);
        foreach(array_unique(array_map('intval',$lines))as$line){$q=$this->db->pdo()->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$line]);$entry=$q->fetchColumn();if($entry!==false)$this->assertEntriesMutable($supplier,[(int)$entry]);}
    }
    public function assertSourceMutable(int $supplier,string $kind,int $id):void
    {if(!$this->supplierHasActiveUse($supplier))return;$entries=(new DimensionPeriodGuard($this->db))->lockSources($supplier,[[$kind,$id]]);$this->assertEntriesMutable($supplier,array_map('intval',array_keys($entries)));}
    public function assertBankStatementMutable(int $supplier,int $id):void
    {
        if(!$this->supplierHasActiveUse($supplier))return;
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);$ownership=\MyInvoice\Repository\BankStatementOwnershipResolver::sql('s',true);$q=$this->db->pdo()->prepare("SELECT e.id FROM journal_entries e JOIN bank_transactions b ON e.source_type='bank' AND e.source_id=b.id JOIN bank_statements s ON s.id=b.statement_id WHERE e.supplier_id=? AND s.id=? AND {$ownership} ORDER BY e.id FOR UPDATE");$q->execute([$supplier,$id,...\MyInvoice\Repository\BankStatementOwnershipResolver::params($supplier)]);$this->assertEntriesMutable($supplier,array_map('intval',$q->fetchAll(\PDO::FETCH_COLUMN)));
    }
    /**
     * Rychlá cesta bez zámků a bez ověřování důkazů: aktivní (nestornované) použití úspory
     * eviduje nonprofit_tax_saving_cost_bindings, jejíž vznik i zánik hlídají triggery
     * (2062). Firma bez použití úspory tak zaplatí jediný dotaz po indexu.
     */
    private function supplierHasActiveUse(int $supplier):bool
    {$q=$this->db->pdo()->prepare('SELECT 1 FROM nonprofit_tax_saving_cost_bindings WHERE supplier_id=? LIMIT 1');$q->execute([$supplier]);return$q->fetchColumn()!==false;}
    private function entryHasActiveUse(int $supplier,int $entry):bool
    {$q=$this->db->pdo()->prepare('SELECT 1 FROM nonprofit_tax_saving_cost_bindings b JOIN journal_entry_lines l ON l.supplier_id=b.supplier_id AND l.id=b.cost_line_id WHERE b.supplier_id=? AND l.entry_id=? LIMIT 1');$q->execute([$supplier,$entry]);return$q->fetchColumn()!==false;}
    private function used():never
    {throw new PostingException('nonprofit_tax_saving_cost_in_use','Náklad prokazuje použití daňové úspory. Nejprve odvolejte příslušné použití.',409);}
}
