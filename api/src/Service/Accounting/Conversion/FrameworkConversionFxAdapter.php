<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Conversion;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\FrameworkConversionRepository as R;
use MyInvoice\Service\Accounting\Closing\Fx504CaseProjection;
use MyInvoice\Service\Accounting\Conversion\FrameworkConversionException as E;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
final class FrameworkConversionFxAdapter
{
    public function __construct(private readonly Connection $db){}
    public function project(int $supplier,array $period,array $history,array $origins,bool $current=false):array
    {
        $r=new R($this->db);$conversions=$r->rows("SELECT id FROM accounting_framework_conversions WHERE supplier_id=? AND status='applied' AND effective_from<=? ORDER BY effective_from,id",[$supplier,$period['ends_on']],$current);
        $proof=[];
        foreach($conversions as $conversion){$row=$r->receipt($supplier,(int)$conversion['id'],$current);$receipt=$row['receipt'];$source=$r->source($supplier,(int)$row['source_period_id'],(int)$row['target_period_id'],$current);E::need(hash_equals($row['source_hash'],$source['source_hash']),'conversion_source_changed');
            E::need(array_column($receipt['plan']['mapped_cells'],'source')===$r->cells($source),'conversion_cells_changed');
            $ledger=(new PaymentJournalProof($this->db))->snapshot($supplier,(int)$row['opening_entry_id'],$current);E::need($ledger===$receipt['ledger'],'conversion_opening_changed');E::need($receipt['opening_line_ids']===array_column($ledger['lines'],'id')&&PaymentJournalProof::canonicalLines($receipt['lines'])===PaymentJournalProof::canonicalLines($ledger['lines']),'conversion_opening_changed');
            $cells=$r->rows('SELECT * FROM accounting_framework_conversion_cells WHERE supplier_id=? AND conversion_id=? ORDER BY cell_key',[$supplier,$row['id']],$current);$expected=[];foreach($receipt['plan']['mapped_cells'] as $index=>$mapped)$expected[$mapped['source']['cell_key']]=[$mapped['source'],$receipt['lines'][$index*2],$receipt['opening_line_ids'][$index*2]];E::need(count($cells)===count($expected),'conversion_cells_changed');
            foreach($cells as $cell){$sourceCell=json_decode($cell['source_json'],true,512,JSON_THROW_ON_ERROR);$target=json_decode($cell['target_json'],true,512,JSON_THROW_ON_ERROR);E::need(($expected[$cell['cell_key']]??null)===[$sourceCell,$target,(int)$cell['opening_line_id']],'conversion_cells_changed');
                if($sourceCell['currency_code']!=='CZK'){$origin=$sourceCell['origin'];E::need($origin!==null&&$target['account_code']===$sourceCell['account_code'],'conversion_fx_source_account_adoption_required');$origins[(int)$cell['opening_line_id']]=[Fx504CaseProjection::origin($origin)];}
            }
            $history=array_values(array_filter($history,static fn($line)=>$line['entry_date']>=$row['effective_from']));$proof[]=$row['receipt_hash'];
        }
        return ['rows'=>$history,'origins'=>$origins,'proof'=>$proof];
    }
}
