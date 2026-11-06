<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Tax;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\Tax\Return\{TaxReturnService,TaxReturnException};
use MyInvoice\Service\Tax\Nonprofit\{NonprofitDppoStoredProof,NonprofitTaxSavingsService};
use PHPUnit\Framework\TestCase;

final class NonprofitDppoStoredArchiveGuardTest extends TestCase
{
    private NonprofitDppoNativeWorkflowTest $workflow;private NonprofitDppoDataProviderTest $fixture;private Connection $db;private \DI\Container $container;private int $supplier;private int $period;private int $actor;
    protected function setUp():void
    {
        $this->workflow=new NonprofitDppoNativeWorkflowTest('testNativeFinalizationStoresActual504XmlAndOnlyActualTaxSaving');(new \ReflectionMethod($this->workflow,'setUp'))->invoke($this->workflow);
        foreach(['fixture','db','container','supplier','period','actor']as$key)$this->$key=(new \ReflectionProperty($this->workflow,$key))->getValue($this->workflow);
    }
    protected function tearDown():void
    {(new \ReflectionMethod($this->workflow,'tearDown'))->invoke($this->workflow);}
    private function savingFixture():array
    {
        $inputs=['nonprofit'=>['reduction_kc'=>300000,'reduction_evidence'=>'Synthetic loss-free saved archive election']];
        $data=$this->container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitDppoDataProvider::class)->gather($this->supplier,2026,$inputs);
        $constants=\MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $computed=(new \MyInvoice\Service\Tax\Return\DppoReturnCalculator())->compute($data,$inputs,$constants);
        $data['loss_appendix']=\MyInvoice\Service\Tax\Return\DppoLossAppendix::build($this->supplier,'po',2026,$data['period'],[],0,0,5);$data['source_manifest']['loss_appendix_hash']=$data['loss_appendix']['proof_hash'];
        $record=NonprofitDppoStoredProof::capture($data,$inputs,$constants,$this->container->get(TaxReturnService::class)->nonprofitEntityProof($this->supplier,$this->period));
        $manifest=$data['source_manifest']+['nonprofit_filing_proof_hash'=>$record['filing_proof_hash'],'nonprofit_data_manifest_hash'=>\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules::hash($data['source_manifest'])];
        $snapshot=['computed'=>$computed,'podklady'=>['accounting_decree'=>'504','period'=>$data['period'],'nonprofit_calculation'=>$record]];
        $built=$this->container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitDppoArchiveBuilder::class)->build($record,$computed);
        self::assertSame('passed',(new \MyInvoice\Service\Validation\XmlSchemaValidator())->validate($built['xml'],'dppdp9')['status']);
        NonprofitDppoStoredProof::verify($snapshot,$manifest,$built['xml']);
        $repo=new TaxReturnRepository($this->db);$row=$repo->create($this->supplier,2026,'po',$inputs,$this->actor);
        $snapshotId=$repo->createSnapshot($row['id'],$this->supplier,$snapshot,$manifest,$built['xml'],'passed',[],$this->actor);
        $repo->finalize($this->supplier,2026,'po',$snapshot,$row['row_version'],snapshotId:$snapshotId,finalizedBy:$this->actor);
        (new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsLedger($this->db))->registerFinal($this->supplier,$snapshotId,$this->actor);
        $periods=new \MyInvoice\Repository\AccountingPeriodRepository($this->db);$next=$periods->create($this->supplier,2027,'2026-07-01','2027-06-30');$legal=new \MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService($this->db);$profile=$legal->read($this->supplier,$this->period)['latest']['profile'];unset($profile['ruleset'],$profile['reduction_band']);$legal->confirm($this->supplier,$next,['expected_revision'=>0,'idempotency_key'=>'next-legal','reason'=>'Synthetic next-period legal review','profile'=>$profile],$this->actor);
        $catalog=(new \MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier,'2026-08-01');$kind=(new \ReflectionProperty($this->fixture,'kind'))->getValue($this->fixture);$dims=[$catalog['activity']['id']=>$catalog['activity']['values']['main'],$catalog['activity_kind']['id']=>$kind,$catalog['tax_regime']['id']=>$catalog['tax_regime']['values']['subject']];$origins=array_fill_keys(array_keys($dims),['origin'=>'explicit','ref'=>null]);
        $entry=$this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier,'manual',null,[['account_code'=>'518','side'=>'debit','amount'=>'100000.00','dimensions'=>$dims,'dimension_origins'=>$origins],['account_code'=>'221','side'=>'credit','amount'=>'100000.00','dimensions'=>$dims,'dimension_origins'=>$origins]],['entry_date'=>'2026-08-01','posted_by'=>$this->actor]);
        $j6=$this->container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService::class);$snapshot=$j6->read($this->supplier,$next)['snapshot'];$a=['profile'=>['kind'=>'documented','entity_kind'=>'narrow_vpp','evidence'=>'Synthetic supported legal form'],'method'=>['mode'=>'documented_manual','valid_from'=>'2026-07-01','valid_to'=>'2027-06-30','evidence'=>'Synthetic documented future-period classification','legal_basis'=>'zdp_18a_24_25'],'activities'=>[$kind=>['evidence'=>'Synthetic statutory nonbusiness activity']],'decisions'=>[],'funding'=>[]];
        foreach($snapshot['sources']as$row)$a['decisions'][$row['id']]=['title'=>'nonbusiness_cost','deductible_cents'=>0,'final_regime'=>'subject','evidence'=>'Synthetic qualifying statutory expense','legal_basis'=>'zdp_25'];$j6->confirm($this->supplier,$next,$a,$snapshot['input_hash'],'Synthetic qualified-cost review','next-j6',$this->actor);
        $line=(int)$this->db->pdo()->query('SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts c ON c.id=l.account_id WHERE l.entry_id='.(int)$entry." AND c.account_code='518'")->fetchColumn();$source=(new NonprofitTaxSavingsService($this->db))->context($this->supplier)['sources'][0];return compact('entry','line','next','source');
    }
    private function appendHistoricalUse(array $f):array
    {
        $owner=new NonprofitTaxSavingsService($this->db);$ledger=new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsLedger($this->db);$source=$f['source']['source'];
        $input=['cost_line_id'=>$f['line'],'amount_cents'=>'2000000','purpose'=>'nonbusiness','evidence'=>'Synthetic historical qualified cost-use'];
        $assessment=$this->container->get(\MyInvoice\Repository\NonprofitTaxAssessmentRepository::class)->latest($this->supplier,$f['next'],true);
        $row=array_column($assessment['result']['rows'],null,'line_id')[$f['line']];
        $capacity=(new \MyInvoice\Service\Accounting\Funding\CostCoverage($this->db))->capacity($this->supplier,$f['line']);
        $proof=['cost_native'=>(new \MyInvoice\Service\Accounting\Funding\NativeCostCoverage($this->db))->financingCost($this->supplier,$f['line']),
            'assessment'=>['id'=>$assessment['id'],'input_hash'=>$assessment['input_hash'],'request_hash'=>$assessment['request_hash'],'row'=>$row],'legal_profile'=>$assessment['snapshot']['legal_profile'],
            'purpose'=>$input['purpose'],'evidence'=>$input['evidence'],'cost_capacity_hash'=>\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules::hash($capacity),'cost_capacity'=>$capacity];
        $plan=['operation'=>'use','source_id'=>$source['id'],'source_hash'=>$source['record_hash'],'head_hash'=>$source['record_hash'],'period_id'=>$f['next'],'cost_line_id'=>$f['line'],'amount_cents'=>$input['amount_cents'],'proof'=>$proof];
        $request=['operation'=>'use','source_id'=>$source['id'],'input'=>$input,'preview_hash'=>\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules::hash($plan),'expected_head_hash'=>$source['record_hash']];
        return(new \ReflectionMethod($owner,'append'))->invoke($owner,$this->supplier,$request,'synthetic-historical-use',$this->actor,$f['next'],$f['line'],$input['amount_cents'],null,$proof);
    }
    public function testStoredHistoricalUseClaimsCapacityAndCanBeReversedWithExactReceipt():void
    {
        $f=$this->savingFixture();$use=$this->appendHistoricalUse($f);$owner=new NonprofitTaxSavingsService($this->db);
        $capacity=new \MyInvoice\Service\Accounting\Funding\CostCoverage($this->db);self::assertSame('2000000',$capacity->claimed($this->supplier,$f['line']));self::assertCount(1,$capacity->capacity($this->supplier,$f['line'])['claims']['tax_saving']);
        $reverse=$owner->previewReverse($this->supplier,$f['source']['source']['id'],$use['id'],'Synthetic corrected historical cost-use');
        $event=$owner->confirmReverse($this->supplier,$f['source']['source']['id'],$use['id'],'Synthetic corrected historical cost-use',$reverse['preview_hash'],$reverse['head_hash'],'historical-saving-reverse',$this->actor);
        self::assertSame('reverse_use',$event['operation']);self::assertSame('0',$capacity->claimed($this->supplier,$f['line']));
    }
    public function testUsedCostRejectsActualNativeMutationAndAllowsNativeMarkPostedNoop():void
    {
        $f=$this->savingFixture();$this->appendHistoricalUse($f);
        $journal=$this->container->get(\MyInvoice\Repository\JournalEntryRepository::class);self::assertFalse($journal->markPosted($f['entry'],$this->supplier,$this->actor));
        try{$this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($this->supplier,$f['entry'],['entry_date'=>'2026-08-02','posted_by'=>$this->actor]);self::fail('A used expense was reversed');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('nonprofit_tax_saving_cost_in_use',$e->errorCode);}
        self::assertTrue($this->db->pdo()->inTransaction());self::assertSame('2000000',(new \MyInvoice\Service\Accounting\Funding\CostCoverage($this->db))->claimed($this->supplier,$f['line']));
    }
    public function testNativeReversalAloneRejectsHistoricalUse():void
    {
        $f=$this->savingFixture();$this->appendHistoricalUse($f);
        try{$this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($this->supplier,$f['entry'],['entry_date'=>'2026-08-02','posted_by'=>$this->actor]);self::fail('A used expense was reversed');}
        catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('nonprofit_tax_saving_cost_in_use',$e->errorCode);}
    }
    public function testNativeDimensionChangeRejectsHistoricalUse():void
    {
        $f=$this->savingFixture();$this->appendHistoricalUse($f);$assignments=new \MyInvoice\Repository\DimensionAssignmentRepository($this->db);$before=$assignments->lineDimensions($this->supplier,[$f['line']]);
        try{$assignments->replaceLineDimensions($this->supplier,$f['line'],[]);self::fail('Dimensions of a used expense were removed');}
        catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('nonprofit_tax_saving_cost_in_use',$e->errorCode);}
        self::assertSame($before,$assignments->lineDimensions($this->supplier,[$f['line']]));
    }
    public function testReversedHistoricalUseAllowsNativeReplaceAndKeepsExactReceiptAndSource():void
    {
        $f=$this->savingFixture();$use=$this->appendHistoricalUse($f);$owner=new NonprofitTaxSavingsService($this->db);$source=$f['source']['source'];
        $preview=$owner->previewReverse($this->supplier,$source['id'],$use['id'],'Synthetic corrected historical cost-use');$owner->confirmReverse($this->supplier,$source['id'],$use['id'],'Synthetic corrected historical cost-use',$preview['preview_hash'],$preview['head_hash'],'historical-fk-reverse',$this->actor);
        $ledger=new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsLedger($this->db);$before=$ledger->events($this->supplier,$source['id']);$sourceBefore=$ledger->source($this->supplier,$source['id']);self::assertCount(2,$before);$raw=$this->db->pdo()->prepare('SELECT * FROM nonprofit_tax_saving_events WHERE supplier_id=? AND source_id=? ORDER BY id');$raw->execute([$this->supplier,$source['id']]);$bytesBefore=$raw->fetchAll(\PDO::FETCH_ASSOC);
        $assessment=$this->container->get(\MyInvoice\Repository\NonprofitTaxAssessmentRepository::class)->latest($this->supplier,$f['next'],true);$this->container->get(\MyInvoice\Service\Tax\Nonprofit\J6FundingCapacity::class)->assertCurrentReceipt($this->supplier,$assessment);
        (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockPeriods($this->supplier);$journal=$this->container->get(\MyInvoice\Repository\JournalEntryRepository::class);$entry=$journal->findForUpdate($f['entry'],$this->supplier);$assignments=new \MyInvoice\Repository\DimensionAssignmentRepository($this->db);$ids=array_column($entry['lines'],'id');$dims=$assignments->lineDimensions($this->supplier,$ids,true);$origins=$assignments->lineOrigins($this->supplier,$ids,true);$lines=$entry['lines'];
        foreach($lines as&$line){$line['amount']='90000.00';$line['dimensions']=$dims[$line['id']]??[];$line['dimension_origins']=$origins[$line['id']]??[];}unset($line);
        $journal->replace($f['entry'],$entry,$lines);$after=$journal->findForUpdate($f['entry'],$this->supplier);self::assertNotContains($f['line'],array_column($after['lines'],'id'));self::assertSame(90000.0,$after['lines'][0]['amount']);
        self::assertSame($before,$ledger->events($this->supplier,$source['id']));self::assertSame($sourceBefore,$ledger->source($this->supplier,$source['id']));self::assertSame($f['line'],$before[0]['cost_line_id']);self::assertSame($f['line'],$before[1]['cost_line_id']);self::assertSame([],$ledger->costClaims($this->supplier,$f['line']));$raw->execute([$this->supplier,$source['id']]);self::assertSame($bytesBefore,$raw->fetchAll(\PDO::FETCH_ASSOC));
        $this->container->get(\MyInvoice\Service\Tax\Nonprofit\J6FundingCapacity::class)->assertHistoricalReceipt($this->supplier,$assessment);
        try{$this->container->get(\MyInvoice\Service\Tax\Nonprofit\J6FundingCapacity::class)->assertCurrentReceipt($this->supplier,$assessment);self::fail('Old J6 receipt became current after native replacement');}catch(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException $e){self::assertSame('nonprofit_tax_funding_changed',$e->errorCode);}
        $classification=$dims[$f['line']];$newEntry=$this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier,'manual',null,[['account_code'=>'518','side'=>'debit','amount'=>'10.00','dimensions'=>$classification,'dimension_origins'=>$origins[$f['line']]],['account_code'=>'221','side'=>'credit','amount'=>'10.00','dimensions'=>$classification,'dimension_origins'=>$origins[$f['line']]]],['entry_date'=>'2026-08-02','posted_by'=>$this->actor]);self::assertGreaterThan(0,$newEntry);self::assertNotNull($journal->findForUpdate($newEntry,$this->supplier)['posted_at']);
    }
    public function testActiveBindingRestrictsNativeLineDeletionAndCannotBeReleasedBeforeExactReversal():void
    {
        $f=$this->savingFixture();$use=$this->appendHistoricalUse($f);$pdo=$this->db->pdo();$q=$pdo->prepare('SELECT cost_line_id FROM nonprofit_tax_saving_cost_bindings WHERE supplier_id=? AND event_id=?');$q->execute([$this->supplier,$use['id']]);self::assertSame($f['line'],(int)$q->fetchColumn());
        try{$pdo->prepare('DELETE FROM journal_entry_lines WHERE supplier_id=? AND id=?')->execute([$this->supplier,$f['line']]);self::fail('Active use lost its native cost line');}catch(\PDOException $e){self::assertSame(1451,(int)$e->errorInfo[1]);}
        try{$pdo->prepare('DELETE FROM nonprofit_tax_saving_cost_bindings WHERE supplier_id=? AND event_id=?')->execute([$this->supplier,$use['id']]);self::fail('Unreversed use lost its active binding');}catch(\PDOException $e){self::assertSame('45000',$e->getCode());self::assertStringContainsString('nonprofit_tax_saving_binding_in_use',$e->getMessage());}
        self::assertSame('2000000',(new \MyInvoice\Service\Accounting\Funding\CostCoverage($this->db))->claimed($this->supplier,$f['line']));
    }
    public function testCanonicalReceiptRejectsMissingActiveBindingWithoutChangingHistoricalProof():void
    {
        $f=$this->savingFixture();$this->appendHistoricalUse($f);$source=$f['source']['source'];$pdo=$this->db->pdo();$pdo->exec('CREATE TEMPORARY TABLE nonprofit_tax_saving_cost_bindings(supplier_id INT UNSIGNED NOT NULL,source_id BIGINT UNSIGNED NOT NULL,event_id BIGINT UNSIGNED NOT NULL,cost_line_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(supplier_id,event_id)) ENGINE=InnoDB');
        try{try{(new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsLedger($this->db))->events($this->supplier,$source['id']);self::fail('Missing active projection was ignored');}catch(TaxReturnException $e){self::assertSame('nonprofit_tax_saving_proof_changed',$e->errorCode);}self::assertTrue($pdo->inTransaction());}
        finally{$pdo->exec('DROP TEMPORARY TABLE nonprofit_tax_saving_cost_bindings');}
        self::assertCount(1,(new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsLedger($this->db))->events($this->supplier,$source['id']));
    }
    public function testPublicTaxReturnReadsValidSavedArchive():void
    {
        $this->savingFixture();
        self::assertSame('final',$this->container->get(TaxReturnService::class)->getReturn($this->supplier,2026,'po',$this->actor)['return']['status']);
    }
}
