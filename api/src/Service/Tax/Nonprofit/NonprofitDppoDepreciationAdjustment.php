<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AssetRepository,DepreciationEntryRepository};
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use PDO;

final class NonprofitDppoDepreciationAdjustment
{
    public function __construct(private readonly Connection $db) {}
    public function build(int $supplier,array $period,array $assessment,array $election):array
    {
        if(array_diff(array_keys($election),['kind','asset_id','tax_entry_id','book_source_ids','tax_deductible_cents','legal_basis','evidence'])!==[]||!is_int($election['asset_id']??null)||!is_int($election['tax_entry_id']??null)||!is_int($election['tax_deductible_cents']??null)||$election['tax_deductible_cents']<0||!is_array($election['book_source_ids']??null)||!array_is_list($election['book_source_ids']))$this->invalid();
        $basis=NonprofitTaxRules::evidence($election['legal_basis']??null);$evidence=NonprofitTaxRules::evidence($election['evidence']??null);$asset=(new AssetRepository($this->db))->find($supplier,$election['asset_id']);$entries=new DepreciationEntryRepository($this->db);$tax=$entries->find($supplier,$election['tax_entry_id']);
        if($asset===null||$tax===null||$tax['asset_id']!==$election['asset_id']||$tax['kind']!=='tax'||$tax['status']!=='confirmed'||$tax['fiscal_year']!==(int)$period['fiscal_year'])$this->invalid();
        $q=$this->db->pdo()->prepare("SELECT id FROM nonprofit_capital_sources WHERE supplier_id=? AND asset_id=? AND kind='investment' ORDER BY id FOR UPDATE");$q->execute([$supplier,$asset['id']]);if($q->fetchColumn()!==false)throw new TaxReturnException('nonprofit_dppo_asset_tax_basis_unsupported','Daňová vstupní cena investičně financovaného majetku vyžaduje samostatný ověřený podklad. Účetní zúčtování901 ji nedokládá.',422);
        $book=$entries->findYear($asset['id'],'accounting',(int)$period['fiscal_year']);if($book===null||$book['supplier_id']!==$supplier||$book['status']!=='posted')$this->invalid();
        $q=$this->db->pdo()->prepare("SELECT id FROM journal_entries WHERE supplier_id=? AND source_type='depreciation' AND source_id=? AND posted_at IS NOT NULL ORDER BY id FOR UPDATE");$q->execute([$supplier,$book['id']]);$journal=$q->fetchAll(PDO::FETCH_COLUMN);if(count($journal)!==1)$this->invalid();$ledger=(new PaymentJournalProof($this->db))->current($supplier,(int)$journal[0]);if($ledger['entry']['reversed_by']!==null||(int)$ledger['entry']['period_id']!==(int)$period['id'])$this->invalid();
        $rows=[];$bookAmount=0;$deductible=0;foreach($assessment['result']['rows']as$row)if(($row['origin']['type']??null)==='depreciation'&&(int)($row['origin']['id']??0)===$book['id']){if($row['kind']!=='expense'||$row['amount_cents']<=0)$this->invalid();$rows[$row['id']]=$row;$bookAmount+=$row['amount_cents'];$deductible+=$row['deductible_cents'];}
        $ids=array_keys($rows);sort($ids,SORT_STRING);$selected=$election['book_source_ids'];sort($selected,SORT_STRING);
        if($ids===[]||$ids!==$selected||$bookAmount!==(int)bcmul((string)$book['amount'],'100',0)||$deductible!==$bookAmount||$election['tax_deductible_cents']>(int)bcmul((string)$tax['amount'],'100',0))$this->invalid();
        $difference=$deductible-$election['tax_deductible_cents'];
        return['kind'=>'depreciation','source_ids'=>$ids,'asset_id'=>$asset['id'],'line'=>$difference>=0?50:150,'amount_cents'=>abs($difference),'legal_basis'=>$basis,'evidence'=>$evidence,'proof'=>['assessment_id'=>$assessment['id'],'input_hash'=>$assessment['input_hash'],'request_hash'=>$assessment['request_hash'],'rows'=>array_values($rows),'asset'=>$asset,'accounting'=>$book,'tax'=>$tax,'ledger'=>$ledger]];
    }
    private function invalid():never
    {throw new TaxReturnException('nonprofit_dppo_depreciation_invalid','Odpisová úprava vyžaduje skutečný potvrzený daňový odpis a úplnou shodnou způsobilou skupinu nativních účetních odpisů. Smíšené použití nebo nedoloženou daňovou vstupní cenu nelze automaticky rozdělit.',422);}
}
