<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Reconciliation;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Services\Settlement\SettlementEvidence;
use PDO;
final class ServiceReconciliation
{
    public function __construct(private readonly Connection $db){}
    private function q(string $sql,array $args=[]):\PDOStatement{$q=$this->db->pdo()->prepare($sql);$q->execute($args);return $q;}
    private static function credit(string $account,string $type):bool{return str_starts_with($account,'324')||str_starts_with($account,'389')||$type==='revenue';}
    private static function amount(array $row,string $type):string{return $row['side']===(self::credit($row['account_code'],$type)?'credit':'debit')?$row['amount_cents']:bcsub('0',$row['amount_cents'],0);}
    private static function add(array &$values,int $unit,int $service,string $account,string $amount):void{$values[$unit][$service][$account]=bcadd($values[$unit][$service][$account]??'0',$amount,0);}
    private static function selected(int $unit,int $service,?int $unitId,?int $serviceId):bool{return($unitId===null||$unitId===$unit)&&($serviceId===null||$serviceId===$service);}
    public function at(int $supplier,string $date,?int $unitId=null,?int $serviceId=null,?string $knownAt=null):array
    {
        PropertyInput::id($supplier,'supplier_id');SettlementEvidence::date($date);if($knownAt!==null)throw new ServiceReconciliationException('known_at_unsupported');if($unitId!==null)PropertyInput::id($unitId,'unit_id');if($serviceId!==null)PropertyInput::id($serviceId,'service_id');
        return(new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$date,$unitId,$serviceId):array{
            (new PaymentLocks($this->db))->acquire($supplier);$periods=$this->q('SELECT id,starts_on,ends_on FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? ORDER BY id FOR UPDATE',[$supplier,$date,$date])->fetchAll(PDO::FETCH_ASSOC);if(count($periods)!==1)throw new ServiceReconciliationException('period_coverage');$from=$periods[0]['starts_on'];
            $objects=$this->q("SELECT o.id,o.kind,o.dimension_value_id,v.type_id FROM property_objects o JOIN dimension_values v ON v.supplier_id=o.supplier_id AND v.id=o.dimension_value_id WHERE o.supplier_id=? AND o.kind IN ('unit','service') ORDER BY o.id FOR UPDATE",[$supplier])->fetchAll(PDO::FETCH_ASSOC);$ids=[];$dimensions=[];foreach($objects as $object){$ids[$object['kind']][(int)$object['id']]=true;$dimensions[$object['kind']][(int)$object['type_id']][(int)$object['dimension_value_id']]=(int)$object['id'];}if(($unitId!==null&&!isset($ids['unit'][$unitId]))||($serviceId!==null&&!isset($ids['service'][$serviceId])))throw new ServiceReconciliationException('foreign_scope');
            $accounts=array_column($this->q('SELECT account_code,account_type FROM chart_of_accounts WHERE supplier_id=?',[$supplier])->fetchAll(PDO::FETCH_ASSOC),'account_type','account_code');$source=(new ServiceReconciliationSources($this->db))->read($supplier);$expected=[];$expectedLines=[];$components=[];$incomplete=[];$legacy=[];
            foreach($source['accounts'] as $units)foreach($units as $codes)foreach($codes as $code=>$yes)$legacy[$code]=true;
            foreach($source['pending'] as $pending)if(self::selected($pending['unit_id'],$pending['service_id'],$unitId,$serviceId)&&(($pending['accounting_date']??$pending['period_to'])<=$date))$incomplete[]=$pending;
            foreach($source['rows'] as $row){if($row['accounting_date']>$date||!self::selected($row['unit_id'],$row['service_id'],$unitId,$serviceId))continue;$type=$accounts[$row['account_code']]??throw new ServiceReconciliationException('account_missing');if(in_array($type,['expense','revenue'],true)&&$row['accounting_date']<$from)continue;$amount=self::amount($row,$type);self::add($expected,$row['unit_id'],$row['service_id'],$row['account_code'],$amount);$expectedLines[$row['line_id']]=$row;$kind=$row['source_kind'];$components[$kind][$row['account_code']]=bcadd($components[$kind][$row['account_code']]??'0',$amount,0);}
            $sql="SELECT l.id,l.entry_id,l.side,l.amount,l.is_red_storno,l.currency_code,l.amount_foreign,l.fx_rate,c.account_code,c.account_type,e.entry_date,e.source_type,e.period_id FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id JOIN chart_of_accounts c ON c.supplier_id=l.supplier_id AND c.id=l.account_id WHERE l.supplier_id=? AND e.posted_at IS NOT NULL AND e.entry_date<=? AND (c.account_type NOT IN ('expense','revenue') OR (e.entry_date>=? AND EXISTS(SELECT 1 FROM journal_entry_line_dimensions d JOIN property_objects o ON o.supplier_id=d.supplier_id AND o.dimension_value_id=d.dimension_value_id AND o.kind='service' WHERE d.supplier_id=l.supplier_id AND d.line_id=l.id))) AND (c.account_code LIKE '315.2%' OR c.account_code LIKE '324.1%' OR c.account_code LIKE '388.2%' OR c.account_code LIKE '389.1%' OR c.account_type IN ('expense','revenue')";
            $params=[$supplier,$date,$from];if($legacy!==[]){$sql.=' OR c.account_code IN ('.implode(',',array_fill(0,count($legacy),'?')).')';array_push($params,...array_keys($legacy));}$sql.=') ORDER BY e.id,l.line_no,l.id';$rows=$this->q($sql,$params)->fetchAll(PDO::FETCH_ASSOC);$assignments=new DimensionAssignmentRepository($this->db);$lineIds=array_column($rows,'id');$assigned=$assignments->lineDimensions($supplier,$lineIds,true);$splits=$assignments->lineSplits($supplier,$lineIds,true);$carry=new ServiceCarryReadProof($this->db);$openingEntries=$this->openingBatchEntries($supplier);$carryMatches=[];$reserveRead=$this->reserveReader();$actual=[];$unregistered=[];$actualLines=[];$carriers=[];$reserveSteps=[];$reserveSnapshots=[];
            foreach($rows as $row){$pl=in_array($row['account_type'],['expense','revenue'],true);if($pl&&$row['entry_date']<$from)continue;$tuple=[];foreach(['unit','service'] as $kind){$matches=[];foreach($dimensions[$kind]??[] as $type=>$values){$value=$assigned[$row['id']][$type]??null;if($value!==null&&isset($values[$value]))$matches[]=$values[$value];}$tuple[$kind]=count($matches)===1?$matches[0]:null;}
                $unit=$tuple['unit'];$service=$tuple['service'];if($pl&&$service===null)continue;if($unit!==null&&$service!==null&&!self::selected($unit,$service,$unitId,$serviceId))continue;
                if($pl&&$this->verifiedReserveEntry($reserveRead,$supplier,$date,$row,$reserveSteps,$reserveSnapshots))continue;
                $nativeOpening=$row['source_type']==='opening'&&isset($openingEntries[(int)$row['entry_id']]);
                if(in_array($row['source_type'],['opening','closing'],true)&&!$nativeOpening&&($carryMatches[(int)$row['entry_id']]??=$carry->matches($supplier,(int)$row['entry_id']))){$carriers[(int)$row['entry_id']]=true;continue;}
                if($unit===null||$service===null||($splits[$row['id']]??[])!==[]){$unregistered[]=(int)$row['id'];$incomplete[]=['code'=>'ledger_scope','line_id'=>(int)$row['id']];continue;}
                if($pl&&!isset($source['policies'][$unit][$service]['service_profit_and_loss'])){$unregistered[]=(int)$row['id'];$incomplete[]=['code'=>'result_policy_unproven','line_id'=>(int)$row['id'],'unit_id'=>$unit,'service_id'=>$service];continue;}
                if($row['currency_code']!==null||$row['amount_foreign']!==null||$row['fx_rate']!==null){$incomplete[]=['code'=>'fx_unsupported','line_id'=>(int)$row['id']];}
                $row['amount_cents']=(string)JournalLineAmount::signedCents($row);self::add($actual,$unit,$service,$row['account_code'],self::amount($row,$row['account_type']));$actualLines[(int)$row['id']]=true;
                if(!isset($expectedLines[$row['id']])){$unregistered[]=(int)$row['id'];$incomplete[]=['code'=>'ledger_source_missing','line_id'=>(int)$row['id'],'unit_id'=>$unit,'service_id'=>$service];}
            }
            foreach($expectedLines as $id=>$row)if(!isset($actualLines[$id]))$incomplete[]=['code'=>'source_ledger_missing','line_id'=>$id,'unit_id'=>$row['unit_id'],'service_id'=>$row['service_id']];
            $difference=[];$totals=[];$ledgerTotals=[];$matches=$incomplete===[];foreach(array_unique([...array_keys($expected),...array_keys($actual)]) as $unit)foreach(array_unique([...array_keys($expected[$unit]??[]),...array_keys($actual[$unit]??[])]) as $service)foreach(array_unique([...array_keys($expected[$unit][$service]??[]),...array_keys($actual[$unit][$service]??[])]) as $account){$want=$expected[$unit][$service][$account]??'0';$have=$actual[$unit][$service][$account]??'0';$delta=bcsub($have,$want,0);$difference[$unit][$service][$account]=$delta;$totals[$account]=bcadd($totals[$account]??'0',$want,0);$ledgerTotals[$account]=bcadd($ledgerTotals[$account]??'0',$have,0);if($delta!=='0')$matches=false;}
            ksort($totals);ksort($ledgerTotals);sort($unregistered);return ['supplier_id'=>$supplier,'unit_id'=>$unitId,'service_id'=>$serviceId,'accounting_date'=>$date,'period_from'=>$from,'known_at'=>null,'knowledge_basis'=>'current_ledger','view'=>'before_closing','coverage'=>$incomplete===[]?'complete':'incomplete','matches'=>$matches,'totals'=>$totals,'ledger_totals'=>$ledgerTotals,'unit_service_balances'=>$expected,'ledger_unit_service_balances'=>$actual,'differences'=>$difference,'component_totals'=>$components,'incomplete'=>$incomplete,'unregistered_line_ids'=>array_values(array_unique($unregistered)),'verified_carry_entry_ids'=>array_keys($carriers),'source_line_ids'=>array_map('intval',array_keys($expectedLines))];
        });
    }
    /** Zápisy počátečních stavů SVJ jedním dotazem místo dotazu pro každý řádek deníku. */
    private function openingBatchEntries(int $supplier):array
    {
        $entries=[];
        foreach($this->q('SELECT journal_entry_id,reversal_entry_id FROM property_opening_batches WHERE supplier_id=?',[$supplier])->fetchAll(PDO::FETCH_ASSOC) as $batch){
            foreach(['journal_entry_id','reversal_entry_id'] as $key)if($batch[$key]!==null)$entries[(int)$batch[$key]]=true;
        }
        return $entries;
    }
    private function reserveReader():\MyInvoice\Service\Accounting\Closing\LegalClosing504Repository
    {
        $journal=new \MyInvoice\Repository\JournalEntryRepository($this->db,new \MyInvoice\Service\ActivityLogger($this->db));
        return new \MyInvoice\Service\Accounting\Closing\LegalClosing504Repository($this->db,$journal,true);
    }
    private function verifiedReserveEntry(\MyInvoice\Service\Accounting\Closing\LegalClosing504Repository $read,int $supplier,string $date,array $row,array &$steps,array &$snapshots):bool
    {
        $period=(int)$row['period_id'];$entry=(int)$row['entry_id'];
        if(!array_key_exists($period,$steps)){
            $step=$read->step($supplier,$period,'provisions');$records=[];
            foreach(array_merge($step['payload']['entries']??[],$step['payload']['reversed']??[])as$record){
                if(($record['legal_decision']['version']??null)!=='504-legal-v1'||!in_array($record['rule_key']??null,['reserve.repairs.create','reserve.repairs.release'],true))continue;
                $id=(int)($record['entry_id']??0);if($id<=0||isset($records[$id]))throw \MyInvoice\Service\Accounting\Closing\ClosingLegal504Proof::changed();$records[$id]=$record;
            }
            $steps[$period]=$records;
        }
        $record=$steps[$period][$entry]??null;if($record===null)return false;
        $creation=$record['rule_key']==='reserve.repairs.create';$source=$creation?$entry:(int)($record['legal_decision']['source_entry_id']??0);
        if($source<=0)throw \MyInvoice\Service\Accounting\Closing\ClosingLegal504Proof::changed();
        $snapshot=$snapshots[$source]??=$read->reserveSnapshot($supplier,$source,$date);
        if(!in_array($entry,array_map('intval',$snapshot['verified_entry_ids']),true)||($creation&&(int)$snapshot['origin']['entry_id']!==$entry))throw \MyInvoice\Service\Accounting\Closing\ClosingLegal504Proof::changed();
        return true;
    }
}
