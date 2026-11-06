<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Receivables;
use MyInvoice\Service\Property\PropertyInput;
final class PropertySaldoCursor
{
    public static function encode(int $supplier,string $asOf,string $knownAt,int $after):string
    {
        return rtrim(strtr(base64_encode(json_encode(['v'=>1,'supplier_id'=>$supplier,'as_of'=>$asOf,'known_at'=>$knownAt,'after_id'=>$after],JSON_THROW_ON_ERROR)),'+/','-_'),'=');
    }
    public static function decode(?string $cursor,int $supplier,string $asOf,string $knownAt):int
    {
        if($cursor===null)return 0;
        if(strlen($cursor)>1000||!preg_match('/^[A-Za-z0-9_-]+$/D',$cursor))PropertyInput::invalid('cursor');
        try{$value=json_decode(base64_decode(strtr($cursor,'-_','+/'),true),true,8,JSON_THROW_ON_ERROR);}catch(\Throwable){PropertyInput::invalid('cursor');}
        if(!is_array($value)||array_keys($value)!==['v','supplier_id','as_of','known_at','after_id']||$value['v']!==1||$value['supplier_id']!==$supplier||$value['as_of']!==$asOf||$value['known_at']!==$knownAt)PropertyInput::invalid('cursor');
        return PropertyInput::id($value['after_id'],'cursor');
    }
}
