<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Tests\Support\J6CalculatorFactory;
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Tax\Nonprofit\{NonprofitSvjSourceFacts,NonprofitTaxCalculator,NonprofitTaxAssessmentException};
use MyInvoice\Tests\Support\FundingFixture;
use PHPUnit\Framework\TestCase;

final class NonprofitSvjFundingFactsTest extends TestCase
{
    use FundingFixture;
    private function snapshot(int $cost,array $operations,string $end='2026-12-31'):array
    {
        $q=$this->db->pdo()->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=?');$q->execute([$this->f['supplier'],$cost]);$ledger=(new PaymentJournalProof($this->db))->current($this->f['supplier'],(int)$q->fetchColumn());$line=array_column($ledger['lines'],null,'id')[$cost];
        $sources=[['id'=>'line:'.$cost,'kind'=>'expense','line_id'=>$cost,'entry_id'=>$ledger['entry']['id'],'origin'=>['line_id'=>$cost],'amount_cents'=>(int)bcmul($line['amount'],'100',0),'activity_kind_id'=>1]];
        foreach($operations as$op)if($op['accounting_date']<=$end)foreach($op['receipt']['ledger']['lines']as$row)if(str_starts_with($row['account_code'],'649'))$sources[]=['id'=>'line:'.$row['id'],'kind'=>'revenue','line_id'=>$row['id'],'entry_id'=>$op['journal_entry_id'],'origin'=>['line_id'=>$row['id']],'amount_cents'=>(int)bcmul($row['amount'],'100',0)*($row['side']==='credit'?1:-1),'activity_kind_id'=>1];
        return ['legal_profile'=>['profile'=>['kind'=>'svj']],'period'=>['starts_on'=>'2026-01-01','ends_on'=>$end],'sources'=>$sources];
    }
    private function attach(array $snapshot):array{return(new NonprofitSvjSourceFacts($this->db))->attach($this->f['supplier'],$snapshot);}
    private function assessment(array $snapshot,int $costDeductible=0):array
    {
        $a=['profile'=>['kind'=>'svj','evidence'=>'Synthetic SVJ legal identity'],'activities'=>[1=>['evidence'=>'Synthetic documented statutory management']],'decisions'=>[]];
        foreach($snapshot['sources']as$row)$a['decisions'][$row['id']]=$row['kind']==='expense'?['title'=>'cost','deductible_cents'=>$costDeductible,'legal_basis'=>'zdp_25_1i','evidence'=>'Synthetic exact purpose assessment']:['title'=>'svj_management_payment','evidence'=>'Synthetic original owner contributions'];
        return$a;
    }
    private function reverse(array $op,string $date):array{$request=$this->fundingRequest('reverse',$date)+['operation_id'=>$op['id']];unset($request['amount_cents']);return$this->fundingApply($request,'tax-reverse');}
    public function testActualIssuedPartialCoverageDoesNotUnlockManagementCostResidual():void
    {
        $this->fundingIssue();$cost=$this->fundingCost('2026-09-01','518.200','10000.00');$op=$this->fundingApply($this->fundingDraw('2026-09-01','administration','500000',$cost,'issued'),'tax-native-management');
        $snapshot=$this->attach($this->snapshot($cost,[$op]));$facts=$snapshot['sources'][0]['svj_owner_facts']??[];
        self::assertSame(1000000,$facts['related_out_of_scope_cents']??null);self::assertSame(500000,$facts['covered_cost_cents']);
        $out=J6CalculatorFactory::legacy()->calculate($snapshot,$this->assessment($snapshot));self::assertSame(0,$out['assessed_result_cents']);
        foreach(array_slice($out['rows'],1)as$row)self::assertSame(['regime'=>'out_of_scope','method'=>'none','legal_basis'=>'zdp_18_2f_2'],$row['classification']);
        $this->expectException(NonprofitTaxAssessmentException::class);J6CalculatorFactory::legacy()->calculate($snapshot,$this->assessment($snapshot,500000));
    }
    public function testCanonicalAdoptedAdvanceProvesPurposeAndExactOpeningLineage():void
    {
        $this->fundingOpening();$cost=$this->fundingCost('2026-03-01','549.900','10000.00');$op=$this->fundingApply($this->fundingDraw('2026-03-01','repair','500000',$cost),'tax-adopted-management');$snapshot=$this->attach($this->snapshot($cost,[$op]));
        self::assertSame(1000000,$snapshot['sources'][0]['svj_owner_facts']['related_out_of_scope_cents']??null);
        $facts=$snapshot['sources'][1]['svj_owner_facts'];self::assertSame('opening',$facts['advance_sources'][0]['kind']);self::assertNotEmpty($facts['advance_sources'][0]['title_ref']);self::assertNotEmpty($facts['advance_sources'][0]['evidence_ref']);self::assertNotEmpty($facts['advance_sources'][0]['purpose_ref']);
        self::assertSame(0,J6CalculatorFactory::legacy()->calculate($snapshot,$this->assessment($snapshot))['assessed_result_cents']);
    }
    public function testUnownedBalanceAndUnrelatedCostCannotInventContributionProof():void
    {
        $cost=$this->fundingCost('2026-03-01','549.900','10000.00');$snapshot=$this->attach($this->snapshot($cost,[]));self::assertArrayNotHasKey('svj_owner_facts',$snapshot['sources'][0]);
        $snapshot['sources'][]=['id'=>'manual-income','kind'=>'revenue','line_id'=>999999999,'origin'=>['line_id'=>999999999],'amount_cents'=>500000,'activity_kind_id'=>1];$a=$this->assessment($snapshot);
        $this->expectException(NonprofitTaxAssessmentException::class);J6CalculatorFactory::legacy()->calculate($snapshot,$a);
    }
    public function testSameYearDrawReversalDoesNotChangeExpenseLegalPurpose():void
    {
        $this->fundingIssue();$cost=$this->fundingCost('2026-09-01','518.200','10000.00');$op=$this->fundingApply($this->fundingDraw('2026-09-01','administration','500000',$cost,'issued'),'tax-sameyear');$reverse=$this->reverse($op,'2026-10-01');$snapshot=$this->attach($this->snapshot($cost,[$op,$reverse]));
        self::assertSame(1000000,$snapshot['sources'][0]['svj_owner_facts']['related_out_of_scope_cents']??null);self::assertSame(0,$snapshot['sources'][0]['svj_owner_facts']['covered_cost_cents']);self::assertSame(0,J6CalculatorFactory::legacy()->calculate($snapshot,$this->assessment($snapshot))['assessed_result_cents']);
    }
    public function testFutureReversalDoesNotRewriteEarlierFiscalFacts():void
    {
        $this->fundingIssue();$cost=$this->fundingCost('2026-09-01','518.200','10000.00');$op=$this->fundingApply($this->fundingDraw('2026-09-01','administration','500000',$cost,'issued'),'tax-future');$before=$this->attach($this->snapshot($cost,[$op],'2026-09-30'));$this->reverse($op,'2026-10-01');$after=$this->attach($this->snapshot($cost,[$op],'2026-09-30'));
        self::assertSame($before,$after);self::assertSame(500000,$after['sources'][0]['svj_owner_facts']['covered_cost_cents']??null);
    }
    public function testMultipleDrawsDoNotDoubleExcludeTheDirectManagementCost():void
    {
        $this->fundingOpening();$cost=$this->fundingCost('2026-03-01','549.900','10000.00');$one=$this->fundingApply($this->fundingDraw('2026-03-01','repair','200000',$cost),'tax-first');$two=$this->fundingApply($this->fundingDraw('2026-03-02','repair','300000',$cost),'tax-second');$snapshot=$this->attach($this->snapshot($cost,[$one,$two]));
        self::assertSame(1000000,$snapshot['sources'][0]['svj_owner_facts']['related_out_of_scope_cents']??null);self::assertSame(500000,$snapshot['sources'][0]['svj_owner_facts']['covered_cost_cents']);self::assertCount(2,$snapshot['sources'][0]['svj_owner_facts']['funding_sources']);self::assertSame(0,J6CalculatorFactory::legacy()->calculate($snapshot,$this->assessment($snapshot))['assessed_result_cents']);
    }
    public function testReversedDrawStillRejectsChangedPhysicalOriginalCost():void
    {
        $this->fundingIssue();$cost=$this->fundingCost('2026-09-01','518.200','10000.00');$op=$this->fundingApply($this->fundingDraw('2026-09-01','administration','500000',$cost,'issued'),'tax-tamper');$reverse=$this->reverse($op,'2026-10-01');$snapshot=$this->snapshot($cost,[$op,$reverse]);$this->db->pdo()->prepare('UPDATE journal_entry_lines SET amount=amount+1 WHERE supplier_id=? AND id=?')->execute([$this->f['supplier'],$cost]);
        $this->expectException(NonprofitTaxAssessmentException::class);$this->attach($snapshot);
    }
    public function testPartialJointProjectionRequiresExactNativePurposeAllocation():void
    {
        $this->fundingOpening();$cost=$this->fundingCost('2026-03-01','549.900','10000.00');$op=$this->fundingApply($this->fundingDraw('2026-03-01','repair','500000',$cost),'tax-child');$snapshot=$this->snapshot($cost,[$op]);$snapshot['sources'][0]['amount_cents']=700000;$snapshot['sources'][0]['line_id']=999999999;$snapshot['sources'][0]['id']='line:999999999';
        $this->expectException(NonprofitTaxAssessmentException::class);$this->attach($snapshot);
    }
    public function testNativeSeparatelyDocumentedTaxableCostRemainsDeductibleWithoutDoubleExclusion():void
    {
        $this->fundingIssue();$cost=$this->fundingCost('2026-09-01','518.200','7000.00');$op=$this->fundingApply($this->fundingDraw('2026-09-01','administration','500000',$cost,'issued'),'tax-separated');$other=$this->fundingCost('2026-09-01','518','3000.00');$snapshot=$this->snapshot($cost,[$op]);$extra=$this->snapshot($other,[])['sources'][0];$extra['activity_kind_id']=2;$snapshot['sources'][]=$extra;$snapshot=$this->attach($snapshot);$a=$this->assessment($snapshot);$a['activities'][2]=['evidence'=>'Synthetic separately recorded work for own taxable income'];$a['decisions'][$extra['id']]=['title'=>'cost','deductible_cents'=>300000,'legal_basis'=>'zdp_24_1','evidence'=>'Synthetic separately invoiced taxable collection work'];$out=J6CalculatorFactory::legacy()->calculate($snapshot,$a);
        self::assertSame(-300000,$out['assessed_result_cents']);$rows=array_column($out['rows'],null,'id');self::assertSame(300000,$rows[$extra['id']]['deductible_cents']);self::assertSame(0,$rows['line:'.$cost]['deductible_cents']);self::assertSame(0,$rows['line:'.$cost]['funded_excluded_cents']);
    }
    public function testForeignTenantCannotAttachOwnerProofFromMatchingForeignLineIds():void
    {
        $this->fundingOpening();$cost=$this->fundingCost('2026-03-01','549.900','10000.00');$op=$this->fundingApply($this->fundingDraw('2026-03-01','repair','500000',$cost),'tax-tenant');$snapshot=$this->snapshot($cost,[$op]);$q=$this->db->pdo()->prepare('SELECT id FROM supplier WHERE id<>? ORDER BY id LIMIT 1');$q->execute([$this->f['supplier']]);$other=(int)$q->fetchColumn();self::assertGreaterThan(0,$other);$actual=(new NonprofitSvjSourceFacts($this->db))->attach($other,$snapshot);foreach($actual['sources']as$row)self::assertArrayNotHasKey('svj_owner_facts',$row);
    }

    public function testRehashedReceiptCannotReplaceTheNativePhysicalLedgerProof():void
    {
        $this->fundingOpening();$cost=$this->fundingCost('2026-03-01','549.900','10000.00');$op=$this->fundingApply($this->fundingDraw('2026-03-01','repair','500000',$cost),'tax-rehash');$snapshot=$this->snapshot($cost,[$op]);$receipt=$op['receipt'];$receipt['ledger']['lines'][0]['amount']='1.00';$this->db->pdo()->prepare('UPDATE property_funding_operations SET receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=?')->execute([json_encode($receipt,JSON_THROW_ON_ERROR),\MyInvoice\Service\Property\PropertyInput::hash($receipt),$this->f['supplier'],$op['id']]);
        try{$this->attach($snapshot);self::fail('Rehashed financial corruption must fail');}catch(NonprofitTaxAssessmentException $e){self::assertSame('nonprofit_tax_svj_source_changed',$e->errorCode);self::assertSame(409,$e->httpStatus);}
    }
    public function testCrossFiscalReversalRemainsAnExcludedNegativeContribution():void
    {
        $this->fundingIssue();$cost=$this->fundingCost('2026-09-01','518.200','10000.00');$op=$this->fundingApply($this->fundingDraw('2026-09-01','administration','500000',$cost,'issued'),'tax-fiscal');$s=$this->f['supplier'];$this->db->pdo()->prepare("UPDATE accounting_periods SET ends_on='2026-09-30' WHERE supplier_id=? AND fiscal_year=2026")->execute([$s]);(new \MyInvoice\Repository\AccountingPeriodRepository($this->db))->create($s,2027,'2026-10-01','2027-09-30');$before=$this->attach($this->snapshot($cost,[$op],'2026-09-30'));$reverse=$this->reverse($op,'2026-10-01');self::assertSame($before,$this->attach($this->snapshot($cost,[$op],'2026-09-30')));
        $snapshot=$this->snapshot($cost,[$reverse],'2027-09-30');$snapshot['period']['starts_on']='2026-10-01';array_shift($snapshot['sources']);$snapshot=$this->attach($snapshot);$a=$this->assessment($snapshot);$a['profile']=['kind'=>'documented','entity_kind'=>'svj','evidence'=>'Synthetic SVJ identity'];$a['method']=['mode'=>'documented_manual','valid_from'=>'2026-10-01','valid_to'=>'2027-09-30','legal_basis'=>'Synthetic documented law in this fiscal period','evidence'=>'Synthetic signed historical review'];foreach($a['decisions']as&$decision)$decision+=['final_regime'=>'out_of_scope','final_method'=>'none','legal_basis'=>'zdp_18_2f_2'];unset($decision);$out=J6CalculatorFactory::legacy()->calculate($snapshot,$a);self::assertSame(0,$out['assessed_result_cents']);self::assertLessThan(0,$out['accounting_result_cents']);foreach($out['rows']as$row)self::assertSame('out_of_scope',$row['classification']['regime']);
    }

}
