<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Receivables;
final readonly class PropertyReceivableHistoryByUnit
{
    private array $metadata;
    private array $units;
    public function __construct(array $history)
    {
        $metadata=$history;$units=[];
        foreach(['debts','unregistered_claims','credits','unresolved_negative_charge_lines'] as $collection)$metadata[$collection]=[];
        foreach($history['debts']+$history['unregistered_claims'] as $key=>$row){
            $collection=array_key_exists($key,$history['debts'])?'debts':'unregistered_claims';
            $units[$row['unit_id']][$collection][$key]=$row;
        }
        foreach(['credits','unresolved_negative_charge_lines'] as $collection)foreach($history[$collection] as $key=>$row)$units[$row['unit_id']][$collection][$key]=$row;
        $this->metadata=$metadata;$this->units=$units;
    }
    public function forUnit(int $unitId):array
    {
        return array_replace($this->metadata,$this->units[$unitId]??[]);
    }
}
