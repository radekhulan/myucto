<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Receivables;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\Receivables\ReceivableException;
use PDO;
final class SelectedFinancialClosure
{
    public function __construct(private readonly Connection $db){}
    public function current(int $supplier,array $debtIds):array
    {
        return $this->currentForSources($supplier,$debtIds,[]);
    }
    public function currentForSources(int $supplier,array $debtIds,array $chargeIds):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Selected financial history requires owner transaction.');
        foreach($debtIds as $id)PropertyInput::id($id,'debt_id');
        foreach($chargeIds as $id)PropertyInput::id($id,'charge_id');
        (new PaymentLocks($this->db))->acquire($supplier);
        $sets=['debts'=>array_fill_keys($debtIds,true),'charges'=>array_fill_keys($chargeIds,true),'lines'=>[],'allocations'=>[],'operations'=>[],'corrections'=>[],'credits'=>[],'reservations'=>[]];$rows=[];
        do{
            $before=array_map('count',$sets);
            $rows['unit_payment_debts']=$this->select($supplier,'unit_payment_debts',['id'=>$sets['debts'],'charge_id'=>$sets['charges']]);
            foreach($rows['unit_payment_debts'] as $row){
                $sets['debts'][(int)$row['id']]=true;if($row['charge_id']!==null)$sets['charges'][(int)$row['charge_id']]=true;
                $fact=FinancialHistoryRepository::decode($row);
                if($row['charge_id']!==null)foreach($fact['lines'] as $line)$sets['lines'][$line['id']]=true;
            }
            $rows['unit_charge_lines']=$this->select($supplier,'unit_charge_lines',['id'=>$sets['lines'],'charge_id'=>$sets['charges']]);
            foreach($rows['unit_charge_lines'] as $row){$sets['lines'][(int)$row['id']]=true;if($row['charge_id']!==null)$sets['charges'][(int)$row['charge_id']]=true;}
            $rows['unit_payment_allocations']=$this->select($supplier,'unit_payment_allocations',['id'=>$sets['allocations'],'debt_id'=>$sets['debts'],'operation_id'=>$sets['operations']],['allocation_json'=>['$.debt_id'=>$sets['debts'],'$.charge_line_id'=>$sets['lines']]]);
            foreach($rows['unit_payment_allocations'] as $row){
                $sets['allocations'][(int)$row['id']]=true;if($row['operation_id']!==null)$sets['operations'][(int)$row['operation_id']]=true;$sets['debts'][(int)$row['debt_id']]=true;if($row['charge_id']!==null)$sets['charges'][(int)$row['charge_id']]=true;
            }
            $anchors=$this->select($supplier,'unit_payment_correction_anchors',['correction_id'=>$sets['corrections'],'original_line_id'=>$sets['lines']],[],'correction_id,adjustment_line_id,original_line_id');
            foreach($anchors as $row){$sets['corrections'][(int)$row['correction_id']]=true;$sets['lines'][(int)$row['original_line_id']]=true;$sets['lines'][(int)$row['adjustment_line_id']]=true;}
            $reliefs=$this->select($supplier,'unit_payment_reliefs',['correction_id'=>$sets['corrections'],'allocation_id'=>$sets['allocations']],[],'correction_id,allocation_id');
            foreach($reliefs as $row){$sets['corrections'][(int)$row['correction_id']]=true;$sets['allocations'][(int)$row['allocation_id']]=true;}
            $rows['unit_payment_corrections']=$this->select($supplier,'unit_payment_corrections',['id'=>$sets['corrections'],'adjustment_charge_id'=>$sets['charges'],'operation_id'=>$sets['operations']],['snapshot_json'=>['$.anchors[*].original_line_id'=>$sets['lines'],'$.reliefs[*].allocation_id'=>$sets['allocations']]]);
            foreach($rows['unit_payment_corrections'] as $row){
                $sets['corrections'][(int)$row['id']]=true;$sets['charges'][(int)$row['adjustment_charge_id']]=true;if($row['operation_id']!==null)$sets['operations'][(int)$row['operation_id']]=true;
                $fact=FinancialHistoryRepository::decode($row);
                foreach($fact['anchors'] as $part){$sets['lines'][$part['original_line_id']]=true;$sets['lines'][$part['adjustment_line_id']]=true;}
                foreach($fact['reliefs'] as $part)$sets['allocations'][$part['allocation_id']]=true;
            }
            $rows['unit_payment_operations']=$this->select($supplier,'unit_payment_operations',['id'=>$sets['operations'],'original_operation_id'=>$sets['operations']],['snapshot_json'=>['$.allocations[*].debt_id'=>$sets['debts'],'$.original_operation_id'=>$sets['operations'],'$.credit.id'=>$sets['credits'],'$.reservation.id'=>$sets['reservations'],'$.correction_id'=>$sets['corrections']]]);
            foreach($rows['unit_payment_operations'] as $row){
                $sets['operations'][(int)$row['id']]=true;if($row['original_operation_id']!==null)$sets['operations'][(int)$row['original_operation_id']]=true;
                $fact=FinancialHistoryRepository::decode($row);
                foreach($fact['allocations']??[] as $part){$sets['debts'][$part['debt_id']]=true;if($part['charge_id']!==null)$sets['charges'][$part['charge_id']]=true;}
                if(isset($fact['credit']['id']))$sets['credits'][$fact['credit']['id']]=true;
                if(isset($fact['reservation']['id']))$sets['reservations'][$fact['reservation']['id']]=true;
                if(isset($fact['original_operation_id']))$sets['operations'][$fact['original_operation_id']]=true;
                if(isset($fact['correction_id']))$sets['corrections'][$fact['correction_id']]=true;
            }
            $serviceEffects=$this->select($supplier,'property_service_settlement_effects',['debt_id'=>$sets['debts'],'allocation_id'=>$sets['allocations']]);$serviceIds=array_fill_keys(array_column($serviceEffects,'id'),true);
            foreach($this->select($supplier,'unit_credit_lots',['settlement_effect_id'=>$serviceIds]) as $credit)$sets['credits'][(int)$credit['id']]=true;
            $rows['unit_credit_lots']=$this->select($supplier,'unit_credit_lots',['id'=>$sets['credits'],'operation_id'=>$sets['operations']]);
            foreach($rows['unit_credit_lots'] as $row){$sets['credits'][(int)$row['id']]=true;if($row['operation_id']!==null)$sets['operations'][(int)$row['operation_id']]=true;}
            $rows['unit_credit_events']=$this->select($supplier,'unit_credit_events',['credit_id'=>$sets['credits'],'operation_id'=>$sets['operations'],'reservation_id'=>$sets['reservations']]);
            foreach($rows['unit_credit_events'] as $row){$sets['credits'][(int)$row['credit_id']]=true;if($row['operation_id']!==null)$sets['operations'][(int)$row['operation_id']]=true;if($row['reservation_id']!==null)$sets['reservations'][(int)$row['reservation_id']]=true;}
            $rows['unit_credit_reservations']=$this->select($supplier,'unit_credit_reservations',['id'=>$sets['reservations'],'credit_id'=>$sets['credits']]);
            foreach($rows['unit_credit_reservations'] as $row){$sets['reservations'][(int)$row['id']]=true;$sets['credits'][(int)$row['credit_id']]=true;}
        }while(array_map('count',$sets)!==$before);
        foreach(['debts'=>'unit_payment_debts','operations'=>'unit_payment_operations','corrections'=>'unit_payment_corrections','credits'=>'unit_credit_lots','reservations'=>'unit_credit_reservations','allocations'=>'unit_payment_allocations','lines'=>'unit_charge_lines'] as $set=>$table){
            if(array_diff_key($sets[$set],array_fill_keys(array_column($rows[$table],'id'),true))!==[])ReceivableException::reject('history_corrupt');
        }
        $rows['unit_claim_accessory_receipts']=$this->select($supplier,'unit_claim_accessory_receipts',['debt_id'=>$sets['debts']]);
        $rows['unit_charge_issue_audit']=$this->select($supplier,'unit_charge_issue_audit',['charge_id'=>$sets['charges']]);
        $rows['unit_charge_adjustments']=$this->select($supplier,'unit_charge_adjustments',['charge_id'=>$sets['charges']]);
        $rows['unit_credit_parties']=$this->select($supplier,'unit_credit_parties',['credit_id'=>$sets['credits']],[], 'credit_id,client_id');
        $parts=[];$params=[$supplier];
        foreach(['debt'=>'debts','operation'=>'operations','correction'=>'corrections','reservation'=>'reservations'] as $kind=>$set){$parts[]="(r.object_kind='".$kind."' AND ".$this->condition('r.object_id',$sets[$set],$params).')';}
        $rows['unit_payment_audit']=$this->query('SELECT r.* FROM unit_payment_audit r WHERE r.supplier_id=? AND ('.implode(' OR ',$parts).') ORDER BY r.id FOR UPDATE',$params);
        return ['rows'=>$rows,'debt_ids'=>array_map('intval',array_keys($sets['debts'])),'charge_ids'=>array_map('intval',array_keys($sets['charges']))];
    }
    private function select(int $supplier,string $table,array $columns,array $json=[],string $order='id'):array
    {
        $parts=[];$params=[$supplier];
        foreach($columns as $column=>$ids)if($ids!==[])$parts[]=$this->condition('r.'.$column,$ids,$params);
        foreach($json as $column=>$paths)foreach($paths as $path=>$ids){
            if($ids===[])continue;
            $extract="JSON_EXTRACT(r.".$column.",'".$path."')";
            $fallback=$this->condition('refs.value',$ids,$params);
            $params[]=json_encode(array_map('intval',array_keys($ids)),JSON_THROW_ON_ERROR);
            $parts[]="CASE WHEN ".$extract." REGEXP '[\".eE]' THEN EXISTS (SELECT 1 FROM JSON_TABLE(r.".$column.",'".$path."' COLUMNS(value BIGINT PATH '$')) refs WHERE ".$fallback.") ELSE JSON_OVERLAPS(".$extract.",?) END";
        }
        if($parts===[])return [];
        return $this->query('SELECT r.* FROM '.$table.' r WHERE r.supplier_id=? AND ('.implode(' OR ',$parts).') ORDER BY '.$order.' FOR UPDATE',$params);
    }
    private function condition(string $column,array $ids,array &$params):string
    {
        if($ids===[])return '0';
        $values=array_map('intval',array_keys($ids));
        if(count($values)<=500){
            array_push($params,...$values);
            return $column.' IN ('.implode(',',array_fill(0,count($values),'?')).')';
        }
        $params[]=json_encode($values,JSON_THROW_ON_ERROR);
        return $column." IN (SELECT id FROM JSON_TABLE(?,'$[*]' COLUMNS(id BIGINT PATH '$')) selected_ids)";
    }
    private function query(string $sql,array $params):array
    {
        $q=$this->db->pdo()->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
