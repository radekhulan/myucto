<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Capital;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
final class CapitalPolicy
{
    public static function normalize(array $data):array
    {
        $fields=['kind','source_line_id','asset_id','provider_id','revenue_tax_regime_id','title','purpose','evidence','decision_authority','valid_from','valid_to','activity_kind_ids','account_prefixes'];
        if(array_diff(array_keys($data),$fields)!==[]||array_diff($fields,array_keys($data))!==[])self::invalid('Zdroj vyžaduje právě podporovaná pole.');
        if(!in_array($data['kind'],['investment','fund'],true)||!is_int($data['source_line_id'])||$data['source_line_id']<=0)self::invalid('Neplatný druh nebo účetní řádek zdroje.');
        if(!is_int($data['revenue_tax_regime_id'])||$data['revenue_tax_regime_id']<=0)self::invalid('Výnos vyžaduje výslovné zařazení daňového režimu.');
        foreach(['asset_id','provider_id']as$key)if($data[$key]!==null&&(!is_int($data[$key])||$data[$key]<=0))self::invalid('Neplatná vazba zdroje.');
        if(($data['kind']==='investment'&&($data['asset_id']===null||$data['provider_id']===null))||($data['kind']==='fund'&&$data['asset_id']!==null))self::invalid('Investiční zdroj vyžaduje vlastní majetek a poskytovatele, fond se nevede jako investiční zdroj.');
        foreach(['title','purpose','evidence','decision_authority']as$key)if(!is_string($data[$key])||trim($data[$key])===''||mb_strlen($data[$key])>2000)self::invalid('Chybí doložený účel a oprávněné rozhodnutí.');
        foreach(['valid_from','valid_to']as$key)self::date($data[$key]);
        if($data['valid_from']>$data['valid_to'])self::invalid('Období způsobilosti je obrácené.');
        foreach(['activity_kind_ids','account_prefixes']as$key)if(!is_array($data[$key])||!array_is_list($data[$key])||$data[$key]===[]||count($data[$key])>1000||count(array_unique($data[$key],SORT_REGULAR))!==count($data[$key]))self::invalid('Zdroj vyžaduje seznam způsobilých činností a účtů bez duplicit.');
        foreach($data['activity_kind_ids']as$id)if(!is_int($id)||$id<=0)self::invalid('Neplatný druh činnosti.');
        foreach($data['account_prefixes']as$code)if(!is_string($code)||preg_match('/^5[0-9]{2}(?:[.0-9A-Za-z]{0,7})$/D',$code)!==1)self::invalid('Způsobilost vyžaduje konkrétní nákladové účty.');
        sort($data['activity_kind_ids'],SORT_NUMERIC);sort($data['account_prefixes'],SORT_STRING);return $data;
    }
    public static function assertCost(array $policy,array $cost):void
    {
        $eligible=false;foreach($policy['account_prefixes']as$prefix)if(str_starts_with($cost['account_code'],$prefix))$eligible=true;
        if(!$eligible||$cost['side']!=='debit'||$cost['amount_cents']<=0||$cost['date']<$policy['valid_from']||$cost['date']>$policy['valid_to']||!in_array($cost['activity_kind_id'],$policy['activity_kind_ids'],true))self::invalid('Náklad nesplňuje doložené podmínky zdroje.','nonprofit_capital_cost_ineligible');
    }
    public static function date(mixed $date):void
    {
        if(!is_string($date)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$date,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))self::invalid('Neplatné datum operace.');
    }
    public static function key(string $key):void
    {
        if(preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,79}$/D',$key)!==1)self::invalid('Neplatný identifikátor požadavku.');
    }
    public static function proportional(int $cumulative,int $funding,int $basis):int
    {
        if($cumulative<0||$funding<=0||$basis<=0||$funding>$basis)self::invalid('Neplatný poměr investičního financování.');
        $value=bcdiv(bcmul((string)$cumulative,(string)$funding,0),(string)$basis,2);
        return min($funding,(int)bcround($value,0));
    }
    public static function hash(array $data):string{return NonprofitTaxRules::hash($data);}
    public static function invalid(string $message,string $code='nonprofit_capital_invalid'):never{throw new CapitalException($code,$message,422);}
    public static function changed():never{throw new CapitalException('nonprofit_capital_changed','Účetní podklady nebo potvrzený stav zdroje se změnily. Vyžaduje se nová kontrola.',409);}
}
