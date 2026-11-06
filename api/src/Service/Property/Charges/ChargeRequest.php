<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges;

use MyInvoice\Service\Property\PropertyInput as Input;
use MyInvoice\Service\Property\PropertyInstant;

final class ChargeRequest
{
    public const KINDS=['long_term_advance','admin_advance','service_advance','contribution','other'];
    public static function component(array $input): array
    {
        Input::fields($input,['code','name','kind','service_id','account_code','effective_at','idempotency_key']);
        if (!in_array($input['kind']??null,self::KINDS,true)) Input::invalid('kind');
        $service=$input['service_id']??null;
        if($input['kind']==='service_advance') $service=Input::id($service,'service_id');
        elseif($service!==null) Input::invalid('service_id');
        $account=$input['account_code']??'';
        if(!is_string($account)||($account!==''&&!preg_match('/^[0-9A-Za-z.]{3,10}$/D',$account))) Input::invalid('account_code');
        return ['code'=>Input::code($input['code']??null,'code'),'name'=>Input::text($input['name']??null,'name'),
            'effective_at'=>PropertyInstant::normalize($input['effective_at']??null),'kind'=>$input['kind'],'service_id'=>$service,'account_code'=>$account,'idempotency_key'=>Input::code($input['idempotency_key']??null,'idempotency_key')];
    }
    public static function version(array $input): array
    {
        Input::fields($input,['house_id','expected_revision','effective_from','effective_to','basis_at','basis_policy','period_policy','frequency','decision_ref','decided_at','reason','rates']);
        if(!is_int($input['expected_revision']??null)||$input['expected_revision']<0) Input::invalid('expected_revision');
        [$from,$to]=PropertyInstant::interval($input['effective_from']??null,$input['effective_to']??null);
        $at=PropertyInstant::normalize($input['basis_at']??null);
        if($at!==$from||($input['basis_policy']??null)!=='fixed_registry_snapshot'||($input['period_policy']??null)!=='no_automatic_proration') Input::invalid('basis_policy');
        if(!in_array($input['frequency']??null,['monthly','quarterly','annual','one_off'],true)) Input::invalid('frequency');
        $rates=[];
        foreach(Input::list($input['rates']??null,'rates',100) as $rate) {
            if(!is_array($rate)) Input::invalid('rates');
            Input::fields($rate,['component_id','unit_ids','method','mode','amount','rounding','legal_basis','scope_ref','correction','correction_reason']);
            $id=Input::id($rate['component_id']??null,'component_id');
            if(isset($rates[$id])) Input::invalid('component_id');
            $units=Input::list($rate['unit_ids']??null,'unit_ids',1000);
            if($units===[]||count(array_unique($units,SORT_REGULAR))!==count($units)) Input::invalid('unit_ids');
            foreach($units as $unit) Input::id($unit,'unit_ids');
            sort($units,SORT_NUMERIC);
            if(!in_array($rate['method']??null,['unit','floor_area','share','person','equal'],true)
                ||!in_array($rate['mode']??null,['rate_per_basis','total_budget'],true)
                ||($rate['rounding']??null)!==ChargeCalculator::ROUNDING) Input::invalid('method');
            $amount=ChargeFraction::decimalCents($rate['amount']??null);
            if($rate['mode']==='total_budget'&&$amount['den']!=='1') Input::invalid('amount');
            if(!is_bool($rate['correction']??null)) Input::invalid('correction');
            $correction=Input::text($rate['correction_reason']??'','correction_reason',2000,!$rate['correction']);
            if(str_starts_with($amount['num'],'-')&&!$rate['correction']) throw new ChargeException('property_charge_correction_required','Záporná opravná částka vyžaduje vlastní doložený důvod.');
            if(!in_array($rate['legal_basis']??null,['management_share','declaration_override','own_administration_equal','service_decision','other_documented','historical_documented'],true)) Input::invalid('legal_basis');
            $date=(new \DateTimeImmutable($from))->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d');
            if($date<'2020-07-01'&&$rate['legal_basis']!=='historical_documented') throw new ChargeException('property_charge_historical_basis_required','Historický výpočet před 1. 7. 2020 vyžaduje výslovný dobový právní podklad; současný zákonný klíč nelze předpokládat.');
            if($date>='2020-07-01'&&$rate['legal_basis']==='historical_documented') Input::invalid('legal_basis');
            $rates[$id]=['component_id'=>$id,'unit_ids'=>$units,'method'=>$rate['method'],'mode'=>$rate['mode'],'amount'=>$rate['amount'],'rounding'=>$rate['rounding'],
                'legal_basis'=>$rate['legal_basis'],'scope_ref'=>Input::text($rate['scope_ref']??null,'scope_ref',2000),'correction'=>$rate['correction'],'correction_reason'=>$correction];
        }
        if($rates===[]) Input::invalid('rates');
        ksort($rates,SORT_NUMERIC);
        return ['house_id'=>Input::id($input['house_id']??null,'house_id'),'expected_revision'=>$input['expected_revision'],
            'effective_from'=>$from,'effective_to'=>$to,'basis_at'=>$at,'basis_policy'=>'fixed_registry_snapshot','period_policy'=>'no_automatic_proration',
            'frequency'=>$input['frequency'],'decision_ref'=>Input::text($input['decision_ref']??null,'decision_ref',2000),'decided_at'=>PropertyInstant::normalize($input['decided_at']??null),
            'reason'=>Input::text($input['reason']??null,'reason',4000),'rates'=>array_values($rates)];
    }
}
