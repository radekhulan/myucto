<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Catalog;

use MyInvoice\Service\Property\PropertyException;

final class PropertyAccountingProjection
{
    public static function cents(mixed $value):string
    {
        if(!is_string($value)||preg_match('/^-?(?:0|[1-9][0-9]*)$/D',$value)!==1)throw new PropertyException('property_catalog_amount_invalid','property_catalog_amount_invalid',409);
        return $value;
    }
    private static function amounts(array $values,int $depth=1):object
    {
        $out=[];foreach($values as $key=>$value)$out[$key]=$depth===1?self::cents($value):self::amounts($value,$depth-1);return(object)$out;
    }
    private static function only(array $value,array $fields):array{return array_intersect_key($value,array_fill_keys($fields,true));}
    private static function findings(array $rows):array
    {
        return array_map(static fn(array $row):array=>self::only($row,['code','case_id','event_id','line_id','unit_id','service_id','accounting_date']),$rows);
    }
    public static function lifecycle(array $state,bool $detail=false):array
    {
        $case=$state['case'];$estimate=$state['estimate'];$final=$state['final'];$unwinds=$state['unwinds'];$pending=$state['pending'];$required=count($estimate['receipt']['sources']??[]);
        $phase=$final!==null?'finalized':($estimate===null?'pending':(count($unwinds)===$required?'unwound':($unwinds===[]?'estimated':'partially_unwound')));
        $out=self::only($case,['id','unit_id','service_id','period_from','period_to','policy','service_cost_account'])+['currency'=>'CZK','knowledge_basis'=>'current_ledger','known_at'=>null,'phase'=>$phase,'estimate_cents'=>$estimate===null?null:self::cents($estimate['receipt']['amount_cents']),'final_cents'=>$final===null?null:self::cents($final['amount_cents']),'final_cost_line_id'=>$final['cost_line_id']??null,'incomplete'=>$estimate===null||$pending!==[],'pending'=>self::findings($pending)];
        if($detail){
            $events=[];
            if($estimate!==null)$events[]=['event_id'=>$estimate['event_id'],'kind'=>$estimate['request']['kind'],'accounting_date'=>$estimate['request']['accounting_date']??null,'journal_entry_id'=>$estimate['receipt']['ledger']['entry']['id']??null];
            foreach($unwinds as $event)$events[]=['event_id'=>$event['event_id'],'kind'=>$event['request']['kind'],'accounting_date'=>$event['accounting_date'],'journal_entry_id'=>$event['ledger']['entry']['id']];
            if($final!==null)$events[]=['event_id'=>$final['event_id'],'kind'=>'final','accounting_date'=>$final['accounting_date'],'journal_entry_id'=>$final['ledger']['entry']['id']??null];
            $out['events']=$events;
        }return $out;
    }
    public static function reconciliation(array $read):array
    {
        $out=self::only($read,['unit_id','service_id','accounting_date','period_from','known_at','knowledge_basis','view','coverage','matches','unregistered_line_ids','verified_carry_entry_ids','source_line_ids'])+['currency'=>'CZK','findings'=>self::findings($read['incomplete'])];
        foreach(['totals','ledger_totals'] as $key)$out[$key]=self::amounts($read[$key]);
        foreach(['unit_service_balances','ledger_unit_service_balances','differences'] as $key)$out[$key]=self::amounts($read[$key],3);
        $out['component_totals']=self::amounts($read['component_totals'],2);return $out;
    }
    public static function operation(array $operation):array
    {
        $amount=$operation['kind']==='reverse'?bcsub('0',self::cents($operation['snapshot']['proofs']['original']['request']['amount_cents']),0):self::cents($operation['request']['amount_cents']);
        $out=self::only($operation,['id','house_id','kind','accounting_date','journal_entry_id','reversal_of'])+['amount_cents'=>$amount,'currency'=>'CZK','status'=>$operation['active']?'active':'reversed'];
        $out['effects']=array_map(static fn(array $effect):array=>self::only($effect,['id','unit_id','account_code','source_kind','source_id'])+['amount_cents'=>self::cents($effect['amount_cents'])],$operation['receipt']['effects']);return $out;
    }
    public static function balances(array $read):array
    {
        $out=self::only($read,['house_id','unit_id','accounting_date','known_at','knowledge_basis','coverage','matches','unregistered_line_ids'])+['currency'=>'CZK'];
        foreach(['totals','ledger_totals'] as $key)$out[$key]=self::amounts($read[$key]);
        foreach(['unit_balances','differences','component_totals'] as $key)$out[$key]=self::amounts($read[$key],2);return $out;
    }
}
