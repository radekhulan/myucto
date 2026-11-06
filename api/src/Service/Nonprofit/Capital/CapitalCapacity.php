<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Capital;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
final class CapitalCapacity
{
    public function __construct(private readonly Connection $db) {}
    public function coveredCost(int $supplier,int $line):string
    {
        $total='0';foreach($this->costClaims($supplier,$line)as$claim)$total=bcadd($total,$claim['amount_cents'],0);return $total;
    }
    public function costClaims(int $supplier,int $line):array
    {
        $guard=new DimensionPeriodGuard($this->db);
        return $guard->transaction(function()use($guard,$supplier,$line):array{
            $guard->lockPeriods($supplier);$claims=[];$service=new CapitalService($this->db);$repo=new CapitalRepository($this->db);
            foreach($service->canonicalEvents($supplier)as$event)if($event['active']&&($event['cost_line_id']??$event['snapshot']['depreciation']['cost']['id']??null)===$line){$command=$repo->command($supplier,$event['command_id']);$claims[]=['event_id'=>$event['id'],'source_id'=>$event['source_id'],'command_id'=>$event['command_id'],'entry_id'=>$event['entry_id'],'cost_line_id'=>$line,'amount_cents'=>(string)$event['amount_cents'],'record_hash'=>$event['record_hash'],'request_hash'=>$command['request_hash'],'receipt_hash'=>CapitalPolicy::hash($event['receipt'])];}
            return $claims;
        });
    }
}
