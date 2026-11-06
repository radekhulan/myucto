<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\PostingException;
use PDO;

final class SecuritiesDebt504UseGuard
{
    private static array $reversing=[];
    public function __construct(private readonly Connection $db) {}

    public static function integrated(): bool
    {
        return defined(\MyInvoice\Repository\JournalEntryRepository::class.'::SECURITIES_DEBT_GUARD_VERSION')
            && constant(\MyInvoice\Repository\JournalEntryRepository::class.'::SECURITIES_DEBT_GUARD_VERSION')===1;
    }

    public function assertEntriesMutable(int $supplier,array $ids): void
    {
        $this->lock($supplier);
        foreach(array_unique($ids) as $id){
            if(isset(self::$reversing[$this->key($supplier,(int)$id)]))continue;
            $q=$this->db->pdo()->prepare('SELECT e.id FROM journal_entries e WHERE e.supplier_id=? AND e.id=? AND ('.self::referenceSql().') FOR UPDATE');
            $q->execute([$supplier,$id]);if($q->fetchColumn()!==false)$this->reject();
        }
    }

    public function assertLinesMutable(int $supplier,array $ids): void
    {
        $this->lock($supplier);
        foreach(array_unique($ids) as $id){$q=$this->db->pdo()->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$id]);$entry=$q->fetchColumn();if($entry!==false)$this->assertEntriesMutable($supplier,[(int)$entry]);}
    }

    public function assertSourceMutable(int $supplier,string $kind,int $id): void
    {
        $this->lock($supplier);
        $q=$this->db->pdo()->prepare('SELECT id FROM journal_entries WHERE supplier_id=? AND source_type=? AND source_id=? ORDER BY id FOR UPDATE');$q->execute([$supplier,$kind,$id]);
        $this->assertEntriesMutable($supplier,array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function assertBankStatementMutable(int $supplier,int $id): void
    {
        $this->lock($supplier);
        $q=$this->db->pdo()->prepare("SELECT e.id FROM journal_entries e JOIN bank_transactions b ON e.source_type='bank' AND e.source_id=b.id WHERE e.supplier_id=? AND b.statement_id=? ORDER BY e.id FOR UPDATE");$q->execute([$supplier,$id]);
        $this->assertEntriesMutable($supplier,array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function assertFxPeriodMutable(int $supplier,int $period): void
    {
        $this->lock($supplier);$q=$this->db->pdo()->prepare('SELECT ends_on FROM accounting_periods WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$period]);$end=$q->fetchColumn();SecuritiesDebt504Terms::need(is_string($end),'debt_period_required');
        $q=$this->db->pdo()->prepare('SELECT c.lot_id FROM accounting_securities_debt_fx_carries c WHERE c.supplier_id=? AND c.period_id=? AND (EXISTS(SELECT 1 FROM accounting_securities_debt_events e WHERE e.supplier_id=c.supplier_id AND e.lot_id=c.lot_id AND e.accounting_date>? FOR UPDATE) OR EXISTS(SELECT 1 FROM accounting_securities_debt_fx_carries n WHERE n.supplier_id=c.supplier_id AND n.lot_id=c.lot_id AND n.period_id<>c.period_id AND n.accounting_date>? FOR UPDATE)) ORDER BY c.lot_id FOR UPDATE');$q->execute([$supplier,$period,$end,$end]);
        if($q->fetchColumn()!==false)throw new PostingException('debt_fx_carry_consumed','Historické kurzové přecenění je použito pozdější operací dluhového cenného papíru.',409);
    }

    public function reverseLatest(int $supplier,int $lot,int $event,int $entry,string $date,callable $reverse): mixed
    {
        $state=(new \MyInvoice\Repository\SecuritiesDebt504Repository($this->db))->current($supplier,$lot,$date);
        $last=$state['events']===[]?null:$state['events'][array_key_last($state['events'])];
        SecuritiesDebt504Terms::need($last!==null && $last['id']===$event && ($last['receipt']['ledger']['entry']['id']??null)===$entry && (!isset($last['receipt']['payment']) || $last['request']['operation']==='sale') && $date>=$state['max_event_date'],'debt_reverse_dependency');
        $key=$this->key($supplier,$entry);SecuritiesDebt504Terms::need(!isset(self::$reversing[$key]),'debt_reverse_dependency');self::$reversing[$key]=true;
        try{return $reverse();}finally{unset(self::$reversing[$key]);}
    }

    private static function referenceSql(): string
    {
        return "EXISTS (SELECT 1 FROM accounting_securities_debt_claims c JOIN journal_entry_lines l ON l.supplier_id=c.supplier_id AND l.id=c.line_id WHERE c.supplier_id=e.supplier_id AND l.entry_id=e.id FOR UPDATE)
            OR EXISTS (SELECT 1 FROM accounting_securities_debt_fx_carries f WHERE f.supplier_id=e.supplier_id AND f.journal_entry_id=e.id FOR UPDATE)
            OR EXISTS (SELECT 1 FROM accounting_securities_debt_events d WHERE d.supplier_id=e.supplier_id AND d.journal_entry_id=e.id FOR UPDATE)";
    }

    private function lock(int $supplier): void
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Securities mutation guard requires a caller transaction.');
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);
        $q=$this->db->pdo()->prepare('SELECT id FROM supplier WHERE id=? FOR UPDATE');$q->execute([$supplier]);
    }
    private function key(int $supplier,int $entry): string{return spl_object_id($this->db).':'.$supplier.':'.$entry;}
    private function reject(): never{throw new PostingException('debt_source_immutable','Účetní zdroj je součástí evidence dluhového cenného papíru.',409);}
}
