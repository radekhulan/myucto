<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Receivables;
final class UnresolvedNegativeChargeScope
{
    public static function accountingDate(array $history,string $date,string $knownAt,?int $unitId=null):array
    {
        return self::ids($history,$unitId,fn($event)=>ReceivableTimeline::accountingDate([$event],$date,$knownAt)['gross_receivable_cents']!=='0');
    }
    public static function at(array $history,string $asOf,string $knownAt,?int $unitId=null):array
    {
        return self::ids($history,$unitId,fn($event)=>ReceivableTimeline::inclusion($event,$asOf,$knownAt)!==false);
    }
    private static function ids(array $history,?int $unitId,callable $include):array
    {
        $ids=[];
        foreach($history['unresolved_negative_charge_lines'] as $line)if(($unitId===null||$unitId===$line['unit_id'])&&$include($line['event']))$ids[]=$line['id'];
        return $ids;
    }
}
