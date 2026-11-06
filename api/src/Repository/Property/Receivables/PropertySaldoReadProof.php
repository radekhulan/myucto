<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Receivables;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Receivables\ReceivableException;
final class PropertySaldoReadProof
{
    public static function materializations(array $rows,array $charges=[]):void
    {
        \MyInvoice\Service\Property\Services\Settlement\SettlementHistory::selectedMaterializations($rows);
        $bindings=[];foreach($rows['unit_payment_debt_lines'] as $r)$bindings[(int)$r['debt_id']][]=(int)$r['charge_line_id'];
        foreach($rows['unit_payment_debts'] as $r){$fact=FinancialHistoryRepository::decode($r);if(($r['settlement_effect_id']??null)!==null){if($r['charge_id']!==null||($r['opening_item_id']??null)!==null||($bindings[(int)$r['id']]??[])!==[])ReceivableException::reject('history_corrupt');continue;}if(($r['opening_item_id']??null)!==null){if($r['charge_id']!==null||($bindings[(int)$r['id']]??[])!==[]||($fact['opening_item_id']??null)!==(int)$r['opening_item_id'])ReceivableException::reject('history_corrupt');continue;}$expected=array_column($fact['lines'],'id');sort($expected,SORT_NUMERIC);$actual=$bindings[(int)$r['id']]??[];sort($actual,SORT_NUMERIC);if($expected!==$actual)ReceivableException::reject('history_corrupt');if($charges!==[]&&(!isset($charges[(int)$r['charge_id']])||$fact['unit_id']!==$charges[(int)$r['charge_id']]['unit_id']))ReceivableException::reject('history_corrupt');}
        foreach(['anchors'=>['unit_payment_correction_anchors',['adjustment_line_id','original_line_id','amount_cents']],'reliefs'=>['unit_payment_reliefs',['allocation_id','amount_cents']]] as $key=>[$table,$fields]){
            $actual=[];foreach($rows[$table] as $r){$part=[];foreach($fields as $field)$part[$field]=$field==='amount_cents'?$r[$field]:(int)$r[$field];$actual[(int)$r['correction_id']][]=$part;}
            foreach($rows['unit_payment_corrections'] as $r){$fact=FinancialHistoryRepository::decode($r);if(PropertyInput::hash($fact[$key])!==PropertyInput::hash($actual[(int)$r['id']]??[]))ReceivableException::reject('history_corrupt');}
        }
        $debts=array_column($rows['unit_payment_debts'],null,'id');$accessories=array_column($rows['unit_claim_accessory_receipts']??[],null,'id');
        foreach($accessories as $r){$fact=FinancialHistoryRepository::decode($r,'receipt');$debt=$debts[(int)$r['debt_id']]??null;
            if(($r['opening_component_id']??null)!==null){if(!$debt)ReceivableException::reject('history_corrupt');\MyInvoice\Service\Property\Opening\OpeningOriginProof::accessoryMaterialization((int)$r['supplier_id'],$debt,$r);continue;}
            if(!$debt||$fact['debt_id']!==(int)$r['debt_id']||(int)$debt['charge_id']!==(int)$r['charge_id']||$fact['request']['component']!==$r['component']||(($r['opening_component_id']??null)===null?$fact['line']['id']!==(int)$r['journal_line_id']:($r['journal_line_id']!==null||($fact['opening_component_id']??null)!==(int)$r['opening_component_id']))||$fact['ledger']['entry']['id']!==(int)$r['journal_entry_id']||bcmul($fact['line']['amount'],'100',0)!==$r['amount_cents'])ReceivableException::reject('history_corrupt');
        }
        foreach($rows['unit_payment_allocations']??[] as $r){
            if($r['accessory_id']!==null){$accessory=$accessories[(int)$r['accessory_id']]??null;if(!$accessory||(int)$accessory['debt_id']!==(int)$r['debt_id']||$r['charge_line_id']!==null)ReceivableException::reject('history_corrupt');}
            elseif(($r['settlement_effect_id']??null)!==null){$debt=$debts[(int)$r['debt_id']]??null;if(!$debt||($debt['settlement_effect_id']??null)===null||(int)$r['settlement_effect_id']!==(int)$debt['settlement_effect_id']||$r['charge_id']!==null||$r['charge_line_id']!==null||$r['opening_component_id']!==null)ReceivableException::reject('history_corrupt');}
            elseif(($r['opening_component_id']??null)!==null){$debt=$debts[(int)$r['debt_id']]??null;$fact=$debt?FinancialHistoryRepository::decode($debt):null;if(!$fact||$r['charge_id']!==null||$r['charge_line_id']!==null||!isset($fact['opening_item_id'])||!in_array((int)$r['opening_component_id'],array_column($fact['lines'],'id'),true))ReceivableException::reject('history_corrupt');}
            elseif(!in_array((int)$r['charge_line_id'],$bindings[(int)$r['debt_id']]??[],true))ReceivableException::reject('history_corrupt');
        }
        $credits=[];foreach($rows['unit_credit_lots'] as $r)if($r['operation_id']!==null)$credits[(int)$r['operation_id']]=$r;
        $sources=[];foreach($rows['unit_payment_sources'] as $r){$receipt=FinancialHistoryRepository::decode($r,'receipt');if($receipt['amount_cents']!==$r['amount_cents']||$receipt['direction']!==$r['direction']||$receipt['ledger']['entry']['id']!==(int)$r['journal_entry_id'])ReceivableException::reject('history_corrupt');$sources[(int)$r['id']]=$r;}
        foreach($rows['unit_payment_operations'] as $r){
            $fact=FinancialHistoryRepository::decode($r);
            if($r['status']==='pending')continue;
            if(isset($fact['credit']['unit_id'])&&!isset($fact['credit']['id'])&&!isset($credits[(int)$r['id']]))ReceivableException::reject('history_corrupt');
            if(isset($fact['source']['receipt'])){if((isset($fact['source']['id'])&&(int)$r['source_id']!==$fact['source']['id'])||!isset($sources[(int)$r['source_id']])||PropertyInput::hash($fact['source']['receipt'])!==$sources[(int)$r['source_id']]['receipt_hash'])ReceivableException::reject('history_corrupt');}
        }
        $used=[];foreach($rows['unit_payment_operations'] as $operation)if($operation['source_id']!==null&&$operation['status']==='posted'&&in_array($operation['kind'],['allocation','refund'],true))$used[(int)$operation['source_id']]=bcadd($used[(int)$operation['source_id']]??'0',$operation['amount_cents'],0);
        foreach($sources as $id=>$source)if(($used[$id]??'0')!==$source['consumed_cents']||bccomp($source['consumed_cents'],$source['amount_cents'],0)>0)ReceivableException::reject('history_corrupt');
    }
    public static function creditEvents(array $rows):void
    {
        $operations=array_column($rows['unit_payment_operations'],null,'id');$credits=array_column($rows['unit_credit_lots'],null,'id');
        foreach($rows['unit_credit_events'] as $r){$op=$operations[(int)$r['operation_id']]??null;$credit=$credits[(int)$r['credit_id']]??null;if(!$op||!$credit)ReceivableException::reject('history_corrupt');$fact=FinancialHistoryRepository::decode($op);
            if($r['kind']!==$op['kind']||!in_array($r['kind'],['refund','setoff'],true)||$r['amount_cents']!=='-'.$op['amount_cents']||$fact['credit']['id']!==(int)$r['credit_id'])ReceivableException::reject('history_corrupt');
            if($r['kind']==='refund'){$expected=['reservation_id'=>$fact['reservation']['id'],'amount_cents'=>$op['amount_cents'],'source_receipt_hash'=>PropertyInput::hash($fact['source']['receipt'])];if((int)$r['reservation_id']!==$expected['reservation_id'])ReceivableException::reject('history_corrupt');}
            else{$expected=['credit_id'=>(int)$r['credit_id'],'amount_cents'=>$op['amount_cents'],'credit_snapshot_hash'=>$credit['snapshot_hash']];if($r['reservation_id']!==null)ReceivableException::reject('history_corrupt');}
            if(PropertyInput::hash(json_decode($r['event_json'],true,flags:JSON_THROW_ON_ERROR))!==PropertyInput::hash($expected))ReceivableException::reject('history_corrupt');
        }
        foreach($operations as $op)if($op['status']!=='pending'&&in_array($op['kind'],['refund','setoff'],true)&&!array_any($rows['unit_credit_events'],fn($r)=>(int)$r['operation_id']===(int)$op['id']))ReceivableException::reject('history_corrupt');
    }
    public static function parties(array $history,array $events):void
    {
        $heads=[];foreach($events as $event)foreach($event['snapshot']['debts'] as $part){
            if($part['predecessor_event_id']!==($heads[$part['debt_id']]??null))ReceivableException::reject('party_history_chain');$heads[$part['debt_id']]=$event['id'];
            $debt=$history['debts'][$part['debt_id']]??null;
            if(!$debt||$debt['unit_id']!==$event['snapshot']['request']['unit_id']||$part['source_snapshot_hash']!==$debt['snapshot_hash'])ReceivableException::reject('party_source_changed');
            $effects=array_column($debt['events'],null,'id');foreach($part['financial_events'] as $effect)if(!isset($effects[$effect['id']])||PropertyInput::hash($effect)!==PropertyInput::hash($effects[$effect['id']]))ReceivableException::reject('party_source_changed');
        }
    }
}
