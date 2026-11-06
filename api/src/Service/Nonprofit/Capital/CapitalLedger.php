<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Capital;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\AccountRoleResolver;
use MyInvoice\Service\Accounting\JournalLineAmount;
use PDO;
final class CapitalLedger
{
    public function __construct(private readonly Connection $db) {}
    public function line(int $supplier,int $id,bool $lock=true):array
    {
        $q=$this->db->pdo()->prepare('SELECT l.*,a.account_code,a.account_type,e.period_id,e.source_type,e.source_id,e.entry_date,e.posted_at,e.reversed_by FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.id=?'.($lock?' FOR UPDATE':''));$q->execute([$supplier,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['posted_at']===null||$row['reversed_by']!==null||$row['currency_code']!==null&&$row['currency_code']!=='CZK')CapitalPolicy::changed();
        $d=new DimensionAssignmentRepository($this->db);$dims=$d->lineDimensions($supplier,[$id],$lock)[$id]??[];$splits=$d->lineSplits($supplier,[$id],$lock)[$id]??[];$origins=$d->lineOrigins($supplier,[$id],$lock)[$id]??[];$splitOrigins=$d->lineSplitOrigins($supplier,[$id],$lock)[$id]??[];
        ksort($dims,SORT_NUMERIC);ksort($splits,SORT_NUMERIC);ksort($origins,SORT_NUMERIC);ksort($splitOrigins,SORT_NUMERIC);$activityKind=null;
        if($dims!==[]){$q=$this->db->pdo()->prepare("SELECT id FROM dimension_types WHERE supplier_id=? AND system_key='activity_kind'".($lock?' FOR UPDATE':''));$q->execute([$supplier]);$type=$q->fetchColumn();if($type!==false)$activityKind=$dims[(int)$type]??null;}
        return ['id'=>$id,'entry_id'=>(int)$row['entry_id'],'period_id'=>(int)$row['period_id'],'source_type'=>$row['source_type'],'source_id'=>$row['source_id']===null?null:(int)$row['source_id'],'date'=>$row['entry_date'],'account_id'=>(int)$row['account_id'],'account_code'=>$row['account_code'],'account_type'=>$row['account_type'],'side'=>$row['side'],'amount_cents'=>JournalLineAmount::signedCents($row),'dimensions'=>$dims,'dimension_splits'=>$splits,'dimension_origins'=>$origins,'dimension_split_origins'=>$splitOrigins,'activity_kind_id'=>$activityKind,'cost_center'=>$row['cost_center'],'project_id'=>$row['project_id']===null?null:(int)$row['project_id']];
    }
    public function entry(int $supplier,int $id,bool $lock=true):array
    {
        $q=$this->db->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY line_no,id'.($lock?' FOR UPDATE':''));$q->execute([$supplier,$id]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);if($ids===[])CapitalPolicy::changed();
        return array_map(fn($line)=>$this->line($supplier,(int)$line,$lock),$ids);
    }
    public function source(int $supplier,array $policy,bool $lock=true):array
    {
        $line=$this->line($supplier,$policy['source_line_id'],$lock);
        $prefix=self::sourceAccount($this->db,$supplier,$policy['kind'],$line['date']);
        if(!str_starts_with($line['account_code'],$prefix)||$line['account_type']!=='equity'||$line['side']!=='credit'||$line['amount_cents']<=0||$line['dimension_splits']!==[])CapitalPolicy::invalid('Zdroj musí být samostatný kladný kreditní řádek správného účtu901 nebo911.');
        return $line;
    }
    /** Účet zdroje z rolí účetního rámce: vlastní jmění (901) pro investiční zdroj, fondy (911) pro fond. */
    public static function sourceAccount(Connection $db,int $supplier,string $kind,string $date):string
    {
        $profile=AccountRoleResolver::fromConnection($db)->forDate($supplier,$date);
        if($profile->framework!==AccountingFrameworkService::CZ_504)CapitalPolicy::invalid('Investiční zdroje a fondy vyžadují účetní rámec504.');
        return $profile->account($kind==='investment'?'result.own_equity':'result.funds');
    }
    public function cost(int $supplier,int $line,bool $lock=true):array
    {
        $cost=$this->line($supplier,$line,$lock);
        if($cost['account_type']!=='expense'||!str_starts_with($cost['account_code'],'5')||str_starts_with($cost['account_code'],'59')||$cost['amount_cents']<=0||$cost['side']!=='debit'||$cost['activity_kind_id']===null||$cost['dimension_splits']!==[])CapitalPolicy::invalid('Čerpání vyžaduje samostatný kladný náklad s doloženým druhem činnosti.');
        try{(new \MyInvoice\Service\Accounting\Funding\NativeCostCoverage($this->db))->financingCost($supplier,$line,$lock);}catch(\MyInvoice\Service\Accounting\PostingException $e){throw new CapitalException($e->errorCode,$e->getMessage(),$e->httpStatus);}
        return $cost;
    }
}
