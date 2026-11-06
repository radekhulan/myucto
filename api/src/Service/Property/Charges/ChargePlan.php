<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges;

use MyInvoice\Service\Property\PropertyInput;

final class ChargePlan
{
    public static function calculate(array $request,array $registry,array $components): array
    {
        $active=array_column($registry['units'],null,'id'); $all=array_keys($active); sort($all,SORT_NUMERIC);
        $components=array_column($components,null,'id'); $out=[]; $total='0'; $unitTotals=[]; $facts=[];
        foreach($request['rates'] as $rate) {
            $component=$components[$rate['component_id']]??throw new ChargeException('property_charge_component_missing','Složka nepatří do číselníku firmy.',404);
            $units=[];
            foreach($rate['unit_ids'] as $id) $units[]=$active[$id]??throw new ChargeException('property_charge_unit_unavailable','Jednotka rozhodnutí není k okamžiku výpočtu aktivní v tomto domě.',409,['unit_id'=>$id]);
            $basis=$rate['legal_basis'];
            if($component['kind']==='service_advance') {
                if(!in_array($basis,['service_decision','historical_documented'],true)) self::legal();
                foreach($units as $u) if(!in_array($component['service_id'],$u['services'],true)) throw new ChargeException('property_charge_service_unavailable','Jednotka nemá v doloženém snapshotu tuto službu.',409,['unit_id'=>$u['id'],'service_id'=>$component['service_id']]);
                if($basis!=='historical_documented') $facts[]=['component_id'=>$component['id'],'code'=>'service_advance_notice_before_issue','required'=>['written_notice_ref','delivered_at_per_recipient','reason_for_change','earliest_month_after_delivery'],
                    'legal_reference'=>'67/2013 §4(4)','affects'=>'future_issuance_only'];
            } elseif($basis==='service_decision') self::legal();
            if(in_array($basis,['management_share','own_administration_equal'],true)&&!($registry['house']['registry_complete']??false)) self::legal();
            if($basis==='management_share') {
                if($rate['method']!=='share'||$rate['unit_ids']!==$all) self::legal();
                $sum=['num'=>'0','den'=>'1']; foreach($units as $u) {
                    if(!isset($u['attributes']['share'])) self::legal();
                    $sum=ChargeFraction::add($sum,$u['attributes']['share']);
                }
                if($sum!==['num'=>'1','den'=>'1']) throw new ChargeException('property_charge_share_incomplete','Zákonný podílový klíč vyžaduje úplný součet podílů domu.',409);
            }
            if($basis==='own_administration_equal'&&(!in_array($rate['method'],['unit','equal'],true)||$rate['unit_ids']!==$all)) self::legal();
            if(in_array($component['kind'],['long_term_advance','admin_advance','contribution'],true)&&!in_array($basis,['management_share','declaration_override','own_administration_equal','historical_documented'],true)) self::legal();
            if($component['kind']==='contribution') $facts[]=['component_id'=>$component['id'],'code'=>'definitive_contribution_title','required'=>['decision_establishing_definitive_contribution'],'affects'=>'future_issuance_only'];
            if($basis==='historical_documented') $facts[]=['component_id'=>$component['id'],'code'=>'historical_legal_issuance_assessment','required'=>['contemporary_legal_title','applicable_law_at_issue'],'affects'=>'future_issuance_only'];
            $result=ChargeCalculator::calculate($rate,$units);
            foreach($result['lines'] as $line) $unitTotals[$line['unit_id']]=bcadd($unitTotals[$line['unit_id']]??'0',$line['amount_cents'],0);
            $total=bcadd($total,$result['total_cents'],0);
            $out[]=['component'=>$component,'rate'=>$rate,'calculation'=>$result];
        }
        ksort($unitTotals,SORT_NUMERIC);
        $facts[]=['code'=>'no_automatic_proration','required'=>['explicit_period_and_due_date','legal_parties_and_liability_basis','applicable_effective_snapshot'],'affects'=>'future_issuance_only'];
        return ['rates'=>$out,'unit_totals'=>array_map(static fn($id,$cents)=>['unit_id'=>$id,'amount_cents'=>$cents],array_keys($unitTotals),array_values($unitTotals)),
            'total_cents'=>$total,'issuance_facts'=>$facts];
    }
    public static function differences(array $before,array $after): array
    {
        $a=array_column($before['unit_totals']??[],'amount_cents','unit_id'); $b=array_column($after['unit_totals']??[],'amount_cents','unit_id');
        $ids=array_unique([...array_keys($a),...array_keys($b)]); sort($ids,SORT_NUMERIC);
        return array_map(static fn($id)=>['unit_id'=>$id,'before_cents'=>$a[$id]??'0','after_cents'=>$b[$id]??'0','delta_cents'=>bcsub($b[$id]??'0',$a[$id]??'0',0)],$ids);
    }
    private static function legal(): never { throw new ChargeException('property_charge_legal_basis_invalid','Metoda nebo okruh jednotek neodpovídá zvolenému právnímu podkladu.'); }
}
