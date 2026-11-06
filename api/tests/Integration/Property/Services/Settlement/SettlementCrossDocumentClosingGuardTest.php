<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Services\Settlement;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Property\Services\Settlement\SettlementDocument;
use MyInvoice\Repository\Property\Services\Settlement\{SettlementApplicationRepository,SettlementDocumentRepository};
use PHPUnit\Framework\TestCase;
final class SettlementCrossDocumentClosingGuardTest extends TestCase
{
    use \MyInvoice\Tests\Support\SharedFixtureSavepointTrait;
    private Connection $db;
    private \DI\Container $container;
    private SettlementHistoricalRolloverTest $fixture;
    private array $f;
    private function container():\DI\Container {return $this->container;}
    protected function setUp():void
    {
        $this->container=Bootstrap::buildContainer();$this->db=$this->container->get(Connection::class);
        self::assertSame(getenv('MYINVOICE_DB_NAME'),$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));
        $this->db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->beginSharedFixtureTest($this->db->pdo());
        $this->fixture=new SettlementHistoricalRolloverTest('testNativeS6CloseOpenS8ApplyCloseOpenAndTypedReverseKeepExactCurrentHistory');
        foreach(['db'=>$this->db,'container'=>$this->container,'closing'=>$this->container->get(ClosingService::class)] as $key=>$value)(new \ReflectionProperty($this->fixture,$key))->setValue($this->fixture,$value);
    }
    protected function tearDown():void {$this->endSharedFixtureTest($this->db->pdo());}
    /** Základ testu se staví jednou za třídu i se stavem pomocné fixtury; fixtury samotné používají i jiné třídy. */
    private function shared(string $method,mixed ...$args):array
    {
        $helper=new \ReflectionProperty($this->fixture,'f');
        $state=$this->sharedFixture($method.'|'.serialize($args),function()use($method,$args,$helper):array{$data=$method==='newClaimFixture'?$this->newClaimFixture(...$args):$this->fixture($method,...$args);return['data'=>$data,'f'=>$this->f??null,'helper'=>$helper->isInitialized($this->fixture)?$helper->getValue($this->fixture):null];});
        if($state['f']!==null)$this->f=$state['f'];if($state['helper']!==null)$helper->setValue($this->fixture,$state['helper']);
        return $state['data'];
    }
    private function fixture(string $method,mixed ...$args):mixed {if($method==='nativeFixture')return $this->nativeFixture(...$args);return(new \ReflectionMethod($this->fixture,$method))->invoke($this->fixture,...$args);}
    private function cloneDocument(array $data,string $segment):array
    {
        $facts=$data['document']->snapshot();unset($facts['amounts']);$facts['segment_key']=$segment;
        return $data['docRepo']->save($data['f']['supplier'],SettlementDocument::fromEvidence($facts),[$data['f']['selector']],'2026-12-31',0,$segment,$data['f']['actor']);
    }
    public function testBatchedAssignmentGuardProtectsApplicationAndConsumedSource():void
    {
        extract($this->shared('appliedFixture',true));
        $application=(new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($this->db))->application($f['supplier'],$result['id'],true);
        $q=$this->db->pdo()->prepare('SELECT MIN(id) FROM journal_entry_lines WHERE supplier_id=? AND entry_id=?');
        $q->execute([$f['supplier'],$application['journal_entry_id']]);
        $lines=[(int)$q->fetchColumn(),$document->snapshot()['cost']['source_id']];
        $guard=new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db);
        foreach($lines as $line){
            self::assertGreaterThan(0,$line);
            try{$guard->assertAssignmentInsertsAfterPeriodPreflight($f['supplier'],[$line]);self::fail('Immutable settlement assignment accepted');}
            catch(\MyInvoice\Service\Accounting\Dimension\DimensionException $e){self::assertSame('property_service_settlement_owner_required',$e->errorCode);}
        }
    }
    public function testOpeningAdvanceCannotBeConsumedAcrossAnotherDocumentSegment():void
    {
        $data=$this->shared('appliedFixture',false);extract($data);
        $other=$this->cloneDocument($data,'SYNTHETIC-OTHER-SEGMENT');self::assertNotSame($saved['id'],$other['id']);
        $owner->apply($f['supplier'],$saved['id'],$request,0,$preview['preview_hash'],'first-segment',$f['actor']);
        $this->expectException(\DomainException::class);$this->expectExceptionMessage('settlement_origin_already_disposed');
        $owner->preview($f['supplier'],$other['id'],$request,0);
    }
    public function testIndependentNativeSourceCanBeConsumed():void
    {
        $this->sharedClean();foreach([1,2] as $i){$data=$this->fixture('appliedFixture',false);extract($data);$done=$owner->apply($f['supplier'],$saved['id'],$request,0,$preview['preview_hash'],'independent-'.$i,$f['actor']);self::assertGreaterThan(0,$done['id']);}
    }
    public function testCostCannotBeConsumedAcrossIndependentAdvanceAndDocument():void
    {
        extract($this->shared('nativeFixture','800000'));
        $issue=$f['issue_request'];$issue['source_key']='SYNTHETIC-INDEPENDENT';$issue['case_identity']['case_ref']='SYNTHETIC-INDEPENDENT';$issue['case_identity']['title_ref']='Synthetic independent legal title';
        foreach($issue['units'] as &$unit)$unit['case_distinction']=['against_source_keys'=>[$f['issue_request']['source_key']],'legal_reason'=>'Separate synthetic service obligation under another title','evidence_ref'=>'Synthetic separate decision'];unset($unit);
        $repo=$this->container->get(\MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository::class);
        $ip=(new \MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService($this->db))->preview($f['supplier'],$issue);
        $batch=$repo->issue($f['supplier'],['request'=>$issue,'preview_hash'=>$ip['preview_hash'],'idempotency_key'=>'independent-issue'],$f['actor']);
        $expected=[];foreach($batch['charges'] as $charge)$expected[]=['id'=>$charge['id'],'row_version'=>$charge['row_version']];$repo->post($f['supplier'],$batch['id'],$expected,$f['actor'],\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
        $q=$this->db->pdo()->prepare('SELECT l.id,l.charge_id FROM unit_charge_lines l JOIN unit_charges c ON c.id=l.charge_id AND c.supplier_id=l.supplier_id WHERE c.supplier_id=? AND c.batch_id=? AND c.unit_id=? AND l.service_id=?');$q->execute([$f['supplier'],$batch['id'],$f['units']['U02'],$f['service']['id']]);$line=$q->fetch(\PDO::FETCH_ASSOC);self::assertIsArray($line);
        (new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->register($f['supplier'],['charge_id'=>(int)$line['charge_id'],'expected_version'=>2,'principal_line_ids'=>[(int)$line['id']],'title_ref'=>'Synthetic independent debt','due_date'=>'2026-01-15','legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-01','evidence'=>'Synthetic independent decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic no security'],'damages_for'=>null],'independent-debt',$f['actor']);
        $otherSelector=$selector;$otherSelector['id']=(int)$line['id'];
        $current=$this->container->get(\MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$otherSelector]);
        $facts=$doc->snapshot();unset($facts['amounts']);$facts['segment_key']='SYNTHETIC-OTHER-COST';$facts['origins']=$current['origins'];
        $other=$this->container->get(SettlementDocumentRepository::class)->save($f['supplier'],SettlementDocument::fromEvidence($facts),[$otherSelector],'2026-12-31',0,'other-cost-document',$f['actor']);
        $request=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic native cost disposition','advances'=>[['origin_key'=>'s3_line:'.$selector['id'],'applied_cents'=>'800000']],'reliefs'=>[],'opening_credits'=>[],'new_claim'=>null];
        $owner=$this->container->get(SettlementApplicationRepository::class);$preview=$owner->preview($f['supplier'],$saved['id'],$request,0);$owner->apply($f['supplier'],$saved['id'],$request,0,$preview['preview_hash'],'original-cost-apply',$f['actor']);
        $request['advances'][0]['origin_key']='s3_line:'.$otherSelector['id'];
        try{$owner->preview($f['supplier'],$other['id'],$request,0);self::fail('Shared cost accepted under another native advance');}catch(\DomainException $e){self::assertSame('settlement_cost_already_disposed',$e->getMessage());}
        $dims=$current['origins'][0]['advance_line']['dimensions'];$cat=(new \MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog($this->db))->ensureForFramework($f['supplier'],'2026-12-31');$dims[$cat['activity']['id']]=$cat['activity']['values']['main'];
        $entry=$this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($f['supplier'],'manual',null,[['account_code'=>'315.210','side'=>'debit','amount'=>'8000.00','dimensions'=>$dims],['account_code'=>'321','side'=>'credit','amount'=>'8000.00','dimensions'=>$dims]],['entry_date'=>'2026-12-31','posted_by'=>$f['actor']]);
        $otherSelector['cost_line_id']=(int)$this->db->pdo()->query('SELECT MIN(id) FROM journal_entry_lines WHERE entry_id='.$entry)->fetchColumn();
        $current=$this->container->get(\MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$otherSelector]);
        $facts['segment_key']='SYNTHETIC-INDEPENDENT-COST';$facts['cost']=array_values($current['costs'])[0];
        $independent=$this->container->get(SettlementDocumentRepository::class)->save($f['supplier'],SettlementDocument::fromEvidence($facts),[$otherSelector],'2026-12-31',0,'independent-cost-document',$f['actor']);
        $fresh=$owner->preview($f['supplier'],$independent['id'],$request,0);$done=$owner->apply($f['supplier'],$independent['id'],$request,0,$fresh['preview_hash'],'independent-cost-apply',$f['actor']);self::assertGreaterThan(0,$done['id']);
    }
    public function testTypedReversePermitsCorrectedDocumentRevision():void
    {
        $data=$this->shared('appliedFixture',true);extract($data);$oldDocument=$docRepo->find($f['supplier'],$saved['id'],true);
        $history=new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($this->db);
        $first=$history->application($f['supplier'],$result['id'],true);$old=$first['receipt'];
        $owner->reverse($f['supplier'],$result['id'],'2026-12-31',1,'Synthetic correction of settlement','correction-reverse',$f['actor']);
        self::assertFalse($history->application($f['supplier'],$result['id'],true)['active']);
        $facts=$document->snapshot();unset($facts['amounts']);$facts['evidence']='Synthetic corrected evidence after typed reversal';
        $corrected=$docRepo->save($f['supplier'],SettlementDocument::fromEvidence($facts),[$f['selector']],'2026-12-31',1,'correction-document',$f['actor']);
        self::assertNotSame($saved['id'],$corrected['id']);self::assertSame($saved['id'],$corrected['previous_id']);self::assertSame(2,$corrected['revision_no']);
        self::assertSame($oldDocument,$docRepo->find($f['supplier'],$saved['id'],true));
        try{$owner->preview($f['supplier'],$saved['id'],$request,2);self::fail('Superseded document reapplied');}catch(\DomainException $e){self::assertSame('settlement_document_superseded',$e->getMessage());}
        $other=$this->cloneDocument($data,'SYNTHETIC-CORRECTION-OTHER');
        $fresh=$owner->preview($f['supplier'],$corrected['id'],$request,0);$done=$owner->apply($f['supplier'],$corrected['id'],$request,0,$fresh['preview_hash'],'corrected-apply',$f['actor']);
        self::assertCount(1,$done['credit_ids']);self::assertCount(0,$done['debt_ids']);self::assertNotSame($first['credit_ids'],$done['credit_ids']);
        self::assertSame('0',$payments->credit($f['supplier'],$first['credit_ids'][0])['balance_cents']);self::assertSame('10000',$payments->credit($f['supplier'],$done['credit_ids'][0])['balance_cents']);
        $this->assertClosure($f['supplier'],$f['units']['U02'],$debt);
        try{$owner->preview($f['supplier'],$other['id'],$request,0);self::fail('Active source consumed twice after correction');}catch(\DomainException $e){self::assertSame('settlement_origin_already_disposed',$e->getMessage());}
        $this->assertFinancialHistory($f['supplier'],$debt,'20000','20000');
        $owner->reverse($f['supplier'],$done['id'],'2026-12-31',1,'Synthetic corrected reversal','corrected-reverse',$f['actor']);
        self::assertFalse($history->application($f['supplier'],$done['id'],true)['active']);self::assertSame($old,$history->application($f['supplier'],$result['id'],true)['receipt']);
        self::assertSame('0',$payments->credit($f['supplier'],$done['credit_ids'][0])['balance_cents']);$this->assertFinancialHistory($f['supplier'],$debt,'40000','30000');
        $this->assertClosure($f['supplier'],$f['units']['U02'],$debt);
        $q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM unit_credit_lots WHERE supplier_id=? AND settlement_effect_id IS NOT NULL');$q->execute([$f['supplier']]);self::assertSame(2,(int)$q->fetchColumn());
        $q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM unit_payment_debts WHERE supplier_id=? AND settlement_effect_id IS NOT NULL');$q->execute([$f['supplier']]);self::assertSame(0,(int)$q->fetchColumn());
    }
    private function assertFinancialHistory(int $supplier,int $debt,string $claim,string $paid):void
    {
        $native=(new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->currentMany($supplier,[$debt])[0];self::assertSame($paid,(string)array_sum($native['paid']));
        $history=(new \MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository($this->db))->readForDebts($supplier,[$debt]);$sums=['claim'=>'0','paid'=>'0'];foreach($history['debts'][$debt]['events'] as $event)if(isset($sums[$event['kind']]))$sums[$event['kind']]=bcadd($sums[$event['kind']],$event['amount_cents'],0);self::assertSame($claim,$sums['claim']);self::assertSame($paid,$sums['paid']);
    }
    private function assertClosure(int $supplier,int $unit,int $debt):void
    {
        $closure=(new \MyInvoice\Repository\Property\Receivables\PropertySaldoReadClosure($this->db))->units($supplier,[$unit],[$debt]);\MyInvoice\Repository\Property\Receivables\PropertySaldoReadProof::materializations($closure->rows);self::assertContains($debt,$closure->debtIds);
    }
    public function testForgedReversalReceiptCannotReleaseSources():void
    {
        $data=$this->shared('appliedFixture',true);extract($data);$pdo=$this->db->pdo();
        $fakeEntry=$this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($f['supplier'],'manual',null,[['account_code'=>'221','side'=>'debit','amount'=>'1.00'],['account_code'=>'321','side'=>'credit','amount'=>'1.00']],['entry_date'=>'2026-12-31','posted_by'=>$f['actor']]);
        $pdo->exec('SAVEPOINT forged_reverse');
        $pdo->prepare("INSERT INTO property_service_settlement_requests (supplier_id,document_id,reversal_of,expected_version,request_json,request_hash,preview_json,preview_hash,idempotency_key,created_by,recorded_at) SELECT supplier_id,document_id,?,1,request_json,request_hash,preview_json,preview_hash,'forged-reversal',created_by,recorded_at FROM property_service_settlement_requests WHERE supplier_id=? AND id=?")->execute([$result['id'],$f['supplier'],$result['request_id']]);$requestId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO property_service_settlement_applications (supplier_id,document_id,reversal_of,accounting_date,period_id,journal_entry_id,receipt_json,receipt_hash,request_hash,idempotency_key,created_by,recorded_at,request_id) SELECT supplier_id,document_id,id,accounting_date,period_id,?,receipt_json,receipt_hash,request_hash,'forged-reversal',created_by,recorded_at,? FROM property_service_settlement_applications WHERE supplier_id=? AND id=?")->execute([$fakeEntry,$requestId,$f['supplier'],$result['id']]);
        $pdo->prepare('UPDATE journal_entries SET reversed_by=? WHERE supplier_id=? AND id=?')->execute([$fakeEntry,$f['supplier'],$result['journal_entry_id']]);
        try{(new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($this->db))->application($f['supplier'],$result['id'],true);self::fail('Forged reversal returned inactive application');}catch(\DomainException $e){self::assertSame('settlement_ledger_mismatch',$e->getMessage());}
        $facts=$document->snapshot();unset($facts['amounts']);$facts['evidence']='Synthetic forged reversal must not release revision';
        try{$docRepo->save($f['supplier'],SettlementDocument::fromEvidence($facts),[$f['selector']],'2026-12-31',1,'forged-correction',$f['actor']);self::fail('Forged reversal released document');}catch(\DomainException $e){self::assertSame('settlement_ledger_mismatch',$e->getMessage());}
        $pdo->exec('ROLLBACK TO SAVEPOINT forged_reverse');
        self::assertTrue((new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($this->db))->application($f['supplier'],$result['id'],true)['active']);
    }
    public function testSupersededUnusedDocumentCannotApply():void
    {
        $data=$this->shared('appliedFixture',false);extract($data);$facts=$document->snapshot();unset($facts['amounts']);$facts['evidence']='Synthetic superseding unused document';
        $new=$docRepo->save($f['supplier'],SettlementDocument::fromEvidence($facts),[$f['selector']],'2026-12-31',1,'unused-revision',$f['actor']);self::assertNotSame($saved['id'],$new['id']);
        $this->expectException(\DomainException::class);$this->expectExceptionMessage('settlement_document_superseded');$owner->preview($f['supplier'],$saved['id'],$request,0);
    }
    public function testNewClaimCorrectionLoopCreatesOnlyOneActiveDebt():void
    {
        extract($this->shared('newClaimFixture'));$history=new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($this->db);$docRepo=$this->container->get(SettlementDocumentRepository::class);$document=$docRepo->find($f['supplier'],$saved['id'],true);
        self::assertCount(1,$result['debt_ids']);$firstDebt=$result['debt_ids'][0];$this->assertFinancialHistory($f['supplier'],$firstDebt,'200000','0');
        $owner->reverse($f['supplier'],$result['id'],'2026-12-31',1,'Synthetic new claim correction','new-claim-correction-reverse',$f['actor']);$this->assertFinancialHistory($f['supplier'],$firstDebt,'0','0');
        $facts=$document['document'];unset($facts['amounts']);$facts['evidence']='Synthetic corrected new claim evidence';
        $next=$docRepo->save($f['supplier'],SettlementDocument::fromEvidence($facts),$document['origin_receipt']['request']['selectors'],'2026-12-31',1,'new-claim-corrected-document',$f['actor']);
        $fresh=$owner->preview($f['supplier'],$next['id'],$r,0);$done=$owner->apply($f['supplier'],$next['id'],$r,0,$fresh['preview_hash'],'new-claim-corrected-apply',$f['actor']);self::assertCount(1,$done['debt_ids']);self::assertCount(0,$done['credit_ids']);self::assertNotSame($firstDebt,$done['debt_ids'][0]);
        self::assertFalse($history->application($f['supplier'],$result['id'],true)['active']);self::assertTrue($history->application($f['supplier'],$done['id'],true)['active']);$this->assertFinancialHistory($f['supplier'],$done['debt_ids'][0],'200000','0');$this->assertClosure($f['supplier'],$f['units']['U02'],$done['debt_ids'][0]);
        $owner->reverse($f['supplier'],$done['id'],'2026-12-31',1,'Synthetic corrected new claim reversal','new-claim-corrected-reverse',$f['actor']);$this->assertFinancialHistory($f['supplier'],$firstDebt,'0','0');$this->assertFinancialHistory($f['supplier'],$done['debt_ids'][0],'0','0');
        $q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM unit_payment_debts WHERE supplier_id=? AND settlement_effect_id IS NOT NULL');$q->execute([$f['supplier']]);self::assertSame(2,(int)$q->fetchColumn());
    }
    public function testSelectedPagedCurrentAndHistoricalReadsUseCorrectedGraph():void
    {
        extract($this->shared('newClaimFixture'));$docRepo=$this->container->get(SettlementDocumentRepository::class);$original=$docRepo->find($f['supplier'],$saved['id'],true);
        $owner->reverse($f['supplier'],$result['id'],'2026-12-31',1,'Synthetic paged correction','paged-reverse',$f['actor']);$baseline=(new \MyInvoice\Service\Property\Receivables\PropertyReceivableProjection($this->db))->at($f['supplier'],'2027-01-01T12:00:00+01:00','2099-01-01T00:00:00Z',['unit_id'=>$f['units']['U02']],true);$baselineGross=$baseline['totals']['gross_receivable_cents'];$facts=$original['document'];unset($facts['amounts']);$facts['evidence']='Synthetic selected revision2';
        $next=$docRepo->save($f['supplier'],SettlementDocument::fromEvidence($facts),$original['origin_receipt']['request']['selectors'],'2026-12-31',1,'paged-revision',$f['actor']);$fresh=$owner->preview($f['supplier'],$next['id'],$r,0);$done=$owner->apply($f['supplier'],$next['id'],$r,0,$fresh['preview_hash'],'paged-corrected-apply',$f['actor']);
        $closure=(new \MyInvoice\Repository\Property\Receivables\PropertySaldoReadClosure($this->db))->units($f['supplier'],[$f['units']['U02']]);self::assertContains($done['debt_ids'][0],$closure->debtIds);self::assertContains($result['debt_ids'][0],$closure->debtIds);self::assertContains($source['debt_id'],$closure->debtIds);self::assertCount(2,$closure->rows['property_service_settlements']);self::assertCount(3,$closure->rows['property_service_settlement_applications']);
        $current=(new \MyInvoice\Service\Property\Receivables\PropertyReceivableProjection($this->db))->at($f['supplier'],'2027-01-01T12:00:00+01:00','2099-01-01T00:00:00Z',['unit_id'=>$f['units']['U02']],true);self::assertSame(bcadd($baselineGross,'200000',0),$current['totals']['gross_receivable_cents']);
        $this->db->pdo()->commit();
        foreach(['2026-12-30T12:00:00+01:00'=>$baselineGross,'2027-01-01T12:00:00+01:00'=>bcadd($baselineGross,'200000',0)] as $asOf=>$expected){
            $card=(new \MyInvoice\Service\Property\Receivables\PropertyReceivableProjection($this->db))->at($f['supplier'],$asOf,'2099-01-01T00:00:00Z',['unit_id'=>$f['units']['U02']]);self::assertSame($expected,$card['totals']['gross_receivable_cents']);
            $reader=new \MyInvoice\Repository\Property\Receivables\PropertySaldoReadRepository($this->db);$cursor=null;$found=null;$seen=[];
            do{$page=$reader->page($f['supplier'],$asOf,'2099-01-01T00:00:00Z',1,$cursor);foreach($page['items'] as $item){self::assertNotContains($item['unit_id'],$seen);$seen[]=$item['unit_id'];if($item['unit_id']===$f['units']['U02'])$found=$item;}$cursor=$page['next_cursor'];self::assertLessThanOrEqual(count($f['units']),count($seen));}while($cursor!==null);
            self::assertIsArray($found);unset($found['unit_code']);self::assertSame(\MyInvoice\Service\Property\Receivables\PropertySaldoSummary::fromCard($card),$found);
        }
    }
    public function testSelectedProofRejectsMissingNativeBindingAndMixedTenant():void
    {
        extract($this->shared('newClaimFixture'));$closure=(new \MyInvoice\Repository\Property\Receivables\PropertySaldoReadClosure($this->db))->units($f['supplier'],[$f['units']['U02']]);\MyInvoice\Repository\Property\Receivables\PropertySaldoReadProof::materializations($closure->rows);
        $rows=$closure->rows;$rows['property_service_settlement_effects']=[];try{\MyInvoice\Repository\Property\Receivables\PropertySaldoReadProof::materializations($rows);self::fail('Missing S8 effect binding accepted');}catch(\DomainException $e){self::assertSame('settlement_history_corrupt',$e->getMessage());}
        $rows=$closure->rows;$rows['property_service_settlement_applications'][0]['supplier_id']++;try{\MyInvoice\Service\Property\Services\Settlement\SettlementHistory::selectedMaterializations($rows);self::fail('Mixed tenant context accepted');}catch(\DomainException $e){self::assertSame('settlement_history_corrupt',$e->getMessage());}
        $rows=$closure->rows;$rows['unit_payment_debts']=array_values(array_filter($rows['unit_payment_debts'],fn($row)=>(int)$row['id']!==$result['debt_ids'][0]));try{\MyInvoice\Repository\Property\Receivables\PropertySaldoReadProof::materializations($rows);self::fail('Missing generated S8 debt accepted');}catch(\DomainException $e){self::assertSame('settlement_claim_origin',$e->getMessage());}
        try{$this->db->pdo()->prepare('DELETE FROM unit_payment_debt_lines WHERE supplier_id=? AND debt_id=?')->execute([$f['supplier'],$source['debt_id']]);self::fail('Immutable native binding removed');}catch(\PDOException $e){self::assertSame('45000',$e->getCode());self::assertSame(1644,(int)$e->errorInfo[1]);self::assertStringContainsString('immutable',$e->getMessage());}
        $rows=$closure->rows;$rows['unit_payment_debt_lines']=array_values(array_filter($rows['unit_payment_debt_lines'],fn($row)=>(int)$row['debt_id']!==$source['debt_id']));
        try{\MyInvoice\Repository\Property\Receivables\PropertySaldoReadProof::materializations($rows);self::fail('Missing original S3 debt binding accepted');}catch(\MyInvoice\Service\Property\PropertyException $e){self::assertSame('property_receivable_history_corrupt',$e->errorCode);}
    }
    public function testSelectedCreditPartyIdentityRequiresNativeBinding():void
    {
        extract($this->shared('appliedFixture',true));$closure=(new \MyInvoice\Repository\Property\Receivables\PropertySaldoReadClosure($this->db))->units($f['supplier'],[$f['units']['U02']]);\MyInvoice\Repository\Property\Receivables\PropertySaldoReadProof::materializations($closure->rows);self::assertNotEmpty($closure->rows['unit_credit_parties']);
        $rows=$closure->rows;$rows['unit_credit_parties']=[];try{\MyInvoice\Repository\Property\Receivables\PropertySaldoReadProof::materializations($rows);self::fail('Missing S8 creditor binding accepted');}catch(\DomainException $e){self::assertSame('settlement_credit_origin',$e->getMessage());}
        $rows=$closure->rows;$rows['unit_credit_lots']=array_values(array_filter($rows['unit_credit_lots'],fn($row)=>(int)$row['id']!==$result['credit_ids'][0]));try{\MyInvoice\Repository\Property\Receivables\PropertySaldoReadProof::materializations($rows);self::fail('Missing generated S8 credit lot accepted');}catch(\DomainException $e){self::assertSame('settlement_credit_origin',$e->getMessage());}
    }
    public function testUnappliedDocumentDoesNotPinSelectedFinancialGraph():void
    {
        extract($this->shared('nativeFixture','800000'));$q=$this->db->pdo()->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=?');$q->execute([$f['supplier'],$selector['cost_line_id']]);$entry=(int)$q->fetchColumn();
        $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($f['supplier'],$entry,['entry_date'=>'2026-12-31','posted_by'=>$f['actor']]);
        $closure=(new \MyInvoice\Repository\Property\Receivables\PropertySaldoReadClosure($this->db))->units($f['supplier'],[$f['units']['U02']]);self::assertSame([],$closure->rows['property_service_settlements']);\MyInvoice\Repository\Property\Receivables\PropertySaldoReadProof::materializations($closure->rows);
    }
    public function testRevertOpenNextCannotDeleteOpeningCostConsumedBySettlement():void
    {
        extract($this->shared('nativeFixture','800000'));
        $q=$this->db->pdo()->prepare('SELECT id FROM accounting_periods WHERE supplier_id=? AND fiscal_year=?');$q->execute([$f['supplier'],2026]);$year=(int)$q->fetchColumn();
        $this->fixture('close',$year);$closing=$this->container->get(ClosingService::class);$opened=$closing->openNext($f['supplier'],$year,$this->fixture('rv',$year));
        $q=$this->db->pdo()->prepare("SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? AND a.account_code='315.210' AND l.side='debit'");$q->execute([$f['supplier'],$opened['entry_id']]);$cost=(int)$q->fetchColumn();self::assertGreaterThan(0,$cost);
        $selector['cost_line_id']=$cost;
        $current=$this->container->get(\MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2027-02-01',[$selector]);
        $facts=$doc->snapshot();unset($facts['amounts']);$facts['segment_key']='SYNTHETIC-OPENING-COST';$facts['origins']=$current['origins'];$facts['cost']=array_values($current['costs'])[0];
        $saved=$this->container->get(SettlementDocumentRepository::class)->save($f['supplier'],SettlementDocument::fromEvidence($facts),[$selector],'2027-02-01',0,'opening-cost-document',$f['actor']);
        $request=['accounting_date'=>'2027-02-01','evidence'=>'Synthetic opening cost settlement','advances'=>[['origin_key'=>'s3_line:'.$selector['id'],'applied_cents'=>'800000']],'reliefs'=>[],'opening_credits'=>[],'new_claim'=>null];
        $owner=$this->container->get(SettlementApplicationRepository::class);$preview=$owner->preview($f['supplier'],$saved['id'],$request,0);$done=$owner->apply($f['supplier'],$saved['id'],$request,0,$preview['preview_hash'],'opening-cost-apply',$f['actor']);self::assertGreaterThan(0,$done['id']);
        $version=$this->fixture('rv',$year);
        try{$closing->revertStep($f['supplier'],$year,'open_next',$version,['user_id'=>$f['actor']]);self::fail('Used opening deleted');}catch(\MyInvoice\Service\Accounting\Closing\ClosingException $e){self::assertSame('property_service_settlement_owner_required',$e->errorCode);self::assertSame(409,$e->httpStatus);}
        self::assertSame($version,$this->fixture('rv',$year));
        $q=$this->db->pdo()->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=?');$q->execute([$f['supplier'],$cost]);self::assertSame((int)$opened['entry_id'],(int)$q->fetchColumn());
        self::assertTrue((new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($this->db))->application($f['supplier'],$done['id'],true)['active']);
    }
 private function nativeFixture(string $actual):array {

  $f=$this->fixture('issuanceFixture',$this->db);$this->f=$f;(new \ReflectionProperty($this->fixture,'f'))->setValue($this->fixture,$f);$chart=new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);$parent=$chart->findByCode($f['supplier'],'315');foreach(['315.100','315.210'] as $code)$chart->insert($f['supplier'],['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>'asset','normal_side'=>'debit','parent_id'=>$parent['id']]);foreach($f['issue_request']['accounts'] as &$account)$account['receivable_account']='315.100';unset($account);$repo=$this->container()->get(\MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository::class);
  $preview=(new \MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService($this->db))->preview($f['supplier'],$f['issue_request']);
  $batch=$repo->issue($f['supplier'],['request'=>$f['issue_request'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'native-s8-issue'],$f['actor']);
  $expected=[];foreach($batch['charges'] as $charge)$expected[]=['id'=>$charge['id'],'row_version'=>$charge['row_version']];
  $repo->post($f['supplier'],$batch['id'],$expected,$f['actor'],\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
  $q=$this->db->pdo()->prepare('SELECT l.id FROM unit_charge_lines l JOIN unit_charges c ON c.supplier_id=l.supplier_id AND c.id=l.charge_id WHERE l.supplier_id=? AND c.unit_id=? AND l.service_id=?');$q->execute([$f['supplier'],$f['units']['U02'],$f['service']['id']]);$id=(int)$q->fetchColumn();
  (new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->register($f['supplier'],['charge_id'=>(int)$this->db->pdo()->query('SELECT charge_id FROM unit_charge_lines WHERE id='.$id)->fetchColumn(),'expected_version'=>2,'principal_line_ids'=>[$id],'title_ref'=>'Synthetic service debt','due_date'=>'2026-01-15','legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-01','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic no security'],'damages_for'=>null],'s8-debt',$f['actor']);
  $result=$this->container()->get(\MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[['kind'=>'s3_line','id'=>$id]]);

  $dims=$result['origins'][0]['advance_line']['dimensions'];$cat=(new \MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog($this->db))->ensureForFramework($f['supplier'],'2026-12-31');$dims[$cat['activity']['id']]=$cat['activity']['values']['main'];$entry=$this->container()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($f['supplier'],'manual',null,[['account_code'=>'315.210','side'=>'debit','amount'=>bcdiv($actual,'100',2),'dimensions'=>$dims],['account_code'=>'321','side'=>'credit','amount'=>bcdiv($actual,'100',2),'dimensions'=>$dims]],['entry_date'=>'2026-12-31','posted_by'=>$f['actor']]);
  $costId=(int)$this->db->pdo()->query('SELECT MIN(id) FROM journal_entry_lines WHERE entry_id='.$entry)->fetchColumn();$selector=['kind'=>'s3_line','id'=>$id,'cost_line_id'=>$costId];
  $native=$this->container()->get(\MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$selector]);
  $facts=['supplier_id'=>$f['supplier'],'unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'segment_key'=>'S3-SERVICE-2026','period_from'=>'2026-01-01','period_to'=>'2026-12-31','cutoff_at'=>'2026-12-31T23:59:59+01:00','known_at'=>'2027-01-01T12:00:00+01:00','recipient_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic native service ledger','prescribed_cents'=>'1200000','paid_cents'=>'0','actual_cents'=>$actual,'origins'=>$native['origins'],'cutoff_allocations'=>[],'cost'=>array_values($native['costs'])[0]];
  $doc=\MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts);$saved=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class)->save($f['supplier'],$doc,[$selector],'2026-12-31',0,'s3-service-doc',$f['actor']);return compact('f','selector','saved','native','doc');

 }

 private function newClaimFixture(bool $apply=true):array {

  extract($this->nativeFixture('1400000'));$source=$native['origins'][0];$owner=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);
  $r=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic native service disposition','advances'=>[['origin_key'=>'s3_line:'.$source['id'],'applied_cents'=>'1200000']],'reliefs'=>[],'opening_credits'=>[],'new_claim'=>['party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic independent settlement claim','due_date'=>'2027-04-30','account_code'=>'315.210'],'claim_title'=>['effective_at'=>'2026-12-31T12:00:00+01:00','title_ref'=>'Synthetic service settlement title','liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic service liability','valid_from'=>'2026-12-31T12:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2026-12-31','evidence'=>'Synthetic service legal act','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic unsecured finding']]];
  $preview=$owner->preview($f['supplier'],$saved['id'],$r,0);$result=$apply?$owner->apply($f['supplier'],$saved['id'],$r,0,$preview['preview_hash'],'s3-new-claim-apply',$f['actor']):null;
  return compact('f','saved','source','owner','r','preview','result');
 }

}
