<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Nonprofit;
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Service\Accounting\Funding\CostCoverage;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Nonprofit\Capital\CapitalService;
use MyInvoice\Service\Nonprofit\Capital\CapitalLedger;
use MyInvoice\Service\Nonprofit\Capital\CapitalException;
use MyInvoice\Service\Property\Funds\FundingException;
use MyInvoice\Tests\Support\FundingFixture;
use PHPUnit\Framework\TestCase;
final class CapitalS9NativeTest extends TestCase
{
    use FundingFixture;
    private function capitalSource(int $cost):array
    {
        $s=$this->f['supplier'];$posting=$this->container->get(PostingService::class);$entry=$posting->postDocument($s,'manual',null,[['account_code'=>'221','side'=>'debit','amount'=>'1000.00'],['account_code'=>'911','side'=>'credit','amount'=>'1000.00']],['entry_date'=>'2026-01-01','description'=>'Synthetic actual statutory fund','posted'=>true,'posted_by'=>$this->f['actor']]);
        $q=$this->db->pdo()->prepare("SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? AND a.account_code='911'");$q->execute([$s,$entry]);$line=(int)$q->fetchColumn();$costProof=(new CapitalLedger($this->db))->cost($s,$cost);$cat=(new \MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog($this->db))->ensureForFramework($s,'2026-01-01');
        $policy=['kind'=>'fund','source_line_id'=>$line,'asset_id'=>null,'provider_id'=>$this->f['clients'][1],'revenue_tax_regime_id'=>$cat['tax_regime']['values']['subject'],'title'=>'Synthetic actual statutory fund911','purpose'=>'Synthetic property maintenance separate from owner advances','evidence'=>'Synthetic approved statutory fund rules','decision_authority'=>'Synthetic governing authority','valid_from'=>'2026-01-01','valid_to'=>'2026-12-31','activity_kind_ids'=>[$costProof['activity_kind_id']],'account_prefixes'=>['549.900']];
        $capital=new CapitalService($this->db,$posting);$preview=$capital->previewSource($s,$policy);return [$capital,$capital->confirmSource($s,$policy,$preview['input_hash'],'Synthetic explicit true fund title','capital-source',$this->f['actor'])];
    }
    public function test911ThenS9CannotSpendTheSameRemainingCostTwice():void
    {
        $this->fundingOpening();$cost=$this->fundingCost('2026-03-01','549.900','800.00');[$capital,$source]=$this->capitalSource($cost);$s=$this->f['supplier'];$p=$capital->previewFund($s,$source['id'],$cost,40000,'2026-03-01');$capital->confirmFund($s,$source['id'],$cost,40000,'2026-03-01',$p['input_hash'],'Synthetic actual911 coverage','capital-use',$this->f['actor']);
        $r=$this->fundingDraw('2026-03-01','repair','40001',$cost);
        try{(new FundingRepository($this->db))->preview($s,$r,$this->f['actor']);self::fail('S9 must include actual911648 coverage');}catch(FundingException $e){self::assertSame('property_funding_capacity',$e->errorCode);}
        $this->fundingApply($this->fundingDraw('2026-03-01','repair','40000',$cost),'remaining-s9');self::assertSame('80000',(new CostCoverage($this->db))->claimed($s,$cost));
    }
    public function testS9Then911CannotSpendTheSameRemainingCostTwice():void
    {
        $this->fundingOpening();$cost=$this->fundingCost('2026-03-01','549.900','800.00');[$capital,$source]=$this->capitalSource($cost);$s=$this->f['supplier'];$this->fundingApply($this->fundingDraw('2026-03-01','repair','60000',$cost),'s9-first');
        try{$capital->previewFund($s,$source['id'],$cost,20001,'2026-03-01');self::fail('911 must include earlier actual955649 coverage');}catch(CapitalException $e){self::assertSame('nonprofit_capital_cost_overcovered',$e->errorCode);}
        $p=$capital->previewFund($s,$source['id'],$cost,20000,'2026-03-01');$capital->confirmFund($s,$source['id'],$cost,20000,'2026-03-01',$p['input_hash'],'Synthetic remaining true fund use','capital-remaining',$this->f['actor']);self::assertSame('80000',(new CostCoverage($this->db))->claimed($s,$cost));
    }
}
