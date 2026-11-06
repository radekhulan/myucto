<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Property\PropertyInput;
use PDO;
final class PaymentJournalProof
{
    public function __construct(private readonly Connection $db) {}
    public function current(int $supplier,int $id): array
    {
        return $this->currentMany($supplier,[$id])[$id];
    }
    public function snapshot(int $supplier,int $id,bool $currentRead=false):array
    {
        return $this->readMany($supplier,[$id],$currentRead)[$id];
    }
    public function currentMany(int $supplier,array $entryIds): array
    {
        return $this->readMany($supplier,$entryIds,true);
    }
    /** Tabulky, ze kterých se důkaz zápisu skládá. */
    private const TABLES=['journal_entries','journal_entry_lines','chart_of_accounts','journal_entry_line_dimensions','journal_entry_line_dimension_splits'];

    /**
     * Důkazy zápisů přečtené aktuálním čtením (`FOR UPDATE`) v probíhající transakci.
     * Zámky drží přečtené řádky do konce transakce a zápisy SVJ jdou přes zámek období,
     * jiná transakce je tedy nezmění. Změnu vlastní transakcí, savepoint i konec
     * transakce pozná {@see \MyInvoice\Infrastructure\Database\LoggingPdo::tableWriteCount()}.
     *
     * @var \WeakMap<\PDO,array{writes:string,entries:array<string,array>}>|null
     */
    private static ?\WeakMap $currentMemo=null;

    private function readMany(int $supplier,array $entryIds,bool $currentRead):array
    {
        $pdo=$this->db->pdo();
        if(!$pdo->inTransaction()) throw new \LogicException('Payment ledger proof requires period-first owner locks.');
        foreach($entryIds as $id)PropertyInput::id($id,'journal_entry_id');
        if(!$currentRead||!$pdo instanceof \MyInvoice\Infrastructure\Database\LoggingPdo||!$pdo->statementsTracked())return $this->readFresh($supplier,$entryIds,$currentRead);
        $writes=implode('|',array_map($pdo->tableWriteCount(...),self::TABLES));
        self::$currentMemo??=new \WeakMap();
        $memo=self::$currentMemo[$pdo]??null;
        if($memo===null||$memo['writes']!==$writes)$memo=['writes'=>$writes,'entries'=>[]];
        $entryIds=array_values(array_unique($entryIds));sort($entryIds,SORT_NUMERIC);
        $missing=array_values(array_filter($entryIds,fn(int $id)=>!isset($memo['entries'][$supplier.'|'.$id])));
        if($missing!==[])foreach($this->readFresh($supplier,$missing,true) as $id=>$proof)$memo['entries'][$supplier.'|'.$id]=$proof;
        self::$currentMemo[$pdo]=$memo;
        $out=[];
        foreach($entryIds as $id)$out[$id]=$memo['entries'][$supplier.'|'.$id];
        return $out;
    }

    private function readFresh(int $supplier,array $entryIds,bool $currentRead):array
    {
        $suffix=$currentRead?' FOR UPDATE':'';
        $entryIds=array_values(array_unique($entryIds));sort($entryIds,SORT_NUMERIC);$out=[];
        foreach(array_chunk($entryIds,500) as $chunk){
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $s=$this->db->pdo()->prepare('SELECT id,period_id,entry_date,source_type,source_id,posted_at,reversed_by FROM journal_entries WHERE supplier_id=? AND id IN ('.$marks.') ORDER BY id'.$suffix);$s->execute([$supplier,...$chunk]);
            $entries=$s->fetchAll(PDO::FETCH_ASSOC);
            if(count($entries)!==count($chunk))PaymentException::reject('ledger_unposted');
            foreach($entries as $entry){
                if($entry['posted_at']===null)PaymentException::reject('ledger_unposted');
                foreach(['id','period_id','source_id','reversed_by'] as $key)if($entry[$key]!==null)$entry[$key]=(int)$entry[$key];
                unset($entry['posted_at']);$out[$entry['id']]=['entry'=>$entry,'lines'=>[]];
            }
            $s=$this->db->pdo()->prepare('SELECT l.*,a.account_code FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id IN ('.$marks.') ORDER BY l.entry_id,l.line_no,l.id'.$suffix);$s->execute([$supplier,...$chunk]);$lines=$s->fetchAll(PDO::FETCH_ASSOC);
            $repo=new DimensionAssignmentRepository($this->db);$ids=array_column($lines,'id');
            $dims=$repo->lineDimensions($supplier,$ids,$currentRead);$splits=$repo->lineSplits($supplier,$ids,$currentRead);$origins=$repo->lineOrigins($supplier,$ids,$currentRead);$splitOrigins=$repo->lineSplitOrigins($supplier,$ids,$currentRead);
            foreach($lines as $l)$out[(int)$l['entry_id']]['lines'][]=['id'=>(int)$l['id'],'account_code'=>$l['account_code'],'side'=>$l['side'],'amount'=>JournalLineAmount::decimal($l['amount']),
                'currency_code'=>$l['currency_code'],'amount_foreign'=>$l['amount_foreign'],'fx_rate'=>$l['fx_rate'],'is_red_storno'=>(bool)$l['is_red_storno'],
                'dimensions'=>$dims[$l['id']]??[],'dimension_splits'=>$splits[$l['id']]??[],'dimension_origins'=>$origins[$l['id']]??[],'dimension_split_origins'=>$splitOrigins[$l['id']]??[]]+self::legacyContext($l);
        }
        return $out;
    }
    public function assertSame(int $supplier,array $receipt): void
    {
        if(PropertyInput::hash($receipt)!==PropertyInput::hash($this->current($supplier,$receipt['entry']['id']))) PaymentException::reject('ledger_mismatch');
    }
    public static function canonicalLines(array $lines): array
    {
        $out=[];
        foreach($lines as $l) $out[]=['account_code'=>$l['account_code'],'side'=>$l['side'],'amount'=>JournalLineAmount::decimal($l['amount']),
            'currency_code'=>$l['currency_code']??null,'amount_foreign'=>$l['amount_foreign']??null,'fx_rate'=>$l['fx_rate']??null,'is_red_storno'=>(bool)($l['is_red_storno']??false),
            'dimensions'=>$l['dimensions']??[],'dimension_splits'=>$l['dimension_splits']??[],'dimension_origins'=>$l['dimension_origins']??[],'dimension_split_origins'=>$l['dimension_split_origins']??[]]+self::legacyContext($l);
        return $out;
    }

    private static function legacyContext(array $line): array
    {
        $context=[];
        if(($line['cost_center']??null)!==null)$context['cost_center']=(string)$line['cost_center'];
        if(($line['project_id']??null)!==null)$context['project_id']=(int)$line['project_id'];
        return $context;
    }
}
