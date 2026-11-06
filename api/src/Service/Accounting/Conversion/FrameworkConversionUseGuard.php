<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Conversion;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\FrameworkConversionRepository as R;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\PostingException;
final class FrameworkConversionUseGuard
{
    public function __construct(private readonly Connection $db){}
    public function assertEntriesMutable(int $supplier,array $ids):void
    {
        if($ids===[])return;$this->lock($supplier);$ids=array_values(array_unique(array_map('intval',$ids)));$marks=implode(',',array_fill(0,count($ids),'?'));
        $q=$this->db->pdo()->prepare("SELECT c.id FROM accounting_framework_conversions c JOIN accounting_periods p ON p.id=c.source_period_id AND p.supplier_id=c.supplier_id JOIN journal_entries e ON e.supplier_id=c.supplier_id AND (e.entry_date<=p.ends_on OR e.id=c.opening_entry_id OR EXISTS(SELECT 1 FROM accounting_framework_conversion_decisions d WHERE d.supplier_id=c.supplier_id AND d.conversion_id=c.id AND d.journal_entry_id=e.id)) WHERE c.supplier_id=? AND c.status='applied' AND e.id IN ($marks) ORDER BY c.id FOR UPDATE");$q->execute([$supplier,...$ids]);if($q->fetchColumn()!==false)$this->reject();
    }
    public function assertSourceMutable(int $supplier,string $type,int $id):void
    {$this->lock($supplier);$q=$this->db->pdo()->prepare('SELECT id FROM journal_entries WHERE supplier_id=? AND source_type=? AND source_id=? ORDER BY id FOR UPDATE');$q->execute([$supplier,$type,$id]);$this->assertEntriesMutable($supplier,$q->fetchAll(\PDO::FETCH_COLUMN));}
    public function assertLinesMutable(int $supplier,array $ids):void
    {if($ids===[])return;$this->lock($supplier);$q=$this->db->pdo()->prepare('SELECT DISTINCT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY entry_id FOR UPDATE');$q->execute([$supplier,...$ids]);$this->assertEntriesMutable($supplier,$q->fetchAll(\PDO::FETCH_COLUMN));}
    public function assertBankStatementMutable(int $supplier,int $statement):void
    {$this->lock($supplier);$ownership=\MyInvoice\Repository\BankStatementOwnershipResolver::sql('s',true);$q=$this->db->pdo()->prepare("SELECT e.id FROM journal_entries e JOIN bank_transactions b ON e.source_type='bank' AND e.source_id=b.id JOIN bank_statements s ON s.id=b.statement_id WHERE e.supplier_id=? AND s.id=? AND {$ownership} ORDER BY e.id FOR UPDATE");$q->execute([$supplier,$statement,...\MyInvoice\Repository\BankStatementOwnershipResolver::params($supplier)]);$this->assertEntriesMutable($supplier,$q->fetchAll(\PDO::FETCH_COLUMN));}
    public function assertPeriodMutable(int $supplier,int $period):void
    {$this->lock($supplier);$q=$this->db->pdo()->prepare("SELECT c.id FROM accounting_framework_conversions c JOIN accounting_periods p ON p.supplier_id=c.supplier_id AND p.id=? JOIN accounting_periods s ON s.id=c.source_period_id AND s.supplier_id=c.supplier_id WHERE c.supplier_id=? AND c.status='applied' AND p.starts_on<=s.ends_on ORDER BY c.id FOR UPDATE");$q->execute([$period,$supplier]);if($q->fetchColumn()!==false)$this->reject();}
    public function assertFrameworkMutable(int $supplier,string $effective):void
    {$this->lock($supplier);$q=$this->db->pdo()->prepare("SELECT id FROM accounting_framework_conversions WHERE supplier_id=? AND status='applied' AND effective_from>=? ORDER BY id FOR UPDATE");$q->execute([$supplier,$effective]);if($q->fetchColumn()!==false)$this->reject();}
    public function assertGenericJournal(array $header):void
    {
        $supplier=(int)$header['supplier_id'];$this->lockJournalPeriod($header);
        $q=$this->db->pdo()->prepare("SELECT c.id FROM accounting_framework_conversions c JOIN accounting_periods p ON p.supplier_id=c.supplier_id AND p.id=c.source_period_id WHERE c.supplier_id=? AND c.status='applied' AND (?<=p.ends_on OR (?='opening' AND c.target_period_id=?)) ORDER BY c.id FOR UPDATE");$q->execute([$supplier,$header['entry_date'],$header['source_type']??'manual',$header['period_id']??0]);if($q->fetchColumn()!==false)$this->reject();
    }
    private function lockJournalPeriod(array $header):void
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Conversion guard requires caller transaction.');
        $sql='SELECT id FROM accounting_periods WHERE supplier_id=?';$args=[(int)$header['supplier_id']];
        if(isset($header['period_id'])){$sql.=' AND id=?';$args[]=(int)$header['period_id'];}
        else{$sql.=' AND starts_on<=? AND ends_on>=?';array_push($args,$header['entry_date'],$header['entry_date']);}
        $q=$this->db->pdo()->prepare($sql.' ORDER BY starts_on,id FOR UPDATE');$q->execute($args);$q->fetchAll(\PDO::FETCH_COLUMN);
        $q=$this->db->pdo()->prepare('SELECT id FROM supplier WHERE id=? FOR UPDATE');$q->execute([(int)$header['supplier_id']]);
    }
    private function lock(int $supplier):void
    {if(!$this->db->pdo()->inTransaction())throw new \LogicException('Conversion guard requires caller transaction.');(new DimensionPeriodGuard($this->db))->lockPeriods($supplier);$q=$this->db->pdo()->prepare('SELECT id FROM supplier WHERE id=? FOR UPDATE');$q->execute([$supplier]);}
    private function reject():never{throw new PostingException('framework_conversion_source_immutable','Účetní podklad je použit v řízeném převodu 500 → 504. Nejprve zrušte dosud nepoužitý převod jeho vlastním postupem.',409);}
}
