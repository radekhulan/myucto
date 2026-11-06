<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Tax;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\Tax\Return\{TaxReturnService,TaxReturnException};
use MyInvoice\Service\Tax\Nonprofit\{NonprofitDppoStoredProof,NonprofitDppoFilingGate,NonprofitTaxRules,NonprofitTaxSavingsService};
use PHPUnit\Framework\TestCase;

final class NonprofitDppoNativeWorkflowTest extends TestCase
{
    private NonprofitDppoDataProviderTest $fixture;private Connection $db;private \DI\Container $container;private int $supplier;private int $period;private int $actor;
    protected function setUp():void
    {
        $this->fixture=new NonprofitDppoDataProviderTest('testCanonicalJ6AndNativeStatementsUseTrueFiscalInterval');(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);
        foreach(['db','container','supplier','period','actor']as$key)$this->$key=(new \ReflectionProperty($this->fixture,$key))->getValue($this->fixture);
        $this->invoke('profile');$this->invoke('post','602','800000.00');$this->invoke('confirm');
        \MyInvoice\Tests\Support\NonprofitDppoAcceptanceFixture::accept($this->db,$this->supplier,$this->actor);
        $notes=$this->container->get(\MyInvoice\Service\Accounting\Reports\StatementNotesService::class);$read=$notes->build($this->supplier,$this->period);foreach($read['sections']as$section)$notes->saveSection($this->supplier,2026,$section['key'],'Synthetic reviewed statutory disclosure',$this->actor);
    }
    protected function tearDown():void
    {(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);}
    private function invoke(string $method,mixed ...$arguments):mixed
    {return(new \ReflectionMethod($this->fixture,$method))->invoke($this->fixture,...$arguments);}
    private function draft():array
    {
        $service=$this->container->get(TaxReturnService::class);$read=$service->getReturn($this->supplier,2026,'po',$this->actor);
        return$service->saveInputs($this->supplier,2026,'po',['nonprofit'=>['reduction_kc'=>300000,'reduction_evidence'=>'Synthetic explicit next-period cost election']],$read['return']['row_version'],$this->actor,partial:true);
    }
    public function testRejectedReductionDoesNotPersistInvalidDraftOrIncrementVersion():void
    {
        $draft=$this->draft();$repository=new TaxReturnRepository($this->db);$before=$repository->find($this->supplier,2026,'po');
        try{$this->container->get(TaxReturnService::class)->saveInputs($this->supplier,2026,'po',['nonprofit'=>['reduction_kc'=>300001,'reduction_evidence'=>'Synthetic invalid reduction election']],$draft['return']['row_version'],$this->actor,partial:true);self::fail('Invalid reduction was accepted');}catch(TaxReturnException){}
        $after=$repository->find($this->supplier,2026,'po');self::assertSame($before['inputs'],$after['inputs']);self::assertSame($before['row_version'],$after['row_version']);self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame(300000,$this->container->get(TaxReturnService::class)->getReturn($this->supplier,2026,'po',$this->actor)['return']['inputs']['nonprofit']['reduction_kc']);
    }
    public function testNativeWorkingPreviewHasReal504SentencesWithoutArchivingOrLegacyUnsupportedFindings():void
    {
        $this->draft();$service=$this->container->get(TaxReturnService::class);$preview=$service->previewXml($this->supplier,2026,'po');
        self::assertSame('passed',$preview['xsd_status']);self::assertSame('dppdp9-preview',$preview['form_code']);self::assertStringContainsString('uv_vyhl="504"',$preview['xml']);self::assertStringContainsString('<VetaUR ',$preview['xml']);
        self::assertSame([],array_values(array_intersect(['accounting_decree_unsupported','public_benefit_taxpayer','taxpayer_type_unsupported'],array_column($preview['unsupported_cases'],'key'))));
        $q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM income_tax_return_snapshots WHERE supplier_id=?');$q->execute([$this->supplier]);self::assertSame(0,(int)$q->fetchColumn());self::assertSame('draft',(new TaxReturnRepository($this->db))->find($this->supplier,2026,'po')['status']);
    }
    public function testPresentationMinuteKeepsConsentHashButFinancialMutationChangesIt():void
    {
        $record=$this->draft()['podklady']['nonprofit_calculation'];self::assertSame('stable_presentation_v1',$record['proof_hash_mode']);$later=$record;
        $later['data']['statements']['balance_sheet']['entity']['prepared_at']='2099-12-31 23:59';$later['data']['statements']['income_statement']['entity']['prepared_at']='2099-12-31 23:59';$later['entity_proof']['entity_header']['prepared_at']='2099-12-31 23:59';$later['data']['source_manifest']['statements_hash']=NonprofitTaxRules::hash($later['data']['statements']);
        self::assertSame($record['filing_proof_hash'],NonprofitDppoStoredProof::filingProofHash($later));self::assertSame(NonprofitDppoStoredProof::comparisonHash($record),NonprofitDppoStoredProof::comparisonHash($later));self::assertNotSame($record['data']['statements']['balance_sheet']['entity']['prepared_at'],$later['data']['statements']['balance_sheet']['entity']['prepared_at']);
        $later['data']['statements']['income_statement']['rows'][0]['total']+=1;$later['data']['source_manifest']['statements_hash']=NonprofitTaxRules::hash($later['data']['statements']);
        self::assertNotSame($record['filing_proof_hash'],NonprofitDppoStoredProof::filingProofHash($later));self::assertNotSame(NonprofitDppoStoredProof::comparisonHash($record),NonprofitDppoStoredProof::comparisonHash($later));
        $unknown=$record;$unknown['proof_hash_mode']='unknown';try{NonprofitDppoStoredProof::filingProofHash($unknown);self::fail('Unknown proof hash mode was accepted');}catch(TaxReturnException $e){self::assertSame('nonprofit_dppo_stored_proof_invalid',$e->errorCode);}
    }
    public function testLegacyRawV3SnapshotRemainsCurrentAcrossPresentationMinute():void
    {
        $service=$this->container->get(TaxReturnService::class);$read=$service->getReturn($this->supplier,2026,'po',$this->actor);$draft=$service->saveInputs($this->supplier,2026,'po',['nonprofit'=>['reduction_kc'=>0,'reduction_evidence'=>'Synthetic legacy raw proof without tax saving']],$read['return']['row_version'],$this->actor,partial:true);$final=$service->finalize($this->supplier,2026,'po',$draft['return']['row_version'],$this->actor,expectedNonprofitFilingProofHash:$draft['nonprofit_filing_proof_hash']);$built=$service->buildXml($this->supplier,2026,'po');$snapshotId=(int)$final['return']['final_snapshot_id'];$stored=(new TaxReturnRepository($this->db))->snapshot($this->supplier,$snapshotId);$snapshot=$stored['snapshot_json'];$manifest=$stored['source_manifest'];$record=$snapshot['podklady']['nonprofit_calculation'];unset($record['proof_hash_mode'],$record['filing_proof_hash']);$record['filing_proof_hash']=NonprofitTaxRules::hash($record);$snapshot['podklady']['nonprofit_calculation']=$record;$manifest['nonprofit_filing_proof_hash']=$record['filing_proof_hash'];$snapshotJson=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$manifestJson=json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $q=$this->db->pdo()->prepare('UPDATE income_tax_return_snapshots SET snapshot_json=?,source_manifest=?,source_sha256=? WHERE supplier_id=? AND id=?');$q->execute([$snapshotJson,$manifestJson,hash('sha256',$manifestJson),$this->supplier,$snapshotId]);
        $proof=(new NonprofitDppoFilingGate($this->db))->submission($this->supplier,['form_code'=>'dppdp9','period_year'=>2026,'xml_content'=>$built['xml']],true);self::assertSame($record['filing_proof_hash'],$proof['filing_proof_hash']);self::assertSame(NonprofitDppoStoredProof::comparisonHash($record),$proof['comparison_hash']);
    }
    public function testLegacyV2SnapshotUsesOnlyItsSupportedCurrentComparisonLayer():void
    {
        $service=$this->container->get(TaxReturnService::class);$read=$service->getReturn($this->supplier,2026,'po',$this->actor);$draft=$service->saveInputs($this->supplier,2026,'po',['nonprofit'=>['reduction_kc'=>0,'reduction_evidence'=>'Synthetic legacy v2 proof without tax saving']],$read['return']['row_version'],$this->actor,partial:true);$final=$service->finalize($this->supplier,2026,'po',$draft['return']['row_version'],$this->actor,expectedNonprofitFilingProofHash:$draft['nonprofit_filing_proof_hash']);$snapshotId=(int)$final['return']['final_snapshot_id'];$stored=(new TaxReturnRepository($this->db))->snapshot($this->supplier,$snapshotId);$snapshot=$stored['snapshot_json'];$record=$snapshot['podklady']['nonprofit_calculation'];
        unset($record['form_manifest'],$record['line_coverage'],$record['proof_hash_mode'],$record['filing_proof_hash'],$record['data']['j6_receipt'],$record['data']['loss_appendix'],$record['data']['nonprofit']['j6_receipt'],$record['data']['source_manifest']['j6_receipt_hash'],$record['data']['source_manifest']['loss_appendix_hash']);$record['version']=2;$record['data']['nonprofit']=\MyInvoice\Service\Tax\Nonprofit\NonprofitDppoComputation::seal($record['data']['nonprofit']);$record['data']['source_manifest']['nonprofit_proof_hash']=$record['data']['nonprofit']['proof_hash'];$record['filing_proof_hash']=NonprofitTaxRules::hash($record);$snapshot['podklady']['nonprofit_calculation']=$record;
        $manifest=$record['data']['source_manifest']+['nonprofit_filing_proof_hash'=>$record['filing_proof_hash'],'nonprofit_data_manifest_hash'=>NonprofitTaxRules::hash($record['data']['source_manifest'])];$snapshot['podklady']['source_manifest']=$manifest;$built=$this->container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitDppoArchiveBuilder::class)->build($record,$snapshot['computed']);$snapshotJson=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$manifestJson=json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$q=$this->db->pdo()->prepare('UPDATE income_tax_return_snapshots SET snapshot_json=?,source_manifest=?,source_sha256=?,xml_content=?,xml_sha256=? WHERE supplier_id=? AND id=?');$q->execute([$snapshotJson,$manifestJson,hash('sha256',$manifestJson),$built['xml'],hash('sha256',$built['xml']),$this->supplier,$snapshotId]);
        $submission=['form_code'=>'dppdp9','period_year'=>2026,'xml_content'=>$built['xml']];$proof=(new NonprofitDppoFilingGate($this->db))->submission($this->supplier,$submission,true);self::assertSame($record['filing_proof_hash'],$proof['filing_proof_hash']);$this->db->pdo()->prepare('UPDATE supplier SET company_name=? WHERE id=?')->execute(['Synthetic changed legacy v2 entity',$this->supplier]);
        try{(new NonprofitDppoFilingGate($this->db))->submission($this->supplier,$submission,true);self::fail('Legacy v2 comparison ignored a changed current entity.');}catch(TaxReturnException $failure){self::assertSame('nonprofit_dppo_filing_sources_changed',$failure->errorCode);}
    }
    public function testArchiveFormatterUsesOnlyCapturedIdentityNotesAndFinancialStatements():void
    {
        $draft=$this->draft();$record=$draft['podklady']['nonprofit_calculation'];$name=$record['entity_proof']['supplier']['company_name'];$this->db->pdo()->prepare('UPDATE supplier SET company_name=? WHERE id=?')->execute(['Synthetic later entity name',$this->supplier]);
        $formatter=new \MyInvoice\Service\Tax\Nonprofit\NonprofitDppoArchiveBuilder($this->container->get(\MyInvoice\Service\Tax\Return\DppoXmlBuilder::class),$this->container->get(\MyInvoice\Service\Pdf\StatementNotesPdfRenderer::class));
        $built=$formatter->build($record,$draft['computed']);self::assertStringContainsString(htmlspecialchars($name,ENT_QUOTES|ENT_XML1,'UTF-8'),$built['xml']);self::assertStringNotContainsString('Synthetic later entity name',$built['xml']);self::assertSame('passed',(new \MyInvoice\Service\Validation\XmlSchemaValidator())->validate($built['xml'],'dppdp9')['status']);
        self::assertSame($record['filing_proof_hash'],NonprofitDppoStoredProof::verify(['computed'=>$draft['computed'],'podklady'=>$draft['podklady']],$draft['podklady']['source_manifest'],$built['xml'])['filing_proof_hash']);
    }
    public function testNativeFinalizationStoresActual504XmlAndOnlyActualTaxSaving():void
    {
        $draft=$this->draft();$service=$this->container->get(TaxReturnService::class);$final=$service->finalize($this->supplier,2026,'po',$draft['return']['row_version'],$this->actor,expectedNonprofitFilingProofHash:$draft['nonprofit_filing_proof_hash']);
        self::assertSame('final',$final['return']['status']);self::assertSame(105000,(int)$final['computed']['tax']);self::assertSame(63000,$final['computed']['nonprofit']['saving']['saving_kc']);
        $built=$service->buildXml($this->supplier,2026,'po');$dom=new \DOMDocument();$dom->loadXML($built['xml']);$xp=new \DOMXPath($dom);self::assertSame('504',$xp->evaluate('string(/Pisemnost/DPPDP9/VetaD/@uv_vyhl)'));self::assertSame('3',$xp->evaluate('string(/Pisemnost/DPPDP9/VetaD/@typ_popldpp)'));self::assertSame(0,$xp->query('//VetaUA|//VetaUB|//VetaUD|//VetaUZ')->length);self::assertGreaterThan(0,$xp->query('//VetaUR')->length);self::assertGreaterThan(0,$xp->query('//VetaUS')->length);self::assertGreaterThan(0,$xp->query('//VetaUT')->length);
        $gate=(new NonprofitDppoFilingGate($this->db))->submission($this->supplier,['form_code'=>'dppdp9','period_year'=>2026,'xml_content'=>$built['xml']],true);self::assertSame($draft['nonprofit_filing_proof_hash'],$gate['filing_proof_hash']);
        $savings=(new NonprofitTaxSavingsService($this->db))->context($this->supplier);self::assertCount(1,$savings['sources']);self::assertSame('6300000',$savings['sources'][0]['source']['saving_cents']);self::assertSame('6300000',$savings['sources'][0]['remaining_cents']);
    }
    public function testFinalizationXmlAndSubmissionAreRejectedAfterAcceptanceIsRevoked():void
    {
        $draft=$this->draft();$service=$this->container->get(TaxReturnService::class);$final=$service->finalize($this->supplier,2026,'po',$draft['return']['row_version'],$this->actor,expectedNonprofitFilingProofHash:$draft['nonprofit_filing_proof_hash']);$built=$service->buildXml($this->supplier,2026,'po');
        $acceptance=new \MyInvoice\Service\Tax\Nonprofit\NonprofitDppoAcceptanceService($this->db);$head=$acceptance->state($this->supplier)['head'];
        $acceptance->change($this->supplier,\MyInvoice\Service\Tax\Nonprofit\NonprofitDppoAcceptanceService::REVOKE,false,'','Synthetic withdrawn verification','synthetic-revoke-'.$this->supplier,$head['id'],$this->actor);
        self::assertFalse($acceptance->isAccepted($this->supplier));
        foreach([fn()=>$service->buildXml($this->supplier,2026,'po'),fn()=>(new \MyInvoice\Service\Tax\Return\AccountingDecreeService($this->db))->assertDppoSubmissionSupported($this->supplier,['form_code'=>'dppdp9','period_year'=>2026,'xml_content'=>$built['xml']],true)]as$blocked){
            try{$blocked();self::fail('DPPO 504 XML or submission passed without accepted calculation.');}catch(TaxReturnException $e){self::assertSame('nonprofit_dppo_calculation_not_accepted',$e->errorCode);}
        }
        self::assertSame('final',$final['return']['status']);
    }
    public function testQueuedArtifactResolverRechecksCurrentProofBeforeDispatch():void
    {
        $draft=$this->draft();$service=$this->container->get(TaxReturnService::class);$service->finalize($this->supplier,2026,'po',$draft['return']['row_version'],$this->actor,expectedNonprofitFilingProofHash:$draft['nonprofit_filing_proof_hash']);$built=$service->buildXml($this->supplier,2026,'po');$artifact=(new \MyInvoice\Repository\TaxSubmissionRepository($this->db))->archive($this->supplier,'dppdp9',2026,null,null,$built['xml'],[],'passed',[],null);self::assertGreaterThan(0,$artifact);
        $resolver=$this->container->get(\MyInvoice\Service\Submission\DefaultSubmissionArtifactResolver::class);self::assertSame($built['xml'],$resolver->resolve($this->supplier,'tax_submission',$artifact)['bytes']);$this->db->pdo()->prepare('UPDATE supplier SET company_name=? WHERE id=?')->execute(['Synthetic changed after finalization',$this->supplier]);
        try{$resolver->resolve($this->supplier,'tax_submission',$artifact);self::fail('Queued DPPO 504 ignored changed current proof');}catch(\MyInvoice\Service\Submission\Channel\SubmissionChannelException $e){self::assertSame('nonprofit_dppo_filing_sources_changed',$e->errorCode);self::assertSame(409,$e->httpStatus);}
    }
    public function testSameMoneyLegalRefreshRejectsStaleFilingConsentWithoutPartialWrite():void
    {
        $draft=$this->draft();$legal=new \MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService($this->db);$record=$legal->read($this->supplier,$this->period)['latest'];$p=$record['profile'];unset($p['ruleset'],$p['reduction_band']);$legal->confirm($this->supplier,$this->period,['expected_revision'=>1,'idempotency_key'=>'refresh-legal','reason'=>'Synthetic second legal review','profile'=>$p],$this->actor);
        try{$this->container->get(TaxReturnService::class)->finalize($this->supplier,2026,'po',$draft['return']['row_version'],$this->actor,expectedNonprofitFilingProofHash:$draft['nonprofit_filing_proof_hash']);self::fail('Stale legal proof finalized');}catch(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException|TaxReturnException){}
        self::assertTrue($this->db->pdo()->inTransaction());self::assertSame('draft',(new TaxReturnRepository($this->db))->find($this->supplier,2026,'po')['status']);$q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM nonprofit_tax_saving_sources WHERE supplier_id=?');$q->execute([$this->supplier]);self::assertSame(0,(int)$q->fetchColumn());
    }
    private function savingFixture():array
    {
        $draft=$this->draft();$service=$this->container->get(TaxReturnService::class);$service->finalize($this->supplier,2026,'po',$draft['return']['row_version'],$this->actor,expectedNonprofitFilingProofHash:$draft['nonprofit_filing_proof_hash']);
        $periods=new \MyInvoice\Repository\AccountingPeriodRepository($this->db);$next=$periods->create($this->supplier,2027,'2026-07-01','2027-06-30');$legal=new \MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService($this->db);$profile=$legal->read($this->supplier,$this->period)['latest']['profile'];unset($profile['ruleset'],$profile['reduction_band']);$legal->confirm($this->supplier,$next,['expected_revision'=>0,'idempotency_key'=>'next-legal','reason'=>'Synthetic next-period legal review','profile'=>$profile],$this->actor);
        $catalog=(new \MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier,'2026-08-01');$kind=(new \ReflectionProperty($this->fixture,'kind'))->getValue($this->fixture);$dims=[$catalog['activity']['id']=>$catalog['activity']['values']['main'],$catalog['activity_kind']['id']=>$kind,$catalog['tax_regime']['id']=>$catalog['tax_regime']['values']['subject']];$origins=array_fill_keys(array_keys($dims),['origin'=>'explicit','ref'=>null]);
        $entry=$this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier,'manual',null,[['account_code'=>'518','side'=>'debit','amount'=>'100000.00','dimensions'=>$dims,'dimension_origins'=>$origins],['account_code'=>'221','side'=>'credit','amount'=>'100000.00','dimensions'=>$dims,'dimension_origins'=>$origins]],['entry_date'=>'2026-08-01','posted_by'=>$this->actor]);
        $j6=$this->container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService::class);$snapshot=$j6->read($this->supplier,$next)['snapshot'];$a=['profile'=>['kind'=>'documented','entity_kind'=>'narrow_vpp','evidence'=>'Synthetic supported legal form'],'method'=>['mode'=>'documented_manual','valid_from'=>'2026-07-01','valid_to'=>'2027-06-30','evidence'=>'Synthetic documented future-period classification','legal_basis'=>'zdp_18a_24_25'],'activities'=>[$kind=>['evidence'=>'Synthetic statutory nonbusiness activity']],'decisions'=>[],'funding'=>[]];
        foreach($snapshot['sources']as$row)$a['decisions'][$row['id']]=['title'=>'nonbusiness_cost','deductible_cents'=>0,'final_regime'=>'subject','evidence'=>'Synthetic qualifying statutory expense','legal_basis'=>'zdp_25'];$j6->confirm($this->supplier,$next,$a,$snapshot['input_hash'],'Synthetic qualified-cost review','next-j6',$this->actor);
        $line=(int)$this->db->pdo()->query('SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts c ON c.id=l.account_id WHERE l.entry_id='.(int)$entry." AND c.account_code='518'")->fetchColumn();$source=(new NonprofitTaxSavingsService($this->db))->context($this->supplier)['sources'][0];return compact('entry','line','next','source');
    }
    public function testActualSavingUseRetryReverseAndProportionalUnusedAddback():void
    {
        $f=$this->savingFixture();$owner=new NonprofitTaxSavingsService($this->db);self::assertSame($f['line'],$f['source']['eligible_costs'][0]['cost_line_id']);$input=['cost_line_id'=>$f['line'],'amount_cents'=>'2000000','purpose'=>'nonbusiness','evidence'=>'Synthetic proven cost-use'];$preview=$owner->previewUse($this->supplier,$f['source']['source']['id'],$input);
        $use=$owner->confirmUse($this->supplier,$f['source']['source']['id'],$input,$preview['preview_hash'],$preview['head_hash'],'saving-use',$this->actor);self::assertSame($use,$owner->confirmUse($this->supplier,$f['source']['source']['id'],$input,$preview['preview_hash'],$preview['head_hash'],'saving-use',$this->actor));
        $capacity=new \MyInvoice\Service\Accounting\Funding\CostCoverage($this->db);self::assertSame('2000000',$capacity->claimed($this->supplier,$f['line']));self::assertCount(1,$capacity->capacity($this->supplier,$f['line'])['claims']['tax_saving']);$due=(new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsLedger($this->db))->due($this->supplier,$f['next']);self::assertSame(204762,$due['addback_kc']);
        $reverse=$owner->previewReverse($this->supplier,$f['source']['source']['id'],$use['id'],'Synthetic corrected cost-use');$event=$owner->confirmReverse($this->supplier,$f['source']['source']['id'],$use['id'],'Synthetic corrected cost-use',$reverse['preview_hash'],$reverse['head_hash'],'saving-reverse',$this->actor);self::assertSame('reverse_use',$event['operation']);self::assertSame('0',$capacity->claimed($this->supplier,$f['line']));
    }
    public function testUsedCostRejectsActualNativeMutationAndAllowsNativeMarkPostedNoop():void
    {
        $f=$this->savingFixture();$owner=new NonprofitTaxSavingsService($this->db);$input=['cost_line_id'=>$f['line'],'amount_cents'=>'2000000','purpose'=>'nonbusiness','evidence'=>'Synthetic proven cost-use'];$preview=$owner->previewUse($this->supplier,$f['source']['source']['id'],$input);$owner->confirmUse($this->supplier,$f['source']['source']['id'],$input,$preview['preview_hash'],$preview['head_hash'],'saving-use',$this->actor);
        $journal=$this->container->get(\MyInvoice\Repository\JournalEntryRepository::class);self::assertFalse($journal->markPosted($f['entry'],$this->supplier,$this->actor));
        try{$this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($this->supplier,$f['entry'],['entry_date'=>'2026-08-02','posted_by'=>$this->actor]);self::fail('A used expense was reversed');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('nonprofit_tax_saving_cost_in_use',$e->errorCode);}
        self::assertTrue($this->db->pdo()->inTransaction());self::assertSame('2000000',(new \MyInvoice\Service\Accounting\Funding\CostCoverage($this->db))->claimed($this->supplier,$f['line']));
    }
    private function fund(array $f):array
    {
        foreach(['911'=>'equity','648'=>'revenue']as$code=>$type)$this->db->pdo()->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type)VALUES(?,?,?,?)')->execute([$this->supplier,(string)$code,'Synthetic fund '.$code,$type]);
        $catalog=(new \MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier,'2026-08-01');$kind=(new \ReflectionProperty($this->fixture,'kind'))->getValue($this->fixture);$dims=[$catalog['activity']['id']=>$catalog['activity']['values']['main'],$catalog['activity_kind']['id']=>$kind,$catalog['tax_regime']['id']=>$catalog['tax_regime']['values']['subject']];$origins=array_fill_keys(array_keys($dims),['origin'=>'explicit','ref'=>null]);
        $entry=$this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier,'manual',null,[['account_code'=>'221','side'=>'debit','amount'=>'100000.00','dimensions'=>$dims,'dimension_origins'=>$origins],['account_code'=>'911','side'=>'credit','amount'=>'100000.00','dimensions'=>$dims,'dimension_origins'=>$origins]],['entry_date'=>'2026-08-01','posted_by'=>$this->actor]);
        $line=(int)$this->db->pdo()->query('SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts c ON c.id=l.account_id WHERE l.entry_id='.(int)$entry." AND c.account_code='911'")->fetchColumn();
        $policy=['kind'=>'fund','source_line_id'=>$line,'asset_id'=>null,'provider_id'=>null,'revenue_tax_regime_id'=>$catalog['tax_regime']['values']['subject'],'title'=>'Synthetic approved statutory fund','purpose'=>'Synthetic statutory nonbusiness costs','evidence'=>'Synthetic fund conditions','decision_authority'=>'Synthetic governing board','valid_from'=>'2026-07-01','valid_to'=>'2027-06-30','activity_kind_ids'=>[$kind],'account_prefixes'=>['518']];$owner=new \MyInvoice\Service\Nonprofit\Capital\CapitalService($this->db,$this->container->get(\MyInvoice\Service\Accounting\PostingService::class));$p=$owner->previewSource($this->supplier,$policy);$source=$owner->confirmSource($this->supplier,$policy,$p['input_hash'],'Synthetic explicit fund approval','cross-fund',$this->actor);return[$owner,$source];
    }
    private function refreshNext(int $next):void
    {
        $j6=$this->container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService::class);$read=$j6->read($this->supplier,$next);$a=$read['latest']['assessment'];$a['decisions']=[];
        foreach($read['snapshot']['sources']as$row)$a['decisions'][$row['id']]=['title'=>$row['kind']==='expense'?'nonbusiness_cost':'other_taxable','deductible_cents'=>0,'final_regime'=>'subject','final_method'=>$row['kind']==='revenue'?'return':'cost','evidence'=>'Synthetic refreshed native owner evidence','legal_basis'=>'zdp_18_25'];$j6->confirm($this->supplier,$next,$a,$read['snapshot']['input_hash'],'Synthetic reviewed new fund release','refresh-next',$this->actor,$read['latest']['id']);
    }
    public function testSavingsFirstCannotBeCoveredAgainByFund911():void
    {
        $f=$this->savingFixture();[$capital,$fund]=$this->fund($f);$owner=new NonprofitTaxSavingsService($this->db);$input=['cost_line_id'=>$f['line'],'amount_cents'=>'2000000','purpose'=>'nonbusiness','evidence'=>'Synthetic proven cost-use'];$p=$owner->previewUse($this->supplier,$f['source']['source']['id'],$input);$owner->confirmUse($this->supplier,$f['source']['source']['id'],$input,$p['preview_hash'],$p['head_hash'],'saving-use',$this->actor);
        $this->expectException(\MyInvoice\Service\Nonprofit\Capital\CapitalException::class);$capital->previewFund($this->supplier,$fund['id'],$f['line'],9000000,'2026-08-02');
    }
    public function testFund911FirstRestrictsSavingUseOnTheSameNativeCost():void
    {
        $f=$this->savingFixture();[$capital,$fund]=$this->fund($f);$p=$capital->previewFund($this->supplier,$fund['id'],$f['line'],6000000,'2026-08-02');$capital->confirmFund($this->supplier,$fund['id'],$f['line'],6000000,'2026-08-02',$p['input_hash'],'Synthetic approved fund use','cross-fund-release',$this->actor);$this->refreshNext($f['next']);
        $this->expectException(TaxReturnException::class);(new NonprofitTaxSavingsService($this->db))->previewUse($this->supplier,$f['source']['source']['id'],['cost_line_id'=>$f['line'],'amount_cents'=>'5000000','purpose'=>'nonbusiness','evidence'=>'Synthetic invalid repeated coverage']);
    }
}
