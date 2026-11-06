<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Receivables;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Repository\Property\Receivables\{FinancialHistoryRepository,PartyEventRepository};
use MyInvoice\Service\Accounting\Dimension\DimensionFilter;
use PDO;
final class PropertyReconciliation
{
    public function __construct(private readonly Connection $db){}
    public function at(int $supplier,string $date,?int $unitId=null):array
    {
        $known=PartyEventRepository::now();ReceivableTimeline::accountingDate([],$date,$known);$pdo=$this->db->pdo();$owned=!$pdo->inTransaction();if($owned)$pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT starts_on FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? ORDER BY starts_on,id');$q->execute([$supplier,$date,$date]);$periods=$q->fetchAll(PDO::FETCH_COLUMN);if(count($periods)!==1)ReceivableException::reject('period_coverage');
            $filter=[];if($unitId!==null){$q=$pdo->prepare("SELECT o.dimension_value_id,v.type_id FROM property_objects o JOIN dimension_values v ON v.supplier_id=o.supplier_id AND v.id=o.dimension_value_id WHERE o.supplier_id=? AND o.id=? AND o.kind='unit'");$q->execute([$supplier,$unitId]);$unit=$q->fetch(PDO::FETCH_ASSOC);if(!$unit)ReceivableException::reject('foreign_unit');$filter=['dimension'=>new DimensionFilter((int)$unit['type_id'],(int)$unit['dimension_value_id'],[(int)$unit['dimension_value_id']])];}
            $rows=(new LedgerReportRepository($this->db))->trialBalanceRows($supplier,$periods[0],$date,$periods[0],true,$filter,true);$ledger=['receivable'=>'0','credit'=>'0'];
            $svj=\MyInvoice\Service\Accounting\AccountRoleResolver::fromConnection($this->db)->forDate($supplier,$date)->framework==='cz_504'&&((new \MyInvoice\Repository\Property\PropertyProfileRepository($this->db))->at($supplier,(new \DateTimeImmutable($date.' 23:59:59',new \DateTimeZone('Europe/Prague')))->format(DATE_ATOM))['enabled']??false);
            foreach($rows as $row){$balance=bcsub(bcadd((string)$row['ps_md'],(string)$row['to_md'],2),bcadd((string)$row['ps_d'],(string)$row['to_d'],2),2);$cents=bcmul($balance,'100',0);
                if(($row['account_code']==='315'||str_starts_with($row['account_code'],'315.'))&&!($svj&&preg_match(\MyInvoice\Service\Property\Opening\OpeningRolePolicy::ROLES['service_cost'][2],$row['account_code'])===1))$ledger['receivable']=bcadd($ledger['receivable'],$cents,0);
                if($row['account_code']==='379.910')$ledger['credit']=bcsub($ledger['credit'],$cents,0);
            }
            // Jednotka čte jen uzavřenou historii svých nároků a kreditů, dům celou historii firmy.
            $history=$unitId===null?(new FinancialHistoryRepository($this->db))->read($supplier):(new \MyInvoice\Repository\Property\Receivables\PropertySaldoReadRepository($this->db))->unitHistory($supplier,[$unitId])['history'];$events=[];foreach($history['debts']+$history['unregistered_claims'] as $debt)if($unitId===null||$unitId===$debt['unit_id'])array_push($events,...$debt['events']);foreach($history['credits'] as $credit)if($unitId===null||$unitId===$credit['unit_id'])array_push($events,...$credit['events']);
            $totals=ReceivableTimeline::accountingDate($events,$date,$known);$difference=['receivable'=>bcsub($ledger['receivable'],$totals['gross_receivable_cents'],0),'credit'=>bcsub($ledger['credit'],$totals['gross_credit_cents'],0)];
            $unresolved=UnresolvedNegativeChargeScope::accountingDate($history,$date,$known,$unitId);
            $opening=(new \MyInvoice\Service\Property\Opening\OpeningReconciliation($this->db))->at($supplier,$periods[0],$date,$unitId);
            $result=['accounting_date'=>$date,'knowledge_basis'=>'current_ledger','unit_id'=>$unitId,'ledger_cents'=>$ledger,'subledger_cents'=>['receivable'=>$totals['gross_receivable_cents'],'credit'=>$totals['gross_credit_cents']],'difference_cents'=>$difference,'matches'=>$difference===['receivable'=>'0','credit'=>'0']&&$unresolved===[],'view'=>'before_closing','opening_import_coverage'=>$opening['coverage'],'opening'=>$opening];
            if($owned)$pdo->commit();return $result;
        }catch(\Throwable $e){if($owned&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
