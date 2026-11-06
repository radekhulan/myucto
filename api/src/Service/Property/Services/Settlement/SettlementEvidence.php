<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Settlement;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
final class SettlementEvidence
{
    public static function detached(array $value):array
    {
        $out=[];foreach($value as $key=>$item){
            if(is_array($item))$out[$key]=self::detached($item);
            elseif($item===null||is_bool($item)||is_int($item)||is_string($item))$out[$key]=$item;
            else PropertyInput::invalid('evidence_type');
        }return $out;
    }
    public static function fields(array $value,array $keys):void
    {
        PropertyInput::fields($value,$keys);
        foreach($keys as $key)if(!array_key_exists($key,$value))PropertyInput::invalid($key);
    }
    public static function cents(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/^(0|[1-9][0-9]{0,29})$/D',$value))PropertyInput::invalid('amount_cents');return $value;
    }
    public static function hash(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/^[a-f0-9]{64}$/D',$value))PropertyInput::invalid('source_hash');return $value;
    }
    public static function date(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value))PropertyInput::invalid('date');
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value)PropertyInput::invalid('date');return $value;
    }
    public static function ids(mixed $value):array
    {
        $ids=PropertyInput::list($value,'party_ids');if($ids===[])PropertyInput::invalid('party_ids');foreach($ids as $id)PropertyInput::id($id,'party_id');
        if(count(array_unique($ids))!==count($ids))PropertyInput::invalid('party_ids');sort($ids,SORT_NUMERIC);return $ids;
    }
    public static function line(array $line):array
    {
        self::fields($line,['account_id','account_code','dimensions','dimension_splits','dimension_origins','dimension_split_origins','cost_center','project_id','currency_code','amount_foreign','fx_rate']);
        PropertyInput::id($line['account_id'],'account_id');if(!is_string($line['account_code'])||!preg_match('/^[A-Za-z0-9.]{3,10}$/D',$line['account_code']))PropertyInput::invalid('account_code');
        foreach(['dimensions','dimension_splits','dimension_origins','dimension_split_origins'] as $key)if(!is_array($line[$key]))PropertyInput::invalid($key);
        foreach($line['dimensions'] as $type=>$value){PropertyInput::id($type,'dimension_type');PropertyInput::id($value,'dimension_value');}
        if($line['project_id']!==null)PropertyInput::id($line['project_id'],'project_id');if($line['cost_center']!==null)PropertyInput::text($line['cost_center'],'cost_center');
        foreach(['currency_code','amount_foreign','fx_rate'] as $key)if($line[$key]!==null)self::reject('fx_unsupported');
        PropertyInput::hash($line);return $line;
    }
    public static function cost(array $cost):array
    {
        self::fields($cost,['source_id','source_hash','amount_cents','line',...(array_key_exists('sources',$cost)?['sources']:[])]);PropertyInput::id($cost['source_id'],'cost_source_id');self::hash($cost['source_hash']);self::cents($cost['amount_cents']);self::line($cost['line']);
        if(array_key_exists('sources',$cost)){$parts=PropertyInput::list($cost['sources'],'cost_sources',100);if(count($parts)<2)self::reject('composite_cost_sources');$sum='0';$last=0;foreach($parts as $part){if(!is_array($part))PropertyInput::invalid('cost_sources');self::fields($part,['source_id','source_hash','amount_cents','line','provenance']);$single=$part;unset($single['provenance']);self::cost($single);if($part['source_id']<=$last||$part['amount_cents']==='0')self::reject('composite_cost_duplicate');$last=$part['source_id'];$provenance=$part['provenance'];self::fields($provenance,['kind','id','source_hash','source_input']);if(!in_array($provenance['kind'],['document','lifecycle'],true))self::reject('composite_cost_kind');PropertyInput::id($provenance['id'],'cost_provenance_id');self::hash($provenance['source_hash']);if(!is_array($provenance['source_input'])||PropertyInput::hash($provenance['source_input'])!==$provenance['source_hash'])self::reject('composite_cost_provenance');$sum=bcadd($sum,$part['amount_cents'],0);}$first=$parts[0];if($cost['source_id']!==$first['source_id']||$cost['source_hash']!==$first['source_hash']||PropertyInput::hash($cost['line'])!==PropertyInput::hash($first['line'])||$sum!==$cost['amount_cents'])self::reject('composite_cost_margin');}
        return $cost;
    }
    public static function costSources(array $cost):array{return $cost['sources']??[$cost];}
    public static function origin(array $source,int $supplier,int $unit,int $service):string
    {
        self::fields($source,['kind','source_period','id','debt_id','supplier_id','unit_id','service_id','prescribed_cents','carried_paid_cents','receivable_cents','opening_advance_component_id','opening_state_hash','receivable_line','advance_line']);
        if(!in_array($source['kind'],['s3_line','opening_component'],true))self::reject('origin_kind');
        self::fields($source['source_period'],['from','to']);foreach(['from','to'] as $key)self::date($source['source_period'][$key]);if($source['source_period']['from']>$source['source_period']['to'])self::reject('origin_period');
        PropertyInput::id($source['id'],'origin_id');if($source['debt_id']!==null)PropertyInput::id($source['debt_id'],'debt_id');
        if($source['supplier_id']!==$supplier||$source['unit_id']!==$unit||$source['service_id']!==$service)self::reject('origin_scope');
        self::cents($source['prescribed_cents']);self::cents($source['carried_paid_cents']);self::cents($source['receivable_cents']);
        if(bcsub($source['prescribed_cents'],$source['carried_paid_cents'],0)!==$source['receivable_cents'])self::reject('opening_basis');
        if($source['kind']==='s3_line'){if($source['carried_paid_cents']!=='0'||$source['opening_advance_component_id']!==null||$source['opening_state_hash']!==null)self::reject('opening_basis');}
        else{PropertyInput::id($source['opening_advance_component_id'],'opening_advance_component_id');self::hash($source['opening_state_hash']);}
        if($source['debt_id']===null&&($source['kind']!=='opening_component'||$source['receivable_cents']!=='0'||$source['id']!==$source['opening_advance_component_id']))self::reject('debt_origin');
        if($source['receivable_line']===null){if($source['debt_id']!==null||$source['receivable_cents']!=='0')self::reject('debt_origin');}else self::line($source['receivable_line']);self::line($source['advance_line']);return $source['kind'].':'.$source['id'];
    }
    public static function entitlement(array $value,array $parties):array
    {
        self::fields($value,['party_ids','evidence','due_date','line']);$value['party_ids']=self::ids($value['party_ids']);
        if(array_diff($value['party_ids'],$parties)!==[])self::reject('foreign_party');PropertyInput::text($value['evidence'],'entitlement.evidence',4000);self::date($value['due_date']);self::line($value['line']);return $value;
    }
    public static function reject(string $reason):never {throw new \DomainException('settlement_'.$reason);}
}
