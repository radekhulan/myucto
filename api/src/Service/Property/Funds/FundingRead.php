<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Funds;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\{PaymentJournalProof,PaymentLocks};
use MyInvoice\Service\Property\Opening\OpeningReadProof;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
use PDO;
final class FundingRead
{
    public function __construct(private readonly Connection $db){}
    private function q(string $sql,array $args=[]):\PDOStatement {$q=$this->db->pdo()->prepare($sql);$q->execute($args);return $q;}
    private static function add(array &$balance,int $unit,string $account,string $amount):void {$balance[$unit][$account]=bcadd($balance[$unit][$account]??'0',$amount,0);}
    public static function ledgerKnowledgeSql():string
    {
        return "COALESCE((SELECT fo.recorded_at FROM property_funding_operations fo WHERE fo.supplier_id=e.supplier_id AND fo.journal_entry_id=e.id),
            (SELECT ob.recorded_at FROM property_opening_batches ob WHERE ob.supplier_id=e.supplier_id AND ob.journal_entry_id=e.id),
            (SELECT nb.recorded_at FROM property_opening_batches ob JOIN property_opening_batches nb ON nb.supplier_id=ob.supplier_id AND nb.previous_id=ob.id WHERE ob.supplier_id=e.supplier_id AND ob.reversal_entry_id=e.id),
            (SELECT MAX(a.recorded_at) FROM unit_charges uc JOIN unit_charge_issue_audit a ON a.supplier_id=uc.supplier_id AND a.charge_id=uc.id WHERE uc.supplier_id=e.supplier_id AND ((uc.journal_entry_id=e.id AND a.action='posted') OR (uc.reversal_entry_id=e.id AND a.action='reversed'))),e.posted_at)";
    }
    public function at(int $supplier,?int $house,string $date,?string $knownAt=null,?int $unitId=null):array
    {return(new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$house,$date,$knownAt,$unitId){
        if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$date,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))PropertyInput::invalid('accounting_date');
        (new PaymentLocks($this->db))->acquire($supplier);$known=$knownAt===null?null:PropertyInstant::sql($knownAt);
        $objects=array_column((new PropertyReadRepository($this->db))->objects($supplier,'unit',true),null,'id');
        $units=array_filter($objects,fn($unit)=>($house===null||$unit['parent_id']===$house)&&($unitId===null||$unit['id']===$unitId));
        if(($house!==null||$unitId!==null)&&$units===[])FundingException::reject('registry_empty');
        $ids=array_keys($units);$marks=implode(',',array_fill(0,count($ids),'?'));$base=[];$sources=[];$ownedEntries=[];$components=['opening'=>[],'issued'=>[],'draw'=>[],'income'=>[],'transfer'=>[],'payout'=>[],'reverse'=>[]];
        $opening=new OpeningReadProof($this->db);$issued=new IssuanceReadRepository($this->db);$journal=new PaymentJournalProof($this->db);
        $existsAt=function(int $entry)use($supplier,$date,$known,$journal):bool{$ledger=$journal->current($supplier,$entry);if($ledger['entry']['entry_date']>$date)return false;if($known===null)return true;return (string)$this->q('SELECT '.self::ledgerKnowledgeSql().' FROM journal_entries e WHERE e.supplier_id=? AND e.id=?',[$supplier,$entry])->fetchColumn()<=$known;};
        $rows=$ids===[]?[]:$this->q("SELECT c.id,c.item_id FROM property_opening_components c JOIN property_opening_items i ON i.supplier_id=c.supplier_id AND i.id=c.item_id WHERE c.supplier_id=? AND i.unit_id IN ($marks) AND c.role IN ('long_term_advance','admin_advance') ORDER BY c.id FOR UPDATE",[$supplier,...$ids])->fetchAll(PDO::FETCH_ASSOC);
        $openingItems=$opening->items($supplier,array_values(array_unique(array_map('intval',array_column($rows,'item_id')))),true);
        foreach($rows as $row){
            $item=$openingItems[(int)$row['item_id']];$batch=$item['batch'];$ownedEntries[$batch['journal_entry_id']]=true;if($batch['reversal_entry_id']!==null)$ownedEntries[$batch['reversal_entry_id']]=true;
            if($batch['accounting_date']>$date||($known!==null&&PropertyInstant::sql($batch['recorded_at'])>$known)||($batch['reversal_entry_id']!==null&&$existsAt($batch['reversal_entry_id'])))continue;
            $parts=array_column($item['components'],null,'id');$part=$parts[(int)$row['id']];$unit=$item['snapshot']['unit_id'];$account=$part['line']['account_code'];
            self::add($base,$unit,$account,$part['amount_cents']);self::add($components['opening'],$unit,$account,$part['amount_cents']);
            $sources[]=['source'=>['kind'=>'opening','id'=>(int)$row['id']],'unit_id'=>$unit,'account_code'=>$account,'amount_cents'=>$part['amount_cents'],'purpose_ref'=>$item['snapshot']['advance_state']['purpose_ref']];
        }
        foreach($issued->charges($supplier,true) as $charge){
            if(!isset($units[$charge['unit_id']]))continue;
            if($charge['journal_entry_id']!==null){$proof=new \MyInvoice\Service\Property\Charges\Issuance\IssuanceJournalProof($this->db);$proof->assertEntry($supplier,$charge['journal_entry_id'],$charge);$ownedEntries[$charge['journal_entry_id']]=true;if($charge['reversal_entry_id']!==null){$proof->assertEntry($supplier,$charge['reversal_entry_id'],$charge,true);$ownedEntries[$charge['reversal_entry_id']]=true;}}
            if(!isset($units[$charge['unit_id']])||$charge['journal_entry_id']===null||!$existsAt($charge['journal_entry_id'])||($charge['reversal_entry_id']!==null&&$existsAt($charge['reversal_entry_id'])))continue;
            foreach($this->q('SELECT id,line_json FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? ORDER BY id FOR UPDATE',[$supplier,$charge['id']])->fetchAll(PDO::FETCH_ASSOC) as $row){
                $line=json_decode($row['line_json'],true,flags:JSON_THROW_ON_ERROR);if(!in_array($line['component']['kind'],['long_term_advance','admin_advance'],true))continue;
                $unit=$charge['unit_id'];$account=$line['account']['counter_account'];self::add($base,$unit,$account,$line['amount_cents']);self::add($components['issued'],$unit,$account,$line['amount_cents']);
                if(bccomp($line['amount_cents'],'0',0)>0)$sources[]=['source'=>['kind'=>'issued','id'=>(int)$row['id']],'unit_id'=>$unit,'account_code'=>$account,'amount_cents'=>$line['amount_cents'],'purpose_ref'=>$charge['snapshot']['title']];
            }
        }
        $owner=new FundingRepository($this->db);$income=[];
        $operations=$this->q('SELECT o.id,o.house_id,o.accounting_date,o.recorded_at,r.journal_entry_id AS reversal_entry_id FROM property_funding_operations o LEFT JOIN property_funding_operations r ON r.supplier_id=o.supplier_id AND r.reversal_of=o.id AND r.receipt_json IS NOT NULL WHERE o.supplier_id=? AND o.receipt_json IS NOT NULL'.($house===null?'':' AND o.house_id=?').' ORDER BY o.id FOR UPDATE',$house===null?[$supplier]:[$supplier,$house])->fetchAll(PDO::FETCH_ASSOC);
        foreach($operations as $row){
            $op=$owner->operation($supplier,(int)$row['id']);$ownedEntries[$op['journal_entry_id']]=true;if($row['reversal_entry_id']!==null)$ownedEntries[(int)$row['reversal_entry_id']]=true;if($row['accounting_date']>$date||($known!==null&&$row['recorded_at']>$known))continue;
            foreach($op['receipt']['effects'] as $effect){
                $unit=$effect['unit_id'];if(!isset($units[$unit]))continue;$account=$effect['account_code'];self::add($base,$unit,$account,$effect['amount_cents']);self::add($components[$op['kind']],$unit,$account,$effect['amount_cents']);
                if($effect['source_kind']!==null){foreach($sources as &$candidate)if($candidate['source']['kind']===$effect['source_kind']&&$candidate['source']['id']===$effect['source_id'])$candidate['amount_cents']=bcadd($candidate['amount_cents'],$effect['amount_cents'],0);unset($candidate);}
                if(($row['reversal_entry_id']===null||!$existsAt((int)$row['reversal_entry_id']))&&bccomp($effect['amount_cents'],'0',0)>0){
                    if($account==='379.920'&&FundingSourceProof::isIncomeOperation($op))$income[]=$effect+['operation_id'=>$op['id'],'accounting_date'=>$op['accounting_date']];
                    elseif($op['kind']==='transfer')$sources[]=['source'=>['kind'=>'transfer','id'=>$effect['id']],'unit_id'=>$unit,'account_code'=>$account,'amount_cents'=>$effect['amount_cents'],'purpose_ref'=>$op['request']['purpose_ref']];
                }
            }
        }
        foreach($income as &$lot){$used=$this->q("SELECT COALESCE(SUM(e.amount_cents),0) FROM property_funding_effects e JOIN property_funding_operations o ON o.supplier_id=e.supplier_id AND o.id=e.operation_id WHERE e.supplier_id=? AND e.source_kind='income' AND e.source_id=? AND o.accounting_date<=? AND o.receipt_json IS NOT NULL".($known===null?'':' AND o.recorded_at<=?'),$known===null?[$supplier,$lot['id'],$date]:[$supplier,$lot['id'],$date,$known])->fetchColumn();$lot['remaining_cents']=bcadd($lot['amount_cents'],(string)$used,0);}unset($lot);
        $ledger=[];$rows=$this->q("SELECT l.id,l.entry_id,l.side,l.amount,l.is_red_storno,c.account_code FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id JOIN chart_of_accounts c ON c.supplier_id=l.supplier_id AND c.id=l.account_id WHERE l.supplier_id=? AND e.entry_date<=? AND e.posted_at IS NOT NULL".($known===null?'':' AND '.self::ledgerKnowledgeSql().'<=?')." AND e.source_type<>'closing' AND (e.source_type<>'opening' OR EXISTS(SELECT 1 FROM property_opening_batches b WHERE b.supplier_id=e.supplier_id AND (b.journal_entry_id=e.id OR b.reversal_entry_id=e.id))) AND (LEFT(c.account_code,3)='955' OR c.account_code IN ('324.200','379.920')) ORDER BY e.id,l.id",$known===null?[$supplier,$date]:[$supplier,$date,$known])->fetchAll(PDO::FETCH_ASSOC);
        $dimensions=new \MyInvoice\Repository\DimensionAssignmentRepository($this->db);$assigned=$dimensions->lineDimensions($supplier,array_column($rows,'id'),false);$splits=$dimensions->lineSplits($supplier,array_column($rows,'id'),false);$values=array_column($objects,'id','dimension_value_id');$unregistered=[];
        foreach($rows as $row){
            $matched=[];foreach($assigned[$row['id']]??[] as $value)if(isset($values[$value]))$matched[]=$values[$value];
            if(($splits[$row['id']]??[])!==[]||count($matched)!==1){$unregistered[(int)$row['id']]=true;continue;}
            $unit=$matched[0];if(!isset($units[$unit]))continue;if(!isset($ownedEntries[$row['entry_id']]))$unregistered[(int)$row['id']]=true;$amount=(string)\MyInvoice\Service\Accounting\JournalLineAmount::signedCents($row);self::add($ledger,$unit,$row['account_code'],$row['side']==='credit'?$amount:bcsub('0',$amount,0));
        }
        $difference=[];$totals=[];$ledgerTotals=[];$componentTotals=[];
        foreach($units as $unit=>$object)foreach(array_unique([...array_keys($base[$unit]??[]),...array_keys($ledger[$unit]??[])]) as $account){$expected=$base[$unit][$account]??'0';$actual=$ledger[$unit][$account]??'0';$difference[$unit][$account]=bcsub($actual,$expected,0);$totals[$account]=bcadd($totals[$account]??'0',$expected,0);$ledgerTotals[$account]=bcadd($ledgerTotals[$account]??'0',$actual,0);}
        foreach($components as $kind=>$balances)foreach($balances as $accounts)foreach($accounts as $account=>$amount)$componentTotals[$kind][$account]=bcadd($componentTotals[$kind][$account]??'0',$amount,0);
        $matches=$unregistered===[];foreach($difference as $accounts)foreach($accounts as $amount)if($amount!=='0')$matches=false;
        return ['house_id'=>$house,'unit_id'=>$unitId,'accounting_date'=>$date,'known_at'=>$knownAt,'revision'=>$house===null?null:$owner->list($supplier,$house)['revision'],'unit_balances'=>$base,'totals'=>$totals,'ledger_totals'=>$ledgerTotals,'component_totals'=>$componentTotals,'differences'=>$difference,'unregistered_line_ids'=>array_keys($unregistered),'matches'=>$matches,'sources'=>array_values(array_filter($sources,fn($entry)=>bccomp($entry['amount_cents'],'0',0)>0)),'income_lots'=>$income,'knowledge_basis'=>$known===null?'current_ledger':'posted_and_recorded_at_cutoff','coverage'=>'typed_opening_issued_and_funding_events'];
    });}
}
