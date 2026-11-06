<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Receivables;
use MyInvoice\Service\Property\PropertyInstant;
final class ReceivableTimeline
{
    public static function inclusion(array $event,string $asOf,string $knownAt):?bool
    {
        $asOf=PropertyInstant::normalize($asOf);$knownAt=PropertyInstant::normalize($knownAt);
        if(PropertyInstant::normalize($event['recorded_at'])>$knownAt)return false;
        if(($event['effective_at']??null)!==null)return PropertyInstant::normalize($event['effective_at'])<=$asOf;
        $date=$event['date'];$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Europe/Prague'));
        if(!$d||$d->format('Y-m-d')!==$date)ReceivableException::reject('history_corrupt');
        $start=PropertyInstant::normalize($d->format('c'));$end=PropertyInstant::normalize($d->modify('+1 day')->format('c'));
        if($asOf<$start)return false;if($asOf>=$end)return true;return null;
    }
    public static function sum(array $events,string $asOf,string $knownAt):array
    {
        PropertyInstant::normalize($asOf);PropertyInstant::normalize($knownAt);
        return self::aggregate($events,fn($event)=>self::inclusion($event,$asOf,$knownAt));
    }
    public static function accountingDate(array $events,string $date,string $knownAt):array
    {
        $valid=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$valid||$valid->format('Y-m-d')!==$date)ReceivableException::reject('invalid_date');$knownAt=PropertyInstant::normalize($knownAt);
        return self::aggregate($events,fn($event)=>PropertyInstant::normalize($event['recorded_at'])<=$knownAt&&$event['date']<=$date);
    }
    private static function aggregate(array $events,callable $include):array
    {
        $amounts=['claim'=>'0','paid'=>'0','credit'=>'0','reserved'=>'0'];$uncertain=[];$ids=[];$precision='instant';
        foreach($events as $event){
            if(isset($ids[$event['id']]))ReceivableException::reject('duplicate_event');$ids[$event['id']]=true;
            if(!array_key_exists($event['kind'],$amounts)||!is_string($event['amount_cents'])||!preg_match('/^-?(?:0|[1-9][0-9]*)$/D',$event['amount_cents']))ReceivableException::reject('history_corrupt');
            $included=$include($event);
            if($included===null){$uncertain[]=$event['id'];$precision='day';continue;}
            if(!$included)continue;
            if(($event['effective_at']??null)===null)$precision='day';
            $amounts[$event['kind']]=bcadd($amounts[$event['kind']],$event['amount_cents'],0);
        }
        $complete=$uncertain===[];
        return ['complete'=>$complete,'precision'=>$precision,'uncertain_event_ids'=>$uncertain,
            'gross_receivable_cents'=>$complete?bcsub($amounts['claim'],$amounts['paid'],0):null,
            'gross_credit_cents'=>$complete?$amounts['credit']:null,'reserved_credit_cents'=>$complete?$amounts['reserved']:null,
            'available_credit_cents'=>$complete?bcsub($amounts['credit'],$amounts['reserved'],0):null];
    }
}
