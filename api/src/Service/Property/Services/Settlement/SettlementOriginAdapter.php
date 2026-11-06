<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Settlement;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Repository\Property\Payments\DebtRepository;
use MyInvoice\Service\Property\Charges\Issuance\{IssuanceLocks,IssuanceJournalProof};
use MyInvoice\Service\Property\Opening\OpeningReadProof;
use MyInvoice\Service\Property\Payments\{PaymentLocks,PaymentJournalProof};
use MyInvoice\Service\Property\PropertyInput;
use PDO;
final class SettlementOriginAdapter
{
    public function __construct(private readonly Connection $db, private readonly \MyInvoice\Repository\Property\Payments\PaymentRepository $payments) {}
    /**
     * Aktuální důkaz původu se skládá z mnoha zamčených tabulek (předpisy, platby, deník,
     * počáteční stavy, životní cyklus služby); v jedné transakci se počítá znovu jen po
     * zápisu nebo hranici transakce ({@see \MyInvoice\Infrastructure\Database\CurrentReadMemo}).
     */
    public function current(int $supplier,int $unit,int $service,string $date,array $selectors):array
    {
        return \MyInvoice\Infrastructure\Database\CurrentReadMemo::remember($this->db,'settlement-origin|'.md5(serialize([$supplier,$unit,$service,$date,$selectors])),fn():array=>$this->currentFresh($supplier,$unit,$service,$date,$selectors));
    }
    private function currentFresh(int $supplier,int $unit,int $service,string $date,array $selectors):array
    {
        try{return $this->currentLocked($supplier,$unit,$service,$date,$selectors);}
        catch(\Throwable $failure){\MyInvoice\Infrastructure\Database\TransactionFailure::rollbackIfAborted($this->db->pdo(),$failure);throw $failure;}
    }
    private function currentLocked(int $supplier,int $unit,int $service,string $date,array $selectors):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Settlement current proof requires an owner transaction.');
        foreach([$supplier,$unit,$service] as $id)PropertyInput::id($id,'scope');SettlementEvidence::date($date);
        (new PaymentLocks($this->db))->acquire($supplier);
        (new \MyInvoice\Service\Property\Payments\PaymentPostingDate($this->db))->assertOpen($supplier,$date);
        $period=$this->one('SELECT id,status FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? FOR UPDATE',[$supplier,$date,$date]);
        if(!$period||$period['status']!=='open')SettlementEvidence::reject('period_not_open');
        $origins=[];$allocations=[];$evidence=[];$costs=[];$hashInputs=[];
        foreach($selectors as $selector){
            if(!is_array($selector))SettlementEvidence::reject('selector');
            if(($selector['kind']??null)==='s3_line'){$result=$this->s3($supplier,$unit,$service,$date,$selector);}
            elseif(($selector['kind']??null)==='opening'){$result=$this->opening($supplier,$unit,$service,$date,$selector);}
            else SettlementEvidence::reject('selector');
            $origin=$result['origin'];$key=SettlementEvidence::origin($origin,$supplier,$unit,$service);
            if(isset($origins[$key]))SettlementEvidence::reject('duplicate_origin');$origins[$key]=$origin;
            foreach($result['allocations'] as $allocation){if(isset($allocations[$allocation['id']]))SettlementEvidence::reject('duplicate_allocation');$allocations[$allocation['id']]=$allocation;}
            $evidence[$key]=$result['evidence'];if(array_key_exists('cost_sources',$selector)){$composite=(new SettlementCostProof($this->db))->current($supplier,$unit,$service,$date,$origin['source_period'],$selector['cost_sources'],$this,$selector['kind']==='opening');$costs[$key]=$composite['cost'];array_push($hashInputs,...$composite['hash_inputs']);continue;}if(isset($selector['cost_line_id']))$result['cost']=$this->cost($supplier,$unit,$service,PropertyInput::id($selector['cost_line_id'],'cost_line_id'),$date,$origin['source_period']);if(isset($result['cost'])){$hashInputs[]=$result['cost']['hash_input'];unset($result['cost']['hash_input']);$costs[$key]=$result['cost'];}
        }
        if($origins===[])SettlementEvidence::reject('origin_missing');ksort($origins);ksort($allocations);ksort($evidence);ksort($costs);
        $facts=['supplier_id'=>$supplier,'unit_id'=>$unit,'service_id'=>$service,'accounting_date'=>$date,'period_id'=>(int)$period['id'],'origins'=>array_values($origins),'allocations'=>array_values($allocations),'costs'=>$costs,'evidence'=>$evidence,'hash_inputs'=>$hashInputs];
        return $facts+['current_hash'=>PropertyInput::hash($facts)];
    }
    private function s3(int $supplier,int $unit,int $service,string $date,array $selector):array
    {
        SettlementEvidence::fields($selector,['kind','id',...(array_key_exists('cost_line_id',$selector)?['cost_line_id']:[]),...(array_key_exists('cost_sources',$selector)?['cost_sources']:[])]);if(array_key_exists('cost_sources',$selector)&&array_key_exists('cost_line_id',$selector))SettlementEvidence::reject('composite_cost_selector');$id=PropertyInput::id($selector['id'],'line_id');
        $row=$this->one('SELECT * FROM unit_charge_lines WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);if(!$row)SettlementEvidence::reject('source_not_found');
        $charge=(new IssuanceReadRepository($this->db))->find($supplier,(int)$row['charge_id'],true);
        if($charge['unit_id']!==$unit||(int)$row['service_id']!==$service||$charge['status']!=='posted'||$charge['accounting_date']>$date)SettlementEvidence::reject('origin_scope');
        $fact=json_decode($row['line_json'],true,flags:JSON_THROW_ON_ERROR);
        if($fact['component']['kind']!=='service_advance'||$fact['amount_cents']!==$row['amount_cents']||bccomp($row['amount_cents'],'0',0)<=0)SettlementEvidence::reject('source_role');
        (new IssuanceJournalProof($this->db))->assertEntry($supplier,$charge['journal_entry_id'],$charge);
        $rows=$this->all('SELECT id FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? ORDER BY id FOR UPDATE',[$supplier,$charge['id']]);$position=array_search($id,array_map('intval',array_column($rows,'id')),true);
        $ledger=(new PaymentJournalProof($this->db))->current($supplier,$charge['journal_entry_id']);
        $receivable=$ledger['lines'][$position*2];$advance=$ledger['lines'][$position*2+1];
        $this->role($supplier,$receivable,'asset','315','debit');$this->role($supplier,$advance,'liability','324','credit');
        $bound=$this->one('SELECT debt_id FROM unit_payment_debt_lines WHERE supplier_id=? AND charge_line_id=? FOR UPDATE',[$supplier,$id]);if(!$bound)SettlementEvidence::reject('documented_debt_required');
        $debt=(new DebtRepository($this->db))->currentMany($supplier,[(int)$bound['debt_id']])[0];if(!$debt['active']||array_intersect_key($debt['reductions'],array_flip(array_column($debt['snapshot']['lines'],'id')))!==[])SettlementEvidence::reject('source_already_corrected');
        $origin=['kind'=>'s3_line','source_period'=>['from'=>$charge['period_from'],'to'=>$charge['period_to']],'id'=>$id,'debt_id'=>$debt['id'],'supplier_id'=>$supplier,'unit_id'=>$unit,'service_id'=>$service,'prescribed_cents'=>$row['amount_cents'],'carried_paid_cents'=>'0','receivable_cents'=>$row['amount_cents'],'opening_advance_component_id'=>null,'opening_state_hash'=>null,'receivable_line'=>$this->line($supplier,$receivable),'advance_line'=>$this->line($supplier,$advance)];
        return ['origin'=>$origin,'allocations'=>$this->allocations($supplier,$debt['id'],'charge_line_id',$id,'s3_line:'.$id,$date),'evidence'=>['charge_id'=>$charge['id'],'snapshot_hash'=>$charge['snapshot_hash'],'row_version'=>$charge['row_version'],'ledger_hash'=>PropertyInput::hash($ledger),'debt'=>$debt]];
    }
    private function opening(int $supplier,int $unit,int $service,string $date,array $selector):array
    {
        SettlementEvidence::fields($selector,['kind','advance_item_id','service_item_id',...(array_key_exists('cost_line_id',$selector)?['cost_line_id']:[]),...(array_key_exists('cost_sources',$selector)?['cost_sources']:[])]);if(array_key_exists('cost_sources',$selector)&&array_key_exists('cost_line_id',$selector))SettlementEvidence::reject('composite_cost_selector');
        $proof=new OpeningReadProof($this->db);$advance=$proof->item($supplier,PropertyInput::id($selector['advance_item_id'],'advance_item_id'),true);$serviceItem=$proof->item($supplier,PropertyInput::id($selector['service_item_id'],'service_item_id'),true);
        foreach([$advance,$serviceItem] as $item)if(!$item['active']||$item['snapshot']['unit_id']!==$unit||$item['snapshot']['service_id']!==$service||$item['batch']['accounting_date']>$date)SettlementEvidence::reject('origin_scope');
        if($advance['batch']['id']!==$serviceItem['batch']['id']||$advance['snapshot']['kind']!=='advance'||$serviceItem['snapshot']['kind']!=='service_carry')SettlementEvidence::reject('source_role');
        $a=$advance['snapshot']['advance_state'];$s=$serviceItem['snapshot']['service_state'];
        if((!isset($selector['cost_line_id'])&&$s['profile']!=='service_balance_sheet')||$a['used_cents']!=='0'||$s['settled_cents']!=='0'||$s['cancelled_unpaid_cents']!=='0'||$a['prescribed_cents']!==$s['prescribed_cents']||$a['paid_cents']!==$s['paid_cents']||PropertyInput::hash($a['unpaid_cases'])!==PropertyInput::hash($s['unpaid_cases']))SettlementEvidence::reject('opening_state');
        $advancePart=$this->part($advance,'service_advance');
        if(isset($selector['cost_line_id'])){$nativeCost=$this->cost($supplier,$unit,$service,PropertyInput::id($selector['cost_line_id'],'cost_line_id'),$date,['from'=>$serviceItem['snapshot']['period_from'],'to'=>$serviceItem['snapshot']['period_to']],$serviceItem['id']);$lifecycle=(new \MyInvoice\Repository\Property\Services\Settlement\ServiceLifecycleRepository($this->db))->assertCostReady($supplier,$unit,$service,$selector['cost_line_id'],$date,$serviceItem['id']);if($lifecycle===null||$lifecycle['case']['policy']!==$s['profile'])SettlementEvidence::reject('lifecycle_opening_scope');$costPart=['line'=>$nativeCost['hash_input'],'amount_cents'=>$nativeCost['amount_cents']];}
        else $costPart=$this->part($serviceItem,'service_cost');
        $this->role($supplier,$advancePart['line'],'liability','324','credit');$this->role($supplier,$costPart['line'],'asset','315','debit');
        if(!str_starts_with(str_replace('.','',$costPart['line']['account_code']),'3152'))SettlementEvidence::reject('source_role');
        if(!isset($selector['cost_line_id']))$this->sameDimensions($advancePart['line'],$costPart['line']);
        $unpaid=bcsub($a['prescribed_cents'],$a['paid_cents'],0);$links=$this->all('SELECT debt_item_id,amount_cents FROM property_opening_case_links WHERE supplier_id=? AND item_id=? ORDER BY debt_item_id FOR UPDATE',[$supplier,$advance['id']]);
        $debt=null;$receivable=null;$part=null;$allocations=[];
        if($unpaid!=='0'){
            if(count($links)!==1||$links[0]['amount_cents']!==$unpaid)SettlementEvidence::reject('opening_partition_required');
            $item=$proof->item($supplier,(int)$links[0]['debt_item_id'],true);
            if(!$item['active']||$item['batch']['id']!==$advance['batch']['id']||$item['snapshot']['unit_id']!==$unit||$item['snapshot']['service_id']!==$service)SettlementEvidence::reject('origin_scope');
            $part=$this->part($item,'principal');if($part['amount_cents']!==$unpaid)SettlementEvidence::reject('opening_partition_required');$receivable=$part['line'];$this->role($supplier,$receivable,'asset','315','debit');$this->sameDimensions($advancePart['line'],$receivable);
            $bound=$this->one('SELECT id FROM unit_payment_debts WHERE supplier_id=? AND opening_item_id=? FOR UPDATE',[$supplier,$item['id']]);if(!$bound)SettlementEvidence::reject('source_not_found');$debt=(new DebtRepository($this->db))->currentMany($supplier,[(int)$bound['id']])[0];if(!$debt['active'])SettlementEvidence::reject('origin_scope');
            $allocations=$this->allocations($supplier,$debt['id'],'opening_component_id',$part['id'],'opening_component:'.$part['id'],$date);
        }elseif($links!==[])SettlementEvidence::reject('opening_state');
        $state=['advance_item_id'=>$advance['id'],'advance'=>$a,'service_item_id'=>$serviceItem['id'],'service'=>$s,'links'=>$links];
        $origin=['kind'=>'opening_component','source_period'=>['from'=>$serviceItem['snapshot']['period_from'],'to'=>$serviceItem['snapshot']['period_to']],'id'=>$part['id']??$advancePart['id'],'debt_id'=>$debt['id']??null,'supplier_id'=>$supplier,'unit_id'=>$unit,'service_id'=>$service,'prescribed_cents'=>$a['prescribed_cents'],'carried_paid_cents'=>$a['paid_cents'],'receivable_cents'=>$unpaid,'opening_advance_component_id'=>$advancePart['id'],'opening_state_hash'=>PropertyInput::hash($state),'receivable_line'=>$receivable===null?null:$this->line($supplier,$receivable),'advance_line'=>$this->line($supplier,$advancePart['line'])];
        $cost=['source_id'=>$costPart['line']['id'],'source_hash'=>PropertyInput::hash($costPart['line']),'amount_cents'=>$costPart['amount_cents'],'line'=>$this->line($supplier,$costPart['line']),'hash_input'=>$costPart['line']];
        return ['origin'=>$origin,'allocations'=>$allocations,'cost'=>$cost,'evidence'=>['advance_hash'=>$advance['snapshot_hash'],'service_hash'=>$serviceItem['snapshot_hash'],'state'=>$state,'debt'=>$debt]];
    }
    private function cost(int $supplier,int $unit,int $service,int $id,string $date,?array $sourcePeriod=null,?int $openingItem=null):array
    {
        $cost=$this->nativeCostSource($supplier,$unit,$service,$id,$date);(new \MyInvoice\Repository\Property\Services\Settlement\ServiceLifecycleRepository($this->db))->assertCostReady($supplier,$unit,$service,$id,$date,$openingItem,$sourcePeriod);return $cost;
    }
    public function nativeCostSource(int $supplier,int $unit,int $service,int $id,string $date):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Native service cost requires the owner transaction.');foreach([$supplier,$unit,$service,$id] as $value)PropertyInput::id($value,'cost_scope');SettlementEvidence::date($date);
        $row=$this->one('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);if(!$row)SettlementEvidence::reject('cost_source_not_found');
        $ledger=(new PaymentJournalProof($this->db))->current($supplier,(int)$row['entry_id']);if($ledger['entry']['reversed_by']!==null||$ledger['entry']['entry_date']>$date)SettlementEvidence::reject('cost_source_changed');
        $line=array_values(array_filter($ledger['lines'],fn($line)=>$line['id']===$id))[0];$this->role($supplier,$line,'asset','315','debit');if(!str_starts_with(str_replace('.','',$line['account_code']),'3152'))SettlementEvidence::reject('source_role');
        foreach([$unit,$service] as $object){$value=$this->one('SELECT d.type_id,d.id FROM property_objects o JOIN dimension_values d ON d.supplier_id=o.supplier_id AND d.id=o.dimension_value_id WHERE o.supplier_id=? AND o.id=? FOR UPDATE',[$supplier,$object]);if(!$value||($line['dimensions'][(int)$value['type_id']]??null)!==(int)$value['id'])SettlementEvidence::reject('source_tuple');}
        return ['source_id'=>$id,'source_hash'=>PropertyInput::hash($line),'amount_cents'=>bcmul($line['amount'],'100',0),'line'=>$this->line($supplier,$line),'hash_input'=>$line];
    }
    private function allocations(int $supplier,int $debt,string $column,int $id,string $key,string $date):array
    {
        $rows=$this->all('SELECT a.*,o.status,o.row_version,o.accounting_date,o.snapshot_hash FROM unit_payment_allocations a JOIN unit_payment_operations o ON o.supplier_id=a.supplier_id AND o.id=a.operation_id WHERE a.supplier_id=? AND a.debt_id=? AND a.'.$column.'=? ORDER BY a.id FOR UPDATE',[$supplier,$debt,$id]);$out=[];
        foreach($rows as $row){$operation=$this->payments->operation($supplier,(int)$row['operation_id']);if($operation['snapshot_hash']!==$row['snapshot_hash'])SettlementEvidence::reject('allocation_changed');if($operation['kind']!=='allocation')SettlementEvidence::reject('nonphysical_allocation_review');$source=$operation['snapshot']['source']['receipt'];$currentSource=(new \MyInvoice\Service\Property\Payments\PaymentSourceProof($this->db))->current($supplier,$source['kind'],$source['id']);if($row['accounting_date']>$date)SettlementEvidence::reject('payment_after_application');
            $sourceInput=['allocation_id'=>(int)$row['id'],'operation_hash'=>$row['snapshot_hash'],'amount_cents'=>$row['amount_cents'],'liability_group'=>$row['liability_group']];
            $sourceHead=['id'=>$currentSource['id'],'version'=>$currentSource['version'],'receipt_hash'=>PropertyInput::hash($currentSource['receipt']),'consumed_cents'=>$currentSource['consumed_cents'],'available_cents'=>$currentSource['available_cents'],'revision_id'=>$currentSource['revision_id']??null,'cell_consumed'=>$currentSource['cell_consumed']??[]];
            $out[]=['source_input'=>$sourceInput,'current_source_input'=>$sourceHead,'id'=>(int)$row['id'],'origin_key'=>$key,'source_hash'=>PropertyInput::hash($sourceInput),'amount_cents'=>$row['amount_cents'],'operation_id'=>(int)$row['operation_id'],'current_source_hash'=>PropertyInput::hash($sourceHead),'operation_version'=>(int)$row['row_version'],'active'=>$row['status']==='posted','accounting_date'=>$row['accounting_date']];
        }return $out;
    }
    private function part(array $item,string $role):array {$parts=array_values(array_filter($item['components'],fn($p)=>$p['role']===$role));if(count($parts)!==1)SettlementEvidence::reject('opening_partition_required');return $parts[0];}
    private function role(int $supplier,array $line,string $type,string $prefix,string $side):void
    {
        $account=$this->one('SELECT id,account_type FROM chart_of_accounts WHERE supplier_id=? AND account_code=? FOR UPDATE',[$supplier,$line['account_code']]);
        if(!$account||$account['account_type']!==$type||!str_starts_with($line['account_code'],$prefix)||$line['side']!==$side||$line['is_red_storno']||bccomp($line['amount'],'0',2)<=0)SettlementEvidence::reject('source_role');
        foreach(['currency_code','amount_foreign','fx_rate'] as $key)if($line[$key]!==null)SettlementEvidence::reject('fx_unsupported');
    }
    private function line(int $supplier,array $line):array
    {
        $account=$this->one('SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code=? FOR UPDATE',[$supplier,$line['account_code']]);if(!$account)SettlementEvidence::reject('source_role');
        return ['account_id'=>(int)$account['id'],'account_code'=>$line['account_code'],'dimensions'=>$line['dimensions'],'dimension_splits'=>$line['dimension_splits'],'dimension_origins'=>$line['dimension_origins'],'dimension_split_origins'=>$line['dimension_split_origins'],'cost_center'=>$line['cost_center']??null,'project_id'=>$line['project_id']??null,'currency_code'=>$line['currency_code'],'amount_foreign'=>$line['amount_foreign'],'fx_rate'=>$line['fx_rate']];
    }
    private function sameDimensions(array $a,array $b):void {foreach(['dimensions','dimension_splits','cost_center','project_id'] as $key)if(PropertyInput::hash([$a[$key]??null])!==PropertyInput::hash([$b[$key]??null]))SettlementEvidence::reject('source_tuple');}
    private function one(string $sql,array $args):?array{return $this->all($sql,$args)[0]??null;}
    private function all(string $sql,array $args):array{$q=$this->db->pdo()->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
}
