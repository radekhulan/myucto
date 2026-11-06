<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Conversion;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\FrameworkConversionRepository as R;
use MyInvoice\Service\Accounting\Closing\{ClosingSourceId,ProfitDistribution504Builder};
use MyInvoice\Service\Accounting\Conversion\FrameworkConversionException as E;
final class FrameworkConversionPendingOwners
{
    public function __construct(private readonly Connection $db){}
    public function assertSupported(int $supplier,array $source,bool $current):void
    {
        $offbalance=array_values(array_filter($source['native'],static fn($row)=>$row['account_type']==='offbalance'));
        E::need(ProfitDistribution504Builder::cells($offbalance)===[],'conversion_offbalance_adoption_required',409,['required'=>'Explicit offbalance register adoption with each account, title, dimensions and exact quantity.']);
        $period=(int)$source['source']['id'];$r=new R($this->db);
        foreach([['fx_revaluation',ClosingSourceId::fxSaldo($period),'native500_fx_case_carry_and_reversal'],['closing',ClosingSourceId::stockClosing($period),'native_stock_opening_release'],['small_asset_accrual',ClosingSourceId::smallAssetAccrual($period),'native_small_asset_accrual_release']] as [$type,$id,$owner]){
            $rows=$r->rows('SELECT e.id FROM journal_entries e WHERE e.supplier_id=? AND e.source_type=? AND e.source_id=? AND e.posted_at IS NOT NULL AND EXISTS(SELECT 1 FROM journal_entry_lines l WHERE l.supplier_id=e.supplier_id AND l.entry_id=e.id) ORDER BY e.id',[$supplier,$type,$id],$current);
            E::need($rows===[],'conversion_native_followup_required',409,['owner'=>$owner,'entry_ids'=>array_map('intval',array_column($rows,'id')),'required'=>'Original native closing journal and a verified target-framework role/cell adoption protocol for its scheduled follow-up.']);
        }
    }
}
