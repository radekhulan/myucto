<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Funds;
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Funds\FundingException;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService;
use MyInvoice\Tests\Support\FundingFixture;
use PHPUnit\Framework\TestCase;
final class FundingJointCoverageTest extends TestCase
{
    use FundingFixture;
    private function prepareCost():array
    {
        $this->fundingOpening();$cost=$this->fundingCost('2026-03-01','549.900','800.00');$q=$this->db->pdo()->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=?');$q->execute([$this->f['supplier'],$cost]);$source=(new PaymentJournalProof($this->db))->current($this->f['supplier'],(int)$q->fetchColumn());$dims=$source['lines'][0]['dimensions'];
        $entry=$this->container->get(PostingService::class)->postDocument($this->f['supplier'],'manual',null,[['account_code'=>'221','side'=>'debit','amount'=>'1000.00'],['account_code'=>'682','side'=>'credit','amount'=>'1000.00','dimensions'=>$dims,'dimension_origins'=>array_fill_keys(array_keys($dims),['origin'=>'explicit','ref'=>null])]],['entry_date'=>'2026-03-01','posted_by'=>$this->f['actor']]);$q=$this->db->pdo()->prepare("SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? AND side='credit'");$q->execute([$this->f['supplier'],$entry]);return [$cost,(int)$q->fetchColumn(),$dims];
    }
    private function assessment(int $cost,int $gift,?int $correction=null):array
    {
        $tax=$this->container->get(NonprofitTaxAssessmentService::class);$period=$this->container->get(\MyInvoice\Repository\AccountingPeriodRepository::class)->findByYear($this->f['supplier'],2026)['id'];$snapshot=$tax->read($this->f['supplier'],$period)['snapshot'];self::assertSame([],$snapshot['findings']);$a=['profile'=>['kind'=>'svj','evidence'=>'Synthetic owner association tax profile'],'activities'=>[],'decisions'=>[],'funding'=>[['source_id'=>'line:'.$gift,'cost_id'=>'line:'.$cost,'amount_cents'=>40000,'evidence'=>'Synthetic documented independent gift use']]];
        foreach($snapshot['sources']as$row){
            $a['activities'][$row['activity_kind_id']]=['evidence'=>'Synthetic documented source activity'];$owner=$row['svj_owner_facts']??null;
            if($owner!==null){
                $a['decisions'][$row['id']]=$row['kind']==='expense'?['title'=>'cost','deductible_cents'=>0,'legal_basis'=>'zdp_25_1i','evidence'=>'Synthetic native management expense']:['title'=>match($owner['legal_basis']){'zdp_18_2f_2'=>'svj_management_payment','zdp_18_2f_3'=>'svj_service_payment'},'evidence'=>'Synthetic native owner contribution'];
            }else{
                $a['decisions'][$row['id']]=$row['kind']==='expense'?['title'=>'cost','deductible_cents'=>!empty($row['mandatory_non_deductible'])?0:$row['amount_cents'],'legal_basis'=>'zdp_24_1','evidence'=>'Synthetic native expense']:($row['line_id']===$gift?['title'=>'gift','facts'=>['claim_exemption'=>false,'income_treatment'=>'separate_taxable_income','income_attribution_evidence'=>'Synthetic separate taxable gift'],'evidence'=>'Synthetic actual gift']:['title'=>'other_taxable','evidence'=>'Synthetic explicit revenue assessment']);
            }
        }
        if($correction!==null)$a['funding_costs']=[['anchor'=>'line:'.$cost,'members'=>['line:'.$cost,'line:'.$correction],'evidence'=>'Synthetic signed cost correction']];return [$tax,$period,$snapshot,$a];
    }
    public function testS9CannotReuseCostAlreadyClaimedByJ6():void
    {
        [$cost,$gift]=$this->prepareCost();[$tax,$period,$snapshot,$a]=$this->assessment($cost,$gift);$tax->confirm($this->f['supplier'],$period,$a,$snapshot['input_hash'],'Synthetic first J6 financing','j6-before-s9',$this->f['actor']);
        $this->expectException(FundingException::class);$this->expectExceptionMessage('property_funding_capacity');$this->fundingApply($this->fundingDraw('2026-03-01','repair','50000',$cost),'s9-after-j6');
    }
    public function testJ6CannotReuseCostAlreadyClaimedByS9():void
    {
        [$cost,$gift]=$this->prepareCost();$this->fundingApply($this->fundingDraw('2026-03-01','repair','50000',$cost),'s9-before-j6');[$tax,$period,$snapshot,$a]=$this->assessment($cost,$gift);
        $this->expectException(NonprofitTaxAssessmentException::class);$this->expectExceptionMessage('Součet');$tax->confirm($this->f['supplier'],$period,$a,$snapshot['input_hash'],'Synthetic second J6 financing','j6-after-s9',$this->f['actor']);
    }
    public function testS9RespectsJ6SignedCostPoolCapacity():void
    {
        [$cost,$gift,$dims]=$this->prepareCost();$entry=$this->container->get(PostingService::class)->postDocument($this->f['supplier'],'manual',null,[['account_code'=>'549.900','side'=>'debit','amount'=>'100.00','is_red_storno'=>true,'dimensions'=>$dims,'dimension_origins'=>array_fill_keys(array_keys($dims),['origin'=>'explicit','ref'=>null])],['account_code'=>'321','side'=>'credit','amount'=>'100.00','is_red_storno'=>true]],['entry_date'=>'2026-03-01','posted_by'=>$this->f['actor']]);$q=$this->db->pdo()->prepare("SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? AND side='debit'");$q->execute([$this->f['supplier'],$entry]);$negative=(int)$q->fetchColumn();[$tax,$period,$snapshot,$a]=$this->assessment($cost,$gift,$negative);$tax->confirm($this->f['supplier'],$period,$a,$snapshot['input_hash'],'Synthetic corrected cost financing','j6-signed-before-s9',$this->f['actor']);
        $this->expectException(FundingException::class);$this->expectExceptionMessage('property_funding_capacity');$this->fundingApply($this->fundingDraw('2026-03-01','repair','35000',$cost),'s9-after-corrected-j6');
    }

    public static function j6ReceiptShapes():array{return [[false],[true]];}
    #[\PHPUnit\Framework\Attributes\DataProvider('j6ReceiptShapes')]
    public function testS9PreviewExpiresWhenJ6ReceiptChangesWithoutAmountChange(bool $declaredPool):void
    {
        [$cost,$gift]=$this->prepareCost();[$tax,$period,$snapshot,$a]=$this->assessment($cost,$gift);if($declaredPool)$a['funding_costs']=[['anchor'=>'line:'.$cost,'members'=>['line:'.$cost],'evidence'=>'Synthetic explicit costpool title']];$first=$tax->confirm($this->f['supplier'],$period,$a,$snapshot['input_hash'],'Synthetic initial independent funding','j6-cas-first',$this->f['actor']);$request=$this->fundingDraw('2026-03-01','repair','20000',$cost);$owner=new FundingRepository($this->db);$preview=$owner->preview($this->f['supplier'],$request,$this->f['actor']);$a['profile']['evidence']='Synthetic reviewed replacement legal profile evidence';$tax->confirm($this->f['supplier'],$period,$a,$snapshot['input_hash'],'Synthetic legal provenance refresh','j6-cas-refreshed',$this->f['actor'],$first['id']);
        $this->expectException(FundingException::class);$this->expectExceptionMessage('property_funding_preview_stale');$owner->apply($this->f['supplier'],$request,$preview['preview_hash'],'s9-stale-coverage',$this->f['actor']);
    }

    public static function systemAxes():array{return [['activity'],['activity_kind'],['tax_regime']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('systemAxes')]
    public function testFundingCannotLaunderUnknownSystemDimensionOrigin(string $axis):void
    {
        [$cost]=$this->prepareCost();$q=$this->db->pdo()->prepare("UPDATE journal_entry_line_dimensions d JOIN dimension_types t ON t.supplier_id=d.supplier_id AND t.id=d.dimension_type_id SET d.assignment_origin='unknown',d.assignment_origin_ref=NULL WHERE d.supplier_id=? AND d.line_id=? AND t.system_key=?");$q->execute([$this->f['supplier'],$cost,$axis]);self::assertSame(1,$q->rowCount());
        $this->expectException(FundingException::class);$this->expectExceptionMessage('property_funding_cost_coverage_scope');(new FundingRepository($this->db))->preview($this->f['supplier'],$this->fundingDraw('2026-03-01','repair','20000',$cost),$this->f['actor']);
    }
}
