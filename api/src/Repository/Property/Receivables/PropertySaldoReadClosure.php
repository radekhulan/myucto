<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Receivables;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Receivables\ReceivableException;
use PDO;
final class PropertySaldoReadClosure
{
    public function __construct(private readonly Connection $db){}
    public function units(int $supplier,array $unitIds,array $debtIds=[]):PropertySaldoReadSelection
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Selected financial history requires owner transaction.');
        foreach($unitIds as $id)PropertyInput::id($id,'unit_id');

        $queried=[];$retained=[];$familyQueried=[];
        $discover=function(string $table,array $columns,array $json=[],string $order='id')use($supplier,&$queried,&$retained):array{
            $deltaColumns=[];$deltaJson=[];
            foreach($columns as $column=>$ids){
                $deltaColumns[$column]=array_diff_key($ids,$queried[$table]['columns'][$column]??[]);
                $queried[$table]['columns'][$column]=$ids;
            }
            foreach($json as $column=>$paths)foreach($paths as $path=>$ids){
                $deltaJson[$column][$path]=array_diff_key($ids,$queried[$table]['json'][$column][$path]??[]);
                $queried[$table]['json'][$column][$path]=$ids;
            }
            $fresh=[];
            foreach($this->select($supplier,$table,$deltaColumns,$deltaJson,$order,$order==='id'?($retained[$table]??[]):[]) as $row){
                $key=implode(':',array_map(fn($column)=>$row[$column],explode(',',$order)));
                if(isset($retained[$table][$key]))continue;
                $retained[$table][$key]=$row;$fresh[]=$row;
            }
            $retained[$table]??=[];
            return $fresh;
        };
        $sets=['debts'=>array_fill_keys($debtIds,true),'charges'=>[],'lines'=>[],'allocations'=>[],'operations'=>[],'corrections'=>[],'credits'=>[],'reservations'=>[],'sources'=>[],'source_entries'=>[],'service_documents'=>[],'service_applications'=>[],'service_effects'=>[],'service_requests'=>[],'opening_components'=>[],'opening_items'=>[],'opening_batches'=>[],'journal_lines'=>[],'service_charges'=>[]];$rows=[];
        $seed=$this->select($supplier,'unit_charges',['unit_id'=>array_fill_keys($unitIds,true),'read_unit_id'=>array_fill_keys($unitIds,true)]);foreach($seed as $r)$sets['charges'][(int)$r['id']]=true;
        $seed=$this->select($supplier,'unit_credit_lots',['unit_id'=>array_fill_keys($unitIds,true),'read_unit_id'=>array_fill_keys($unitIds,true)]);foreach($seed as $r)$sets['credits'][(int)$r['id']]=true;
        $params=[$supplier];$condition=$this->condition('i.unit_id',array_fill_keys($unitIds,true),$params);$seed=$this->query('SELECT d.id FROM unit_payment_debts d JOIN property_opening_items i ON i.id=d.opening_item_id AND i.supplier_id=d.supplier_id WHERE d.supplier_id=? AND '.$condition.' ORDER BY d.id',$params);foreach($seed as $r)$sets['debts'][(int)$r['id']]=true;
        $params=[$supplier];$condition=$this->condition('d.unit_id',array_fill_keys($unitIds,true),$params);$seed=$this->query('SELECT DISTINCT a.document_id id FROM property_service_settlement_applications a JOIN property_service_settlements d ON d.supplier_id=a.supplier_id AND d.id=a.document_id WHERE a.supplier_id=? AND '.$condition.' ORDER BY a.document_id',$params);foreach($seed as $r)$sets['service_documents'][(int)$r['id']]=true;
        do{
            $before=array_map('count',$sets);$unused=[];
            $documents=$discover('property_service_settlements',['id'=>$sets['service_documents']]);
            foreach($documents as $r){$sets['service_documents'][(int)$r['id']]=true;$facts=json_decode($r['document_json'],true,flags:JSON_THROW_ON_ERROR);foreach(\MyInvoice\Service\Property\Services\Settlement\SettlementEvidence::costSources($facts['cost']) as $part){$sets['journal_lines'][$part['source_id']]=true;if(($part['provenance']['kind']??null)==='document')$sets['service_documents'][$part['provenance']['id']]=true;}$receipt=json_decode($r['origin_receipt_json'],true,flags:JSON_THROW_ON_ERROR);foreach($receipt['current']['evidence'] as $evidence)if(isset($evidence['charge_id']))$sets['service_charges'][(int)$evidence['charge_id']]=true;foreach($receipt['request']['selectors'] as $selector)if($selector['kind']==='opening'){$sets['opening_items'][(int)$selector['advance_item_id']]=true;$sets['opening_items'][(int)$selector['service_item_id']]=true;}}
            $origins=$discover('property_service_settlement_origins',['document_id'=>$sets['service_documents']],[],'document_id,ordinal');
            foreach($origins as $r){$sets['service_documents'][(int)$r['document_id']]=true;foreach(['debt_id'=>'debts','charge_line_id'=>'lines','opening_component_id'=>'opening_components','opening_advance_component_id'=>'opening_components'] as $column=>$set)if($r[$column]!==null)$sets[$set][(int)$r[$column]]=true;}
            $applications=$discover('property_service_settlement_applications',['id'=>$sets['service_applications'],'document_id'=>$sets['service_documents'],'reversal_of'=>$sets['service_applications']]);
            foreach($applications as $r){$sets['service_applications'][(int)$r['id']]=true;$sets['service_documents'][(int)$r['document_id']]=true;$sets['service_requests'][(int)$r['request_id']]=true;$sets['source_entries'][(int)$r['journal_entry_id']]=true;if($r['reversal_of']!==null)$sets['service_applications'][(int)$r['reversal_of']]=true;}
            $effects=$discover('property_service_settlement_effects',['id'=>$sets['service_effects'],'application_id'=>$sets['service_applications'],'debt_id'=>$sets['debts'],'allocation_id'=>$sets['allocations'],'charge_line_id'=>$sets['lines'],'opening_component_id'=>$sets['opening_components']]);
            foreach($effects as $r){$sets['service_effects'][(int)$r['id']]=true;$sets['service_applications'][(int)$r['application_id']]=true;foreach(['debt_id'=>'debts','charge_line_id'=>'lines','opening_component_id'=>'opening_components','allocation_id'=>'allocations'] as $column=>$set)if($r[$column]!==null)$sets[$set][(int)$r[$column]]=true;}
            $discover('property_service_settlement_requests',['id'=>$sets['service_requests'],'document_id'=>$sets['service_documents']]);
            $components=$discover('property_opening_components',['id'=>$sets['opening_components'],'item_id'=>$sets['opening_items']]);foreach($components as $r){$sets['opening_components'][(int)$r['id']]=true;$sets['opening_items'][(int)$r['item_id']]=true;}
            $opening=$discover('property_opening_items',['id'=>$sets['opening_items']]);foreach($opening as $r){$sets['opening_items'][(int)$r['id']]=true;$sets['opening_batches'][(int)$r['batch_id']]=true;}
            $batches=$discover('property_opening_batches',['id'=>$sets['opening_batches']]);foreach($batches as $r){$sets['opening_batches'][(int)$r['id']]=true;$sets['source_entries'][(int)$r['journal_entry_id']]=true;}
            $journalLines=$discover('journal_entry_lines',['id'=>$sets['journal_lines']]);foreach($journalLines as $r)$sets['source_entries'][(int)$r['entry_id']]=true;
            $sourceCharges=$discover('unit_charges',['id'=>$sets['service_charges']]);foreach($sourceCharges as $r)if($r['journal_entry_id']!==null)$sets['source_entries'][(int)$r['journal_entry_id']]=true;

            $familyDelta=array_diff_key($sets['charges'],$familyQueried);$familyQueried=$sets['charges'];
            $family=$familyDelta===[]?[]:$this->query('SELECT sibling.id FROM unit_charges selected JOIN unit_charges sibling ON sibling.supplier_id=selected.supplier_id AND sibling.unit_id=selected.unit_id AND sibling.source_key=selected.source_key WHERE selected.supplier_id=? AND '.$this->condition('selected.id',$familyDelta,$unused).' ORDER BY sibling.id',[$supplier]);foreach($family as $r)$sets['charges'][(int)$r['id']]=true;
            $rows['unit_payment_debts']=$discover('unit_payment_debts',['id'=>$sets['debts'],'charge_id'=>$sets['charges'],'read_charge_id'=>$sets['charges'],'settlement_effect_id'=>$sets['service_effects']]);
            foreach($rows['unit_payment_debts'] as $row){
                $sets['debts'][(int)$row['id']]=true;if($row['charge_id']!==null)$sets['charges'][(int)$row['charge_id']]=true;
                $fact=FinancialHistoryRepository::decode($row);
                if(($row['settlement_effect_id']??null)!==null)$sets['service_effects'][(int)$row['settlement_effect_id']]=true;
                if($row['charge_id']!==null)foreach($fact['lines'] as $line)$sets['lines'][$line['id']]=true;
            }
            $rows['unit_charge_lines']=$discover('unit_charge_lines',['id'=>$sets['lines'],'charge_id'=>$sets['charges']]);
            foreach($rows['unit_charge_lines'] as $row){$sets['lines'][(int)$row['id']]=true;if($row['charge_id']!==null)$sets['charges'][(int)$row['charge_id']]=true;}
            $rows['unit_payment_allocations']=$discover('unit_payment_allocations',['id'=>$sets['allocations'],'debt_id'=>$sets['debts'],'operation_id'=>$sets['operations']],['allocation_json'=>['$.debt_id'=>$sets['debts'],'$.charge_line_id'=>$sets['lines']]]);
            foreach($rows['unit_payment_allocations'] as $row){
                $sets['allocations'][(int)$row['id']]=true;if($row['operation_id']!==null)$sets['operations'][(int)$row['operation_id']]=true;$sets['debts'][(int)$row['debt_id']]=true;if($row['charge_id']!==null)$sets['charges'][(int)$row['charge_id']]=true;
            }
            $anchors=$discover('unit_payment_correction_anchors',['correction_id'=>$sets['corrections'],'original_line_id'=>$sets['lines']],[],'correction_id,adjustment_line_id,original_line_id');
            foreach($anchors as $row){$sets['corrections'][(int)$row['correction_id']]=true;$sets['lines'][(int)$row['original_line_id']]=true;$sets['lines'][(int)$row['adjustment_line_id']]=true;}
            $reliefs=$discover('unit_payment_reliefs',['correction_id'=>$sets['corrections'],'allocation_id'=>$sets['allocations']],[],'correction_id,allocation_id');
            foreach($reliefs as $row){$sets['corrections'][(int)$row['correction_id']]=true;$sets['allocations'][(int)$row['allocation_id']]=true;}
            $rows['unit_payment_corrections']=$discover('unit_payment_corrections',['id'=>$sets['corrections'],'adjustment_charge_id'=>$sets['charges'],'operation_id'=>$sets['operations']],['snapshot_json'=>['$.anchors[*].original_line_id'=>$sets['lines'],'$.reliefs[*].allocation_id'=>$sets['allocations']]]);
            foreach($rows['unit_payment_corrections'] as $row){
                $sets['corrections'][(int)$row['id']]=true;$sets['charges'][(int)$row['adjustment_charge_id']]=true;if($row['operation_id']!==null)$sets['operations'][(int)$row['operation_id']]=true;
                $fact=FinancialHistoryRepository::decode($row);
                foreach($fact['anchors'] as $part){$sets['lines'][$part['original_line_id']]=true;$sets['lines'][$part['adjustment_line_id']]=true;}
                foreach($fact['reliefs'] as $part)$sets['allocations'][$part['allocation_id']]=true;
            }
            $rows['unit_payment_operations']=$discover('unit_payment_operations',['id'=>$sets['operations'],'original_operation_id'=>$sets['operations'],'source_id'=>$sets['sources'],'read_source_entry_id'=>$sets['source_entries']],['snapshot_json'=>['$.allocations[*].debt_id'=>$sets['debts'],'$.original_operation_id'=>$sets['operations'],'$.credit.id'=>$sets['credits'],'$.reservation.id'=>$sets['reservations'],'$.correction_id'=>$sets['corrections'],'$.source.id'=>$sets['sources'],'$.credit.unit_id'=>array_fill_keys($unitIds,true)]]);
            foreach($rows['unit_payment_operations'] as $row){
                $sets['operations'][(int)$row['id']]=true;if($row['source_id']!==null)$sets['sources'][(int)$row['source_id']]=true;if($row['original_operation_id']!==null)$sets['operations'][(int)$row['original_operation_id']]=true;
                $fact=FinancialHistoryRepository::decode($row);
                if(isset($fact['source']['id']))$sets['sources'][$fact['source']['id']]=true;if(isset($fact['source']['receipt']['ledger']['entry']['id']))$sets['source_entries'][$fact['source']['receipt']['ledger']['entry']['id']]=true;
                foreach($fact['allocations']??[] as $part){$sets['debts'][$part['debt_id']]=true;if($part['charge_id']!==null)$sets['charges'][$part['charge_id']]=true;}
                if(isset($fact['credit']['id']))$sets['credits'][$fact['credit']['id']]=true;
                if(isset($fact['reservation']['id']))$sets['reservations'][$fact['reservation']['id']]=true;
                if(isset($fact['original_operation_id']))$sets['operations'][$fact['original_operation_id']]=true;
                if(isset($fact['correction_id']))$sets['corrections'][$fact['correction_id']]=true;
            }
            $rows['unit_payment_sources']=$discover('unit_payment_sources',['id'=>$sets['sources'],'journal_entry_id'=>$sets['source_entries']]);foreach($rows['unit_payment_sources'] as $r){$sets['sources'][(int)$r['id']]=true;$sets['source_entries'][(int)$r['journal_entry_id']]=true;}
            $rows['unit_credit_lots']=$discover('unit_credit_lots',['id'=>$sets['credits'],'operation_id'=>$sets['operations'],'settlement_effect_id'=>$sets['service_effects']]);
            foreach($rows['unit_credit_lots'] as $row){$sets['credits'][(int)$row['id']]=true;if(($row['settlement_effect_id']??null)!==null)$sets['service_effects'][(int)$row['settlement_effect_id']]=true;if($row['operation_id']!==null)$sets['operations'][(int)$row['operation_id']]=true;}
            $rows['unit_credit_events']=$discover('unit_credit_events',['credit_id'=>$sets['credits'],'operation_id'=>$sets['operations'],'reservation_id'=>$sets['reservations']]);
            foreach($rows['unit_credit_events'] as $row){$sets['credits'][(int)$row['credit_id']]=true;if($row['operation_id']!==null)$sets['operations'][(int)$row['operation_id']]=true;if($row['reservation_id']!==null)$sets['reservations'][(int)$row['reservation_id']]=true;}
            $rows['unit_credit_reservations']=$discover('unit_credit_reservations',['id'=>$sets['reservations'],'credit_id'=>$sets['credits']]);
            foreach($rows['unit_credit_reservations'] as $row){$sets['reservations'][(int)$row['id']]=true;$sets['credits'][(int)$row['credit_id']]=true;}
        }while(array_map('count',$sets)!==$before);
        foreach($retained as $table=>$selected){
            $order=match($table){'unit_payment_correction_anchors'=>['correction_id','adjustment_line_id','original_line_id'],'unit_payment_reliefs'=>['correction_id','allocation_id'],'property_service_settlement_origins'=>['document_id','ordinal'],default=>['id']};
            $selected=array_values($selected);
            usort($selected,static function(array $left,array $right)use($order):int{foreach($order as $column){$compare=$left[$column]<=>$right[$column];if($compare!==0)return $compare;}return 0;});
            $rows[$table]=$selected;
        }
        foreach(['debts'=>'unit_payment_debts','operations'=>'unit_payment_operations','corrections'=>'unit_payment_corrections','credits'=>'unit_credit_lots','reservations'=>'unit_credit_reservations','allocations'=>'unit_payment_allocations','lines'=>'unit_charge_lines'] as $set=>$table){
            if(array_diff_key($sets[$set],array_fill_keys(array_column($rows[$table],'id'),true))!==[])ReceivableException::reject('history_corrupt');
        }
        $rows['unit_payment_debt_lines']=$this->select($supplier,'unit_payment_debt_lines',['debt_id'=>$sets['debts']],[],'debt_id,charge_line_id');
        $rows['unit_payment_sources']=$this->select($supplier,'unit_payment_sources',['id'=>$sets['sources']]);
        $rows['unit_claim_accessory_receipts']=$this->select($supplier,'unit_claim_accessory_receipts',['debt_id'=>$sets['debts']],['receipt_json'=>['$.debt_id'=>$sets['debts']]]);
        $rows['unit_charge_issue_audit']=$this->select($supplier,'unit_charge_issue_audit',['charge_id'=>$sets['charges']]);
        $rows['unit_charge_adjustments']=$this->select($supplier,'unit_charge_adjustments',['charge_id'=>$sets['charges']]);
        $rows['unit_credit_parties']=$this->select($supplier,'unit_credit_parties',['credit_id'=>$sets['credits']],[], 'credit_id,client_id');
        $parts=[];$params=[$supplier];
        foreach(['debt'=>'debts','operation'=>'operations','correction'=>'corrections','reservation'=>'reservations'] as $kind=>$set){$parts[]="(r.object_kind='".$kind."' AND ".$this->condition('r.object_id',$sets[$set],$params).')';}
        $rows['unit_payment_audit']=$this->query('SELECT r.* FROM unit_payment_audit r WHERE r.supplier_id=? AND ('.implode(' OR ',$parts).') ORDER BY r.id',$params);
        $rows['property_service_settlement_ledgers']=[];
        if(($rows['property_service_settlement_applications']??[])!==[]){
        $history=new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($this->db);
        foreach($rows['property_service_settlement_applications']??[] as $application)$history->application($supplier,(int)$application['id'],false);
        foreach($rows['property_opening_items']??[] as $item)(new \MyInvoice\Service\Property\Opening\OpeningReadProof($this->db))->item($supplier,(int)$item['id'],false);
        $rows['property_service_settlement_ledgers']=[];
        foreach(array_keys($sets['source_entries']) as $entry){$ledger=(new \MyInvoice\Service\Property\Payments\PaymentJournalProof($this->db))->snapshot($supplier,(int)$entry,false);$rows['property_service_settlement_ledgers'][]=['supplier_id'=>$supplier,'id'=>(int)$entry,'snapshot'=>$ledger];}
        }
        $adapter=null;foreach($rows['property_service_settlements']??[] as $row){$fact=json_decode($row['document_json'],true,flags:JSON_THROW_ON_ERROR);if(!isset($fact['cost']['sources']))continue;if($adapter===null){$container=\MyInvoice\Bootstrap::buildContainer();$container->set(Connection::class,$this->db);$adapter=$container->get(\MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter::class);}$receipt=json_decode($row['origin_receipt_json'],true,flags:JSON_THROW_ON_ERROR);(new \MyInvoice\Service\Property\Services\Settlement\SettlementCostProof($this->db))->assertRecorded($supplier,$fact['unit_id'],$fact['service_id'],$receipt['request']['accounting_date'],['from'=>$fact['period_from'],'to'=>$fact['period_to']],$fact['cost'],$adapter);}
        \MyInvoice\Service\Property\Services\Settlement\SettlementHistory::selectedMaterializations($rows);
        return new PropertySaldoReadSelection($rows,array_map('intval',array_keys($sets['debts'])),array_map('intval',array_keys($sets['charges'])),$sets);
    }
    private function select(int $supplier,string $table,array $columns,array $json=[],string $order='id',array $excluded=[]):array
    {
        $parts=[];$params=[$supplier];
        foreach($columns as $column=>$ids)if($ids!==[])$parts[]=$this->condition('r.'.$column,$ids,$params);
        if($table==='unit_payment_operations'&&isset($json['snapshot_json'])){
            $mapping=['$.allocations[*].debt_id'=>'$.debt_ids[*]','$.original_operation_id'=>'$.original_operation_id','$.credit.id'=>'$.credit_id','$.reservation.id'=>'$.reservation_id','$.correction_id'=>'$.correction_id','$.source.id'=>'$.source_id','$.credit.unit_id'=>'$.credit_unit_id'];$paths=[];
            foreach($json['snapshot_json'] as $path=>$ids)$paths[$mapping[$path]]=$ids;unset($json['snapshot_json']);$json['read_links_json']=$paths;
        }
        foreach($json as $column=>$paths){$definitions=[];$matches=[];$n=0;foreach($paths as $path=>$ids){
            if($ids===[])continue;$name='value'.($n++);
            $definitions[]="NESTED PATH '".$path."' COLUMNS(".$name." BIGINT PATH '$')";
            $matches[]=$this->condition('refs.'.$name,$ids,$params);
        }if($definitions!==[])$parts[]="EXISTS (SELECT 1 FROM JSON_TABLE(r.".$column.",'$' COLUMNS(".implode(',',$definitions).")) refs WHERE ".implode(' OR ',$matches).")";}
        if($parts===[])return [];
        $exclude=$excluded===[]?'':' AND NOT ('.$this->condition('r.id',$excluded,$params).')';
        return $this->query('SELECT r.* FROM '.$table.' r WHERE r.supplier_id=? AND ('.implode(' OR ',$parts).')'.$exclude.' ORDER BY '.$order,$params);
    }
    private function condition(string $column,array $ids,array &$params):string
    {
        if($ids===[])return '0';
        return $column.' IN ('.implode(',',array_map('intval',array_keys($ids))).')';
    }

    private function query(string $sql,array $params):array
    {
        $q=$this->db->pdo()->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
